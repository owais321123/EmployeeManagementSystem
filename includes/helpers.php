<?php
/**
 * includes/helpers.php
 * ------------------------------------------------------------
 * Shared helpers for every file under /api, plus login.php and
 * logout.php. Include this AFTER db.php:
 *
 *   require_once __DIR__ . '/../db.php';
 *   require_once __DIR__ . '/../includes/helpers.php';
 * ------------------------------------------------------------
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Send a JSON response and stop execution.
 */
function json_response(bool $success, $data = null, ?string $error = null, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'data'    => $data,
        'error'   => $error,
    ]);
    exit;
}

/**
 * Parse a JSON request body (used for POST / PUT) into an
 * associative array. Returns [] if the body is empty or invalid.
 */
function get_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Halt the request with 401 unless a user is logged in.
 */
function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        json_response(false, null, 'Unauthorized. Please log in.', 401);
    }
}

/**
 * Halt the request with 403 unless the logged-in user has one
 * of the given roles. Call require_login() first.
 */
function require_role(array $roles): void
{
    if (empty($_SESSION['role']) || !in_array($_SESSION['role'], $roles, true)) {
        json_response(false, null, 'Forbidden. You do not have permission to do this.', 403);
    }
}

/**
 * Make sure every key in $required is present and non-empty
 * in $input. On failure, responds 422 and stops execution.
 */
function require_fields(array $input, array $required): void
{
    $missing = [];
    foreach ($required as $field) {
        if (!array_key_exists($field, $input) || $input[$field] === '' || $input[$field] === null) {
            $missing[] = $field;
        }
    }
    if (!empty($missing)) {
        json_response(false, null, 'Missing required field(s): ' . implode(', ', $missing), 422);
    }
}

/**
 * Record an entry in activity_log (feeds api/dashboard.php).
 */
function log_activity(PDO $pdo, string $message): void
{
    $stmt = $pdo->prepare('INSERT INTO activity_log (message) VALUES (:message)');
    $stmt->execute(['message' => $message]);
}

/**
 * Read the numeric ?id= query param, or null if absent/invalid.
 */
function get_id_param(): ?int
{
    if (!isset($_GET['id']) || !ctype_digit((string) $_GET['id'])) {
        return null;
    }
    return (int) $_GET['id'];
}
