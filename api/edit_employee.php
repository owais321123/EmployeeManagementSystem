<?php
/**
 * api/edit_employee.php
 * ------------------------------------------------------------
 * POST/PUT /api/edit_employee.php?id=5
 * Accepts either JSON body or standard form-encoded POST data.
 * (POST is also accepted, since plain HTML forms can't send PUT.)
 *
 * Required: id (query string), first_name, last_name, email,
 *           phone, hire_date, status
 * Optional: department_id, position_id, salary
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();

if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT'], true)) {
    json_response(false, null, 'Method not allowed. Use POST or PUT.', 405);
}

$id = get_id_param();
if ($id === null) {
    json_response(false, null, 'A valid ?id= is required.', 400);
}

$input = get_json_body();
if (empty($input)) {
    $input = $_POST;
}

require_fields($input, ['first_name', 'last_name', 'email', 'phone', 'hire_date', 'status']);

try {
    // No RETURNING — update, check rowCount(), then re-select.
    $stmt = $pdo->prepare(
        'UPDATE employees SET
            first_name = :first_name,
            last_name = :last_name,
            email = :email,
            phone = :phone,
            department_id = :department_id,
            position_id = :position_id,
            hire_date = :hire_date,
            salary = :salary,
            status = :status
         WHERE id = :id'
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
        'id'            => $id,
    ]);

    if ($stmt->rowCount() === 0) {
        json_response(false, null, 'Employee not found.', 404);
    }

    $employee = db_fetch_one('SELECT * FROM employees WHERE id = :id', ['id' => $id]);

    log_activity($pdo, "Updated employee {$input['first_name']} {$input['last_name']}");
    json_response(true, $employee);
} catch (PDOException $e) {
    // SQLite/PDO reports unique-constraint violations as SQLSTATE 23000
    // (generic), unlike Postgres's specific 23505 — check the message too.
    if ($e->getCode() === '23000' && stripos($e->getMessage(), 'UNIQUE') !== false) {
        json_response(false, null, 'A record with that email already exists.', 409);
    }
    error_log('DB error: ' . $e->getMessage());
    json_response(false, null, 'A database error occurred.', 500);
}
