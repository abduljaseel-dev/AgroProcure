
<?php
require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_login();

$pageTitle = 'Low-Stock & Reorder Report';

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? 'all';

$allowedStatuses = ['all', 'low', 'out', 'available'];

if (!in_array($status, $allowedStatuses, true)) {
    $status = 'all';
}

$goods = [];
$summary = [
    'total' => 0,
    'low' => 0,
    'out' => 0,
    'available' => 0,
    'stock_value' => 0.0,
];
$error = '';

function lsEscape($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function lsMoney($value): string
{
    return number_format((float)$value, 2);
}

try {
    /*
     * Calculate available stock from stock lots linked to posted
     * supply receipts. Do not modify inventory records.
     */
    $sql = "
        SELECT
            g.id,
            g.goods_code,
            g.goods_name,
            g.unit,
            g.reorder_level,

            COALESCE(SUM(sl.remaining_quantity), 0) AS available_qty,

            COALESCE(
                SUM(sl.remaining_quantity * sri.unit_cost),
                0
            ) AS stock_value

        FROM goods g

        LEFT JOIN supply_receipt_items sri
            ON sri.goods_id = g.id

        LEFT JOIN supply_receipts sr
            ON sr.id = sri.receipt_id
           AND sr.status = 'posted'

        LEFT JOIN stock_lots sl
            ON sl.receipt_item_id = sri.id
           AND sr.id IS NOT NULL

        WHERE g.is_active = 1
    ";

    $params = [];

    if ($search !== '') {
        $sql .= "
            AND (
                g.goods_name LIKE :search_name
                OR g.goods_code LIKE :search_code
            )
        ";

        $params['search_name'] = '%' . $search . '%';
        $params['search_code'] = '%' . $search . '%';
    }

    $sql .= "
        GROUP BY
            g.id,
            g.goods_code,
            g.goods_name,
            g.unit,
            g.reorder_level
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $allGoods = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
     * Determine each item's status in PHP.
     * Zero stock is out of stock.
     * Positive stock at or below the reorder level is low stock.
     */
    foreach ($allGoods as $item) {
        $quantity = (float)$item['available_qty'];
        $reorder = (float)$item['reorder_level'];

        if ($quantity <= 0) {
            $item['stock_status'] = 'out';
            $summary['out']++;
        } elseif ($reorder > 0 && $quantity <= $reorder) {
            $item['stock_status'] = 'low';
            $summary['low']++;
        } else {
            $item['stock_status'] = 'available';
            $summary['available']++;
        }

        $summary['total']++;
        $summary['stock_value'] += (float)$item['stock_value'];

        if ($status === 'all' || $status === $item['stock_status']) {
            $goods[] = $item;
        }
    }

    usort($goods, function ($a, $b) {
        $priority = ['out' => 0, 'low' => 1, 'available' => 2];

        $statusComparison =
            $priority[$a['stock_status']] <=> $priority[$b['stock_status']];

        if ($statusComparison !== 0) {
            return $statusComparison;
        }

        return strcasecmp($a['goods_name'], $b['goods_name']);
    });

} catch (Throwable $e) {
    error_log('AgroProcure low-stock report error: ' . $e->getMessage());
    $error = 'Unable to load the stock report. Please check the database and try again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= lsEscape($pageTitle) ?> | AgroProcure</title>

    <style>
        :root {
            --navy: #173653;
            --navy-dark: #10283f;
            --teal: #087e80;
            --bg: #f3f6fa;
            --white: #fff;
            --text: #243447;
            --muted: #718096;
            --border: #e2e8f0;
            --red: #b42318;
            --amber: #92400e;
            --green: #166534;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        .ls-page {
            max-width: 1450px;
            margin: auto;
            padding: 28px;
        }

        .ls-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }

        .ls-header h1 {
            margin: 0;
            color: var(--navy);
            font-size: clamp(24px, 3vw, 32px);
        }

        .ls-header p {
            margin: 7px 0 0;
            color: var(--muted);
            font-size: 14px;
        }

        .ls-eyebrow {
            margin-bottom: 8px !important;
            color: var(--teal) !important;
            font-size: 11px !important;
            font-weight: 800;
            letter-spacing: 1.5px;
        }

        .ls-button {
            display: inline-block;
            border: 0;
            border-radius: 7px;
            background: var(--navy);
            color: white;
            padding: 11px 16px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }

        .ls-button:hover {
            background: var(--teal);
        }

        .ls-panel {
            background: white;
            border: 1px solid var(--border);
            border-radius: 12px;
            margin-bottom: 22px;
            overflow: hidden;
        }

        .ls-filter {
            display: grid;
            grid-template-columns: minmax(180px, 2fr) minmax(150px, 1fr) auto;
            align-items: end;
            gap: 15px;
            padding: 20px;
        }

        .ls-field label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 7px;
            color: var(--navy);
        }

        .ls-field input,
        .ls-field select {
            width: 100%;
            min-height: 42px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            background: white;
            padding: 9px 11px;
            font-size: 14px;
        }

        .ls-summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 22px;
        }

        .ls-metric {
            background: white;
            padding: 19px;
            border: 1px solid var(--border);
            border-top: 4px solid var(--teal);
            border-radius: 10px;
        }

        .ls-metric span {
            display: block;
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 9px;
        }

        .ls-metric strong {
            display: block;
            color: var(--navy);
            font-size: clamp(20px, 2vw, 27px);
            overflow-wrap: anywhere;
        }

        .ls-metric small {
            display: block;
            margin-top: 7px;
            color: var(--muted);
        }

        .ls-metric.red {
            border-top-color: var(--red);
        }

        .ls-metric.amber {
            border-top-color: #d97706;
        }

        .ls-metric.green {
            border-top-color: var(--green);
        }

        .ls-panel-title {
            padding: 19px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .ls-panel-title h2 {
            margin: 0;
            color: var(--navy);
            font-size: 17px;
        }

        .ls-table-wrap {
            overflow-x: auto;
        }

        .ls-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .ls-table th,
        .ls-table td {
            padding: 14px 17px;
            text-align: left;
            border-bottom: 1px solid #edf1f5;
            white-space: nowrap;
        }

        .ls-table th {
            background: #f8fafc;
            color: #64748b;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        .ls-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .ls-table td:first-child {
            color: var(--navy);
            font-weight: 700;
        }

        .ls-status {
            display: inline-block;
            border-radius: 20px;
            padding: 5px 9px;
            font-size: 11px;
            font-weight: 700;
        }

        .ls-status.out {
            color: var(--red);
            background: #fee2e2;
        }

        .ls-status.low {
            color: var(--amber);
            background: #fef3c7;
        }

        .ls-status.available {
            color: var(--green);
            background: #dcfce7;
        }

        .ls-empty {
            text-align: center !important;
            padding: 30px !important;
            color: var(--muted) !important;
            font-weight: 400 !important;
        }

        .ls-note {
            padding: 15px 17px;
            border: 1px solid #bfdbfe;
            border-radius: 9px;
            background: #eff6ff;
            color: #1e3a5f;
            font-size: 13px;
            line-height: 1.6;
            margin-bottom: 22px;
        }

        .ls-error {
            padding: 14px;
            margin-bottom: 20px;
            background: #fff1f2;
            border: 1px solid #fecdd3;
            color: #9f1239;
            border-radius: 8px;
        }

        .ls-footer {
            padding: 20px 0;
            text-align: center;
            color: var(--muted);
            font-size: 12px;
        }

        @media (max-width: 800px) {
            .ls-page {
                padding: 16px;
            }

            .ls-summary {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .ls-filter {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 450px) {
            .ls-summary {
                grid-template-columns: 1fr 1fr;
                gap: 9px;
            }

            .ls-metric {
                padding: 13px;
            }
        }

        @media print {
            body {
                background: white;
            }

            .ls-page {
                max-width: none;
                padding: 0;
            }

            .ls-filter,
            .ls-actions {
                display: none !important;
            }

            .ls-panel,
            .ls-metric {
                box-shadow: none;
                break-inside: avoid;
            }

            .ls-table th,
            .ls-table td {
                padding: 8px;
                font-size: 10px;
            }
        }
    </style>
</head>
<body>

<main class="ls-page">

    <header class="ls-header">
        <div>
            <p class="ls-eyebrow">AGROPROCURE / INVENTORY CONTROL</p>
            <h1>Low-Stock &amp; Reorder Report</h1>
            <p>Monitor inventory levels and identify goods requiring procurement.</p>
        </div>

        <div class="ls-actions">
            <button type="button" class="ls-button" id="printReport">
                Print Report
            </button>
        </div>
    </header>

    <?php if ($error !== ''): ?>
        <div class="ls-error" role="alert">
            <?= lsEscape($error) ?>
        </div>
    <?php else: ?>

        <div class="ls-note">
            <strong>How this report works:</strong>
            A good is marked low-stock when its available quantity is greater
            than zero and at or below its configured reorder level.
            A good with zero or negative available stock is marked out of stock.
            A reorder level of zero disables the low-stock threshold.
        </div>

        <section class="ls-summary">
            <article class="ls-metric">
                <span>Active Goods</span>
                <strong><?= number_format($summary['total']) ?></strong>
                <small>Matching goods in the search</small>
            </article>

            <article class="ls-metric red">
                <span>Out of Stock</span>
                <strong><?= number_format($summary['out']) ?></strong>
                <small>No available quantity</small>
            </article>

            <article class="ls-metric amber">
                <span>Low Stock</span>
                <strong><?= number_format($summary['low']) ?></strong>
                <small>At or below reorder level</small>
            </article>

            <article class="ls-metric green">
                <span>Available Stock Value</span>
                <strong>₹<?= lsMoney($summary['stock_value']) ?></strong>
                <small>Estimated purchase-cost value</small>
            </article>
        </section>

        <section class="ls-panel">
            <form method="GET"
                  action="/agroprocure/modules/reports/low-stock.php"
                  class="ls-filter">

                <div class="ls-field">
                    <label for="search">Search Goods</label>
                    <input type="search"
                           id="search"
                           name="search"
                           maxlength="120"
                           value="<?= lsEscape($search) ?>"
                           placeholder="Enter goods name or code">
                </div>

                <div class="ls-field">
                    <label for="status">Stock Status</label>
                    <select id="status" name="status">
                        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>
                            All Goods
                        </option>
                        <option value="out" <?= $status === 'out' ? 'selected' : '' ?>>
                            Out of Stock
                        </option>
                        <option value="low" <?= $status === 'low' ? 'selected' : '' ?>>
                            Low Stock
                        </option>
                        <option value="available" <?= $status === 'available' ? 'selected' : '' ?>>
                            Available
                        </option>
                    </select>
                </div>

                <div class="ls-field">
                    <button type="submit" class="ls-button">
                        Apply Filters
                    </button>
                </div>
            </form>
        </section>

        <section class="ls-panel">
            <div class="ls-panel-title">
                <h2>Inventory Reorder List</h2>
                <span><?= number_format(count($goods)) ?> result(s)</span>
            </div>

            <div class="ls-table-wrap">
                <table class="ls-table">
                    <thead>
                        <tr>
                            <th>Goods Code</th>
                            <th>Goods Name</th>
                            <th>Available Quantity</th>
                            <th>Reorder Level</th>
                            <th>Suggested Reorder Qty.</th>
                            <th>Stock Value (₹)</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (!$goods): ?>
                            <tr>
                                <td colspan="7" class="ls-empty">
                                    No goods match the selected filters.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($goods as $item): ?>
                                <?php
                                $quantity = (float)$item['available_qty'];
                                $reorder = (float)$item['reorder_level'];

                                $suggested = max(0, $reorder - $quantity);

                                $statusLabel = [
                                    'out' => 'Out of Stock',
                                    'low' => 'Low Stock',
                                    'available' => 'Available',
                                ][$item['stock_status']];
                                ?>
                                <tr>
                                    <td><?= lsEscape($item['goods_code']) ?></td>

                                    <td><?= lsEscape($item['goods_name']) ?></td>

                                    <td>
                                        <?= number_format($quantity, 3) ?>
                                        <?= lsEscape($item['unit']) ?>
                                    </td>

                                    <td>
                                        <?= number_format($reorder, 3) ?>
                                        <?= lsEscape($item['unit']) ?>
                                    </td>

                                    <td>
                                        <?= number_format($suggested, 3) ?>
                                        <?= lsEscape($item['unit']) ?>
                                    </td>

                                    <td><?= lsMoney($item['stock_value']) ?></td>

                                    <td>
                                        <span class="ls-status <?= lsEscape($item['stock_status']) ?>">
                                            <?= lsEscape($statusLabel) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

    <?php endif; ?>

    <footer class="ls-footer">
        AgroProcure · Inventory Control · Generated
        <?= lsEscape(date('d M Y, h:i A')) ?>
    </footer>

</main>

<script src="/agroprocure/public/assets/js/low-stock.js" defer></script>
</body>
</html>
