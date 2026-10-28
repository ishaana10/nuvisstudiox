<?php
declare(strict_types=1);
/**
 * NuAuth - Authentication, Session Management & Permission Engine
 */

require_once __DIR__ . '/Audit.php';

class NuAuth {
    private $db;
    private $config;
    private static $instance = null;
    private array $permCache = [];

    public function __construct() {
        global $nuConfig;
        $this->config = $nuConfig;
        $this->db = NuDatabase::getInstance();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // --- Login ---
    public function login($username, $password, $otp = null) {
        $user = $this->db->fetchOne(
            "SELECT * FROM nu_users WHERE usr_username = :u AND usr_active = 1",
            [':u' => $username]
        );

        if (!$user) { $this->logAuthFailure('Unknown or inactive user', (string)$username, null); return ['success' => false, 'message' => 'Invalid credentials']; }

        if ($user['usr_failed_attempts'] >= $this->config['maxLoginAttempts']) {
            $lastAttempt = strtotime((string)($user['usr_last_attempt'] ?? ''));
            if ($lastAttempt && (time() - $lastAttempt < $this->config['lockoutDuration'])) {
                $this->logAuthFailure('Account locked after repeated failures', (string)$username, $user['usr_id']);
                return ['success' => false, 'message' => 'Account locked. Try again later.'];
            }
            $this->resetAttempts($user['usr_id']);
        }

        if (!password_verify($password, $user['usr_password'])) {
            $this->incrementAttempts($user['usr_id']);
            $this->logAuthFailure('Wrong password', (string)$username, $user['usr_id']);
            return ['success' => false, 'message' => 'Invalid credentials'];
        }

        $mfaEnforcement = $this->getMfaEnforcementLevel(); // 'optional' | 'required' | 'required_for_admins'

        $mustUseMfa = !empty($user['usr_2fa_enabled'])
                   || !empty($user['usr_mfa_required'])
                   || $mfaEnforcement === 'required'
                   || ($mfaEnforcement === 'required_for_admins' && in_array(strtolower((string)($user['usr_role'] ?? '')), ['globeadmin', 'admin'], true));

        $totpSecret = $user['usr_2fa_secret'] ?? '';
        if (class_exists('NuEncryption') && NuEncryption::isEncrypted($totpSecret)) {
            require_once __DIR__ . '/Encryption.php';
            $totpSecret = NuEncryption::decryptField('nu_users', 'usr_2fa_secret', $totpSecret);
        }

        if ($mustUseMfa) {
            if (empty($totpSecret)) {
                $this->createPartialSession($user);
                return [
                    'success' => false,
                    'message' => 'MFA enrollment required before logging in.',
                    'requiresMfaEnrollment' => true,
                    'user_id' => $user['usr_id']
                ];
            }

            if (!$otp || !$this->verifyTOTP($totpSecret, $otp)) {
                if (!$this->verifyBackupCode($user['usr_id'], (string)$otp)) {
                    $this->logAuthFailure($otp ? 'Wrong MFA code' : 'MFA code missing', (string)$username, $user['usr_id']);
                    return [
                        'success' => false,
                        'message' => 'Invalid or missing MFA code',
                        'requires2FA' => true
                    ];
                }
            }
        }

        // Enforce password change for default credentials or when usr_must_change_password is set
        $mustChange = !empty($user['usr_must_change_password']);
        if (($user['usr_username'] === 'globeadmin' && password_verify('password', $user['usr_password'])) || $password === 'password') {
            $mustChange = true;
            try {
                $this->db->query("UPDATE nu_users SET usr_must_change_password = 1 WHERE usr_id = :id", [':id' => $user['usr_id']]);
                $user['usr_must_change_password'] = 1;
            } catch (\Throwable $t) {}
        }

        $this->resetAttempts($user['usr_id']);
        $this->createSession($user);
        $this->logAudit('login', 'nu_users', $user['usr_id']);

        // Trigger Outgoing Webhooks for user_login
        try {
            require_once __DIR__ . '/WebhookSender.php';
            NuWebhookSender::trigger('user_login', [
                'table'     => 'nu_users',
                'record_id' => $user['usr_id'],
                'data'      => [
                    'usr_id'       => $user['usr_id'],
                    'usr_username' => $user['usr_username'],
                    'usr_email'    => $user['usr_email'] ?? '',
                    'usr_role'     => $user['usr_role'] ?? ''
                ]
            ]);
        } catch (\Throwable $whe) {
            error_log('[Webhook Login Trigger Error] ' . $whe->getMessage());
        }

        return ['success' => true, 'user' => $this->sanitizeUser($user)];
    }

    // --- SSO Login ---
    public function loginViaSso(array $user): array {
        $this->resetAttempts($user['usr_id']);
        $this->createSession($user);
        $this->logAudit('sso_login', 'nu_users', $user['usr_id']);
        return ['success' => true, 'user' => $this->sanitizeUser($user)];
    }

    // --- Logout ---
    public function logout() {
        if (isset($_SESSION['nu_user_id'])) {
            $this->logAudit('logout', 'nu_users', $_SESSION['nu_user_id']);
        }
        session_destroy();
        return true;
    }

    // --- Session Check ---
    public function checkAuth() {
        if (empty($_SESSION['nu_user_id'])) return false;
        $timeout = (int)($this->config['sessionTimeout'] ?? 1800);
        if (time() - ($_SESSION['nu_last_activity'] ?? 0) > $timeout) {
            $this->logout();
            return false;
        }
        $_SESSION['nu_last_activity'] = time();
        return true;
    }

    public function isLoggedIn() {
        return $this->checkAuth();
    }

    // --- Current User ---
    public function getCurrentUser() {
        if (!$this->checkAuth()) return null;
        if (($_SESSION['nu_user_id'] ?? '') === 'guest_tester') {
            return [
                'usr_id' => 'guest_tester',
                'usr_username' => 'guest_tester',
                'usr_name' => 'Guest Tester (Public Test Mode)',
                'usr_role' => 'user',
                'usr_active' => 1
            ];
        }
        return $this->db->fetchOne(
            "SELECT * FROM nu_users WHERE usr_id = :id",
            [':id' => $_SESSION['nu_user_id']]
        );
    }

    public function getCurrentRole(): string {
        return $_SESSION['nu_role'] ?? '';
    }

    // --- CSRF ---
    public function getCsrfToken() {
        if (empty($_SESSION['nu_csrf'])) {
            $_SESSION['nu_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['nu_csrf'];
    }

    public function verifyCsrf($token) {
        return isset($_SESSION['nu_csrf']) && hash_equals($_SESSION['nu_csrf'], (string)$token);
    }

    public function verifyCsrfToken($token) {
        return $this->verifyCsrf($token);
    }

    // --- Legacy permission check (module-level) ---
    public function hasPermission($permission) {
        $user = $this->getCurrentUser();
        if (!$user) return false;
        if ($user['usr_role'] === 'globeadmin') return true;

        $cacheKey = 'has_perm_' . $user['usr_role'] . '_' . $permission;
        if (array_key_exists($cacheKey, $this->permCache)) {
            return $this->permCache[$cacheKey];
        }

        $perms = $this->db->fetchAll(
            "SELECT p.perm_code FROM nu_permissions p
             JOIN nu_role_permissions rp ON p.perm_id = rp.rp_perm_id
             JOIN nu_roles r ON rp.rp_role_id = r.role_id
             WHERE r.role_code = :role",
            [':role' => $user['usr_role']]
        );

        $permCodes = array_column($perms, 'perm_code');
        $has       = in_array($permission, $permCodes, true);
        $this->permCache[$cacheKey] = $has;
        return $has;
    }

    // --- Form-level permission check ---
    // action: 'view' | 'add' | 'edit' | 'delete' | 'export'
    public function canForm(string $formCode, string $action): bool {
        $user = $this->getCurrentUser();
        if (!$user) return false;
        if ($user['usr_role'] === 'globeadmin') return true;

        $perms = $this->formPerms($formCode);
        return $perms[$action] ?? false;
    }

    // Returns all 5 flags for a form — used by FormRenderer to inject data attrs
    public function formPerms(string $formCode): array {
        $user = $this->getCurrentUser();
        if (!$user) return ['view'=>false,'add'=>false,'edit'=>false,'delete'=>false,'export'=>false];
        if ($user['usr_role'] === 'globeadmin') {
            return ['view'=>true,'add'=>true,'edit'=>true,'delete'=>true,'export'=>true];
        }

        $role = $user['usr_role'];
        $cacheKey = 'form_perms_' . $role . '_' . $formCode;
        if (isset($this->permCache[$cacheKey])) {
            return $this->permCache[$cacheKey];
        }

        $row  = $this->db->fetchOne(
            "SELECT * FROM nu_role_form_permissions
             WHERE rfp_role_code = :role AND rfp_form_code = :form",
            [':role' => $role, ':form' => $formCode]
        );
        if (!$row) {
            $row = $this->db->fetchOne(
                "SELECT * FROM nu_role_form_permissions
                 WHERE rfp_role_code = :role AND rfp_form_code = '*'",
                [':role' => $role]
            );
        }

        $res = !$row ? ['view'=>false,'add'=>false,'edit'=>false,'delete'=>false,'export'=>false] : [
            'view'   => (bool)($row['rfp_can_view'] ?? false),
            'add'    => (bool)($row['rfp_can_add'] ?? false),
            'edit'   => (bool)($row['rfp_can_edit'] ?? false),
            'delete' => (bool)($row['rfp_can_delete'] ?? false),
            'export' => (bool)($row['rfp_can_export'] ?? false),
        ];

        $this->permCache[$cacheKey] = $res;
        return $res;
    }

    // -------------------------------------------------------------------------
    // --- Global user meta: ##key## hash replacement in SQL ------------------
    // -------------------------------------------------------------------------

    /**
     * Replace ##key## placeholders in a SQL string with the current user's
     * global meta values stored in $_SESSION['nu_user_meta'].
     *
     * Only keys defined as 'global' => true in config.user_fields.php are
     * available. Values are escaped for safe embedding.
     *
     * Example:
     *   Input:  "SELECT * FROM sales WHERE station = '##station##'"
     *   Output: "SELECT * FROM sales WHERE station = 'North'"
     *
     * If a key has no value in session, the placeholder is replaced with empty string.
     */
    public function resolveHashes(string $sql): string {
        $meta = $this->getGlobalMeta();
        if (empty($meta)) return $sql;

        $pdo = null;
        try {
            $pdo = NuDatabase::getInstance()->getPdo();
        } catch (\Throwable $e) {}

        foreach ($meta as $key => $value) {
            $safeKey = preg_quote((string)$key, '/');
            if ($value === null) {
                $quotedVal = "''";
            } else if (is_int($value) || is_float($value)) {
                $quotedVal = (string)$value;
            } else if (is_numeric($value) && (string)(int)$value === (string)$value) {
                $quotedVal = (string)$value;
            } else if ($pdo) {
                $quotedVal = $pdo->quote((string)$value);
            } else {
                $quotedVal = "'" . addslashes((string)$value) . "'";
            }

            // Replace single-quoted placeholders ('##key##') or unquoted placeholders (##key##) safely
            $sql = preg_replace("/'##" . $safeKey . "##'/", $quotedVal, $sql);
            $sql = preg_replace("/##" . $safeKey . "##/", $quotedVal, $sql);
        }
        return $sql;
    }

    /**
     * Get the current user's global meta as a key=>value array.
     * Useful for JS injection or debug.
     */
    public function getGlobalMeta(): array {
        if (isset($this->permCache['global_meta'])) {
            return $this->permCache['global_meta'];
        }
        $meta = $_SESSION['nu_user_meta'] ?? [];

        // Load custom global developer settings from nu_system_settings
        try {
            $db = NuDatabase::getInstance();
            $row = $db->fetchOne("SELECT setting_value FROM nu_system_settings WHERE setting_key = 'system_fields_def'");
            if ($row && !empty($row['setting_value'])) {
                $fields = json_decode($row['setting_value'], true);
                if (is_array($fields)) {
                    foreach ($fields as $f) {
                        if (!empty($f['global']) && !empty($f['key'])) {
                            $meta[$f['key']] = $f['value'] ?? '';
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[Auth.php getGlobalMeta] ' . $e->getMessage());
        }

        $this->permCache['global_meta'] = $meta;
        return $meta;
    }

    // -------------------------------------------------------------------------

    public function requireAuth() {
        if (!$this->checkAuth()) {
            if ($this->isApiRequest()) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Unauthorized']);
                exit;
            }
            header('Location: ../index.php');
            exit;
        }

        if (!empty($_SESSION['nu_must_change_password'])) {
            $uri = $_SERVER['REQUEST_URI'] ?? '';
            $isPasswordEndpoint = (strpos($uri, 'password') !== false);
            if (!$isPasswordEndpoint) {
                if ($this->isApiRequest()) {
                    http_response_code(403);
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'error' => 'Password change required before proceeding.', 'must_change_password' => true]);
                    exit;
                } else {
                    if (strpos($uri, 'index.php') === false) {
                        header('Location: ../index.php');
                        exit;
                    }
                }
            }
        }
    }

    public function requirePermission($permission) {
        $this->requireAuth();
        if (!$this->hasPermission($permission)) {
            if ($this->isApiRequest()) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Forbidden']);
                exit;
            }
            http_response_code(403);
            exit('Access denied.');
        }
    }

    public function requireFormPerm(string $formCode, string $action) {
        $this->requireAuth();
        if (!$this->canForm($formCode, $action)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'You do not have ' . $action . ' permission on this form.']);
            exit;
        }
    }

    public function regenerateSession(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    // --- Private Helpers ---
    private function createSession($user) {
        $this->regenerateSession();
        $_SESSION['nu_user_id']       = $user['usr_id'];  // preserved as-is: string (UUID) or int
        $_SESSION['nu_username']      = $user['usr_username'];
        $_SESSION['nu_role']          = $user['usr_role'];
        $_SESSION['nu_last_activity'] = time();
        $_SESSION['nu_csrf']          = bin2hex(random_bytes(32));

        if (!empty($user['usr_must_change_password']) || ($user['usr_username'] === 'globeadmin' && password_verify('password', $user['usr_password']))) {
            $_SESSION['nu_must_change_password'] = true;
        } else {
            unset($_SESSION['nu_must_change_password']);
        }

        // Pass usr_id without casting — loadGlobalMeta accepts string or int.
        // A (int) cast here would silently zero any UUID string.
        $_SESSION['nu_user_meta'] = $this->loadGlobalMeta($user['usr_id']);
    }

    /**
     * Load all 'global' meta values for a user.
     * Supports system fields and dynamic fields saved in usr_custom_fields JSON.
     * Accepts string or int $userId to support both UUID and auto_increment modes.
     * Returns an associative array of global hashes.
     *
     * @param mixed $userId  string (UUID) or int
     */
    private function loadGlobalMeta($userId): array {
        $user = $this->db->fetchOne(
            "SELECT * FROM nu_users WHERE usr_id = :id",
            [':id' => $userId]
        );
        if (!$user) return [];

        $fieldsDef = nu_get_user_fields_def($this->db);
        if (empty($fieldsDef)) return [];

        $meta = [];
        $customValues = json_decode($user['usr_custom_fields'] ?? '{}', true);

        foreach ($fieldsDef as $f) {
            if (!empty($f['global'])) {
                $key = $f['key'];
                if (!empty($f['is_system'])) {
                    $val = $user[$key] ?? '';
                    $meta[$key] = $val;
                    $shortKey = str_replace('usr_', '', $key);
                    $meta[$shortKey] = $val;
                } else {
                    $val = $customValues[$key] ?? null;
                    if ($val === null) {
                        $metaVal = $this->db->fetchOne(
                            "SELECT umeta_value FROM nu_user_meta WHERE umeta_user_id = :id AND umeta_key = :k",
                            [':id' => $userId, ':k' => $key]
                        );
                        $val = $metaVal ? $metaVal['umeta_value'] : '';
                    }
                    $meta[$key] = $val;
                }
            }
        }

        return $meta;
    }

    private function incrementAttempts($userId) {
        $nowStr = date('Y-m-d H:i:s');
        $this->db->query(
            "UPDATE nu_users SET usr_failed_attempts = usr_failed_attempts + 1, usr_last_attempt = :now WHERE usr_id = :id",
            [':now' => $nowStr, ':id' => $userId]
        );
    }

    private function resetAttempts($userId) {
        $this->db->query(
            "UPDATE nu_users SET usr_failed_attempts = 0, usr_last_attempt = NULL WHERE usr_id = :id",
            [':id' => $userId]
        );
    }

    /** Failed sign-in attempts are always recorded (independent of enableAuditTrail): they are security events. */
    private function logAuthFailure(string $reason, string $username, $userId): void {
        try {
            (new NuAudit())->event('login_failed', $reason . ' for "' . mb_substr($username, 0, 80) . '"', ['reason' => $reason],
                'failure', ['username' => mb_substr($username, 0, 80), 'user_id' => $userId, 'table' => 'nu_users', 'record_id' => $userId]);
        } catch (\Throwable $t) {}
    }

    private function logAudit($action, $table, $recordId) {
        if (empty($this->config['enableAuditTrail'])) return;
        $audit = new NuAudit();
        $audit->log($action, $table, $recordId);
    }

    private function sanitizeUser($user) {
        unset($user['usr_password']);
        unset($user['usr_2fa_secret']);
        return $user;
    }

    private function isApiRequest() {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return (
            !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
            strpos($uri, '/api/') !== false
        );
    }

    // --- TOTP 2FA Helpers ---
    public function getMfaEnforcementLevel(): string {
        try {
            $row = $this->db->fetchOne("SELECT setting_value FROM nu_system_settings WHERE setting_key = 'mfa_enforcement'");
            if ($row && !empty($row['setting_value'])) {
                return (string)$row['setting_value'];
            }
        } catch (\Throwable $e) {}
        return 'optional';
    }

    public function createPartialSession($user): void {
        $this->regenerateSession();
        $_SESSION['nu_user_id_mfa'] = $user['usr_id'];
        $_SESSION['nu_mfa_partial']  = true;
    }

    public function generateTOTPSecret(): string {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < 16; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }
        return $secret;
    }

    public function generateBackupCodes($userId): array {
        $codes = [];
        $hashes = [];
        for ($i = 0; $i < 8; $i++) {
            $code = sprintf('%04d-%04d', random_int(1000, 9999), random_int(1000, 9999));
            $codes[] = $code;
            $hashes[] = password_hash(str_replace('-', '', $code), PASSWORD_BCRYPT);
        }
        try {
            $user = $this->db->fetchOne("SELECT usr_custom_fields FROM nu_users WHERE usr_id = :id", [':id' => $userId]);
            $custom = json_decode($user['usr_custom_fields'] ?? '{}', true) ?: [];
            $custom['backup_codes'] = $hashes;
            try {
                $this->db->query("UPDATE nu_users SET usr_custom_fields = :c, usr_2fa_backup_codes = :bc WHERE usr_id = :id", [
                    ':c'  => json_encode($custom),
                    ':bc' => json_encode($hashes),
                    ':id' => $userId
                ]);
            } catch (\Throwable $t) {
                $this->db->query("UPDATE nu_users SET usr_custom_fields = :c WHERE usr_id = :id", [':c' => json_encode($custom), ':id' => $userId]);
            }
        } catch (\Throwable $e) {}
        return $codes;
    }

    public function verifyBackupCode($userId, string $code): bool {
        $cleanCode = str_replace(['-', ' '], '', trim($code));
        if ($cleanCode === '') return false;
        $user = $this->db->fetchOne("SELECT * FROM nu_users WHERE usr_id = :id", [':id' => $userId]);
        if (!$user) return false;

        $custom = json_decode($user['usr_custom_fields'] ?? '{}', true) ?: [];
        $hashes = json_decode($user['usr_2fa_backup_codes'] ?? '[]', true) ?: ($custom['backup_codes'] ?? []);
        if (!is_array($hashes) || empty($hashes)) return false;

        foreach ($hashes as $idx => $hash) {
            if (password_verify($cleanCode, $hash)) {
                unset($hashes[$idx]);
                $hashes = array_values($hashes);
                $custom['backup_codes'] = $hashes;
                try {
                    $this->db->query("UPDATE nu_users SET usr_custom_fields = :c, usr_2fa_backup_codes = :bc WHERE usr_id = :id", [
                        ':c'  => json_encode($custom),
                        ':bc' => json_encode($hashes),
                        ':id' => $userId
                    ]);
                } catch (\Throwable $t) {
                    $this->db->query("UPDATE nu_users SET usr_custom_fields = :c WHERE usr_id = :id", [':c' => json_encode($custom), ':id' => $userId]);
                }
                return true;
            }
        }
        return false;
    }

    public function verifyTOTP($secret, $otp) {
        $timeSlice = floor(time() / 30);
        for ($i = -1; $i <= 1; $i++) {
            $calculated = $this->generateTOTP($secret, $timeSlice + $i);
            if (hash_equals($calculated, $otp)) return true;
        }
        return false;
    }

    private function generateTOTP($secret, $timeSlice) {
        $secret = base32_decode($secret);
        $time   = pack('N*', 0) . pack('N*', $timeSlice);
        $hm     = hash_hmac('sha1', $time, $secret, true);
        $offset = ord(substr($hm, -1)) & 0x0F;
        $code   = ((ord($hm[$offset])   & 0x7F) << 24) |
                  ((ord($hm[$offset+1]) & 0xFF) << 16) |
                  ((ord($hm[$offset+2]) & 0xFF) <<  8) |
                   (ord($hm[$offset+3]) & 0xFF);
        return str_pad((string)($code % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function __clone() {}
    public function __wakeup() { throw new RuntimeException('Cannot unserialize NuAuth.'); }
}

if (!class_exists('Auth')) {
    class_alias('NuAuth', 'Auth');
}

function base32_decode($input) {
    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $input = strtoupper(str_replace('=', '', (string)$input));
    $output = '';
    $buffer = 0;
    $bufferSize = 0;
    for ($i = 0; $i < strlen($input); $i++) {
        $val = strpos($map, $input[$i]);
        if ($val === false) continue;
        $buffer = ($buffer << 5) | $val;
        $bufferSize += 5;
        if ($bufferSize >= 8) {
            $bufferSize -= 8;
            $output .= chr(($buffer >> $bufferSize) & 0xFF);
        }
    }
    return $output;
}

function nu_ensure_custom_user_columns(NuDatabase $db) {
    static $ensured = false;
    if ($ensured) return;
    try {
        $db->query("SELECT usr_custom_fields FROM nu_users LIMIT 1");
    } catch (Exception $e) {
        try {
            $db->exec("ALTER TABLE nu_users ADD COLUMN usr_custom_fields LONGTEXT DEFAULT NULL");
        } catch (Exception $ex) {}
    }
    try {
        $db->query("SELECT usr_custom_fields_def FROM nu_users LIMIT 1");
    } catch (Exception $e) {
        try {
            $db->exec("ALTER TABLE nu_users ADD COLUMN usr_custom_fields_def LONGTEXT DEFAULT NULL");
        } catch (Exception $ex) {}
    }
    $ensured = true;
}

function nu_get_user_fields_def(NuDatabase $db): array {
    static $cachedUserFieldsDef = null;
    if ($cachedUserFieldsDef !== null) {
        return $cachedUserFieldsDef;
    }
    nu_ensure_custom_user_columns($db);
    $row = $db->fetchOne("SELECT usr_custom_fields_def FROM nu_users WHERE usr_username = 'globeadmin'");
    if ($row && !empty($row['usr_custom_fields_def'])) {
        return json_decode($row['usr_custom_fields_def'], true);
    }

    // Default system fields
    $defs = [
        ['key' => 'usr_username', 'label' => 'Username', 'type' => 'text', 'is_system' => true, 'global' => false],
        ['key' => 'usr_email', 'label' => 'Email', 'type' => 'email', 'is_system' => true, 'global' => false],
        ['key' => 'usr_role', 'label' => 'Role', 'type' => 'select', 'is_system' => true, 'global' => false],
        ['key' => 'usr_active', 'label' => 'Active', 'type' => 'checkbox', 'is_system' => true, 'global' => false],
    ];

    // Fallback to config.user_fields.php if exists
    $metaConfigFile = NU_ROOT . '/config.user_fields.php';
    if (file_exists($metaConfigFile)) {
        $metaFields = include $metaConfigFile;
        if (is_array($metaFields)) {
            foreach ($metaFields as $mf) {
                if (($mf['key'] ?? '') === 'user_id') continue;
                $defs[] = [
                    'key' => $mf['key'],
                    'label' => $mf['label'],
                    'type' => $mf['type'] ?? 'text',
                    'options' => isset($mf['options']) ? implode(',', $mf['options']) : '',
                    'is_system' => false,
                    'global' => !empty($mf['global']),
                ];
            }
        }
    }
    return $defs;
}
