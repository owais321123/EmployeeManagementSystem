<?php
/**
 * api/employees.php
 * ------------------------------------------------------------
 * GET    /api/employees.php              -> list (supports ?search=&page=&per_page=)
 * GET    /api/employees.php?id=5         -> single employee
 * POST   /api/employees.php              -> create
 * PUT    /api/employees.php?id=5         -> update
 * DELETE /api/employees.php?id=5         -> delete
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();

$method = $_SERVER['REQUEST_METHOD'];
$id = get_id_param();

switch ($method) {
    case 'GET':
        if ($id !== null) {
            $employee = db_fetch_one('SELECT * FROM employees WHERE id = :id', ['id' => $id]);
            if (!$employee) {
                json_response(false, null, 'Employee not found.', 404);
            }
            json_response(true, $employee);
        }

        $page    = isset($_GET['page']) && ctype_digit($_GET['page']) ? (int) $_GET['page'] : 1;
        $perPage = isset($_GET['per_page']) && ctype_digit($_GET['per_page']) ? (int) $_GET['per_page'] : 10;
        $perPage = min(max($perPage, 1), 100);
        $offset  = ($page - 1) * $perPage;

        $search = trim($_GET['search'] ?? '');
        $where  = '';
        $params = [];
        if ($search !== '') {
            // SQLite has no ILIKE — its plain LIKE is already
            // case-insensitive for ASCII text, so this is a direct swap.
            $where = "WHERE (first_name || ' ' || last_name) LIKE :search
                      OR email LIKE :search
                      OR phone LIKE :search";
            $params['search'] = '%' . $search . '%';
        }

        $total = db_fetch_one("SELECT COUNT(*) AS count FROM employees $where", $params)['count'];

        $sql = "SELECT * FROM employees $where ORDER BY id DESC LIMIT :limit OFFSET :offset";
        global $pdo;
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue(":$key", $val);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        json_response(true, [
            'items'       => $rows,
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => (int) $total,
            'total_pages' => (int) ceil($total / $perPage),
        ]);
        break;

    case 'POST':
        $input = get_json_body();
        require_fields($input, ['first_name', 'last_name', 'email', 'phone', 'hire_date', 'status']);

        try {
            // No RETURNING — insert, then re-select via lastInsertId().
            $sql = 'INSERT INTO employees
                        (first_name, last_name, email, phone, department_id, position_id, hire_date, salary, status)
                    VALUES
                        (:first_name, :last_name, :email, :phone, :department_id, :position_id, :hire_date, :salary, :status)';
            global $pdo;
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'first_name'    => $input['first_name'],
                'last_name'     => $input['last_name'],
                'email'         => $input['email'],
                'phone'         => $input['phone'],
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
            handle_db_error($e);
        }
        break;

    case 'PUT':
        if ($id === null) {
            json_response(false, null, 'A valid ?id= is required.', 400);
        }
        $input = get_json_body();
        require_fields($input, ['first_name', 'last_name', 'email', 'phone', 'hire_date', 'status']);

        try {
            $sql = 'UPDATE employees SET
                        first_name = :first_name,
                        last_name = :last_name,
                        email = :email,
                        phone = :phone,
                        department_id = :department_id,
                        position_id = :position_id,
                        hire_date = :hire_date,
                        salary = :salary,
                        status = :status
                    WHERE id = :id';
            global $pdo;
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'first_name'    => $input['first_name'],
                'last_name'     => $input['last_name'],
                'email'         => $input['email'],
                'phone'         => $input['phone'],
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
            handle_db_error($e);
        }
        break;

    case 'DELETE':
        if ($id === null) {
            json_response(false, null, 'A valid ?id= is required.', 400);
        }
        global $pdo;
        $existing = db_fetch_one('SELECT * FROM employees WHERE id = :id', ['id' => $id]);
        if (!$existing) {
            json_response(false, null, 'Employee not found.', 404);
        }
        db_execute('DELETE FROM employees WHERE id = :id', ['id' => $id]);
        log_activity($pdo, "Deleted employee {$existing['first_name']} {$existing['last_name']}");
        json_response(true, ['id' => $id]);
        break;

    default:
        json_response(false, null, 'Method not allowed.', 405);
}

/**
 * Turn common constraint violations into friendly errors.
 */
function handle_db_error(PDOException $e): void
{
    // SQLite/PDO reports unique-constraint violations as SQLSTATE 23000
    // (generic), unlike Postgres's specific 23505 — check the message too.
    if ($e->getCode() === '23000' && stripos($e->getMessage(), 'UNIQUE') !== false) {
        json_response(false, null, 'A record with that email already exists.', 409);
    }
    error_log('DB error: ' . $e->getMessage());
    json_response(false, null, 'A database error occurred.', 500);
}
