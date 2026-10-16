<?php
require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_role(['admin', 'vendor', 'staff']);

$errors = [];
$success = '';
$old = $_POST;

function department_receipt_number(): string
{
    return 'DREC-' . date('Ymd-His') . '-' .
        strtoupper(bin2hex(random_bytes(3)));
}

function department_money_input($value): bool
{
    return is_string($value) || is_int($value) || is_float($value);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session token is invalid. Refresh the page and try again.';
    } else {
        $departmentId = filter_var(
            $_POST['department_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $receivedAtInput = trim($_POST['received_at'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        $goodsIds = $_POST['goods_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $rates = $_POST['unit_cost'] ?? [];

        if (!is_array($goodsIds) || !is_array($quantities) || !is_array($rates)) {
            $errors[] = 'Invalid goods form data.';
            $goodsIds = [];
            $quantities = [];
            $rates = [];
        }

        if (!$departmentId) {
            $errors[] = 'Select a valid department.';
        }

        if (mb_strlen($notes) > 2000) {
            $errors[] = 'Notes must not exceed 2,000 characters.';
        }

        $receivedAt = null;

        if ($receivedAtInput !== '') {
            $date = DateTimeImmutable::createFromFormat(
                '!Y-m-d\TH:i',
                $receivedAtInput
            );

            if (!$date || $date->format('Y-m-d\TH:i') !== $receivedAtInput) {
                $errors[] = 'Enter a valid receipt date and time.';
            } else {
                $receivedAt = $date->format('Y-m-d H:i:s');
            }
        } else {
            $receivedAt = date('Y-m-d H:i:s');
        }

        $items = [];
        $rowCount = max(count($goodsIds), count($quantities), count($rates));

        if ($rowCount < 1) {
            $errors[] = 'Add at least one goods item.';
        } elseif ($rowCount > 20) {
            $errors[] = 'A receipt can contain at most 20 goods lines.';
        } else {
            $seenGoods = [];

            for ($i = 0; $i < $rowCount; $i++) {
                $rawGoodsId = $goodsIds[$i] ?? '';
                $rawQuantity = $quantities[$i] ?? '';
                $rawRate = $rates[$i] ?? '';

                // Allow completely empty extra rows.
                if (
                    trim((string)$rawGoodsId) === '' &&
                    trim((string)$rawQuantity) === '' &&
                    trim((string)$rawRate) === ''
                ) {
                    continue;
                }

                if (!department_money_input($rawGoodsId) ||
                    !department_money_input($rawQuantity) ||
                    !department_money_input($rawRate)) {
                    $errors[] = 'Invalid data in goods row ' . ($i + 1) . '.';
                    continue;
                }

                $goodsId = filter_var(
                    (string)$rawGoodsId,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );

                $quantityText = trim((string)$rawQuantity);
                $rateText = trim((string)$rawRate);

                if (!$goodsId) {
                    $errors[] = 'Select valid goods in row ' . ($i + 1) . '.';
                    continue;
                }

                if (
                    !preg_match('/^\d{1,11}(?:\.\d{1,3})?$/', $quantityText) ||
                    (float)$quantityText <= 0
                ) {
                    $errors[] = 'Quantity in row ' . ($i + 1) .
                        ' must be greater than zero with up to 3 decimal places.';
                    continue;
                }

                if (
                    !preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $rateText) ||
                    (float)$rateText <= 0
                ) {
                    $errors[] = 'Purchase rate in row ' . ($i + 1) .
                        ' must be greater than zero with up to 2 decimal places.';
                    continue;
                }

                if (isset($seenGoods[$goodsId])) {
                    $errors[] = 'Each goods type can appear only once per receipt. ' .
                        'Combine duplicate rows for the same goods.';
                    continue;
                }

                $seenGoods[$goodsId] = true;

                $quantity = number_format((float)$quantityText, 3, '.', '');
                $unitCost = number_format((float)$rateText, 2, '.', '');

                // Reject values that would exceed the intended DECIMAL precision.
                if ((float)$quantity > 99999999999.999 ||
                    (float)$unitCost > 999999999999.99) {
                    $errors[] = 'Quantity or rate is too large in row ' . ($i + 1) . '.';
                    continue;
                }

                $items[] = [
                    'goods_id' => (int)$goodsId,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost
                ];
            }

            if (!$items && !$errors) {
                $errors[] = 'Enter at least one complete goods row.';
            }
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();

                // Lock and validate the department account.
                $departmentStmt = $pdo->prepare(
                    'SELECT id, department_code, department_name
                     FROM department_accounts
                     WHERE id = ? AND is_active = 1
                     FOR UPDATE'
                );
                $departmentStmt->execute([$departmentId]);
                $department = $departmentStmt->fetch(PDO::FETCH_ASSOC);

                if (!$department) {
                    throw new RuntimeException(
                        'The selected department does not exist or is inactive.'
                    );
                }

                // Validate each goods record is active.
                $goodsCheck = $pdo->prepare(
                    'SELECT id, goods_name
                     FROM goods
                     WHERE id = ? AND is_active = 1
                     FOR UPDATE'
                );

                foreach ($items as $item) {
                    $goodsCheck->execute([$item['goods_id']]);

                    if (!$goodsCheck->fetch(PDO::FETCH_ASSOC)) {
                        throw new RuntimeException(
                            'One or more selected goods are inactive or no longer available. Refresh and try again.'
                        );
                    }
                }

                $receiptNumber = '';

                for ($attempt = 0; $attempt < 5; $attempt++) {
                    $candidate = department_receipt_number();

                    $check = $pdo->prepare(
                        'SELECT id FROM supply_receipts
                         WHERE receipt_number = ?
                         LIMIT 1'
                    );
                    $check->execute([$candidate]);

                    if (!$check->fetch(PDO::FETCH_ASSOC)) {
                        $receiptNumber = $candidate;
                        break;
                    }
                }

                if ($receiptNumber === '') {
                    throw new RuntimeException(
                        'Could not generate a unique receipt number. Please retry.'
                    );
                }

                $receiptStmt = $pdo->prepare(
                    'INSERT INTO supply_receipts
                     (receipt_number, supplier_type, farmer_id, department_id,
                      received_at, received_by, notes, status)
                     VALUES (?, ?, NULL, ?, ?, ?, ?, ?)'
                );

                $receiptStmt->execute([
                    $receiptNumber,
                    'department',
                    $departmentId,
                    $receivedAt,
                    $_SESSION['user_id'] ?? null,
                    $notes !== '' ? $notes : null,
                    'draft'
                ]);

                $receiptId = (int)$pdo->lastInsertId();

                $itemStmt = $pdo->prepare(
                    'INSERT INTO supply_receipt_items
                     (receipt_id, goods_id, quantity, unit_cost)
                     VALUES (?, ?, ?, ?)'
                );

                foreach ($items as $item) {
                    $itemStmt->execute([
                        $receiptId,
                        $item['goods_id'],
                        $item['quantity'],
                        $item['unit_cost']
                    ]);
                }

                $auditStmt = $pdo->prepare(
                    'INSERT INTO audit_logs
                     (user_id, action, entity_type, entity_id, details, ip_address)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );

                $auditStmt->execute([
                    $_SESSION['user_id'] ?? null,
                    'CREATE_DEPARTMENT_SUPPLY_DRAFT',
                    'supply_receipt',
                    $receiptId,
                    json_encode([
                        'receipt_number' => $receiptNumber,
                        'department_id' => (int)$departmentId,
                        'department_code' => $department['department_code'],
                        'item_count' => count($items),
                        'status' => 'draft'
                    ], JSON_UNESCAPED_UNICODE),
                    $_SERVER['REMOTE_ADDR'] ?? null
                ]);

                $pdo->commit();

                header(
                    'Location: supply.php?created=1&receipt=' .
                    rawurlencode($receiptNumber)
                );
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($e instanceof RuntimeException) {
                    $errors[] = $e->getMessage();
                } elseif (
                    $e instanceof PDOException &&
                    $e->getCode() === '23000'
                ) {
                    $errors[] = 'A duplicate or conflicting record was detected. Please refresh and try again.';
                } else {
                    error_log('AgroProcure department supply draft error: ' . $e->getMessage());
                    $errors[] = 'The draft receipt could not be saved. Please try again.';
                }
            }
        }
    }
}

