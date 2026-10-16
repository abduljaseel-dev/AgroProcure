
<?php
require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_role(['admin', 'vendor', 'staff']);

// Stock is shown by receipt lot so each lot retains its supplier and purchase rate.
$stockSql = "
    SELECT
        f.id AS farmer_id,
        f.farmer_code,
        f.full_name,
        sr.id AS receipt_id,
        sr.received_at,
        g.goods_code,
        g.goods_name,
        g.unit,
        sri.quantity AS received_quantity,
        sri.unit_cost,
        sl.remaining_quantity,
        ROUND(sl.remaining_quantity * sri.unit_cost, 2) AS remaining_value
    FROM stock_lots sl
    INNER JOIN supply_receipt_items sri
        ON sri.id = sl.receipt_item_id
    INNER JOIN supply_receipts sr
        ON sr.id = sri.receipt_id
    INNER JOIN farmers f
        ON f.id = sr.farmer_id
    INNER JOIN goods g
        ON g.id = sri.goods_id
    WHERE sr.supplier_type = 'farmer'
      AND sr.status = 'posted'
    ORDER BY f.full_name, g.goods_name, sr.id DESC
";

$stockRows = $pdo->query($stockSql)->fetchAll();

// Calculate outstanding supplier balances independently to avoid duplicate totals.
$balanceSql = "
    SELECT
        f.id AS farmer_id,
        f.farmer_code,
        f.full_name,
        COALESCE(SUM(sl.amount_due), 0) AS total_due,
        COALESCE(SUM(sl.amount_paid), 0) AS total_ledger_paid,
        COALESCE(SUM(sl.amount_due - sl.amount_paid), 0) AS outstanding
    FROM farmers f
    LEFT JOIN supplier_ledger sl
        ON sl.farmer_id = f.id
       AND sl.supplier_type = 'farmer'
    GROUP BY f.id, f.farmer_code, f.full_name
    ORDER BY f.full_name
";

$balances = $pdo->query($balanceSql)->fetchAll();

$totalRemainingValue = 0.0;
foreach ($stockRows as $row) {
    $totalRemainingValue += (float)$row['remaining_value'];
}

$totalOutstanding = 0.0;
foreach ($balances as $balance) {
    $totalOutstanding += (float)$balance['outstanding'];
}

$formatMoney = static function ($amount): string {
    return '₹' . number_format((float)$amount, 2, '.', ',');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Farmer Stock & Balances | AgroProcure</title>
    <link rel="stylesheet" href="../../public/assets/css/supply.css">
</head>
<body>
<div class="supply-page">
    <header class="supply-header">
        <div>
            <p class="eyebrow">AGROPROCURE · ACCOUNTING REPORTS</p>
            <h1>Farmer Stock & Balances</h1>
            <p>Review remaining stock and outstanding supplier balances.</p>
        </div>
        <a class="back-link" href="index.php">Back to Farmers</a>
    </header>

    <div class="form-card">
        <h2>Current Summary</h2>
        <div class="summary-grid">
            <div class="summary-item">
                <span>Remaining Farmer Stock Value</span>
                <strong><?= e($formatMoney($totalRemainingValue)) ?></strong>
            </div>
            <div class="summary-item">
                <span>Outstanding Farmer Balances</span>
                <strong><?= e($formatMoney($totalOutstanding)) ?></strong>
            </div>
        </div>
        <p class="help">
            Stock value is based on the original purchase rate of each lot.
            Outstanding balances come from posted supplier-ledger entries.
        </p>
    </div>

    <section class="form-card">
        <h2>Remaining Stock by Farmer and Receipt</h2>
        <p class="help">
            Each row represents a stock lot, preserving the original supplier
            and purchase rate. Zero-quantity lots remain visible for traceability.
        </p>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Farmer ID</th>
                    <th>Farmer</th>
                    <th>Receipt</th>
                    <th>Goods</th>
                    <th>Received</th>
                    <th>Remaining</th>
                    <th>Purchase Rate</th>
                    <th>Remaining Value</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$stockRows): ?>
                    <tr>
                        <td colspan="8">
                            No posted farmer stock receipts found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($stockRows as $row): ?>
                        <tr>
                            <td><?= e($row['farmer_code']) ?></td>
                            <td><?= e($row['full_name']) ?></td>
                            <td>
                                <a href="receipt-details.php?id=<?= (int)$row['receipt_id'] ?>">
                                    FR-<?= (int)$row['receipt_id'] ?>
                                </a>
                            </td>
                            <td>
                                <?= e($row['goods_name']) ?>
                                (<?= e($row['unit']) ?>)
                            </td>
                            <td><?= e((string)$row['received_quantity']) ?></td>
                            <td><?= e((string)$row['remaining_quantity']) ?></td>
                            <td><?= e($formatMoney($row['unit_cost'])) ?></td>
                            <td><?= e($formatMoney($row['remaining_value'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="form-card">
        <h2>Outstanding Balance by Farmer</h2>
        <p class="help">
            This is the net balance recorded in the supplier ledger.
            It is not the value of unsold goods.
        </p>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Farmer ID</th>
                    <th>Farmer</th>
                    <th>Recorded Amount Due</th>
                    <th>Recorded Amount Paid</th>
                    <th>Outstanding</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$balances): ?>
                    <tr>
                        <td colspan="5">No farmer records found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($balances as $balance): ?>
                        <tr>
                            <td><?= e($balance['farmer_code']) ?></td>
                            <td><?= e($balance['full_name']) ?></td>
                            <td><?= e($formatMoney($balance['total_due'])) ?></td>
                            <td><?= e($formatMoney($balance['total_ledger_paid'])) ?></td>
                            <td><strong><?= e($formatMoney($balance['outstanding'])) ?></strong></td>
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
