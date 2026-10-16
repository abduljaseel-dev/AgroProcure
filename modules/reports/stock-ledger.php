
<?php
require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_login();

$pageTitle = 'Goods Movement & Stock Ledger';

$errors = [];
$rows = [];

$today = date('Y-m-d');
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? $today;
$search = trim($_GET['search'] ?? '');

function stockLedgerMoney($value): string
{
    return number_format((float) $value, 2);
}

function stockLedgerQty($value): string
{
    return number_format((float) $value, 3);
}

if (
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) ||
    $startDate > $endDate
) {
    $errors[] = 'Please select a valid date range.';
    $startDate = date('Y-m-01');
    $endDate = $today;
}

try {
    /*
     * Reusable joins:
     * Each movement is linked to its stock lot and original
     * receipt item so the original supplier can be identified.
     */
    $joins = "
        FROM stock_movements sm
        LEFT JOIN stock_lots sl
            ON sl.id = sm.stock_lot_id
        LEFT JOIN supply_receipt_items sri
            ON sri.id = COALESCE(
                sm.receipt_item_id,
                sl.receipt_item_id
            )
        LEFT JOIN goods g
            ON g.id = sri.goods_id
        LEFT JOIN supply_receipts sr
            ON sr.id = sri.receipt_id
        LEFT JOIN farmers f
            ON f.id = sr.farmer_id
        LEFT JOIN department_accounts da
            ON da.id = sr.department_id
        LEFT JOIN sale_items si
            ON si.id = sm.sale_item_id
        LEFT JOIN sales s
            ON s.id = si.sale_id
    ";

    $searchSql = '';
    $params = [];

    if ($search !== '') {
        $searchSql = "
            AND (
                g.goods_name LIKE :search_goods
                OR g.goods_code LIKE :search_code
                OR COALESCE(f.full_name, da.department_name, '') 
                    LIKE :search_supplier
                OR COALESCE(sm.reference_number, '') 
                    LIKE :search_reference
            )
        ";

        $term = '%' . $search . '%';

        $params = [
            'search_goods' => $term,
            'search_code' => $term,
            'search_supplier' => $term,
            'search_reference' => $term
        ];
    }

    /*
     * Opening stock:
     * Net movement before the selected start date.
     */
    $openingSql = "
        SELECT COALESCE(
            SUM(COALESCE(sm.quantity_in, 0)
              - COALESCE(sm.quantity_out, 0)),
            0
        )
        $joins
        WHERE sm.created_at < :opening_date
        $searchSql
    ";

    $openingParams = array_merge(
        ['opening_date' => $startDate . ' 00:00:00'],
        $params
    );

    $stmt = $pdo->prepare($openingSql);
    $stmt->execute($openingParams);
    $openingStock = (float) $stmt->fetchColumn();

    /*
     * Selected-period movements.
     */
    $movementSql = "
        SELECT
            sm.id,
            sm.created_at,
            sm.movement_type,
            sm.quantity_in,
            sm.quantity_out,
            sm.reference_number,
            sm.notes,

            g.goods_code,
            g.goods_name,
            g.unit,

            COALESCE(
                f.full_name,
                da.department_name,
                'Supplier not identified'
            ) AS supplier_name,

            CASE
                WHEN sr.supplier_type = 'farmer'
                    THEN 'Farmer'
                WHEN sr.supplier_type = 'department'
                    THEN 'Department'
                ELSE 'Not identified'
            END AS supplier_type,

            s.invoice_number

        $joins
        WHERE sm.created_at >= :period_start
          AND sm.created_at < :period_end
          $searchSql
        ORDER BY sm.created_at ASC, sm.id ASC
    ";

    $movementParams = array_merge(
        [
            'period_start' => $startDate . ' 00:00:00',
            'period_end' => date(
                'Y-m-d H:i:s',
                strtotime($endDate . ' +1 day')
            )
        ],
        $params
    );

    $stmt = $pdo->prepare($movementSql);
    $stmt->execute($movementParams);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
     * Calculate the ledger running balance.
     */
    $runningStock = $openingStock;
    $totalIn = 0.0;
    $totalOut = 0.0;

    foreach ($rows as &$row) {
        $qtyIn = (float) ($row['quantity_in'] ?? 0);
        $qtyOut = (float) ($row['quantity_out'] ?? 0);

        $totalIn += $qtyIn;
        $totalOut += $qtyOut;

        $runningStock += $qtyIn - $qtyOut;

        $row['running_balance'] = $runningStock;
    }
    unset($row);

    $closingStock = $openingStock + $totalIn - $totalOut;

} catch (Throwable $e) {
    error_log('Stock ledger report error: ' . $e->getMessage());
    $errors[] = 'Unable to load the stock ledger. Please check the database schema and try again.';

    $openingStock = 0;
    $totalIn = 0;
    $totalOut = 0;
    $closingStock = 0;
}

function stockLedgerEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function stockLedgerMovementLabel($type): string
{
    $labels = [
        'receipt' => 'Stock Received',
        'sale' => 'Stock Sold',
        'return' => 'Stock Returned',
        'adjustment' => 'Stock Adjustment'
    ];

    return $labels[$type] ?? ucfirst((string) $type);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= stockLedgerEscape($pageTitle) ?> | AgroProcure</title>

    <link rel="stylesheet"
          href="/agroprocure/public/assets/css/reports.css">
</head>
<body>

<div class="report-page stock-ledger-page">

    <header class="report-header">
        <div>
            <p class="report-eyebrow">AGROPROCURE / REPORTS</p>
            <h1><?= stockLedgerEscape($pageTitle) ?></h1>
            <p>Track stock movements, supplier origins and running inventory balances.</p>
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
                <p><?= stockLedgerEscape($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="report-panel">
        <form method="GET"
              action="/agroprocure/modules/reports/stock-ledger.php"
              class="report-filter">

            <div class="filter-field">
                <label for="start_date">From Date</label>
                <input type="date"
                       id="start_date"
                       name="start_date"
                       value="<?= stockLedgerEscape($startDate) ?>"
                       required>
            </div>

            <div class="filter-field">
                <label for="end_date">To Date</label>
                <input type="date"
                       id="end_date"
                       name="end_date"
                       value="<?= stockLedgerEscape($endDate) ?>"
                       required>
            </div>

            <div class="filter-field">
                <label for="search">Search Goods / Supplier / Reference</label>
                <input type="search"
                       id="search"
                       name="search"
                       value="<?= stockLedgerEscape($search) ?>"
                       placeholder="Enter name, code or reference">
            </div>

            <div class="filter-field filter-actions">
                <button type="submit" class="report-button">
                    Generate Report
                </button>
            </div>
        </form>
    </section>

    <section class="report-summary">
        <article class="summary-card">
            <span>Opening Stock</span>
            <strong><?= stockLedgerQty($openingStock) ?></strong>
            <small>Net stock before the selected period</small>
        </article>

        <article class="summary-card">
            <span>Total Stock In</span>
            <strong><?= stockLedgerQty($totalIn) ?></strong>
            <small>All recorded inward movements</small>
        </article>

        <article class="summary-card">
            <span>Total Stock Out</span>
            <strong><?= stockLedgerQty($totalOut) ?></strong>
            <small>All recorded outward movements</small>
        </article>

        <article class="summary-card">
            <span>Closing Stock</span>
            <strong><?= stockLedgerQty($closingStock) ?></strong>
            <small>Opening + inward − outward</small>
        </article>
    </section>

    <section class="report-panel">

        <div class="report-table-heading">
            <div>
                <h2>Stock Movement Register</h2>
                <p>
                    Period:
                    <?= stockLedgerEscape($startDate) ?>
                    to
                    <?= stockLedgerEscape($endDate) ?>
                </p>
            </div>
        </div>

        <div class="report-table-wrapper">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Date &amp; Time</th>
                        <th>Goods</th>
                        <th>Movement</th>
                        <th>Supplier</th>
                        <th>Reference</th>
                        <th>Stock In</th>
                        <th>Stock Out</th>
                        <th>Running Balance</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="8">
                                No stock movements found for the selected filters.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td>
                                    <?= stockLedgerEscape(
                                        date(
                                            'd M Y H:i',
                                            strtotime($row['created_at'])
                                        )
                                    ) ?>
                                </td>

                                <td>
                                    <strong>
                                        <?= stockLedgerEscape(
                                            $row['goods_name'] ?? 'Unknown good'
                                        ) ?>
                                    </strong>
                                    <br>
                                    <small>
                                        <?= stockLedgerEscape(
                                            $row['goods_code'] ?? ''
                                        ) ?>
                                    </small>
                                </td>

                                <td>
                                    <?= stockLedgerEscape(
                                        stockLedgerMovementLabel(
                                            $row['movement_type']
                                        )
                                    ) ?>
                                </td>

                                <td>
                                    <?= stockLedgerEscape($row['supplier_name']) ?>
                                    <br>
                                    <small>
                                        <?= stockLedgerEscape($row['supplier_type']) ?>
                                    </small>
                                </td>

                                <td>
                                    <?= stockLedgerEscape(
                                        $row['reference_number']
                                        ?: ($row['invoice_number'] ?? '—')
                                    ) ?>
                                </td>

                                <td>
                                    <?= stockLedgerQty($row['quantity_in']) ?>
                                    <?= stockLedgerEscape($row['unit'] ?? '') ?>
                                </td>

                                <td>
                                    <?= stockLedgerQty($row['quantity_out']) ?>
                                    <?= stockLedgerEscape($row['unit'] ?? '') ?>
                                </td>

                                <td>
                                    <strong>
                                        <?= stockLedgerQty(
                                            $row['running_balance']
                                        ) ?>
                                    </strong>
                                    <?= stockLedgerEscape($row['unit'] ?? '') ?>
                                </td>
                            </tr>

                            <?php if (!empty($row['notes'])): ?>
                                <tr class="stock-ledger-note-row">
                                    <td colspan="8">
                                        <small>
                                            Note:
                                            <?= stockLedgerEscape($row['notes']) ?>
                                        </small>
                                    </td>
                                </tr>
                            <?php endif; ?>

                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>

                <tfoot>
                    <tr>
                        <th colspan="5">Period Totals</th>
                        <th><?= stockLedgerQty($totalIn) ?></th>
                        <th><?= stockLedgerQty($totalOut) ?></th>
                        <th><?= stockLedgerQty($closingStock) ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>

        <footer class="report-footer">
            <span>AgroProcure Stock Ledger</span>
            <span>Generated <?= date('d M Y, h:i A') ?></span>
        </footer>
    </section>

    <p class="report-note">
        <strong>Important:</strong>
        This report calculates balances from recorded stock movements.
        It does not independently verify physical stock. Supplier details
        are derived from the original receipt associated with the stock lot.
        Review missing references and reconcile the report with the existing
        stock report before relying on the figures.
    </p>

</div>

</body>
</html>
