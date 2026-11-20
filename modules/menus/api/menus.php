<?php
declare(strict_types=1);
/**
 * modules/menus/api/menus.php
 * REST API for Menu Builder CRUD.
 *
 * GET  ?action=get&id=N   — fetch a single menu item
 * GET  ?action=roles       — return all active role codes+names for Select2
 * POST action=create      — create
 * POST action=update      — update
 * POST action=delete      — hard-delete item + its children
 * POST action=reorder     — bulk update menu_order after drag-drop
 */
require_once dirname(__DIR__, 3) . '/core/module_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$db      = NuDatabase::getInstance();
$action  = $_GET['action'] ?? '';
$role    = strtolower((string)($_SESSION['nu_role'] ?? ''));
$isAdmin = in_array($role, ['globeadmin', 'admin'], true);

if (!$isAdmin) {
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

function mu_json(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Turn raw database errors into a message an admin can act on (details go to the error log). */
function mu_friendly_error(Throwable $e): string {
    $m = $e->getMessage();
    if (stripos($m, 'Duplicate entry') !== false) {
        return 'A menu item with the same label, type and target already exists. Change the label or target.';
    }
    return 'Could not save the menu item. See the server error log for details.';
}

/** Validate a single display-mode value: 'inline' or 'popup'. */
function mu_sanitise_display(string $raw, string $default = 'inline'): string {
    $v = strtolower(trim($raw));
    return in_array($v, ['inline', 'popup'], true) ? $v : $default;
}

/** Validate a view value: 'browse' or 'preview'. */
function mu_sanitise_view(string $raw, string $default = 'browse'): string {
    $v = strtolower(trim($raw));
    return in_array($v, ['browse', 'preview'], true) ? $v : $default;
}

/**
 * Normalise incoming roles value to a JSON array string for storage.
 * Accepts: array, JSON string, or comma-separated string.
 * Returns: JSON string e.g. '["admin","manager"]' or NULL if empty/all.
 */
function mu_roles_to_json($raw): ?string {
    if ($raw === null || $raw === '' || $raw === '[]') return null;
    if (is_array($raw)) {
        $clean = array_values(array_filter(array_map('trim', $raw)));
        return empty($clean) ? null : json_encode($clean, JSON_UNESCAPED_UNICODE);
    }
    if (is_string($raw)) {
        // Already a JSON array?
        if (substr(trim($raw), 0, 1) === '[') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $clean = array_values(array_filter(array_map('trim', $decoded)));
                return empty($clean) ? null : json_encode($clean, JSON_UNESCAPED_UNICODE);
            }
        }
        // Comma-separated fallback
        $parts = array_values(array_filter(array_map('trim', explode(',', $raw))));
        return empty($parts) ? null : json_encode($parts, JSON_UNESCAPED_UNICODE);
    }
    return null;
}

/**
 * Convert roles raw value to a comma-separated string (for legacy menu_roles column).
 */
function mu_roles_to_csv($raw): string {
    if ($raw === null || $raw === '' || $raw === '[]') return '';
    if (is_array($raw)) {
        $clean = array_values(array_filter(array_map('trim', $raw)));
        return implode(',', $clean);
    }
    if (is_string($raw)) {
        if (substr(trim($raw), 0, 1) === '[') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $clean = array_values(array_filter(array_map('trim', $decoded)));
                return implode(',', $clean);
            }
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $raw))));
        return implode(',', $parts);
    }
    return '';
}

/**
 * Decode a stored roles value back to an array for the API response.
 * Handles JSON array, comma-separated string, or NULL.
 */
function mu_roles_to_array($stored): array {
    if ($stored === null || $stored === '') return [];
    if (substr(trim($stored), 0, 1) === '[') {
        $decoded = json_decode($stored, true);
        if (is_array($decoded)) return $decoded;
    }
    // Legacy comma-separated
    return array_values(array_filter(array_map('trim', explode(',', $stored))));
}

