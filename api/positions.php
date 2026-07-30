<?php
/**
 * api/positions.php
 * ------------------------------------------------------------
 * GET    /api/positions.php       -> list all (with department name + employee count)
 * GET    /api/positions.php?id=5  -> single position
 * POST   /api/positions.php       -> create
 * PUT    /api/positions.php?id=5  -> update
 * DELETE /api/positions.php?id=5  -> delete
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();

$method = $_SERVER['REQUEST_METHOD'];
$id = get_id_param();

const LIST_SQL = "
    SELECT
        p.id, p.title, p.department_id, p.base_salary, p.description,
        d.name AS department_name,
        (SELECT COUNT(*) FROM employees WHERE position_id = p.id) AS employee_count
    FROM positions p
    LEFT JOIN departments d ON d.id = p.department_id
";

switch ($method) {
    case 'GET':
        if ($id !== null) {
            $pos = db_fetch_one(LIST_SQL . ' WHERE p.id = :id', ['id' => $id]);
            if (!$pos) {
                json_response(false, null, 'Position not found.', 404);
            }
            json_response(true, $pos);
        }
        $rows = db_fetch_all(LIST_SQL . ' ORDER BY p.title ASC');
        json_response(true, $rows);
        break;

    case 'POST':
        $input = get_json_body();
        require_fields($input, ['title', 'base_salary']);

        try {
            // No RETURNING — insert, then re-select via lastInsertId().
            global $pdo;
            $stmt = $pdo->prepare(
                'INSERT INTO positions (title, department_id, base_salary, description)
                 VALUES (:title, :department_id, :base_salary, :description)'
            );
            $stmt->execute([
                'title'         => $input['title'],
                'department_id' => $input['department_id'] ?? null,
                'base_salary'   => $input['base_salary'],
                'description'   => $input['description'] ?? null,
            ]);
            $newId = $pdo->lastInsertId();
            $pos = db_fetch_one(LIST_SQL . ' WHERE p.id = :id', ['id' => $newId]);

            log_activity($pdo, "Added position {$input['title']}");
            json_response(true, $pos, null, 201);
        } catch (PDOException $e) {
            handle_db_error($e);
        }
        break;

    case 'PUT':
        if ($id === null) {
            json_response(false, null, 'A valid ?id= is required.', 400);
        }
        $input = get_json_body();
        require_fields($input, ['title', 'base_salary']);

        try {
            global $pdo;
            $stmt = $pdo->prepare(
                'UPDATE positions SET title = :title, department_id = :department_id,
                    base_salary = :base_salary, description = :description
                 WHERE id = :id'
            );
            $stmt->execute([
                'title'         => $input['title'],
                'department_id' => $input['department_id'] ?? null,
                'base_salary'   => $input['base_salary'],
                'description'   => $input['description'] ?? null,
                'id'            => $id,
            ]);

            if ($stmt->rowCount() === 0) {
                json_response(false, null, 'Position not found.', 404);
            }

            $pos = db_fetch_one(LIST_SQL . ' WHERE p.id = :id', ['id' => $id]);
            log_activity($pdo, "Updated position {$input['title']}");
            json_response(true, $pos);
        } catch (PDOException $e) {
            handle_db_error($e);
        }
        break;

    case 'DELETE':
        if ($id === null) {
            json_response(false, null, 'A valid ?id= is required.', 400);
        }
        global $pdo;
        $existing = db_fetch_one('SELECT * FROM positions WHERE id = :id', ['id' => $id]);
        if (!$existing) {
            json_response(false, null, 'Position not found.', 404);
        }
        db_execute('DELETE FROM positions WHERE id = :id', ['id' => $id]);
        log_activity($pdo, "Deleted position {$existing['title']}");
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
        json_response(false, null, 'That position already exists for this department.', 409);
    }
    error_log('DB error: ' . $e->getMessage());
    json_response(false, null, 'A database error occurred.', 500);
}
