<?php
declare(strict_types=1);
/**
 * api/users.php — User CRUD + meta fields
 *
 * Actions:
 *   POST ?action=create   Create user + meta
 *   POST ?action=update   Update user + meta
 *   POST ?action=delete   Delete user
 *
 * Supports both userIdType modes set during setup:
 *   'uuid'           — usr_id is VARCHAR(36), generated via nuGenerateUuid()
 *   'auto_increment' — usr_id is INT AUTO_INCREMENT, resolved via lastInsertId()
 */
header('Content-Type: application/json');
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';

// Load local config (contains userIdType written by setup.php)
if (file_exists('../config.local.php')) {
    require_once '../config.local.php';
}

$auth = new NuAuth();
if (!$auth->checkAuth()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db     = NuDatabase::getInstance();
$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

require_once '../core/Tenant.php';
$nuActor       = $auth->getCurrentUser() ?: [];
$nuActorRole   = strtolower((string)($nuActor['usr_role'] ?? ''));
$nuActorTenant = NuTenant::current();

/** A non-globeadmin never touches users of another tenant, nor globeadmin accounts. */
function nuAssertCanManageUser(array $target, string $actorRole, string $actorTenant): void {
    if ($actorRole === 'globeadmin') return;
    if (strtolower((string)($target['usr_role'] ?? '')) === 'globeadmin') throw new Exception('Access denied');
    if (!NuTenant::canManage($actorRole, $actorTenant, (string)($target['tenant_code'] ?? 'default'))) throw new Exception('User not found');
}

/**
 * Returns true if the installation uses UUID for usr_id.
 */
function nuIsUuidMode(): bool {
    global $nuConfig;
    return ($nuConfig['userIdType'] ?? 'auto_increment') === 'uuid';
}

/**
 * Generate a v4 UUID using random_bytes() — cryptographically secure.
 */
function nuGenerateUuid(): string {
    $data    = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant RFC 4122
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Parse a user ID from request body.
 * UUID mode  : returns validated UUID string, or null if malformed.
 * INT mode   : casts to int, returns null if <= 0.
 */
function nuParseUserId(mixed $raw): string|int|null {
    if ($raw === null || $raw === '' || $raw === 0) return null;
    if (nuIsUuidMode()) {
        $id = trim((string)$raw);
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id)
            ? $id
            : null;
    }
    $id = (int)$raw;
    return $id > 0 ? $id : null;
}

try {
    switch ($action) {

        case 'create': {
            if (!$auth->hasPermission('users.create')) throw new Exception('Access denied');

            $username = trim($body['username'] ?? '');
            $email    = trim($body['email']    ?? '');
            $role     = trim($body['role']     ?? 'user');
            $password = $body['password'] ?? '';
            $active   = isset($body['active']) ? (int)$body['active'] : 1;
            $meta     = $body['meta'] ?? [];

            if (!NuTenant::canAssignRole($nuActorRole, $nuActorTenant, $role)) throw new Exception('You cannot assign that role');
            if (!$username) throw new Exception('Username is required');
            if (!$password) throw new Exception('Password is required for new users');

            $exists = $db->fetchOne('SELECT usr_id FROM nu_users WHERE usr_username = :u', [':u' => $username]);
            if ($exists) throw new Exception('Username already exists');

            nu_ensure_custom_user_columns($db);

            $hash   = password_hash($password, PASSWORD_BCRYPT);
            $fields = [
                'usr_username' => $username,
                'usr_email'    => $email,
                'usr_role'     => $role,
                'usr_password' => $hash,
                'usr_active'   => $active,
                'usr_custom_fields' => json_encode($meta),
                'tenant_code'  => ($nuActorRole === 'globeadmin' && !empty($body['tenant_code'])) ? NuTenant::normalize($body['tenant_code']) : $nuActorTenant,
            ];

            if (nuIsUuidMode()) {
                // Pre-generate UUID in PHP and supply it explicitly.
                // This avoids a second SELECT to retrieve the ID after insert,
                // since lastInsertId() returns 0 for non-AUTO_INCREMENT PKs.
                $newId          = nuGenerateUuid();
                $fields['usr_id'] = $newId;
            }

            $db->insert('nu_users', $fields);

            if (!nuIsUuidMode()) {
                $newId = $db->lastInsertId();
            }

            saveMeta($db, $newId, $meta);

            // The default project is members-only, so a new user joins it as a member.
            try {
                $def = $db->fetchOne("SELECT project_id FROM nu_projects WHERE project_is_default = 1 AND project_active = 1 LIMIT 1");
                if ($def) {
                    $db->insert('nu_project_members', ['pm_project_id' => $def['project_id'], 'pm_user_id' => (string)$newId, 'pm_role' => 'member']);
                }
            } catch (Throwable $e) { error_log('[users] default project membership: ' . $e->getMessage()); }

            echo json_encode(['success' => true, 'usr_id' => $newId]);
            break;
        }

        case 'update': {
            if (!$auth->hasPermission('users.edit')) throw new Exception('Access denied');

            $id     = nuParseUserId($body['id'] ?? null);
            $email  = trim($body['email']  ?? '');
            $role   = trim($body['role']   ?? '');
            $active = isset($body['active']) ? (int)$body['active'] : 1;
            $meta   = $body['meta'] ?? [];

            if ($id === null) throw new Exception('Valid User ID required');

            $user = $db->fetchOne('SELECT * FROM nu_users WHERE usr_id = :id', [':id' => $id]);
            if (!$user) throw new Exception('User not found');
            nuAssertCanManageUser($user, $nuActorRole, $nuActorTenant);
            if ($role === '') $role = (string)$user['usr_role'];
            if ($role !== (string)$user['usr_role'] && !NuTenant::canAssignRole($nuActorRole, $nuActorTenant, $role)) throw new Exception('You cannot assign that role');

            nu_ensure_custom_user_columns($db);

            $update = [
                'usr_email'  => $email,
                'usr_role'   => $role,
                'usr_active' => $active,
                'usr_custom_fields' => json_encode($meta),
            ];

            if (!empty($body['password'])) {
                $update['usr_password'] = password_hash($body['password'], PASSWORD_BCRYPT);
            }

            $db->update('nu_users', $update, 'usr_id = :id', [':id' => $id]);
            saveMeta($db, $id, $meta);

            echo json_encode(['success' => true]);
            break;
        }

        case 'save_fields_def': {
            $currentUser = $auth->getCurrentUser();
            if (!$currentUser || $currentUser['usr_username'] !== 'globeadmin') {
                throw new Exception('Access denied: only globeadmin can manage fields.');
            }
            $fields = $body['fields'] ?? [];
            if (!is_array($fields)) throw new Exception('Invalid fields data');

            // Clean up keys
            foreach ($fields as &$f) {
                $f['key'] = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$f['key']);
                $f['label'] = trim((string)$f['label']);
                $f['type'] = trim((string)($f['type'] ?? 'text'));
                $f['global'] = !empty($f['global']);
                $f['is_system'] = !empty($f['is_system']);
                if (isset($f['options'])) {
                    $f['options'] = trim((string)$f['options']);
                }
                $f['lookup_table'] = trim((string)($f['lookup_table'] ?? ''));
                $f['lookup_field'] = trim((string)($f['lookup_field'] ?? ''));
            }

            nu_ensure_custom_user_columns($db);
            $db->update('nu_users',
                ['usr_custom_fields_def' => json_encode($fields)],
                "usr_username = 'globeadmin'"
            );

            echo json_encode(['success' => true]);
            break;
        }

        case 'get_tables': {
            $currentUser = $auth->getCurrentUser();
            if (!$currentUser || $currentUser['usr_username'] !== 'globeadmin') {
                throw new Exception('Access denied.');
            }
            $tables = array_map('current', $db->fetchAll("SHOW TABLES"));
            echo json_encode(['success' => true, 'tables' => $tables]);
            break;
        }

        case 'get_columns': {
            $currentUser = $auth->getCurrentUser();
            if (!$currentUser || $currentUser['usr_username'] !== 'globeadmin') {
                throw new Exception('Access denied.');
            }
            $table = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['table'] ?? '');
            if (!$table) throw new Exception('Table name is required');
            $cols = array_column($db->fetchAll("SHOW COLUMNS FROM `{$table}`"), 'Field');
            echo json_encode(['success' => true, 'columns' => $cols]);
            break;
        }

        case 'delete': {
            if (!$auth->hasPermission('users.delete')) throw new Exception('Access denied');

            $id = nuParseUserId($body['id'] ?? null);
            if ($id === null) throw new Exception('Valid User ID required');

            $user = $db->fetchOne('SELECT usr_username, usr_role, tenant_code FROM nu_users WHERE usr_id = :id', [':id' => $id]);
            if (!$user) throw new Exception('User not found');
            nuAssertCanManageUser($user, $nuActorRole, $nuActorTenant);
            if ($user['usr_username'] === 'globeadmin') throw new Exception('Cannot delete globeadmin');

            $db->delete('nu_user_meta', 'umeta_user_id = :id', [':id' => $id]);
            $db->delete('nu_users', 'usr_id = :id',            [':id' => $id]);

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

/**
 * Upsert meta key/value pairs for a user.
 * Accepts string|int $userId to support both UUID and auto_increment modes.
 * Skips empty keys; preserves existing values when $value is blank.
 */
function saveMeta(NuDatabase $db, string|int $userId, array $meta): void {
    foreach ($meta as $key => $value) {
        $key   = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$key);
        $value = trim((string)$value);
        if ($key === '') continue;

        $existing = $db->fetchOne(
            'SELECT umeta_id FROM nu_user_meta WHERE umeta_user_id = :uid AND umeta_key = :k',
            [':uid' => $userId, ':k' => $key]
        );

        if ($existing) {
            $db->update('nu_user_meta',
                ['umeta_value' => $value],
                'umeta_user_id = :uid AND umeta_key = :k',
                [':uid' => $userId, ':k' => $key]
            );
        } else {
            $db->insert('nu_user_meta', [
                'umeta_user_id' => $userId,
                'umeta_key'     => $key,
                'umeta_value'   => $value,
            ]);
        }
    }
}