// ── AUTO-MIGRATE: ensure all columns exist ───────────────────────────────────
$autoMigrations = [
    "ALTER TABLE nu_menus ADD COLUMN menu_role_access  VARCHAR(512) DEFAULT NULL",
    "ALTER TABLE nu_menus ADD COLUMN menu_open_mode    VARCHAR(30)  NOT NULL DEFAULT 'inline|browse'",
    "ALTER TABLE nu_menus ADD COLUMN menu_browse_mode  VARCHAR(10)  NOT NULL DEFAULT 'inline'",
    "ALTER TABLE nu_menus ADD COLUMN menu_preview_mode VARCHAR(10)  NOT NULL DEFAULT 'inline'",
    "ALTER TABLE nu_menus ADD COLUMN menu_default_view VARCHAR(10)  NOT NULL DEFAULT 'browse'",
    // Ensure role_access is VARCHAR, not JSON type
    "ALTER TABLE nu_menus MODIFY COLUMN menu_role_access VARCHAR(512) DEFAULT NULL",
];
foreach ($autoMigrations as $sql) {
    try { $db->query($sql); } catch (Throwable $ignored) {}
}

// Read POST body
$body = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) $body = $_POST;
}

// ── GET roles list (for Select2) ─────────────────────────────────────────────
if ($action === 'roles') {
    $rows = $db->fetchAll("SELECT role_code, role_name FROM nu_roles WHERE role_active = 1 ORDER BY role_name");
    $out  = [];
    foreach ($rows as $r) {
        $out[] = ['id' => $r['role_code'], 'text' => $r['role_name'] . ' (' . $r['role_code'] . ')'];
    }
    mu_json(['success' => true, 'roles' => $out]);
}

// ── GET single item ──────────────────────────────────────────────────────────
if ($action === 'get') {
    $id  = (int)($_GET['id'] ?? 0);
    $row = $db->fetchOne("SELECT * FROM nu_menus WHERE menu_id = ?", [$id]);
    if (!$row) mu_json(['success' => false, 'message' => 'Not found']);

    // Backfill open-mode columns for legacy rows
    if (empty($row['menu_browse_mode']) || empty($row['menu_preview_mode'])) {
        $old = $row['menu_open_mode'] ?? 'inline|browse';
        if (strpos($old, '|') !== false) {
            list($disp, $view) = explode('|', $old, 2);
        } else {
            $disp = 'inline'; $view = 'browse';
        }
        $row['menu_browse_mode']  = mu_sanitise_display($disp);
        $row['menu_preview_mode'] = 'inline';
        $row['menu_default_view'] = mu_sanitise_view($view);
    }

    // Decode roles to array for JS
    $row['menu_role_access_array'] = mu_roles_to_array($row['menu_role_access'] ?? null);

    mu_json(['success' => true, 'menu' => $row]);
}

// ── CREATE ───────────────────────────────────────────────────────────────────
if ($action === 'create') {
    $label       = substr(trim((string)($body['label']       ?? '')), 0, 120);
    $type        = preg_replace('/[^a-z_]/', '', (string)($body['type'] ?? 'form'));
    $target      = substr(trim((string)($body['target']      ?? '')), 0, 200);
    $icon        = substr(trim((string)($body['icon']        ?? 'default')), 0, 60);
    $parent      = (int)($body['parent'] ?? 0);
    $order       = (int)($body['order']  ?? 0);
    $rolesJson   = mu_roles_to_json($body['roles'] ?? null);
    $rolesCsv    = mu_roles_to_csv($body['roles'] ?? null);
    $active      = isset($body['active']) ? (int)$body['active'] : 1;
    $browseMode  = mu_sanitise_display((string)($body['browse_mode']  ?? 'inline'));
    $previewMode = mu_sanitise_display((string)($body['preview_mode'] ?? 'inline'));
    $defaultView = mu_sanitise_view((string)($body['default_view']    ?? 'browse'));
    $openMode    = $browseMode . '|' . $defaultView;

    if (!$label && $type !== 'divider') {
        mu_json(['success' => false, 'message' => 'Label is required']);
    }

    if ($label === '') $label = '---'; // divider
    if (in_array($type, ['form', 'report', 'query', 'url'], true) && $target === '') {
        mu_json(['success' => false, 'message' => 'Choose a target (form, report, query or URL) for this menu item.']);
    }
    $projectId = (int)ProjectContext::getId();

    try {
        $db->query(
            "INSERT INTO nu_menus
             (project_id, menu_label, menu_type, menu_target, menu_icon, menu_order,
              menu_parent_id, menu_role_access, menu_roles, menu_active,
              menu_open_mode, menu_browse_mode, menu_preview_mode, menu_default_view)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $projectId, $label, $type, $target, $icon, $order,
                $parent, $rolesJson, $rolesCsv, $active,
                $openMode, $browseMode, $previewMode, $defaultView
            ]
        );
        mu_json(['success' => true, 'id' => $db->lastInsertId()]);
    } catch (Throwable $e) {
        error_log('[menus api create] ' . $e->getMessage());
        mu_json(['success' => false, 'message' => mu_friendly_error($e)]);
    }
}

