<?php
/**
 * api/search_employee.php
 * ------------------------------------------------------------
 * GET /api/search_employee.php?q=jane&page=1&per_page=10
 *
 * Searches by name, email, or phone. Omit ?q= to just list
 * everyone (paginated).
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(false, null, 'Method not allowed. Use GET.', 405);
}

$query = trim($_GET['q'] ?? '');

$page    = isset($_GET['page']) && ctype_digit($_GET['page']) ? (int) $_GET['page'] : 1;
$perPage = isset($_GET['per_page']) && ctype_digit($_GET['per_page']) ? (int) $_GET['per_page'] : 10;
$perPage = min(max($perPage, 1), 100);
$offset  = ($page - 1) * $perPage;

$where  = '';
$params = [];
if ($query !== '') {
    // SQLite has no ILIKE — its plain LIKE is already
    // case-insensitive for ASCII text, so this is a direct swap.
    $where = "WHERE (e.first_name || ' ' || e.last_name) LIKE :q
              OR e.email LIKE :q
              OR e.phone LIKE :q
              OR d.name LIKE :q
              OR p.title LIKE :q";
    $params['q'] = '%' . $query . '%';
}

$baseSql = "
    FROM employees e
    LEFT JOIN departments d ON d.id = e.department_id
    LEFT JOIN positions p ON p.id = e.position_id
    $where
";

$total = db_fetch_one("SELECT COUNT(*) AS count $baseSql", $params)['count'];

$sql = "
    SELECT e.*, d.name AS department_name, p.title AS position_title
    $baseSql
    ORDER BY e.id DESC
    LIMIT :limit OFFSET :offset
";
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
    'query'       => $query,
    'page'        => $page,
    'per_page'    => $perPage,
    'total'       => (int) $total,
    'total_pages' => (int) ceil($total / $perPage),
]);
