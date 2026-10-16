
<?php

require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

date_default_timezone_set('Asia/Kolkata');

require_role(['admin', 'vendor']);

$success = '';
$error = '';
$today = date('Y-m-d');

/**
 * Convert a validated decimal amount into integer paise.
 * This avoids using floating-point arithmetic for cash calculations.
 */

function cashbookToPaise(string $amount): int
{
    $parts = explode('.', $amount, 2);
    $rupees = (int) $parts[0];

    $paise = isset($parts[1])
        ? (int) str_pad($parts[1], 2, '0')
        : 0;

    return ($rupees * 100) + $paise;
}

/**
 * Convert a database DECIMAL amount to paise without floating-point math.
 */
function cashbookDecimalToPaise(string|int $amount): int
{
    $amount = (string) $amount;

    if (!preg_match('/^\d{1,14}(?:\.\d{1,2})?$/', $amount)) {
        throw new RuntimeException('Invalid cashbook decimal amount.');
    }

    return cashbookToPaise($amount);
}


function cashbookFormatAmount(string|int $amount): string
{
    return number_format((float) $amount, 2, '.', ',');
}

function cashbookRedirect(string $message, string $type = 'success'): never
{
    header(
        'Location: cashbook.php?' . $type . '=' . rawurlencode($message)
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transactionStarted = false;

    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException(
                'Security verification failed. Refresh the page and try again.'
            );
        }

        $action = (string) ($_POST['action'] ?? '');

        $userId = filter_var(
            $_SESSION['user_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if (!$userId) {
            throw new RuntimeException(
                'Your login session is invalid. Please log in again.'
            );
        }

        $pdo->beginTransaction();
        $transactionStarted = true;

        if ($action === 'open') {
            $openingInput = trim((string) ($_POST['opening_cash'] ?? ''));

            if (!preg_match('/^\d{1,14}(?:\.\d{1,2})?$/', $openingInput)) {
                throw new RuntimeException(
                    'Enter a valid opening cash amount.'
                );
            }

            $openingPaise = cashbookToPaise($openingInput);

            // Do not allow opening cash beyond DECIMAL(16,2) capacity.
            if ($openingPaise > 9999999999999999) {
                throw new RuntimeException(
                    'The opening cash amount is too large.'
                );
            }

            // Lock an existing session if one already exists for today.
            $existingStatement = $pdo->prepare(
                "SELECT id, status
                 FROM daily_cash_sessions
                 WHERE business_date = :business_date
                 FOR UPDATE"
            );

            $existingStatement->execute([
                'business_date' => $today
            ]);

            $existingSession = $existingStatement->fetch(PDO::FETCH_ASSOC);

            if ($existingSession) {
                if ($existingSession['status'] === 'closed') {
                    throw new RuntimeException(
                        'Today’s cash session has already been closed. A second session cannot be opened for the same business date.'
                    );
                }

                throw new RuntimeException(
                    'A cash session is already open for today.'
                );
            }

            $insertSession = $pdo->prepare(
                "INSERT INTO daily_cash_sessions
                    (business_date, opening_cash, status, opened_by)
                 VALUES
                    (:business_date, :opening_cash, 'open', :opened_by)"
            );

            $insertSession->execute([
                'business_date' => $today,
                'opening_cash' => number_format(
                    $openingPaise / 100,
                    2,
                    '.',
                    ''
                ),
                'opened_by' => $userId
            ]);

            $sessionId = (int) $pdo->lastInsertId();

            $audit = $pdo->prepare(
                "INSERT INTO audit_logs
                    (user_id, action, entity_type, entity_id, details, ip_address)
                 VALUES
                    (:user_id, 'OPEN_CASH_SESSION', 'daily_cash_session',
                     :entity_id, :details, :ip_address)"
            );

            $audit->execute([
                'user_id' => $userId,
                'entity_id' => $sessionId,
                'details' => json_encode([
                    'business_date' => $today,
                    'opening_cash' => number_format(
                        $openingPaise / 100,
                        2,
                        '.',
                        ''
                    )
                ], JSON_THROW_ON_ERROR),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            $pdo->commit();
            $transactionStarted = false;

            cashbookRedirect('Daily cash session opened successfully.');
        }

        if ($action === 'close') {
            $closingInput = trim(
                (string) ($_POST['counted_closing_cash'] ?? '')
            );

            if (!preg_match('/^\d{1,14}(?:\.\d{1,2})?$/', $closingInput)) {
                throw new RuntimeException(
                    'Enter a valid counted closing cash amount.'
                );
            }

            $closingPaise = cashbookToPaise($closingInput);

            if ($closingPaise > 9999999999999999) {
                throw new RuntimeException(
                    'The counted closing cash amount is too large.'
                );
            }

            $sessionStatement = $pdo->prepare(
                "SELECT id, opening_cash, status
                 FROM daily_cash_sessions
                 WHERE business_date = :business_date
                 FOR UPDATE"
            );

            $sessionStatement->execute([
                'business_date' => $today
            ]);

            $session = $sessionStatement->fetch(PDO::FETCH_ASSOC);

            if (!$session || $session['status'] !== 'open') {
                throw new RuntimeException(
                    'There is no open cash session to close for today.'
                );
            }

            $totalsStatement = $pdo->prepare(
                "SELECT
                    COALESCE(SUM(amount_in), 0.00) AS total_in,
                    COALESCE(SUM(amount_out), 0.00) AS total_out,
                    COUNT(*) AS entry_count
                 FROM cashbook_entries
                 WHERE cash_session_id = :session_id"
            );

            $totalsStatement->execute([
                'session_id' => (int) $session['id']
            ]);

            $totals = $totalsStatement->fetch(PDO::FETCH_ASSOC);

            $openingPaise = cashbookDecimalToPaise(
                (string) $session['opening_cash']
            );
            $inPaise = cashbookDecimalToPaise(
                (string) $totals['total_in']
            );
            $outPaise = cashbookDecimalToPaise(
                (string) $totals['total_out']
            );

            $expectedPaise = $openingPaise + $inPaise - $outPaise;
            $differencePaise = $closingPaise - $expectedPaise;

            $closeStatement = $pdo->prepare(
                "UPDATE daily_cash_sessions
                 SET counted_closing_cash = :closing_cash,
                     status = 'closed',
                     closed_by = :closed_by,
                     closed_at = NOW()
                 WHERE id = :session_id
                   AND status = 'open'"
            );

            $closeStatement->execute([
                'closing_cash' => number_format(
                    $closingPaise / 100,
                    2,
                    '.',
                    ''
                ),
                'closed_by' => $userId,
                'session_id' => (int) $session['id']
            ]);

            if ($closeStatement->rowCount() !== 1) {
                throw new RuntimeException(
                    'The cash session could not be closed. Refresh and try again.'
                );
            }

            $audit = $pdo->prepare(
                "INSERT INTO audit_logs
                    (user_id, action, entity_type, entity_id, details, ip_address)
                 VALUES
                    (:user_id, 'CLOSE_CASH_SESSION', 'daily_cash_session',
                     :entity_id, :details, :ip_address)"
            );

            $audit->execute([
                'user_id' => $userId,
                'entity_id' => (int) $session['id'],
                'details' => json_encode([
                    'business_date' => $today,
                    'opening_cash' => number_format(
                        $openingPaise / 100,
                        2,
                        '.',
                        ''
                    ),
                    'total_in' => number_format(
                        $inPaise / 100,
                        2,
                        '.',
                        ''
                    ),
                    'total_out' => number_format(
                        $outPaise / 100,
                        2,
                        '.',
                        ''
                    ),
                    'expected_closing_cash' => number_format(
                        $expectedPaise / 100,
                        2,
                        '.',
                        ''
                    ),
                    'counted_closing_cash' => number_format(
                        $closingPaise / 100,
                        2,
                        '.',
                        ''
                    ),
                    'difference' => number_format(
                        $differencePaise / 100,
                        2,
                        '.',
                        ''
                    ),
                    'cashbook_entry_count' => (int) $totals['entry_count']
                ], JSON_THROW_ON_ERROR),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            $pdo->commit();
            $transactionStarted = false;

            cashbookRedirect(
                'Cash session closed. Expected cash: ₹' .
                number_format($expectedPaise / 100, 2) .
                '; counted cash: ₹' .
                number_format($closingPaise / 100, 2) .
                '; difference: ₹' .
                number_format($differencePaise / 100, 2)
            );
        }

        throw new RuntimeException('Invalid cashbook action.');
    } catch (Throwable $exception) {
        if ($transactionStarted && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            'AgroProcure cashbook error: ' . $exception->getMessage()
        );

        if ($exception instanceof RuntimeException) {
            $error = $exception->getMessage();
        } else {
            $error = 'The cashbook operation failed. No changes were saved. Check the server error log if the problem continues.';
        }
    }
}

if (isset($_GET['success']) && is_string($_GET['success'])) {
    $success = $_GET['success'];
}

if (isset($_GET['error']) && is_string($_GET['error'])) {
    $error = $_GET['error'];
}

$sessionStatement = $pdo->prepare(
    "SELECT
        d.*,
        opener.username AS opened_by_name,
        closer.username AS closed_by_name
     FROM daily_cash_sessions d
     LEFT JOIN users opener ON opener.id = d.opened_by
     LEFT JOIN users closer ON closer.id = d.closed_by
     WHERE d.business_date = :business_date
     LIMIT 1"
);

$sessionStatement->execute([
    'business_date' => $today
]);

$session = $sessionStatement->fetch(PDO::FETCH_ASSOC);

$totals = [
    'total_in' => '0.00',
    'total_out' => '0.00',
    'entry_count' => 0
];

$entries = [];

if ($session) {
    $totalsStatement = $pdo->prepare(
        "SELECT
            COALESCE(SUM(amount_in), 0.00) AS total_in,
            COALESCE(SUM(amount_out), 0.00) AS total_out,
            COUNT(*) AS entry_count
         FROM cashbook_entries
         WHERE cash_session_id = :session_id"
    );

    $totalsStatement->execute([
        'session_id' => (int) $session['id']
    ]);

    $totals = $totalsStatement->fetch(PDO::FETCH_ASSOC);

    $entriesStatement = $pdo->prepare(
        "SELECT
            c.*,
            u.username AS recorded_by_name
         FROM cashbook_entries c
         LEFT JOIN users u ON u.id = c.recorded_by
         WHERE c.cash_session_id = :session_id
         ORDER BY c.id DESC
         LIMIT 100"
    );

    $entriesStatement->execute([
        'session_id' => (int) $session['id']
    ]);

    $entries = $entriesStatement->fetchAll(PDO::FETCH_ASSOC);
}


$openingPaise = $session
    ? cashbookDecimalToPaise((string) $session['opening_cash'])
    : 0;

$inPaise = cashbookDecimalToPaise(
    (string) $totals['total_in']
);

$outPaise = cashbookDecimalToPaise(
    (string) $totals['total_out']
);

$expectedPaise = $openingPaise + $inPaise - $outPaise;

$countedPaise = $session && $session['counted_closing_cash'] !== null
    ? cashbookDecimalToPaise((string) $session['counted_closing_cash'])
    : null;


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Cashbook | AgroProcure</title>
    <link rel="stylesheet" href="../../public/assets/css/sales.css">
</head>
<body>
<main class="sales-page">
    <header class="sales-header">
        <div>
            <p class="sales-eyebrow">AGROPROCURE / VENDOR</p>
            <h1>Daily Cashbook</h1>
            <p>Business date: <?= e($today) ?></p>
        </div>
        <a class="sales-back-link" href="sales.php">Back to Sales</a>
    </header>

    <?php if ($success !== ''): ?>
        <div class="sales-notice sales-notice-success" role="status">
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="sales-notice sales-notice-error" role="alert">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <section class="sales-card">
        <h2>Cash Session</h2>

        <?php if (!$session): ?>
            <p>No cash session has been opened for today.</p>

            <form method="post" action="cashbook.php">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrf_token()) ?>"
                >
                <input type="hidden" name="action" value="open">

                <div class="sales-field">
                    <label for="opening_cash">Opening Cash (₹) *</label>
                    <input
                        type="number"
                        name="opening_cash"
                        id="opening_cash"
                        min="0"
                        max="99999999999999.99"
                        step="0.01"
                        value="0.00"
                        required
                    >
                </div>

                <button type="submit" class="sales-button">
                    Open Today's Cash Session
                </button>
            </form>

        <?php elseif ($session['status'] === 'open'): ?>
            <p>
                <strong>Status:</strong> Open
                <br>
                <strong>Opened by:</strong>
                <?= e($session['opened_by_name'] ?? 'Unknown') ?>
                <br>
                <strong>Opening time:</strong>
                <?= e($session['opened_at']) ?>
            </p>

            <grid columns={2} gap={3}>
                <grid-item>
                    <div class="sales-card">
                        <p>Opening Cash</p>
                        <h2>₹<?= e(cashbookFormatAmount($openingPaise / 100)) ?></h2>
                    </div>
                </grid-item>
                <grid-item>
                    <div class="sales-card">
                        <p>Cash Received</p>
                        <h2>₹<?= e(cashbookFormatAmount($inPaise / 100)) ?></h2>
                    </div>
                </grid-item>
                <grid-item>
                    <div class="sales-card">
                        <p>Cash Paid Out</p>
                        <h2>₹<?= e(cashbookFormatAmount($outPaise / 100)) ?></h2>
                    </div>
                </grid-item>
                <grid-item>
                    <div class="sales-card">
                        <p>Expected Closing Cash</p>
                        <h2>₹<?= e(cashbookFormatAmount($expectedPaise / 100)) ?></h2>
                    </div>
                </grid-item>
            </grid>

            <div class="sales-notice sales-notice-error">
                Closing cash is calculated only from entries linked to this cash session.
                Ensure cash supplier payments and cash expenses are integrated before
                relying on this figure for financial reconciliation.
            </div>

            <form method="post" action="cashbook.php">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrf_token()) ?>"
                >
                <input type="hidden" name="action" value="close">

                <div class="sales-field">
                    <label for="counted_closing_cash">
                        Actual Counted Closing Cash (₹) *
                    </label>
                    <input
                        type="number"
                        name="counted_closing_cash"
                        id="counted_closing_cash"
                        min="0"
                        max="99999999999999.99"
                        step="0.01"
                        required
                    >
                </div>

                <button
                    type="submit"
                    class="sales-button"
                    onclick="return confirm('Close today’s cash session? It cannot be reopened for the same business date.');"
                >
                    Close Cash Session
                </button>
            </form>

        <?php else: ?>
            <p>
                <strong>Status:</strong> Closed
                <br>
                <strong>Opened by:</strong>
                <?= e($session['opened_by_name'] ?? 'Unknown') ?>
                <br>
                <strong>Closed by:</strong>
                <?= e($session['closed_by_name'] ?? 'Unknown') ?>
                <br>
                <strong>Counted Closing Cash:</strong>
                ₹<?= e(cashbookFormatAmount($countedPaise / 100)) ?>
            </p>

            <p>
                <strong>Expected Closing Cash:</strong>
                ₹<?= e(cashbookFormatAmount($expectedPaise / 100)) ?>
            </p>

            <p>
                <strong>Difference:</strong>
                ₹<?= e(cashbookFormatAmount(($countedPaise - $expectedPaise) / 100)) ?>
            </p>

            <p>This session is closed. A second session cannot be opened for the same business date.</p>
        <?php endif; ?>
    </section>

    <section class="sales-card">
        <h2>Cashbook Entries</h2>
        <p class="sales-description">
            Entries recorded in today's cash session. The table displays up to 100 latest entries.
        </p>

        <div class="sales-table-wrap">
            <table class="sales-table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Description</th>
                        <th>Cash In</th>
                        <th>Cash Out</th>
                        <th>Recorded By</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$entries): ?>
                    <tr>
                        <td colspan="6">No cashbook entries recorded for this session yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($entries as $entry): ?>
                        <tr>
                            <td><?= e(ucwords(str_replace('_', ' ', $entry['entry_type']))) ?></td>
                            <td><?= e($entry['description'] ?? '—') ?></td>
                            <td>₹<?= e(cashbookFormatAmount($entry['amount_in'])) ?></td>
                            <td>₹<?= e(cashbookFormatAmount($entry['amount_out'])) ?></td>
                            <td><?= e($entry['recorded_by_name'] ?? '—') ?></td>
                            <td><?= e($entry['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section> 
</main>
</body>
</html>
