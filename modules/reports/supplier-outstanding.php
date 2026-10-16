
<?php
require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_login();

$pageTitle = 'Supplier Outstanding & Payment Report';

$supplierType = $_GET['supplier_type'] ?? 'all';
$balanceFilter = $_GET['balance'] ?? 'all';
$search = trim($_GET['search'] ?? '');

if (!in_array($supplierType, ['all', 'farmer', 'department'], true)) {
    $supplierType = 'all';
}

if (!in_array($balanceFilter, ['all', 'outstanding', 'settled'], true)) {
    $balanceFilter = 'all';
}

$rows = [];
$errors = [];

$totalSupply = 0.0;
$totalPaid = 0.0;
$totalOutstanding = 0.0;
$suppliersOwed = 0;

try {
    $sql = "
        SELECT
            sl.supplier_type,
            sl.farmer_id,
            sl.department_id,

            COALESCE(SUM(sl.amount_due), 0) AS total_due,
            COALESCE(SUM(sl.amount_paid), 0) AS total_paid,

            CASE
                WHEN sl.supplier_type = 'farmer'
                    THEN f.farmer_code
                ELSE da.department_code
            END AS supplier_code,

            CASE
                WHEN sl.supplier_type = 'farmer'
                    THEN f.full_name
                ELSE da.department_name
            END AS supplier_name,

            CASE
                WHEN sl.supplier_type = 'farmer'
                    THEN f.phone
                ELSE da.phone
            END AS supplier_phone

        FROM supplier_ledger sl

        LEFT JOIN farmers f
            ON sl.supplier_type = 'farmer'
           AND f.id = sl.farmer_id

        LEFT JOIN department_accounts da
            ON sl.supplier_type = 'department'
           AND da.id = sl.department_id

        WHERE 1 = 1
    ";

    $params = [];

    if ($supplierType !== 'all') {
        $sql .= " AND sl.supplier_type = :supplier_type";
        $params[':supplier_type'] = $supplierType;
    }

    if ($search !== '') {
        $sql .= "
            AND (
                f.full_name LIKE :search_name
                OR f.farmer_code LIKE :search_code
                OR f.phone LIKE :search_phone
                OR da.department_name LIKE :search_department
                OR da.department_code LIKE :search_department_code
                OR da.phone LIKE :search_department_phone
            )
        ";

        $searchTerm = '%' . $search . '%';

        $params[':search_name'] = $searchTerm;
        $params[':search_code'] = $searchTerm;
        $params[':search_phone'] = $searchTerm;
        $params[':search_department'] = $searchTerm;
        $params[':search_department_code'] = $searchTerm;
        $params[':search_department_phone'] = $searchTerm;
    }

    $sql .= "
        GROUP BY
            sl.supplier_type,
            sl.farmer_id,
            sl.department_id,
            f.farmer_code,
            f.full_name,
            f.phone,
            da.department_code,
            da.department_name,
            da.phone

        ORDER BY supplier_name ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $allRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allRows as $row) {
        $due = (float) $row['total_due'];
        $paid = (float) $row['total_paid'];
        $balance = $due - $paid;

        $row['balance'] = $balance;

        if ($balanceFilter === 'outstanding' && $balance <= 0.005) {
            continue;
        }

        if ($balanceFilter === 'settled' && $balance > 0.005) {
            continue;
        }

        if ($balance > 0.005) {
            $suppliersOwed++;
        }

        $totalSupply += $due;
        $totalPaid += $paid;
        $totalOutstanding += $balance;

        if ($row['supplier_type'] === 'farmer') {
            $row['supplier_name'] = $row['supplier_name']
                ?: ('Farmer #' . $row['farmer_id']);

            $row['supplier_code'] = $row['supplier_code'] ?: '—';
        } else {
            $row['supplier_name'] = $row['supplier_name']
                ?: ('Department #' . $row['department_id']);

            $row['supplier_code'] = $row['supplier_code'] ?: '—';
        }

        $rows[] = $row;
    }
} catch (PDOException $ex) {
    error_log('Supplier outstanding report error: ' . $ex->getMessage());
    $errors[] = 'Unable to load supplier balances. Please check the database configuration.';
}

