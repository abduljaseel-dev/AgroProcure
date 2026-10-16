
<?php
require_once __DIR__ . '/../../app/security.php';

require_role(['admin', 'vendor']);

$reportGroups = [
    'Sales & Revenue' => [
        [
            'title' => 'Daily Summary',
            'description' => 'Review daily business activity and totals.',
            'url' => 'daily-summary.php',
        ],
        [
            'title' => 'Sales & Profit',
            'description' => 'Review sales performance and profitability.',
            'url' => 'sales-profit.php',
        ],
        [
            'title' => 'Vendor Cash Profit',
            'description' => 'Review the vendor cash and profit report.',
            'url' => 'vendor-cash-profit.php',
        ],
    ],
    'Stock & Goods' => [
        [
            'title' => 'Current Stock',
            'description' => 'View current inventory balances.',
            'url' => 'stock.php',
        ],
        [
            'title' => 'Stock Ledger',
            'description' => 'Review stock movements and transaction history.',
            'url' => 'stock-ledger.php',
        ],
        [
            'title' => 'Low Stock',
            'description' => 'Identify goods that need replenishment.',
            'url' => 'low-stock.php',
        ],
        [
            'title' => 'Goods Rate Management',
            'description' => 'Manage or review goods rates.',
            'url' => 'goods-rate-management.php',
        ],
        [
            'title' => 'Price History',
            'description' => 'Review historical changes in goods prices.',
            'url' => 'price-history.php',
        ],
    ],
    'Customer Accounts' => [
        [
            'title' => 'Customer Outstanding',
            'description' => 'Identify unpaid customer bill balances.',
            'url' => 'customer-outstanding.php',
        ],
    ],
    'Supplier Accounts' => [
        [
            'title' => 'Supplier Outstanding',
            'description' => 'Review amounts owed to farmers and departments.',
            'url' => 'supplier-outstanding.php',
        ],
        [
            'title' => 'Supplier Account Statement',
            'description' => 'Review supplier-wise account transactions.',
            'url' => 'supplier-account-statement.php',
        ],
        [
            'title' => 'Supplier Payment History',
            'description' => 'Review recorded supplier payments.',
            'url' => 'supplier-payment-history.php',
        ],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports Centre | AgroProcure</title>
    <link rel="stylesheet" href="../../public/assets/css/index-pages.css">
</head>
<body>
<main class="portal">
    <header class="portal-header">
        <div>
            <p class="portal-eyebrow">AGROPROCURE / ANALYTICS</p>
            <h1>Reports Centre</h1>
            <p class="portal-subtitle">
                Business reports, stock records and account statements
            </p>
        </div>
        <a class="portal-button" href="../vendor/index.php">
            Owner Portal
        </a>
    </header>

    <section class="portal-intro">
        <h2>Reports &amp; Statements</h2>
        <p>
            Select a report to review business performance, inventory,
            customer balances or supplier accounts.
        </p>
    </section>

    <?php foreach ($reportGroups as $groupName => $reports): ?>
        <section class="report-group">
            <h2><?= e($groupName) ?></h2>

            <div class="portal-grid">
                <?php foreach ($reports as $report): ?>
                    <a class="portal-card" href="<?= e($report['url']) ?>">
                        <span class="portal-card-title">
                            <?= e($report['title']) ?>
                        </span>
                        <span class="portal-card-description">
                            <?= e($report['description']) ?>
                        </span>
                        <span class="portal-card-link">Open report →</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <footer class="portal-footer">
        AgroProcure &middot; Business Reporting
    </footer>
</main>
</body>
</html>