if (isset($_GET['created'])) {
    $success = 'Department supply draft created successfully. Receipt: ' .
        (isset($_GET['receipt']) && is_string($_GET['receipt'])
            ? $_GET['receipt'] : '');
}

$departments = [];
$goodsList = [];
$receipts = [];

try {
    $departments = $pdo->query(
        'SELECT id, department_code, department_name
         FROM department_accounts
         WHERE is_active = 1
         ORDER BY department_name'
    )->fetchAll(PDO::FETCH_ASSOC);

    $goodsList = $pdo->query(
        'SELECT id, goods_code, goods_name, unit
         FROM goods
         WHERE is_active = 1
         ORDER BY goods_name'
    )->fetchAll(PDO::FETCH_ASSOC);

    $receiptsStmt = $pdo->query(
        'SELECT r.id, r.receipt_number, r.received_at, r.status,
                d.department_code, d.department_name,
                COUNT(i.id) AS item_count,
                COALESCE(SUM(i.line_total), 0) AS receipt_total
         FROM supply_receipts r
         INNER JOIN department_accounts d ON d.id = r.department_id
         LEFT JOIN supply_receipt_items i ON i.receipt_id = r.id
         WHERE r.supplier_type = "department"
         GROUP BY r.id, r.receipt_number, r.received_at, r.status,
                  d.department_code, d.department_name
         ORDER BY r.id DESC
         LIMIT 100'
    );
    $receipts = $receiptsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('AgroProcure department supply listing error: ' . $e->getMessage());
    $errors[] = 'Department or goods lists could not be loaded. Check the server error log.';
}

