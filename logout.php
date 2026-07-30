<?php
/**
 * logout.php
 * ------------------------------------------------------------
 * Destroys the current session and redirects to login.php.
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/helpers.php';

if (!empty($_SESSION['username'])) {
    log_activity($pdo, "{$_SESSION['username']} logged out");
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

header('Location: login.php');
exit;