// ── UPDATE ───────────────────────────────────────────────────────────────────
if ($action === 'update') {
    $id          = (int)($body['id']   ?? 0);
    $label       = substr(trim((string)($body['label']       ?? '')), 0, 120);
    $type        = preg_replace('/[^a-z_]/', '', (string)($body['type'] ?? 'form'));
    $target      = substr(trim((string)($body['target']      ?? '')), 0, 200);
    $icon        = substr(trim((string)($body['icon']        ?? 'default')), 0, 60);
    $parent      = (int)($body['parent'] ?? 0);
    $order       = (int)($body['order']  ?? 0);
    $rolesJson   = mu_roles_to_json($body['roles'] ?? null);
    $rolesCsv    = mu_roles_to_csv($body['roles'] ?? null);
    $active      = isset($body['active']) ? (int)$body['active'] : 1;
    $browseMode  = mu_sanitise_display((string)($body['browse_mode']  ?? 'inline'));
    $previewMode = mu_sanitise_display((string)($body['preview_mode'] ?? 'inline'));
    $defaultView = mu_sanitise_view((string)($body['default_view']    ?? 'browse'));
    $openMode    = $browseMode . '|' . $defaultView;

    if (!$id) mu_json(['success' => false, 'message' => 'Invalid ID']);
    if ($parent === $id) mu_json(['success' => false, 'message' => 'A menu item cannot be its own parent.']);

    try {
        $db->query(
            "UPDATE nu_menus SET
               menu_label        = ?,
               menu_type         = ?,
               menu_target       = ?,
               menu_icon         = ?,
               menu_order        = ?,
               menu_parent_id    = ?,
               menu_role_access  = ?,
               menu_roles        = ?,
               menu_active       = ?,
               menu_open_mode    = ?,
               menu_browse_mode  = ?,
               menu_preview_mode = ?,
               menu_default_view = ?
             WHERE menu_id = ?",
            [
                $label, $type, $target, $icon, $order,
                $parent, $rolesJson, $rolesCsv, $active,
                $openMode, $browseMode, $previewMode, $defaultView,
                $id
            ]
        );
        mu_json(['success' => true]);
    } catch (Throwable $e) {
        error_log('[menus api update] ' . $e->getMessage());
        mu_json(['success' => false, 'message' => mu_friendly_error($e)]);
    }
}

// ── DELETE ───────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) mu_json(['success' => false, 'message' => 'Invalid ID']);
    try {
        $db->query("DELETE FROM nu_menus WHERE menu_id = ? OR menu_parent_id = ?", [$id, $id]);
        mu_json(['success' => true]);
    } catch (Throwable $e) {
        error_log('[menus api delete] ' . $e->getMessage());
        mu_json(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ── REORDER ──────────────────────────────────────────────────────────────────
if ($action === 'reorder') {
    $items = (array)($body['items'] ?? []);
    try {
        foreach ($items as $i => $item) {
            $id  = (int)($item['id']    ?? 0);
            $ord = (int)($item['order'] ?? $i);
            if (!$id) continue;
            $db->query("UPDATE nu_menus SET menu_order = ? WHERE menu_id = ?", [$ord, $id]);
        }
        mu_json(['success' => true]);
    } catch (Throwable $e) {
        error_log('[menus api reorder] ' . $e->getMessage());
        mu_json(['success' => false, 'message' => $e->getMessage()]);
    }
}

mu_json(['success' => false, 'message' => 'Unknown action']);
