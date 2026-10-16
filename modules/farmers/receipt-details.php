
<?php
require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_role(['admin', 'vendor', 'staff']);

$rawId = $_GET['id'] ?? '';
$receiptId = is_string($rawId) ? filter_var($rawId, FILTER_VALIDATE_INT) : false;

if (!$receiptId || $receiptId < 1) {
    http_response_code(400);
    exit('Invalid receipt number.');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
        ? $_POST['csrf_token'] : null;

    $postedId = isset($_POST['receipt_id']) && is_string($_POST['receipt_id'])
        ? filter_var($_POST['receipt_id'], FILTER_VALIDATE_INT) : false;

    if (!verify_csrf_token($token)) {
        $error = 'Session expired or invalid. Refresh and try again.';
    } elseif ($postedId !== $receiptId) {
        $error = 'Invalid receipt submission.';
    } else {
        try {
            $pdo->beginTransaction();

            // Lock the receipt so two requests cannot post it simultaneously.
            $stmt = $pdo->prepare(
                'SELECT id, supplier_type, farmer_id, department_id, status
                 FROM supply_receipts
                 WHERE id = ?
                 FOR UPDATE'
            );
            $stmt->execute([$receiptId]);
            $lockedReceipt = $stmt->fetch();

            if (!$lockedReceipt) {
                throw new RuntimeException('Receipt not found.');
            }

            if ($lockedReceipt['supplier_type'] !== 'farmer') {
                throw new RuntimeException(
                    'This page only posts farmer supply receipts.'
                );
            }

            if ($lockedReceipt['status'] !== 'draft') {
                throw new RuntimeException(
                    'This receipt is not a draft. It cannot be posted again.'
                );
            }

            if (empty($lockedReceipt['farmer_id'])) {
                throw new RuntimeException(
                    'The receipt does not have a valid farmer.'
                );
            }

            // Lock all receipt lines and obtain their generated line totals.
            $stmt = $pdo->prepare(
                'SELECT id, goods_id, quantity, unit_cost, line_total
                 FROM supply_receipt_items
                 WHERE receipt_id = ?
                 ORDER BY id
                 FOR UPDATE'
            );
            $stmt->execute([$receiptId]);
            $items = $stmt->fetchAll();

            if (!$items) {
                throw new RuntimeException(
                    'A receipt must contain at least one goods item.'
                );
            }

            $totalValue = 0.0;

            foreach ($items as $item) {
                if ((float)$item['quantity'] <= 0 ||
                    (float)$item['unit_cost'] <= 0) {
                    throw new RuntimeException(
                        'A receipt contains an invalid quantity or rate.'
                    );
                }

                $totalValue += (float)$item['line_total'];
            }

            if ($totalValue <= 0) {
                throw new RuntimeException(
                    'The receipt total must be greater than zero.'
                );
            }

            // Defensive checks against records created outside this workflow.
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM stock_lots sl
                 INNER JOIN supply_receipt_items sri
                     ON sri.id = sl.receipt_item_id
                 WHERE sri.receipt_id = ?'
            );
            $stmt->execute([$receiptId]);

            if ((int)$stmt->fetchColumn() !== 0) {
                throw new RuntimeException(
                    'Stock lots already exist for this receipt. Please investigate before proceeding.'
                );
            }

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM supplier_ledger
                 WHERE supply_receipt_id = ?'
            );
            $stmt->execute([$receiptId]);

            if ((int)$stmt->fetchColumn() !== 0) {
                throw new RuntimeException(
                    'A supplier-ledger entry already exists. Please investigate before proceeding.'
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
                    'FR-' . $receiptId,
                    'Stock received from farmer supply receipt.',
                    $_SESSION['user_id']
                ]);
            }

            // One supplier-liability entry for this receipt.
            $stmt = $pdo->prepare(
                "INSERT INTO supplier_ledger
                    (supplier_type, farmer_id, department_id, entry_type,
                     supply_receipt_id, supplier_payment_id, amount_due,
                     amount_paid, description, recorded_by)
                 VALUES ('farmer', ?, NULL, 'supply', ?, NULL, ?, 0, ?, ?)"
            );

            $stmt->execute([
                $lockedReceipt['farmer_id'],
                $receiptId,
                number_format($totalValue, 2, '.', ''),
                'Goods supplied under farmer receipt FR-' . $receiptId,
                $_SESSION['user_id']
            ]);

            // Change the receipt status only within the same transaction.
            $stmt = $pdo->prepare(
                "UPDATE supply_receipts
                 SET status = 'posted'
                 WHERE id = ? AND status = 'draft'"
            );
            $stmt->execute([$receiptId]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'Receipt status changed unexpectedly. No changes were retained.'
                );
            }

            $details = json_encode([
                'receipt_id' => $receiptId,
                'supplier_type' => 'farmer',
                'farmer_id' => (int)$lockedReceipt['farmer_id'],
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
                $_SESSION['user_id'],
                'POST_SUPPLY_RECEIPT',
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
                    'AgroProcure receipt posting error: ' . $e->getMessage()
                );
                $error = 'Posting failed. The transaction was rolled back. Check the PHP error log if the problem continues.';
            }
        }
    }
}

