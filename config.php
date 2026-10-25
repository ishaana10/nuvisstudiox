<?php
declare(strict_types=1);
// nuvis - Master Configuration

define('NU_VERSION', '5.0.17');
define('NU_BUILD_DATE', '2026-10-25');
if (!defined('NU_ROOT')) define('NU_ROOT', __DIR__);

// ─── Database ─────────────────────────────────────────────────────────────────
if (!isset($nuConfig)) $nuConfig = [];
$nuConfig['dbHost']     = getenv('NU_DB_HOST')    ?: 'localhost';
$nuConfig['dbName']     = getenv('NU_DB_NAME')    ?: 'your_db_name';
$nuConfig['dbUser']     = getenv('NU_DB_USER')    ?: 'your_db_user';
$nuConfig['dbPassword'] = getenv('NU_DB_PASS')    ?: 'your_db_password';
$nuConfig['dbCharset']  = 'utf8mb4';
$nuConfig['dbPort']     = (int)(getenv('NU_DB_PORT') ?: 3306);

// ─── Security ─────────────────────────────────────────────────────────────────
$nuConfig['sessionTimeout']        = 1800;
$nuConfig['maxLoginAttempts']      = 5;
$nuConfig['lockoutDuration']       = 1800;
$nuConfig['csrfTokenName']         = 'nu_csrf';
$nuConfig['sessionCookieSecure']   = true;
$nuConfig['sessionCookieHttpOnly'] = true;
$nuConfig['sessionCookieSameSite'] = 'Strict';
$nuConfig['passwordMinLength']     = 12;

$nuConfig['password'] = [
    'minLength'       => 12,
    'requireUpper'    => true,
    'requireLower'    => true,
    'requireNumber'   => true,
    'requireSpecial'  => true,
    'maxAgeDays'      => 90,
    'historyCount'    => 5,
    'lockoutAttempts' => 5,
    'lockoutMinutes'  => 30,
];

// ─── Application-Level Field Encryption ─────────────────────────────────────
$nuConfig['encryption'] = [
    'key' => getenv('NU_ENCRYPTION_KEY') ?: 'base64:' . base64_encode(hash('sha256', 'nuvis_master_encryption_key_2026', true)),
    'enabled_fields' => [
        'nu_users' => ['usr_2fa_secret'],
    ],
];

// ─── HTTP Security Headers ───────────────────────────────────────────────────
require_once NU_ROOT . '/core/SecurityHeaders.php';

// ─── Redis ────────────────────────────────────────────────────────────────────
$nuConfig['redis'] = [
    'enabled'     => false, // Set true in config.local.php or env when active
    'scheme'      => 'unix',
    'path'        => '/home/ictfjcom/.redis/redis.sock',
    'host'        => '127.0.0.1',
    'port'        => 6379,
    'password'    => null,
    'database'    => 0,
    'prefix'      => 'nuvis:',
    'timeout'     => 2.5,
    'default_ttl' => 300,
];

// ─── Features ─────────────────────────────────────────────────────────────────
$nuConfig['enable2FA']         = true;
$nuConfig['enableAPI']         = true;
$nuConfig['enableAuditTrail']  = true;
$nuConfig['enableFileUploads'] = true;
$nuConfig['maxUploadSize']     = 10485760;
$nuConfig['allowedFileTypes']  = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','gif','csv'];

// ─── Paths ────────────────────────────────────────────────────────────────────
$nuConfig['baseUrl']    = rtrim(getenv('NU_BASE_URL') ?: '/nbv5u/m/', '/') . '/';
$nuConfig['uploadPath'] = NU_ROOT . '/uploads/';
$nuConfig['logPath']    = NU_ROOT . '/logs/';

// ─── Display ──────────────────────────────────────────────────────────────────
$nuConfig['siteTitle'] = 'nuvis';
$nuConfig['theme']     = 'auto';
$nuConfig['userHeaderFormat'] = '{name} | {location}';

// ─── API ──────────────────────────────────────────────────────────────────────
$nuConfig['apiRateLimit'] = 1000;
$nuConfig['apiKeyHeader'] = 'X-API-Key';
$nuConfig['enableWebhookDemo'] = true;
$nuConfig['enableGitUpdater'] = true;
$nuConfig['allowApiKeyQueryParam'] = false;

// ─── PHP Error Handling ───────────────────────────────────────────────────────
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

// ─── Load Local Overrides BEFORE session_start ────────────────────────────────
// config.local.php can override dbHost, dbName, dbPassword, baseUrl,
// sessionCookieSecure etc. It MUST be loaded before the session block below.
$_localOverride = NU_ROOT . '/config.local.php';
if (is_file($_localOverride)) {
    require $_localOverride;
}
unset($_localOverride);

// ─── Load Environment-Specific Config Overrides ───────────────────────────────
// Automatically loads config.production.php, config.staging.php etc. depending on NU_ENV
$_env = getenv('NU_ENV') ?: 'development';
$_envFile = NU_ROOT . '/config.' . $_env . '.php';
if (is_file($_envFile)) {
    require $_envFile;
}
unset($_env, $_envFile);

// ─── Session Hardening + Start ────────────────────────────────────────────────
require_once NU_ROOT . '/core/SessionBootstrap.php';

if (!function_exists('h')) {
    function h($string, $flags = ENT_QUOTES, $encoding = 'UTF-8') {
        return htmlspecialchars((string)$string, $flags, $encoding);
    }
}
