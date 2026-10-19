<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';

header('Content-Type: application/json');

$auth   = new NuAuth();
$db     = NuDatabase::getInstance();
$action = $_GET['action'] ?? '';

// ─── Helper: load active password policy ─────────────────────────────────────
function loadPolicy(NuDatabase $db): array {
    global $nuConfig;
    $cfgPolicy = $nuConfig['password'] ?? [];
    $default = [
        'policy_min_length'                  => $cfgPolicy['minLength'] ?? 12,
        'policy_require_uppercase'           => !empty($cfgPolicy['requireUpper']) ? 1 : 1,
        'policy_require_lowercase'           => !empty($cfgPolicy['requireLower']) ? 1 : 1,
        'policy_require_number'              => !empty($cfgPolicy['requireNumber']) ? 1 : 1,
        'policy_require_special'             => !empty($cfgPolicy['requireSpecial']) ? 1 : 1,
        'policy_disallow_username'           => 1,
        'policy_history_count'               => $cfgPolicy['historyCount'] ?? 5,
        'policy_expiry_days'                 => $cfgPolicy['maxAgeDays'] ?? 90,
        'policy_expiry_warning_days'         => 14,
        'policy_force_change_on_first_login' => 1,
    ];
    try {
        $row = $db->fetchOne("SELECT * FROM nu_password_policy WHERE policy_id = 1");
        return $row ?: $default;
    } catch (\Throwable $e) {
        return $default;
    }
}

function validatePasswordStrength(string $password, array $policy, string $username = ''): array {
    $errors = [];
    $minLength = (int)($policy['policy_min_length'] ?? $policy['minLength'] ?? 12);
    if (strlen($password) < $minLength) {
        $errors[] = "Password must be at least {$minLength} characters.";
    }
    $reqUpper = !empty($policy['policy_require_uppercase']) || !empty($policy['requireUpper']);
    if ($reqUpper && !preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain an uppercase letter.";
    }
    $reqLower = !empty($policy['policy_require_lowercase']) || !empty($policy['requireLower']);
    if ($reqLower && !preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain a lowercase letter.";
    }
    $reqNum = !empty($policy['policy_require_number']) || !empty($policy['requireNumber']);
    if ($reqNum && !preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain a number.";
    }
    $reqSpec = !empty($policy['policy_require_special']) || !empty($policy['requireSpecial']);
    if ($reqSpec && !preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = "Password must contain a special character.";
    }
    if (!empty($policy['policy_disallow_username']) && $username !== '' && stripos($password, $username) !== false) {
        $errors[] = "Password must not contain your username.";
    }
    return $errors;
}

function validatePassword(string $password, array $policy, string $username = ''): array {
    return validatePasswordStrength($password, $policy, $username);
}

// ─── Helper: check password history ─────────────────────────────────────────
function isPasswordReused(NuDatabase $db, $userId, string $newPassword, int $historyCount): bool {
    if ($historyCount <= 0) return false;
    try {
        $rows = $db->fetchAll(
            "SELECT ph_hash FROM nu_password_history WHERE ph_user_id = :uid ORDER BY ph_created_at DESC LIMIT :lim",
            [':uid' => $userId, ':lim' => $historyCount]
        );
        foreach ($rows as $r) {
            if (password_verify($newPassword, $r['ph_hash'])) return true;
        }
    } catch (\Throwable $e) {}
    return false;
}

// ─── Helper: record password history ─────────────────────────────────────────
function recordPasswordHistory(NuDatabase $db, $userId, string $hash, int $historyCount): void {
    if ($historyCount <= 0) return;
    try {
        $db->query(
            "INSERT INTO nu_password_history (ph_user_id, ph_hash) VALUES (:uid, :hash)",
            [':uid' => $userId, ':hash' => $hash]
        );
        // Prune old history beyond limit
        $db->query(
            "DELETE FROM nu_password_history WHERE ph_user_id = :uid
             AND ph_id NOT IN (
                 SELECT ph_id FROM (
                     SELECT ph_id FROM nu_password_history WHERE ph_user_id = :uid2
                     ORDER BY ph_created_at DESC LIMIT :lim
                 ) AS t
             )",
            [':uid' => $userId, ':uid2' => $userId, ':lim' => max(20, $historyCount)]
        );
    } catch (\Throwable $e) {}
}

