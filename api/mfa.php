<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Encryption.php';
require_once __DIR__ . '/../lib/TOTP.php';

header('Content-Type: application/json');

$auth   = new NuAuth();
$db     = NuDatabase::getInstance();
$action = $_GET['action'] ?? '';

// Support partial session for enrollment
$userId = $_SESSION['nu_user_id'] ?? $_SESSION['nu_user_id_mfa'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user = $db->fetchOne("SELECT * FROM nu_users WHERE usr_id = :id", [':id' => $userId]);
if (!$user) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

switch ($action) {
    case 'get_status':
    case 'status':
        echo json_encode([
            'success'      => true,
            'enabled'      => !empty($user['usr_2fa_enabled']),
            'enforcement'  => $auth->getMfaEnforcementLevel(),
            'mfa_required' => !empty($user['usr_mfa_required']),
            'enrolled_at'  => $user['usr_mfa_enrolled_at'] ?? null,
        ]);
        break;

    case 'setup':
        $secret  = TOTP::generateSecret();
        $issuer  = 'Nuvis';
        $account = $user['usr_username'];
        $qrUrl   = TOTP::getQRCodeUrl($secret, $account, $issuer);

        // Store draft secret
        $_SESSION['nu_mfa_draft_secret'] = $secret;

        echo json_encode([
            'success'     => true,
            'secret'      => $secret,
            'otpauth_url' => $qrUrl,
        ]);
        break;

    case 'confirm':
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $code   = trim((string)($input['code'] ?? ''));
        $secret = trim((string)($input['secret'] ?? $_SESSION['nu_mfa_draft_secret'] ?? ''));

        if (!$secret || !$code) {
            echo json_encode(['success' => false, 'message' => 'Secret and verification code are required.']);
            break;
        }

        if (!TOTP::verify($secret, $code)) {
            echo json_encode(['success' => false, 'message' => 'Invalid verification code. Please check your authenticator app and try again.']);
            break;
        }

        $nowStr      = date('Y-m-d H:i:s');
        $backupCodes = $auth->generateBackupCodes($user['usr_id']);
        $saveSecret  = NuEncryption::encryptField('nu_users', 'usr_2fa_secret', $secret);

        $db->query(
            "UPDATE nu_users SET usr_2fa_secret = :s, usr_2fa_enabled = 1, usr_mfa_enrolled_at = :now WHERE usr_id = :id",
            [':s' => $saveSecret, ':now' => $nowStr, ':id' => $user['usr_id']]
        );

        unset($_SESSION['nu_mfa_draft_secret'], $_SESSION['nu_mfa_partial'], $_SESSION['nu_user_id_mfa']);

        // Upgrade partial session to full session
        $_SESSION['nu_user_id']       = $user['usr_id'];
        $_SESSION['nu_username']      = $user['usr_username'];
        $_SESSION['nu_role']          = $user['usr_role'];
        $_SESSION['nu_last_activity'] = time();

        echo json_encode([
            'success'      => true,
            'message'      => 'MFA successfully enabled!',
            'backup_codes' => $backupCodes,
        ]);
        break;

    case 'disable':
        $input      = json_decode(file_get_contents('php://input'), true) ?? [];
        $currentPwd = trim((string)($input['current_password'] ?? ''));
        $otpCode    = trim((string)($input['code'] ?? ''));

        if (!password_verify($currentPwd, $user['usr_password'])) {
            echo json_encode(['success' => false, 'message' => 'Incorrect password.']);
            break;
        }

        $secret = $user['usr_2fa_secret'] ?? '';
        if (class_exists('NuEncryption') && NuEncryption::isEncrypted($secret)) {
            $secret = NuEncryption::decryptField('nu_users', 'usr_2fa_secret', $secret);
        }

        if (!empty($secret) && !TOTP::verify($secret, $otpCode)) {
            echo json_encode(['success' => false, 'message' => 'Invalid MFA verification code.']);
            break;
        }

        $enforcement = $auth->getMfaEnforcementLevel();
        if ($enforcement === 'required' || ($enforcement === 'required_for_admins' && in_array(strtolower((string)$user['usr_role']), ['globeadmin', 'admin'], true))) {
            echo json_encode(['success' => false, 'message' => 'MFA is mandatory for your role or organization and cannot be disabled.']);
            break;
        }

        $db->query(
            "UPDATE nu_users SET usr_2fa_secret = NULL, usr_2fa_enabled = 0, usr_mfa_enrolled_at = NULL WHERE usr_id = :id",
            [':id' => $user['usr_id']]
        );

        echo json_encode(['success' => true, 'message' => 'MFA disabled successfully.']);
        break;

    case 'backup_codes':
        $codes = $auth->generateBackupCodes($user['usr_id']);
        echo json_encode(['success' => true, 'backup_codes' => $codes]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
}
