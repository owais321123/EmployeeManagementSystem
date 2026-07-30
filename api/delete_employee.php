<?php
/**
 * api/delete_employee.php
 * ------------------------------------------------------------
 * POST/DELETE /api/delete_employee.php?id=5
 * (POST is also accepted, since plain HTML forms/links can't
 * send a DELETE request without JavaScript.)
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();

if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'DELETE'], true)) {
    json_response(false, null, 'Method not allowed. Use POST or DELETE.', 405);
}

$id = get_id_param();
if ($id === null) {
    json_response(false, null, 'A valid ?id= is required.', 400);
}

$existing = db_fetch_one('SELECT * FROM employees WHERE id = :id', ['id' => $id]);
if (!$existing) {
    json_response(false, null, 'Employee not found.', 404);
}

db_execute('DELETE FROM employees WHERE id = :id', ['id' => $id]);

log_activity($pdo, "Deleted employee {$existing['first_name']} {$existing['last_name']}");
json_response(true, ['id' => $id]);
