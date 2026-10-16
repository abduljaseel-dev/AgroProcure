
<?php
require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_role(['admin', 'vendor']);

/*
|--------------------------------------------------------------------------
| Sale posting endpoint
|--------------------------------------------------------------------------
| Only draft sales can be posted.
| Stock, movement records, sale status and audit log are updated atomically.
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: sales.php');
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    header('Location: sales.php?error=' . rawurlencode(
        'Security validation failed. Refresh the page and try again.'
    ));
    exit;
}

$saleIdValue = $_POST['sale_id'] ?? '';

if (
    !is_string($saleIdValue) ||
    !ctype_digit($saleIdValue) ||
    (int) $saleIdValue < 1
) {
    header('Location: sales.php?error=' . rawurlencode('Invalid sale ID.'));
    exit;
}

$saleId = (int) $saleIdValue;

$userId = filter_var(
    $_SESSION['user_id'] ?? null,
    FILTER_VALIDATE_INT
);

if (!$userId || $userId < 1) {
    header('Location: sales.php?error=' . rawurlencode(
        'Your login session is invalid. Please log in again.'
    ));
    exit;
}

try {
    $pdo->beginTransaction();

    /*
     * Lock the sale so two users cannot post the same draft concurrently.
     */
    $saleStmt = $pdo->prepare("
        SELECT id, invoice_number, status, total_amount
        FROM sales
        WHERE id = ?
        FOR UPDATE
    ");
    $saleStmt->execute([$saleId]);
    $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);

    if (!$sale) {
        throw new RuntimeException('The requested bill was not found.');
    }

    if ($sale['status'] !== 'draft') {
        throw new RuntimeException(
            'Only draft bills can be posted. This bill may already be posted or cancelled.'
        );
    }

    /*
     * Lock sale items in stock-lot order to reduce deadlock risk.
     */
    $itemsStmt = $pdo->prepare("
        SELECT id, stock_lot_id, quantity, selling_rate
        FROM sale_items
        WHERE sale_id = ?
        ORDER BY stock_lot_id ASC, id ASC
    ");
    $itemsStmt->execute([$saleId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$items) {
        throw new RuntimeException(
            'This bill has no items and cannot be posted.'
        );
    }

    /*
     * Refuse to post if any item already has a stock movement.
     * This is an additional safeguard alongside the unique sale_item_id.
     */
    $existingMovementStmt = $pdo->prepare("
        SELECT id
        FROM stock_movements
        WHERE sale_item_id = ?
        LIMIT 1
    ");

    /*
     * Lock each lot, recheck its receipt and availability, and deduct stock.
     * All changes are rolled back if any item fails.
     */
    $lotStmt = $pdo->prepare("
        SELECT
            sl.id,
            sl.remaining_quantity,
            sr.status AS receipt_status
        FROM stock_lots sl
        INNER JOIN supply_receipt_items sri
            ON sri.id = sl.receipt_item_id
        INNER JOIN supply_receipts sr
            ON sr.id = sri.receipt_id
        WHERE sl.id = ?
        FOR UPDATE
    ");

    $deductStmt = $pdo->prepare("
        UPDATE stock_lots
        SET remaining_quantity = remaining_quantity - ?
        WHERE id = ?
          AND remaining_quantity >= ?
    ");

    $movementStmt = $pdo->prepare("
        INSERT INTO stock_movements (
            stock_lot_id,
            movement_type,
            quantity_in,
            quantity_out,
            sale_item_id,
            reference_number,
            notes,
            created_by
        ) VALUES (
            ?, 'sale', 0.000, ?, ?, ?, ?, ?
        )
    ");

    $postedItems = [];

    foreach ($items as $item) {
        $itemId = (int) $item['id'];
        $lotId = (int) $item['stock_lot_id'];
        $quantity = (string) $item['quantity'];

        $existingMovementStmt->execute([$itemId]);

        if ($existingMovementStmt->fetchColumn()) {
            throw new RuntimeException(
                'A stock movement already exists for one of this bill’s items. Posting was stopped for safety.'
            );
        }

        $lotStmt->execute([$lotId]);
        $lot = $lotStmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$lot ||
            $lot['receipt_status'] !== 'posted'
        ) {
            throw new RuntimeException(
                'A selected stock lot or its supply receipt is unavailable. Check the bill before posting.'
            );
        }

        if (
            (float) $lot['remaining_quantity'] <
            (float) $quantity
        ) {
            throw new RuntimeException(
                'Insufficient stock for lot #' . $lotId .
                '. Available quantity: ' .
                $lot['remaining_quantity'] .
                '. Posting was cancelled.'
            );
        }

        $deductStmt->execute([
            $quantity,
            $lotId,
            $quantity
        ]);

        if ($deductStmt->rowCount() !== 1) {
            throw new RuntimeException(
                'Stock could not be updated for lot #' . $lotId .
                '. Posting was cancelled.'
            );
        }

        $postedItems[] = [
            'sale_item_id' => $itemId,
            'stock_lot_id' => $lotId,
            'quantity' => $quantity
        ];
    }

    /*
     * Record one movement per sale item.
     */
    foreach ($postedItems as $item) {
        $movementStmt->execute([
            $item['stock_lot_id'],
            $item['quantity'],
            $item['sale_item_id'],
            $sale['invoice_number'],
            'Stock deducted for posted customer sale.',
            $userId
        ]);
    }

    /*
     * Change status only after all stock deductions and movements succeed.
     */
    $updateSaleStmt = $pdo->prepare("
        UPDATE sales
        SET status = 'posted'
        WHERE id = ?
          AND status = 'draft'
    ");
    $updateSaleStmt->execute([$saleId]);

    if ($updateSaleStmt->rowCount() !== 1) {
        throw new RuntimeException(
            'The bill status could not be updated. Posting was cancelled.'
        );
    }

    /*
     * Audit the completed posting.
     */
    $auditStmt = $pdo->prepare("
        INSERT INTO audit_logs (
            user_id,
            action,
            entity_type,
            entity_id,
            details,
            ip_address
        ) VALUES (?, ?, ?, ?, ?, ?)
    ");

    $auditStmt->execute([
        $userId,
        'POST_SALE',
        'sale',
        $saleId,
        json_encode([
            'invoice_number' => $sale['invoice_number'],
            'item_count' => count($postedItems),
            'total_amount' => (string) $sale['total_amount'],
            'stock_movements_created' => count($postedItems)
        ], JSON_THROW_ON_ERROR),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $pdo->commit();

    header('Location: sales.php?posted=' .
        rawurlencode($sale['invoice_number']));
    exit;

} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($exception instanceof RuntimeException) {
        $message = $exception->getMessage();
    } else {
        error_log(
            'AgroProcure sale posting error: ' .
            $exception->getMessage()
        );

        $message =
            'The bill could not be posted. All changes were rolled back. Check the PHP error log for details.';
    }

    header('Location: sales.php?error=' . rawurlencode($message));
    exit;
}
