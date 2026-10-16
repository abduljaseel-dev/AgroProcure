
<?php
require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_login();

$pageTitle = 'Supplier Payment History';

$today = date('Y-m-d');
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? $today;
$search = trim($_GET['search'] ?? '');
$supplierType = $_GET['supplier_type'] ?? '';
$paymentMethod = $_GET['payment_method'] ?? '';

$errors = [];
$rows = [];
$totalPayments = 0;
$farmerTotal = 0;
$departmentTotal = 0;
$paymentCount = 0;

function supplierHistoryEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function supplierHistoryMoney($value): string
{
    return number_format((float) $value, 2);
}

/* Validate filters */
if (
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) ||
    !checkdate(
        (int) substr($startDate, 5, 2),
        (int) substr($startDate, 8, 2),
        (int) substr($startDate, 0, 4)
    ) ||
    !checkdate(
        (int) substr($endDate, 5, 2),
        (int) substr($endDate, 8, 2),
        (int) substr($endDate, 0, 4)
    ) ||
    $startDate > $endDate
) {
    $errors[] = 'Please select a valid date range.';
    $startDate = date('Y-m-01');
    $endDate = $today;
}

if (!in_array($supplierType, ['', 'farmer', 'department'], true)) {
    $supplierType = '';
}

$allowedMethods = [
    '',
    'cash',
    'bank_transfer',
    'upi',
    'cheque',
    'other'
];

if (!in_array($paymentMethod, $allowedMethods, true)) {
    $paymentMethod = '';
}

