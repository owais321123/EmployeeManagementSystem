<?php
/**
 * api/leave_requests.php
 * ------------------------------------------------------------
 * Backed by the `leaves` table from schema.sql.
 *
 * GET    /api/leave_requests.php       -> list all (with employee name)
 * GET    /api/leave_requests.php?id=5  -> single leave request
 * POST   /api/leave_requests.php       -> create
 * PUT    /api/leave_requests.php?id=5  -> update
 * DELETE /api/leave_requests.php?id=5  -> delete
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();

$method = $_SERVER['REQUEST_METHOD'];
$id = get_id_param();

const LIST_SQL = "
    SELECT
        l.*,
        (e.first_name || ' ' || e.last_name) AS employee_name
    FROM leaves l
    LEFT JOIN employees e ON e.id = l.employee_id
";

switch ($method) {
    case 'GET':
        if ($id !== null) {
            $row = db_fetch_one(LIST_SQL . ' WHERE l.id = :id', ['id' => $id]);
            if (!$row) {
                json_response(false, null, 'Leave request not found.', 404);
            }
            json_response(true, $row);
        }
        $rows = db_fetch_all(LIST_SQL . ' ORDER BY l.start_date DESC, l.id DESC');
        json_response(true, $rows);
        break;

    case 'POST':
        $input = get_json_body();
        require_fields($input, ['employee_id', 'leave_type', 'start_date', 'end_date', 'reason', 'status']);

        if (strtotime($input['end_date']) < strtotime($input['start_date'])) {
            json_response(false, null, 'End date cannot be before start date.', 422);
        }

        try {
            // No RETURNING — insert, then re-select via lastInsertId().
            // Note: `days` is a generated column computed from start_date/
            // end_date, so it only comes back correct once re-selected —
            // never assumed from the input.
            global $pdo;
            $stmt = $pdo->prepare(
                'INSERT INTO leaves (employee_id, leave_type, start_date, end_date, reason, status)
                 VALUES (:employee_id, :leave_type, :start_date, :end_date, :reason, :status)'
            );
            $stmt->execute([
                'employee_id' => $input['employee_id'],
                'leave_type'  => $input['leave_type'],
                'start_date'  => $input['start_date'],
                'end_date'    => $input['end_date'],
                'reason'      => $input['reason'],
                'status'      => $input['status'],
            ]);
            $newId = $pdo->lastInsertId();
            $row = db_fetch_one(LIST_SQL . ' WHERE l.id = :id', ['id' => $newId]);

            log_activity($pdo, 'Added a leave request');
            json_response(true, $row, null, 201);
        } catch (PDOException $e) {
            handle_db_error($e);
        }
        break;

    case 'PUT':
        if ($id === null) {
            json_response(false, null, 'A valid ?id= is required.', 400);
        }
        $input = get_json_body();
        require_fields($input, ['employee_id', 'leave_type', 'start_date', 'end_date', 'reason', 'status']);

        if (strtotime($input['end_date']) < strtotime($input['start_date'])) {
            json_response(false, null, 'End date cannot be before start date.', 422);
        }

        try {
            global $pdo;
            $stmt = $pdo->prepare(
                'UPDATE leaves SET
                    employee_id = :employee_id, leave_type = :leave_type,
                    start_date = :start_date, end_date = :end_date,
                    reason = :reason, status = :status
                 WHERE id = :id'
            );
            $stmt->execute([
                'employee_id' => $input['employee_id'],
                'leave_type'  => $input['leave_type'],
                'start_date'  => $input['start_date'],
                'end_date'    => $input['end_date'],
                'reason'      => $input['reason'],
                'status'      => $input['status'],
                'id'          => $id,
            ]);

            if ($stmt->rowCount() === 0) {
                json_response(false, null, 'Leave request not found.', 404);
            }

            $row = db_fetch_one(LIST_SQL . ' WHERE l.id = :id', ['id' => $id]);
            log_activity($pdo, 'Updated a leave request');
            json_response(true, $row);
        } catch (PDOException $e) {
            handle_db_error($e);
        }
        break;

    case 'DELETE':
        if ($id === null) {
            json_response(false, null, 'A valid ?id= is required.', 400);
        }
        global $pdo;
        $existing = db_fetch_one('SELECT * FROM leaves WHERE id = :id', ['id' => $id]);
        if (!$existing) {
            json_response(false, null, 'Leave request not found.', 404);
        }
        db_execute('DELETE FROM leaves WHERE id = :id', ['id' => $id]);
        log_activity($pdo, 'Deleted a leave request');
        json_response(true, ['id' => $id]);
        break;

    default:
        json_response(false, null, 'Method not allowed.', 405);
}

function handle_db_error(PDOException $e): void
{
    error_log('DB error: ' . $e->getMessage());
    json_response(false, null, 'A database error occurred.', 500);
}