switch ($action) {

    // ── Self-service: change own password ────────────────────────────────────
    case 'change_password':
        if (!$auth->checkAuth()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

        $input       = json_decode(file_get_contents('php://input'), true) ?? [];
        $currentPwd  = trim($input['current_password'] ?? '');
        $newPwd      = trim($input['new_password'] ?? '');
        $confirmPwd  = trim($input['confirm_password'] ?? '');

        if ($newPwd !== $confirmPwd) { echo json_encode(['success'=>false,'message'=>'New passwords do not match.']); break; }

        $user = $auth->getCurrentUser();
        if (!password_verify($currentPwd, $user['usr_password'])) {
            echo json_encode(['success'=>false,'message'=>'Current password is incorrect.']);
            break;
        }

        $policy = loadPolicy($db);
        $errors = validatePassword($newPwd, $policy, $user['usr_username']);
        if ($errors) { echo json_encode(['success'=>false,'message'=>implode(' ', $errors)]); break; }

        if (isPasswordReused($db, $user['usr_id'], $newPwd, (int)$policy['policy_history_count'])) {
            echo json_encode(['success'=>false,'message'=>'You cannot reuse a recent password. Choose a different one.']);
            break;
        }

        $newHash = password_hash($newPwd, PASSWORD_DEFAULT);
        $nowStr  = date('Y-m-d H:i:s');
        recordPasswordHistory($db, $user['usr_id'], $user['usr_password'], (int)$policy['policy_history_count']);
        $db->query(
            "UPDATE nu_users SET usr_password = :h, usr_password_changed_at = :now, usr_must_change_password = 0 WHERE usr_id = :id",
            [':h' => $newHash, ':now' => $nowStr, ':id' => $user['usr_id']]
        );
        unset($_SESSION['nu_must_change_password']);
        echo json_encode(['success'=>true,'message'=>'Password updated successfully.']);
        break;

    // ── Admin: reset another user's password ─────────────────────────────────
    case 'admin_reset':
        if (!$auth->checkAuth()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
        if (!$auth->hasPermission('users.edit')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Forbidden']); exit; }

        $input    = json_decode(file_get_contents('php://input'), true) ?? [];
        $targetId = (int)($input['user_id'] ?? 0);
        $newPwd   = trim($input['new_password'] ?? '');
        $force    = (bool)($input['force_change'] ?? true);

        if (!$targetId || !$newPwd) { echo json_encode(['success'=>false,'message'=>'user_id and new_password required.']); break; }

        $target = $db->fetchOne("SELECT * FROM nu_users WHERE usr_id = :id", [':id' => $targetId]);
        if (!$target) { echo json_encode(['success'=>false,'message'=>'User not found.']); break; }

        $policy = loadPolicy($db);
        $errors = validatePassword($newPwd, $policy, $target['usr_username']);
        if ($errors) { echo json_encode(['success'=>false,'message'=>implode(' ', $errors)]); break; }

        $newHash = password_hash($newPwd, PASSWORD_DEFAULT);
        $nowStr  = date('Y-m-d H:i:s');
        recordPasswordHistory($db, $targetId, $target['usr_password'], (int)$policy['policy_history_count']);
        $db->query(
            "UPDATE nu_users SET usr_password = :h, usr_password_changed_at = :now, usr_must_change_password = :f WHERE usr_id = :id",
            [':h' => $newHash, ':now' => $nowStr, ':f' => $force ? 1 : 0, ':id' => $targetId]
        );
        echo json_encode(['success'=>true,'message'=>'Password reset for user #' . $targetId . '.']);
        break;

    // ── Get current password policy ──────────────────────────────────────────
    case 'get_policy':
        if (!$auth->checkAuth()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
        echo json_encode(['success'=>true,'policy'=>loadPolicy($db)]);
        break;

    // ── Save password policy (admin/globeadmin only) ──────────────────────────
    case 'save_policy':
        if (!$auth->checkAuth()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
        if (!$auth->hasPermission('system.config')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Forbidden']); exit; }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $fields = [
            'policy_min_length'                  => max(6, (int)($input['policy_min_length'] ?? 8)),
            'policy_require_uppercase'           => (int)(bool)($input['policy_require_uppercase'] ?? 1),
            'policy_require_lowercase'            => (int)(bool)($input['policy_require_lowercase'] ?? 1),
            'policy_require_number'              => (int)(bool)($input['policy_require_number'] ?? 1),
            'policy_require_special'             => (int)(bool)($input['policy_require_special'] ?? 0),
            'policy_disallow_username'           => (int)(bool)($input['policy_disallow_username'] ?? 1),
            'policy_history_count'               => max(0, (int)($input['policy_history_count'] ?? 5)),
            'policy_expiry_days'                 => max(0, (int)($input['policy_expiry_days'] ?? 0)),
            'policy_expiry_warning_days'         => max(0, (int)($input['policy_expiry_warning_days'] ?? 7)),
            'policy_force_change_on_first_login' => (int)(bool)($input['policy_force_change_on_first_login'] ?? 1),
        ];

        $existing = $db->fetchOne("SELECT policy_id FROM nu_password_policy WHERE policy_id = 1");
        if ($existing) {
            $sets = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
            $params = array_combine(array_map(fn($k) => ":$k", array_keys($fields)), array_values($fields));
            $params[':id'] = 1;
            $db->query("UPDATE nu_password_policy SET $sets WHERE policy_id = :id", $params);
        } else {
            $cols   = implode(', ', array_keys($fields));
            $phs    = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
            $params = array_combine(array_map(fn($k) => ":$k", array_keys($fields)), array_values($fields));
            $db->query("INSERT INTO nu_password_policy ($cols) VALUES ($phs)", $params);
        }
        echo json_encode(['success'=>true,'message'=>'Password policy saved.']);
        break;

    // ── Check if current user's password has expired ──────────────────────────
    case 'check_expiry':
        if (!$auth->checkAuth()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
        $user   = $auth->getCurrentUser();
        $policy = loadPolicy($db);
        $expiry = (int)$policy['policy_expiry_days'];
        $result = ['expired'=>false,'warning'=>false,'days_remaining'=>null,'must_change'=>(bool)$user['usr_must_change_password']];
        if ($expiry > 0 && $user['usr_password_changed_at']) {
            $changed  = strtotime($user['usr_password_changed_at']);
            $expireAt = $changed + ($expiry * 86400);
            $now      = time();
            $remaining = (int)(($expireAt - $now) / 86400);
            $result['days_remaining'] = $remaining;
            if ($remaining <= 0)  { $result['expired'] = true; }
            elseif ($remaining <= (int)$policy['policy_expiry_warning_days']) { $result['warning'] = true; }
        }
        echo json_encode(['success'=>true] + $result);
        break;

    // ── 2FA: Status check ────────────────────────────────────────────────────
    case 'get_2fa_status':
        if (!$auth->checkAuth()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
        $user = $auth->getCurrentUser();
        echo json_encode(['success'=>true, 'enabled' => !empty($user['usr_2fa_secret'])]);
        break;

    // ── 2FA: Initiate setup ──────────────────────────────────────────────────
    case 'setup_2fa':
        if (!$auth->checkAuth()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
        $secret = $auth->generateTOTPSecret();
        $user   = $auth->getCurrentUser();
        $issuer = rawurlencode('Nuvis');
        $account = rawurlencode($user['usr_username']);
        $otpauth = "otpauth://totp/{$issuer}:{$account}?secret={$secret}&issuer={$issuer}";
        echo json_encode(['success'=>true, 'secret'=>$secret, 'otpauth_url'=>$otpauth]);
        break;

    // ── 2FA: Enable after verification ────────────────────────────────────────
    case 'enable_2fa':
        if (!$auth->checkAuth()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
        $input  = json_decode(file_get_contents('php://input'), true) ?? [];
        $secret = trim($input['secret'] ?? '');
        $code   = trim($input['code'] ?? '');

        if (!$secret || !$code) {
            echo json_encode(['success'=>false,'message'=>'Secret and verification code are required.']);
            break;
        }

        if (!$auth->verifyTOTP($secret, $code)) {
            echo json_encode(['success'=>false,'message'=>'Invalid verification code. Please try again.']);
            break;
        }

        $user = $auth->getCurrentUser();
        $db->query("UPDATE nu_users SET usr_2fa_secret = :s WHERE usr_id = :id", [':s'=>$secret, ':id'=>$user['usr_id']]);
        $backupCodes = $auth->generateBackupCodes($user['usr_id']);

        echo json_encode(['success'=>true, 'message'=>'2FA enabled successfully!', 'backup_codes'=>$backupCodes]);
        break;

    // ── 2FA: Disable ──────────────────────────────────────────────────────────
    case 'disable_2fa':
        if (!$auth->checkAuth()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
        $input      = json_decode(file_get_contents('php://input'), true) ?? [];
        $currentPwd = trim($input['current_password'] ?? '');

        $user = $auth->getCurrentUser();
        if (!password_verify($currentPwd, $user['usr_password'])) {
            echo json_encode(['success'=>false,'message'=>'Incorrect password.']);
            break;
        }

        $db->query("UPDATE nu_users SET usr_2fa_secret = NULL WHERE usr_id = :id", [':id'=>$user['usr_id']]);
        echo json_encode(['success'=>true, 'message'=>'2FA disabled successfully.']);
        break;

    default:
        echo json_encode(['success'=>false,'message'=>'Unknown action.']);
}
?>
