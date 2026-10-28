<?php
declare(strict_types=1);
/** OAuth redirect target for Google Drive / OneDrive / Dropbox. Register this address in the provider's developer console. */
require_once dirname(__DIR__) . '/core/module_bootstrap.php';
require_once dirname(__DIR__) . '/core/FileStore.php';

$user = $auth->getCurrentUser();
$role = strtolower((string)($user['usr_role'] ?? ''));
$back = dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/api/cloud_oauth.php'))) ;
$back = rtrim($back === '\\' ? '' : $back, '/') . '/index.php#cloud_storage';

function co_finish(string $back, bool $ok, string $msg): never {
    $_SESSION['nu_cloud_flash'] = ['ok' => $ok, 'message' => $msg];
    header('Location: ' . $back); exit;
}

if (!$user || !in_array($role, ['globeadmin', 'admin'], true)) { http_response_code(403); exit('Administrators only'); }
$st = $_SESSION['nu_cloud_oauth'] ?? null; unset($_SESSION['nu_cloud_oauth']);
if (!$st || $st['exp'] < time() || !hash_equals((string)$st['state'], (string)($_GET['state'] ?? '')) || (string)$st['user'] !== (string)($_SESSION['nu_user_id'] ?? '')) {
    co_finish($back, false, 'The sign-in link expired or did not match. Please start the connection again.');
}
if (!empty($_GET['error'])) co_finish($back, false, 'The provider reported: ' . mb_substr((string)($_GET['error_description'] ?? $_GET['error']), 0, 200));
$code = (string)($_GET['code'] ?? '');
if ($code === '') co_finish($back, false, 'No authorisation code was returned.');

$p = (string)$st['provider'];
$cfg = NuCloudConfig::get(true);
try {
    $prov = NuCloud::provider($p, $cfg);
    $res = $prov->exchangeCode($code, NuCloudConfig::redirectUri($cfg));
    NuCloudConfig::setConnection($p, ['refresh_token' => $res['refresh_token'], 'account' => $res['account'], 'connected_at' => date('Y-m-d H:i:s')]);
    try { (new NuAudit())->event('cloud_connect', $prov->label() . ' connected' . ($res['account'] ? ' as ' . $res['account'] : ''), ['provider' => $p], 'success', ['table' => 'cloud_storage']); } catch (Throwable $t) {}
    co_finish($back, true, $prov->label() . ' connected' . ($res['account'] ? ' as ' . $res['account'] : '') . '.');
} catch (Throwable $e) {
    try { (new NuAudit())->event('cloud_connect', 'Connecting ' . $p . ' failed', ['error' => $e->getMessage()], 'failure', ['table' => 'cloud_storage']); } catch (Throwable $t) {}
    co_finish($back, false, $e->getMessage());
}
