<?php
declare(strict_types=1);

ob_start();

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Auth.php';
require_once dirname(__DIR__) . '/core/ProjectContext.php';

ob_clean();
header('Content-Type: application/json; charset=utf-8');

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e['message']]);
    }
    ob_end_flush();
});

$auth = new NuAuth();
if (!$auth->checkAuth()) {
    ob_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db       = NuDatabase::getInstance();
$action   = $_GET['action'] ?? $_POST['action'] ?? '';
$method   = $_SERVER['REQUEST_METHOD'];
$userRole = strtolower($_SESSION['usr_role'] ?? '');
$isAdmin  = ($userRole === 'globeadmin' || $userRole === 'admin');

// Ensure tables exist
try {
    $db->fetchAll("SELECT 1 FROM nu_report_dashboards LIMIT 1");
} catch (Throwable $t) {
    try {
        $isSqlite = ($db->getDriverName() === 'sqlite');
        if ($isSqlite) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS nu_report_dashboards (
                    dashboard_id INTEGER PRIMARY KEY AUTOINCREMENT,
                    dashboard_code TEXT NOT NULL UNIQUE,
                    dashboard_name TEXT NOT NULL,
                    dashboard_description TEXT,
                    dashboard_active INTEGER DEFAULT 1,
                    dashboard_role_access TEXT,
                    dashboard_filters TEXT,
                    dashboard_reports TEXT,
                    dashboard_created_by TEXT,
                    dashboard_created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    dashboard_updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    project_id INTEGER DEFAULT 1
                );
            ");
        } else {
            $db->exec("
                CREATE TABLE IF NOT EXISTS nu_report_dashboards (
                    dashboard_id INT AUTO_INCREMENT PRIMARY KEY,
                    dashboard_code VARCHAR(50) NOT NULL UNIQUE,
                    dashboard_name VARCHAR(100) NOT NULL,
                    dashboard_description TEXT,
                    dashboard_active TINYINT(1) DEFAULT 1,
                    dashboard_role_access JSON,
                    dashboard_filters JSON,
                    dashboard_reports JSON,
                    dashboard_created_by INT,
                    dashboard_created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    dashboard_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    project_id INT DEFAULT 1
                ) ENGINE=InnoDB;
            ");
        }
    } catch (Throwable $t2) {}
}

// Ensure project_id column exists on nu_report_dashboards
try {
    $cols = [];
    if ($db->getDriverName() === 'sqlite') {
        $info = $db->fetchAll("PRAGMA table_info(nu_report_dashboards)");
        $cols = array_map(fn($c) => $c['name'], $info);
    } else {
        $info = $db->fetchAll("DESCRIBE nu_report_dashboards");
        $cols = array_map(fn($c) => $c['Field'], $info);
    }
    if (!in_array('project_id', $cols, true)) {
        $db->exec("ALTER TABLE nu_report_dashboards ADD COLUMN project_id INT DEFAULT 1");
    }
} catch (Throwable $t3) {}

$body = [];
if ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? [];
    if (empty($body)) $body = $_POST;
}

