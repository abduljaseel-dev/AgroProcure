
<?php
require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_role(['admin', 'vendor', 'staff']);

$error = '';
$created = isset($_GET['created']) && $_GET['created'] === '1';
$search = trim($_GET['q'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid form submission. Refresh the page and try again.';
    } else {
        $name = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $idType = trim($_POST['identification_type'] ?? '');
        $idReference = trim($_POST['identification_reference'] ?? '');

        if ($name === '' || mb_strlen($name) > 150) {
            $error = 'Enter a farmer name of up to 150 characters.';
        } elseif (
            $phone === '' ||
            strlen($phone) > 20 ||
            !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)
        ) {
            $error = 'Enter a valid phone number.';
        } elseif (mb_strlen($address) > 5000) {
            $error = 'The address is too long.';
        } elseif (mb_strlen($idType) > 50 || mb_strlen($idReference) > 100) {
            $error = 'Identification details exceed the allowed length.';
        } else {
            try {
                // Retry with a new code if a rare unique-code collision occurs.
                $saved = false;

                for ($attempt = 0; $attempt < 3; $attempt++) {
                    $farmerCode = 'FRM-' . date('Ymd') . '-'
                        . strtoupper(bin2hex(random_bytes(3)));

                    try {
                        $stmt = $pdo->prepare(
                            'INSERT INTO farmers
                                (farmer_code, full_name, phone, address,
                                 identification_type, identification_reference)
                             VALUES
                                (:code, :name, :phone, :address, :id_type, :id_reference)'
                        );

                        $stmt->execute([
                            'code' => $farmerCode,
                            'name' => $name,
                            'phone' => $phone,
                            'address' => $address !== '' ? $address : null,
                            'id_type' => $idType !== '' ? $idType : null,
                            'id_reference' => $idReference !== '' ? $idReference : null,
                        ]);

                        $saved = true;
                        break;
                    } catch (PDOException $e) {
                        if ($e->getCode() !== '23000') {
                            throw $e;
                        }

                        // Could be a generated-code collision; retry.
                        // If other unique constraints are added later,
                        // handle their constraint names separately.
                    }
                }

                if ($saved) {
                    header('Location: index.php?created=1');
                    exit;
                }

                $error = 'Could not generate a unique Farmer ID. Please try again.';
            } catch (PDOException $e) {
                error_log('AgroProcure farmer registration failed.');
                $error = 'Farmer could not be saved. Check the details and try again.';
            }
        }
    }
}

try {
    $sql = 'SELECT id, farmer_code, full_name, phone, address, created_at
            FROM farmers';
    $params = [];

    if ($search !== '') {
        $sql .= ' WHERE farmer_code LIKE :code
                  OR full_name LIKE :name
                  OR phone LIKE :phone';
        $term = '%' . $search . '%';
        $params = [
            'code' => $term,
            'name' => $term,
            'phone' => $term,
        ];
    }

    $sql .= ' ORDER BY id DESC LIMIT 100';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $farmers = $stmt->fetchAll();

    $totalFarmers = (int) $pdo->query(
        'SELECT COUNT(*) FROM farmers WHERE is_active = 1'
    )->fetchColumn();
} catch (PDOException $e) {
    error_log('AgroProcure farmer list query failed.');
    http_response_code(500);
    exit('Farmer records are temporarily unavailable.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Farmer Management | AgroProcure</title>
    <link rel="stylesheet" href="../../public/assets/css/farmers.css">
</head>
<body>
<div class="page-shell">
    <header class="page-header">
        <div>
            <a class="back-link" href="../../public/dashboard.php">
                &larr; Back to Dashboard
            </a>
            <p class="eyebrow">SUPPLIER MANAGEMENT</p>
            <h1>Farmer Management</h1>
            <p class="subtitle">
                Register farmers and maintain their supplier records.
            </p>
        </div>
        <div class="header-mark">AP</div>
    </header>

    <?php if ($created): ?>
        <div class="notice success" role="status">
            Farmer registered successfully.
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="notice error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="summary-card">
        <span class="summary-label">Active Registered Farmers</span>
        <strong><?= number_format($totalFarmers) ?></strong>
        <span class="summary-help">Total active farmer accounts</span>
    </section>

    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Register a Farmer</h2>
                <p>Fields marked with * are required.</p>
            </div>
        </div>

        <form method="post" action="index.php" class="farmer-form">
            <input type="hidden" name="csrf_token"
                   value="<?= e(csrf_token()) ?>">

            <div class="form-grid">
                <div class="field">
                    <label for="full_name">Full Name *</label>
                    <input id="full_name" name="full_name" type="text"
                           maxlength="150" autocomplete="name" required
                           value="<?= e($_POST['full_name'] ?? '') ?>">
                </div>

                <div class="field">
                    <label for="phone">Phone Number *</label>
                    <input id="phone" name="phone" type="tel"
                           maxlength="20" autocomplete="tel" required
                           value="<?= e($_POST['phone'] ?? '') ?>">
                </div>

                <div class="field">
                    <label for="identification_type">Identification Type</label>
                    <select id="identification_type" name="identification_type">
                        <option value="">Select if applicable</option>
                        <?php foreach (['Government ID', 'Voter ID', 'Other'] as $type): ?>
                            <option value="<?= e($type) ?>"
                                <?= (($_POST['identification_type'] ?? '') === $type)
                                    ? 'selected' : '' ?>>
                                <?= e($type) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="identification_reference">Identification Reference</label>
                    <input id="identification_reference"
                           name="identification_reference" type="text"
                           maxlength="100" autocomplete="off"
                           value="<?= e($_POST['identification_reference'] ?? '') ?>">
                    <small>Enter only information you are authorized to collect.</small>
                </div>

                <div class="field full-width">
                    <label for="address">Address</label>
                    <textarea id="address" name="address" rows="3"
                              maxlength="5000"><?= e($_POST['address'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="primary-button">
                    Register Farmer
                </button>
            </div>
        </form>
    </section>

    <section class="panel">
        <div class="panel-heading list-heading">
            <div>
                <h2>Registered Farmers</h2>
                <p>Showing up to 100 most recently registered records.</p>
            </div>

            <form method="get" action="index.php" class="search-form">
                <label class="visually-hidden" for="q">Search farmers</label>
                <input id="q" name="q" type="search"
                       placeholder="Search name, ID or phone"
                       value="<?= e($search) ?>">
                <button type="submit" class="secondary-button">Search</button>
            </form>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Farmer ID</th>
                        <th>Farmer Name</th>
                        <th>Phone</th>
                        <th>Registered On</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$farmers): ?>
                    <tr>
                        <td colspan="4" class="empty-state">
                            No farmer records found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($farmers as $farmer): ?>
                        <tr>
                            <td><span class="farmer-code">
                                <?= e($farmer['farmer_code']) ?>
                            </span></td>
                            <td><?= e($farmer['full_name']) ?></td>
                            <td><?= e($farmer['phone']) ?></td>
                            <td><?= e(date('d M Y', strtotime($farmer['created_at']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <footer class="page-footer">
        AgroProcure &mdash; Agricultural Procurement &amp; Payment Management System
    </footer>
</div>
</body>
</html>
