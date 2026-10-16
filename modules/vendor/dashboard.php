
<?php
require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_login();

$pageTitle = 'Vendor Dashboard';
$today = date('Y-m-d');

$metrics = [
    'sales' => 0,
    'invoices' => 0,
    'cogs' => 0,
    'profit' => 0,
    'collections' => 0,
    'expenses' => 0,
    'farmer_due' => 0,
    'department_due' => 0,
    'customer_due' => 0,
];

$stockItems = [];
$recentSales = [];
$error = '';

function vdEscape($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function vdMoney($value): string
{
    return number_format((float)$value, 2);
}

try {
    $start = $today . ' 00:00:00';
    $end = date('Y-m-d H:i:s', strtotime($today . ' +1 day'));

    /* Today's posted sales */
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS invoice_count,
            COALESCE(SUM(total_amount), 0) AS sales_total
        FROM sales
        WHERE status = 'posted'
          AND sold_at >= ?
          AND sold_at < ?
    ");
    $stmt->execute([$start, $end]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $metrics['sales'] = (float)$row['sales_total'];
    $metrics['invoices'] = (int)$row['invoice_count'];

    /* Today's cost of goods sold */
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(si.quantity * sri.unit_cost), 0)
        FROM sales s
        INNER JOIN sale_items si ON si.sale_id = s.id
        INNER JOIN stock_lots sl ON sl.id = si.stock_lot_id
        INNER JOIN supply_receipt_items sri
            ON sri.id = sl.receipt_item_id
        WHERE s.status = 'posted'
          AND s.sold_at >= ?
          AND s.sold_at < ?
    ");
    $stmt->execute([$start, $end]);
    $metrics['cogs'] = (float)$stmt->fetchColumn();

    $metrics['profit'] = $metrics['sales'] - $metrics['cogs'];

    /* Customer collections received today */
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM customer_payments
        WHERE paid_at >= ?
          AND paid_at < ?
    ");
    $stmt->execute([$start, $end]);
    $metrics['collections'] = (float)$stmt->fetchColumn();

    /* Posted business expenses today */
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM business_expenses
        WHERE status = 'posted'
          AND expense_date >= ?
          AND expense_date < ?
    ");
    $stmt->execute([$start, $end]);
    $metrics['expenses'] = (float)$stmt->fetchColumn();

    /* Current outstanding supplier balances */
    $stmt = $pdo->query("
        SELECT
            supplier_type,
            COALESCE(SUM(amount_due - amount_paid), 0) AS balance
        FROM supplier_ledger
        GROUP BY supplier_type
    ");

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['supplier_type'] === 'farmer') {
            $metrics['farmer_due'] = (float)$row['balance'];
        } elseif ($row['supplier_type'] === 'department') {
            $metrics['department_due'] = (float)$row['balance'];
        }
    }

    /* Current customer outstanding balances */
    $stmt = $pdo->query("
        SELECT COALESCE(
            SUM(s.total_amount - COALESCE(p.total_paid, 0)),
            0
        )
        FROM sales s
        LEFT JOIN (
            SELECT sale_id, SUM(amount) AS total_paid
            FROM customer_payments
            GROUP BY sale_id
        ) p ON p.sale_id = s.id
        WHERE s.status = 'posted'
    ");
    $metrics['customer_due'] = (float)$stmt->fetchColumn();

    /* Stock overview: only goods from posted receipts */
    $stmt = $pdo->query("
        SELECT
            g.id,
            g.goods_code,
            g.goods_name,
            g.unit,
            COALESCE(SUM(sl.remaining_quantity), 0) AS available_quantity
        FROM goods g
        INNER JOIN supply_receipt_items sri
            ON sri.goods_id = g.id
        INNER JOIN supply_receipts sr
            ON sr.id = sri.receipt_id
           AND sr.status = 'posted'
        INNER JOIN stock_lots sl
            ON sl.receipt_item_id = sri.id
        WHERE g.is_active = 1
        GROUP BY
            g.id,
            g.goods_code,
            g.goods_name,
            g.unit
        ORDER BY available_quantity ASC, g.goods_name ASC
        LIMIT 8
    ");
    $stockItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Recent posted sales */
    $stmt = $pdo->query("
        SELECT
            invoice_number,
            customer_name,
            sold_at,
            total_amount,
            payment_status
        FROM sales
        WHERE status = 'posted'
        ORDER BY sold_at DESC, id DESC
        LIMIT 8
    ");
    $recentSales = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log('AgroProcure vendor dashboard error: ' . $e->getMessage());
    $error = 'Dashboard data could not be loaded. Please check the database and try again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendor Dashboard | AgroProcure</title>

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
            --green: #167344;
            --red: #b42318;
            --shadow: 0 4px 18px rgba(22, 44, 68, .06);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
            line-height: 1.5;
        }

        .vd-layout {
            min-height: 100vh;
        }

        .vd-topbar {
            background: var(--navy-dark);
            color: white;
            padding: 19px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .vd-brand {
            font-size: 20px;
            font-weight: 800;
            letter-spacing: .3px;
        }

        .vd-brand small {
            display: block;
            color: #b9d0df;
            font-size: 11px;
            font-weight: 500;
            letter-spacing: 1.4px;
            margin-top: 3px;
        }

        .vd-top-date {
            font-size: 13px;
            color: #d8e5ee;
        }

        .vd-main {
            width: min(1500px, 100%);
            margin: 0 auto;
            padding: 28px;
        }

        .vd-heading {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 23px;
        }

        .vd-heading h1 {
            margin: 0;
            color: var(--navy);
            font-size: clamp(24px, 3vw, 31px);
        }

        .vd-heading p {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 14px;
        }

        .vd-section-title {
            font-size: 17px;
            margin: 0 0 14px;
            color: var(--navy);
        }

        .vd-metrics {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 27px;
        }

        .vd-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--shadow);
            min-width: 0;
        }

        .vd-metric-label {
            display: block;
            color: var(--muted);
            font-size: 13px;
            margin-bottom: 11px;
        }

        .vd-metric-value {
            font-size: clamp(19px, 2vw, 26px);
            font-weight: 800;
            color: var(--navy);
            overflow-wrap: anywhere;
        }

        .vd-metric-note {
            display: block;
            color: var(--muted);
            font-size: 12px;
            margin-top: 9px;
        }

        .vd-accent {
            border-top: 4px solid var(--teal);
        }

        .vd-green { color: var(--green); }
        .vd-red { color: var(--red); }

        .vd-shortcuts {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 29px;
        }

        .vd-shortcut {
            display: block;
            background: white;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 15px 17px;
            text-decoration: none;
            color: var(--navy);
            font-weight: 700;
            box-shadow: var(--shadow);
            transition: border-color .15s, transform .15s;
        }

        .vd-shortcut:hover {
            border-color: var(--teal);
            transform: translateY(-2px);
        }

        .vd-shortcut small {
            display: block;
            margin-top: 5px;
            color: var(--muted);
            font-weight: 400;
        }

        .vd-panels {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 20px;
        }

        .vd-panel {
            min-width: 0;
            background: white;
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 20px;
        }

        .vd-panel-header {
            padding: 19px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .vd-panel-header h2 {
            font-size: 16px;
            color: var(--navy);
            margin: 0;
        }

        .vd-panel-header a {
            color: var(--teal);
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
        }

        .vd-table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        .vd-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .vd-table th,
        .vd-table td {
            text-align: left;
            padding: 13px 17px;
            border-bottom: 1px solid #edf1f5;
            white-space: nowrap;
        }

        .vd-table th {
            background: #f8fafc;
            color: #64748b;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        .vd-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .vd-table td:first-child {
            color: var(--navy);
            font-weight: 600;
        }

        .vd-empty {
            padding: 24px;
            text-align: center;
            color: var(--muted);
        }

        .vd-status {
            display: inline-block;
            border-radius: 20px;
            padding: 4px 8px;
            background: #edf2f7;
            font-size: 11px;
            text-transform: capitalize;
        }

        .vd-note {
            padding: 14px 16px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 9px;
            color: #1e3a5f;
            font-size: 13px;
            margin-bottom: 20px;
        }

        .vd-error {
            padding: 14px 16px;
            background: #fff1f2;
            border: 1px solid #fecdd3;
            color: #9f1239;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .vd-footer {
            text-align: center;
            color: var(--muted);
            font-size: 12px;
            padding: 24px 0 8px;
        }

        @media (max-width: 1000px) {
            .vd-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .vd-shortcuts { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .vd-panels { grid-template-columns: 1fr; }
        }

        @media (max-width: 560px) {
            .vd-topbar { padding: 16px; }
            .vd-main { padding: 16px; }
            .vd-metrics { gap: 10px; }
            .vd-card { padding: 15px; }
            .vd-shortcuts { grid-template-columns: 1fr 1fr; gap: 9px; }
            .vd-shortcut { padding: 12px; font-size: 13px; }
            .vd-metric-value { font-size: 18px; }
        }
    </style>
</head>

<body>
<div class="vd-layout">

    <header class="vd-topbar">
        <div class="vd-brand">
            AGROPROCURE
            <small>AGRICULTURAL PROCUREMENT &amp; PAYMENT MANAGEMENT</small>
        </div>
        <div class="vd-top-date">
            <?= vdEscape(date('l, d F Y')) ?>
        </div>
    </header>

    <main class="vd-main">

        <div class="vd-heading">
            <div>
                <h1>Vendor Dashboard</h1>
                <p>Business overview, supplier obligations and inventory position.</p>
            </div>
        </div>

        <?php if ($error !== ''): ?>
            <div class="vd-error" role="alert">
                <?= vdEscape($error) ?>
            </div>
        <?php else: ?>

            <div class="vd-note">
                <strong>Financial note:</strong>
                Gross profit is sales revenue less the purchase cost of goods sold.
                It is not the same as cash in hand or net profit.
            </div>

            <h2 class="vd-section-title">Today's Business</h2>

            <section class="vd-metrics">
                <article class="vd-card vd-accent">
                    <span class="vd-metric-label">Today's Sales</span>
                    <div class="vd-metric-value">
                        ₹<?= vdMoney($metrics['sales']) ?>
                    </div>
                    <small class="vd-metric-note">
                        <?= number_format($metrics['invoices']) ?> posted invoice(s)
                    </small>
                </article>

                <article class="vd-card">
                    <span class="vd-metric-label">Cost of Goods Sold</span>
                    <div class="vd-metric-value">
                        ₹<?= vdMoney($metrics['cogs']) ?>
                    </div>
                    <small class="vd-metric-note">Purchase cost of goods sold today</small>
                </article>

                <article class="vd-card">
                    <span class="vd-metric-label">Today's Gross Profit</span>
                    <div class="vd-metric-value <?= $metrics['profit'] >= 0 ? 'vd-green' : 'vd-red' ?>">
                        ₹<?= vdMoney($metrics['profit']) ?>
                    </div>
                    <small class="vd-metric-note">Sales less goods purchase cost</small>
                </article>

                <article class="vd-card">
                    <span class="vd-metric-label">Customer Collections</span>
                    <div class="vd-metric-value">
                        ₹<?= vdMoney($metrics['collections']) ?>
                    </div>
                    <small class="vd-metric-note">Payments recorded today</small>
                </article>
            </section>

            <h2 class="vd-section-title">Supplier &amp; Customer Balances</h2>

            <section class="vd-metrics">
                <article class="vd-card">
                    <span class="vd-metric-label">Farmer Payments Due</span>
                    <div class="vd-metric-value">
                        ₹<?= vdMoney($metrics['farmer_due']) ?>
                    </div>
                    <small class="vd-metric-note">Current ledger balance</small>
                </article>

                <article class="vd-card">
                    <span class="vd-metric-label">Department Payments Due</span>
                    <div class="vd-metric-value">
                        ₹<?= vdMoney($metrics['department_due']) ?>
                    </div>
                    <small class="vd-metric-note">Current ledger balance</small>
                </article>

                <article class="vd-card">
                    <span class="vd-metric-label">Customer Outstanding</span>
                    <div class="vd-metric-value">
                        ₹<?= vdMoney($metrics['customer_due']) ?>
                    </div>
                    <small class="vd-metric-note">Posted invoices less recorded payments</small>
                </article>

                <article class="vd-card">
                    <span class="vd-metric-label">Today's Expenses</span>
                    <div class="vd-metric-value">
                        ₹<?= vdMoney($metrics['expenses']) ?>
                    </div>
                    <small class="vd-metric-note">Posted expenses</small>
                </article>
            </section>

            <h2 class="vd-section-title">Quick Access</h2>

            <section class="vd-shortcuts">
                <a class="vd-shortcut" href="/agroprocure/modules/reports/stock.php">
                    Stock Report
                    <small>Goods and remaining quantities</small>
                </a>

                <a class="vd-shortcut" href="/agroprocure/modules/reports/sales-profit.php">
                    Sales &amp; Profit
                    <small>Sales and cost analysis</small>
                </a>

                <a class="vd-shortcut" href="/agroprocure/modules/reports/supplier-outstanding.php">
                    Supplier Outstanding
                    <small>Farmer and department dues</small>
                </a>

                <a class="vd-shortcut" href="/agroprocure/modules/reports/customer-outstanding.php">
                    Customer Outstanding
                    <small>Unpaid customer invoices</small>
                </a>

                <a class="vd-shortcut" href="/agroprocure/modules/reports/supplier-payment-history.php">
                    Supplier Payment History
                    <small>Payments to each supplier</small>
                </a>

                <a class="vd-shortcut" href="/agroprocure/modules/reports/daily-summary.php">
                    Daily Summary
                    <small>Sales, cash and expenses</small>
                </a>

                <a class="vd-shortcut" href="/agroprocure/modules/reports/supplier-account-statement.php">
                    Supplier Statements
                    <small>Individual supplier accounts</small>
                </a>

                <a class="vd-shortcut" href="/agroprocure/modules/reports/vendor-cash-profit.php">
                    Cash &amp; Profit Report
                    <small>Financial overview by date</small>
                </a>
            </section>

            <section class="vd-panels">

                <div class="vd-panel">
                    <div class="vd-panel-header">
                        <h2>Current Stock Overview</h2>
                        <a href="/agroprocure/modules/reports/stock.php">Full Report →</a>
                    </div>

                    <div class="vd-table-wrap">
                        <table class="vd-table">
                            <thead>
                                <tr>
                                    <th>Goods</th>
                                    <th>Available Quantity</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$stockItems): ?>
                                    <tr>
                                        <td colspan="2" class="vd-empty">
                                            No stock records found.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($stockItems as $item): ?>
                                        <tr>
                                            <td>
                                                <?= vdEscape($item['goods_name']) ?>
                                                <br>
                                                <small><?= vdEscape($item['goods_code']) ?></small>
                                            </td>
                                            <td>
                                                <?= number_format((float)$item['available_quantity'], 3) ?>
                                                <?= vdEscape($item['unit']) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="vd-panel">
                    <div class="vd-panel-header">
                        <h2>Recent Posted Sales</h2>
                        <a href="/agroprocure/modules/reports/sales-profit.php">Sales Report →</a>
                    </div>

                    <div class="vd-table-wrap">
                        <table class="vd-table">
                            <thead>
                                <tr>
                                    <th>Invoice</th>
                                    <th>Customer</th>
                                    <th>Amount</th>
                                    <th>Payment</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$recentSales): ?>
                                    <tr>
                                        <td colspan="4" class="vd-empty">
                                            No posted sales found.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recentSales as $sale): ?>
                                        <tr>
                                            <td>
                                                <?= vdEscape($sale['invoice_number']) ?>
                                                <br>
                                                <small>
                                                    <?= vdEscape(date(
                                                        'd M Y, h:i A',
                                                        strtotime($sale['sold_at'])
                                                    )) ?>
                                                </small>
                                            </td>
                                            <td><?= vdEscape($sale['customer_name']) ?></td>
                                            <td>₹<?= vdMoney($sale['total_amount']) ?></td>
                                            <td>
                                                <span class="vd-status">
                                                    <?= vdEscape($sale['payment_status']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </section>

        <?php endif; ?>

        <footer class="vd-footer">
            AgroProcure · Vendor Dashboard · <?= vdEscape(date('Y')) ?>
        </footer>

    </main>
</div>
</body>
</html>
