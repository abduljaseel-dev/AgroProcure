
<?php
require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_login();

$pageTitle = 'Goods Price History & Rate Change Report';

$today = date('Y-m-d');
$firstDay = date('Y-m-01');

$dateFrom = trim($_GET['from'] ?? $firstDay);
$dateTo = trim($_GET['to'] ?? $today);
$supplierType = trim($_GET['supplier_type'] ?? 'all');
$goodsSearch = trim($_GET['goods'] ?? '');

$errors = [];
$rows = [];
$totalRecords = 0;
$rateIncreases = 0;
$rateDecreases = 0;
$unchangedRates = 0;

function priceReportDateIsValid($date)
{
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);

    return $parsed && $parsed->format('Y-m-d') === $date;
}

function priceReportMoney($amount)
{
    return number_format((float) $amount, 2);
}

if (!priceReportDateIsValid($dateFrom)) {
    $errors[] = 'Please select a valid start date.';
}

if (!priceReportDateIsValid($dateTo)) {
    $errors[] = 'Please select a valid end date.';
}

if (
    priceReportDateIsValid($dateFrom) &&
    priceReportDateIsValid($dateTo) &&
    $dateFrom > $dateTo
) {
    $errors[] = 'The start date cannot be after the end date.';
}

if (!in_array($supplierType, ['all', 'farmer', 'department'], true)) {
    $supplierType = 'all';
}

