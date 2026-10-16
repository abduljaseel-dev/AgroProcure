
<?php
require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_role(['admin', 'vendor', 'staff']);

$sql = "
    SELECT
        sr.id,
        sr.received_at,
        sr.status,
        f.farmer_code,
        f.full_name,
        COUNT(sri.id) AS item_count,
        COALESCE(SUM(sri.line_total), 0) AS total_value
    FROM supply_receipts sr
    INNER JOIN farmers f ON f.id = sr.farmer_id
    LEFT JOIN supply_receipt_items sri ON sri.receipt_id = sr.id
    WHERE sr.supplier_type = 'farmer'
    GROUP BY
        sr.id,
        sr.received_at,
        sr.status,
        f.farmer_code,
        f.full_name
    ORDER BY sr.id DESC
    LIMIT 200
";

$stmt = $pdo->query($sql);
$receipts = $stmt->fetchAll();

function receipt_status_label(string $status): string
{
    return match ($status) {
        'draft' => 'Draft',
        'posted' => 'Posted',
        'cancelled' => 'Cancelled',
        default => 'Unknown',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Farmer Receipt Register | AgroProcure</title>
    <link rel="stylesheet" href="../../public/assets/css/supply.css">
</head>
<body>
<div class="supply-page">
    <header class="supply-header">
        <div>
            <p class="eyebrow">AGROPROCURE · PROCUREMENT</p>
            <h1>Farmer Receipt Register</h1>
            <p>Review saved farmer supply receipts and their current status.</p>
        </div>
        <a class="back-link" href="supply.php">New Supply Receipt</a>
    </header>

    <section class="form-card">
        <h2>Recent Receipts</h2>
        <p class="help">
            Up to 200 most recent receipts are shown.
            Draft receipts have not changed stock or supplier balances.
        </p>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Receipt No.</th>
                    <th>Date Received</th>
                    <th>Farmer ID</th>
                    <th>Farmer Name</th>
                    <th>Items</th>
                    <th>Total Value</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$receipts): ?>
                    <tr>
                        <td colspan="7">No farmer supply receipts found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($receipts as $receipt): ?>
                        <tr>
                            <td>
                                <a href="receipt-details.php?id=<?= (int)$receipt['id'] ?>">
                                    FR-<?= (int)$receipt['id'] ?>
                                </a>
                            </td>
                            <td><?= e((string)$receipt['received_at']) ?></td>
                            <td><?= e($receipt['farmer_code']) ?></td>
                            <td><?= e($receipt['full_name']) ?></td>
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
                                <?= e(receipt_status_label(
                                    (string)$receipt['status']
                                )) ?>
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
