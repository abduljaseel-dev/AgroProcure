<?php
// AgroProcure - Department Stock & Supply Report

require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_login();
require_role(['admin', 'vendor', 'staff']);

$errors = [];
$departmentFilter = filter_input(INPUT_GET, 'department_id', FILTER_VALIDATE_INT);
$departmentFilter = ($departmentFilter && $departmentFilter > 0)
    ? $departmentFilter
    : 0;

function departmentStockMoney($amount): string
{
    return '₹' . number_format((float) $amount, 2);
}

try {
    // Department list for the filter.
    $departmentsStmt = $pdo->query("
        SELECT id, department_code, department_name
        FROM department_accounts
        ORDER BY department_name
    ");
    $departments = $departmentsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Summary of posted supply receipts by department.
    $summarySql = "
        SELECT
            d.id AS department_id,
            d.department_code,
            d.department_name,
            COUNT(DISTINCT sr.id) AS receipt_count,
            COALESCE(SUM(sri.line_total), 0) AS supply_value,
            COALESCE(SUM(sl.amount_due), 0) AS ledger_due,
            COALESCE(SUM(sl.amount_paid), 0) AS ledger_paid
        FROM department_accounts d
        LEFT JOIN supply_receipts sr
            ON sr.department_id = d.id
           AND sr.supplier_type = 'department'
           AND sr.farmer_id IS NULL
           AND sr.status = 'posted'
        LEFT JOIN supply_receipt_items sri
            ON sri.receipt_id = sr.id
        LEFT JOIN supplier_ledger sl
            ON sl.department_id = d.id
           AND sl.supplier_type = 'department'
        GROUP BY d.id, d.department_code, d.department_name
        ORDER BY d.department_name
    ";

    /*
     * Do not aggregate receipt items and supplier ledger rows together:
     * that can multiply totals. The detailed queries below calculate them
     * independently. Summary totals are also calculated independently.
     */
    $summarySql = "
        SELECT
            d.id AS department_id,
            d.department_code,
            d.department_name,
            COALESCE(r.receipt_count, 0) AS receipt_count,
            COALESCE(r.supply_value, 0) AS supply_value,
            COALESCE(l.ledger_due, 0) AS ledger_due,
            COALESCE(l.ledger_paid, 0) AS ledger_paid
        FROM department_accounts d
        LEFT JOIN (
            SELECT
                sr.department_id,
                COUNT(DISTINCT sr.id) AS receipt_count,
                SUM(sri.line_total) AS supply_value
            FROM supply_receipts sr
            INNER JOIN supply_receipt_items sri
                ON sri.receipt_id = sr.id
            WHERE sr.supplier_type = 'department'
              AND sr.farmer_id IS NULL
              AND sr.status = 'posted'
            GROUP BY sr.department_id
        ) r ON r.department_id = d.id
        LEFT JOIN (
            SELECT
                department_id,
                SUM(amount_due) AS ledger_due,
                SUM(amount_paid) AS ledger_paid
            FROM supplier_ledger
            WHERE supplier_type = 'department'
            GROUP BY department_id
        ) l ON l.department_id = d.id
    ";

    if ($departmentFilter > 0) {
        $summarySql .= " WHERE d.id = :department_id";
    }

    $summarySql .= " ORDER BY d.department_name";

    $summaryStmt = $pdo->prepare($summarySql);

    if ($departmentFilter > 0) {
        $summaryStmt->execute(['department_id' => $departmentFilter]);
    } else {
        $summaryStmt->execute();
    }

    $summaryRows = $summaryStmt->fetchAll(PDO::FETCH_ASSOC);

    // Posted department receipts with item-level details.
    $receiptSql = "
        SELECT
            sr.id AS receipt_id,
            sr.receipt_number,
            sr.received_at,
            d.department_code,
            d.department_name,
            COUNT(sri.id) AS item_count,
            COALESCE(SUM(sri.line_total), 0) AS receipt_total
        FROM supply_receipts sr
        INNER JOIN department_accounts d
            ON d.id = sr.department_id
        LEFT JOIN supply_receipt_items sri
            ON sri.receipt_id = sr.id
        WHERE sr.supplier_type = 'department'
          AND sr.farmer_id IS NULL
          AND sr.status = 'posted'
    ";

    if ($departmentFilter > 0) {
        $receiptSql .= " AND sr.department_id = :department_id";
    }

    $receiptSql .= "
        GROUP BY
            sr.id,
            sr.receipt_number,
            sr.received_at,
            d.department_code,
            d.department_name
        ORDER BY sr.received_at DESC, sr.id DESC
        LIMIT 200
    ";

    $receiptStmt = $pdo->prepare($receiptSql);

    if ($departmentFilter > 0) {
        $receiptStmt->execute(['department_id' => $departmentFilter]);
    } else {
        $receiptStmt->execute();
    }

    $receipts = $receiptStmt->fetchAll(PDO::FETCH_ASSOC);

    // Current stock from lots originating from posted department receipts.

    // Current stock from lots originating from posted department receipts.
    // stock_lots links to supply_receipt_items using receipt_item_id.
    $stockSql = "
        SELECT
            d.department_code,
            d.department_name,
            g.goods_code,
            g.goods_name,
            g.unit,
            COALESCE(SUM(sl.original_quantity), 0) AS quantity_received,
            COALESCE(SUM(sl.remaining_quantity), 0) AS quantity_remaining,
            COALESCE(
                SUM(
                    (sl.original_quantity - sl.remaining_quantity)
                    * sri.unit_cost
                ), 0
            ) AS estimated_cost_of_depleted_stock,
            COALESCE(
                SUM(sl.remaining_quantity * sri.unit_cost), 0
            ) AS remaining_stock_value
        FROM stock_lots sl
        INNER JOIN supply_receipt_items sri
            ON sri.id = sl.receipt_item_id
        INNER JOIN supply_receipts sr
            ON sr.id = sri.receipt_id
        INNER JOIN department_accounts d
            ON d.id = sr.department_id
        INNER JOIN goods g
            ON g.id = sri.goods_id
        WHERE sr.supplier_type = 'department'
          AND sr.farmer_id IS NULL
          AND sr.status = 'posted'
    ";

    if ($departmentFilter > 0) {
        $stockSql .= " AND sr.department_id = :department_id";
    }

    $stockSql .= "
        GROUP BY
            d.department_code,
            d.department_name,
            g.goods_code,
            g.goods_name,
            g.unit
        ORDER BY d.department_name, g.goods_name
    ";

    $stockStmt = $pdo->prepare($stockSql);

    if ($departmentFilter > 0) {
        $stockStmt->execute(['department_id' => $departmentFilter]);
    } else {
        $stockStmt->execute();
    }

    $stockRows = $stockStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log('AgroProcure department stock report: ' . $e->getMessage());
    $errors[] = 'Unable to load the department stock report. Check the application error log for details.';
}

$totalSupplyValue = 0;
$totalOutstanding = 0;
$totalReceipts = 0;

foreach ($summaryRows ?? [] as $row) {
    $totalSupplyValue += (float) $row['supply_value'];
    $totalOutstanding += (float) $row['ledger_due'] - (float) $row['ledger_paid'];
    $totalReceipts += (int) $row['receipt_count'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Stock Report | AgroProcure</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #f3f6fa;
            color: #1d2939;
            font-family: Arial, Helvetica, sans-serif;
        }
        .page { max-width: 1500px; margin: auto; padding: 26px; }
        .header {
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 15px; margin-bottom: 22px;
        }
        h1 { margin: 0 0 8px; font-size: 28px; color: #142b4a; }
        h2 { margin: 0 0 16px; font-size: 19px; color: #142b4a; }
        .subtitle { margin: 0; color: #667085; }
        .nav { display: flex; flex-wrap: wrap; gap: 9px; }
        .nav a, button {
            display: inline-block; padding: 10px 13px; border-radius: 7px;
            border: 0; background: #174a78; color: #fff; font-weight: 700;
            text-decoration: none; font-size: 13px; cursor: pointer;
        }
        .nav a { background: #e5edf6; color: #17375e; }
        .panel {
            background: #fff; border: 1px solid #e1e7ef; border-radius: 12px;
            padding: 21px; margin-bottom: 22px;
            box-shadow: 0 3px 12px rgba(16, 24, 40, .04);
        }
        .cards {
            display: grid; grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 15px; margin-bottom: 22px;
        }
        .card {
            padding: 20px; border-radius: 11px; background: #fff;
            border: 1px solid #e1e7ef;
        }
        .card small { display: block; color: #667085; margin-bottom: 10px; }
        .card strong { font-size: 24px; color: #17375e; overflow-wrap: anywhere; }
        .filter {
            display: flex; align-items: end; gap: 12px; flex-wrap: wrap;
        }
        .filter label { display: block; margin-bottom: 7px; font-size: 13px; font-weight: 700; }
        select {
            min-width: 280px; max-width: 100%; padding: 10px;
            border: 1px solid #cfd8e3; border-radius: 7px; background: #fff;
        }
        .table-wrap { overflow-x: auto; width: 100%; }
        table { width: 100%; min-width: 820px; border-collapse: collapse; }
        th, td {
            text-align: left; padding: 12px; border-bottom: 1px solid #e9edf2;
            font-size: 13px; vertical-align: top;
        }
        th {
            background: #f7f9fc; color: #475467; font-size: 11px;
            text-transform: uppercase; letter-spacing: .35px;
        }
        .money { white-space: nowrap; font-weight: 700; }
        .muted { color: #667085; }
        .alert {
            background: #fff0ef; color: #a12622; padding: 13px 15px;
            border-radius: 8px; margin-bottom: 15px;
        }
        .empty { padding: 18px; text-align: center; color: #667085; }
        @media (max-width: 700px) {
            .page { padding: 14px; }
            .cards { grid-template-columns: 1fr; }
            h1 { font-size: 23px; }
            .panel { padding: 15px; }
            select { min-width: 0; width: 100%; }
        }
        @media print {
            .nav, .filter, .no-print { display: none !important; }
            body { background: #fff; }
            .page { max-width: none; padding: 0; }
            .panel, .card { box-shadow: none; break-inside: avoid; }
        }
    </style>
</head>
<body>
<main class="page">
    <header class="header">
        <div>
            <h1>Department Stock Report</h1>
            <p class="subtitle">Department-wise supply, remaining stock and supplier balances.</p>
        </div>
        <nav class="nav" aria-label="Department navigation">
            <a href="index.php">Departments</a>
            <a href="supply.php">Record Supply</a>
            <a href="receipts.php">Supply Receipts</a>
            <a href="payments.php">Payments</a>
        </nav>
    </header>

    <?php foreach ($errors as $error): ?>
        <div class="alert"><?= e($error) ?></div>
    <?php endforeach; ?>

    <section class="panel no-print">
        <form method="get" action="stock.php" class="filter">
            <div>
                <label for="department_id">Filter by Department</label>
                <select name="department_id" id="department_id">
                    <option value="">All departments</option>
                    <?php foreach ($departments ?? [] as $department): ?>
                        <option value="<?= (int) $department['id'] ?>"
                            <?= $departmentFilter === (int) $department['id'] ? 'selected' : '' ?>>
                            <?= e($department['department_code'] . ' - ' . $department['department_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit">Apply Filter</button>
            <button type="button" onclick="window.print()">Print Report</button>
        </form>
    </section>

    <section class="cards">
        <div class="card">
            <small>Posted Supply Value</small>
            <strong><?= e(departmentStockMoney($totalSupplyValue)) ?></strong>
        </div>
        <div class="card">
            <small>Outstanding Department Payments</small>
            <strong><?= e(departmentStockMoney($totalOutstanding)) ?></strong>
        </div>
        <div class="card">
            <small>Posted Supply Receipts</small>
            <strong><?= number_format($totalReceipts) ?></strong>
        </div>
    </section>

    <section class="panel">
        <h2>Department Summary</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Department Code</th>
                    <th>Department Name</th>
                    <th>Posted Receipts</th>
                    <th>Posted Supply Value</th>
                    <th>Ledger Outstanding</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($summaryRows)): ?>
                    <tr><td colspan="5" class="empty">No department records found.</td></tr>
                <?php else: ?>
                    <?php foreach ($summaryRows as $row): ?>
                        <?php $outstanding = (float) $row['ledger_due'] - (float) $row['ledger_paid']; ?>
                        <tr>
                            <td><?= e($row['department_code']) ?></td>
                            <td><?= e($row['department_name']) ?></td>
                            <td><?= number_format((int) $row['receipt_count']) ?></td>
                            <td class="money"><?= e(departmentStockMoney($row['supply_value'])) ?></td>
                            <td class="money"><?= e(departmentStockMoney($outstanding)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <h2>Current Stock by Department and Goods</h2>
        <p class="muted">Quantities and values below come from department-origin stock lots. They show stock remaining in those lots, not necessarily the supplier's full outstanding balance.</p>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Department</th>
                    <th>Goods Code</th>
                    <th>Goods Name</th>
                    <th>Unit</th>
                    <th>Received Quantity</th>
                    <th>Remaining Quantity</th>
                    <th>Remaining Stock Value</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($stockRows)): ?>
                    <tr><td colspan="7" class="empty">No posted department stock lots found.</td></tr>
                <?php else: ?>
                    <?php foreach ($stockRows as $stock): ?>
                        <tr>
                            <td>
                                <?= e($stock['department_code']) ?><br>
                                <span class="muted"><?= e($stock['department_name']) ?></span>
                            </td>
                            <td><?= e($stock['goods_code']) ?></td>
                            <td><?= e($stock['goods_name']) ?></td>
                            <td><?= e($stock['unit']) ?></td>
                            <td><?= e(number_format((float) $stock['quantity_received'], 3)) ?></td>
                            <td><?= e(number_format((float) $stock['quantity_remaining'], 3)) ?></td>
                            <td class="money"><?= e(departmentStockMoney($stock['remaining_stock_value'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <h2>Recent Posted Supply Receipts</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Receipt Number</th>
                    <th>Received Date</th>
                    <th>Department</th>
                    <th>Item Lines</th>
                    <th>Receipt Value</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($receipts)): ?>
                    <tr><td colspan="6" class="empty">No posted receipts found.</td></tr>
                <?php else: ?>
                    <?php foreach ($receipts as $receipt): ?>
                        <tr>
                            <td><?= e($receipt['receipt_number']) ?></td>
                            <td><?= e($receipt['received_at']) ?></td>
                            <td><?= e($receipt['department_code'] . ' - ' . $receipt['department_name']) ?></td>
                            <td><?= number_format((int) $receipt['item_count']) ?></td>
                            <td class="money"><?= e(departmentStockMoney($receipt['receipt_total'])) ?></td>
                            <td>
                                <a href="receipt-details.php?id=<?= (int) $receipt['receipt_id'] ?>">
                                    View Receipt
                                </a>
                            </td>
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
