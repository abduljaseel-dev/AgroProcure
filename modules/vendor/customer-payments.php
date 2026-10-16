
<?php

require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

date_default_timezone_set('Asia/Kolkata');


require_role(['admin', 'vendor']);

$success = '';
$error = '';

/**
 * Generate a unique customer payment reference.
 */
function customerPaymentReference(): string
{
    return 'CP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
}

/**
 * Format an amount safely for display.
 */
function customerPaymentMoney(mixed $amount): string
{
    return number_format((float) $amount, 2);
}

/**
 * Redirect after a successful POST.
 */
function customerPaymentRedirect(string $message): never
{
    header('Location: customer-payments.php?success=' . rawurlencode($message));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transactionStarted = false;

    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Security verification failed. Refresh the page and try again.');
        }

        $saleId = filter_var(
            $_POST['sale_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if (!$saleId) {
            throw new RuntimeException('Please select a valid customer bill.');
        }

        $amountInput = trim((string) ($_POST['amount'] ?? ''));

        if (!preg_match('/^\d{1,14}(?:\.\d{1,2})?$/', $amountInput)) {
            throw new RuntimeException('Enter a valid payment amount with up to two decimal places.');
        }

        $amount = (float) $amountInput;

        if (!is_finite($amount) || $amount <= 0) {
            throw new RuntimeException('Payment amount must be greater than zero.');
        }

        // Round-trip check prevents accepting values beyond the database precision.
        $amount = round($amount, 2);

        $allowedMethods = ['cash', 'bank_transfer', 'upi', 'cheque', 'other'];
        $paymentMethod = (string) ($_POST['payment_method'] ?? '');

        if (!in_array($paymentMethod, $allowedMethods, true)) {
            throw new RuntimeException('Select a valid payment method.');
        }

        $transactionReference = trim((string) ($_POST['transaction_reference'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if (strlen($transactionReference) > 100) {
            throw new RuntimeException('Transaction reference cannot exceed 100 characters.');
        }

        if (strlen($notes) > 5000) {
            throw new RuntimeException('Notes are too long.');
        }

        $paidAtInput = trim((string) ($_POST['paid_at'] ?? ''));

        if ($paidAtInput === '') {
            $paidAt = date('Y-m-d H:i:s');
        } else {
            $dateObject = DateTime::createFromFormat('!Y-m-d\TH:i', $paidAtInput);
            $dateErrors = DateTime::getLastErrors();

            if (
                $dateObject === false ||
                ($dateErrors !== false &&
                    ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) ||
                $dateObject->format('Y-m-d\TH:i') !== $paidAtInput
            ) {
                throw new RuntimeException('Enter a valid payment date and time.');
            }

            // Do not permit future-dated receipts.
            if ($dateObject->getTimestamp() > time()) {
                throw new RuntimeException('Payment date and time cannot be in the future.');
            }

            
            $paidAt = $dateObject->format('Y-m-d H:i:s');
        }

        // Cash receipts must belong to today's business date.
        // The server enforces this; browser-side validation is not sufficient.
        if (
            $paymentMethod === 'cash' &&
            substr($paidAt, 0, 10) !== date('Y-m-d')
        ) {
            throw new RuntimeException(
                'Cash payments must be recorded with today’s date. '
                . 'Backdated or future-dated cash receipts are not allowed.'
            );
        }


        $userId = filter_var(
            $_SESSION['user_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if (!$userId) {
            throw new RuntimeException('Your login session is invalid. Please log in again.');
        }

        $pdo->beginTransaction();
        $transactionStarted = true;

        // Lock the sale first. This serializes payment attempts for the same bill.
        $saleStatement = $pdo->prepare(
            "SELECT id, invoice_number, total_amount, status, payment_status
             FROM sales
             WHERE id = :sale_id
             FOR UPDATE"
        );
        $saleStatement->execute(['sale_id' => $saleId]);
        $sale = $saleStatement->fetch(PDO::FETCH_ASSOC);

        if (!$sale) {
            throw new RuntimeException('The selected customer bill was not found.');
        }

        if ($sale['status'] !== 'posted') {
            throw new RuntimeException('Payments can only be recorded against posted bills.');
        }

        $billTotal = round((float) $sale['total_amount'], 2);

        if ($billTotal <= 0) {
            throw new RuntimeException('This bill has no payable amount.');
        }

        // Calculate the balance from actual payment records, not the status field.
        $paidStatement = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0)
             FROM customer_payments
             WHERE sale_id = :sale_id"
        );
        $paidStatement->execute(['sale_id' => $saleId]);

        $alreadyPaid = round((float) $paidStatement->fetchColumn(), 2);
        $outstanding = round($billTotal - $alreadyPaid, 2);

        if ($outstanding <= 0) {
            throw new RuntimeException('This bill is already fully paid.');
        }

        if ($amount > $outstanding) {
            throw new RuntimeException(
                'Payment exceeds the outstanding balance of ₹' .
                number_format($outstanding, 2) . '.'
            );
        }

        // Physical cash receipts must be associated with an open session
        // for today's business date.
        $cashSessionId = null;

        if ($paymentMethod === 'cash') {
            $sessionStatement = $pdo->prepare(
                "SELECT id
                 FROM daily_cash_sessions
                 WHERE business_date = :business_date
                   AND status = 'open'
                 FOR UPDATE"
            );
            $sessionStatement->execute([
                'business_date' => date('Y-m-d')
            ]);

            $cashSessionId = $sessionStatement->fetchColumn();

            if ($cashSessionId === false) {
                throw new RuntimeException(
                    'There is no open cash session for today. Open the daily cash session before recording a cash receipt.'
                );
            }

            $cashSessionId = (int) $cashSessionId;
        }

        $paymentReference = customerPaymentReference();

        $paymentStatement = $pdo->prepare(
            "INSERT INTO customer_payments
                (payment_reference, sale_id, amount, payment_method,
                 transaction_reference, paid_at, received_by, notes)
             VALUES
                (:payment_reference, :sale_id, :amount, :payment_method,
                 :transaction_reference, :paid_at, :received_by, :notes)"
        );

        $paymentStatement->execute([
            'payment_reference' => $paymentReference,
            'sale_id' => $saleId,
            'amount' => number_format($amount, 2, '.', ''),
            'payment_method' => $paymentMethod,
            'transaction_reference' => $transactionReference !== ''
                ? $transactionReference
                : null,
            'paid_at' => $paidAt,
            'received_by' => $userId,
            'notes' => $notes !== '' ? $notes : null
        ]);

        $paymentId = (int) $pdo->lastInsertId();

        // Only physical cash enters the cashbook's cash-in amount.
        if ($paymentMethod === 'cash') {
            $cashbookStatement = $pdo->prepare(
                "INSERT INTO cashbook_entries
                    (cash_session_id, entry_type, customer_payment_id,
                     amount_in, amount_out, description, recorded_by)
                 VALUES
                    (:cash_session_id, 'customer_receipt', :payment_id,
                     :amount_in, 0.00, :description, :recorded_by)"
            );

            $cashbookStatement->execute([
                'cash_session_id' => $cashSessionId,
                'payment_id' => $paymentId,
                'amount_in' => number_format($amount, 2, '.', ''),
                'description' => 'Customer receipt for invoice ' .
                    $sale['invoice_number'] . ' (' . $paymentReference . ')',
                'recorded_by' => $userId
            ]);
        }

        $newPaidTotal = round($alreadyPaid + $amount, 2);
        $newStatus = ($newPaidTotal >= $billTotal) ? 'paid' : 'partial';

        $statusStatement = $pdo->prepare(
            "UPDATE sales
             SET payment_status = :payment_status
             WHERE id = :sale_id
               AND status = 'posted'"
        );

        $statusStatement->execute([
            'payment_status' => $newStatus,
            'sale_id' => $saleId
        ]);

        if ($statusStatement->rowCount() !== 1) {
            throw new RuntimeException('The bill status could not be updated.');
        }

        $auditDetails = json_encode([
            'payment_reference' => $paymentReference,
            'invoice_number' => $sale['invoice_number'],
            'payment_method' => $paymentMethod,
            'amount' => number_format($amount, 2, '.', ''),
            'previous_paid' => number_format($alreadyPaid, 2, '.', ''),
            'new_paid_total' => number_format($newPaidTotal, 2, '.', ''),
            'outstanding_after_payment' => number_format(
                max(0, $billTotal - $newPaidTotal),
                2,
                '.',
                ''
            ),
            'payment_status' => $newStatus
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $auditStatement = $pdo->prepare(
            "INSERT INTO audit_logs
                (user_id, action, entity_type, entity_id, details, ip_address)
             VALUES
                (:user_id, 'RECORD_CUSTOMER_PAYMENT', 'customer_payment',
                 :entity_id, :details, :ip_address)"
        );

        $auditStatement->execute([
            'user_id' => $userId,
            'entity_id' => $paymentId,
            'details' => $auditDetails,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        $pdo->commit();
        $transactionStarted = false;

        customerPaymentRedirect(
            $paymentReference . ' recorded successfully. Outstanding balance: ₹' .
            number_format(max(0, $billTotal - $newPaidTotal), 2)
        );
    } catch (Throwable $exception) {
        if ($transactionStarted && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // Log technical details on the server; show a safe message to the user.
        error_log('AgroProcure customer payment error: ' . $exception->getMessage());

        if ($exception instanceof RuntimeException) {
            $error = $exception->getMessage();
        } else {
            $error = 'The payment could not be recorded. No changes were saved. Please try again or check the server error log.';
        }
    }
}

if (isset($_GET['success']) && is_string($_GET['success'])) {
    $success = $_GET['success'];
}

// Load bills that can still receive payments.
$billsStatement = $pdo->query(
    "SELECT
        s.id,
        s.invoice_number,
        s.customer_name,
        s.customer_phone,
        s.sold_at,
        s.total_amount,
        s.payment_status,
        COALESCE(p.paid_total, 0) AS paid_total,
        GREATEST(s.total_amount - COALESCE(p.paid_total, 0), 0) AS outstanding
     FROM sales s
     LEFT JOIN (
        SELECT sale_id, SUM(amount) AS paid_total
        FROM customer_payments
        GROUP BY sale_id
     ) p ON p.sale_id = s.id
     WHERE s.status = 'posted'
       AND s.total_amount > COALESCE(p.paid_total, 0)
     ORDER BY s.sold_at DESC, s.id DESC"
);
$openBills = $billsStatement->fetchAll(PDO::FETCH_ASSOC);

// Recent payments, including payments against bills that are now fully paid.
$recentPaymentsStatement = $pdo->query(
    "SELECT
        cp.id,
        cp.payment_reference,
        cp.amount,
        cp.payment_method,
        cp.transaction_reference,
        cp.paid_at,
        cp.notes,
        s.invoice_number,
        s.customer_name,
        u.username AS received_by_name
     FROM customer_payments cp
     INNER JOIN sales s ON s.id = cp.sale_id
     LEFT JOIN users u ON u.id = cp.received_by
     ORDER BY cp.id DESC
     LIMIT 20"
);
$recentPayments = $recentPaymentsStatement->fetchAll(PDO::FETCH_ASSOC);

$currentOpenSessionStatement = $pdo->prepare(
    "SELECT id
     FROM daily_cash_sessions
     WHERE business_date = :business_date
       AND status = 'open'
     LIMIT 1"
);
$currentOpenSessionStatement->execute(['business_date' => date('Y-m-d')]);
$hasOpenCashSession = $currentOpenSessionStatement->fetchColumn() !== false;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Payments | AgroProcure</title>
    <link rel="stylesheet" href="../../public/assets/css/sales.css">
</head>
<body>
<main class="sales-page">
    <header class="sales-header">
        <div>
            <p class="sales-eyebrow">AGROPROCURE / VENDOR</p>
            <h1>Customer Payment Management</h1>
            <p>Record customer receipts and track outstanding invoice balances.</p>
        </div>
        <a class="sales-back-link" href="sales.php">Back to Sales</a>
    </header>

    <?php if ($success !== ''): ?>
        <div class="sales-notice sales-notice-success" role="status">
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="sales-notice sales-notice-error" role="alert">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <section class="sales-card">
        <h2>Record a Customer Payment</h2>
        <p class="sales-description">
            Only posted bills with an outstanding balance are available.
            A payment cannot exceed the remaining balance.
        </p>

        <?php if (!$hasOpenCashSession): ?>
            <p class="sales-notice sales-notice-error">
                No open cash session was found for today.
                Cash payments cannot be recorded until today's session is opened.
                Other payment methods remain available.
            </p>
        <?php endif; ?>

        <?php if (count($openBills) === 0): ?>
            <p>There are no posted bills with outstanding balances.</p>
        <?php else: ?>
            <form method="post" action="customer-payments.php">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrf_token()) ?>"
                >

                <div class="sales-form-grid">
                    <div class="sales-field">
                        <label for="sale_id">Customer Bill *</label>
                        <select id="sale_id" name="sale_id" required>
                            <option value="">Select a bill</option>
                            <?php foreach ($openBills as $bill): ?>
                                <option
                                    value="<?= (int) $bill['id'] ?>"
                                    data-outstanding="<?= e(number_format((float) $bill['outstanding'], 2, '.', '')) ?>"
                                >
                                    <?= e($bill['invoice_number']) ?>
                                    — <?= e($bill['customer_name'] ?: 'Walk-in customer') ?>
                                    — Due ₹<?= e(customerPaymentMoney($bill['outstanding'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="sales-field">
                        <label for="amount">Payment Amount (₹) *</label>
                        <input
                            type="number"
                            id="amount"
                            name="amount"
                            min="0.01"
                            step="0.01"
                            max="99999999999999.99"
                            required
                        >
                        <small id="outstanding_hint">Select a bill to see its outstanding balance.</small>
                    </div>

                    <div class="sales-field">
                        <label for="payment_method">Payment Method *</label>
                        <select id="payment_method" name="payment_method" required>
                            <option value="cash">Cash</option>
                            <option value="upi">UPI</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="cheque">Cheque</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div class="sales-field">
                        <label for="transaction_reference">Transaction Reference</label>
                        <input
                            type="text"
                            id="transaction_reference"
                            name="transaction_reference"
                            maxlength="100"
                            placeholder="UPI ID, bank reference or cheque number"
                        >
                    </div>

                    <div class="sales-field">
                        <label for="paid_at">Payment Date and Time</label>
                        <input
                            type="datetime-local"
                            id="paid_at"
                            name="paid_at"
                            value="<?= e(date('Y-m-d\TH:i')) ?>"
                            required
                        >
                    </div>

                    <div class="sales-field">
                        <label for="notes">Notes</label>
                        <input
                            type="text"
                            id="notes"
                            name="notes"
                            maxlength="5000"
                            placeholder="Optional payment remarks"
                        >
                    </div>
                </div>

                <button type="submit" class="sales-button">
                    Record Payment
                </button>
            </form>
        <?php endif; ?>
    </section>

    <section class="sales-card">
        <h2>Outstanding Customer Bills</h2>
        <div class="sales-table-wrap">
            <table class="sales-table">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Bill Date</th>
                        <th>Bill Amount</th>
                        <th>Paid</th>
                        <th>Outstanding</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$openBills): ?>
                    <tr>
                        <td colspan="7">No outstanding bills found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($openBills as $bill): ?>
                        <tr>
                            <td><?= e($bill['invoice_number']) ?></td>
                            <td>
                                <?= e($bill['customer_name'] ?: 'Walk-in customer') ?>
                                <?php if (!empty($bill['customer_phone'])): ?>
                                    <br><small><?= e($bill['customer_phone']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= e($bill['sold_at']) ?></td>
                            <td>₹<?= e(customerPaymentMoney($bill['total_amount'])) ?></td>
                            <td>₹<?= e(customerPaymentMoney($bill['paid_total'])) ?></td>
                            <td><strong>₹<?= e(customerPaymentMoney($bill['outstanding'])) ?></strong></td>
                            <td><?= e(ucfirst($bill['payment_status'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="sales-card">
        <h2>Recent Customer Payments</h2>
        <div class="sales-table-wrap">
            <table class="sales-table">
                <thead>
                    <tr>
                        <th>Payment Reference</th>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Transaction Reference</th>
                        <th>Received At</th>
                        <th>Recorded By</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$recentPayments): ?>
                    <tr>
                        <td colspan="8">No customer payments recorded yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentPayments as $payment): ?>
                        <tr>
                            <td><?= e($payment['payment_reference']) ?></td>
                            <td><?= e($payment['invoice_number']) ?></td>
                            <td><?= e($payment['customer_name'] ?: 'Walk-in customer') ?></td>
                            <td>₹<?= e(customerPaymentMoney($payment['amount'])) ?></td>
                            <td><?= e(ucwords(str_replace('_', ' ', $payment['payment_method']))) ?></td>
                            <td><?= e($payment['transaction_reference'] ?: '—') ?></td>
                            <td><?= e($payment['paid_at']) ?></td>
                            <td><?= e($payment['received_by_name'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<script src="../../public/assets/js/customer-payments.js" defer></script>
</body>
</html>
