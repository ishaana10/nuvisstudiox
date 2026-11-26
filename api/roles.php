<?php
declare(strict_types=1);
/**
 * api/roles.php  —  Dynamic Role & Form-Permission Management API
 *
 * Actions (all require globeadmin / roles.view permission):
 *   GET  ?action=list                       List all roles
 *   GET  ?action=get&role_code=x            Get one role + its form permissions
 *   GET  ?action=forms                      List all available forms (for the matrix UI)
 *   POST ?action=create                     Create a new role
 *   POST ?action=update&role_code=x         Rename / update description
 *   POST ?action=delete&role_code=x         Delete a non-system role
 *   POST ?action=save_perms&role_code=x     Upsert form permissions for a role
 */

header('Content-Type: application/json');
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';

$auth = new NuAuth();
if (!$auth->checkAuth()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Only globeadmin / roles.view may manage roles
if (!$auth->hasPermission('roles.view')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$db     = NuDatabase::getInstance();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

require_once __DIR__ . '/../core/Tenant.php';
$nuActorRole   = strtolower((string)(($auth->getCurrentUser()['usr_role'] ?? '')));
$nuActorTenant = NuTenant::current();

/** A non-globeadmin only manages their own tenant's roles; globeadmin and system roles are never theirs to change. */
function nuAssertRoleInTenant(array $role, string $actorRole, string $actorTenant): void {
    if ($actorRole === 'globeadmin') return;
    if (strtolower((string)$role['role_code']) === 'globeadmin' || !NuTenant::canManage($actorRole, $actorTenant, (string)($role['tenant_code'] ?? 'default'))) {
        throw new Exception('Role not found');
    }
}

try {
    switch ($action) {

        // ── List all roles ────────────────────────────────────────────────────
        case 'list':
            $roles = $nuActorRole === 'globeadmin'
                ? $db->fetchAll("SELECT * FROM nu_roles ORDER BY role_name")
                : $db->fetchAll("SELECT * FROM nu_roles WHERE role_code <> 'globeadmin' AND (role_is_system = 1 OR tenant_code = ?) ORDER BY role_name", [$nuActorTenant]);
            echo json_encode(['success' => true, 'roles' => $roles]);
            break;

        // ── Get one role + its form-level permissions ─────────────────────────
        case 'get':
            $code = trim($_GET['role_code'] ?? '');
            if (!$code) throw new Exception('role_code required');
            $role = $db->fetchOne("SELECT * FROM nu_roles WHERE role_code = :c", [':c' => $code]);
            if (!$role) throw new Exception('Role not found');
            if (in_array($action, ['get', 'update', 'delete', 'save_perms'], true)) nuAssertRoleInTenant($role, $nuActorRole, $nuActorTenant);
            $perms = $db->fetchAll(
                "SELECT * FROM nu_role_form_permissions WHERE rfp_role_code = :c ORDER BY rfp_form_code",
                [':c' => $code]
            );
            echo json_encode(['success' => true, 'role' => $role, 'permissions' => $perms]);
            break;

        // ── List all forms (for permission matrix) ────────────────────────────
        case 'forms':
            $forms = $db->fetchAll("SELECT form_code, form_name FROM nu_forms WHERE form_active = 1 ORDER BY form_name");
            echo json_encode(['success' => true, 'forms' => $forms]);
            break;

        // ── Create role ───────────────────────────────────────────────────────
        case 'create': {
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $name = trim($body['role_name'] ?? '');
            $code = trim($body['role_code'] ?? '');
            $desc = trim($body['role_description'] ?? '');
            if (!$name || !$code) throw new Exception('role_name and role_code are required');
            $code = preg_replace('/[^a-z0-9_]/', '_', strtolower($code));
            if ($code === 'globeadmin') throw new Exception('Cannot create a role named globeadmin');
            $exists = $db->fetchOne("SELECT role_id FROM nu_roles WHERE role_code = :c", [':c' => $code]);
            if ($exists) throw new Exception('A role with that code already exists');
            $db->insert('nu_roles', [
                'role_name'        => $name,
                'role_code'        => $code,
                'role_description' => $desc,
                'role_is_system'   => 0,
                'tenant_code'      => ($nuActorRole === 'globeadmin' && !empty($body['tenant_code'])) ? NuTenant::normalize($body['tenant_code']) : $nuActorTenant,
            ]);
            echo json_encode(['success' => true, 'role_code' => $code]);
            break;
        }

        // ── Update role name/description ──────────────────────────────────────
        case 'update': {
            $code = trim($_GET['role_code'] ?? '');
            if (!$code) throw new Exception('role_code required');
            $role = $db->fetchOne("SELECT * FROM nu_roles WHERE role_code = :c", [':c' => $code]);
            if (!$role) throw new Exception('Role not found');
            if (in_array($action, ['get', 'update', 'delete', 'save_perms'], true)) nuAssertRoleInTenant($role, $nuActorRole, $nuActorTenant);
            if (!empty($role['role_is_system'])) throw new Exception('System roles cannot be modified');
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $update = [];
            if (isset($body['role_name']))        $update['role_name']        = trim($body['role_name']);
            if (isset($body['role_description'])) $update['role_description'] = trim($body['role_description']);
            if ($update) $db->update('nu_roles', $update, 'role_code = :c', [':c' => $code]);
            echo json_encode(['success' => true]);
            break;
        }

        // ── Delete role ───────────────────────────────────────────────────────
        case 'delete': {
            $code = trim($_GET['role_code'] ?? '');
            if (!$code) throw new Exception('role_code required');
            $role = $db->fetchOne("SELECT * FROM nu_roles WHERE role_code = :c", [':c' => $code]);
            if (!$role) throw new Exception('Role not found');
            if (in_array($action, ['get', 'update', 'delete', 'save_perms'], true)) nuAssertRoleInTenant($role, $nuActorRole, $nuActorTenant);
            if (!empty($role['role_is_system'])) throw new Exception('System roles cannot be deleted');
            $inUse = $db->fetchOne("SELECT COUNT(*) as cnt FROM nu_users WHERE usr_role = :c", [':c' => $code]);
            if ($inUse['cnt'] > 0) throw new Exception('Cannot delete - ' . $inUse['cnt'] . ' user(s) are assigned this role');
            $db->delete('nu_role_form_permissions', 'rfp_role_code = :c', [':c' => $code]);
            $db->delete('nu_roles', 'role_code = :c', [':c' => $code]);
            echo json_encode(['success' => true]);
            break;
        }

        // ── Save form permissions for a role ──────────────────────────────────
        case 'save_perms': {
            $code = trim($_GET['role_code'] ?? '');
            if (!$code) throw new Exception('role_code required');
            $role = $db->fetchOne("SELECT * FROM nu_roles WHERE role_code = :c", [':c' => $code]);
            if (!$role) throw new Exception('Role not found');
            if (in_array($action, ['get', 'update', 'delete', 'save_perms'], true)) nuAssertRoleInTenant($role, $nuActorRole, $nuActorTenant);
            if (!empty($role['role_is_system'])) throw new Exception('Cannot edit permissions for system roles');

            $body  = json_decode(file_get_contents('php://input'), true) ?? [];
            $perms = $body['permissions'] ?? [];
            if (!is_array($perms)) throw new Exception('permissions must be an array');

            $pdo  = $db->getPdo();
            $stmt = $pdo->prepare("
                INSERT INTO nu_role_form_permissions
                    (rfp_role_code, rfp_form_code, rfp_can_view, rfp_can_add, rfp_can_edit, rfp_can_delete, rfp_can_export)
                VALUES
                    (:role, :form, :view, :add, :edit, :del, :exp)
                ON DUPLICATE KEY UPDATE
                    rfp_can_view   = VALUES(rfp_can_view),
                    rfp_can_add    = VALUES(rfp_can_add),
                    rfp_can_edit   = VALUES(rfp_can_edit),
                    rfp_can_delete = VALUES(rfp_can_delete),
                    rfp_can_export = VALUES(rfp_can_export)
            ");

            foreach ($perms as $p) {
                $fcode = trim($p['form_code'] ?? '');
                if (!$fcode) continue;
                $stmt->execute([
                    ':role' => $code,
                    ':form' => $fcode,
                    ':view' => (int)!empty($p['can_view']),
                    ':add'  => (int)!empty($p['can_add']),
                    ':edit' => (int)!empty($p['can_edit']),
                    ':del'  => (int)!empty($p['can_delete']),
                    ':exp'  => (int)!empty($p['can_export']),
                ]);
            }
            echo json_encode(['success' => true]);
            break;
        }

        default:
            throw new Exception('Unknown action: ' . $action);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
