<?php
/**
 * api/departments.php
 * ------------------------------------------------------------
 * GET    /api/departments.php       -> list all (with manager name + employee count)
 * GET    /api/departments.php?id=5  -> single department
 * POST   /api/departments.php       -> create
 * PUT    /api/departments.php?id=5  -> update
 * DELETE /api/departments.php?id=5  -> delete
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();

$method = $_SERVER['REQUEST_METHOD'];
$id = get_id_param();

const LIST_SQL = "
    SELECT
        d.id, d.name, d.manager_id, d.budget,
        (e.first_name || ' ' || e.last_name) AS manager_name,
        (SELECT COUNT(*) FROM employees WHERE department_id = d.id) AS employee_count
    FROM departments d
    LEFT JOIN employees e ON e.id = d.manager_id
";

switch ($method) {
    case 'GET':
        if ($id !== null) {
            $dept = db_fetch_one(LIST_SQL . ' WHERE d.id = :id', ['id' => $id]);
            if (!$dept) {
                json_response(false, null, 'Department not found.', 404);
            }
            json_response(true, $dept);
        }
        $rows = db_fetch_all(LIST_SQL . ' ORDER BY d.name ASC');
        json_response(true, $rows);
        break;

    case 'POST':
        $input = get_json_body();
        require_fields($input, ['name', 'budget']);

        try {
            global $pdo;
            // No RETURNING support — insert, then re-select via lastInsertId().
            $stmt = $pdo->prepare(
                'INSERT INTO departments (name, manager_id, budget)
                 VALUES (:name, :manager_id, :budget)'
            );
            $stmt->execute([
                'name'       => $input['name'],
                'manager_id' => $input['manager_id'] ?? null,
                'budget'     => $input['budget'],
            ]);
            $newId = $pdo->lastInsertId();
            $dept = db_fetch_one(LIST_SQL . ' WHERE d.id = :id', ['id' => $newId]);

            log_activity($pdo, "Added department {$input['name']}");
            json_response(true, $dept, null, 201);
        } catch (PDOException $e) {
            handle_db_error($e);
        }
        break;

    case 'PUT':
        if ($id === null) {
            json_response(false, null, 'A valid ?id= is required.', 400);
        }
        $input = get_json_body();
        require_fields($input, ['name', 'budget']);

        try {
            global $pdo;
            $stmt = $pdo->prepare(
                'UPDATE departments SET name = :name, manager_id = :manager_id, budget = :budget
                 WHERE id = :id'
            );
            $stmt->execute([
                'name'       => $input['name'],
                'manager_id' => $input['manager_id'] ?? null,
                'budget'     => $input['budget'],
                'id'         => $id,
            ]);

            if ($stmt->rowCount() === 0) {
                json_response(false, null, 'Department not found.', 404);
            }

            $dept = db_fetch_one(LIST_SQL . ' WHERE d.id = :id', ['id' => $id]);
            log_activity($pdo, "Updated department {$input['name']}");
            json_response(true, $dept);
        } catch (PDOException $e) {
            handle_db_error($e);
        }
        break;

    case 'DELETE':
        if ($id === null) {
            json_response(false, null, 'A valid ?id= is required.', 400);
        }
        global $pdo;
        $existing = db_fetch_one('SELECT * FROM departments WHERE id = :id', ['id' => $id]);
        if (!$existing) {
            json_response(false, null, 'Department not found.', 404);
        }
        db_execute('DELETE FROM departments WHERE id = :id', ['id' => $id]);
        log_activity($pdo, "Deleted department {$existing['name']}");
        json_response(true, ['id' => $id]);
        break;

    default:
        json_response(false, null, 'Method not allowed.', 405);
}

function handle_db_error(PDOException $e): void
{
    // SQLite/PDO reports unique-constraint violations as SQLSTATE 23000
    // (generic), unlike Postgres's specific 23505 — check the message too.
    if ($e->getCode() === '23000' && stripos($e->getMessage(), 'UNIQUE') !== false) {
        json_response(false, null, 'A department with that name already exists.', 409);
    }
    error_log('DB error: ' . $e->getMessage());
    json_response(false, null, 'A database error occurred.', 500);
}
