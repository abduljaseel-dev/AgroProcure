
<?php
require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_role(['admin', 'vendor', 'staff']);

$error = '';
$success = '';

/**
 * Convert a decimal money string into integer paise.
 */
function moneyToPaise(string $amount): int
{
    $parts = explode('.', $amount, 2);
    $rupees = (int) $parts[0];
    $paise = isset($parts[1])
        ? (int) str_pad($parts[1], 2, '0')
        : 0;

    return ($rupees * 100) + $paise;
}

function formatMoney($amount): string
{
    return '₹' . number_format((float) $amount, 2);
}

function generatePaymentReference(): string
{
    return 'SPM-' . date('Ymd') . '-' .
        strtoupper(bin2hex(random_bytes(5)));
}

/* ---------------- RECORD PAYMENT ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Security validation failed. Refresh the page and try again.';
    } else {
        $farmerId = filter_var(
            $_POST['farmer_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $amountInput = trim((string) ($_POST['amount'] ?? ''));
        $method = (string) ($_POST['payment_method'] ?? '');

        $transactionReference = trim(
            (string) ($_POST['transaction_reference'] ?? '')
        );

        $notes = trim((string) ($_POST['notes'] ?? ''));

        $allowedMethods = [
            'cash',
            'bank_transfer',
            'upi',
            'cheque',
            'other'
        ];

        if (!$farmerId) {
            $error = 'Please select a valid farmer.';
        } elseif (
            !preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $amountInput)
            || moneyToPaise($amountInput) <= 0
        ) {
            $error = 'Enter a valid payment amount with up to two decimal places.';
        } elseif (!in_array($method, $allowedMethods, true)) {
            $error = 'Select a valid payment method.';
        } elseif (
            strlen($transactionReference) > 100 ||
            strlen($notes) > 1000
        ) {
            $error = 'Transaction reference or notes are too long.';
        } else {
            $amountInput = number_format(
                moneyToPaise($amountInput) / 100,
                2,
                '.',
                ''
            );

            $transactionStarted = false;

            try {
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

                /*
                 * Lock the farmer record.
                 */
                $farmerStmt = $pdo->prepare(
                    "SELECT id, farmer_code, full_name
                     FROM farmers
                     WHERE id = ? AND is_active = 1
                     FOR UPDATE"
                );

                $farmerStmt->execute([$farmerId]);
                $farmer = $farmerStmt->fetch(PDO::FETCH_ASSOC);

                if (!$farmer) {
                    throw new RuntimeException(
                        'The selected farmer was not found or is inactive.'
                    );
                }

                /*
                 * Lock existing supplier-ledger rows for this farmer
                 * before calculating the outstanding amount.
                 */
                $ledgerLockStmt = $pdo->prepare(
                    "SELECT id
                     FROM supplier_ledger
                     WHERE supplier_type = 'farmer'
                       AND farmer_id = ?
                     ORDER BY id
                     FOR UPDATE"
                );

                $ledgerLockStmt->execute([$farmerId]);
                $ledgerLockStmt->fetchAll(PDO::FETCH_COLUMN);

                $balanceStmt = $pdo->prepare(
                    "SELECT
                        COALESCE(SUM(amount_due), 0) AS total_due,
                        COALESCE(SUM(amount_paid), 0) AS total_paid
                     FROM supplier_ledger
                     WHERE supplier_type = 'farmer'
                       AND farmer_id = ?"
                );

                $balanceStmt->execute([$farmerId]);
                $balance = $balanceStmt->fetch(PDO::FETCH_ASSOC);

                $outstandingPaise =
                    moneyToPaise((string) $balance['total_due'])
                    - moneyToPaise((string) $balance['total_paid']);

                $paymentPaise = moneyToPaise($amountInput);

                if ($outstandingPaise <= 0) {
                    throw new RuntimeException(
                        'This farmer has no outstanding payment balance.'
                    );
                }

                if ($paymentPaise > $outstandingPaise) {
                    throw new RuntimeException(
                        'Payment cannot exceed the outstanding balance of ' .
                        formatMoney($outstandingPaise / 100) . '.'
                    );
                }

                /*
                 * CASHBOOK INTEGRATION
                 *
                 * Cash payments require an open session for today's
                 * business date. Lock that session while recording
                 * the payment and cashbook entry.
                 *
                 * Non-cash payments do not reduce physical cash.
                 */
                $cashSessionId = null;

                if ($method === 'cash') {
                    $sessionStmt = $pdo->prepare(
                        "SELECT id
                         FROM daily_cash_sessions
                         WHERE business_date = ?
                           AND status = 'open'
                         FOR UPDATE"
                    );

                    $sessionStmt->execute([date('Y-m-d')]);
                    $sessionId = $sessionStmt->fetchColumn();

                    if ($sessionId === false) {
                        throw new RuntimeException(
                            'There is no open cash session for today. ' .
                            'Open today’s daily cash session before recording a cash payment.'
                        );
                    }

                    $cashSessionId = (int) $sessionId;
                }

                $paymentReference = generatePaymentReference();

                /*
                 * Save the supplier payment.
                 */
                $paymentStmt = $pdo->prepare(
                    "INSERT INTO supplier_payments (
                        payment_reference,
                        supplier_type,
                        farmer_id,
                        department_id,
                        amount,
                        payment_method,
                        transaction_reference,
                        paid_at,
                        recorded_by,
                        notes
                    ) VALUES (
                        ?, 'farmer', ?, NULL, ?, ?, ?, NOW(), ?, ?
                    )"
                );

                $paymentStmt->execute([
                    $paymentReference,
                    $farmerId,
                    $amountInput,
                    $method,
                    $transactionReference !== ''
                        ? $transactionReference
                        : null,
                    $userId,
                    $notes !== '' ? $notes : null
                ]);

                $paymentId = (int) $pdo->lastInsertId();

                /*
                 * Update the supplier ledger.
                 */
                $ledgerStmt = $pdo->prepare(
                    "INSERT INTO supplier_ledger (
                        supplier_type,
                        farmer_id,
                        department_id,
                        entry_type,
                        supply_receipt_id,
                        supplier_payment_id,
                        amount_due,
                        amount_paid,
                        description,
                        recorded_by
                    ) VALUES (
                        'farmer', ?, NULL, 'payment',
                        NULL, ?, 0.00, ?, ?, ?
                    )"
                );

                $ledgerStmt->execute([
                    $farmerId,
                    $paymentId,
                    $amountInput,
                    'Payment ' . $paymentReference .
                        ' recorded for ' . $farmer['farmer_code'],
                    $userId
                ]);

                /*
                 * If payment is cash, record the cash going out.
                 * supplier_payment_id is unique in cashbook_entries,
                 * helping prevent duplicate cashbook links.
                 */
                if ($method === 'cash') {
                    $cashbookStmt = $pdo->prepare(
                        "INSERT INTO cashbook_entries (
                            cash_session_id,
                            entry_type,
                            customer_payment_id,
                            supplier_payment_id,
                            expense_id,
                            amount_in,
                            amount_out,
                            description,
                            recorded_by
                        ) VALUES (
                            ?, 'supplier_payment', NULL, ?, NULL,
                            0.00, ?, ?, ?
                        )"
                    );

                    $cashbookStmt->execute([
                        $cashSessionId,
                        $paymentId,
                        $amountInput,
                        'Cash paid to farmer ' .
                            $farmer['farmer_code'] .
                            ' (' . $paymentReference . ')',
                        $userId
                    ]);
                }

                /*
                 * Audit log.
                 */
                $auditStmt = $pdo->prepare(
                    "INSERT INTO audit_logs (
                        user_id,
                        action,
                        entity_type,
                        entity_id,
                        details,
                        ip_address
                    ) VALUES (?, ?, ?, ?, ?, ?)"
                );

                $auditStmt->execute([
                    $userId,
                    'RECORD_SUPPLIER_PAYMENT',
                    'supplier_payment',
                    $paymentId,
                    json_encode([
                        'payment_reference' => $paymentReference,
                        'farmer_code' => $farmer['farmer_code'],
                        'amount' => $amountInput,
                        'payment_method' => $method,
                        'cashbook_recorded' => $method === 'cash'
                    ], JSON_THROW_ON_ERROR),
                    $_SERVER['REMOTE_ADDR'] ?? null
                ]);

                /*
                 * All changes succeed together or roll back together.
                 */
                $pdo->commit();
                $transactionStarted = false;

                header(
                    'Location: payments.php?success=' .
                    rawurlencode($paymentReference)
                );
                exit;

            } catch (Throwable $exception) {
                if ($transactionStarted && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($exception instanceof RuntimeException) {
                    $error = $exception->getMessage();
                } else {
                    error_log(
                        'AgroProcure supplier payment error: ' .
                        $exception->getMessage()
                    );

                    $error = 'Payment could not be recorded. No changes were saved. Check the PHP error log if the problem continues.';
                }
            }
        }
    }
}

