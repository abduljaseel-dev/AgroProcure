<?php
require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_role(['admin', 'vendor', 'staff']);

$sql = "
    SELECT
        sr.id,
        sr.receipt_number,
        sr.received_at,
        sr.status,
        d.department_code,
        d.department_name,
        COUNT(sri.id) AS item_count,
        COALESCE(SUM(sri.line_total), 0) AS total_value
    FROM supply_receipts sr
    INNER JOIN department_accounts d
        ON d.id = sr.department_id
    LEFT JOIN supply_receipt_items sri
        ON sri.receipt_id = sr.id
    WHERE sr.supplier_type = 'department'
      AND sr.farmer_id IS NULL
    GROUP BY
        sr.id,
        sr.receipt_number,
        sr.received_at,
        sr.status,
        d.department_code,
        d.department_name
    ORDER BY sr.id DESC
    LIMIT 200
";

$stmt = $pdo->query($sql);
$receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);

function department_receipt_status_label(string $status): string
{
    switch ($status) {
        case 'draft':
            return 'Draft';
        case 'posted':
            return 'Posted';
        case 'cancelled':
            return 'Cancelled';
        default:
            return 'Unknown';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Department Receipt Register | AgroProcure</title>
    <link rel="stylesheet" href="../../public/assets/css/supply.css">
    <style>
        .department-label {
            display: inline-block;
            padding: 4px 9px;
            border-radius: 5px;
            background: #e8f1f8;
            color: #16486a;
            font-size: 12px;
            font-weight: 700;
        }

        .receipt-link {
            color: #14577d;
            font-weight: 700;
            text-decoration: none;
        }

        .receipt-link:hover {
            text-decoration: underline;
        }

        .status-label {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 5px;
            background: #edf1f4;
            color: #344b5d;
            font-size: 12px;
            font-weight: 700;
        }

        .status-draft {
            background: #fff2d8;
            color: #805500;
        }

        .status-posted {
            background: #e1f5e8;
            color: #17643a;
        }

        .status-cancelled {
            background: #fde8e8;
            color: #932d2d;
        }

        .register-summary {
            margin: 16px 0;
            padding: 14px 16px;
            background: #f1f6fa;
            border: 1px solid #dbe6ed;
            border-radius: 8px;
            color: #385267;
        }

        .register-summary strong {
            color: #123b58;
        }

        .register-table td {
            vertical-align: middle;
        }
    </style>
</head>
<body>
<div class="supply-page">
    <header class="supply-header">
        <div>
            <p class="eyebrow">AGROPROCURE · PROCUREMENT</p>
            <h1>Department Receipt Register</h1>
            <p>Review Agriculture Department supply receipts and posting status.</p>
        </div>
        <a class="back-link" href="supply.php">New Department Receipt</a>
    </header>

    <section class="form-card">
        <h2>Recent Department Receipts</h2>

        <p class="help">
            Up to 200 recent department receipts are shown.
            Draft receipts do not change stock or supplier balances.
        </p>

        <div class="register-summary">
            <strong><?= count($receipts) ?></strong>
            department receipt(s) displayed.
        </div>

        <div class="table-wrap">
            <table class="register-table">
                <thead>
                <tr>
                    <th>Receipt Number</th>
                    <th>Date Received</th>
                    <th>Department Code</th>
                    <th>Department Name</th>
                    <th>Goods Lines</th>
                    <th>Total Value</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$receipts): ?>
                    <tr>
                        <td colspan="8">
                            No department supply receipts found.
                            Use “New Department Receipt” to create one.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($receipts as $receipt): ?>
                        <?php
                        $status = (string)$receipt['status'];
                        $statusClass = match ($status) {
                            'draft' => 'status-draft',
                            'posted' => 'status-posted',
                            'cancelled' => 'status-cancelled',
                            default => ''
                        };
                        ?>
                        <tr>
                            <td>
                                <a class="receipt-link"
                                   href="receipt-details.php?id=<?= (int)$receipt['id'] ?>">
                                    <?= e($receipt['receipt_number']) ?>
                                </a>
                            </td>
                            <td><?= e((string)$receipt['received_at']) ?></td>
                            <td>
                                <span class="department-label">
                                    <?= e($receipt['department_code']) ?>
                                </span>
                            </td>
                            <td><?= e($receipt['department_name']) ?></td>
                            <td><?= (int)$receipt['item_count'] ?></td>
                            <td>
                                ₹<?= number_format(
                                    (float)$receipt['total_value'],
                                    2,
                                    '.',
                                    ','
                                ) ?>
                            </td>
                            <td>
                                <span class="status-label <?= e($statusClass) ?>">
                                    <?= e(department_receipt_status_label($status)) ?>
                                </span>
                            </td>
                            <td>
                                <a class="receipt-link"
                                   href="receipt-details.php?id=<?= (int)$receipt['id'] ?>">
                                    View / Review
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
</body>
</html>