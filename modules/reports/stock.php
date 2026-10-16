
<?php
require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_login();

$pageTitle = 'Goods-Wise Stock Report';
$error = '';
$search = trim($_GET['search'] ?? '');
$goodsRows = [];
$summary = [
    'goods_count' => 0,
    'received' => 0,
    'farmer_received' => 0,
    'department_received' => 0,
    'sold' => 0,
    'remaining' => 0,
    'stock_value' => 0
];

try {
    $sql = "
        SELECT
            g.id,
            g.goods_code,
            g.goods_name,
            g.unit,
            COALESCE(r.total_received, 0) AS total_received,
            COALESCE(r.farmer_received, 0) AS farmer_received,
            COALESCE(r.department_received, 0) AS department_received,
            COALESCE(s.net_sold, 0) AS net_sold,
            COALESCE(r.total_remaining, 0) AS total_remaining,
            COALESCE(r.remaining_value, 0) AS remaining_value
        FROM goods g

        LEFT JOIN (
            SELECT
                sri.goods_id,
                SUM(sl.original_quantity) AS total_received,
                SUM(
                    CASE
                        WHEN sr.supplier_type = 'farmer'
                        THEN sl.original_quantity ELSE 0
                    END
                ) AS farmer_received,
                SUM(
                    CASE
                        WHEN sr.supplier_type = 'department'
                        THEN sl.original_quantity ELSE 0
                    END
                ) AS department_received,
                SUM(sl.remaining_quantity) AS total_remaining,
                SUM(
                    sl.remaining_quantity * sri.unit_cost
                ) AS remaining_value
            FROM stock_lots sl
            INNER JOIN supply_receipt_items sri
                ON sri.id = sl.receipt_item_id
            INNER JOIN supply_receipts sr
                ON sr.id = sri.receipt_id
            WHERE sr.status = 'posted'
            GROUP BY sri.goods_id
        ) r ON r.goods_id = g.id

        LEFT JOIN (
            SELECT
                sri.goods_id,
                SUM(
                    CASE
                        WHEN sm.movement_type = 'sale'
                        THEN sm.quantity_out
                        WHEN sm.movement_type = 'return'
                        THEN -sm.quantity_in
                        ELSE 0
                    END
                ) AS net_sold
            FROM stock_movements sm
            INNER JOIN stock_lots sl
                ON sl.id = sm.stock_lot_id
            INNER JOIN supply_receipt_items sri
                ON sri.id = sl.receipt_item_id
            INNER JOIN supply_receipts sr
                ON sr.id = sri.receipt_id
            WHERE sr.status = 'posted'
              AND sm.movement_type IN ('sale', 'return')
            GROUP BY sri.goods_id
        ) s ON s.goods_id = g.id

        WHERE (
            :search_name = ''
            OR g.goods_name LIKE :name_pattern
            OR g.goods_code LIKE :code_pattern
        )
        ORDER BY g.goods_name ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'search_name' => $search,
        'name_pattern' => '%' . $search . '%',
        'code_pattern' => '%' . $search . '%'
    ]);

    $goodsRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($goodsRows as $row) {
        $summary['goods_count']++;
        $summary['received'] += (float) $row['total_received'];
        $summary['farmer_received'] += (float) $row['farmer_received'];
        $summary['department_received'] += (float) $row['department_received'];
        $summary['sold'] += (float) $row['net_sold'];
        $summary['remaining'] += (float) $row['total_remaining'];
        $summary['stock_value'] += (float) $row['remaining_value'];
    }
} catch (PDOException $ex) {
    error_log('AgroProcure stock report error: ' . $ex->getMessage());
    $error = 'Unable to load the stock report. Please check the database configuration and table structure.';
}

function reportNumber($value, $decimals = 3)
{
    return number_format((float) $value, $decimals);
}