$oldGoods = is_array($old['goods_id'] ?? null) ? $old['goods_id'] : [];
$oldQuantities = is_array($old['quantity'] ?? null) ? $old['quantity'] : [];
$oldRates = is_array($old['unit_cost'] ?? null) ? $old['unit_cost'] : [];

$oldRowCount = max(count($oldGoods), count($oldQuantities), count($oldRates));
$displayRows = max(5, min(20, $oldRowCount));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Supply Receipts | AgroProcure</title>
    <link rel="stylesheet" href="../../public/assets/css/goods.css">
    <style>
        .ds-page {
            max-width: 1450px;
            margin: 0 auto;
            padding: 28px 22px 48px;
            color: #183247;
        }
        .ds-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;
            margin-bottom: 24px;
        }
        .ds-header h1 { margin: 0 0 7px; font-size: 28px; }
        .ds-header p { margin: 0; color: #64798b; }
        .ds-back, .ds-button {
            display: inline-block;
            border: 0;
            border-radius: 7px;
            background: #123b58;
            color: #fff;
            padding: 11px 15px;
            font: inherit;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }
        .ds-button:hover, .ds-back:hover { background: #1d5477; }
        .ds-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 22px;
        }
        .ds-panel {
            background: #fff;
            border: 1px solid #dbe5ec;
            border-radius: 12px;
            padding: 22px;
            box-shadow: 0 4px 16px rgba(18, 59, 88, .045);
        }
        .ds-panel h2 { margin: 0 0 18px; font-size: 20px; }
        .ds-fields {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }
        .ds-field { margin-bottom: 15px; }
        .ds-field label {
            display: block;
            margin-bottom: 6px;
            color: #40596d;
            font-weight: 700;
            font-size: 13px;
        }
        .ds-field input, .ds-field select, .ds-field textarea {
            width: 100%;
            box-sizing: border-box;
            padding: 11px 12px;
            border: 1px solid #cbd8e2;
            border-radius: 7px;
            background: #fff;
            color: #183247;
            font: inherit;
        }
        .ds-field textarea { min-height: 75px; resize: vertical; }
        .ds-table-wrap { overflow-x: auto; margin: 12px 0 18px; }
        .ds-table { width: 100%; border-collapse: collapse; min-width: 650px; }
        .ds-table th, .ds-table td {
            padding: 11px 9px;
            text-align: left;
            border-bottom: 1px solid #e4ebf0;
            vertical-align: middle;
        }
        .ds-table th {
            background: #f3f7fa;
            font-size: 12px;
            color: #40596d;
            white-space: nowrap;
        }
        .ds-table td input, .ds-table td select {
            width: 100%;
            min-width: 100px;
            box-sizing: border-box;
            padding: 9px;
            border: 1px solid #cad7e1;
            border-radius: 6px;
            font: inherit;
        }
        .ds-table td .ds-goods { min-width: 210px; }
        .ds-total { font-weight: 700; white-space: nowrap; }
        .ds-grand-total {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 20px;
            margin: 18px 0;
            padding: 16px;
            background: #f2f7fa;
            border-radius: 8px;
        }
        .ds-grand-total strong { font-size: 23px; }
        .ds-note { color: #667b8c; font-size: 13px; line-height: 1.5; }
        .ds-alert { padding: 12px 15px; border-radius: 8px; margin-bottom: 16px; }
        .ds-error { background: #fff0f0; color: #982e2e; }
        .ds-success { background: #e4f6eb; color: #17643a; }
        .ds-badge {
            display: inline-block;
            padding: 4px 9px;
            border-radius: 20px;
            background: #fff0d4;
            color: #825000;
            font-size: 11px;
            font-weight: 700;
        }
        .ds-register td { font-size: 13px; }
        .ds-register th { text-align: left; }
        .ds-empty { text-align: center; padding: 24px !important; color: #708292; }
        .ds-actions { display: flex; flex-wrap: wrap; gap: 10px; }
        .ds-remove {
            background: #fbe8e8;
            color: #8c2c2c;
            border: 0;
            border-radius: 6px;
            padding: 8px 10px;
            cursor: pointer;
            font-weight: 700;
        }
        @media (max-width: 650px) {
            .ds-page { padding: 18px 12px 32px; }
            .ds-header h1 { font-size: 23px; }
            .ds-panel { padding: 15px; }
            .ds-fields { grid-template-columns: 1fr; gap: 0; }
        }
    </style>
</head>
<body>
<main class="ds-page">
    <header class="ds-header">
        <div>
            <p class="eyebrow">AGROPROCURE · DEPARTMENT PROCUREMENT</p>
            <h1>Department Supply Receipts</h1>
            <p>Record goods received from Agriculture Department accounts.</p>
        </div>
        <a class="ds-back" href="../../public/dashboard.php">Back to Dashboard</a>
    </header>

    <?php if ($success !== ''): ?>
        <div class="ds-alert ds-success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php foreach ($errors as $message): ?>
        <div class="ds-alert ds-error"><?= e($message) ?></div>
    <?php endforeach; ?>

    <section class="ds-panel">
        <h2>Create Department Supply Draft</h2>
        <p class="ds-note">
            Saving this form creates a draft only. Stock and department balances
            are not changed until the receipt is posted through the authorised
            receipt-posting workflow.
        </p>

        <?php if (!$departments): ?>
            <div class="ds-alert ds-error">
                No active department accounts are available.
                Register or activate a department first.
            </div>
        <?php elseif (!$goodsList): ?>
            <div class="ds-alert ds-error">
                No active goods are available. Register or activate goods first.
            </div>
        <?php else: ?>
            <form method="post" action="supply.php">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <div class="ds-fields">
                    <div class="ds-field">
                        <label for="department_id">Agriculture Department *</label>
                        <select name="department_id" id="department_id" required>
                            <option value="">Select department</option>
                            <?php foreach ($departments as $department): ?>
                                <option value="<?= (int)$department['id'] ?>"
                                    <?= (string)($old['department_id'] ?? '') ===
                                        (string)$department['id'] ? 'selected' : '' ?>>
                                    <?= e($department['department_code']) ?> —
                                    <?= e($department['department_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="ds-field">
                        <label for="received_at">Date and time received *</label>
                        <input type="datetime-local" name="received_at" id="received_at"
                               required
                               value="<?= e(
                                   isset($old['received_at']) && is_string($old['received_at'])
                                       ? $old['received_at']
                                       : date('Y-m-d\TH:i')
                               ) ?>">
                    </div>
                </div>

                <h2>Goods Received</h2>
                <div class="ds-table-wrap">
                    <table class="ds-table" id="departmentGoodsTable">
                        <thead>
                        <tr>
                            <th style="width:38%">Goods</th>
                            <th>Quantity</th>
                            <th>Purchase rate (₹)</th>
                            <th>Line total (₹)</th>
                            <th>Action</th>
                        </tr>
                        </thead>
                        <tbody id="departmentGoodsRows">
                        <?php for ($i = 0; $i < $displayRows; $i++): ?>
                            <tr>
                                <td>
                                    <select class="ds-goods" name="goods_id[]" aria-label="Goods">
                                        <option value="">Select goods</option>
                                        <?php foreach ($goodsList as $goods): ?>
                                            <option value="<?= (int)$goods['id'] ?>"
                                                <?= (string)($oldGoods[$i] ?? '') ===
                                                    (string)$goods['id'] ? 'selected' : '' ?>>
                                                <?= e($goods['goods_name']) ?>
                                                (<?= e($goods['unit']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <input name="quantity[]" type="number" min="0.001"
                                           step="0.001" max="99999999999.999"
                                           value="<?= e((string)($oldQuantities[$i] ?? '')) ?>"
                                           placeholder="0.000" aria-label="Quantity">
                                </td>
                                <td>
                                    <input name="unit_cost[]" type="number" min="0.01"
                                           step="0.01" max="999999999999.99"
                                           value="<?= e((string)($oldRates[$i] ?? '')) ?>"
                                           placeholder="0.00" aria-label="Purchase rate">
                                </td>
                                <td class="ds-total">₹0.00</td>
                                <td>
                                    <button class="ds-remove" type="button">Remove</button>
                                </td>
                            </tr>
                        <?php endfor; ?>
                        </tbody>
                    </table>
                </div>

                <div class="ds-actions">
                    <button class="ds-button" type="button" id="addDepartmentGoods">
                        + Add Goods Row
                    </button>
                </div>

                <div class="ds-grand-total">
                    <span>Estimated receipt value</span>
                    <strong id="departmentGrandTotal">₹0.00</strong>
                </div>

                <div class="ds-field">
                    <label for="notes">Notes (optional)</label>
                    <textarea id="notes" name="notes" maxlength="2000"
                              placeholder="Reference number, delivery details or remarks"><?= e(
                                  isset($old['notes']) && is_string($old['notes'])
                                      ? $old['notes'] : ''
                              ) ?></textarea>
                </div>

                <button class="ds-button" type="submit">Save Draft Receipt</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="ds-panel" style="margin-top:22px">
        <h2>Recent Department Receipts</h2>
        <div class="ds-table-wrap">
            <table class="ds-table ds-register">
                <thead>
                <tr>
                    <th>Receipt Number</th>
                    <th>Department</th>
                    <th>Date Received</th>
                    <th>Goods Lines</th>
                    <th>Receipt Value</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$receipts): ?>
                    <tr>
                        <td class="ds-empty" colspan="6">No department receipts found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($receipts as $receipt): ?>
                        <tr>
                            <td><strong><?= e($receipt['receipt_number']) ?></strong></td>
                            <td>
                                <?= e($receipt['department_name']) ?>
                                <div class="ds-note"><?= e($receipt['department_code']) ?></div>
                            </td>
                            <td><?= e(date('d M Y, h:i A', strtotime($receipt['received_at']))) ?></td>
                            <td><?= (int)$receipt['item_count'] ?></td>
                            <td>₹<?= e(number_format((float)$receipt['receipt_total'], 2)) ?></td>
                            <td>
                                <span class="ds-badge"><?= e(ucfirst($receipt['status'])) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<script src="../../public/assets/js/department-supply.js" defer></script>
</body>
</html>