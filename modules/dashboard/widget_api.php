<?php
declare(strict_types=1);
/**
 * modules/dashboard/widget_api.php — dashboard widgets JSON API.
 *
 * Thin glue: permissions (NuWidgetPolicy), CSRF, then one handler per action.
 * Logic lives in core/Widget*.php (unit-tested). "Developer" = globeadmin.
 *
 * Self-service (any signed-in user): list, render, prefs_save, prefs_reset, settings_save
 * Developer only: types, designer_load, add/update/remove/reorder (role widgets),
 *                 run_sql, list_tables
 */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
foreach (['WidgetSchema', 'WidgetPrefs', 'WidgetPolicy', 'WidgetSql', 'WidgetRegistry', 'WidgetStore'] as $_wf) {
    require_once dirname(__DIR__, 2) . "/core/{$_wf}.php";
}

header('Content-Type: application/json; charset=utf-8');

$db       = NuDatabase::getInstance();
$userId   = (int)($_SESSION['nu_user_id'] ?? 0);
$role     = strtolower((string)($_SESSION['nu_role'] ?? ''));
$action   = (string)($_GET['action'] ?? ($_POST['action'] ?? ''));
$isPost   = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

function wu_json(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Legacy action names → policy actions
$policyMap = [
    'list' => 'list', 'render' => 'render', 'prefs_save' => 'prefs_save', 'prefs_reset' => 'prefs_reset',
    'settings_save' => 'settings_save', 'types' => 'types', 'designer_load' => 'designer_load',
    'add' => 'role_add', 'update' => 'role_update', 'remove' => 'role_remove', 'reorder' => 'role_reorder',
    'run_sql' => 'run_sql', 'list_tables' => 'list_tables',
    'list_roles' => 'designer_load', 'reset' => 'prefs_reset',
];
if (!isset($policyMap[$action])) wu_json(['error' => 'Unknown action'], 404);
if (!NuWidgetPolicy::can($policyMap[$action], $role)) wu_json(['error' => 'Forbidden'], 403);

$body = [];
if ($isPost) {
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) $body = $_POST;
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['csrf_token'] ?? ($_POST['nu_csrf'] ?? ''));
    if (!$auth->verifyCsrf((string)$token)) {
        wu_json(['error' => 'Invalid security token. Reload the page and try again.'], 403);
    }
}

try {
    if (empty($_SESSION['_nu_widget_schema_ensured'])) {
        NuWidgetSchema::ensure($db);
        $_SESSION['_nu_widget_schema_ensured'] = true;
    }
} catch (Throwable $e) {
    error_log('[widget_api] schema ensure: ' . $e->getMessage());
}

/** Role-row / own-personal-row guard for developer write actions. */
function wu_writable_row(NuDatabase $db, int $id, int $userId): ?array {
    $row = $db->fetchOne(
        'SELECT * FROM nu_dashboard_widgets WHERE widget_id = ? AND (widget_user_id IS NULL OR widget_user_id = ?)',
        [$id, $userId]
    );
    return $row ?: null;
}

function wu_clamp(mixed $v, int $min, int $max, int $fallback): int {
    return is_numeric($v) ? max($min, min($max, (int)$v)) : $fallback;
}

