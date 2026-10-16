<?php
require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_role(['admin', 'vendor', 'staff']);

$errors = [];
$success = '';
$search = trim($_GET['search'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session token is invalid. Refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $name = trim($_POST['department_name'] ?? '');
            $contact = trim($_POST['contact_person'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $address = trim($_POST['address'] ?? '');

            if ($name === '' || mb_strlen($name) > 150) {
                $errors[] = 'Enter a department name of up to 150 characters.';
            }

            if (mb_strlen($contact) > 150) {
                $errors[] = 'Contact person must not exceed 150 characters.';
            }

            if (mb_strlen($phone) > 20) {
                $errors[] = 'Phone number must not exceed 20 characters.';
            }

            if (mb_strlen($address) > 65535) {
                $errors[] = 'Address is too long.';
            }

            if (!$errors) {
                try {
                    $pdo->beginTransaction();

                    // Generate a unique department code.
                    $departmentCode = '';

                    for ($attempt = 0; $attempt < 5; $attempt++) {
                        $candidate = 'DEP-' . date('Ymd') . '-' .
                            strtoupper(bin2hex(random_bytes(3)));

                        $check = $pdo->prepare(
                            'SELECT id FROM department_accounts
                             WHERE department_code = ?
                             LIMIT 1'
                        );
                        $check->execute([$candidate]);

                        if (!$check->fetch(PDO::FETCH_ASSOC)) {
                            $departmentCode = $candidate;
                            break;
                        }
                    }

                    if ($departmentCode === '') {
                        throw new RuntimeException(
                            'Unable to generate a unique department code. Please retry.'
                        );
                    }

                    $stmt = $pdo->prepare(
                        'INSERT INTO department_accounts
                         (department_code, department_name, contact_person,
                          phone, address, is_active)
                         VALUES (?, ?, ?, ?, ?, 1)'
                    );

                    $stmt->execute([
                        $departmentCode,
                        $name,
                        $contact !== '' ? $contact : null,
                        $phone !== '' ? $phone : null,
                        $address !== '' ? $address : null
                    ]);

                    $departmentId = (int) $pdo->lastInsertId();

                    $audit = $pdo->prepare(
                        'INSERT INTO audit_logs
                         (user_id, action, entity_type, entity_id, details, ip_address)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );

                    $audit->execute([
                        $_SESSION['user_id'] ?? null,
                        'CREATE_DEPARTMENT',
                        'department_account',
                        $departmentId,
                        json_encode([
                            'department_code' => $departmentCode,
                            'department_name' => $name
                        ], JSON_UNESCAPED_UNICODE),
                        $_SERVER['REMOTE_ADDR'] ?? null
                    ]);

                    $pdo->commit();

                    header('Location: index.php?created=1');
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    if (
                        $e instanceof PDOException &&
                        $e->getCode() === '23000'
                    ) {
                        $errors[] = 'A duplicate department record was detected. Please retry.';
                    } else {
                        error_log('AgroProcure department creation error: ' . $e->getMessage());
                        $errors[] = 'Department could not be saved. Check the server error log if the problem continues.';
                    }
                }
            }
        }

        if ($action === 'toggle_status') {
            $departmentId = filter_var(
                $_POST['department_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            if (!$departmentId) {
                $errors[] = 'Invalid department selected.';
            } else {
                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare(
                        'SELECT id, department_code, department_name, is_active
                         FROM department_accounts
                         WHERE id = ?
                         FOR UPDATE'
                    );
                    $stmt->execute([$departmentId]);
                    $department = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$department) {
                        throw new RuntimeException('Department not found.');
                    }

                    $newStatus = (int) $department['is_active'] === 1 ? 0 : 1;

                    $update = $pdo->prepare(
                        'UPDATE department_accounts
                         SET is_active = ?
                         WHERE id = ?'
                    );
                    $update->execute([$newStatus, $departmentId]);

                    $audit = $pdo->prepare(
                        'INSERT INTO audit_logs
                         (user_id, action, entity_type, entity_id, details, ip_address)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $audit->execute([
                        $_SESSION['user_id'] ?? null,
                        $newStatus ? 'ACTIVATE_DEPARTMENT' : 'DEACTIVATE_DEPARTMENT',
                        'department_account',
                        $departmentId,
                        json_encode([
                            'department_code' => $department['department_code'],
                            'department_name' => $department['department_name'],
                            'new_status' => $newStatus
                        ], JSON_UNESCAPED_UNICODE),
                        $_SERVER['REMOTE_ADDR'] ?? null
                    ]);

                    $pdo->commit();

                    header('Location: index.php?status_updated=1');
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    error_log('AgroProcure department status error: ' . $e->getMessage());
                    $errors[] = 'Department status could not be changed.';
                }
            }
        }
    }
}

if (isset($_GET['created'])) {
    $success = 'Department account created successfully.';
} elseif (isset($_GET['status_updated'])) {
    $success = 'Department status updated successfully.';
}

try {
    $sql = 'SELECT id, department_code, department_name, contact_person,
                   phone, address, is_active, created_at
            FROM department_accounts';

    $params = [];

    if ($search !== '') {
        $sql .= ' WHERE department_code LIKE ?
                  OR department_name LIKE ?
                  OR contact_person LIKE ?
                  OR phone LIKE ?';

        $term = '%' . $search . '%';
        $params = [$term, $term, $term, $term];
    }

    $sql .= ' ORDER BY id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalStmt = $pdo->query(
        'SELECT
            COUNT(*) AS total,
            COALESCE(SUM(is_active = 1), 0) AS active,
            COALESCE(SUM(is_active = 0), 0) AS inactive
         FROM department_accounts'
    );
    $stats = $totalStmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('AgroProcure department listing error: ' . $e->getMessage());
    $departments = [];
    $stats = ['total' => 0, 'active' => 0, 'inactive' => 0];
    $errors[] = 'Department records could not be loaded.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Accounts | AgroProcure</title>
    <link rel="stylesheet" href="../../public/assets/css/sales.css">
    <style>
        .dept-page {
            max-width: 1400px;
            margin: 0 auto;
            padding: 28px 22px 50px;
            color: #172b3a;
        }
        .dept-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;
            margin-bottom: 24px;
        }
        .dept-heading h1 { margin: 0 0 6px; font-size: 28px; }
        .dept-heading p { margin: 0; color: #617386; }
        .dept-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .dept-card, .dept-panel {
            background: #fff;
            border: 1px solid #dce5ec;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 3px 12px rgba(20, 43, 60, .04);
        }
        .dept-card span { display: block; color: #617386; font-size: 13px; }
        .dept-card strong { display: block; font-size: 27px; margin-top: 8px; }
        .dept-layout {
            display: grid;
            grid-template-columns: minmax(280px, .8fr) minmax(0, 1.7fr);
            gap: 20px;
            align-items: start;
        }
        .dept-panel h2 { margin: 0 0 18px; font-size: 19px; }
        .dept-field { margin-bottom: 15px; }
        .dept-field label {
            display: block; font-size: 13px; font-weight: 700;
            margin-bottom: 6px; color: #344b5e;
        }
        .dept-field input, .dept-field textarea, .dept-search input {
            width: 100%; padding: 11px 12px; border: 1px solid #cbd7e1;
            border-radius: 7px; background: #fff; color: #172b3a;
            font: inherit; box-sizing: border-box;
        }
        .dept-field textarea { min-height: 85px; resize: vertical; }
        .dept-button {
            display: inline-block; border: 0; border-radius: 7px;
            padding: 11px 15px; background: #123b58; color: white;
            font-weight: 700; cursor: pointer; text-decoration: none;
        }
        .dept-button:hover { background: #1b5378; }
        .dept-button-small { padding: 7px 10px; font-size: 12px; }
        .dept-search { display: flex; gap: 8px; margin-bottom: 16px; }
        .dept-table-wrap { overflow-x: auto; }
        .dept-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .dept-table th, .dept-table td {
            padding: 12px 10px; text-align: left; vertical-align: top;
            border-bottom: 1px solid #e5ebf0;
        }
        .dept-table th { background: #f4f7fa; color: #43596b; white-space: nowrap; }
        .dept-code { font-weight: 700; color: #174e70; white-space: nowrap; }
        .dept-status {
            display: inline-block; padding: 4px 8px; border-radius: 20px;
            font-size: 11px; font-weight: 700;
        }
        .dept-active { background: #e1f5e9; color: #17643a; }
        .dept-inactive { background: #fbe8e8; color: #982e2e; }
        .dept-alert { padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; }
        .dept-success { background: #e4f6eb; color: #17643a; }
        .dept-error { background: #fff0f0; color: #982e2e; }
        .dept-muted { color: #6c7e8d; }
        .dept-inline { display: inline; }
        .dept-empty { text-align: center; padding: 28px !important; color: #718292; }
        @media (max-width: 900px) {
            .dept-layout { grid-template-columns: 1fr; }
        }
        @media (max-width: 600px) {
            .dept-page { padding: 18px 12px 35px; }
            .dept-grid { grid-template-columns: 1fr; }
            .dept-heading h1 { font-size: 23px; }
        }
    </style>
</head>
<body>
<main class="dept-page">
    <header class="dept-heading">
        <div>
            <h1>Agriculture Department Accounts</h1>
            <p>Register departments and manage their supplier account status.</p>
        </div>
        <a class="dept-button" href="../../public/dashboard.php">Back to Dashboard</a>
    </header>

    <?php if ($success !== ''): ?>
        <div class="dept-alert dept-success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <div class="dept-alert dept-error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <section class="dept-grid">
        <div class="dept-card">
            <span>Total departments</span>
            <strong><?= (int) $stats['total'] ?></strong>
        </div>
        <div class="dept-card">
            <span>Active departments</span>
            <strong><?= (int) $stats['active'] ?></strong>
        </div>
        <div class="dept-card">
            <span>Inactive departments</span>
            <strong><?= (int) $stats['inactive'] ?></strong>
        </div>
    </section>

    <section class="dept-layout">
        <div class="dept-panel">
            <h2>Register Department</h2>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create">

                <div class="dept-field">
                    <label for="department_name">Department name *</label>
                    <input id="department_name" name="department_name"
                           maxlength="150" required
                           value="<?= e($_POST['department_name'] ?? '') ?>"
                           autocomplete="organization">
                </div>

                <div class="dept-field">
                    <label for="contact_person">Contact person</label>
                    <input id="contact_person" name="contact_person"
                           maxlength="150"
                           value="<?= e($_POST['contact_person'] ?? '') ?>">
                </div>

                <div class="dept-field">
                    <label for="phone">Phone number</label>
                    <input id="phone" name="phone" maxlength="20"
                           value="<?= e($_POST['phone'] ?? '') ?>"
                           autocomplete="tel">
                </div>

                <div class="dept-field">
                    <label for="address">Department address</label>
                    <textarea id="address" name="address"
                              maxlength="65535"><?= e($_POST['address'] ?? '') ?></textarea>
                </div>

                <button class="dept-button" type="submit">Create Department Account</button>
            </form>
        </div>

        <div class="dept-panel">
            <h2>Department Register</h2>

            <form method="get" class="dept-search">
                <input type="search" name="search"
                       placeholder="Search code, name, contact or phone"
                       value="<?= e($search) ?>">
                <button class="dept-button" type="submit">Search</button>
                <?php if ($search !== ''): ?>
                    <a class="dept-button" href="index.php">Clear</a>
                <?php endif; ?>
            </form>

            <div class="dept-table-wrap">
                <table class="dept-table">
                    <thead>
                    <tr>
                        <th>Department</th>
                        <th>Contact</th>
                        <th>Status</th>
                        <th>Registered</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$departments): ?>
                        <tr>
                            <td class="dept-empty" colspan="5">
                                No department records found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($departments as $department): ?>
                            <tr>
                                <td>
                                    <div class="dept-code"><?= e($department['department_code']) ?></div>
                                    <strong><?= e($department['department_name']) ?></strong>
                                    <?php if (!empty($department['address'])): ?>
                                        <div class="dept-muted">
                                            <?= e($department['address']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= e($department['contact_person'] ?? '') ?>
                                    <?php if (!empty($department['phone'])): ?>
                                        <div><?= e($department['phone']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int) $department['is_active'] === 1): ?>
                                        <span class="dept-status dept-active">Active</span>
                                    <?php else: ?>
                                        <span class="dept-status dept-inactive">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e(date('d M Y', strtotime($department['created_at']))) ?></td>
                                <td>
                                    <form method="post" class="dept-inline">
                                        <input type="hidden" name="csrf_token"
                                               value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="department_id"
                                               value="<?= (int) $department['id'] ?>">
                                        <button class="dept-button dept-button-small" type="submit">
                                            <?= (int) $department['is_active'] === 1
                                                ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</main>
</body>
</html>
