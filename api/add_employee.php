<?php
/**
 * api/add_employee.php
 * ------------------------------------------------------------
 * POST /api/add_employee.php
 * Accepts either JSON body or standard form-encoded POST data.
 *
 * Required: first_name, last_name, email, phone, hire_date, status
 * Optional: department_id, position_id, salary
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, null, 'Method not allowed. Use POST.', 405);
}

// Accept JSON body, fall back to regular form POST data.
$input = get_json_body();
if (empty($input)) {
    $input = $_POST;
}

require_fields($input, ['first_name', 'last_name', 'email', 'phone', 'hire_date', 'status']);

try {
    // SQLite's PDO driver does not reliably support RETURNING —
    // insert, then re-select the new row by lastInsertId().
    $stmt = $pdo->prepare(
        'INSERT INTO employees
            (first_name, last_name, email, phone, department_id, position_id, hire_date, salary, status)
         VALUES
            (:first_name, :last_name, :email, :phone, :department_id, :position_id, :hire_date, :salary, :status)'
    );
    $stmt->execute([
        'first_name'    => trim($input['first_name']),
        'last_name'     => trim($input['last_name']),
        'email'         => trim($input['email']),
        'phone'         => trim($input['phone']),
        'department_id' => $input['department_id'] ?? null,
        'position_id'   => $input['position_id'] ?? null,
        'hire_date'     => $input['hire_date'],
        'salary'        => $input['salary'] ?? 0,
        'status'        => $input['status'],
    ]);

    $newId = $pdo->lastInsertId();
    $employee = db_fetch_one('SELECT * FROM employees WHERE id = :id', ['id' => $newId]);

    log_activity($pdo, "Added employee {$input['first_name']} {$input['last_name']}");
    json_response(true, $employee, null, 201);
} catch (PDOException $e) {
    // SQLite/PDO reports unique-constraint violations as SQLSTATE 23000
    // (generic "constraint violation"), unlike Postgres's specific 23505,
    // so the message text has to be checked too.
    if ($e->getCode() === '23000' && stripos($e->getMessage(), 'UNIQUE') !== false) {
        json_response(false, null, 'A record with that email already exists.', 409);
    }
    error_log('DB error: ' . $e->getMessage());
    json_response(false, null, 'A database error occurred.', 500);
}
