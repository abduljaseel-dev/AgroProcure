
<?php
require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_login();
require_role(['admin', 'vendor', 'staff']);

date_default_timezone_set('Asia/Kolkata');

$pdo = $pdo ?? $db ?? null;

if (!$pdo instanceof PDO) {
    http_response_code(500);
    exit('Database connection unavailable.');
}

$categories = [
    'Transport',
    'Loading and Unloading',
    'Packaging',
    'Storage',
    'Rent',
    'Electricity',
    'Maintenance',
    'Office Supplies',
    'Wages',
    'Equipment',
    'Telephone and Internet',
    'Other'
];

$methods = [
    'cash' => 'Cash',
    'bank_transfer' => 'Bank Transfer',
    'upi' => 'UPI',
    'cheque' => 'Cheque',
    'other' => 'Other'
];

$error = '';
$success = '';

function expenseMoneyToPaise(string $amount): int
{
    $amount = trim($amount);

    if (!preg_match('/^\d{1,14}(?:\.\d{1,2})?$/', $amount)) {
        throw new RuntimeException(
            'Enter a valid amount with no more than two decimal places.'
        );
    }

    $parts = explode('.', $amount, 2);
    $rupees = (int) $parts[0];
    $paise = isset($parts[1])
        ? (int) str_pad($parts[1], 2, '0')
        : 0;

    if ($rupees > intdiv(PHP_INT_MAX - $paise, 100)) {
        throw new RuntimeException('Amount is too large.');
    }

    return $rupees * 100 + $paise;
}

function expenseAmount($amount): string
{
    return number_format((float) $amount, 2, '.', ',');
}

function expenseGenerateReference(PDO $pdo): string
{
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $reference = 'EXP-' . date('Ymd-His') . '-'
            . strtoupper(bin2hex(random_bytes(3)));

        $stmt = $pdo->prepare(
            'SELECT id FROM business_expenses
             WHERE expense_number = ? LIMIT 1'
        );
        $stmt->execute([$reference]);

        if (!$stmt->fetchColumn()) {
            return $reference;
        }
    }

    throw new RuntimeException(
        'Unable to generate an expense number. Please retry.'
    );
}

/*
|--------------------------------------------------------------------------
| CREATE EXPENSE
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException(
                'Security token expired or invalid. Refresh the page and retry.'
            );
        }

        $category = trim($_POST['category'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $amountInput = trim($_POST['amount'] ?? '');
        $paymentMethod = trim($_POST['payment_method'] ?? '');
        $transactionReference = trim(
            $_POST['transaction_reference'] ?? ''
        );

        if (!in_array($category, $categories, true)) {
            throw new RuntimeException('Select a valid category.');
        }

        if ($description === '' || mb_strlen($description) > 255) {
            throw new RuntimeException(
                'Description is required and must not exceed 255 characters.'
            );
        }

        if (!array_key_exists($paymentMethod, $methods)) {
            throw new RuntimeException('Select a valid payment method.');
        }

        if (mb_strlen($transactionReference) > 100) {
            throw new RuntimeException(
                'Transaction reference cannot exceed 100 characters.'
            );
        }

        $amountPaise = expenseMoneyToPaise($amountInput);

        if ($amountPaise <= 0) {
            throw new RuntimeException(
                'Expense amount must be greater than zero.'
            );
        }

        $amount = number_format($amountPaise / 100, 2, '.', '');

        $userId = $_SESSION['user_id'] ?? null;

        if (
            !is_scalar($userId) ||
            !ctype_digit((string) $userId) ||
            (int) $userId <= 0
        ) {
            throw new RuntimeException(
                'Your login session is invalid. Please log in again.'
            );
        }

        $userId = (int) $userId;

        $pdo->beginTransaction();

        /*
         * Lock today's cash session for cash expenses.
         * This prevents recording a cash expense against a closed day.
         */
        $cashSession = null;

        if ($paymentMethod === 'cash') {
            $stmt = $pdo->prepare(
                "SELECT id
                 FROM daily_cash_sessions
                 WHERE business_date = CURDATE()
                   AND status = 'open'
                 FOR UPDATE"
            );
            $stmt->execute();

            $cashSession = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$cashSession) {
                throw new RuntimeException(
                    'There is no open cashbook session for today. '
                    . 'Open today’s cashbook session before recording a cash expense.'
                );
            }
        }

        $expenseNumber = expenseGenerateReference($pdo);

        $stmt = $pdo->prepare(
            "INSERT INTO business_expenses (
                expense_number,
                expense_date,
                category,
                description,
                amount,
                payment_method,
                transaction_reference,
                status,
                recorded_by
            ) VALUES (?, NOW(), ?, ?, ?, ?, ?, 'posted', ?)"
        );

        $stmt->execute([
            $expenseNumber,
            $category,
            $description,
            $amount,
            $paymentMethod,
            $transactionReference !== ''
                ? $transactionReference
                : null,
            $userId
        ]);

        $expenseId = (int) $pdo->lastInsertId();

        /*
         * Only physical cash expenses affect the cashbook.
         * Bank, UPI and cheque payments do not reduce physical cash.
         */
        if ($paymentMethod === 'cash') {
            $stmt = $pdo->prepare(
                "INSERT INTO cashbook_entries (
                    cash_session_id,
                    entry_type,
                    expense_id,
                    amount_in,
                    amount_out,
                    description,
                    recorded_by
                ) VALUES (?, 'expense', ?, 0.00, ?, ?, ?)"
            );

            $stmt->execute([
                (int) $cashSession['id'],
                $expenseId,
                $amount,
                $category . ': ' . $description
                    . ' (' . $expenseNumber . ')',
                $userId
            ]);
        }

        $details = json_encode([
            'expense_number' => $expenseNumber,
            'category' => $category,
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'cashbook_recorded' => $paymentMethod === 'cash'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt = $pdo->prepare(
            "INSERT INTO audit_logs (
                user_id,
                action,
                entity_type,
                entity_id,
                details,
                ip_address
            ) VALUES (?, 'expense_created', 'business_expenses', ?, ?, ?)"
        );

        $stmt->execute([
            $userId,
            $expenseId,
            $details,
            substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)
        ]);

        $pdo->commit();

        header('Location: expenses.php?success=1');
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('AgroProcure expense error: ' . $e->getMessage());

        if ($e instanceof RuntimeException) {
            $error = $e->getMessage();
        } else {
            $error = 'Expense could not be saved. Check the server error log.';
        }
    }
}