function reportMoney($value)
{
    return '₹' . number_format((float) $value, 2);
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
                Monitor received goods, sales, available inventory,
                and estimated inventory cost.
            </p>
        </div>

        <a class="report-back"
           href="/agroprocure/public/dashboard.php">
            ← Dashboard
        </a>
    </header>

    <section class="report-notice">
        <strong>Inventory valuation:</strong>
        Remaining stock is valued at its recorded purchase cost.
        This is an inventory estimate, not profit or cash balance.
    </section>

    <?php if ($error !== ''): ?>
        <div class="report-error"><?= e($error) ?></div>
    <?php else: ?>

    <section class="report-summary">
        <article class="summary-card">
            <span>Goods Listed</span>
            <strong><?= number_format($summary['goods_count']) ?></strong>
        </article>

        <article class="summary-card">
            <span>Total Received</span>
            <strong><?= reportNumber($summary['received']) ?></strong>
        </article>

        <article class="summary-card">
            <span>Received from Farmers</span>
            <strong><?= reportNumber($summary['farmer_received']) ?></strong>
        </article>

        <article class="summary-card">
            <span>Received from Department</span>
            <strong><?= reportNumber($summary['department_received']) ?></strong>
        </article>

        <article class="summary-card">
            <span>Net Quantity Sold</span>
            <strong><?= reportNumber($summary['sold']) ?></strong>
        </article>

        <article class="summary-card summary-highlight">
            <span>Estimated Stock Value</span>
            <strong><?= reportMoney($summary['stock_value']) ?></strong>
        </article>
    </section>

    <section class="report-panel">
        <form method="GET" class="report-filter">
            <div class="filter-field">
                <label for="search">Search goods</label>
                <input
                    type="search"
                    id="search"
                    name="search"
                    value="<?= e($search) ?>"
                    placeholder="Enter goods name or code">
            </div>

            <button type="submit" class="report-button">
                Search
            </button>

            <a href="stock.php" class="report-reset">Reset</a>
        </form>

        <div class="report-table-heading">
            <div>
                <h2>Stock Details</h2>
                <p>Only posted supply receipts are included.</p>
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
                    <th>Goods Code</th>
                    <th>Goods Name</th>
                    <th>Unit</th>
                    <th>Received</th>
                    <th>From Farmers</th>
                    <th>From Department</th>
                    <th>Net Sold</th>
                    <th>Remaining Stock</th>
                    <th>Remaining Stock Value</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$goodsRows): ?>
                    <tr>
                        <td colspan="9" class="report-empty">
                            No goods found for this search.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($goodsRows as $row): ?>
                    <tr>
                        <td><?= e($row['goods_code']) ?></td>
                        <td class="goods-name">
                            <?= e($row['goods_name']) ?>
                        </td>
                        <td><?= e($row['unit']) ?></td>
                        <td><?= reportNumber($row['total_received']) ?></td>
                        <td><?= reportNumber($row['farmer_received']) ?></td>
                        <td><?= reportNumber($row['department_received']) ?></td>
                        <td><?= reportNumber($row['net_sold']) ?></td>
                        <td class="stock-quantity">
                            <?= reportNumber($row['total_remaining']) ?>
                        </td>
                        <td class="stock-value">
                            <?= reportMoney($row['remaining_value']) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>

                <?php if ($goodsRows): ?>
                <tfoot>
                <tr>
                    <th colspan="3">TOTAL</th>
                    <th><?= reportNumber($summary['received']) ?></th>
                    <th><?= reportNumber($summary['farmer_received']) ?></th>
                    <th><?= reportNumber($summary['department_received']) ?></th>
                    <th><?= reportNumber($summary['sold']) ?></th>
                    <th><?= reportNumber($summary['remaining']) ?></th>
                    <th><?= reportMoney($summary['stock_value']) ?></th>
                </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <footer class="report-footer">
        AgroProcure · Agricultural Procurement &amp; Payment Management System
    </footer>
</div>
</body>
</html>
