
<?php
require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_login();
require_role(['admin', 'vendor', 'staff']);

$pageTitle = 'Department Ledger';

$errors = [];
$departments = [];
$transactions = [];

$summary = [
    'total_supplies' => 0,
    'total_payments' => 0,
    'outstanding' => 0,
];

$departmentFilter = filter_input(INPUT_GET, 'department_id', FILTER_VALIDATE_INT);
$departmentFilter = (
    $departmentFilter !== false &&
    $departmentFilter !== null &&
    $departmentFilter > 0
) ? $departmentFilter : 0;

$entryType = trim((string) ($_GET['entry_type'] ?? ''));
$allowedEntryTypes = ['supply', 'payment', 'adjustment', 'reversal'];

if ($entryType !== '' && !in_array($entryType, $allowedEntryTypes, true)) {
    $entryType = '';
}

$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));

function ledgerValidDate(string $date): bool
{
    if ($date === '') {
        return true;
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $dateErrors = DateTimeImmutable::getLastErrors();

    return $parsed !== false
        && ($dateErrors === false
            || (
                $dateErrors['warning_count'] === 0
                && $dateErrors['error_count'] === 0
            ))
        && $parsed->format('Y-m-d') === $date;
}

function ledgerMoney($amount): string
{
    return '₹' . number_format((float) $amount, 2, '.', ',');
}

if (!ledgerValidDate($dateFrom)) {
    $errors[] = 'The start date is invalid.';
}

if (!ledgerValidDate($dateTo)) {
    $errors[] = 'The end date is invalid.';
}

if (
    $dateFrom !== '' &&
    $dateTo !== '' &&
    ledgerValidDate($dateFrom) &&
    ledgerValidDate($dateTo) &&
    $dateFrom > $dateTo
) {
    $errors[] = 'The start date cannot be later than the end date.';
}

try {
    // Load department options.
    $departmentStmt = $pdo->query("
        SELECT id, department_code, department_name
        FROM department_accounts
        ORDER BY department_name
    ");

    $departments = $departmentStmt->fetchAll(PDO::FETCH_ASSOC);

    // All-time financial summary for the selected department(s).
    $summarySql = "
        SELECT
            COALESCE(SUM(
                CASE
                    WHEN sl.entry_type = 'supply'
                    THEN sl.amount_due
                    ELSE 0
                END
            ), 0) AS total_supplies,

            COALESCE(SUM(
                CASE
                    WHEN sl.entry_type = 'payment'
                    THEN sl.amount_paid
                    ELSE 0
                END
            ), 0) AS total_payments,

            COALESCE(SUM(sl.amount_due), 0)
            - COALESCE(SUM(sl.amount_paid), 0) AS outstanding

        FROM supplier_ledger sl
        WHERE sl.supplier_type = 'department'
    ";

    $summaryParams = [];

    if ($departmentFilter > 0) {
        $summarySql .= " AND sl.department_id = :summary_department_id";
        $summaryParams['summary_department_id'] = $departmentFilter;
    }

    $summaryStmt = $pdo->prepare($summarySql);
    $summaryStmt->execute($summaryParams);

    $summaryResult = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    if ($summaryResult) {
        $summary = $summaryResult;
    }

    /*
     * Transaction history.
     *
     * Balance after entry is calculated using all ledger entries
     * for that department up to the current entry's timestamp and ID.
     * The calculation is independent of the visible date/type filters.
     */
    $transactionSql = "
        SELECT
            sl.id,
            sl.entry_type,
            sl.amount_due,
            sl.amount_paid,
            sl.description,
            sl.created_at,
            d.department_code,
            d.department_name,
            u.username AS recorded_by_name,

            (
                SELECT COALESCE(
                    SUM(l2.amount_due - l2.amount_paid),
                    0
                )
                FROM supplier_ledger l2
                WHERE l2.supplier_type = 'department'
                  AND l2.department_id = sl.department_id
                  AND (
                      l2.created_at < sl.created_at
                      OR (
                          l2.created_at = sl.created_at
                          AND l2.id <= sl.id
                      )
                  )
            ) AS balance_after_entry

        FROM supplier_ledger sl

        INNER JOIN department_accounts d
            ON d.id = sl.department_id

        LEFT JOIN users u
            ON u.id = sl.recorded_by

        WHERE sl.supplier_type = 'department'
    ";

    $transactionParams = [];

    if ($departmentFilter > 0) {
        $transactionSql .= " AND sl.department_id = :department_id";
        $transactionParams['department_id'] = $departmentFilter;
    }

    if ($entryType !== '') {
        $transactionSql .= " AND sl.entry_type = :entry_type";
        $transactionParams['entry_type'] = $entryType;
    }

    if ($dateFrom !== '' && ledgerValidDate($dateFrom)) {
        $transactionSql .= " AND sl.created_at >= :date_from";
        $transactionParams['date_from'] = $dateFrom . ' 00:00:00';
    }

    if ($dateTo !== '' && ledgerValidDate($dateTo)) {
        $nextDate = (new DateTimeImmutable($dateTo))
            ->modify('+1 day')
            ->format('Y-m-d');

        $transactionSql .= " AND sl.created_at < :date_to";
        $transactionParams['date_to'] = $nextDate . ' 00:00:00';
    }

    $transactionSql .= "
        ORDER BY sl.created_at DESC, sl.id DESC
        LIMIT 1000
    ";

    if (empty($errors)) {
        $transactionStmt = $pdo->prepare($transactionSql);
        $transactionStmt->execute($transactionParams);
        $transactions = $transactionStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log('AgroProcure Department Ledger Error: ' . $e->getMessage());
    $errors[] = 'Unable to load the department ledger. Please check the application error log.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> | AgroProcure</title>
    <link rel="stylesheet" href="/agroprocure/public/assets/css/department-ledger.css">
</head>
<body>

<div class="ledger-page">

    <header class="ledger-header">
        <div>
            <p class="eyebrow">AGROPROCURE / FINANCIAL MANAGEMENT</p>
            <h1>Department Ledger</h1>
            <p class="header-description">
                Track department supplies, payments and outstanding balances.
            </p>
        </div>

        <a class="back-link" href="index.php">
            ← Department Accounts
        </a>
    </header>

    <nav class="ledger-nav" aria-label="Department navigation">
        <a href="index.php">Departments</a>
        <a href="receipts.php">Supply Receipts</a>
        <a href="stock.php">Department Stock</a>
        <a href="payments.php">Make Payment</a>
        <a class="active" href="ledger.php">Department Ledger</a>
    </nav>

    <?php if (!empty($errors)): ?>
        <section class="notice notice-error" role="alert">
            <?php foreach ($errors as $error): ?>
                <p><?= e($error) ?></p>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <section class="summary-grid">
        <article class="summary-card">
            <span class="summary-label">Total Recorded Supplies</span>
            <strong><?= ledgerMoney($summary['total_supplies']) ?></strong>
            <span class="summary-note">All-time supply entries</span>
        </article>

        <article class="summary-card">
            <span class="summary-label">Total Recorded Payments</span>
            <strong><?= ledgerMoney($summary['total_payments']) ?></strong>
            <span class="summary-note">Payments entered in the ledger</span>
        </article>

        <article class="summary-card summary-outstanding">
            <span class="summary-label">Outstanding Balance</span>
            <strong><?= ledgerMoney($summary['outstanding']) ?></strong>
            <span class="summary-note">Recorded amounts due minus amounts paid</span>
        </article>
    </section>

    <section class="panel">

        <div class="panel-heading">
            <div>
                <h2>Transaction History</h2>
                <p>Search and review department ledger entries.</p>
            </div>

            <span class="record-count">
                <?= number_format(count($transactions)) ?> records shown
            </span>
        </div>

        <form method="get" action="ledger.php" class="filter-form">

            <div class="filter-field">
                <label for="department_id">Department</label>
                <select name="department_id" id="department_id">
                    <option value="0">All departments</option>

                    <?php foreach ($departments as $department): ?>
                        <option
                            value="<?= (int) $department['id'] ?>"
                            <?= $departmentFilter === (int) $department['id'] ? 'selected' : '' ?>
                        >
                            <?= e($department['department_code']) ?>
                            — <?= e($department['department_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label for="entry_type">Transaction Type</label>
                <select name="entry_type" id="entry_type">
                    <option value="">All types</option>

                    <?php foreach ($allowedEntryTypes as $type): ?>
                        <option
                            value="<?= e($type) ?>"
                            <?= $entryType === $type ? 'selected' : '' ?>
                        >
                            <?= e(ucfirst($type)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label for="date_from">From Date</label>
                <input
                    type="date"
                    name="date_from"
                    id="date_from"
                    value="<?= e($dateFrom) ?>"
                >
            </div>

            <div class="filter-field">
                <label for="date_to">To Date</label>
                <input
                    type="date"
                    name="date_to"
                    id="date_to"
                    value="<?= e($dateTo) ?>"
                >
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn-primary">
                    Apply Filters
                </button>
                <a href="ledger.php" class="btn-secondary">Reset</a>
            </div>

        </form>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Entry ID</th>
                        <th>Date &amp; Time</th>
                        <th>Department</th>
                        <th>Type</th>
                        <th class="numeric">Supply / Debit</th>
                        <th class="numeric">Payment / Credit</th>
                        <th class="numeric">Balance After Entry</th>
                        <th>Description / Reference</th>
                        <th>Recorded By</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (empty($transactions)): ?>
                    <tr>
                        <td colspan="9" class="empty-state">
                            No department ledger entries match these filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($transactions as $transaction): ?>
                        <tr>
                            <td>#<?= (int) $transaction['id'] ?></td>

                            <td>
                                <?= e(date(
                                    'd M Y, h:i A',
                                    strtotime($transaction['created_at'])
                                )) ?>
                            </td>

                            <td>
                                <strong><?= e($transaction['department_code']) ?></strong>
                                <span class="department-name">
                                    <?= e($transaction['department_name']) ?>
                                </span>
                            </td>

                            <td>
                                <span class="type-badge type-<?= e($transaction['entry_type']) ?>">
                                    <?= e(ucfirst($transaction['entry_type'])) ?>
                                </span>
                            </td>

                            <td class="numeric">
                                <?= ledgerMoney($transaction['amount_due']) ?>
                            </td>

                            <td class="numeric">
                                <?= ledgerMoney($transaction['amount_paid']) ?>
                            </td>

                            <td class="numeric">
                                <strong>
                                    <?= ledgerMoney($transaction['balance_after_entry']) ?>
                                </strong>
                            </td>

                            <td class="description-cell">
                                <?= trim((string) $transaction['description']) !== ''
                                    ? e($transaction['description'])
                                    : '—' ?>
                            </td>

                            <td>
                                <?= e($transaction['recorded_by_name'] ?: 'System / unavailable') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <p class="table-footnote">
            Showing up to 1,000 entries. Supply and payment columns show
            individual ledger transactions. Balance After Entry shows the
            department's cumulative ledger balance after that entry.
            Summary balances are all-time balances; date filters apply only
            to the transaction list.
        </p>

    </section>

    <footer class="ledger-footer">
        AgroProcure · Department Financial Records
    </footer>

</div>

</body>
</html>