/* ---------------- SUCCESS MESSAGE ---------------- */

if (isset($_GET['success']) && is_string($_GET['success'])) {
    $success = 'Payment recorded successfully. Reference: ' .
        $_GET['success'];
}

/* ---------------- FARMER BALANCES ---------------- */

$balanceSql = "
    SELECT
        f.id,
        f.farmer_code,
        f.full_name,
        COALESCE(SUM(sl.amount_due), 0) AS total_due,
        COALESCE(SUM(sl.amount_paid), 0) AS total_paid,
        COALESCE(SUM(sl.amount_due - sl.amount_paid), 0) AS outstanding
    FROM farmers f
    LEFT JOIN supplier_ledger sl
        ON sl.farmer_id = f.id
       AND sl.supplier_type = 'farmer'
    WHERE f.is_active = 1
    GROUP BY f.id, f.farmer_code, f.full_name
    ORDER BY f.full_name
";

$balances = $pdo->query($balanceSql)->fetchAll(PDO::FETCH_ASSOC);

/* ---------------- RECENT PAYMENTS ---------------- */

$paymentsSql = "
    SELECT
        sp.payment_reference,
        sp.amount,
        sp.payment_method,
        sp.paid_at,
        f.farmer_code,
        f.full_name,
        u.username AS recorded_by_name
    FROM supplier_payments sp
    INNER JOIN farmers f ON f.id = sp.farmer_id
    LEFT JOIN users u ON u.id = sp.recorded_by
    WHERE sp.supplier_type = 'farmer'
    ORDER BY sp.id DESC
    LIMIT 50
