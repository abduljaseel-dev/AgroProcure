
<?php
require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_login();

$pageTitle = 'Vendor Cash & Profit Report';

$today = date('Y-m-d');
$startDate = $_GET['start_date'] ?? $today;
$endDate = $_GET['end_date'] ?? $today;

$errors = [];
$salesRevenue = 0.0;
$costOfGoodsSold = 0.0;
$grossProfit = 0.0;
$profitMargin = 0.0;
$customerCollections = 0.0;
$supplierPayments = 0.0;
$totalExpenses = 0.0;
$cashExpenses = 0.0;
$cashbookIn = 0.0;
$cashbookOut = 0.0;
$cashSessionFound = false;
$supplierResults = [];
$salesCount = 0;
$expenseCount = 0;
$customerPaymentCount = 0;
$supplierPaymentCount = 0;

function vendorProfitEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function vendorProfitMoney($value): string
{
    return number_format((float) $value, 2);
}

function vendorProfitValidDate($value): bool
{
    if (
        !is_string($value) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
    ) {
        return false;
    }

    return checkdate(
        (int) substr($value, 5, 2),
        (int) substr($value, 8, 2),
        (int) substr($value, 0, 4)
    );
}

/* Validate selected dates */
if (
    !vendorProfitValidDate($startDate) ||
    !vendorProfitValidDate($endDate) ||
    $startDate > $endDate
) {
    $errors[] = 'Please select a valid date range.';
    $startDate = $today;
    $endDate = $today;
}

