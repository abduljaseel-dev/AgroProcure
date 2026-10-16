<?php
require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_role(['admin', 'vendor', 'staff']);

$rawId = $_GET['id'] ?? '';
$receiptId = is_string($rawId)
    ? filter_var($rawId, FILTER_VALIDATE_INT)
    : false;

if (!$receiptId || $receiptId < 1) {
    http_response_code(400);
    exit('Invalid receipt number.');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
        ? $_POST['csrf_token'] : null;

    $postedId = isset($_POST['receipt_id']) && is_string($_POST['receipt_id'])
        ? filter_var($_POST['receipt_id'], FILTER_VALIDATE_INT)
        : false;

    if (!verify_csrf_token($token)) {
        $error = 'Session expired or invalid. Refresh and try again.';
    } elseif ($postedId !== $receiptId) {
        $error = 'Invalid receipt submission.';
    } else {
        try {
            $pdo->beginTransaction();

            // Lock the receipt to prevent simultaneous duplicate posting.
            $stmt = $pdo->prepare(
                'SELECT id, supplier_type, farmer_id, department_id, status
                 FROM supply_receipts
                 WHERE id = ?
                 FOR UPDATE'
            );
            $stmt->execute([$receiptId]);
            $lockedReceipt = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$lockedReceipt) {
                throw new RuntimeException('Receipt not found.');
            }

            if ($lockedReceipt['supplier_type'] !== 'department') {
                throw new RuntimeException(
                    'This page only posts department supply receipts.'
                );
            }

            if ($lockedReceipt['status'] !== 'draft') {
                throw new RuntimeException(
                    'This receipt is not a draft. It cannot be posted again.'
                );
            }

            if (empty($lockedReceipt['department_id'])) {
                throw new RuntimeException(
                    'The receipt does not have a valid department.'
                );
            }

            if (!empty($lockedReceipt['farmer_id'])) {
                throw new RuntimeException(
                    'This department receipt unexpectedly references a farmer. Please investigate.'
                );
            }

            // Confirm that the department account exists and lock it.
            $stmt = $pdo->prepare(
                'SELECT id, department_code, department_name
                 FROM department_accounts
                 WHERE id = ?
                 FOR UPDATE'
            );
            $stmt->execute([$lockedReceipt['department_id']]);
            $department = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$department) {
                throw new RuntimeException(
                    'The department linked to this receipt no longer exists.'
                );
            }

            // Lock receipt lines.
            $stmt = $pdo->prepare(
                'SELECT id, goods_id, quantity, unit_cost, line_total
                 FROM supply_receipt_items
                 WHERE receipt_id = ?
                 ORDER BY id
                 FOR UPDATE'
            );
            $stmt->execute([$receiptId]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$items) {
                throw new RuntimeException(
                    'A receipt must contain at least one goods item.'
                );
            }

            $totalValue = 0.0;

            foreach ($items as $item) {
                if (
                    (float)$item['quantity'] <= 0 ||
                    (float)$item['unit_cost'] <= 0 ||
                    $item['line_total'] === null ||
                    (float)$item['line_total'] <= 0
                ) {
                    throw new RuntimeException(
                        'A receipt contains an invalid quantity, rate or line total.'
                    );
                }

                $totalValue += (float)$item['line_total'];
            }

            if (!is_finite($totalValue) || $totalValue <= 0) {
                throw new RuntimeException(
                    'The receipt total must be greater than zero.'
                );
            }

            // Validate the referenced goods still exist.
            $goodsCheck = $pdo->prepare(
                'SELECT id
                 FROM goods
                 WHERE id = ?
                 FOR UPDATE'
            );

            foreach ($items as $item) {
                $goodsCheck->execute([$item['goods_id']]);

                if (!$goodsCheck->fetchColumn()) {
                    throw new RuntimeException(
                        'A goods record referenced by this receipt is missing.'
                    );
                }
            }

            // Prevent duplicate stock lots.
            $stmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM stock_lots sl
                 INNER JOIN supply_receipt_items sri
                     ON sri.id = sl.receipt_item_id
                 WHERE sri.receipt_id = ?'
            );
            $stmt->execute([$receiptId]);

            if ((int)$stmt->fetchColumn() !== 0) {
                throw new RuntimeException(
                    'Stock lots already exist for this receipt. Investigate before proceeding.'
                );
            }

            // Prevent duplicate supplier liability entries.
            $stmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM supplier_ledger
                 WHERE supply_receipt_id = ?'
            );
            $stmt->execute([$receiptId]);

            if ((int)$stmt->fetchColumn() !== 0) {
                throw new RuntimeException(
                    'A supplier-ledger entry already exists. Investigate before proceeding.'
                );
            }

            $insertLot = $pdo->prepare(
                'INSERT INTO stock_lots
                    (receipt_item_id, original_quantity, remaining_quantity)
                 VALUES (?, ?, ?)'
            );

            $insertMovement = $pdo->prepare(
                "INSERT INTO stock_movements
                    (stock_lot_id, movement_type, quantity_in, quantity_out,
                     receipt_item_id, sale_item_id, reference_number,
                     notes, created_by)
                 VALUES (?, 'receipt', ?, 0, ?, NULL, ?, ?, ?)"
            );

            foreach ($items as $item) {
                $quantity = $item['quantity'];

                $insertLot->execute([
                    $item['id'],
                    $quantity,
                    $quantity
                ]);

                $stockLotId = (int)$pdo->lastInsertId();

                $insertMovement->execute([
                    $stockLotId,
                    $quantity,
                    $item['id'],
                    'DREC-' . $receiptId,
                    'Stock received from department supply receipt.',
                    $_SESSION['user_id'] ?? null
                ]);
            }

            // Create one liability entry, linked to the department only.
            $stmt = $pdo->prepare(
                "INSERT INTO supplier_ledger
                    (supplier_type, farmer_id, department_id, entry_type,
                     supply_receipt_id, supplier_payment_id, amount_due,
                     amount_paid, description, recorded_by)
                 VALUES ('department', NULL, ?, 'supply', ?, NULL, ?, 0, ?, ?)"
            );

            $stmt->execute([
                $lockedReceipt['department_id'],
                $receiptId,
                number_format($totalValue, 2, '.', ''),
                'Goods supplied by department under receipt DREC-' . $receiptId,
                $_SESSION['user_id'] ?? null
            ]);

            // Post the receipt only after stock and ledger entries are ready.
            $stmt = $pdo->prepare(
                "UPDATE supply_receipts
                 SET status = 'posted'
                 WHERE id = ? AND status = 'draft'
                   AND supplier_type = 'department'"
            );
            $stmt->execute([$receiptId]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'Receipt status changed unexpectedly. No changes were retained.'
                );
            }

            $details = json_encode([
                'receipt_id' => $receiptId,
                'supplier_type' => 'department',
                'department_id' => (int)$lockedReceipt['department_id'],
                'department_code' => $department['department_code'],
                'item_count' => count($items),
                'total_value' => number_format($totalValue, 2, '.', '')
            ], JSON_UNESCAPED_SLASHES);

            $stmt = $pdo->prepare(
                'INSERT INTO audit_logs
                    (user_id, action, entity_type, entity_id,
                     details, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );

            $stmt->execute([
                $_SESSION['user_id'] ?? null,
                'POST_DEPARTMENT_SUPPLY_RECEIPT',
                'supply_receipts',
                $receiptId,
                $details,
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            $pdo->commit();

            header(
                'Location: receipt-details.php?id=' .
                $receiptId . '&posted=1'
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
                    'AgroProcure department receipt posting error: ' .
                    $e->getMessage()
                );
                $error = 'Posting failed and the transaction was rolled back. Check the PHP error log if the problem continues.';
            }
        }
    }
}

