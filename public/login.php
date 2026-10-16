
<?php
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../config/database.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Enter your username and password.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    'SELECT id, username, password_hash, role, is_active
                     FROM users
                     WHERE username = :username
                     LIMIT 1'
                );

                $stmt->execute(['username' => $username]);
                $user = $stmt->fetch();

                if (
                    $user &&
                    (int) $user['is_active'] === 1 &&
                    password_verify($password, $user['password_hash'])
                ) {
                    session_regenerate_id(true);

                    $_SESSION['user_id'] = (int) $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['user_role'] = $user['role'];

                    // Rotate the CSRF token after authentication.
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                    header('Location: dashboard.php');
                    exit;
                }

                $error = 'Invalid username or password.';
            } catch (PDOException $e) {
                error_log('AgroProcure login database error.');
                http_response_code(500);
                $error = 'Login is temporarily unavailable. Please try again later.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Login | AgroProcure</title>
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body>
    <main class="auth-card">
        <div class="brand-mark">AP</div>
        <p class="eyebrow">AGRICULTURAL PROCUREMENT SYSTEM</p>
        <h1>Welcome back</h1>
        <p class="subtitle">
            Sign in to securely manage procurement, stock and payments.
        </p>

        <?php if ($error !== ''): ?>
            <div class="message error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="login.php">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrf_token()) ?>"
            >

            <label for="username">Username</label>
            <input
                id="username"
                name="username"
                type="text"
                maxlength="80"
                autocomplete="username"
                required
                autofocus
            >

            <label for="password">Password</label>
            <input
                id="password"
                name="password"
                type="password"
                autocomplete="current-password"
                required
            >

            <button class="primary-button" type="submit">Sign In</button>
        </form>

        <p class="security-note">
            Authorized personnel only. Protect your credentials and sign out
            when you finish using the system.
        </p>
    </main>
</body>
</html>
