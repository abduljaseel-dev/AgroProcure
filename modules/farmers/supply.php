
<?php
require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_role(['admin', 'vendor', 'staff']);

$error = '';
$success = '';

$farmers = $pdo->query(
    "SELECT id, farmer_code, full_name
     FROM farmers
     WHERE is_active = 1
     ORDER BY full_name"
)->fetchAll();

$goods = $pdo->query(
    "SELECT id, goods_code, goods_name, unit
     FROM goods
     WHERE is_active = 1
     ORDER BY goods_name"
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token(
        isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
            ? $_POST['csrf_token'] : null
    )) {
        $error = 'Session expired or invalid. Refresh and try again.';
    } else {
        $farmerId = filter_var(
            $_POST['farmer_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $notes = trim(
            is_string($_POST['notes'] ?? null) ? $_POST['notes'] : ''
        );

        $goodsIds = $_POST['goods_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $rates = $_POST['unit_cost'] ?? [];

        if (!is_array($goodsIds) ||
            !is_array($quantities) ||
            !is_array($rates)) {
            $error = 'Invalid goods details submitted.';
        } elseif (!$farmerId || $notes !== '' && mb_strlen($notes) > 1000) {
            $error = 'Select a farmer and enter valid notes.';
        } elseif (count($goodsIds) !== 5 ||
                  count($quantities) !== 5 ||
                  count($rates) !== 5) {
            $error = 'Invalid receipt rows. Refresh and try again.';
        } else {
            $items = [];

            for ($i = 0; $i < 5; $i++) {
                $gid = $goodsIds[$i];
                $qty = $quantities[$i];
                $rate = $rates[$i];

                $gid = is_string($gid) || is_int($gid) ? (string)$gid : '';
                $qty = is_string($qty) || is_int($qty) ? (string)$qty : '';
                $rate = is_string($rate) || is_int($rate) ? (string)$rate : '';

                // An entirely empty row is allowed.
                if ($gid === '' && $qty === '' && $rate === '') {
                    continue;
                }

                if (
                    filter_var($gid, FILTER_VALIDATE_INT) === false ||
                    !is_numeric($qty) ||
                    !is_numeric($rate) ||
                    !is_finite((float)$qty) ||
                    !is_finite((float)$rate) ||
                    (float)$qty <= 0 ||
                    (float)$rate <= 0 ||
                    !preg_match('/^\d+(\.\d{1,3})?$/', $qty) ||
                    !preg_match('/^\d+(\.\d{1,2})?$/', $rate)
                ) {
                    $error = 'Each used row needs a valid goods item, positive quantity (up to 3 decimals), and positive purchase rate (up to 2 decimals).';
                    break;
                }

                $items[] = [
                    'goods_id' => (int)$gid,
                    'quantity' => $qty,
                    'unit_cost' => $rate,
                ];
            }

            if ($error === '' && count($items) === 0) {
                $error = 'Add at least one goods item.';
            }

            if ($error === '') {
                try {
                    $stmt = $pdo->prepare(
                        'SELECT id FROM farmers
                         WHERE id = ? AND is_active = 1'
                    );
                    $stmt->execute([$farmerId]);

                    if (!$stmt->fetch()) {
                        throw new RuntimeException(
                            'The selected farmer is unavailable.'
                        );
                    }

                    $checkGoods = $pdo->prepare(
                        'SELECT id FROM goods
                         WHERE id = ? AND is_active = 1'
                    );

                    foreach ($items as $item) {
                        $checkGoods->execute([$item['goods_id']]);

                        if (!$checkGoods->fetch()) {
                            throw new RuntimeException(
                                'One selected goods item is unavailable. Refresh the page.'
                            );
                        }
                    }

                    $pdo->beginTransaction();

                    // Always create a DRAFT. Do not create stock or supplier dues here.
                    $stmt = $pdo->prepare(
                        "INSERT INTO supply_receipts
                            (supplier_type, farmer_id, department_id,
                             received_at, received_by, notes, status)
                         VALUES ('farmer', ?, NULL, NOW(), ?, ?, 'draft')"
                    );

                    $stmt->execute([
                        $farmerId,
                        $_SESSION['user_id'],
                        $notes !== '' ? $notes : null,
                    ]);

                    $receiptId = (int)$pdo->lastInsertId();

                    $insertItem = $pdo->prepare(
                        'INSERT INTO supply_receipt_items
                            (receipt_id, goods_id, quantity, unit_cost)
                         VALUES (?, ?, ?, ?)'
                    );

                    foreach ($items as $item) {
                        $insertItem->execute([
                            $receiptId,
                            $item['goods_id'],
                            $item['quantity'],
                            $item['unit_cost'],
                        ]);
                    }

                    $pdo->commit();

                    header(
                        'Location: supply.php?created=' . $receiptId
                    );
                    exit;

                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    if ($e instanceof RuntimeException) {
                        $error = $e->getMessage();
                    } else {
                        error_log(
                            'AgroProcure draft receipt error: ' .
                            $e->getMessage()
                        );
                        $error = 'Could not save the draft receipt. Check the database error log if the problem continues.';
                    }
                }
            }
        }
    }
}

if (isset($_GET['created']) && is_string($_GET['created'])) {
    $success = 'Draft receipt #' .
        preg_replace('/[^0-9]/', '', $_GET['created']) .
        ' saved. Stock and supplier balances have not changed.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Farmer Supply Receipt | AgroProcure</title>
    <link rel="stylesheet"
          href="../../public/assets/css/supply.css">
</head>
<body>
<div class="supply-page">
    <header class="supply-header">
        <div>
            <p class="eyebrow">AGROPROCURE · PROCUREMENT</p>
            <h1>Farmer Goods Supply</h1>
            <p>Record goods received directly from a registered farmer.</p>
        </div>
        <a class="back-link" href="index.php">Back to Farmers</a>
    </header>

    <?php if ($error !== ''): ?>
        <div class="message error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div class="message success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if (!$farmers): ?>
        <div class="message error">
            No active farmers found. Register a farmer before recording a supply.
        </div>
    <?php elseif (!$goods): ?>
        <div class="message error">
            No active goods found. Register goods in Goods Master first.
        </div>
    <?php else: ?>
        <form method="post" action="supply.php" class="receipt-form">
            <input type="hidden" name="csrf_token"
                   value="<?= e(csrf_token()) ?>">

            <section class="form-card">
                <h2>Supplier Details</h2>

                <label for="farmer_id">Farmer</label>
                <select id="farmer_id" name="farmer_id" required>
                    <option value="">Select farmer</option>
                    <?php foreach ($farmers as $farmer): ?>
                        <option value="<?= (int)$farmer['id'] ?>"
                            <?= (string)($_POST['farmer_id'] ?? '') ===
                                (string)$farmer['id'] ? 'selected' : '' ?>>
                            <?= e($farmer['farmer_code']) ?> —
                            <?= e($farmer['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="help">
                    Every item on this receipt will belong to this farmer.
                </p>
            </section>

            <section class="form-card">
                <h2>Goods Received</h2>
                <p class="help">
                    Enter up to five goods items on this receipt.
                    Leave unused rows blank.
                </p>

                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Goods</th>
                            <th>Quantity</th>
                            <th>Purchase Rate (₹)</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php for ($i = 0; $i < 5; $i++): ?>
                            <tr>
                                <td>
                                    <select name="goods_id[]" aria-label="Goods item <?= $i + 1 ?>">
                                        <option value="">Select goods</option>
                                        <?php foreach ($goods as $good): ?>
                                            <option value="<?= (int)$good['id'] ?>"
                                                <?= (string)(($_POST['goods_id'][$i] ?? '')) ===
                                                    (string)$good['id'] ? 'selected' : '' ?>>
                                                <?= e($good['goods_name']) ?>
                                                (<?= e($good['unit']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="number" name="quantity[]"
                                           min="0.001" step="0.001"
                                           value="<?= e((string)($_POST['quantity'][$i] ?? '')) ?>"
                                           placeholder="0.000"
                                           aria-label="Quantity row <?= $i + 1 ?>">
                                </td>
                                <td>
                                    <input type="number" name="unit_cost[]"
                                           min="0.01" step="0.01"
                                           value="<?= e((string)($_POST['unit_cost'][$i] ?? '')) ?>"
                                           placeholder="0.00"
                                           aria-label="Purchase rate row <?= $i + 1 ?>">
                                </td>
                            </tr>
                        <?php endfor; ?>
                        </tbody>
                    </table>
                </div>

                <label for="notes">Notes (optional)</label>
                <textarea id="notes" name="notes" rows="3"
                          maxlength="1000"
                          placeholder="Delivery details or receipt remarks"><?= e(
                              is_string($_POST['notes'] ?? null)
                                  ? $_POST['notes'] : ''
                          ) ?></textarea>
            </section>

            <div class="notice">
                <strong>Draft receipt only.</strong>
                Saving this form records the supplier and purchase details.
                It does not yet increase stock or create supplier dues.
            </div>

            <button class="submit-button" type="submit">
                Save Draft Receipt
            </button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