// Fetch only department receipts.
$stmt = $pdo->prepare(
    "SELECT sr.id, sr.receipt_number, sr.received_at,
            sr.status, sr.notes, sr.department_id,
            d.department_code, d.department_name
     FROM supply_receipts sr
     INNER JOIN department_accounts d
         ON d.id = sr.department_id
     WHERE sr.id = ?
       AND sr.supplier_type = 'department'
       AND sr.farmer_id IS NULL"
);
$stmt->execute([$receiptId]);
$receipt = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$receipt) {
    http_response_code(404);
    exit('Department receipt not found.');
}

$stmt = $pdo->prepare(
    'SELECT sri.id, g.goods_code, g.goods_name, g.unit,
            sri.quantity, sri.unit_cost, sri.line_total
     FROM supply_receipt_items sri
     INNER JOIN goods g ON g.id = sri.goods_id
     WHERE sri.receipt_id = ?
     ORDER BY sri.id'
);
$stmt->execute([$receiptId]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalValue = 0.0;
foreach ($items as $item) {
    $totalValue += (float)$item['line_total'];
}

$wasPosted = isset($_GET['posted']) && $_GET['posted'] === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Department Receipt Review | AgroProcure</title>
    <link rel="stylesheet" href="../../public/assets/css/supply.css">
    <style>
        .department-tag {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 5px;
            background: #e8f1f8;
            color: #16486a;
            font-weight: 700;
            font-size: 12px;
        }
        .receipt-meta {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px 24px;
            margin-bottom: 22px;
        }
        .receipt-meta p { overflow-wrap: anywhere; }
        .warning-note {
            margin: 18px 0;
            padding: 13px 15px;
            border-left: 4px solid #b17b20;
            background: #fff7e7;
            color: #674a18;
            line-height: 1.6;
        }
        .total-value {
            text-align: right;
            font-size: 20px;
            font-weight: 800;
            margin: 20px 0;
        }
        @media (max-width: 650px) {
            .receipt-meta { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="supply-page">
    <header class="supply-header">
        <div>
            <p class="eyebrow">AGROPROCURE · PROCUREMENT CONTROL</p>
            <h1>Review Department Receipt</h1>
            <p>Verify department ownership, quantities and purchase value.</p>
        </div>
        <a class="back-link" href="receipts.php">Department Receipt Register</a>
    </header>

    <?php if ($error !== ''): ?>
        <div class="message error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($wasPosted && $receipt['status'] === 'posted'): ?>
        <div class="message success">
            Department receipt posted successfully. Stock, department liability
            and audit history were recorded in one transaction.
        </div>
    <?php endif; ?>

    <section class="form-card">
        <span class="department-tag">AGRICULTURE DEPARTMENT</span>
        <h2><?= e($receipt['receipt_number']) ?></h2>

        <div class="receipt-meta">
            <p><strong>Department Code:</strong><br>
                <?= e($receipt['department_code']) ?></p>
            <p><strong>Department:</strong><br>
                <?= e($receipt['department_name']) ?></p>
            <p><strong>Date Received:</strong><br>
                <?= e($receipt['received_at']) ?></p>
            <p><strong>Status:</strong><br>
                <?= e(ucfirst($receipt['status'])) ?></p>
        </div>

        <?php if (!empty($receipt['notes'])): ?>
            <p><strong>Notes:</strong> <?= e($receipt['notes']) ?></p>
        <?php endif; ?>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Goods Code</th>
                    <th>Goods</th>
                    <th>Quantity</th>
                    <th>Purchase Rate</th>
                    <th>Line Total</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$items): ?>
                    <tr><td colspan="5">No items recorded.</td></tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?= e($item['goods_code']) ?></td>
                            <td>
                                <?= e($item['goods_name']) ?>
                                (<?= e($item['unit']) ?>)
                            </td>
                            <td><?= e((string)$item['quantity']) ?></td>
                            <td>₹<?= number_format((float)$item['unit_cost'], 2) ?></td>
                            <td>₹<?= number_format((float)$item['line_total'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="total-value">
            Total Purchase Value: ₹<?= number_format($totalValue, 2) ?>
        </div>

        <?php if ($receipt['status'] === 'draft' && $items): ?>
            <div class="warning-note">
                <strong>Confirm before posting:</strong>
                posting increases available stock and creates an outstanding
                balance for this department. Check all quantities and rates first.
            </div>

            <form method="post"
                  action="receipt-details.php?id=<?= (int)$receiptId ?>">
                <input type="hidden" name="csrf_token"
                       value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="receipt_id"
                       value="<?= (int)$receiptId ?>">
                <button class="submit-button" type="submit"
                        onclick="return confirm('Post this department receipt? Stock and department balances will be updated.');">
                    Verify and Post Department Receipt
                </button>
            </form>
        <?php elseif ($receipt['status'] === 'posted'): ?>
            <p class="help">
                This receipt has already been posted and cannot be posted again.
            </p>
        <?php else: ?>
            <p class="help">
                Only draft receipts containing goods items can be posted.
            </p>
        <?php endif; ?>
    </section>
</div>
</body>
</html>