$stmt = $pdo->prepare(
    "SELECT sr.id, sr.received_at, sr.status, sr.notes,
            f.farmer_code, f.full_name
     FROM supply_receipts sr
     INNER JOIN farmers f ON f.id = sr.farmer_id
     WHERE sr.id = ? AND sr.supplier_type = 'farmer'"
);
$stmt->execute([$receiptId]);
$receipt = $stmt->fetch();

if (!$receipt) {
    http_response_code(404);
    exit('Farmer receipt not found.');
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
$items = $stmt->fetchAll();

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
    <title>Review Receipt | AgroProcure</title>
    <link rel="stylesheet" href="../../public/assets/css/supply.css">
</head>
<body>
<div class="supply-page">
    <header class="supply-header">
        <div>
            <p class="eyebrow">AGROPROCURE · PROCUREMENT CONTROL</p>
            <h1>Review Supply Receipt</h1>
            <p>Verify the supplier, quantities and purchase value.</p>
        </div>
        <a class="back-link" href="receipts.php">Receipt Register</a>
    </header>

    <?php if ($error !== ''): ?>
        <div class="message error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($wasPosted && $receipt['status'] === 'posted'): ?>
        <div class="message success">
            Receipt posted successfully. Stock lots, stock movements,
            supplier balance and audit history were recorded together.
        </div>
    <?php endif; ?>

    <section class="form-card">
        <h2>Receipt FR-<?= (int)$receipt['id'] ?></h2>

        <p><strong>Farmer ID:</strong>
            <?= e($receipt['farmer_code']) ?></p>
        <p><strong>Farmer:</strong>
            <?= e($receipt['full_name']) ?></p>
        <p><strong>Date received:</strong>
            <?= e($receipt['received_at']) ?></p>
        <p><strong>Status:</strong>
            <?= e(ucfirst($receipt['status'])) ?></p>

        <?php if (!empty($receipt['notes'])): ?>
            <p><strong>Notes:</strong>
                <?= e($receipt['notes']) ?></p>
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
                <tfoot>
                <tr>
                    <th colspan="4">Total Purchase Value</th>
                    <th>₹<?= number_format($totalValue, 2) ?></th>
                </tr>
                </tfoot>
            </table>
        </div>

        <?php if ($receipt['status'] === 'draft' && $items): ?>
            <div class="notice">
                <strong>Before posting:</strong>
                verify the farmer, goods, quantities and rates.
                Posting updates stock and the supplier's outstanding balance.
            </div>

            <form method="post"
                  action="receipt-details.php?id=<?= (int)$receiptId ?>"
                  onsubmit="return confirm('Post this receipt? Stock and supplier balances will be updated.');">
                <input type="hidden" name="csrf_token"
                       value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="receipt_id"
                       value="<?= (int)$receiptId ?>">
                <button class="submit-button" type="submit">
                    Verify and Post Receipt
                </button>
            </form>
        <?php elseif ($receipt['status'] === 'posted'): ?>
            <p class="help">
                This receipt has already been posted. It cannot be posted again.
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
