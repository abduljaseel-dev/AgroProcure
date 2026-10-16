
<?php
require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_role(['admin', 'vendor']);

$error = '';
$success = '';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function salesMoney($amount): string
{
    return '₹' . number_format((float) $amount, 2);
}

function createInvoiceNumber(): string
{
    return 'INV-' . date('Ymd-His') . '-' .
        strtoupper(bin2hex(random_bytes(3)));
}

/*
|--------------------------------------------------------------------------
| Preserve form values after validation errors
|--------------------------------------------------------------------------
*/

$lotIds = array_fill(0, 5, '');
$quantities = array_fill(0, 5, '');
$rates = array_fill(0, 5, '');

$customerName = '';
$customerPhone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedName = $_POST['customer_name'] ?? '';
    $postedPhone = $_POST['customer_phone'] ?? '';

    $customerName = is_string($postedName)
        ? trim($postedName)
        : '';

    $customerPhone = is_string($postedPhone)
        ? trim($postedPhone)
        : '';

    if (isset($_POST['lot_id']) && is_array($_POST['lot_id'])) {
        for ($i = 0; $i < 5; $i++) {
            $value = $_POST['lot_id'][$i] ?? '';
            $lotIds[$i] = is_string($value) ? trim($value) : '';
        }
    }

    if (isset($_POST['quantity']) && is_array($_POST['quantity'])) {
        for ($i = 0; $i < 5; $i++) {
            $value = $_POST['quantity'][$i] ?? '';
            $quantities[$i] = is_string($value) ? trim($value) : '';
        }
    }

    if (isset($_POST['selling_rate']) &&
        is_array($_POST['selling_rate'])) {
        for ($i = 0; $i < 5; $i++) {
            $value = $_POST['selling_rate'][$i] ?? '';
            $rates[$i] = is_string($value) ? trim($value) : '';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Available stock
|--------------------------------------------------------------------------
|
| Each option represents one stock lot. The lot remains linked to its
| original farmer or department through the supply receipt.
|
*/

$stockSql = "
    SELECT
        sl.id AS lot_id,
        sl.remaining_quantity,
        sri.unit_cost,
        g.goods_code,
        g.goods_name,
        g.unit,
        sr.supplier_type,
        f.farmer_code,
        f.full_name AS farmer_name,
        d.department_code,
        d.department_name
    FROM stock_lots sl
    INNER JOIN supply_receipt_items sri
        ON sri.id = sl.receipt_item_id
    INNER JOIN supply_receipts sr
        ON sr.id = sri.receipt_id
    INNER JOIN goods g
        ON g.id = sri.goods_id
    LEFT JOIN farmers f
        ON f.id = sr.farmer_id
    LEFT JOIN department_accounts d
        ON d.id = sr.department_id
    WHERE sr.status = 'posted'
      AND sl.remaining_quantity > 0
    ORDER BY g.goods_name, sr.supplier_type, sl.id
";

$stockRows = $pdo->query($stockSql)->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Create draft bill
|--------------------------------------------------------------------------
|
| IMPORTANT:
| Saving a draft does not deduct stock. Stock is deducted only when a
| separate, validated sale-posting process is completed.
|
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Security validation failed. Refresh the page and try again.';
    } elseif (
        !isset($_POST['lot_id'], $_POST['quantity'], $_POST['selling_rate']) ||
        !is_array($_POST['lot_id']) ||
        !is_array($_POST['quantity']) ||
        !is_array($_POST['selling_rate']) ||
        count($_POST['lot_id']) !== 5 ||
        count($_POST['quantity']) !== 5 ||
        count($_POST['selling_rate']) !== 5
    ) {
        $error = 'Invalid bill items. Refresh the page and try again.';
    } elseif (
        strlen($customerName) > 150 ||
        strlen($customerPhone) > 30
    ) {
        $error = 'Customer details exceed the allowed length.';
    } else {
        $items = [];
        $usedLots = [];
        $validationError = '';

        for ($i = 0; $i < 5; $i++) {
            $lotText = $lotIds[$i];
            $quantityText = $quantities[$i];
            $rateText = $rates[$i];

            // Ignore only rows where all three fields are empty.
            if (
                $lotText === '' &&
                $quantityText === '' &&
                $rateText === ''
            ) {
                continue;
            }

            if (
                $lotText === '' ||
                !ctype_digit($lotText) ||
                (int) $lotText < 1
            ) {
                $validationError =
                    'Select a stock lot for every item row you have started.';
                break;
            }

            if (
                $quantityText === '' ||
                !preg_match('/^\d{1,11}(?:\.\d{1,3})?$/', $quantityText) ||
                (float) $quantityText <= 0
            ) {
                $validationError =
                    'Enter a quantity greater than zero, with at most 3 decimal places.';
                break;
            }

            if (
                $rateText === '' ||
                !preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $rateText) ||
                (float) $rateText <= 0
            ) {
                $validationError =
                    'Enter a selling rate greater than zero, with at most 2 decimal places.';
                break;
            }

            $lotId = (int) $lotText;

            if (isset($usedLots[$lotId])) {
                $validationError =
                    'The same stock lot cannot be selected twice on one bill.';
                break;
            }

            $usedLots[$lotId] = true;

            $items[] = [
                'lot_id' => $lotId,
                'quantity' => $quantityText,
                'rate' => number_format(
                    (float) $rateText,
                    2,
                    '.',
                    ''
                )
            ];
        }

        if ($validationError !== '') {
            $error = $validationError;
        } elseif (count($items) === 0) {
            $error = 'Add at least one item before saving the draft bill.';
        } else {
            try {
                $pdo->beginTransaction();

                /*
                 * Lock each selected lot and recheck availability.
                 * This prevents saving a draft against stock that has
                 * already become unavailable.
                 */
                $checkStock = $pdo->prepare("
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

                foreach ($items as $item) {
                    $checkStock->execute([$item['lot_id']]);
                    $lot = $checkStock->fetch(PDO::FETCH_ASSOC);

                    if (
                        !$lot ||
                        $lot['receipt_status'] !== 'posted' ||
                        (float) $lot['remaining_quantity'] <
                            (float) $item['quantity']
                    ) {
                        throw new RuntimeException(
                            'A selected stock lot no longer has enough available quantity. Refresh the page and check your items.'
                        );
                    }
                }

                $invoiceNumber = createInvoiceNumber();

                $userId = filter_var(
                    $_SESSION['user_id'] ?? null,
                    FILTER_VALIDATE_INT
                );

                if (!$userId || $userId < 1) {
                    throw new RuntimeException(
                        'Your login session is invalid. Please log in again.'
                    );
                }

                /*
                 * Create the bill as a draft.
                 * No stock movement or supplier payment entry is created here.
                 */
                $saleStmt = $pdo->prepare("
                    INSERT INTO sales (
                        invoice_number,
                        customer_name,
                        customer_phone,
                        sold_at,
                        subtotal,
                        discount_amount,
                        total_amount,
                        payment_status,
                        status,
                        created_by
                    ) VALUES (
                        ?, ?, ?, NOW(),
                        0.00, 0.00, 0.00,
                        'unpaid', 'draft', ?
                    )
                ");

                $saleStmt->execute([
                    $invoiceNumber,
                    $customerName !== ''
                        ? $customerName
                        : 'Walk-in customer',
                    $customerPhone !== ''
                        ? $customerPhone
                        : null,
                    $userId
                ]);

                $saleId = (int) $pdo->lastInsertId();

                $itemStmt = $pdo->prepare("
                    INSERT INTO sale_items (
                        sale_id,
                        stock_lot_id,
                        quantity,
                        selling_rate
                    ) VALUES (?, ?, ?, ?)
                ");

                foreach ($items as $item) {
                    $itemStmt->execute([
                        $saleId,
                        $item['lot_id'],
                        $item['quantity'],
                        $item['rate']
                    ]);
                }

                /*
                 * Use MySQL's generated line_total values for the bill total.
                 */
                $totalStmt = $pdo->prepare("
                    SELECT COALESCE(SUM(line_total), 0.00)
                    FROM sale_items
                    WHERE sale_id = ?
                ");

                $totalStmt->execute([$saleId]);
                $subtotal = $totalStmt->fetchColumn();

                $updateSale = $pdo->prepare("
                    UPDATE sales
                    SET subtotal = ?,
                        total_amount = ?
                    WHERE id = ?
                      AND status = 'draft'
                ");

                $updateSale->execute([
                    $subtotal,
                    $subtotal,
                    $saleId
                ]);

                if ($updateSale->rowCount() !== 1) {
                    throw new RuntimeException(
                        'The bill total could not be saved. Please try again.'
                    );
                }

                /*
                 * Record the action for audit purposes.
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
                    'CREATE_DRAFT_SALE',
                    'sale',
                    $saleId,
                    json_encode([
                        'invoice_number' => $invoiceNumber,
                        'item_count' => count($items),
                        'total' => (string) $subtotal
                    ], JSON_THROW_ON_ERROR),
                    $_SERVER['REMOTE_ADDR'] ?? null
                ]);

                $pdo->commit();

                // Redirect after saving to prevent accidental resubmission.
                header(
                    'Location: sales.php?created=' .
                    rawurlencode($invoiceNumber)
                );
                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($exception instanceof RuntimeException) {
                    $error = $exception->getMessage();
                } else {
                    error_log(
                        'AgroProcure draft sale error: ' .
                        $exception->getMessage()
                    );

                    $error =
                        'The draft bill could not be saved. No transaction changes were committed. Check the PHP error log for details.';
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Recent bills
|--------------------------------------------------------------------------
*/

$recentSql = "
    SELECT
        s.id,
        s.invoice_number,
        s.customer_name,
        s.sold_at,
        s.total_amount,
        s.payment_status,
        s.status,
        u.username AS created_by_name
    FROM sales s
    LEFT JOIN users u
        ON u.id = s.created_by
    ORDER BY s.id DESC
    LIMIT 100
";

$recentSales = $pdo->query($recentSql)->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['created']) && is_string($_GET['created'])) {
    $createdInvoice = trim($_GET['created']);

    if (
        $createdInvoice !== '' &&
        preg_match('/^INV-[A-Za-z0-9-]+$/', $createdInvoice)
    ) {
        $success = 'Draft bill created: ' . $createdInvoice;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Customer Sales | AgroProcure</title>

    <link
        rel="stylesheet"
        href="../../public/assets/css/sales.css"
    >
</head>

<body>
<div class="sales-page">

    <header class="sales-header">
        <div>
            <p class="sales-eyebrow">AGROPROCURE · VENDOR OPERATIONS</p>
            <h1>Customer Sales</h1>
            <p>Prepare customer bills using traceable supplier stock.</p>
        </div>

        <a
            class="sales-back-link"
            href="../../public/dashboard.php"
        >
            Main Dashboard
        </a>
    </header>
<?php if (isset($_GET['posted']) && is_string($_GET['posted'])): ?>
    <div class="sales-notice sales-notice-success" role="status">
        Sale posted successfully:
        <?= e($_GET['posted']) ?>
    </div>
<?php endif; ?>

<?php if (isset($_GET['error']) && is_string($_GET['error'])): ?>
    <div class="sales-notice sales-notice-error" role="alert">
        <?= e($_GET['error']) ?>
    </div>
<?php endif; ?>

    <section class="sales-card">
        <h2>Create a Draft Bill</h2>

        <p class="sales-description">
            Each item is linked to its original stock lot and supplier.
            Saving a draft does not reduce available stock.
        </p>

        <?php if (!$stockRows): ?>
            <div class="sales-notice sales-notice-error">
                No available stock was found. Record and post a supply
                receipt before creating a customer bill.
            </div>
        <?php else: ?>

            <form method="post" action="sales.php" class="sales-form">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrf_token()) ?>"
                >

                <div class="sales-form-grid">
                    <div class="sales-field">
                        <label for="customer_name">
                            Customer Name (optional)
                        </label>

                        <input
                            id="customer_name"
                            name="customer_name"
                            type="text"
                            maxlength="150"
                            value="<?= e($customerName) ?>"
                            autocomplete="name"
                        >
                    </div>

                    <div class="sales-field">
                        <label for="customer_phone">
                            Customer Phone (optional)
                        </label>

                        <input
                            id="customer_phone"
                            name="customer_phone"
                            type="text"
                            maxlength="30"
                            value="<?= e($customerPhone) ?>"
                            autocomplete="tel"
                        >
                    </div>
                </div>

                <div class="sales-table-wrap">
                    <table class="sales-table">
                        <thead>
                            <tr>
                                <th scope="col">Stock Lot / Supplier</th>
                                <th scope="col">Available Quantity</th>
                                <th scope="col">Sale Quantity</th>
                                <th scope="col">Selling Rate (₹)</th>
                            </tr>
                        </thead>

                        <tbody>
                        <?php for ($i = 0; $i < 5; $i++): ?>
                            <?php
                            $selectedLot = null;

                            foreach ($stockRows as $stock) {
                                if (
                                    (string) $stock['lot_id'] ===
                                    (string) $lotIds[$i]
                                ) {
                                    $selectedLot = $stock;
                                    break;
                                }
                            }
                            ?>

                            <tr>
                                <td>
                                    <select
                                        name="lot_id[]"
                                        class="sales-lot-select"
                                        aria-label="Stock lot and supplier, row <?= $i + 1 ?>"
                                    >
                                        <option value="">
                                            Select item and supplier
                                        </option>

                                        <?php foreach ($stockRows as $stock): ?>
                                            <?php
                                            if ($stock['supplier_type'] === 'farmer') {
                                                $owner = trim(
                                                    (string) ($stock['farmer_code'] ?? '') .
                                                    ' — ' .
                                                    (string) ($stock['farmer_name'] ?? '')
                                                );
                                            } else {
                                                $owner = trim(
                                                    (string) ($stock['department_code'] ?? '') .
                                                    ' — ' .
                                                    (string) ($stock['department_name'] ?? '')
                                                );
                                            }

                                            $optionLabel =
                                                $stock['goods_name'] .
                                                ' (' . $stock['goods_code'] . ')' .
                                                ' — ' . $owner .
                                                ' — Lot #' . $stock['lot_id'];
                                            ?>

                                            <option
                                                value="<?= (int) $stock['lot_id'] ?>"
                                                data-available="<?= e((string) $stock['remaining_quantity']) ?>"
                                                data-unit="<?= e((string) $stock['unit']) ?>"
                                                <?= (string) $lotIds[$i] ===
                                                    (string) $stock['lot_id']
                                                    ? 'selected'
                                                    : '' ?>
                                            >
                                                <?= e($optionLabel) ?>
                                                | Available:
                                                <?= e((string) $stock['remaining_quantity']) ?>
                                                <?= e((string) $stock['unit']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>

                                <td>
                                    <span class="sales-available">
                                        <?php if ($selectedLot): ?>
                                            <?= e(
                                                (string) $selectedLot['remaining_quantity'] .
                                                ' ' .
                                                (string) $selectedLot['unit']
                                            ) ?>
                                        <?php else: ?>
                                            Choose a lot
                                        <?php endif; ?>
                                    </span>
                                </td>

                                <td>
                                    <input
                                        type="number"
                                        name="quantity[]"
                                        min="0.001"
                                        step="0.001"
                                        inputmode="decimal"
                                        aria-label="Sale quantity, row <?= $i + 1 ?>"
                                        value="<?= e($quantities[$i]) ?>"
                                    >
                                </td>

                                <td>
                                    <input
                                        type="number"
                                        name="selling_rate[]"
                                        min="0.01"
                                        step="0.01"
                                        inputmode="decimal"
                                        aria-label="Selling rate, row <?= $i + 1 ?>"
                                        value="<?= e($rates[$i]) ?>"
                                    >
                                </td>
                            </tr>
                        <?php endfor; ?>
                        </tbody>
                    </table>
                </div>

                <p class="sales-description">
                    Enter the selling rate per unit. The final total is
                    calculated by the database when you save the draft.
                </p>

                <button type="submit" class="sales-button">
                    Save Draft Bill
                </button>
            </form>
        <?php endif; ?>
    </section>

    <section class="sales-card">
        <h2>Recent Bills</h2>

        <p class="sales-description">
            The latest 100 bills, including drafts and posted sales.
        </p>

        <div class="sales-table-wrap">
            <table class="sales-table">
                <thead>
                    <tr>
                        <th scope="col">Invoice Number</th>
                        <th scope="col">Date</th>
                        <th scope="col">Customer</th>
                        <th scope="col">Total</th>
                        <th scope="col">Payment Status</th>
                        <th scope="col">Bill Status</th>
                        <th scope="col">Created By</th>
                        <th scope="col">Action</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$recentSales): ?>
                    <tr>
                        <td colspan="7">No bills created yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentSales as $sale): ?>
                        <tr>
                            <td>
                                <strong><?= e($sale['invoice_number']) ?></strong>
                            </td>

                            <td><?= e($sale['sold_at']) ?></td>

                            <td><?= e($sale['customer_name'] ?? 'Walk-in customer') ?></td>

                            <td>
                                <strong>
                                    <?= e(salesMoney($sale['total_amount'])) ?>
                                </strong>
                            </td>

                            <td><?= e(ucfirst($sale['payment_status'])) ?></td>

                            <td><?= e(ucfirst($sale['status'])) ?></td>

                            <td>
                                <?= e($sale['created_by_name'] ?? 'Unknown') ?>
                            </td>
                            <td>
                            <?php if ($sale['status'] === 'draft'): ?>
                                <form
                                    method="post"
                                    action="post-sale.php"
                                    onsubmit="return confirm('Post this bill and deduct its quantities from stock? This action should only be used after verifying the bill.');"
                                >
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e(csrf_token()) ?>"
                                    >
                                    <input
                                        type="hidden"
                                        name="sale_id"
                                        value="<?= (int) $sale['id'] ?>"
                                    >
                                    <button type="submit" class="sales-button">
                                        Post Sale
                                    </button>
                                </form>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</div>

<script>
'use strict';

document.querySelectorAll('.sales-lot-select').forEach(function (select) {
    function updateAvailableQuantity() {
        const row = select.closest('tr');
        const display = row ? row.querySelector('.sales-available') : null;
        const option = select.options[select.selectedIndex];

        if (!display) {
            return;
        }

        if (!option || !option.value) {
            display.textContent = 'Choose a lot';
            return;
        }

        const quantity = option.dataset.available || '0';
        const unit = option.dataset.unit || '';

        display.textContent = quantity + (unit ? ' ' + unit : '');
    }

    select.addEventListener('change', updateAvailableQuantity);
    updateAvailableQuantity();
});
</script>

</body>
</html>
