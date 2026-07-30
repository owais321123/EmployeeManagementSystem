<?php
/**
 * create_admin.php
 * ------------------------------------------------------------
 * ONE-TIME USE: creates the first admin login.
 * Visit this file once in your browser, then DELETE it —
 * leaving it on a live server would let anyone create logins.
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/db.php';

$username = 'admin';
$password = 'admin123';   // change this to whatever you want, then delete this file after use
$role     = 'admin';

$hash = password_hash($password, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (:username, :hash, :role)');
    $stmt->execute([
        'username' => $username,
        'hash'     => $hash,
        'role'     => $role,
    ]);
    echo "Admin user created successfully.<br>";
    echo "Username: $username<br>";
    echo "Password: $password<br><br>";
    echo "<strong>Now delete create_admin.php from your project folder.</strong>";
} catch (PDOException $e) {
    if ($e->getCode() === '23000' && stripos($e->getMessage(), 'UNIQUE') !== false) {
        echo "A user with that username already exists. Nothing was changed.";
    } else {
        echo "Error: " . htmlspecialchars($e->getMessage());
    }
}