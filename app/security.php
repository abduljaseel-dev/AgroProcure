
<?php
// AgroProcure shared security functions.

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/**
 * Safely display text inside HTML.
 */
function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/**
 * Generate a CSRF token for forms.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Validate a submitted CSRF token.
 */
function verify_csrf_token(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Require a logged-in user.
 */
function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: /agroprocure/public/login.php');
        exit;
    }
}

/**
 * Restrict a page to specific roles.
 */
function require_role(array $allowedRoles): void
{
    require_login();

    $role = $_SESSION['user_role'] ?? '';

    if (!in_array($role, $allowedRoles, true)) {
        http_response_code(403);
        exit('Access denied.');
    }
}
