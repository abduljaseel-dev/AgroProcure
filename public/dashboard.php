
<?php
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../config/database.php';

require_login();

$username = $_SESSION['username'] ?? 'Administrator';
$role = $_SESSION['user_role'] ?? '';
$stats = [
    'farmers' => 0,
    'goods' => 0,
    'stock' => 0,
    'today_sales' => 0.00,
];

try {
    $stats['farmers'] = (int) $pdo->query(
        'SELECT COUNT(*) FROM farmers WHERE is_active = 1'
    )->fetchColumn();

    $stats['goods'] = (int) $pdo->query(
        'SELECT COUNT(*) FROM goods WHERE is_active = 1'
    )->fetchColumn();

    $stats['stock'] = (float) $pdo->query(
        'SELECT COALESCE(SUM(remaining_quantity), 0) FROM stock_lots'
    )->fetchColumn();

    $stmt = $pdo->query(
        "SELECT COALESCE(SUM(total_amount), 0)
         FROM sales
         WHERE status = 'posted'
           AND sold_at >= CURDATE()
           AND sold_at < CURDATE() + INTERVAL 1 DAY"
    );
    $stats['today_sales'] = (float) $stmt->fetchColumn();
} catch (PDOException $e) {
    error_log('AgroProcure dashboard summary query failed.');
    http_response_code(500);
    exit('Dashboard data is temporarily unavailable.');
}

$sections = [
    [
        'title' => 'Farmer Management',
        'description' => 'Register farmers and manage supplier records.',
        'icon' => 'FM',
        'link' => '../modules/farmers/',
        'label' => 'Open Farmers',
    ],
    [
        'title' => 'Agriculture Department',
        'description' => 'Manage department accounts and supplied goods.',
        'icon' => 'AD',
        'link' => '../modules/department/',
        'label' => 'Open Department',
    ],
    [
        'title' => 'Vendor Operations',
        'description' => 'Manage stock, customer sales and supplier payments.',
        'icon' => 'VO',
        'link' => '../modules/vendor/',
        'label' => 'Open Vendor',
    ],
    [
    'title' => 'Goods Master',
    'description' => 'Register and manage agricultural goods.',
    'icon' => 'GM',
    'link' => '../modules/vendor/goods.php',
    'label' => 'Manage Goods',
    ],
    [
        'title' => 'Reports & Analytics',
        'description' => 'Review stock, prices, sales and financial reports.',
        'icon' => 'RA',
        'link' => '../modules/reports/',
        'label' => 'Open Reports',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | AgroProcure</title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body>
    <div class="app-layout">
        <aside class="sidebar">
            <a class="brand" href="dashboard.php">
                <span class="brand-mark">AP</span>
                <span>
                    <strong>AgroProcure</strong>
                    <small>Procurement System</small>
                </span>
            </a>

            <div class="nav-heading">MAIN MENU</div>
            <nav class="navigation" aria-label="Main navigation">
                <a class="nav-link active" href="dashboard.php">
                    <span class="nav-icon">DB</span> Dashboard
                </a>
                <a class="nav-link" href="../modules/farmers/">
                    <span class="nav-icon">FM</span> Farmers
                </a>
                <a class="nav-link" href="../modules/department/">
                    <span class="nav-icon">AD</span> Department
                </a>
                <a class="nav-link" href="../modules/vendor/">
                    <span class="nav-icon">VO</span> Vendor Operations
                </a>
                <a class="nav-link" href="../modules/reports/">
                    <span class="nav-icon">RP</span> Reports
                </a>
            </nav>

            <div class="sidebar-footer">
                <span class="status-dot"></span>
                Local system
                <small>Development environment</small>
            </div>
        </aside>

        <main class="main-content">
            <header class="topbar">
                <div>
                    <p class="eyebrow">AGRICULTURAL PROCUREMENT</p>
                    <h1>Dashboard</h1>
                </div>

                <div class="user-area">
                    <div class="user-avatar">
                        <?= e(strtoupper(substr($username, 0, 1))) ?>
                    </div>
                    <div class="user-details">
                        <strong><?= e($username) ?></strong>
                        <small><?= e(ucfirst($role)) ?></small>
                    </div>

                    <form method="post" action="logout.php">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e(csrf_token()) ?>"
                        >
                        <button class="logout-button" type="submit">
                            Sign Out
                        </button>
                    </form>
                </div>
            </header>

            <section class="welcome-panel">
                <div>
                    <span class="welcome-label">OVERVIEW</span>
                    <h2>Welcome to AgroProcure</h2>
                    <p>
                        Manage agricultural procurement, inventory,
                        supplier payments and business reporting from one place.
                    </p>
                </div>
                <div class="welcome-emblem" aria-hidden="true">AP</div>
            </section>

            <section class="section-block">
                <div class="section-heading">
                    <div>
                        <h2>Business Overview</h2>
                        <p>Summary of the current database records.</p>
                    </div>
                    <span class="environment-label">LOCAL PREVIEW</span>
                </div>

                <div class="stats-grid">
                    <article class="stat-card">
                        <div class="stat-top">
                            <span>Registered Farmers</span>
                            <span class="stat-icon blue">FM</span>
                        </div>
                        <strong class="stat-value">
                            <?= number_format($stats['farmers']) ?>
                        </strong>
                        <small>Active farmer records</small>
                    </article>

                    <article class="stat-card">
                        <div class="stat-top">
                            <span>Goods Catalogue</span>
                            <span class="stat-icon green">GD</span>
                        </div>
                        <strong class="stat-value">
                            <?= number_format($stats['goods']) ?>
                        </strong>
                        <small>Active goods and units</small>
                    </article>

                    <article class="stat-card">
                        <div class="stat-top">
                            <span>Remaining Stock</span>
                            <span class="stat-icon gold">ST</span>
                        </div>
                        <strong class="stat-value">
                            <?= number_format($stats['stock'], 3) ?>
                        </strong>
                        <small>Total quantity across stock lots</small>
                    </article>

                    <article class="stat-card">
                        <div class="stat-top">
                            <span>Today's Sales</span>
                            <span class="stat-icon purple">SL</span>
                        </div>
                        <strong class="stat-value currency">
                            ₹<?= number_format($stats['today_sales'], 2) ?>
                        </strong>
                        <small>Posted invoice totals, not profit</small>
                    </article>
                </div>
            </section>

            <section class="section-block">
                <div class="section-heading">
                    <div>
                        <h2>System Modules</h2>
                        <p>Select a module to manage its records.</p>
                    </div>
                </div>

                <div class="modules-grid">
                    <?php foreach ($sections as $section): ?>
                        <article class="module-card">
                            <div class="module-icon">
                                <?= e($section['icon']) ?>
                            </div>
                            <h3><?= e($section['title']) ?></h3>
                            <p><?= e($section['description']) ?></p>
                            <a href="<?= e($section['link']) ?>">
                                <?= e($section['label']) ?>
                                <span aria-hidden="true">→</span>
                            </a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <footer class="page-footer">
                <span>AgroProcure — Agricultural Procurement &amp; Payment Management System</span>
                <span>Development version</span>
            </footer>
        </main>
    </div>
</body>
</html>
