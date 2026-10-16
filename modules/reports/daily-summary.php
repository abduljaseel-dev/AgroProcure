
<?php
require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_login();

$pageTitle = 'Daily Sales, Cash & Expense Summary';

$reportDate = trim($_GET['date'] ?? date('Y-m-d'));
$errors = [];

$salesCount = 0;
$salesTotal = 0.0;
$customerCollections = 0.0;
$customerPaymentCount = 0;
$expenseTotal = 0.0;
$cashExpenses = 0.0;
$nonCashExpenses = 0.0;
$expenseCount = 0;

$cashSession = null;
$cashbookIn = 0.0;
$cashbookOut = 0.0;
$cashbookEntries = 0;
$expectedClosing = null;
$closingDifference = null;

function dailySummaryDateIsValid($date)
{
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);

    return $parsed && $parsed->format('Y-m-d') === $date;
}

function dailySummaryMoney($amount)
{
    return number_format((float) $amount, 2);
}

if (!dailySummaryDateIsValid($reportDate)) {
    $errors[] = 'Please select a valid report date.';
}

if (!$errors) {
    try {
        $startDateTime = $reportDate . ' 00:00:00';
        $endDateTime = date(
            'Y-m-d',
            strtotime($reportDate . ' +1 day')
        ) . ' 00:00:00';

        /*
         * 1. Posted sales for the selected date.
         * Sales are reported separately from customer collections.
         */
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS invoice_count,
                COALESCE(SUM(total_amount), 0) AS sales_total
            FROM sales
            WHERE status = 'posted'
              AND sold_at >= :start_time
              AND sold_at < :end_time
        ");

        $stmt->execute([
            ':start_time' => $startDateTime,
            ':end_time' => $endDateTime
        ]);

        $sales = $stmt->fetch(PDO::FETCH_ASSOC);

        $salesCount = (int) $sales['invoice_count'];
        $salesTotal = (float) $sales['sales_total'];

        /*
         * 2. Customer collections received on the selected date.
         */
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS payment_count,
                COALESCE(SUM(amount), 0) AS collected_total
            FROM customer_payments
            WHERE paid_at >= :start_time
              AND paid_at < :end_time
        ");

        $stmt->execute([
            ':start_time' => $startDateTime,
            ':end_time' => $endDateTime
        ]);

        $collections = $stmt->fetch(PDO::FETCH_ASSOC);

        $customerPaymentCount = (int) $collections['payment_count'];
        $customerCollections = (float) $collections['collected_total'];

        /*
         * 3. Posted expenses for the selected date.
         */
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS expense_count,
                COALESCE(SUM(amount), 0) AS expense_total,
                COALESCE(
                    SUM(
                        CASE
                            WHEN payment_method = 'cash'
                            THEN amount
                            ELSE 0
                        END
                    ),
                    0
                ) AS cash_expenses
            FROM business_expenses
            WHERE status = 'posted'
              AND expense_date >= :start_time
              AND expense_date < :end_time
        ");

        $stmt->execute([
            ':start_time' => $startDateTime,
            ':end_time' => $endDateTime
        ]);

        $expenses = $stmt->fetch(PDO::FETCH_ASSOC);

        $expenseCount = (int) $expenses['expense_count'];
        $expenseTotal = (float) $expenses['expense_total'];
        $cashExpenses = (float) $expenses['cash_expenses'];
        $nonCashExpenses = $expenseTotal - $cashExpenses;

        /*
         * 4. Find the cash session for this business date.
         */
        $stmt = $pdo->prepare("
            SELECT
                id,
                business_date,
                opening_cash,
                counted_closing_cash,
                status,
                opened_at,
                closed_at
            FROM daily_cash_sessions
            WHERE business_date = :business_date
            ORDER BY id DESC
            LIMIT 1
        ");

        $stmt->execute([
            ':business_date' => $reportDate
        ]);

        $cashSession = $stmt->fetch(PDO::FETCH_ASSOC);

        /*
         * 5. Cashbook movements for this specific session.
         * These entries, rather than sales totals, determine expected cash.
         */
        if ($cashSession) {
            $stmt = $pdo->prepare("
                SELECT
                    COUNT(*) AS entry_count,
                    COALESCE(SUM(amount_in), 0) AS total_in,
                    COALESCE(SUM(amount_out), 0) AS total_out
                FROM cashbook_entries
                WHERE cash_session_id = :session_id
            ");

            $stmt->execute([
                ':session_id' => $cashSession['id']
            ]);

            $cashbook = $stmt->fetch(PDO::FETCH_ASSOC);

            $cashbookEntries = (int) $cashbook['entry_count'];
            $cashbookIn = (float) $cashbook['total_in'];
            $cashbookOut = (float) $cashbook['total_out'];

            $expectedClosing =
                (float) $cashSession['opening_cash']
                + $cashbookIn
                - $cashbookOut;

            if ($cashSession['status'] === 'closed') {
                $closingDifference =
                    (float) $cashSession['counted_closing_cash']
                    - $expectedClosing;
            }
        }
    } catch (PDOException $ex) {
        error_log('Daily summary report error: ' . $ex->getMessage());
        $errors[] = 'Unable to generate the daily summary. Please check the database configuration.';
    }
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

<div class="report-page daily-summary-page">

    <header class="report-header">
        <div>
            <p class="report-eyebrow">AGROPROCURE / REPORTS</p>
            <h1><?= e($pageTitle) ?></h1>
            <p>Daily business performance and cash reconciliation.</p>
        </div>

        <div class="report-header-actions">
            <button type="button"
                    class="report-button"
                    onclick="window.print()">
                Print Report
            </button>
        </div>
    </header>

    <?php if ($errors): ?>
        <div class="report-error" role="alert">
            <?php foreach ($errors as $error): ?>
                <p><?= e($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="report-panel">
        <form method="GET"
              action="/agroprocure/modules/reports/daily-summary.php"
              class="report-filter">

            <div class="filter-field">
                <label for="date">Business Date</label>
                <input type="date"
                       id="date"
                       name="date"
                       value="<?= e($reportDate) ?>"
                       required>
            </div>

            <div class="filter-field filter-actions">
                <button type="submit" class="report-button">
                    Generate Report
                </button>
            </div>
        </form>
    </section>

    <?php if ($cashSession): ?>
        <section class="report-summary">
            <article class="summary-card">
                <span>Opening Cash</span>
                <strong>
                    ₹<?= dailySummaryMoney($cashSession['opening_cash']) ?>
                </strong>
                <small>Cash session opening balance</small>
            </article>

            <article class="summary-card">
                <span>Expected Closing Cash</span>
                <strong>
                    <?php if ($expectedClosing !== null): ?>
                        ₹<?= dailySummaryMoney($expectedClosing) ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </strong>
                <small>Opening cash + cashbook inflow − outflow</small>
            </article>

            <article class="summary-card">
                <span>Counted Closing Cash</span>
                <strong>
                    <?php if ($cashSession['status'] === 'closed'): ?>
                        ₹<?= dailySummaryMoney(
                            $cashSession['counted_closing_cash']
                        ) ?>
                    <?php else: ?>
                        Not closed
                    <?php endif; ?>
                </strong>
                <small>
                    Session status:
                    <?= e(ucfirst($cashSession['status'])) ?>
                </small>
            </article>

            <article class="summary-card">
                <span>Cash Difference</span>
                <strong>
                    <?php if ($closingDifference !== null): ?>
                        ₹<?= dailySummaryMoney($closingDifference) ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </strong>
                <small>Counted closing cash − expected closing cash</small>
            </article>
        </section>
    <?php else: ?>
        <div class="report-error">
            No cash session was found for <?= e($reportDate) ?>.
            Sales, collections, and expenses can still be reviewed below,
            but cash reconciliation is unavailable.
        </div>
    <?php endif; ?>

    <section class="report-summary">
        <article class="summary-card">
            <span>Posted Sales</span>
            <strong>₹<?= dailySummaryMoney($salesTotal) ?></strong>
            <small><?= number_format($salesCount) ?> posted invoice(s)</small>
        </article>

        <article class="summary-card">
            <span>Customer Collections</span>
            <strong>₹<?= dailySummaryMoney($customerCollections) ?></strong>
            <small>
                <?= number_format($customerPaymentCount) ?> payment(s) received
            </small>
        </article>

        <article class="summary-card">
            <span>Total Expenses</span>
            <strong>₹<?= dailySummaryMoney($expenseTotal) ?></strong>
            <small><?= number_format($expenseCount) ?> posted expense(s)</small>
        </article>

        <article class="summary-card">
            <span>Cash Expenses</span>
            <strong>₹<?= dailySummaryMoney($cashExpenses) ?></strong>
            <small>Non-cash expenses: ₹<?= dailySummaryMoney($nonCashExpenses) ?></small>
        </article>
    </section>

    <section class="report-panel">
        <div class="report-table-heading">
            <div>
                <h2>Daily Financial Breakdown</h2>
                <p>Business date: <?= e($reportDate) ?></p>
            </div>
        </div>

        <div class="report-table-wrapper">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Financial Activity</th>
                        <th>Amount</th>
                        <th>How to Interpret</th>
                    </tr>
                </thead>

                <tbody>
                    <tr>
                        <td><strong>Posted sales</strong></td>
                        <td>₹<?= dailySummaryMoney($salesTotal) ?></td>
                        <td>Value of posted invoices dated today</td>
                    </tr>

                    <tr>
                        <td><strong>Customer collections</strong></td>
                        <td>₹<?= dailySummaryMoney($customerCollections) ?></td>
                        <td>Payments received today, including payments against earlier invoices</td>
                    </tr>

                    <tr>
                        <td><strong>Posted expenses</strong></td>
                        <td>₹<?= dailySummaryMoney($expenseTotal) ?></td>
                        <td>Expenses recorded for the selected date</td>
                    </tr>

                    <tr>
                        <td><strong>Cashbook inflow</strong></td>
                        <td>₹<?= dailySummaryMoney($cashbookIn) ?></td>
                        <td>All inflow entries linked to this cash session</td>
                    </tr>

                    <tr>
                        <td><strong>Cashbook outflow</strong></td>
                        <td>₹<?= dailySummaryMoney($cashbookOut) ?></td>
                        <td>All outflow entries linked to this cash session</td>
                    </tr>

                    <tr>
                        <td><strong>Expected closing cash</strong></td>
                        <td>
                            <?= $expectedClosing !== null
                                ? '₹' . dailySummaryMoney($expectedClosing)
                                : '—' ?>
                        </td>
                        <td>Opening balance plus recorded inflows minus recorded outflows</td>
                    </tr>

                    <tr>
                        <td><strong>Cashbook entries</strong></td>
                        <td><?= number_format($cashbookEntries) ?></td>
                        <td>Number of entries linked to the selected session</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <footer class="report-footer">
            <span>AgroProcure daily summary</span>
            <span>Generated <?= e(date('d M Y, h:i A')) ?></span>
        </footer>
    </section>

    <p class="report-note">
        <strong>Important:</strong>
        Sales are not the same as cash collections. Expected closing cash
        is calculated from cash-session entries, not directly from sales
        or expense totals. A positive difference means counted cash is
        higher than expected; a negative difference means counted cash is
        lower than expected. Reconcile the cashbook entries before acting
        on the difference.
    </p>

</div>

</body>
</html>
