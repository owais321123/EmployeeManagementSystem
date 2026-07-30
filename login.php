<?php
/**
 * login.php (root)
 * ------------------------------------------------------------
 * Session-based login for the single-page app. On success,
 * redirects to index.html. All api/*.php endpoints require
 * this session via require_login() in includes/helpers.php.
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/helpers.php';

$redirectTo = 'index.html';
$error = '';

if (!empty($_SESSION['user_id'])) {
    header("Location: $redirectTo");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $user = db_fetch_one('SELECT * FROM users WHERE username = :username', ['username' => $username]);

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']  = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'];

            log_activity($pdo, "{$user['username']} logged in");

            header("Location: $redirectTo");
            exit;
        }

        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Employee Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .login-wrapper { min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-box { width: 100%; max-width: 360px; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,0.12); background: white; }
        .login-box h1 { font-size: 1.4rem; margin-bottom: 1.25rem; text-align: center; }
        .login-error { background: #fdecea; color: #b71c1c; padding: 0.6rem 0.8rem; border-radius: 4px; margin-bottom: 1rem; font-size: 0.9rem; }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="login-box">
            <h1><i class="fas fa-users"></i> Employee Management System</h1>
            <?php if ($error): ?>
                <div class="login-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="post" action="login.php">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required autofocus>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;">Log In</button>
            </form>
        </div>
    </div>
</body>
</html>