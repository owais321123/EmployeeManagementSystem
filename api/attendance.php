<?php
/**
 * api/attendance.php
 * ------------------------------------------------------------
 * GET    /api/attendance.php                    -> list (supports ?employee_id=&date=)
 * GET    /api/attendance.php?id=5                -> single record
 * POST   /api/attendance.php                     -> create (mark attendance)
 * PUT    /api/attendance.php?id=5                -> update
 * DELETE /api/attendance.php?id=5                -> delete
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();

$method = $_SERVER['REQUEST_METHOD'];
$id = get_id_param();

const LIST_SQL = "
    SELECT
        a.*,
        (e.first_name || ' ' || e.last_name) AS employee_name
    FROM attendance a
    LEFT JOIN employees e ON e.id = a.employee_id
";

switch ($method) {
    case 'GET':
        if ($id !== null) {
            $row = db_fetch_one(LIST_SQL . ' WHERE a.id = :id', ['id' => $id]);
            if (!$row) {
                json_response(false, null, 'Attendance record not found.', 404);
            }
            json_response(true, $row);
        }

        $where  = [];
        $params = [];
        if (!empty($_GET['employee_id']) && ctype_digit($_GET['employee_id'])) {
            $where[] = 'a.employee_id = :employee_id';
            $params['employee_id'] = (int) $_GET['employee_id'];
        }
        if (!empty($_GET['date'])) {
            $where[] = 'a.attendance_date = :date';
            $params['date'] = $_GET['date'];
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $rows = db_fetch_all(LIST_SQL . " $whereSql ORDER BY a.attendance_date DESC, a.id DESC", $params);
        json_response(true, $rows);
        break;

    case 'POST':
        $input = get_json_body();
        require_fields($input, ['employee_id', 'attendance_date', 'status']);

        try {
            global $pdo;
            // SQLite's PDO driver does not reliably support RETURNING —
            // insert, then re-select by lastInsertId() instead.
            $stmt = $pdo->prepare(
                'INSERT INTO attendance (employee_id, attendance_date, check_in, check_out, status)
                 VALUES (:employee_id, :attendance_date, :check_in, :check_out, :status)'
            );
            $stmt->execute([
                'employee_id'     => $input['employee_id'],
                'attendance_date' => $input['attendance_date'],
                'check_in'        => $input['check_in'] ?? null,
                'check_out'       => $input['check_out'] ?? null,
                'status'          => $input['status'],
            ]);
            $newId = $pdo->lastInsertId();
            $row = db_fetch_one(LIST_SQL . ' WHERE a.id = :id', ['id' => $newId]);

            log_activity($pdo, 'Marked attendance for an employee');
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
        require_fields($input, ['employee_id', 'attendance_date', 'status']);

        try {
            global $pdo;
            // No RETURNING — update, check rowCount(), then re-select.
            $stmt = $pdo->prepare(
                'UPDATE attendance SET
                    employee_id = :employee_id, attendance_date = :attendance_date,
                    check_in = :check_in, check_out = :check_out, status = :status
                 WHERE id = :id'
            );
            $stmt->execute([
                'employee_id'     => $input['employee_id'],
                'attendance_date' => $input['attendance_date'],
                'check_in'        => $input['check_in'] ?? null,
                'check_out'       => $input['check_out'] ?? null,
                'status'          => $input['status'],
                'id'              => $id,
            ]);

            if ($stmt->rowCount() === 0) {
                json_response(false, null, 'Attendance record not found.', 404);
            }

            $row = db_fetch_one(LIST_SQL . ' WHERE a.id = :id', ['id' => $id]);
            log_activity($pdo, 'Updated an attendance record');
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
        $existing = db_fetch_one('SELECT * FROM attendance WHERE id = :id', ['id' => $id]);
        if (!$existing) {
            json_response(false, null, 'Attendance record not found.', 404);
        }
        db_execute('DELETE FROM attendance WHERE id = :id', ['id' => $id]);
        log_activity($pdo, 'Deleted an attendance record');
        json_response(true, ['id' => $id]);
        break;

    default:
        json_response(false, null, 'Method not allowed.', 405);
}

function handle_db_error(PDOException $e): void
{
    // SQLite/PDO reports unique-constraint violations as SQLSTATE 23000
    // (a generic "constraint violation" code, unlike Postgres's specific
    // 23505), so the message text has to be checked too.
    if ($e->getCode() === '23000' && stripos($e->getMessage(), 'UNIQUE') !== false) {
        json_response(false, null, 'Attendance for this employee on this date already exists.', 409);
    }
    error_log('DB error: ' . $e->getMessage());
    json_response(false, null, 'A database error occurred.', 500);
}