try {
    switch ($action) {

        // ── list all dashboards ────────────────────────────────────────────────────────────────
        case 'list':
            $pid = ProjectContext::getId();
            $rows = $db->fetchAll(
                "SELECT dashboard_id, dashboard_code, dashboard_name, dashboard_description,
                        dashboard_active, dashboard_role_access,
                        COALESCE(dashboard_updated_at, dashboard_created_at) AS dashboard_created_at
                 FROM nu_report_dashboards
                 WHERE project_id = ?
                 ORDER BY dashboard_name",
                [$pid]
            );

            $visibleDashboards = [];
            foreach ($rows as $r) {
                $roles = [];
                if (isset($r['dashboard_role_access']) && is_string($r['dashboard_role_access'])) {
                    $roles = json_decode($r['dashboard_role_access'], true) ?? [];
                }
                $r['dashboard_role_access'] = $roles;

                // RBAC: Check access permission for this dashboard
                if ($isAdmin || empty($roles) || in_array($userRole, array_map('strtolower', $roles), true)) {
                    $visibleDashboards[] = $r;
                }
            }

            echo json_encode(['success' => true, 'data' => $visibleDashboards]);
            break;

        // ── get single dashboard ──────────────────────────────────────────────────────────
        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) throw new Exception('Missing id');
            $pid = ProjectContext::getId();
            $row = $db->fetchOne(
                "SELECT * FROM nu_report_dashboards WHERE dashboard_id = ? AND project_id = ?", [$id, $pid]
            );
            if (!$row) throw new Exception('Dashboard not found');

            foreach (['dashboard_role_access', 'dashboard_filters', 'dashboard_reports'] as $col) {
                if (isset($row[$col]) && is_string($row[$col])) {
                    $row[$col] = json_decode($row[$col], true) ?? [];
                } else if (!isset($row[$col])) {
                    $row[$col] = [];
                }
            }

            // RBAC: Verify user has permission to read this dashboard
            $roles = $row['dashboard_role_access'] ?? [];
            if (!$isAdmin && !empty($roles) && !in_array($userRole, array_map('strtolower', $roles), true)) {
                throw new Exception('Access denied to this dashboard');
            }

            echo json_encode(['success' => true, 'data' => $row]);
            break;

        // ── save dashboard ───────────────────────────────────────────────────────────
        case 'save':
            if (!$isAdmin) {
                throw new Exception('Unauthorized action: Developer access required');
            }

            $id          = (int)($body['dashboard_id'] ?? 0);
            $name        = trim($body['dashboard_name'] ?? '');
            $code        = trim($body['dashboard_code'] ?? '');
            $desc        = trim($body['dashboard_description'] ?? '');
            $active      = (int)($body['dashboard_active'] ?? 1);
            $rolesAccess = $body['dashboard_role_access'] ?? [];
            $filters     = $body['dashboard_filters'] ?? [];
            $reports     = $body['dashboard_reports'] ?? [];

            if (!$name) throw new Exception('Dashboard name is required');
            if (!$code) {
                $code = strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($name)));
                $code = trim($code, '_');
            }

            // Sanitize filter fields to be alphanumeric with underscore only (prevents SQL backtick injection)
            foreach ($filters as &$f) {
                if (isset($f['field'])) {
                    $f['field'] = preg_replace('/[^a-z0-9_]/i', '', $f['field']);
                }
            }

            $rolesJson   = json_encode(array_values($rolesAccess));
            $filtersJson = json_encode(array_values($filters));
            $reportsJson = json_encode(array_values($reports));
            $userId      = $_SESSION['user_id'] ?? null;

            $pid = ProjectContext::getId();
            if ($id) {
                $db->query(
                    "UPDATE nu_report_dashboards SET
                        dashboard_name=?, dashboard_code=?, dashboard_description=?, dashboard_active=?,
                        dashboard_role_access=?, dashboard_filters=?, dashboard_reports=?,
                        dashboard_updated_at=NOW()
                     WHERE dashboard_id=? AND project_id=?",
                    [$name, $code, $desc, $active, $rolesJson, $filtersJson, $reportsJson, $id, $pid]
                );
                echo json_encode(['success' => true, 'id' => $id, 'message' => 'Dashboard updated']);
            } else {
                $db->query(
                    "INSERT INTO nu_report_dashboards
                        (project_id, dashboard_name, dashboard_code, dashboard_description, dashboard_active,
                         dashboard_role_access, dashboard_filters, dashboard_reports,
                         dashboard_created_by)
                     VALUES (?,?,?,?,?,?,?,?,?)",
                    [$pid, $name, $code, $desc, $active, $rolesJson, $filtersJson, $reportsJson, $userId]
                );
                $newId = $db->lastInsertId();
                echo json_encode(['success' => true, 'id' => $newId, 'message' => 'Dashboard created']);
            }
            break;

        // ── delete dashboard ───────────────────────────────────────────────────────────
        case 'delete':
            if (!$isAdmin) {
                throw new Exception('Unauthorized action: Developer access required');
            }

            $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
            if (!$id) throw new Exception('Missing id');
            $pid = ProjectContext::getId();
            $db->query("DELETE FROM nu_report_dashboards WHERE dashboard_id = ? AND project_id = ?", [$id, $pid]);
            echo json_encode(['success' => true, 'message' => 'Dashboard deleted']);
            break;

        // ── list tables for lookup option builder ─────────────────────────────────────
        case 'tables':
            if (!$isAdmin) {
                throw new Exception('Unauthorized action: Developer access required');
            }

            $tables = $db->fetchAll("SHOW TABLES");
            $flat   = array_map(fn($r) => array_values($r)[0], $tables);
            echo json_encode(['success' => true, 'data' => $flat]);
            break;

        // ── list columns for lookup option builder ────────────────────────────────────
        case 'columns':
            if (!$isAdmin) {
                throw new Exception('Unauthorized action: Developer access required');
            }

            $table = preg_replace('/[^a-z0-9_]/i', '', $_GET['table'] ?? '');
            if (!$table) throw new Exception('table required');
            $cols = $db->fetchAll("SHOW COLUMNS FROM `{$table}`");
            $flat = array_map(fn($r) => $r['Field'], $cols);
            echo json_encode(['success' => true, 'data' => $flat]);
            break;

        // ── get lookup values for a dropdown ──────────────────────────────────────────
        case 'lookup_options':
            $table  = preg_replace('/[^a-z0-9_]/i', '', $_GET['table'] ?? '');
            $valCol = preg_replace('/[^a-z0-9_]/i', '', $_GET['val_col'] ?? '');
            $lblCol = preg_replace('/[^a-z0-9_]/i', '', $_GET['lbl_col'] ?? '');

            if (!$table || !$valCol || !$lblCol) {
                throw new Exception('table, val_col, and lbl_col are required');
            }

            $rows = $db->fetchAll("SELECT DISTINCT `{$valCol}` AS value, `{$lblCol}` AS label FROM `{$table}` ORDER BY `{$lblCol}`");
            echo json_encode(['success' => true, 'data' => $rows]);
            break;

        // ── run report inside a dashboard ──────────────────────────────────────────────
        case 'run_report':
            $dashboardId = (int)($_GET['dashboard_id'] ?? 0);
            $reportId    = (int)($_GET['report_id'] ?? 0);

            if (!$dashboardId || !$reportId) {
                throw new Exception('dashboard_id and report_id are required');
            }

            // Get dashboard
            $dashboard = $db->fetchOne("SELECT * FROM nu_report_dashboards WHERE dashboard_id = ?", [$dashboardId]);
            if (!$dashboard) throw new Exception('Dashboard not found');

            // Verify dashboard role restriction
            $dashRoles = json_decode($dashboard['dashboard_role_access'] ?? '[]', true) ?: [];
            if (!$isAdmin && !empty($dashRoles) && !in_array($userRole, array_map('strtolower', $dashRoles), true)) {
                throw new Exception('Access denied to this dashboard');
            }

            // Verify report exists in dashboard and user has role access
            $dashReports = json_decode($dashboard['dashboard_reports'] ?? '[]', true) ?: [];
            $matchedReport = null;
            foreach ($dashReports as $dr) {
                if ((int)$dr['report_id'] === $reportId) {
                    $matchedReport = $dr;
                    break;
                }
            }
            if (!$matchedReport) {
                throw new Exception('Report is not assigned to this dashboard');
            }

            // Check report-level role restriction within group
            $reportRoles = $matchedReport['allowed_roles'] ?? [];
            if (!$isAdmin && !empty($reportRoles) && !in_array($userRole, array_map('strtolower', $reportRoles), true)) {
                throw new Exception('Access denied to this report');
            }

            // Get report definition
            $report = $db->fetchOne("SELECT * FROM nu_reports WHERE report_id = ?", [$reportId]);
            if (!$report) throw new Exception('Report definition not found');

            $sql      = $report['report_sql'];
            $columns  = json_decode($report['report_columns'] ?? '[]', true) ?: [];

            // Read the filters defined in the dashboard
            $dashboardFilters = json_decode($dashboard['dashboard_filters'] ?? '[]', true) ?: [];

            // Filter values passed in query string
            $whereParts = [];
            $bindings   = [];

            foreach ($dashboardFilters as $df) {
                $field = $df['field'] ?? '';
                // Strict validation of the field name to eliminate any SQL injection vector via backticks
                $field = preg_replace('/[^a-z0-9_]/i', '', $field);
                if ($field && isset($_GET[$field]) && $_GET[$field] !== '') {
                    $val = $_GET[$field];
                    $op  = $df['operator'] ?? '=';
                    if ($op === 'LIKE') {
                        $whereParts[] = "`{$field}` LIKE ?";
                        $bindings[]   = '%' . $val . '%';
                    } else {
                        $whereParts[] = "`{$field}` {$op} ?";
                        $bindings[]   = $val;
                    }
                }
            }

            if ($whereParts) {
                $sql = "SELECT * FROM ({$sql}) AS _rpt WHERE " . implode(' AND ', $whereParts);
            }

            // Execute SQL safely
            $rows = $db->fetchAll($sql, $bindings);

            // If empty columns list, auto detect from rows
            if (empty($columns) && !empty($rows)) {
                foreach (array_keys($rows[0]) as $col) {
                    $columns[] = ['field' => $col, 'label' => ucwords(str_replace('_', ' ', $col))];
                }
            }

            echo json_encode([
                'success'     => true,
                'data'        => $rows,
                'columns'     => $columns,
                'total'       => count($rows),
                'report_name' => $report['report_name']
            ]);
            break;

        default:
            throw new Exception('Unknown action: ' . htmlspecialchars($action));
    }

} catch (Throwable $e) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
