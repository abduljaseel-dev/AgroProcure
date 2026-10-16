
<?php
require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_login();

$pageTitle = 'Supplier Account Statement';

$today = date('Y-m-d');
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? $today;
$supplierSelection = trim($_GET['supplier'] ?? '');

$errors = [];
$farmers = [];
$departments = [];
$entries = [];

$selectedSupplier = null;
$selectedType = '';
$selectedId = 0;

$openingBalance = 0.0;
$totalDue = 0.0;
$totalPaid = 0.0;
$closingBalance = 0.0;

function supplierStatementEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function supplierStatementMoney($value): string
{
    return number_format((float) $value, 2);
}

function supplierStatementDateValid($value): bool
{
    if (!is_string($value) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }

    return checkdate(
        (int) substr($value, 5, 2),
        (int) substr($value, 8, 2),
        (int) substr($value, 0, 4)
    );
}

/* Validate date filters */
if (
    !supplierStatementDateValid($startDate) ||
    !supplierStatementDateValid($endDate) ||
    $startDate > $endDate
) {
    $errors[] = 'Please select a valid date range.';

    $startDate = date('Y-m-01');
    $endDate = $today;
}

/* Parse supplier selection: farmer:1 or department:1 */
if ($supplierSelection !== '') {
    $parts = explode(':', $supplierSelection);

    if (
        count($parts) !== 2 ||
        !in_array($parts[0], ['farmer', 'department'], true) ||
        !ctype_digit($parts[1]) ||
        (int) $parts[1] < 1
    ) {
        $errors[] = 'Please select a valid supplier account.';
    } else {
        $selectedType = $parts[0];
        $selectedId = (int) $parts[1];
    }
}

try {
    /* Load farmer accounts for the selector */
    $stmt = $pdo->query("
        SELECT id, farmer_code, full_name
        FROM farmers
        ORDER BY full_name ASC, id ASC
    ");

    $farmers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Load department accounts for the selector */
    $stmt = $pdo->query("
        SELECT id, department_code, department_name
        FROM department_accounts
        ORDER BY department_name ASC, id ASC
    ");

    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
     * Load a statement only when a supplier is selected.
     */
    if ($selectedId > 0 && !$errors) {
        if ($selectedType === 'farmer') {
            $stmt = $pdo->prepare("
                SELECT id, farmer_code AS supplier_code,
                       full_name AS supplier_name, phone, address
                FROM farmers
                WHERE id = :supplier_id
                LIMIT 1
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT id, department_code AS supplier_code,
                       department_name AS supplier_name, phone, address
                FROM department_accounts
                WHERE id = :supplier_id
                LIMIT 1
            ");
        }

        $stmt->execute(['supplier_id' => $selectedId]);
        $selectedSupplier = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$selectedSupplier) {
            $errors[] = 'The selected supplier account was not found.';
        }
    }

    if ($selectedSupplier && !$errors) {
        /*
         * Opening balance: all ledger entries before the
         * selected period.
         */
        $openingSql = "
            SELECT COALESCE(
                SUM(
                    COALESCE(amount_due, 0)
                    - COALESCE(amount_paid, 0)
                ),
                0
            )
            FROM supplier_ledger
            WHERE supplier_type = :supplier_type
              AND " . (
                  $selectedType === 'farmer'
                      ? 'farmer_id'
                      : 'department_id'
              ) . " = :supplier_id
              AND created_at < :opening_date
        ";

        $stmt = $pdo->prepare($openingSql);
        $stmt->execute([
            'supplier_type' => $selectedType,
            'supplier_id' => $selectedId,
            'opening_date' => $startDate . ' 00:00:00'
        ]);

        $openingBalance = (float) $stmt->fetchColumn();

        /*
         * Transaction register.
         * LEFT JOINs retain ledger entries even if a linked
         * receipt or payment reference is missing.
         */
        $movementSql = "
            SELECT
                sl.id,
                sl.created_at,
                sl.entry_type,
                sl.amount_due,
                sl.amount_paid,
                sl.description,
                sl.supply_receipt_id,
                sl.supplier_payment_id,

                sr.receipt_number,
                sp.payment_reference,
                sp.payment_method,
                sp.transaction_reference,
                sp.paid_at

            FROM supplier_ledger sl

            LEFT JOIN supply_receipts sr
                ON sr.id = sl.supply_receipt_id

            LEFT JOIN supplier_payments sp
                ON sp.id = sl.supplier_payment_id

            WHERE sl.supplier_type = :supplier_type
              AND sl." . (
                  $selectedType === 'farmer'
                      ? 'farmer_id'
                      : 'department_id'
              ) . " = :supplier_id
              AND sl.created_at >= :period_start
              AND sl.created_at < :period_end

            ORDER BY sl.created_at ASC, sl.id ASC
        ";

        $stmt = $pdo->prepare($movementSql);
        $stmt->execute([
            'supplier_type' => $selectedType,
            'supplier_id' => $selectedId,
            'period_start' => $startDate . ' 00:00:00',
            'period_end' => date(
                'Y-m-d H:i:s',
                strtotime($endDate . ' +1 day')
            )
        ]);

        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        /*
         * Calculate the period totals and running balance.
         * The ledger's stored due/paid amounts are used as-is.
         */
        $runningBalance = $openingBalance;

        foreach ($entries as &$entry) {
            $due = (float) ($entry['amount_due'] ?? 0);
            $paid = (float) ($entry['amount_paid'] ?? 0);

            $totalDue += $due;
            $totalPaid += $paid;

            $runningBalance += $due - $paid;

            $entry['running_balance'] = $runningBalance;
        }

        unset($entry);

        $closingBalance = $openingBalance + $totalDue - $totalPaid;
    }

} catch (Throwable $e) {
    error_log(
        'Supplier account statement error: ' . $e->getMessage()
    );

    $errors[] = 'Unable to load the supplier statement. Please check the database schema and try again.';
}

