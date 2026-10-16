<?php
// AgroProcure - Department Supplier Payments

require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_login();
require_role(['admin', 'vendor', 'staff']);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$errors = [];
$successMessage = '';

function departmentPaymentMoneyToPaise(string $amount): int
{
    $amount = trim($amount);

    if (!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $amount)) {
        throw new InvalidArgumentException('Invalid monetary amount.');
    }

    $parts = explode('.', $amount, 2);
    $rupees = (int) $parts[0];
    $paise = isset($parts[1])
        ? (int) str_pad($parts[1], 2, '0')
        : 0;

    return ($rupees * 100) + $paise;
}

function departmentPaymentFormatMoney($amount): string
{
    return '₹' . number_format((float) $amount, 2);
}

function departmentPaymentReference(): string
{
    return 'DPM-' . date('Ymd') . '-' .
        strtoupper(bin2hex(random_bytes(5)));
}

function departmentPaymentOutstanding(PDO $pdo, int $departmentId, bool $lockRows = false): int
{
    $sql = "
        SELECT COALESCE(SUM(amount_due), 0) AS total_due,
               COALESCE(SUM(amount_paid), 0) AS total_paid
        FROM supplier_ledger
        WHERE supplier_type = 'department'
          AND department_id = ?
    ";

    if ($lockRows) {
        $sql .= " FOR UPDATE";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$departmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $due = departmentPaymentMoneyToPaise(number_format((float) $row['total_due'], 2, '.', ''));
    $paid = departmentPaymentMoneyToPaise(number_format((float) $row['total_paid'], 2, '.', ''));

    return $due - $paid;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Your session token is invalid. Refresh the page and try again.');
        }

        $departmentId = filter_var(
            $_POST['department_id'] ?? '',
            FILTER_VALIDATE_INT
        );

        $amountInput = trim((string) ($_POST['amount'] ?? ''));
        $paymentMethod = (string) ($_POST['payment_method'] ?? '');
        $transactionReference = trim((string) ($_POST['transaction_reference'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if (!$departmentId || $departmentId < 1) {
            throw new RuntimeException('Please select a valid department.');
        }

        $amountPaise = departmentPaymentMoneyToPaise($amountInput);

        if ($amountPaise <= 0) {
            throw new RuntimeException('Payment amount must be greater than zero.');
        }

        $allowedMethods = ['cash', 'bank_transfer', 'upi', 'cheque', 'other'];

        if (!in_array($paymentMethod, $allowedMethods, true)) {
            throw new RuntimeException('Please select a valid payment method.');
        }

        if (strlen($transactionReference) > 100) {
            throw new RuntimeException('Transaction reference cannot exceed 100 characters.');
        }

        if (strlen($notes) > 1000) {
            throw new RuntimeException('Notes cannot exceed 1000 characters.');
        }

        $userId = filter_var(
            $_SESSION['user_id'] ?? '',
            FILTER_VALIDATE_INT
        );

        if (!$userId || $userId < 1) {
            throw new RuntimeException('Your login session is invalid. Please log in again.');
        }

        $amount = number_format($amountPaise / 100, 2, '.', '');
        $pdo->beginTransaction();

        // Lock the department account to serialize payment processing.
        $stmt = $pdo->prepare("
            SELECT id, department_code, department_name
            FROM department_accounts
            WHERE id = ?
              AND is_active = 1
            FOR UPDATE
        ");
        $stmt->execute([$departmentId]);
        $department = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$department) {
            throw new RuntimeException('The selected department does not exist or is inactive.');
        }

        // Lock existing department ledger rows before checking its balance.
        $stmt = $pdo->prepare("
            SELECT id
            FROM supplier_ledger
            WHERE supplier_type = 'department'
              AND department_id = ?
            FOR UPDATE
        ");
        $stmt->execute([$departmentId]);
        $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Recalculate the outstanding amount inside the transaction.
        $outstandingPaise = departmentPaymentOutstanding(
            $pdo,
            (int) $departmentId
        );

        if ($outstandingPaise <= 0) {
            throw new RuntimeException('This department has no outstanding balance.');
        }

        if ($amountPaise > $outstandingPaise) {
            throw new RuntimeException(
                'Payment exceeds the outstanding balance of ' .
                departmentPaymentFormatMoney($outstandingPaise / 100) . '.'
            );
        }

        $cashSessionId = null;

        if ($paymentMethod === 'cash') {
            $businessDate = date('Y-m-d');

            $stmt = $pdo->prepare("
                SELECT id
                FROM daily_cash_sessions
                WHERE business_date = ?
                  AND status = 'open'
                FOR UPDATE
            ");
            $stmt->execute([$businessDate]);
            $cashSessionId = $stmt->fetchColumn();

            if (!$cashSessionId) {
                throw new RuntimeException(
                    'No open cash session exists for today. Open the daily cash session before recording a cash payment.'
                );
            }
        }

        $paymentReference = departmentPaymentReference();

        // Record the department payment.
        $stmt = $pdo->prepare("
            INSERT INTO supplier_payments (
                payment_reference,
                supplier_type,
                farmer_id,
                department_id,
                amount,
                payment_method,
                transaction_reference,
                recorded_by,
                notes
            ) VALUES (
                ?, 'department', NULL, ?, ?, ?, ?, ?, ?
            )
        ");

        $stmt->execute([
            $paymentReference,
            $departmentId,
            $amount,
            $paymentMethod,
            $transactionReference !== '' ? $transactionReference : null,
            $userId,
            $notes !== '' ? $notes : null
        ]);

        $paymentId = (int) $pdo->lastInsertId();

        // Record the payment against the department's supplier ledger.
        $stmt = $pdo->prepare("
            INSERT INTO supplier_ledger (
                supplier_type,
                farmer_id,
                department_id,
                entry_type,
                supply_receipt_id,
                supplier_payment_id,
                amount_due,
                amount_paid,
                description,
                recorded_by
            ) VALUES (
                'department',
                NULL,
                ?,
                'payment',
                NULL,
                ?,
                0.00,
                ?,
                ?,
                ?
            )
        ");

        $description = 'Payment to department ' .
            $department['department_code'] .
            ' - ' . $paymentReference;

        $stmt->execute([
            $departmentId,
            $paymentId,
            $amount,
            $description,
            $userId
        ]);

        // Cash payments reduce physical cash and must appear in the cashbook.
        if ($paymentMethod === 'cash') {
            $stmt = $pdo->prepare("
                INSERT INTO cashbook_entries (
                    cash_session_id,
                    entry_type,
                    customer_payment_id,
                    supplier_payment_id,
                    expense_id,
                    amount_in,
                    amount_out,
                    description,
                    recorded_by
                ) VALUES (
                    ?,
                    'supplier_payment',
                    NULL,
                    ?,
                    NULL,
                    0.00,
                    ?,
                    ?,
                    ?
                )
            ");

            $stmt->execute([
                $cashSessionId,
                $paymentId,
                $amount,
                $description,
                $userId
            ]);
        }

        // Audit trail.
        $auditDetails = json_encode([
            'payment_reference' => $paymentReference,
            'supplier_type' => 'department',
            'department_id' => (int) $departmentId,
            'department_code' => $department['department_code'],
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'transaction_reference' => $transactionReference
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (
                user_id,
                action,
                entity_type,
                entity_id,
                details,
                ip_address
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $userId,
            'RECORD_DEPARTMENT_PAYMENT',
            'supplier_payment',
            $paymentId,
            $auditDetails,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        $pdo->commit();

        header('Location: payments.php?success=' . rawurlencode($paymentReference));
        exit;

    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($e instanceof InvalidArgumentException) {
            $errors[] = $e->getMessage();
        } elseif ($e instanceof RuntimeException) {
            $errors[] = $e->getMessage();
        } else {
            error_log('AgroProcure department payment error: ' . $e->getMessage());
            $errors[] = 'The payment could not be recorded. No partial transaction was committed. Check the application error log if the problem continues.';
        }
    }
}

if (isset($_GET['success']) && is_string($_GET['success'])) {
    $successMessage = 'Payment recorded successfully. Reference: ' .
        preg_replace('/[^A-Z0-9\-]/', '', $_GET['success']);
}

// Active departments and their outstanding balances.
$departmentsStmt = $pdo->query("
    SELECT
        d.id,
        d.department_code,
        d.department_name,
        COALESCE(SUM(sl.amount_due), 0) -
        COALESCE(SUM(sl.amount_paid), 0) AS outstanding
    FROM department_accounts d
    LEFT JOIN supplier_ledger sl
        ON sl.department_id = d.id
       AND sl.supplier_type = 'department'
    WHERE d.is_active = 1
    GROUP BY d.id, d.department_code, d.department_name
    ORDER BY d.department_name
");

$departments = $departmentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Recent department payments.
$paymentsStmt = $pdo->query("
    SELECT
        sp.id,
        sp.payment_reference,
        sp.amount,
        sp.payment_method,
        sp.transaction_reference,
        sp.paid_at,
        sp.notes,
        d.department_code,
        d.department_name,
        u.username AS recorded_by_username
    FROM supplier_payments sp
    INNER JOIN department_accounts d
        ON d.id = sp.department_id
    LEFT JOIN users u
        ON u.id = sp.recorded_by
    WHERE sp.supplier_type = 'department'
      AND sp.farmer_id IS NULL
    ORDER BY sp.id DESC
    LIMIT 50
");

$recentPayments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);

$totalOutstandingPaise = 0;

foreach ($departments as $departmentRow) {
    $totalOutstandingPaise += departmentPaymentMoneyToPaise(
        number_format((float) $departmentRow['outstanding'], 2, '.', '')
    );
}

function departmentPaymentCsrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' .
        e(csrf_token()) . '">';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Payments | AgroProcure</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #f3f6fa;
            color: #1d2939;
        }
        .page { max-width: 1400px; margin: auto; padding: 28px; }
        .topbar {
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 14px; margin-bottom: 24px;
        }
        h1 { margin: 0 0 8px; font-size: 28px; color: #142b4a; }
        h2 { margin: 0 0 18px; font-size: 19px; color: #142b4a; }
        .subtitle { color: #667085; margin: 0; }
        .nav-links { display: flex; flex-wrap: wrap; gap: 10px; }
        .nav-links a, .btn {
            display: inline-block; text-decoration: none; border: 0;
            border-radius: 7px; padding: 10px 14px; font-weight: 700;
            cursor: pointer; font-size: 13px;
        }
        .nav-links a { background: #e5edf6; color: #17375e; }
        .btn-primary { background: #174a78; color: #fff; }
        .panel {
            background: #fff; border: 1px solid #e1e7ef; border-radius: 12px;
            padding: 22px; margin-bottom: 22px;
            box-shadow: 0 3px 12px rgba(16, 24, 40, .04);
        }
        .summary {
            background: #17375e; color: #fff; border-radius: 12px;
            padding: 22px; margin-bottom: 22px;
        }
        .summary small { color: #d4e1f0; display: block; margin-bottom: 8px; }
        .summary strong { font-size: 28px; }
        .form-grid {
            display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }
        .field { display: flex; flex-direction: column; gap: 7px; }
        .field.full { grid-column: 1 / -1; }
        label { font-weight: 700; font-size: 13px; color: #344054; }
        input, select, textarea {
            width: 100%; padding: 11px 12px; border: 1px solid #cfd8e3;
            border-radius: 7px; background: #fff; color: #1d2939;
            font: inherit; min-width: 0;
        }
        textarea { min-height: 85px; resize: vertical; }
        .hint { font-size: 12px; color: #667085; }
        .alert {
            padding: 13px 15px; border-radius: 8px; margin-bottom: 16px;
            font-size: 14px;
        }
        .alert-success { background: #e7f7ed; color: #17663a; }
        .alert-error { background: #fff0ef; color: #a12622; }
        .table-wrap { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 850px; }
        th, td {
            padding: 12px 13px; border-bottom: 1px solid #e9edf2;
            text-align: left; font-size: 13px; vertical-align: top;
        }
        th {
            background: #f7f9fc; color: #475467; font-size: 12px;
            text-transform: uppercase; letter-spacing: .3px;
        }
        .amount { font-weight: 700; white-space: nowrap; }
        .muted { color: #667085; }
        .pill {
            display: inline-block; padding: 4px 8px; border-radius: 20px;
            background: #eaf1f8; color: #174a78; font-size: 11px;
            font-weight: 700; text-transform: uppercase;
        }
        @media (max-width: 650px) {
            .page { padding: 15px; }
            .form-grid { grid-template-columns: 1fr; }
            .field.full { grid-column: auto; }
            h1 { font-size: 23px; }
            .panel { padding: 16px; }
        }
    </style>
</head>
<body>
<main class="page">
    <header class="topbar">
        <div>
            <h1>Department Payments</h1>
            <p class="subtitle">Record payments and track outstanding department balances.</p>
        </div>
        <nav class="nav-links" aria-label="Department navigation">
            <a href="index.php">Departments</a>
            <a href="supply.php">Record Supply</a>
            <a href="receipts.php">Supply Receipts</a>
        </nav>
    </header>

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success"><?= e($successMessage) ?></div>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <section class="summary">
        <small>Total outstanding across active departments</small>
        <strong><?= e(departmentPaymentFormatMoney($totalOutstandingPaise / 100)) ?></strong>
    </section>

    <section class="panel">
        <h2>Record Department Payment</h2>

        <?php if (!$departments): ?>
            <p class="muted">No active departments are available. Create or activate a department first.</p>
        <?php else: ?>
            <form method="post" action="payments.php">
                <?= departmentPaymentCsrfField() ?>

                <div class="form-grid">
                    <div class="field full">
                        <label for="department_id">Department *</label>
                        <select name="department_id" id="department_id" required>
                            <option value="">Select department</option>
                            <?php foreach ($departments as $department): ?>
                                <option
                                    value="<?= (int) $department['id'] ?>"
                                    data-outstanding="<?= e(number_format((float) $department['outstanding'], 2, '.', '')) ?>"
                                    <?= (string) ($_POST['department_id'] ?? '') === (string) $department['id'] ? 'selected' : '' ?>
                                >
                                    <?= e($department['department_code'] . ' - ' . $department['department_name']) ?>
                                    (Outstanding: <?= e(departmentPaymentFormatMoney($department['outstanding'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="hint" id="balanceHint">Select a department to view its outstanding balance.</span>
                    </div>

                    <div class="field">
                        <label for="amount">Payment Amount (₹) *</label>
                        <input
                            type="number"
                            id="amount"
                            name="amount"
                            min="0.01"
                            max="999999999999.99"
                            step="0.01"
                            inputmode="decimal"
                            required
                            value="<?= e((string) ($_POST['amount'] ?? '')) ?>"
                        >
                    </div>

                    <div class="field">
                        <label for="payment_method">Payment Method *</label>
                        <select id="payment_method" name="payment_method" required>
                            <option value="">Select method</option>
                            <?php
                            $methods = [
                                'cash' => 'Cash',
                                'bank_transfer' => 'Bank Transfer',
                                'upi' => 'UPI',
                                'cheque' => 'Cheque',
                                'other' => 'Other'
                            ];
                            foreach ($methods as $value => $label):
                            ?>
                                <option value="<?= e($value) ?>"
                                    <?= ($_POST['payment_method'] ?? '') === $value ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="transaction_reference">Transaction / Bank Reference</label>
                        <input
                            type="text"
                            id="transaction_reference"
                            name="transaction_reference"
                            maxlength="100"
                            value="<?= e((string) ($_POST['transaction_reference'] ?? '')) ?>"
                            placeholder="UTR, cheque number, etc."
                        >
                    </div>

                    <div class="field">
                        <label for="notes">Notes</label>
                        <textarea id="notes" name="notes" maxlength="1000"
                            placeholder="Optional payment details"><?= e((string) ($_POST['notes'] ?? '')) ?></textarea>
                    </div>

                    <div class="field full">
                        <button type="submit" class="btn btn-primary">Record Payment</button>
                        <p class="hint">Verify the department and amount before submitting. A successful payment is recorded in the supplier ledger. Cash payments also reduce the cashbook balance.</p>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Department Outstanding Balances</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Department Code</th>
                    <th>Department Name</th>
                    <th>Outstanding Balance</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$departments): ?>
                    <tr><td colspan="3">No active departments found.</td></tr>
                <?php else: ?>
                    <?php foreach ($departments as $department): ?>
                        <tr>
                            <td><?= e($department['department_code']) ?></td>
                            <td><?= e($department['department_name']) ?></td>
                            <td class="amount"><?= e(departmentPaymentFormatMoney($department['outstanding'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <h2>Recent Department Payments</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Reference</th>
                    <th>Date</th>
                    <th>Department</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Transaction Reference</th>
                    <th>Recorded By</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$recentPayments): ?>
                    <tr><td colspan="7">No department payments recorded yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($recentPayments as $payment): ?>
                        <tr>
                            <td><?= e($payment['payment_reference']) ?></td>
                            <td><?= e($payment['paid_at']) ?></td>
                            <td>
                                <?= e($payment['department_code']) ?><br>
                                <span class="muted"><?= e($payment['department_name']) ?></span>
                            </td>
                            <td class="amount"><?= e(departmentPaymentFormatMoney($payment['amount'])) ?></td>
                            <td><span class="pill"><?= e(str_replace('_', ' ', $payment['payment_method'])) ?></span></td>
                            <td><?= e($payment['transaction_reference'] ?: '—') ?></td>
                            <td><?= e($payment['recorded_by_username'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<script nonce="<?= e($cspNonce ?? '') ?>">
(function () {
    const departmentSelect = document.getElementById('department_id');
    const balanceHint = document.getElementById('balanceHint');

    function updateBalanceHint() {
        const selected = departmentSelect.options[departmentSelect.selectedIndex];

        if (!selected || !selected.value) {
            balanceHint.textContent = 'Select a department to view its outstanding balance.';
            return;
        }

        const outstanding = Number(selected.dataset.outstanding || 0);
        balanceHint.textContent =
            'Current outstanding balance: ₹' +
            outstanding.toLocaleString('en-IN', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
    }

    departmentSelect.addEventListener('change', updateBalanceHint);
    updateBalanceHint();
})();
</script>
</body>
</html>
