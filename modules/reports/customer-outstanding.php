
<?php
require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_login();

$pageTitle = 'Customer Outstanding Report';

$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$paymentStatus = trim($_GET['payment_status'] ?? '');
$search = trim($_GET['search'] ?? '');

$errors = [];
$rows = [];

$summary = [
    'invoices' => 0,
    'invoice_total' => 0,
    'received' => 0,
    'outstanding' => 0
];

function corMoney($amount)
{
    return '₹' . number_format((float) $amount, 2);
}

function corDateValid($date)
{
    if ($date === '') {
        return true;
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

if (!corDateValid($dateFrom)) {
    $errors[] = 'Please enter a valid start date.';
}

if (!corDateValid($dateTo)) {
    $errors[] = 'Please enter a valid end date.';
}

if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    $errors[] = 'The start date cannot be later than the end date.';
}

if (!in_array($paymentStatus, ['', 'unpaid', 'partial', 'paid'], true)) {
    $errors[] = 'Please select a valid payment status.';
}

try {
    if (!$errors) {
        $where = ["s.status = 'posted'"];
        $params = [];

        if ($dateFrom !== '') {
            $where[] = 's.sold_at >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== '') {
            $where[] = 's.sold_at < :date_to';
            $params['date_to'] = date(
                'Y-m-d H:i:s',
                strtotime($dateTo . ' +1 day')
            );
        }

        if ($search !== '') {
            $where[] = '(
                s.invoice_number LIKE :invoice_search
                OR s.customer_name LIKE :customer_search
                OR s.customer_phone LIKE :phone_search
            )';

            $term = '%' . $search . '%';
            $params['invoice_search'] = $term;
            $params['customer_search'] = $term;
            $params['phone_search'] = $term;
        }

        $whereSql = implode(' AND ', $where);

        $sql = "
            SELECT
                s.id,
                s.invoice_number,
                s.customer_name,
                s.customer_phone,
                s.sold_at,
                s.total_amount,
                s.payment_status,
                COALESCE(p.amount_received, 0) AS amount_received,
                COALESCE(p.last_payment_at, NULL) AS last_payment_at,
                COALESCE(p.payment_count, 0) AS payment_count

            FROM sales s

            LEFT JOIN (
                SELECT
                    sale_id,
                    SUM(amount) AS amount_received,
                    MAX(paid_at) AS last_payment_at,
                    COUNT(*) AS payment_count
                FROM customer_payments
                GROUP BY sale_id
            ) p ON p.sale_id = s.id

            WHERE $whereSql

            ORDER BY s.sold_at DESC, s.id DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $allRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($allRows as $row) {
            $invoiceTotal = (float) $row['total_amount'];
            $received = (float) $row['amount_received'];

            // Prevent negative balances from being presented as money owed.
            $balance = max(0, $invoiceTotal - $received);

            // Derive status from the actual payment total.
            if ($received <= 0) {
                $calculatedStatus = 'unpaid';
            } elseif ($received < $invoiceTotal) {
                $calculatedStatus = 'partial';
            } else {
                $calculatedStatus = 'paid';
            }

            if (
                $paymentStatus !== ''
                && $calculatedStatus !== $paymentStatus
            ) {
                continue;
            }

            $row['calculated_balance'] = $balance;
            $row['calculated_status'] = $calculatedStatus;

            $rows[] = $row;

            $summary['invoices']++;
            $summary['invoice_total'] += $invoiceTotal;
            $summary['received'] += $received;
            $summary['outstanding'] += $balance;
        }
    }
} catch (Throwable $ex) {
    error_log('AgroProcure customer outstanding report error: ' . $ex->getMessage());
    $errors[] = 'Unable to load the customer report. Check the database and try again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> | AgroProcure</title>
    <link rel="stylesheet"
          href="/agroprocure/public/assets/css/reports.css">
</head>
<body>
<div class="report-page">

    <header class="report-header">
        <div>
            <p class="report-eyebrow">AGROPROCURE / REPORTS</p>
            <h1><?= e($pageTitle) ?></h1>
            <p class="report-subtitle">
                Track invoice totals, payments received and customer balances.
            </p>
        </div>

        <a class="report-back"
           href="/agroprocure/public/dashboard.php">
            ← Dashboard
        </a>
    </header>

    <section class="report-notice">
        <strong>Accounting basis:</strong>
        Balances are calculated from posted invoice totals minus recorded
        customer payments. Payment status is derived from the transaction
        totals for reporting; this page does not change stored records.
    </section>

    <?php if ($errors): ?>
        <div class="report-error">
            <strong>Please check:</strong>
            <ul>
                <?php foreach ($errors as $message): ?>
                    <li><?= e($message) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section class="report-summary">
        <article class="summary-card">
            <span>Invoices Listed</span>
            <strong><?= number_format($summary['invoices']) ?></strong>
        </article>

        <article class="summary-card">
            <span>Total Invoice Value</span>
            <strong><?= corMoney($summary['invoice_total']) ?></strong>
        </article>

        <article class="summary-card">
            <span>Payments Received</span>
            <strong><?= corMoney($summary['received']) ?></strong>
        </article>

        <article class="summary-card summary-highlight">
            <span>Total Outstanding</span>
            <strong><?= corMoney($summary['outstanding']) ?></strong>
        </article>
    </section>

    <section class="report-panel">
        <form method="GET" class="report-filter">

            <div class="filter-field">
                <label for="date_from">Invoice date from</label>
                <input type="date" id="date_from" name="date_from"
                       value="<?= e($dateFrom) ?>">
            </div>

            <div class="filter-field">
                <label for="date_to">Invoice date to</label>
                <input type="date" id="date_to" name="date_to"
                       value="<?= e($dateTo) ?>">
            </div>

            <div class="filter-field">
                <label for="payment_status">Payment status</label>
                <select id="payment_status" name="payment_status">
                    <option value=""
                        <?= $paymentStatus === '' ? 'selected' : '' ?>>
                        All statuses
                    </option>
                    <option value="unpaid"
                        <?= $paymentStatus === 'unpaid' ? 'selected' : '' ?>>
                        Unpaid
                    </option>
                    <option value="partial"
                        <?= $paymentStatus === 'partial' ? 'selected' : '' ?>>
                        Partially paid
                    </option>
                    <option value="paid"
                        <?= $paymentStatus === 'paid' ? 'selected' : '' ?>>
                        Paid
                    </option>
                </select>
            </div>

            <div class="filter-field">
                <label for="search">Customer or invoice</label>
                <input type="search" id="search" name="search"
                       value="<?= e($search) ?>"
                       placeholder="Name, phone or invoice">
            </div>

            <button type="submit" class="report-button">
                Apply Filters
            </button>

            <a href="customer-outstanding.php" class="report-reset">
                Reset
            </a>
        </form>

        <div class="report-table-heading">
            <div>
                <h2>Invoice Balances</h2>
                <p>Only posted invoices are shown.</p>
            </div>

            <button type="button" class="report-button"
                    onclick="window.print()">
                Print Report
            </button>
        </div>

        <div class="report-table-wrap">
            <table class="report-table">
                <thead>
                <tr>
                    <th>Invoice</th>
                    <th>Invoice Date</th>
                    <th>Customer</th>
                    <th>Phone</th>
                    <th>Invoice Total</th>
                    <th>Payments Received</th>
                    <th>Outstanding</th>
                    <th>Last Payment</th>
                    <th>Payment Entries</th>
                    <th>Status</th>
                </tr>
                </thead>

                <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="10" class="report-empty">
                            No invoices found for the selected filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e($row['invoice_number']) ?></td>

                        <td>
                            <?= e(date(
                                'd M Y',
                                strtotime($row['sold_at'])
                            )) ?>
                        </td>

                        <td class="goods-name">
                            <?= e($row['customer_name']) ?>
                        </td>

                        <td><?= e($row['customer_phone'] ?? '') ?></td>

                        <td><?= corMoney($row['total_amount']) ?></td>

                        <td><?= corMoney($row['amount_received']) ?></td>

                        <td class="stock-value">
                            <?= corMoney($row['calculated_balance']) ?>
                        </td>

                        <td>
                            <?= !empty($row['last_payment_at'])
                                ? e(date(
                                    'd M Y H:i',
                                    strtotime($row['last_payment_at'])
                                ))
                                : '—' ?>
                        </td>

                        <td><?= (int) $row['payment_count'] ?></td>

                        <td>
                            <span class="payment-status status-<?= e($row['calculated_status']) ?>">
                                <?= e(ucfirst($row['calculated_status'])) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>

                <?php if ($rows): ?>
                <tfoot>
                <tr>
                    <th colspan="4">TOTAL</th>
                    <th><?= corMoney($summary['invoice_total']) ?></th>
                    <th><?= corMoney($summary['received']) ?></th>
                    <th><?= corMoney($summary['outstanding']) ?></th>
                    <th colspan="3">—</th>
                </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </section>

    <footer class="report-footer">
        AgroProcure · Agricultural Procurement &amp; Payment Management System
    </footer>

</div>
</body>
</html>
