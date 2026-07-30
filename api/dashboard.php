<?php
/**
 * api/dashboard.php
 * ------------------------------------------------------------
 * GET /api/dashboard.php -> aggregated counts + recent activity
 * for the Dashboard section. Read-only.
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(false, null, 'Method not allowed.', 405);
}

$totalEmployees  = db_fetch_one('SELECT COUNT(*) AS count FROM employees')['count'];
$activeEmployees = db_fetch_one("SELECT COUNT(*) AS count FROM employees WHERE status = 'active'")['count'];
$totalDepartments = db_fetch_one('SELECT COUNT(*) AS count FROM departments')['count'];
$totalPositions   = db_fetch_one('SELECT COUNT(*) AS count FROM positions')['count'];
$pendingLeaves    = db_fetch_one("SELECT COUNT(*) AS count FROM leaves WHERE status = 'pending'")['count'];

$recentActivity = db_fetch_all(
    'SELECT message, created_at FROM activity_log ORDER BY created_at DESC LIMIT 8'
);

json_response(true, [
    'total_employees'   => (int) $totalEmployees,
    'active_employees'  => (int) $activeEmployees,
    'total_departments' => (int) $totalDepartments,
    'total_positions'   => (int) $totalPositions,
    'pending_leaves'    => (int) $pendingLeaves,
    'recent_activity'   => $recentActivity,
]);