try {
    $periodStart = $startDate . ' 00:00:00';
    $periodEnd = date(
        'Y-m-d H:i:s',
        strtotime($endDate . ' +1 day')
    );

    /*
     * 1. POSTED SALES
     */
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS invoice_count,
            COALESCE(SUM(total_amount), 0) AS revenue
        FROM sales
        WHERE status = 'posted'
          AND sold_at >= :start_date
          AND sold_at < :end_date
    ");

    $stmt->execute([
        'start_date' => $periodStart,
        'end_date' => $periodEnd
    ]);

    $salesData = $stmt->fetch(PDO::FETCH_ASSOC);

    $salesCount = (int) $salesData['invoice_count'];
    $salesRevenue = (float) $salesData['revenue'];

    /*
     * 2. COST OF GOODS SOLD
     *
     * Purchase cost comes from the original receipt item
     * associated with each sale item's stock lot.
     *
     * Only posted invoices are included.
     */
    $stmt = $pdo->prepare("
        SELECT COALESCE(
            SUM(si.quantity * sri.unit_cost),
            0
        ) AS total_cost
        FROM sales s
        INNER JOIN sale_items si
            ON si.sale_id = s.id
        INNER JOIN stock_lots sl
            ON sl.id = si.stock_lot_id
        INNER JOIN supply_receipt_items sri
            ON sri.id = sl.receipt_item_id
        WHERE s.status = 'posted'
          AND s.sold_at >= :start_date
          AND s.sold_at < :end_date
    ");

    $stmt->execute([
        'start_date' => $periodStart,
        'end_date' => $periodEnd
    ]);

    $costOfGoodsSold = (float) $stmt->fetchColumn();

    $grossProfit = $salesRevenue - $costOfGoodsSold;

    if ($salesRevenue > 0) {
        $profitMargin = ($grossProfit / $salesRevenue) * 100;
    }

    /*
     * 3. CUSTOMER COLLECTIONS
     */
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS payment_count,
            COALESCE(SUM(amount), 0) AS collection_total
        FROM customer_payments
        WHERE paid_at >= :start_date
          AND paid_at < :end_date
    ");

    $stmt->execute([
        'start_date' => $periodStart,
        'end_date' => $periodEnd
    ]);

    $collectionData = $stmt->fetch(PDO::FETCH_ASSOC);

    $customerPaymentCount = (int) $collectionData['payment_count'];
    $customerCollections = (float) $collectionData['collection_total'];

    /*
     * 4. SUPPLIER PAYMENTS
     */
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS payment_count,
            COALESCE(SUM(amount), 0) AS payment_total
        FROM supplier_payments
        WHERE paid_at >= :start_date
          AND paid_at < :end_date
    ");

    $stmt->execute([
        'start_date' => $periodStart,
        'end_date' => $periodEnd
    ]);

    $supplierPaymentData = $stmt->fetch(PDO::FETCH_ASSOC);

    $supplierPaymentCount = (int) $supplierPaymentData['payment_count'];
    $supplierPayments = (float) $supplierPaymentData['payment_total'];

    /*
     * 5. POSTED BUSINESS EXPENSES
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
            ) AS cash_total
        FROM business_expenses
        WHERE status = 'posted'
          AND expense_date >= :start_date
          AND expense_date < :end_date
    ");

    $stmt->execute([
        'start_date' => $periodStart,
        'end_date' => $periodEnd
    ]);

    $expenseData = $stmt->fetch(PDO::FETCH_ASSOC);

    $expenseCount = (int) $expenseData['expense_count'];
    $totalExpenses = (float) $expenseData['expense_total'];
    $cashExpenses = (float) $expenseData['cash_total'];

    /*
     * 6. CASH SESSION AND CASHBOOK
     */
    $stmt = $pdo->prepare("
        SELECT
            id,
            opening_cash,
            counted_closing_cash,
            status
        FROM daily_cash_sessions
        WHERE business_date = :business_date
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->execute(['business_date' => $endDate]);
    $cashSession = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($cashSession) {
        $cashSessionFound = true;

        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(amount_in), 0) AS total_in,
                COALESCE(SUM(amount_out), 0) AS total_out
            FROM cashbook_entries
            WHERE cash_session_id = :session_id
        ");

        $stmt->execute(['session_id' => $cashSession['id']]);

        $cashbookData = $stmt->fetch(PDO::FETCH_ASSOC);

        $cashbookIn = (float) $cashbookData['total_in'];
        $cashbookOut = (float) $cashbookData['total_out'];
    }

    /*
     * 7. SUPPLIER-WISE PROFITABILITY
     *
     * Attribute each sale item to the supplier who supplied
     * its stock lot. Allocate invoice discounts proportionally
     * to line value when calculating net sales revenue.
     */
    $stmt = $pdo->prepare("
        SELECT
            sr.supplier_type,
            sr.farmer_id,
            sr.department_id,

            CASE
                WHEN sr.supplier_type = 'farmer'
                    THEN COALESCE(f.full_name, 'Farmer record missing')
                WHEN sr.supplier_type = 'department'
                    THEN COALESCE(
                        da.department_name,
                        'Department record missing'
                    )
                ELSE 'Supplier not identified'
            END AS supplier_name,

            CASE
                WHEN sr.supplier_type = 'farmer'
                    THEN COALESCE(f.farmer_code, '')
                WHEN sr.supplier_type = 'department'
                    THEN COALESCE(da.department_code, '')
                ELSE ''
            END AS supplier_code,

            COALESCE(
                SUM(
                    si.line_total
                    - CASE
                        WHEN COALESCE(s.subtotal, 0) > 0
                        THEN s.discount_amount
                             * si.line_total / s.subtotal
                        ELSE 0
                      END
                ),
                0
            ) AS net_sales,

            COALESCE(
                SUM(si.quantity * sri.unit_cost),
                0
            ) AS purchase_cost,

            COALESCE(
                SUM(
                    si.line_total
                    - CASE
                        WHEN COALESCE(s.subtotal, 0) > 0
                        THEN s.discount_amount
                             * si.line_total / s.subtotal
                        ELSE 0
                      END
                    - (si.quantity * sri.unit_cost)
                ),
                0
            ) AS gross_profit

        FROM sales s

        INNER JOIN sale_items si
            ON si.sale_id = s.id

        INNER JOIN stock_lots sl
            ON sl.id = si.stock_lot_id

        INNER JOIN supply_receipt_items sri
            ON sri.id = sl.receipt_item_id

        INNER JOIN supply_receipts sr
            ON sr.id = sri.receipt_id

        LEFT JOIN farmers f
            ON f.id = sr.farmer_id

        LEFT JOIN department_accounts da
            ON da.id = sr.department_id

        WHERE s.status = 'posted'
          AND s.sold_at >= :start_date
          AND s.sold_at < :end_date

        GROUP BY
            sr.supplier_type,
            sr.farmer_id,
            sr.department_id,
            f.full_name,
            f.farmer_code,
            da.department_name,
            da.department_code

        ORDER BY gross_profit DESC, supplier_name ASC
    ");

    $stmt->execute([
        'start_date' => $periodStart,
        'end_date' => $periodEnd
    ]);

    $supplierResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log('Vendor cash and profit report error: ' . $e->getMessage());

    $errors[] = 'Unable to generate the report. Please check the database schema and try again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= vendorProfitEscape($pageTitle) ?> | AgroProcure</title>

    <link rel="stylesheet"
          href="/agroprocure/public/assets/css/reports.css">

    <style>
        .vendor-cash-profit-page .profit-positive {
            color: #166534;
            font-weight: 700;
        }

        .vendor-cash-profit-page .profit-negative {
            color: #b91c1c;
            font-weight: 700;
        }

        .vendor-cash-profit-page .financial-note {
            margin: 0 0 18px;
            padding: 14px 16px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            color: #1e3a5f;
            line-height: 1.6;
        }

        .vendor-cash-profit-page .metric-label {
            display: inline-block;
            margin-top: 5px;
            color: #64748b;
            font-size: 12px;
        }

        .vendor-cash-profit-page .supplier-type {
            display: inline-block;
            padding: 4px 8px;
            background: #eef3f8;
            border-radius: 5px;
            font-size: 12px;
            font-weight: 600;
        }

        .vendor-cash-profit-page .report-table tfoot th {
            background: #edf3f8;
            color: #173b5e;
            font-weight: 700;
        }

        @media print {
            .vendor-cash-profit-page .report-filter,
            .vendor-cash-profit-page .report-header-actions {
                display: none !important;
            }
        }
    </style>
</head>
<body>

<div class="report-page vendor-cash-profit-page">

    <header class="report-header">
        <div>
            <p class="report-eyebrow">AGROPROCURE / REPORTS</p>
            <h1><?= vendorProfitEscape($pageTitle) ?></h1>
            <p>Business profitability, collections, supplier payments and cashbook reconciliation.</p>
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
                <p><?= vendorProfitEscape($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="report-panel">
        <form method="GET"
              action="/agroprocure/modules/reports/vendor-cash-profit.php"
              class="report-filter">

            <div class="filter-field">
                <label for="start_date">From Date</label>
                <input type="date"
                       id="start_date"
                       name="start_date"
                       value="<?= vendorProfitEscape($startDate) ?>"
                       required>
            </div>

            <div class="filter-field">
                <label for="end_date">To Date</label>
                <input type="date"
                       id="end_date"
                       name="end_date"
                       value="<?= vendorProfitEscape($endDate) ?>"
                       required>
            </div>

            <div class="filter-field filter-actions">
                <button type="submit" class="report-button">
                    Generate Report
                </button>
            </div>
        </form>
    </section>

    <div class="financial-note">
        <strong>Accounting distinction:</strong>
        Gross profit is sales revenue minus the purchase cost of goods sold.
        Customer collections and supplier payments are cash-flow figures;
        they are not measures of profit.
    </div>

    <section class="report-summary">
        <article class="summary-card">
            <span>Posted Sales Revenue</span>
            <strong>₹<?= vendorProfitMoney($salesRevenue) ?></strong>
            <small><?= number_format($salesCount) ?> posted invoice(s)</small>
        </article>

        <article class="summary-card">
            <span>Cost of Goods Sold</span>
            <strong>₹<?= vendorProfitMoney($costOfGoodsSold) ?></strong>
            <small>Cost of stock sold during the period</small>
        </article>

        <article class="summary-card">
            <span>Gross Profit</span>
            <strong class="<?= $grossProfit >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                ₹<?= vendorProfitMoney($grossProfit) ?>
            </strong>
            <small>Revenue less cost of goods sold</small>
        </article>

        <article class="summary-card">
            <span>Gross Profit Margin</span>
            <strong><?= number_format($profitMargin, 2) ?>%</strong>
            <small>Gross profit ÷ sales revenue</small>
        </article>
    </section>

    <section class="report-summary">
        <article class="summary-card">
            <span>Customer Collections</span>
            <strong>₹<?= vendorProfitMoney($customerCollections) ?></strong>
            <small><?= number_format($customerPaymentCount) ?> payment(s) recorded</small>
        </article>

        <article class="summary-card">
            <span>Supplier Payments</span>
            <strong>₹<?= vendorProfitMoney($supplierPayments) ?></strong>
            <small><?= number_format($supplierPaymentCount) ?> payment(s) recorded</small>
        </article>

        <article class="summary-card">
            <span>Posted Expenses</span>
            <strong>₹<?= vendorProfitMoney($totalExpenses) ?></strong>
            <small><?= number_format($expenseCount) ?> expense(s)</small>
        </article>

        <article class="summary-card">
            <span>Cash Expenses</span>
            <strong>₹<?= vendorProfitMoney($cashExpenses) ?></strong>
            <small>Expenses marked as cash</small>
        </article>
    </section>

    <section class="report-panel">
        <div class="report-table-heading">
            <div>
                <h2>Cashbook Summary</h2>
                <p>
                    Cash session for <?= vendorProfitEscape($endDate) ?>
                </p>
            </div>
        </div>

        <?php if ($cashSessionFound): ?>
            <div class="report-table-wrapper">
                <table class="report-table">
                    <tbody>
                        <tr>
                            <td><strong>Opening Cash</strong></td>
                            <td>₹<?= vendorProfitMoney($cashSession['opening_cash']) ?></td>
                        </tr>
                        <tr>
                            <td><strong>Cashbook Inflow</strong></td>
                            <td>₹<?= vendorProfitMoney($cashbookIn) ?></td>
                        </tr>
                        <tr>
                            <td><strong>Cashbook Outflow</strong></td>
                            <td>₹<?= vendorProfitMoney($cashbookOut) ?></td>
                        </tr>
                        <tr>
                            <td><strong>Expected Closing Cash</strong></td>
                            <td>
                                ₹<?= vendorProfitMoney(
                                    (float) $cashSession['opening_cash']
                                    + $cashbookIn
                                    - $cashbookOut
                                ) ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Session Status</strong></td>
                            <td>
                                <?= vendorProfitEscape(
                                    ucfirst($cashSession['status'])
                                ) ?>
                            </td>
                        </tr>

                        <?php if ($cashSession['status'] === 'closed'): ?>
                            <tr>
                                <td><strong>Counted Closing Cash</strong></td>
                                <td>
                                    ₹<?= vendorProfitMoney(
                                        $cashSession['counted_closing_cash']
                                    ) ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Cash Difference</strong></td>
                                <td>
                                    ₹<?= vendorProfitMoney(
                                        (float) $cashSession['counted_closing_cash']
                                        - (
                                            (float) $cashSession['opening_cash']
                                            + $cashbookIn
                                            - $cashbookOut
                                        )
                                    ) ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p>
                No cash session was found for <?= vendorProfitEscape($endDate) ?>.
                Cashbook reconciliation is unavailable for this date.
            </p>
        <?php endif; ?>
    </section>

    <section class="report-panel">
        <div class="report-table-heading">
            <div>
                <h2>Supplier-Wise Sales &amp; Gross Profit</h2>
                <p>
                    <?= vendorProfitEscape($startDate) ?>
                    to
                    <?= vendorProfitEscape($endDate) ?>
                </p>
            </div>
        </div>

        <div class="report-table-wrapper">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Supplier</th>
                        <th>Type</th>
                        <th>Net Sales Value</th>
                        <th>Cost of Goods Sold</th>
                        <th>Gross Profit</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$supplierResults): ?>
                        <tr>
                            <td colspan="5">
                                No supplier-attributed sales found for this period.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($supplierResults as $supplier): ?>
                            <tr>
                                <td>
                                    <strong>
                                        <?= vendorProfitEscape(
                                            $supplier['supplier_name']
                                        ) ?>
                                    </strong>

                                    <?php if ($supplier['supplier_code'] !== ''): ?>
                                        <br>
                                        <small>
                                            <?= vendorProfitEscape(
                                                $supplier['supplier_code']
                                            ) ?>
                                        </small>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="supplier-type">
                                        <?= $supplier['supplier_type'] === 'farmer'
                                            ? 'Farmer'
                                            : ($supplier['supplier_type'] === 'department'
                                                ? 'Department'
                                                : 'Unknown') ?>
                                    </span>
                                </td>

                                <td>
                                    ₹<?= vendorProfitMoney($supplier['net_sales']) ?>
                                </td>

                                <td>
                                    ₹<?= vendorProfitMoney($supplier['purchase_cost']) ?>
                                </td>

                                <td class="<?= (float) $supplier['gross_profit'] >= 0
                                    ? 'profit-positive'
                                    : 'profit-negative' ?>">
                                    ₹<?= vendorProfitMoney($supplier['gross_profit']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <p class="report-note">
        <strong>Important:</strong>
        This report does not calculate net profit after all business costs.
        The supplier-wise table allocates invoice discounts proportionally
        to sale-item line values. It relies on sale items being correctly
        linked to stock lots and original receipt costs. Returns, reversals,
        and exceptional accounting adjustments must be reconciled separately
        according to your application's accounting rules.
    </p>

    <footer class="report-footer">
        <span>AgroProcure Vendor Cash &amp; Profit Report</span>
        <span>Generated <?= date('d M Y, h:i A') ?></span>
    </footer>

</div>

</body>
</html>