";

$payments = $pdo->query($paymentsSql)->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Farmer Payments | AgroProcure</title>
    <link rel="stylesheet" href="../../public/assets/css/supply.css">
</head>
<body>

<div class="supply-page">

    <header class="supply-header">
        <div>
            <p class="eyebrow">AGROPROCURE · FINANCIAL MANAGEMENT</p>
            <h1>Farmer Payments</h1>
            <p>Record supplier payments and track outstanding balances.</p>
        </div>
        <a class="back-link" href="stock.php">Back to Stock Report</a>
    </header>

    <?php if ($success !== ''): ?>
        <div class="notice success-notice" role="status">
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="notice error-notice" role="alert">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <section class="supply-card">
        <h2>Record a Payment</h2>
        <p class="section-description">
            Select a farmer and enter the amount actually paid.
            Payments exceeding the outstanding balance are rejected.
            Cash payments are recorded in the daily cashbook.
        </p>

        <form method="post" action="payments.php" class="supply-form">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrf_token()) ?>"
            >

            <div class="form-grid">
                <div class="form-group">
                    <label for="farmer_id">Farmer</label>
                    <select id="farmer_id" name="farmer_id" required>
                        <option value="">Select farmer</option>
                        <?php foreach ($balances as $farmerBalance): ?>
                            <?php if ((float) $farmerBalance['outstanding'] > 0): ?>
                                <option
                                    value="<?= (int) $farmerBalance['id'] ?>"
                                    <?= (string) ($_POST['farmer_id'] ?? '') ===
                                        (string) $farmerBalance['id'] ? 'selected' : '' ?>
                                >
                                    <?= e($farmerBalance['farmer_code']) ?>
                                    — <?= e($farmerBalance['full_name']) ?>
                                    (Due:
                                    <?= e(formatMoney($farmerBalance['outstanding'])) ?>)
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="amount">Payment Amount (₹)</label>
                    <input
                        id="amount"
                        name="amount"
                        type="number"
                        min="0.01"
                        step="0.01"
                        max="999999999999.99"
                        value="<?= e($_POST['amount'] ?? '') ?>"
                        placeholder="Enter amount"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="payment_method">Payment Method</label>
                    <select id="payment_method" name="payment_method" required>
                        <option value="">Select method</option>
                        <?php
                        $methods = [
                            'cash' => 'Cash',
                            'bank_transfer' => 'Bank Transfer',
                            'upi' => 'UPI',
                            'cheque' => 'Cheque',
                            'other' => 'Other'
                        ];

                        foreach ($methods as $value => $label):
                        ?>
                            <option
                                value="<?= e($value) ?>"
                                <?= ($_POST['payment_method'] ?? '') === $value
                                    ? 'selected' : '' ?>
                            >
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="transaction_reference">
                        Transaction Reference (optional)
                    </label>
                    <input
                        id="transaction_reference"
                        name="transaction_reference"
                        type="text"
                        maxlength="100"
                        value="<?= e($_POST['transaction_reference'] ?? '') ?>"
                        placeholder="Bank / UPI / cheque reference"
                    >
                </div>

                <div class="form-group full-width">
                    <label for="notes">Notes (optional)</label>
                    <textarea
                        id="notes"
                        name="notes"
                        maxlength="1000"
                        rows="3"
                        placeholder="Payment details or remarks"
                    ><?= e($_POST['notes'] ?? '') ?></textarea>
                </div>
            </div>

            <button type="submit" class="primary-button">
                Record Payment
            </button>
        </form>
    </section>

    <section class="supply-card">
        <h2>Farmer Outstanding Balances</h2>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Farmer ID</th>
                        <th>Farmer Name</th>
                        <th>Total Due</th>
                        <th>Total Paid</th>
                        <th>Outstanding</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$balances): ?>
                        <tr>
                            <td colspan="5">No farmer records found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($balances as $row): ?>
                            <tr>
                                <td><?= e($row['farmer_code']) ?></td>
                                <td><?= e($row['full_name']) ?></td>
                                <td><?= e(formatMoney($row['total_due'])) ?></td>
                                <td><?= e(formatMoney($row['total_paid'])) ?></td>
                                <td>
                                    <strong>
                                        <?= e(formatMoney($row['outstanding'])) ?>
                                    </strong>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="supply-card">
        <h2>Recent Farmer Payments</h2>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Payment Reference</th>
                        <th>Date</th>
                        <th>Farmer</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Recorded By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$payments): ?>
                        <tr>
                            <td colspan="6">No payments recorded yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?= e($payment['payment_reference']) ?></td>
                                <td><?= e($payment['paid_at']) ?></td>
                                <td>
                                    <?= e($payment['farmer_code']) ?>
                                    — <?= e($payment['full_name']) ?>
                                </td>
                                <td>
                                    <strong>
                                        <?= e(formatMoney($payment['amount'])) ?>
                                    </strong>
                                </td>
                                <td>
                                    <?= e(ucwords(str_replace(
                                        '_',
                                        ' ',
                                        $payment['payment_method']
                                    ))) ?>
                                </td>
                                <td>
                                    <?= e($payment['recorded_by_name'] ?? 'Unknown') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</div>
</body>
</html>
