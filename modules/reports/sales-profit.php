
<?php
require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_login();

$pageTitle = 'Sales & Profitability Report';

$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$supplierType = trim($_GET['supplier_type'] ?? '');

$errors = [];
$rows = [];

$summary = [
    'line_count' => 0,
    'quantity' => 0,
    'sales' => 0,
    'cost' => 0,
    'profit' => 0
];

function spMoney($amount)
{
    return '₹' . number_format((float) $amount, 2);
}

function spNumber($amount)
{
    return number_format((float) $amount, 3);
}

function spDateValid($date)
{
    if ($date === '') {
        return true;
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

if (!spDateValid($dateFrom)) {
    $errors[] = 'Please enter a valid start date.';
}

if (!spDateValid($dateTo)) {
    $errors[] = 'Please enter a valid end date.';
}

if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    $errors[] = 'The start date cannot be later than the end date.';
}

if (!in_array($supplierType, ['', 'farmer', 'department'], true)) {
    $errors[] = 'Please select a valid supplier type.';
}

try {
    if (!$errors) {
        $where = [
            "s.status = 'posted'",
            "sr.status = 'posted'"
        ];

        $params = [];

        if ($dateFrom !== '') {
            $where[] = 's.sold_at >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== '') {
            $where[] = 's.sold_at < :date_to';
            $params['date_to'] = date(
                'Y-m-d H:i:s',
                strtotime($dateTo . ' +1 day')
            );
        }

        if ($supplierType !== '') {
            $where[] = 'sr.supplier_type = :supplier_type';
            $params['supplier_type'] = $supplierType;
        }

        $whereSql = implode(' AND ', $where);

        $sql = "
            SELECT
                s.id AS sale_id,
                s.invoice_number,
                s.sold_at,
                s.customer_name,
                s.subtotal,
                s.discount_amount,
                s.total_amount,

                si.id AS sale_item_id,
                si.quantity,
                si.selling_rate,
                si.line_total,

                g.goods_code,
                g.goods_name,
                g.unit,

                sr.supplier_type,
                sr.farmer_id,
                sr.department_id,
                da.department_name,

                sri.unit_cost,

                CASE
                    WHEN COALESCE(s.subtotal, 0) > 0
                    THEN
                        si.line_total -
                        (
                            s.discount_amount *
                            si.line_total /
                            s.subtotal
                        )
                    ELSE si.line_total
                END AS net_line_sales,

                (si.quantity * sri.unit_cost) AS line_cost

            FROM sales s

            INNER JOIN sale_items si
                ON si.sale_id = s.id

            INNER JOIN stock_lots sl
                ON sl.id = si.stock_lot_id

            INNER JOIN supply_receipt_items sri
                ON sri.id = sl.receipt_item_id

            INNER JOIN supply_receipts sr
                ON sr.id = sri.receipt_id

            INNER JOIN goods g
                ON g.id = sri.goods_id

            LEFT JOIN department_accounts da
                ON da.id = sr.department_id

            WHERE $whereSql

            ORDER BY s.sold_at DESC, s.id DESC, si.id ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $netSales = (float) $row['net_line_sales'];
            $lineCost = (float) $row['line_cost'];

            $row['gross_profit'] = $netSales - $lineCost;

            $row['profit_margin'] = $netSales > 0
                ? ($row['gross_profit'] / $netSales) * 100
                : null;

            if ($row['supplier_type'] === 'department') {
                $row['supplier_name'] =
                    $row['department_name'] ?: (
                        'Department #' . $row['department_id']
                    );
            } else {
                $row['supplier_name'] =
                    'Farmer #' . $row['farmer_id'];
            }

            $summary['line_count']++;
            $summary['quantity'] += (float) $row['quantity'];
            $summary['sales'] += $netSales;
            $summary['cost'] += $lineCost;
            $summary['profit'] += $row['gross_profit'];
        }

        unset($row);
    }
} catch (Throwable $ex) {
    error_log('AgroProcure sales-profit report error: ' . $ex->getMessage());
    $errors[] = 'Unable to load the report. Please check the database and try again.';
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
            <p class="report-subtitle">
                Review posted sales, stock-lot purchase costs,
                supplier ownership and estimated gross profit.
            </p>
        </div>

        <a class="report-back"
           href="/agroprocure/public/dashboard.php">
            ← Dashboard
        </a>
    </header>

    <section class="report-notice">
        <strong>Calculation method:</strong>
        Net sales are calculated after allocating invoice discounts
        proportionally across the invoice items. Cost is based on the
        recorded purchase cost of each item's original stock lot.
        This report includes posted sales only.
    </section>

    <?php if ($errors): ?>
        <div class="report-error">
            <strong>Please check the following:</strong>
            <ul>
                <?php foreach ($errors as $message): ?>
                    <li><?= e($message) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section class="report-summary">
        <article class="summary-card">
            <span>Sale Lines</span>
            <strong><?= number_format($summary['line_count']) ?></strong>
        </article>

        <article class="summary-card">
            <span>Quantity Sold</span>
            <strong><?= spNumber($summary['quantity']) ?></strong>
        </article>

        <article class="summary-card">
            <span>Net Sales</span>
            <strong><?= spMoney($summary['sales']) ?></strong>
        </article>

        <article class="summary-card">
            <span>Stock Purchase Cost</span>
            <strong><?= spMoney($summary['cost']) ?></strong>
        </article>

        <article class="summary-card summary-highlight">
            <span>Estimated Gross Profit</span>
            <strong><?= spMoney($summary['profit']) ?></strong>
        </article>

        <article class="summary-card">
            <span>Gross Profit Margin</span>
            <strong>
                <?php
                echo $summary['sales'] > 0
                    ? number_format(
                        ($summary['profit'] / $summary['sales']) * 100,
                        2
                    ) . '%'
                    : '—';
                ?>
            </strong>
        </article>
    </section>

    <section class="report-panel">

        <form method="GET" class="report-filter">
            <div class="filter-field">
                <label for="date_from">From date</label>
                <input
                    type="date"
                    id="date_from"
                    name="date_from"
                    value="<?= e($dateFrom) ?>">
            </div>

            <div class="filter-field">
                <label for="date_to">To date</label>
                <input
                    type="date"
                    id="date_to"
                    name="date_to"
                    value="<?= e($dateTo) ?>">
            </div>

            <div class="filter-field">
                <label for="supplier_type">Supplier ownership</label>
                <select id="supplier_type" name="supplier_type">
                    <option value=""
                        <?= $supplierType === '' ? 'selected' : '' ?>>
                        All suppliers
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

            <button type="submit" class="report-button">
                Apply Filters
            </button>

            <a href="sales-profit.php" class="report-reset">
                Reset
            </a>
        </form>

        <div class="report-table-heading">
            <div>
                <h2>Sales and Profit Details</h2>
                <p>
                    <?= number_format(count($rows)) ?>
                    sale-item rows found.
                </p>
            </div>

            <button type="button"
                    class="report-button"
                    onclick="window.print()">
                Print Report
            </button>
        </div>

        <div class="report-table-wrap">
            <table class="report-table">
                <thead>
                <tr>
                    <th>Invoice</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Goods</th>
                    <th>Supplier</th>
                    <th>Quantity</th>
                    <th>Selling Rate</th>
                    <th>Net Sales</th>
                    <th>Purchase Cost</th>
                    <th>Gross Profit</th>
                    <th>Margin</th>
                </tr>
                </thead>

                <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="11" class="report-empty">
                            No posted sales found for the selected filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e($row['invoice_number']) ?></td>

                        <td>
                            <?= e(date(
                                'd M Y',
                                strtotime($row['sold_at'])
                            )) ?>
                        </td>

                        <td><?= e($row['customer_name']) ?></td>

                        <td>
                            <strong><?= e($row['goods_name']) ?></strong>
                            <br>
                            <small><?= e($row['goods_code']) ?></small>
                        </td>

                        <td>
                            <?= e($row['supplier_name']) ?>
                            <br>
                            <small>
                                <?= $row['supplier_type'] === 'farmer'
                                    ? 'Farmer'
                                    : 'Department' ?>
                            </small>
                        </td>

                        <td>
                            <?= spNumber($row['quantity']) ?>
                            <?= e($row['unit']) ?>
                        </td>

                        <td><?= spMoney($row['selling_rate']) ?></td>

                        <td><?= spMoney($row['net_line_sales']) ?></td>

                        <td><?= spMoney($row['line_cost']) ?></td>

                        <td style="font-weight:700">
                            <?= spMoney($row['gross_profit']) ?>
                        </td>

                        <td>
                            <?= $row['profit_margin'] !== null
                                ? number_format(
                                    $row['profit_margin'],
                                    2
                                ) . '%'
                                : '—' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>

                <?php if ($rows): ?>
                <tfoot>
                <tr>
                    <th colspan="5">TOTAL</th>
                    <th><?= spNumber($summary['quantity']) ?></th>
                    <th>—</th>
                    <th><?= spMoney($summary['sales']) ?></th>
                    <th><?= spMoney($summary['cost']) ?></th>
                    <th><?= spMoney($summary['profit']) ?></th>
                    <th>
                        <?= $summary['sales'] > 0
                            ? number_format(
                                $summary['profit'] /
                                $summary['sales'] * 100,
                                2
                            ) . '%'
                            : '—' ?>
                    </th>
                </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </section>

    <footer class="report-footer">
        AgroProcure · Agricultural Procurement &amp; Payment Management System
    </footer>

</div>
</body>
</html>
