
<?php
require_once __DIR__ . '/../../app/security.php';

require_role(['admin', 'vendor']);

$ownerModules = [
    [
        'title' => 'Dashboard',
        'description' => 'View business activity and key figures.',
        'url' => 'dashboard.php',
        'icon' => '▦',
    ],
    [
        'title' => 'Sales & Billing',
        'description' => 'Manage sales, invoices and customer bills.',
        'url' => 'sales.php',
        'icon' => '₹',
    ],
    [
        'title' => 'Post a Sale',
        'description' => 'Record and post a new customer sale.',
        'url' => 'post-sale.php',
        'icon' => '+',
    ],
    [
        'title' => 'Customer Payments',
        'description' => 'Record customer receipts and track payments.',
        'url' => 'customer-payments.php',
        'icon' => '↙',
    ],
    [
        'title' => 'Daily Cashbook',
        'description' => 'Manage opening cash, receipts, payments and closing cash.',
        'url' => 'cashbook.php',
        'icon' => '▤',
    ],
    [
        'title' => 'Business Expenses',
        'description' => 'Record and review business expenses.',
        'url' => 'expenses.php',
        'icon' => '−',
    ],
    [
        'title' => 'Goods Management',
        'description' => 'Access the goods register and product information.',
        'url' => 'goods.php',
        'icon' => '▣',
    ],
    [
        'title' => 'Reports Centre',
        'description' => 'Open stock, sales, profit and supplier reports.',
        'url' => '../reports/index.php',
        'icon' => '▥',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Portal | AgroProcure</title>
    <link rel="stylesheet" href="../../public/assets/css/index-pages.css">
</head>
<body>
<main class="portal">
    <header class="portal-header">
        <div>
            <p class="portal-eyebrow">AGROPROCURE / MANAGEMENT</p>
            <h1>Owner Portal</h1>
            <p class="portal-subtitle">
                Agricultural Procurement &amp; Payment Management System
            </p>
        </div>
        <a class="portal-button" href="dashboard.php">Open Dashboard</a>
    </header>

    <section class="portal-intro">
        <h2>Business Operations</h2>
        <p>
            Access your daily operations, manage transactions and monitor
            business records from one place.
        </p>
    </section>

    <section class="portal-grid" aria-label="Business modules">
        <?php foreach ($ownerModules as $module): ?>
            <a class="portal-card" href="<?= e($module['url']) ?>">
                <span class="portal-icon" aria-hidden="true">
                    <?= e($module['icon']) ?>
                </span>
                <span class="portal-card-title">
                    <?= e($module['title']) ?>
                </span>
                <span class="portal-card-description">
                    <?= e($module['description']) ?>
                </span>
                <span class="portal-card-link">Open module →</span>
            </a>
        <?php endforeach; ?>
    </section>

    <footer class="portal-footer">
        AgroProcure &middot; Authorized business access only
    </footer>
</main>
</body>
</html>
