<?php
/**
 * db.php
 * ------------------------------------------------------------
 * PDO connection to the SQLite database for the
 * Employee Management System (see schema_sqlite.sql).
 *
 * Include this file wherever you need database access:
 *   require_once 'db.php';
 *   $stmt = $pdo->query('SELECT * FROM employees');
 * ------------------------------------------------------------
 */

// ------------------------------------------------------------
// CONFIGURATION
// SQLite has no host/port/user/password — it's just a file path.
// Prefer an environment variable so the path isn't hardcoded,
// but default to a sensible location outside the web root.
// ------------------------------------------------------------
$dbPath = getenv('DB_PATH') ?: __DIR__ . '/data/employee_management.sqlite';

// ------------------------------------------------------------
// CONNECT
// ------------------------------------------------------------
$dsn = "sqlite:{$dbPath}";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    // SQLite's DSN takes no user/pass — pass null for both.
    $pdo = new PDO($dsn, null, null, $options);

    // SQLite does not enforce foreign keys unless told to,
    // and this must be set on every new connection/request.
    $pdo->exec('PRAGMA foreign_keys = ON');
} catch (PDOException $e) {
    // Never leak connection details in the response.
    http_response_code(500);
    error_log('Database connection failed: ' . $e->getMessage());
    die(json_encode([
        'success' => false,
        'error'   => 'Database connection failed.'
    ]));
}

/**
 * Optional helper: run a prepared statement and return all rows.
 *
 * @param string $sql
 * @param array  $params
 * @return array
 */
function db_fetch_all(string $sql, array $params = []): array
{
    global $pdo;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Optional helper: run a prepared statement and return one row
 * (or null if there are no results).
 *
 * @param string $sql
 * @param array  $params
 * @return array|null
 */
function db_fetch_one(string $sql, array $params = []): ?array
{
    global $pdo;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * Optional helper: run an INSERT/UPDATE/DELETE statement.
 * Returns the number of affected rows.
 *
 * @param string $sql
 * @param array  $params
 * @return int
 */
function db_execute(string $sql, array $params = []): int
{
    global $pdo;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}