function supplierReportMoney($amount)
{
    return number_format((float) $amount, 2);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= e($pageTitle) ?> | AgroProcure</title>

    <link rel="stylesheet"
          href="/agroprocure/public/assets/css/reports.css">
</head>
<body>

<div class="report-page">

    <header class="report-header">
        <div>
            <p class="report-eyebrow">AGROPROCURE / REPORTS</p>
            <h1><?= e($pageTitle) ?></h1>
            <p>Monitor supplier payments and outstanding balances.</p>
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
                <p><?= e($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<br>
    <section class="report-summary">
        <article class="summary-card">
            <span>Total Supply Value</span>
            <strong>₹<?= supplierReportMoney($totalSupply) ?></strong>
            <small>Net ledger amounts due</small>
        </article>

        <article class="summary-card">
            <span>Total Payments</span>
            <strong>₹<?= supplierReportMoney($totalPaid) ?></strong>
            <small>Payments recorded in ledger</small>
        </article>

        <article class="summary-card">
            <span>Outstanding Balance</span>
            <strong>₹<?= supplierReportMoney($totalOutstanding) ?></strong>
            <small>Due minus paid</small>
        </article>

        <article class="summary-card">
            <span>Suppliers Owed</span>
            <strong><?= number_format($suppliersOwed) ?></strong>
            <small>Suppliers with a positive balance</small>
        </article>
    </section>

    <section class="report-panel">
        <form method="GET"
              action="/agroprocure/modules/reports/supplier-outstanding.php"
              class="report-filter">

            <div class="filter-field">
                <label for="search">Search Supplier</label>
                <input type="text"
                       id="search"
                       name="search"
                       value="<?= e($search) ?>"
                       placeholder="Name, code or phone"
                       maxlength="150">
            </div>

            <div class="filter-field">
                <label for="supplier_type">Supplier Type</label>
                <select id="supplier_type" name="supplier_type">
                    <option value="all"
                        <?= $supplierType === 'all' ? 'selected' : '' ?>>
                        All Suppliers
                    </option>

                    <option value="farmer"
                        <?= $supplierType === 'farmer' ? 'selected' : '' ?>>
                        Farmers
                    </option>

                    <option value="department"
                        <?= $supplierType === 'department' ? 'selected' : '' ?>>
                        Agriculture Department
                    </option>
                </select>
            </div>

            <div class="filter-field">
                <label for="balance">Payment Status</label>
                <select id="balance" name="balance">
                    <option value="all"
                        <?= $balanceFilter === 'all' ? 'selected' : '' ?>>
                        All Balances
                    </option>

                    <option value="outstanding"
                        <?= $balanceFilter === 'outstanding' ? 'selected' : '' ?>>
                        Amount Outstanding
                    </option>

                    <option value="settled"
                        <?= $balanceFilter === 'settled' ? 'selected' : '' ?>>
                        Settled / No Amount Due
                    </option>
                </select>
            </div>

            <div class="filter-field filter-actions">
                <button type="submit" class="report-button">
                    Apply Filters
                </button>

                <a href="/agroprocure/modules/reports/supplier-outstanding.php"
                   class="report-reset">
                    Reset
                </a>
            </div>
        </form>
    </section>

    <section class="report-panel">
        <div class="report-table-heading">
            <div>
                <h2>Supplier Balances</h2>
                <p>
                    Showing <?= number_format(count($rows)) ?>
                    supplier account(s)
                </p>
            </div>
        </div>

        <div class="report-table-wrapper">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Supplier</th>
                        <th>Supplier Code</th>
                        <th>Type</th>
                        <th>Total Due</th>
                        <th>Total Paid</th>
                        <th>Outstanding</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$errors && !$rows): ?>
                        <tr>
                            <td colspan="8" class="report-empty">
                                No supplier ledger records match your filters.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $index => $row): ?>
                            <?php
                            $balance = (float) $row['balance'];

                            if ($balance > 0.005) {
                                $status = 'Outstanding';
                                $statusClass = 'status-outstanding';
                            } elseif ($balance < -0.005) {
                                $status = 'Credit Balance';
                                $statusClass = 'status-credit';
                            } else {
                                $status = 'Settled';
                                $statusClass = 'status-settled';
                            }
                            ?>

                            <tr>
                                <td><?= $index + 1 ?></td>

                                <td>
                                    <strong>
                                        <?= e($row['supplier_name']) ?>
                                    </strong>

                                    <?php if (!empty($row['supplier_phone'])): ?>
                                        <small class="report-subtext">
                                            <?= e($row['supplier_phone']) ?>
                                        </small>
                                    <?php endif; ?>
                                </td>

                                <td><?= e($row['supplier_code']) ?></td>

                                <td>
                                    <?= $row['supplier_type'] === 'farmer'
                                        ? 'Farmer'
                                        : 'Department' ?>
                                </td>

                                <td>
                                    ₹<?= supplierReportMoney($row['total_due']) ?>
                                </td>

                                <td>
                                    ₹<?= supplierReportMoney($row['total_paid']) ?>
                                </td>

                                <td>
                                    <strong>
                                        ₹<?= supplierReportMoney($balance) ?>
                                    </strong>
                                </td>

                                <td>
                                    <span class="supplier-status <?= e($statusClass) ?>">
                                        <?= e($status) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <footer class="report-footer">
            <span>Supplier ledger summary</span>
            <span>Generated <?= e(date('d M Y, h:i A')) ?></span>
        </footer>
    </section>

    <p class="report-note">
        <strong>Accounting note:</strong>
        Balances are calculated from supplier ledger entries as
        total amount due minus total amount paid. Any adjustment
        or reversal must be correctly represented in the ledger.
        A negative balance indicates a credit balance and should
        be reviewed before making another payment.
    </p>

</div>

</body>
</html>