try {
    $where = [
        'sp.paid_at >= :start_date',
        'sp.paid_at < :end_date'
    ];

    $params = [
        'start_date' => $startDate . ' 00:00:00',
        'end_date' => date(
            'Y-m-d H:i:s',
            strtotime($endDate . ' +1 day')
        )
    ];

    if ($supplierType !== '') {
        $where[] = 'sp.supplier_type = :supplier_type';
        $params['supplier_type'] = $supplierType;
    }

    if ($paymentMethod !== '') {
        $where[] = 'sp.payment_method = :payment_method';
        $params['payment_method'] = $paymentMethod;
    }

    if ($search !== '') {
        $where[] = "(
            sp.payment_reference LIKE :ref_search
            OR COALESCE(sp.transaction_reference, '') LIKE :txn_search
            OR COALESCE(sp.notes, '') LIKE :notes_search
            OR COALESCE(f.full_name, '') LIKE :farmer_search
            OR COALESCE(f.farmer_code, '') LIKE :farmer_code_search
            OR COALESCE(da.department_name, '') LIKE :dept_search
            OR COALESCE(da.department_code, '') LIKE :dept_code_search
        )";

        $term = '%' . $search . '%';

        $params['ref_search'] = $term;
        $params['txn_search'] = $term;
        $params['notes_search'] = $term;
        $params['farmer_search'] = $term;
        $params['farmer_code_search'] = $term;
        $params['dept_search'] = $term;
        $params['dept_code_search'] = $term;
    }

    $whereSql = implode(' AND ', $where);

    $baseSql = "
        FROM supplier_payments sp
        LEFT JOIN farmers f
            ON f.id = sp.farmer_id
        LEFT JOIN department_accounts da
            ON da.id = sp.department_id
        WHERE $whereSql
    ";

    /* Payment transaction register */
    $sql = "
        SELECT
            sp.id,
            sp.payment_reference,
            sp.supplier_type,
            sp.farmer_id,
            sp.department_id,
            sp.amount,
            sp.payment_method,
            sp.transaction_reference,
            sp.paid_at,
            sp.notes,

            CASE
                WHEN sp.supplier_type = 'farmer'
                    THEN COALESCE(f.full_name, 'Farmer record missing')
                WHEN sp.supplier_type = 'department'
                    THEN COALESCE(da.department_name, 'Department record missing')
                ELSE 'Unknown supplier'
            END AS supplier_name,

            CASE
                WHEN sp.supplier_type = 'farmer'
                    THEN f.farmer_code
                WHEN sp.supplier_type = 'department'
                    THEN da.department_code
                ELSE NULL
            END AS supplier_code

        $baseSql
        ORDER BY sp.paid_at DESC, sp.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Calculate totals from exactly the filtered rows */
    foreach ($rows as $row) {
        $amount = (float) $row['amount'];

        $totalPayments += $amount;
        $paymentCount++;

        if ($row['supplier_type'] === 'farmer') {
            $farmerTotal += $amount;
        } elseif ($row['supplier_type'] === 'department') {
            $departmentTotal += $amount;
        }
    }

} catch (Throwable $e) {
    error_log(
        'Supplier payment history report error: ' . $e->getMessage()
    );

    $errors[] = 'Unable to load supplier payment history. Please check the database schema and try again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= supplierHistoryEscape($pageTitle) ?> | AgroProcure</title>

    <link rel="stylesheet"
          href="/agroprocure/public/assets/css/reports.css">

    <style>
        .supplier-history-page .payment-amount {
            font-weight: 700;
            color: #14532d;
            white-space: nowrap;
        }

        .supplier-history-page .payment-reference {
            font-weight: 700;
            color: #173b5e;
            overflow-wrap: anywhere;
        }

        .supplier-history-page .supplier-code,
        .supplier-history-page .secondary-text {
            display: inline-block;
            margin-top: 3px;
            color: #64748b;
            font-size: 12px;
        }

        .supplier-history-page .payment-method-label {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 5px;
            background: #eef3f8;
            color: #334155;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        .supplier-history-page .report-filter select {
            width: 100%;
            min-height: 40px;
            padding: 9px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: #fff;
            color: #1e293b;
            font: inherit;
        }

        .supplier-history-page .report-table tfoot th {
            padding: 14px 12px;
            background: #edf3f8;
            color: #173b5e;
            border-top: 2px solid #cbd5e1;
            text-align: right;
        }

        .supplier-history-page .report-table tfoot th:first-child {
            text-align: left;
        }

        @media print {
            .supplier-history-page .report-filter,
            .supplier-history-page .report-header-actions {
                display: none !important;
            }

            .supplier-history-page .report-panel,
            .supplier-history-page .report-summary {
                break-inside: avoid;
            }
        }
    </style>
</head>
<body>

<div class="report-page supplier-history-page">

    <header class="report-header">
        <div>
            <p class="report-eyebrow">AGROPROCURE / REPORTS</p>
            <h1><?= supplierHistoryEscape($pageTitle) ?></h1>
            <p>Payment transactions recorded for farmers and the Agriculture Department.</p>
        </div>

        <div class="report-header-actions">
            <button type="button"
                    class="report-button"
                    onclick="window.print()">
                Print Report
            </button>
        </div>
    </header>

    <?php if ($errors): ?>
        <div class="report-error" role="alert">
            <?php foreach ($errors as $error): ?>
                <p><?= supplierHistoryEscape($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="report-panel">
        <form method="GET"
              action="/agroprocure/modules/reports/supplier-payment-history.php"
              class="report-filter">

            <div class="filter-field">
                <label for="start_date">From Date</label>
                <input type="date"
                       id="start_date"
                       name="start_date"
                       value="<?= supplierHistoryEscape($startDate) ?>"
                       required>
            </div>

            <div class="filter-field">
                <label for="end_date">To Date</label>
                <input type="date"
                       id="end_date"
                       name="end_date"
                       value="<?= supplierHistoryEscape($endDate) ?>"
                       required>
            </div>

            <div class="filter-field">
                <label for="supplier_type">Supplier Type</label>
                <select id="supplier_type" name="supplier_type">
                    <option value="" <?= $supplierType === '' ? 'selected' : '' ?>>
                        All Suppliers
                    </option>
                    <option value="farmer" <?= $supplierType === 'farmer' ? 'selected' : '' ?>>
                        Farmers
                    </option>
                    <option value="department" <?= $supplierType === 'department' ? 'selected' : '' ?>>
                        Agriculture Department
                    </option>
                </select>
            </div>

            <div class="filter-field">
                <label for="payment_method">Payment Method</label>
                <select id="payment_method" name="payment_method">
                    <option value="" <?= $paymentMethod === '' ? 'selected' : '' ?>>
                        All Methods
                    </option>
                    <option value="cash" <?= $paymentMethod === 'cash' ? 'selected' : '' ?>>
                        Cash
                    </option>
                    <option value="bank_transfer" <?= $paymentMethod === 'bank_transfer' ? 'selected' : '' ?>>
                        Bank Transfer
                    </option>
                    <option value="upi" <?= $paymentMethod === 'upi' ? 'selected' : '' ?>>
                        UPI
                    </option>
                    <option value="cheque" <?= $paymentMethod === 'cheque' ? 'selected' : '' ?>>
                        Cheque
                    </option>
                    <option value="other" <?= $paymentMethod === 'other' ? 'selected' : '' ?>>
                        Other
                    </option>
                </select>
            </div>

            <div class="filter-field">
                <label for="search">Search</label>
                <input type="search"
                       id="search"
                       name="search"
                       value="<?= supplierHistoryEscape($search) ?>"
                       placeholder="Supplier, code or reference">
            </div>

            <div class="filter-field filter-actions">
                <button type="submit" class="report-button">
                    Generate Report
                </button>
            </div>
        </form>
    </section>

    <section class="report-summary">
        <article class="summary-card">
            <span>Total Payments</span>
            <strong>₹<?= supplierHistoryMoney($totalPayments) ?></strong>
            <small><?= number_format($paymentCount) ?> transaction(s)</small>
        </article>

        <article class="summary-card">
            <span>Paid to Farmers</span>
            <strong>₹<?= supplierHistoryMoney($farmerTotal) ?></strong>
            <small>Filtered farmer payments</small>
        </article>

        <article class="summary-card">
            <span>Paid to Department</span>
            <strong>₹<?= supplierHistoryMoney($departmentTotal) ?></strong>
            <small>Filtered department payments</small>
        </article>
    </section>

    <section class="report-panel">
        <div class="report-table-heading">
            <div>
                <h2>Supplier Payment Register</h2>
                <p>
                    <?= supplierHistoryEscape($startDate) ?>
                    to
                    <?= supplierHistoryEscape($endDate) ?>
                </p>
            </div>
        </div>

        <div class="report-table-wrapper">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Payment Date</th>
                        <th>Payment Reference</th>
                        <th>Supplier</th>
                        <th>Type</th>
                        <th>Method</th>
                        <th>Transaction Reference</th>
                        <th>Amount Paid</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="7">
                                No supplier payments found for the selected filters.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td>
                                    <?= supplierHistoryEscape(
                                        date(
                                            'd M Y, h:i A',
                                            strtotime($row['paid_at'])
                                        )
                                    ) ?>
                                </td>

                                <td>
                                    <span class="payment-reference">
                                        <?= supplierHistoryEscape(
                                            $row['payment_reference']
                                        ) ?>
                                    </span>
                                </td>

                                <td>
                                    <strong>
                                        <?= supplierHistoryEscape(
                                            $row['supplier_name']
                                        ) ?>
                                    </strong>

                                    <?php if (!empty($row['supplier_code'])): ?>
                                        <br>
                                        <span class="supplier-code">
                                            <?= supplierHistoryEscape(
                                                $row['supplier_code']
                                            ) ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if (!empty($row['notes'])): ?>
                                        <br>
                                        <span class="secondary-text">
                                            <?= supplierHistoryEscape(
                                                $row['notes']
                                            ) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= $row['supplier_type'] === 'farmer'
                                        ? 'Farmer'
                                        : ($row['supplier_type'] === 'department'
                                            ? 'Department'
                                            : 'Unknown') ?>
                                </td>

                                <td>
                                    <span class="payment-method-label">
                                        <?= supplierHistoryEscape(
                                            ucwords(str_replace(
                                                '_',
                                                ' ',
                                                $row['payment_method']
                                            ))
                                        ) ?>
                                    </span>
                                </td>

                                <td>
                                    <?= supplierHistoryEscape(
                                        $row['transaction_reference'] ?: '—'
                                    ) ?>
                                </td>

                                <td class="payment-amount">
                                    ₹<?= supplierHistoryMoney($row['amount']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>

                <tfoot>
                    <tr>
                        <th colspan="6">Total Payments in This Report</th>
                        <th>₹<?= supplierHistoryMoney($totalPayments) ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>

        <footer class="report-footer">
            <span>AgroProcure Supplier Payment History</span>
            <span>Generated <?= date('d M Y, h:i A') ?></span>
        </footer>
    </section>

    <p class="report-note">
        <strong>Important:</strong>
        This report lists records in the supplier_payments table. It does
        not independently verify bank or cash transfers, nor does it
        determine outstanding balances. Use the Supplier Outstanding
        Report and supplier ledger to reconcile payment records.
    </p>

</div>
</body>
</html>