if (isset($_GET['success'])) {
    $success = 'Expense recorded successfully.';
}

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$filterDate = trim($_GET['date'] ?? '');
$filterCategory = trim($_GET['category'] ?? '');
$filterMethod = trim($_GET['method'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$search = trim($_GET['q'] ?? '');

if ($filterDate !== '' &&
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
    $filterDate = '';
}

if (!in_array($filterCategory, $categories, true)) {
    $filterCategory = '';
}

if ($filterMethod !== '' && !array_key_exists($filterMethod, $methods)) {
    $filterMethod = '';
}

if (!in_array($filterStatus, ['', 'posted', 'cancelled'], true)) {
    $filterStatus = '';
}

$where = [];
$params = [];

if ($filterDate !== '') {
    $where[] = 'DATE(expense_date) = ?';
    $params[] = $filterDate;
}

if ($filterCategory !== '') {
    $where[] = 'category = ?';
    $params[] = $filterCategory;
}

if ($filterMethod !== '') {
    $where[] = 'payment_method = ?';
    $params[] = $filterMethod;
}

if ($filterStatus !== '') {
    $where[] = 'status = ?';
    $params[] = $filterStatus;
}

if ($search !== '') {
    $where[] = '(expense_number LIKE ? OR description LIKE ?
                 OR transaction_reference LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term);
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare(
    "SELECT id, expense_number, expense_date, category, description,
            amount, payment_method, transaction_reference, status
     FROM business_expenses
     $whereSql
     ORDER BY expense_date DESC, id DESC
     LIMIT 200"
);
$stmt->execute($params);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| TODAY'S SUMMARY
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query(
    "SELECT
        COALESCE(SUM(CASE
            WHEN status = 'posted' THEN amount ELSE 0
        END), 0) AS total_expenses,
        COALESCE(SUM(CASE
            WHEN status = 'posted' AND payment_method = 'cash'
            THEN amount ELSE 0
        END), 0) AS cash_expenses,
        COUNT(CASE WHEN status = 'posted' THEN 1 END) AS expense_count
     FROM business_expenses
     WHERE DATE(expense_date) = CURDATE()"
);
$today = $stmt->fetch(PDO::FETCH_ASSOC);

function expenseMethodName(string $method): string
{
    return [
        'cash' => 'Cash',
        'bank_transfer' => 'Bank Transfer',
        'upi' => 'UPI',
        'cheque' => 'Cheque',
        'other' => 'Other'
    ][$method] ?? 'Other';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Business Expenses | AgroProcure</title>
<link rel="stylesheet" href="../../public/assets/css/sales.css">

<style>
.expense-page{max-width:1350px;margin:28px auto;padding:0 20px 40px;color:#172b45}
.expense-header{display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap;margin-bottom:24px}
.expense-header h1{font-size:28px;margin:0 0 5px}
.expense-header p{margin:0;color:#64748b}
.expense-link{display:inline-block;padding:10px 15px;background:#123b63;color:#fff;text-decoration:none;border-radius:7px;font-weight:700}
.expense-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:22px}
.expense-stat,.expense-panel{background:#fff;border:1px solid #dce5ee;border-radius:12px;padding:20px;box-shadow:0 4px 15px rgba(20,45,75,.04)}
.expense-stat span{display:block;font-size:13px;color:#64748b;margin-bottom:9px}
.expense-stat strong{font-size:25px}
.expense-panel{margin-bottom:22px}
.expense-panel h2{font-size:19px;margin:0 0 18px}
.expense-form,.expense-filters{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}
.expense-filters{grid-template-columns:repeat(5,minmax(0,1fr));align-items:end}
.expense-field{display:flex;flex-direction:column;gap:7px}
.expense-field label{font-size:13px;font-weight:700}
.expense-field input,.expense-field select,.expense-field textarea{width:100%;box-sizing:border-box;padding:11px 12px;border:1px solid #cbd5e1;border-radius:7px;background:#fff;color:#172b45;font:inherit}
.expense-field textarea{min-height:80px;resize:vertical}
.expense-wide{grid-column:1/-1}
.expense-button{border:0;border-radius:7px;padding:12px 18px;background:#123b63;color:#fff;font-weight:700;cursor:pointer}
.expense-button:hover{background:#0b2946}
.expense-secondary{display:inline-block;padding:11px 14px;background:#edf2f7;color:#233b55;border-radius:7px;text-decoration:none;font-weight:700}
.expense-alert{padding:12px 14px;border-radius:8px;margin-bottom:18px}
.expense-error{background:#fff1f0;color:#a12622;border:1px solid #ffd2cf}
.expense-success{background:#edf9f0;color:#176534;border:1px solid #c9eed2}
.expense-table-wrap{overflow-x:auto}
.expense-table{width:100%;border-collapse:collapse;min-width:900px}
.expense-table th,.expense-table td{text-align:left;padding:12px 10px;border-bottom:1px solid #e6edf4;font-size:13px;vertical-align:top}
.expense-table th{background:#f4f7fb;color:#43566f;font-weight:700}
.expense-table td.amount{text-align:right;white-space:nowrap;font-weight:700}
.expense-tag{display:inline-block;padding:4px 8px;border-radius:20px;background:#eaf1f8;color:#23486c;font-size:12px}
.expense-note{font-size:13px;color:#64748b;margin:12px 0 0}
.expense-empty{text-align:center;color:#64748b;padding:28px!important}
@media(max-width:900px){.expense-filters{grid-template-columns:repeat(2,minmax(0,1fr))}.expense-stats{grid-template-columns:1fr}}
@media(max-width:600px){.expense-page{padding:0 12px 28px}.expense-form,.expense-filters{grid-template-columns:1fr}.expense-wide{grid-column:auto}.expense-header h1{font-size:23px}}
</style>
</head>
<body>
<main class="expense-page">

<header class="expense-header">
    <div>
        <h1>Business Expenses</h1>
        <p>Track business costs and maintain accurate cash accounting.</p>
    </div>
    <a class="expense-link" href="cashbook.php">Open Daily Cashbook</a>
</header>

<?php if ($error !== ''): ?>
    <div class="expense-alert expense-error"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($success !== ''): ?>
    <div class="expense-alert expense-success"><?= e($success) ?></div>
<?php endif; ?>

<section class="expense-stats">
    <div class="expense-stat">
        <span>Today's Posted Expenses</span>
        <strong>₹<?= expenseAmount($today['total_expenses'] ?? 0) ?></strong>
    </div>
    <div class="expense-stat">
        <span>Today's Cash Expenses</span>
        <strong>₹<?= expenseAmount($today['cash_expenses'] ?? 0) ?></strong>
    </div>
    <div class="expense-stat">
        <span>Expenses Recorded Today</span>
        <strong><?= (int)($today['expense_count'] ?? 0) ?></strong>
    </div>
</section>

<section class="expense-panel">
<h2>Record New Expense</h2>

<form method="post" class="expense-form">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

    <div class="expense-field">
        <label for="category">Expense Category *</label>
        <select id="category" name="category" required>
            <option value="">Select category</option>
            <?php foreach ($categories as $category): ?>
                <option value="<?= e($category) ?>"
                    <?= ($_POST['category'] ?? '') === $category ? 'selected' : '' ?>>
                    <?= e($category) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="expense-field">
        <label for="amount">Amount (₹) *</label>
        <input id="amount" name="amount" type="number"
               min="0.01" max="99999999999999.99" step="0.01"
               value="<?= e($_POST['amount'] ?? '') ?>" required>
    </div>

    <div class="expense-field expense-wide">
        <label for="description">Description *</label>
        <textarea id="description" name="description"
                  maxlength="255" required><?= e($_POST['description'] ?? '') ?></textarea>
    </div>

    <div class="expense-field">
        <label for="payment_method">Payment Method *</label>
        <select id="payment_method" name="payment_method" required>
            <?php foreach ($methods as $key => $label): ?>
                <option value="<?= e($key) ?>"
                    <?= ($_POST['payment_method'] ?? 'cash') === $key ? 'selected' : '' ?>>
                    <?= e($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="expense-field">
        <label for="transaction_reference">Transaction Reference</label>
        <input id="transaction_reference" name="transaction_reference"
               maxlength="100"
               value="<?= e($_POST['transaction_reference'] ?? '') ?>"
               placeholder="Optional bank / UPI / cheque reference">
    </div>

    <div class="expense-wide">
        <button type="submit" class="expense-button">Save Expense</button>
        <p class="expense-note">
            Cash expenses require an open cashbook session and reduce expected
            closing cash. Non-cash expenses do not change physical cash.
        </p>
    </div>
</form>
</section>

<section class="expense-panel">
<h2>Expense Register</h2>

<form method="get" class="expense-filters">
    <div class="expense-field">
        <label for="date">Date</label>
        <input type="date" id="date" name="date" value="<?= e($filterDate) ?>">
    </div>

    <div class="expense-field">
        <label for="filter_category">Category</label>
        <select id="filter_category" name="category">
            <option value="">All categories</option>
            <?php foreach ($categories as $category): ?>
                <option value="<?= e($category) ?>"
                    <?= $filterCategory === $category ? 'selected' : '' ?>>
                    <?= e($category) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="expense-field">
        <label for="method">Method</label>
        <select id="method" name="method">
            <option value="">All methods</option>
            <?php foreach ($methods as $key => $label): ?>
                <option value="<?= e($key) ?>"
                    <?= $filterMethod === $key ? 'selected' : '' ?>>
                    <?= e($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="expense-field">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="">All statuses</option>
            <option value="posted" <?= $filterStatus === 'posted' ? 'selected' : '' ?>>Posted</option>
            <option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
        </select>
    </div>

    <div class="expense-field">
        <label for="q">Search</label>
        <input id="q" name="q" value="<?= e($search) ?>"
               maxlength="100" placeholder="Expense no. or description">
    </div>

    <div class="expense-wide">
        <button class="expense-button" type="submit">Apply Filters</button>
        <a class="expense-secondary" href="expenses.php">Clear Filters</a>
    </div>
</form>

<p class="expense-note">Showing up to 200 matching expenses, newest first.</p>

<div class="expense-table-wrap" style="margin-top:16px">
<table class="expense-table">
<thead>
<tr>
    <th>Date</th>
    <th>Expense Number</th>
    <th>Category / Description</th>
    <th>Payment Method</th>
    <th>Reference</th>
    <th>Status</th>
    <th style="text-align:right">Amount (₹)</th>
</tr>
</thead>
<tbody>
<?php if (!$expenses): ?>
    <tr><td colspan="7" class="expense-empty">No matching expenses found.</td></tr>
<?php else: ?>
    <?php foreach ($expenses as $expense): ?>
        <tr>
            <td><?= e(date('d M Y, h:i A', strtotime($expense['expense_date']))) ?></td>
            <td><?= e($expense['expense_number']) ?></td>
            <td>
                <strong><?= e($expense['category']) ?></strong><br>
                <?= e($expense['description']) ?>
            </td>
            <td><span class="expense-tag"><?= e(expenseMethodName($expense['payment_method'])) ?></span></td>
            <td><?= e($expense['transaction_reference'] ?? '—') ?></td>
            <td><?= e(ucfirst($expense['status'])) ?></td>
            <td class="amount"><?= expenseAmount($expense['amount']) ?></td>
        </tr>
    <?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>
</section>
</main>
</body>
</html>
