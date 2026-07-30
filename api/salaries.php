<?php
/**
 * api/salaries.php
 * ------------------------------------------------------------
 * GET    /api/salaries.php       -> list all (with employee + position names)
 * GET    /api/salaries.php?id=5  -> single salary record
 * POST   /api/salaries.php       -> create
 * PUT    /api/salaries.php?id=5  -> update
 * DELETE /api/salaries.php?id=5  -> delete
 *
 * net_salary is a generated column in the database — never sent
 * or updated directly, it's computed automatically by SQLite.
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();

$method = $_SERVER['REQUEST_METHOD'];
$id = get_id_param();

const LIST_SQL = "
    SELECT
        s.*,
        (e.first_name || ' ' || e.last_name) AS employee_name,
        pos.title AS position_title
    FROM salaries s
    LEFT JOIN employees e ON e.id = s.employee_id
    LEFT JOIN positions pos ON pos.id = e.position_id
";

switch ($method) {
    case 'GET':
        if ($id !== null) {
            $sal = db_fetch_one(LIST_SQL . ' WHERE s.id = :id', ['id' => $id]);
            if (!$sal) {
                json_response(false, null, 'Salary record not found.', 404);
            }
            json_response(true, $sal);
        }
        $rows = db_fetch_all(LIST_SQL . ' ORDER BY s.pay_month DESC, s.id DESC');
        json_response(true, $rows);
        break;

    case 'POST':
        $input = get_json_body();
        require_fields($input, ['employee_id', 'pay_month', 'status']);

        try {
            // No RETURNING — insert, then re-select via lastInsertId().
            global $pdo;
            $stmt = $pdo->prepare(
                'INSERT INTO salaries
                    (employee_id, basic_salary, position_salary, allowances, deductions, pay_month, status, notes)
                 VALUES
                    (:employee_id, :basic_salary, :position_salary, :allowances, :deductions, :pay_month, :status, :notes)'
            );
            $stmt->execute([
                'employee_id'     => $input['employee_id'],
                'basic_salary'    => $input['basic_salary'] ?? 0,
                'position_salary' => $input['position_salary'] ?? 0,
                'allowances'      => $input['allowances'] ?? 0,
                'deductions'      => $input['deductions'] ?? 0,
                'pay_month'       => $input['pay_month'],
                'status'          => $input['status'],
                'notes'           => $input['notes'] ?? null,
            ]);
            $newId = $pdo->lastInsertId();
            $sal = db_fetch_one(LIST_SQL . ' WHERE s.id = :id', ['id' => $newId]);

            log_activity($pdo, 'Added a salary record');
            json_response(true, $sal, null, 201);
        } catch (PDOException $e) {
            handle_db_error($e);
        }
        break;

    case 'PUT':
        if ($id === null) {
            json_response(false, null, 'A valid ?id= is required.', 400);
        }
        $input = get_json_body();
        require_fields($input, ['employee_id', 'pay_month', 'status']);

        try {
            global $pdo;
            $stmt = $pdo->prepare(
                'UPDATE salaries SET
                    employee_id = :employee_id, basic_salary = :basic_salary,
                    position_salary = :position_salary, allowances = :allowances,
                    deductions = :deductions, pay_month = :pay_month,
                    status = :status, notes = :notes
                 WHERE id = :id'
            );
            $stmt->execute([
                'employee_id'     => $input['employee_id'],
                'basic_salary'    => $input['basic_salary'] ?? 0,
                'position_salary' => $input['position_salary'] ?? 0,
                'allowances'      => $input['allowances'] ?? 0,
                'deductions'      => $input['deductions'] ?? 0,
                'pay_month'       => $input['pay_month'],
                'status'          => $input['status'],
                'notes'           => $input['notes'] ?? null,
                'id'              => $id,
            ]);

            if ($stmt->rowCount() === 0) {
                json_response(false, null, 'Salary record not found.', 404);
            }

            $sal = db_fetch_one(LIST_SQL . ' WHERE s.id = :id', ['id' => $id]);
            log_activity($pdo, 'Updated a salary record');
            json_response(true, $sal);
        } catch (PDOException $e) {
            handle_db_error($e);
        }
        break;

    case 'DELETE':
        if ($id === null) {
            json_response(false, null, 'A valid ?id= is required.', 400);
        }
        global $pdo;
        $existing = db_fetch_one('SELECT * FROM salaries WHERE id = :id', ['id' => $id]);
        if (!$existing) {
            json_response(false, null, 'Salary record not found.', 404);
        }
        db_execute('DELETE FROM salaries WHERE id = :id', ['id' => $id]);
        log_activity($pdo, 'Deleted a salary record');
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
        json_response(false, null, 'A salary record for this employee and month already exists.', 409);
    }
    error_log('DB error: ' . $e->getMessage());
    json_response(false, null, 'A database error occurred.', 500);
}
