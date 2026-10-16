<?php
require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_role(['admin', 'vendor', 'staff']);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session token is invalid. Refresh the page and try again.';
    } else {
        $goodsName = trim($_POST['goods_name'] ?? '');
        $unit = trim($_POST['unit'] ?? '');
        $description = trim($_POST['description'] ?? '');

        $allowedUnits = ['kg', 'quintal', 'tonne', 'litre', 'piece', 'bag'];

        if ($goodsName === '' || mb_strlen($goodsName) > 100) {
            $error = 'Enter a goods name of up to 100 characters.';
        } elseif (!in_array($unit, $allowedUnits, true)) {
            $error = 'Please select a valid unit.';
        } elseif (mb_strlen($description) > 500) {
            $error = 'Description must not exceed 500 characters.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO goods (goods_code, goods_name, unit, description)
                     VALUES (:code, :name, :unit, :description)'
                );

                $code = 'GDS-' . strtoupper(bin2hex(random_bytes(4)));

                $stmt->execute([
                    'code' => $code,
                    'name' => $goodsName,
                    'unit' => $unit,
                    'description' => $description !== '' ? $description : null,
                ]);

                header('Location: goods.php?created=1');
                exit;
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $error = 'This goods name and unit may already exist. Check the goods list.';
                } else {
                    error_log('AgroProcure goods registration error: ' . $e->getMessage());
                    $error = 'Could not save the goods. Please try again.';
                }
            }
        }
    }
}

if (isset($_GET['created'])) {
    $success = 'Goods registered successfully.';
}

$search = isset($_GET['q']) && is_string($_GET['q'])
    ? trim($_GET['q'])
    : '';

$stmt = $pdo->prepare(
    'SELECT id, goods_code, goods_name, unit, description, is_active, created_at
     FROM goods
     WHERE goods_name LIKE :name OR goods_code LIKE :code
     ORDER BY goods_name ASC
     LIMIT 200'
);

$term = '%' . $search . '%';
$stmt->execute(['name' => $term, 'code' => $term]);
$goodsList = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Goods Master | AgroProcure</title>
    <link rel="stylesheet" href="../../public/assets/css/goods.css">
</head>
<body>
<div class="farmers-page">
    <header class="farmers-header">
        <div>
            <p class="eyebrow">AGROPROCURE · INVENTORY SETUP</p>
            <h1>Goods Master</h1>
            <p>Register and manage the goods handled by your business.</p>
        </div>
        <a class="back-link" href="../../public/dashboard.php">Back to Dashboard</a>
    </header>

    <main class="farmers-layout">
        <section class="farmer-form-card">
            <h2>Add New Goods</h2>

            <?php if ($error !== ''): ?>
                <div class="message error"><?= e($error) ?></div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="message success"><?= e($success) ?></div>
            <?php endif; ?>

            <form method="post" action="goods.php">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <label for="goods_name">Goods Name</label>
                <input id="goods_name" name="goods_name" type="text"
                       maxlength="100" required placeholder="e.g. Rice">

                <label for="unit">Measurement Unit</label>
                <select id="unit" name="unit" required>
                    <option value="">Select unit</option>
                    <option value="kg">Kilogram (kg)</option>
                    <option value="quintal">Quintal</option>
                    <option value="tonne">Tonne</option>
                    <option value="litre">Litre</option>
                    <option value="piece">Piece</option>
                    <option value="bag">Bag</option>
                </select>

                <label for="description">Description (optional)</label>
                <textarea id="description" name="description"
                          maxlength="500" rows="3"></textarea>

                <button type="submit">Register Goods</button>
            </form>
        </section>

        <section class="farmer-list-card">
            <div class="list-heading">
                <div>
                    <h2>Registered Goods</h2>
                    <p>Goods master records</p>
                </div>
                <span class="count-badge"><?= count($goodsList) ?> shown</span>
            </div>

            <form method="get" action="goods.php" class="search-form">
                <input type="search" name="q" value="<?= e($search) ?>"
                       placeholder="Search goods name or code">
                <button type="submit">Search</button>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Goods Code</th>
                        <th>Goods Name</th>
                        <th>Unit</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$goodsList): ?>
                        <tr><td colspan="4">No goods found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($goodsList as $item): ?>
                            <tr>
                                <td><?= e($item['goods_code']) ?></td>
                                <td><?= e($item['goods_name']) ?></td>
                                <td><?= e($item['unit']) ?></td>
                                <td><?= (int)$item['is_active'] === 1 ? 'Active' : 'Inactive' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
</body>
</html>