if (!$errors) {
    try {
        /*
         * Fetch the complete posted purchase-rate history up to the
         * selected end date. Do not filter by start date here because
         * earlier purchases are needed to calculate the previous rate.
         */
        $dateToExclusive = date(
            'Y-m-d',
            strtotime($dateTo . ' +1 day')
        ) . ' 00:00:00';

        $sql = "
            SELECT
                sr.id AS receipt_id,
                sr.receipt_number,
                sr.supplier_type,
                sr.farmer_id,
                sr.department_id,
                sr.received_at,

                sri.id AS receipt_item_id,
                sri.goods_id,
                sri.quantity,
                sri.unit_cost,
                sri.line_total,

                g.goods_code,
                g.goods_name,
                g.unit,

                f.farmer_code,
                f.full_name AS farmer_name,

                da.department_code,
                da.department_name

            FROM supply_receipts sr

            INNER JOIN supply_receipt_items sri
                ON sri.receipt_id = sr.id

            INNER JOIN goods g
                ON g.id = sri.goods_id

            LEFT JOIN farmers f
                ON f.id = sr.farmer_id

            LEFT JOIN department_accounts da
                ON da.id = sr.department_id

            WHERE sr.status = 'posted'
              AND sr.received_at < :date_to_exclusive

            ORDER BY
                sri.goods_id ASC,
                sr.received_at ASC,
                sr.id ASC,
                sri.id ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':date_to_exclusive' => $dateToExclusive
        ]);

        $previousRates = [];
        $historyRows = [];

        while ($record = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $goodsId = (int) $record['goods_id'];
            $currentRate = (float) $record['unit_cost'];

            /*
             * Previous rate is the rate from the preceding posted
             * receipt for this same good.
             */
            $previousRate = $previousRates[$goodsId] ?? null;

            $record['previous_rate'] = $previousRate;

            if ($previousRate === null) {
                $record['rate_difference'] = null;
                $record['percentage_change'] = null;
                $record['rate_direction'] = 'first';
            } else {
                $difference = $currentRate - $previousRate;

                $record['rate_difference'] = $difference;

                $record['percentage_change'] =
                    ((float) $previousRate != 0.0)
                    ? ($difference / (float) $previousRate) * 100
                    : null;

                if ($difference > 0) {
                    $record['rate_direction'] = 'increase';
                } elseif ($difference < 0) {
                    $record['rate_direction'] = 'decrease';
                } else {
                    $record['rate_direction'] = 'same';
                }
            }

            // Update history before applying display filters.
            $previousRates[$goodsId] = $currentRate;

            $receivedDate = substr($record['received_at'], 0, 10);

            if ($receivedDate < $dateFrom || $receivedDate > $dateTo) {
                continue;
            }

            if (
                $supplierType !== 'all' &&
                $record['supplier_type'] !== $supplierType
            ) {
                continue;
            }

            if ($goodsSearch !== '') {
                $searchText = strtolower(
                    ($record['goods_name'] ?? '') . ' ' .
                    ($record['goods_code'] ?? '')
                );

                if (strpos($searchText, strtolower($goodsSearch)) === false) {
                    continue;
                }
            }

            if ($record['supplier_type'] === 'farmer') {
                $record['supplier_name'] =
                    $record['farmer_name'] ?: (
                        $record['farmer_code']
                            ? $record['farmer_code']
                            : 'Farmer #' . $record['farmer_id']
                    );
            } else {
                $record['supplier_name'] =
                    $record['department_name'] ?: (
                        $record['department_code']
                            ? $record['department_code']
                            : 'Department #' . $record['department_id']
                    );
            }

            $historyRows[] = $record;
        }

        // Show the most recent receipts first.
        usort($historyRows, function ($a, $b) {
            $dateCompare = strcmp(
                $b['received_at'],
                $a['received_at']
            );

            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return (int) $b['receipt_item_id']
                <=> (int) $a['receipt_item_id'];
        });

        $rows = $historyRows;
        $totalRecords = count($rows);

        foreach ($rows as $record) {
            if ($record['rate_direction'] === 'increase') {
                $rateIncreases++;
            } elseif ($record['rate_direction'] === 'decrease') {
                $rateDecreases++;
            } elseif ($record['rate_direction'] === 'same') {
                $unchangedRates++;
            }
        }
    } catch (PDOException $ex) {
        error_log('Price history report error: ' . $ex->getMessage());
        $errors[] = 'Unable to load the price history report. Please check the database configuration.';
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

<div class="report-page">

    <header class="report-header">
        <div>
            <p class="report-eyebrow">AGROPROCURE / REPORTS</p>
            <h1><?= e($pageTitle) ?></h1>
            <p>
                Track historical purchase rates and identify changes
                in the cost of each good.
            </p>
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
<br>
    <section class="report-summary">
        <article class="summary-card">
            <span>Total Records</span>
            <strong><?= number_format($totalRecords) ?></strong>
            <small>Posted purchase entries</small>
        </article>

        <article class="summary-card">
            <span>Rate Increases</span>
            <strong><?= number_format($rateIncreases) ?></strong>
            <small>Compared with previous rate</small>
        </article>

        <article class="summary-card">
            <span>Rate Decreases</span>
            <strong><?= number_format($rateDecreases) ?></strong>
            <small>Compared with previous rate</small>
        </article>

        <article class="summary-card">
            <span>Unchanged Rates</span>
            <strong><?= number_format($unchangedRates) ?></strong>
            <small>Same as previous rate</small>
        </article>
    </section>

    <section class="report-panel">
        <form method="GET"
              action="/agroprocure/modules/reports/price-history.php"
              class="report-filter">

            <div class="filter-field">
                <label for="from">From Date</label>
                <input type="date"
                       id="from"
                       name="from"
                       value="<?= e($dateFrom) ?>"
                       required>
            </div>

            <div class="filter-field">
                <label for="to">To Date</label>
                <input type="date"
                       id="to"
                       name="to"
                       value="<?= e($dateTo) ?>"
                       required>
            </div>

            <div class="filter-field">
                <label for="goods">Search Goods</label>
                <input type="text"
                       id="goods"
                       name="goods"
                       placeholder="Goods name or code"
                       value="<?= e($goodsSearch) ?>"
                       maxlength="120">
            </div>

            <div class="filter-field">
                <label for="supplier_type">Supplier Type</label>
                <select id="supplier_type" name="supplier_type">
                    <option value="all"
                        <?= $supplierType === 'all' ? 'selected' : '' ?>>
                        All Suppliers
                    </option>

                    <option value="farmer"
                        <?= $supplierType === 'farmer' ? 'selected' : '' ?>>
                        Farmers
                    </option>

                    <option value="department"
                        <?= $supplierType === 'department' ? 'selected' : '' ?>>
                        Agriculture Department
                    </option>
                </select>
            </div>

            <div class="filter-field filter-actions">
                <button type="submit" class="report-button">
                    Apply Filters
                </button>

                <a href="/agroprocure/modules/reports/price-history.php"
                   class="report-reset">
                    Reset
                </a>
            </div>
        </form>
    </section>

    <section class="report-panel">
        <div class="report-table-heading">
            <div>
                <h2>Purchase Rate History</h2>
                <p>
                    Period:
                    <?= e($dateFrom) ?> to <?= e($dateTo) ?>
                </p>
            </div>
        </div>

        <div class="report-table-wrapper">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Receipt Date</th>
                        <th>Receipt Number</th>
                        <th>Supplier</th>
                        <th>Goods</th>
                        <th>Quantity</th>
                        <th>Previous Rate</th>
                        <th>Current Rate</th>
                        <th>Rate Difference</th>
                        <th>Change %</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$errors && !$rows): ?>
                        <tr>
                            <td colspan="10" class="report-empty">
                                No posted purchase records found for
                                the selected filters.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $index => $record): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>

                                <td>
                                    <?= e(date(
                                        'd M Y',
                                        strtotime($record['received_at'])
                                    )) ?>
                                </td>

                                <td>
                                    <?= e($record['receipt_number']) ?>
                                </td>

                                <td>
                                    <strong>
                                        <?= e($record['supplier_name']) ?>
                                    </strong>
                                    <small class="report-subtext">
                                        <?= e(ucfirst(
                                            $record['supplier_type']
                                        )) ?>
                                    </small>
                                </td>

                                <td>
                                    <strong>
                                        <?= e($record['goods_name']) ?>
                                    </strong>
                                    <small class="report-subtext">
                                        <?= e($record['goods_code']) ?>
                                    </small>
                                </td>

                                <td>
                                    <?= number_format(
                                        (float) $record['quantity'],
                                        3
                                    ) ?>
                                    <?= e($record['unit']) ?>
                                </td>

                                <td>
                                    <?php if ($record['previous_rate'] === null): ?>
                                        <span class="rate-first">
                                            First purchase
                                        </span>
                                    <?php else: ?>
                                        ₹<?= priceReportMoney(
                                            $record['previous_rate']
                                        ) ?>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <strong>
                                        ₹<?= priceReportMoney(
                                            $record['unit_cost']
                                        ) ?>
                                    </strong>
                                    <small class="report-subtext">
                                        Per <?= e($record['unit']) ?>
                                    </small>
                                </td>

                                <td>
                                    <?php if (
                                        $record['rate_difference'] === null
                                    ): ?>
                                        —
                                    <?php elseif (
                                        $record['rate_direction'] === 'increase'
                                    ): ?>
                                        <span class="rate-increase">
                                            +₹<?= priceReportMoney(
                                                $record['rate_difference']
                                            ) ?>
                                        </span>
                                    <?php elseif (
                                        $record['rate_direction'] === 'decrease'
                                    ): ?>
                                        <span class="rate-decrease">
                                            −₹<?= priceReportMoney(
                                                abs($record['rate_difference'])
                                            ) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="rate-same">₹0.00</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if (
                                        $record['percentage_change'] === null
                                    ): ?>
                                        —
                                    <?php elseif (
                                        $record['rate_direction'] === 'increase'
                                    ): ?>
                                        <span class="rate-increase">
                                            +<?= number_format(
                                                $record['percentage_change'],
                                                2
                                            ) ?>%
                                        </span>
                                    <?php elseif (
                                        $record['rate_direction'] === 'decrease'
                                    ): ?>
                                        <span class="rate-decrease">
                                            <?= number_format(
                                                $record['percentage_change'],
                                                2
                                            ) ?>%
                                        </span>
                                    <?php else: ?>
                                        <span class="rate-same">0.00%</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <footer class="report-footer">
            <span>
                <?= number_format($totalRecords) ?> purchase entries
            </span>
            <span>
                Generated on <?= e(date('d M Y, h:i A')) ?>
            </span>
        </footer>
    </section>

    <p class="report-note">
        <strong>Note:</strong> Rate changes compare the current purchase
        rate with the previous posted purchase rate for the same good.
        They represent purchase-cost changes, not selling-price changes
        or profit. The first recorded purchase has no previous rate.
    </p>

</div>

</body>
</html>