switch ($action) {

    case 'list': {
        $r = NuWidgetPrefs::resolve(
            NuWidgetStore::visibleWidgets($db, $userId, $role),
            NuWidgetStore::loadPrefs($db, $userId)
        );
        wu_json(['ok' => true, 'widgets' => $r['visible'], 'hidden' => $r['hidden'],
                 'settings' => NuWidgetStore::loadSettings($db, $userId), 'can_design' => $role === 'globeadmin']);
    }

    case 'render': {
        $id = (int)($_GET['id'] ?? 0);
        $row = null;
        foreach (NuWidgetStore::visibleWidgets($db, $userId, $role) as $w) {
            if ((int)$w['widget_id'] === $id) { $row = $w; break; }
        }
        if ($row === null && $role === 'globeadmin') {   // designer preview of any role widget
            $row = $db->fetchOne('SELECT * FROM nu_dashboard_widgets WHERE widget_id = ? AND widget_active = 1 AND widget_user_id IS NULL', [$id]) ?: null;
        }
        if ($row === null) wu_json(['ok' => false, 'error' => 'Widget not found'], 404);
        $res = NuWidgetRegistry::renderWidget($row, $db, $userId, $role);
        wu_json(['ok' => $res['ok'], 'html' => $res['html'], 'refresh' => (int)($row['widget_refresh'] ?? 0)]);
    }

    case 'prefs_save': {
        $byId = [];
        foreach (NuWidgetStore::visibleWidgets($db, $userId, $role) as $w) $byId[(int)$w['widget_id']] = $w;
        $clean = NuWidgetPrefs::sanitizeSaveItems((array)($body['items'] ?? []), $byId);
        NuWidgetStore::savePrefs($db, $userId, $clean);
        wu_json(['ok' => true, 'saved' => count($clean)]);
    }

    case 'prefs_reset':
    case 'reset': {
        NuWidgetStore::resetPrefs($db, $userId);
        wu_json(['ok' => true]);
    }

    case 'settings_save': {
        wu_json(['ok' => true, 'settings' => NuWidgetStore::saveSettings($db, $userId, $body)]);
    }

    case 'types': {
        $out = [];
        foreach (NuWidgetRegistry::all() as $type => $def) {
            unset($def['render']);
            $out[$type] = $def;
        }
        $icons = [];
        foreach (NuWidgetRegistry::svgIconNames() as $n) $icons[$n] = NuWidgetRegistry::svgIcon($n);
        wu_json(['ok' => true, 'types' => $out, 'icons' => $icons]);
    }

    case 'designer_load':
    case 'list_roles': {
        $roles = [];
        try {
            foreach ($db->fetchAll('SELECT role_code, role_name FROM nu_roles ORDER BY role_name') as $r) {
                if (strtolower((string)$r['role_code']) !== 'globeadmin') $roles[] = $r;
            }
        } catch (Throwable $e) {
            $roles = [['role_code' => 'user', 'role_name' => 'User'], ['role_code' => 'manager', 'role_name' => 'Manager'],
                      ['role_code' => 'supervisor', 'role_name' => 'Supervisor'], ['role_code' => 'admin', 'role_name' => 'Admin']];
        }
        if ($action === 'list_roles') wu_json(['roles' => $roles]);
        $want = NuWidgetStore::normalizeRole((string)($_GET['role'] ?? ($roles[0]['role_code'] ?? 'user')));
        wu_json(['ok' => true, 'roles' => $roles, 'role' => $want, 'widgets' => NuWidgetStore::roleWidgets($db, $want)]);
    }

    case 'add': {
        $type = (string)($body['type'] ?? '');
        if (!NuWidgetRegistry::get($type)) wu_json(['error' => 'Unknown widget type'], 422);
        $targetRole = NuWidgetStore::normalizeRole((string)($body['target_role'] ?? ''));
        if ($targetRole !== '' && !preg_match('/^[A-Za-z0-9_]{1,60}$/', $targetRole)) wu_json(['error' => 'Invalid role'], 422);
        $key = trim((string)($body['widget_key'] ?? ''));
        if ($key !== '' && !preg_match('/^[a-z0-9_.]{1,64}$/', $key)) wu_json(['error' => 'Invalid widget key'], 422);
        $ownerId = $targetRole !== '' ? null : $userId;
        $maxPos = (int)($db->fetchOne(
            $ownerId === null
                ? 'SELECT COALESCE(MAX(widget_position), 0) AS m FROM nu_dashboard_widgets WHERE widget_user_id IS NULL AND widget_role = ?'
                : 'SELECT COALESCE(MAX(widget_position), 0) AS m FROM nu_dashboard_widgets WHERE widget_user_id = ?',
            [$ownerId === null ? $targetRole : $ownerId]
        )['m'] ?? 0);
        try {
            $db->query(
                'INSERT INTO nu_dashboard_widgets
                    (widget_user_id, widget_role, widget_key, widget_type, widget_title, widget_icon, widget_config,
                     widget_width, widget_height, widget_position, widget_locked, widget_refresh)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [$ownerId, $ownerId === null ? $targetRole : null, $key !== '' ? $key : null, $type,
                 substr((string)($body['title'] ?? 'Widget'), 0, 120),
                 ($icon = substr((string)($body['icon'] ?? ''), 0, 120)) !== '' ? $icon : null,
                 json_encode($body['config'] ?? new stdClass()),
                 wu_clamp($body['width'] ?? 2, 1, 4, 2), wu_clamp($body['height'] ?? 1, 1, 3, 1), $maxPos + 10,
                 !empty($body['locked']) ? 1 : 0, wu_clamp($body['refresh'] ?? 0, 0, 3600, 0)]
            );
        } catch (PDOException $e) {
            wu_json(['error' => 'A widget with that key already exists for this role.'], 409);
        }
        wu_json(['ok' => true, 'id' => $db->lastInsertId()]);
    }

    case 'update': {
        $id = (int)($body['id'] ?? 0);
        $row = wu_writable_row($db, $id, $userId);
        if (!$row) wu_json(['error' => 'Widget not found'], 404);
        $type = (string)($body['type'] ?? $row['widget_type']);
        if (!NuWidgetRegistry::get($type)) wu_json(['error' => 'Unknown widget type'], 422);
        $icon = substr((string)($body['icon'] ?? ($row['widget_icon'] ?? '')), 0, 120);
        $db->query(
            'UPDATE nu_dashboard_widgets
                SET widget_type = ?, widget_title = ?, widget_icon = ?, widget_config = ?,
                    widget_width = ?, widget_height = ?, widget_locked = ?, widget_refresh = ?
              WHERE widget_id = ?',
            [$type, substr((string)($body['title'] ?? $row['widget_title']), 0, 120), $icon !== '' ? $icon : null,
             array_key_exists('config', $body) ? json_encode($body['config']) : $row['widget_config'],
             wu_clamp($body['width'] ?? $row['widget_width'], 1, 4, 2), wu_clamp($body['height'] ?? $row['widget_height'], 1, 3, 1),
             array_key_exists('locked', $body) ? (!empty($body['locked']) ? 1 : 0) : (int)$row['widget_locked'],
             wu_clamp($body['refresh'] ?? $row['widget_refresh'], 0, 3600, 0), $id]
        );
        wu_json(['ok' => true]);
    }

    case 'remove': {
        $id = (int)($body['id'] ?? 0);
        if (!wu_writable_row($db, $id, $userId)) wu_json(['error' => 'Widget not found'], 404);
        $db->query('UPDATE nu_dashboard_widgets SET widget_active = 0 WHERE widget_id = ?', [$id]);
        wu_json(['ok' => true]);
    }

    case 'reorder': {
        foreach ((array)($body['order'] ?? []) as $item) {
            $id = (int)($item['id'] ?? 0);
            if ($id && wu_writable_row($db, $id, $userId)) {
                $db->query('UPDATE nu_dashboard_widgets SET widget_position = ? WHERE widget_id = ?', [(int)($item['position'] ?? 0), $id]);
            }
        }
        wu_json(['ok' => true]);
    }

    case 'run_sql': {
        try {
            wu_json(['rows' => NuWidgetSql::run($db, (string)($body['sql'] ?? ''), $userId, $role)]);
        } catch (RuntimeException $e) {
            wu_json(['error' => $e->getMessage()]);
        }
    }

    case 'list_tables': {
        try {
            $tables = array_map(static fn($r) => array_values($r)[0], $db->fetchAll('SHOW TABLES'));
            wu_json(['tables' => $tables]);
        } catch (Throwable $e) {
            wu_json(['error' => $e->getMessage()]);
        }
    }
}

wu_json(['error' => 'Unknown action'], 404);