function supplierStatementEntryLabel($type): string
{
    $labels = [
        'supply' => 'Goods Supplied',
        'payment' => 'Payment',
        'adjustment' => 'Adjustment',
        'reversal' => 'Reversal'
    ];

    return $labels[$type] ?? ucfirst((string) $type);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= supplierStatementEscape($pageTitle) ?> | AgroProcure</title>

    <link rel="stylesheet"
          href="/agroprocure/public/assets/css/reports.css">

    <style>
        .supplier-statement-page .statement-meta {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .supplier-statement-page .statement-meta > div {
            padding: 15px 17px;
            background: #fff;
            border: 1px solid #dce4ec;
            border-radius: 8px;
            min-width: 0;
        }

        .supplier-statement-page .statement-meta span {
            display: block;
            margin-bottom: 5px;
            color: #64748b;
            font-size: 12px;
        }

        .supplier-statement-page .statement-meta strong {
            display: block;
            color: #173b5e;
            overflow-wrap: anywhere;
        }

        .supplier-statement-page .statement-balance {
            font-weight: 700;
            white-space: nowrap;
        }

        .supplier-statement-page .statement-due {
            color: #9a3412;
        }

        .supplier-statement-page .statement-credit {
            color: #166534;
        }

        .supplier-statement-page .statement-type {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 5px;
            background: #eef3f8;
            color: #334155;
            font-size: 12px;
            font-weight: 600;
        }

        .supplier-statement-page .report-filter select {
            width: 100%;
            min-height: 40px;
            padding: 9px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: #fff;
            color: #1e293b;
            font: inherit;
        }

        .supplier-statement-page .statement-opening td,
        .supplier-statement-page .report-table tfoot th {
            background: #edf3f8;
            font-weight: 700;
        }

        .supplier-statement-page .statement-description {
            display: block;
            margin-top: 4px;
            color: #64748b;
            font-size: 12px;
            overflow-wrap: anywhere;
        }

        @media (max-width: 700px) {
            .supplier-statement-page .statement-meta {
                grid-template-columns: 1fr;
            }
        }

        @media print {
            .supplier-statement-page .report-filter,
            .supplier-statement-page .report-header-actions {
                display: none !important;
            }

            .supplier-statement-page .report-panel,
            .supplier-statement-page .statement-meta {
                break-inside: avoid;
            }
        }
    </style>
</head>
<body>

<div class="report-page supplier-statement-page">

    <header class="report-header">
        <div>
            <p class="report-eyebrow">AGROPROCURE / REPORTS</p>
            <h1><?= supplierStatementEscape($pageTitle) ?></h1>
            <p>Individual supplier ledger, payment history and outstanding balance.</p>
        </div>

        <div class="report-header-actions">
            <button type="button"
                    class="report-button"
                    onclick="window.print()">
                Print Statement
            </button>
        </div>
    </header>

    <?php if ($errors): ?>
        <div class="report-error" role="alert">
            <?php foreach ($errors as $error): ?>
                <p><?= supplierStatementEscape($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="report-panel">
        <form method="GET"
              action="/agroprocure/modules/reports/supplier-account-statement.php"
              class="report-filter">

            <div class="filter-field">
                <label for="supplier">Supplier Account</label>
                <select id="supplier" name="supplier" required>
                    <option value="">Select a farmer or department</option>

                    <optgroup label="Farmers">
                        <?php foreach ($farmers as $farmer): ?>
                            <?php $value = 'farmer:' . $farmer['id']; ?>
                            <option
                                value="<?= supplierStatementEscape($value) ?>"
                                <?= $supplierSelection === $value ? 'selected' : '' ?>>
                                <?= supplierStatementEscape(
                                    $farmer['full_name']
                                ) ?>
                                (<?= supplierStatementEscape(
                                    $farmer['farmer_code']
                                ) ?>)
                            </option>
                        <?php endforeach; ?>
                    </optgroup>

                    <optgroup label="Agriculture Department Accounts">
                        <?php foreach ($departments as $department): ?>
                            <?php $value = 'department:' . $department['id']; ?>
                            <option
                                value="<?= supplierStatementEscape($value) ?>"
                                <?= $supplierSelection === $value ? 'selected' : '' ?>>
                                <?= supplierStatementEscape(
                                    $department['department_name']
                                ) ?>
                                (<?= supplierStatementEscape(
                                    $department['department_code']
                                ) ?>)
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
            </div>

            <div class="filter-field">
                <label for="start_date">From Date</label>
                <input type="date"
                       id="start_date"
                       name="start_date"
                       value="<?= supplierStatementEscape($startDate) ?>"
                       required>
            </div>

            <div class="filter-field">
                <label for="end_date">To Date</label>
                <input type="date"
                       id="end_date"
                       name="end_date"
                       value="<?= supplierStatementEscape($endDate) ?>"
                       required>
            </div>

            <div class="filter-field filter-actions">
                <button type="submit" class="report-button">
                    Generate Statement
                </button>
            </div>
        </form>
    </section>

    <?php if ($selectedSupplier && !$errors): ?>

        <section class="statement-meta">
            <div>
                <span>Supplier Name</span>
                <strong>
                    <?= supplierStatementEscape(
                        $selectedSupplier['supplier_name']
                    ) ?>
                </strong>
            </div>

            <div>
                <span>Supplier Code</span>
                <strong>
                    <?= supplierStatementEscape(
                        $selectedSupplier['supplier_code'] ?: '—'
                    ) ?>
                </strong>
            </div>

            <div>
                <span>Supplier Type</span>
                <strong>
                    <?= $selectedType === 'farmer'
                        ? 'Farmer'
                        : 'Agriculture Department' ?>
                </strong>
            </div>
        </section>

        <section class="report-summary">
            <article class="summary-card">
                <span>Opening Balance</span>
                <strong>₹<?= supplierStatementMoney($openingBalance) ?></strong>
                <small>Balance before <?= supplierStatementEscape($startDate) ?></small>
            </article>

            <article class="summary-card">
                <span>Amount Due</span>
                <strong>₹<?= supplierStatementMoney($totalDue) ?></strong>
                <small>Ledger due entries in this period</small>
            </article>

            <article class="summary-card">
                <span>Amount Paid</span>
                <strong>₹<?= supplierStatementMoney($totalPaid) ?></strong>
                <small>Ledger paid entries in this period</small>
            </article>

            <article class="summary-card">
                <span>Closing Balance</span>
                <strong>₹<?= supplierStatementMoney($closingBalance) ?></strong>
                <small>
                    <?php if ($closingBalance > 0): ?>
                        Net amount payable to supplier
                    <?php elseif ($closingBalance < 0): ?>
                        Net supplier account credit
                    <?php else: ?>
                        Account balanced
                    <?php endif; ?>
                </small>
            </article>
        </section>

        <section class="report-panel">
            <div class="report-table-heading">
                <div>
                    <h2>Supplier Transaction History</h2>
                    <p>
                        <?= supplierStatementEscape($startDate) ?>
                        to
                        <?= supplierStatementEscape($endDate) ?>
                    </p>
                </div>
            </div>

            <div class="report-table-wrapper">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Transaction</th>
                            <th>Description / Reference</th>
                            <th>Amount Due</th>
                            <th>Amount Paid</th>
                            <th>Running Balance</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr class="statement-opening">
                            <td>
                                <?= supplierStatementEscape(
                                    date('d M Y', strtotime($startDate))
                                ) ?>
                            </td>
                            <td>Opening Balance</td>
                            <td>Balance brought forward</td>
                            <td>—</td>
                            <td>—</td>
                            <td class="statement-balance">
                                ₹<?= supplierStatementMoney($openingBalance) ?>
                            </td>
                        </tr>

                        <?php if (!$entries): ?>
                            <tr>
                                <td colspan="6">
                                    No ledger transactions were found for this
                                    supplier during the selected period.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($entries as $entry): ?>
                                <tr>
                                    <td>
                                        <?= supplierStatementEscape(
                                            date(
                                                'd M Y, h:i A',
                                                strtotime($entry['created_at'])
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <span class="statement-type">
                                            <?= supplierStatementEscape(
                                                supplierStatementEntryLabel(
                                                    $entry['entry_type']
                                                )
                                            ) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= supplierStatementEscape(
                                            $entry['description'] ?: '—'
                                        ) ?>

                                        <?php if (!empty($entry['receipt_number'])): ?>
                                            <span class="statement-description">
                                                Receipt:
                                                <?= supplierStatementEscape(
                                                    $entry['receipt_number']
                                                ) ?>
                                            </span>
                                        <?php endif; ?>

                                        <?php if (!empty($entry['payment_reference'])): ?>
                                            <span class="statement-description">
                                                Payment:
                                                <?= supplierStatementEscape(
                                                    $entry['payment_reference']
                                                ) ?>
                                            </span>
                                        <?php endif; ?>

                                        <?php if (!empty($entry['payment_method'])): ?>
                                            <span class="statement-description">
                                                Method:
                                                <?= supplierStatementEscape(
                                                    ucwords(str_replace(
                                                        '_',
                                                        ' ',
                                                        $entry['payment_method']
                                                    ))
                                                ) ?>
                                            </span>
                                        <?php endif; ?>

                                        <?php if (!empty($entry['transaction_reference'])): ?>
                                            <span class="statement-description">
                                                Transaction reference:
                                                <?= supplierStatementEscape(
                                                    $entry['transaction_reference']
                                                ) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        ₹<?= supplierStatementMoney(
                                            $entry['amount_due']
                                        ) ?>
                                    </td>

                                    <td>
                                        ₹<?= supplierStatementMoney(
                                            $entry['amount_paid']
                                        ) ?>
                                    </td>

                                    <td class="statement-balance">
                                        ₹<?= supplierStatementMoney(
                                            $entry['running_balance']
                                        ) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>

                    <tfoot>
                        <tr>
                            <th colspan="3">Period Totals</th>
                            <th>₹<?= supplierStatementMoney($totalDue) ?></th>
                            <th>₹<?= supplierStatementMoney($totalPaid) ?></th>
                            <th>₹<?= supplierStatementMoney($closingBalance) ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <footer class="report-footer">
                <span>AgroProcure Supplier Account Statement</span>
                <span>Generated <?= date('d M Y, h:i A') ?></span>
            </footer>
        </section>

        <p class="report-note">
            <strong>Accounting note:</strong>
            This statement uses the amounts and entry types recorded in
            supplier_ledger. Positive closing balances represent amounts
            payable to the supplier; negative balances represent a net
            credit on the supplier account. Verify reversal and adjustment
            entries against your ledger rules before relying on the balance.
        </p>

    <?php else: ?>

        <section class="report-panel">
            <h2>Select a Supplier Account</h2>
            <p>
                Choose a farmer or Agriculture Department account and a date
                range, then generate the statement to view its individual
                supplies, payments, adjustments, and running balance.
            </p>
        </section>

    <?php endif; ?>

</div>

</body>
</html>
