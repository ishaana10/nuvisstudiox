<?php
declare(strict_types=1);
/**
 * Cloud storage administration (administrators only).
 *   GET  ?action=get_config
 *   GET  ?action=recent              latest files with their cloud status
 *   POST ?action=save_config         {config:{...}}      secrets: blank = keep
 *   POST ?action=connect_url         {provider}          returns the provider sign-in address
 *   POST ?action=disconnect          {provider}
 *   POST ?action=test                {provider?}         connects, uploads a tiny file, reads it back, deletes it
 *   POST ?action=retry_failed | backfill
 */
require_once dirname(__DIR__) . '/core/module_bootstrap.php';
require_once dirname(__DIR__) . '/core/FileStore.php';

header('Content-Type: application/json; charset=utf-8');
function cs_out(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d); exit; }
function cs_audit(string $a, string $s, array $d = [], string $st = 'success'): void { try { (new NuAudit())->event($a, $s, $d, $st, ['table' => 'cloud_storage']); } catch (Throwable $t) {} }

$user = $auth->getCurrentUser();
$role = strtolower((string)($user['usr_role'] ?? ''));
if (!$user || !in_array($role, ['globeadmin', 'admin'], true)) cs_out(['success' => false, 'error' => 'Administrators only'], 403);

$action = (string)($_GET['action'] ?? '');
$readOnly = ['get_config', 'recent'];
$body = [];
if (!in_array($action, $readOnly, true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') cs_out(['success' => false, 'error' => 'POST required'], 405);
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === false) cs_out(['success' => false, 'error' => 'JSON body required'], 415);
    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
}
NuFileStore::ensureSchema();
$cfg = NuCloudConfig::get();

switch ($action) {
    case 'get_config':
        $db = NuDatabase::getInstance();
        $st = [];
        foreach ($db->fetchAll("SELECT COALESCE(file_remote_status,'none') s, COUNT(*) c FROM nu_files GROUP BY COALESCE(file_remote_status,'none')") as $r) $st[$r['s']] = (int)$r['c'];
        $flash = $_SESSION['nu_cloud_flash'] ?? null; unset($_SESSION['nu_cloud_flash']);
        cs_out(['success' => true, 'config' => NuCloudConfig::publicView($cfg), 'labels' => NuCloud::labels(), 'counts' => $st, 'curl' => function_exists('curl_init'),
                'writable' => is_writable(dirname(NuCloudConfig::file())), 'flash' => $flash]);

    case 'recent':
        $rows = NuDatabase::getInstance()->fetchAll("SELECT file_id, file_original_name, file_size, file_storage, file_remote_status, file_remote_error, file_remote_url, file_uploaded_at FROM nu_files ORDER BY file_id DESC LIMIT 20");
        cs_out(['success' => true, 'rows' => $rows]);

    case 'save_config':
        try { $new = NuCloudConfig::save(is_array($body['config'] ?? null) ? $body['config'] : []); }
        catch (Throwable $e) { cs_out(['success' => false, 'error' => $e->getMessage()]); }
        if (($new['provider'] ?? 'local') !== 'local' && !NuCloudConfig::isConnected($new['provider'], $new)) {
            $warn = 'Saved, but ' . NuCloud::labels()[$new['provider']] . ' is not connected yet, so files stay on this server until you connect it.';
        }
        cs_audit('cloud_settings_save', 'Cloud storage settings changed', ['provider' => $new['provider'], 'mode' => $new['mode'], 'folder' => $new['folder']]);
        cs_out(['success' => true, 'warning' => $warn ?? '', 'config' => NuCloudConfig::publicView($new)]);

    case 'connect_url':
        $p = (string)($body['provider'] ?? '');
        $prov = in_array($p, ['gdrive', 'onedrive', 'dropbox'], true) ? NuCloud::provider($p, $cfg) : null;
        if (!$prov) cs_out(['success' => false, 'error' => 'This provider does not use browser sign-in.']);
        $idKey = $p === 'dropbox' ? 'app_key' : 'client_id'; $secKey = $p === 'dropbox' ? 'app_secret' : 'client_secret';
        if (($cfg[$p][$idKey] ?? '') === '' || ($cfg[$p][$secKey] ?? '') === '') cs_out(['success' => false, 'error' => 'Save the client ID and secret first.']);
        $state = bin2hex(random_bytes(16));
        $_SESSION['nu_cloud_oauth'] = ['state' => $state, 'provider' => $p, 'exp' => time() + 600, 'user' => $_SESSION['nu_user_id'] ?? ''];
        cs_out(['success' => true, 'url' => $prov->authUrl(NuCloudConfig::redirectUri($cfg), $state)]);

    case 'disconnect':
        $p = (string)($body['provider'] ?? '');
        if (!in_array($p, ['gdrive', 'onedrive', 'dropbox', 'webdav'], true)) cs_out(['success' => false, 'error' => 'Unknown provider']);
        NuCloudConfig::setConnection($p, $p === 'webdav' ? ['password' => '', 'account' => '', 'connected_at' => ''] : ['refresh_token' => '', 'account' => '', 'connected_at' => '']);
        cs_audit('cloud_disconnect', NuCloud::labels()[$p] . ' disconnected', ['provider' => $p]);
        cs_out(['success' => true, 'config' => NuCloudConfig::publicView(NuCloudConfig::get(true))]);

    case 'test':
        $p = (string)($body['provider'] ?? $cfg['provider']);
        $prov = NuCloud::provider($p, $cfg);
        if (!$prov) cs_out(['success' => false, 'error' => 'Choose and connect a cloud provider first.']);
        try {
            $acct = $prov->whoami();
            $tmp = tempnam(sys_get_temp_dir(), 'nuct'); file_put_contents($tmp, 'Nuvis StudioX connection test ' . date('c'));
            $res = $prov->upload($tmp, array_merge(NuFileStore::remoteSegments($cfg), ['_connection_test']), 'test-' . date('His') . '.txt', 'text/plain');
            $got = ''; $prov->download($res['id'], function ($c) use (&$got) { $got .= $c; });
            $prov->delete($res['id']); @unlink($tmp);
            if ($got === '') throw new NuCloudException('Uploaded the test file but could not read it back.');
            NuCloudConfig::setConnection($p, ['account' => $acct, 'connected_at' => $cfg[$p]['connected_at'] ?: date('Y-m-d H:i:s')]);
            cs_audit('cloud_test', $prov->label() . ' connection test passed', ['account' => $acct]);
            cs_out(['success' => true, 'message' => "Connected as {$acct}. Test file uploaded, read back and removed."]);
        } catch (Throwable $e) {
            cs_audit('cloud_test', $prov->label() . ' connection test failed', ['error' => $e->getMessage()], 'failure');
            cs_out(['success' => false, 'error' => $e->getMessage()]);
        }

    case 'retry_failed':
        $r = NuFileStore::retryFailed(25); cs_audit('cloud_retry', "Retried failed cloud uploads: {$r['synced']} synced, {$r['failed']} failed", $r);
        cs_out(['success' => true] + $r);

    case 'backfill':
        if (!NuFileStore::cloudActive($cfg)) cs_out(['success' => false, 'error' => 'Connect a cloud provider first.']);
        $r = NuFileStore::backfill(25); cs_audit('cloud_backfill', "Copied existing files to the cloud: {$r['synced']} synced, {$r['failed']} failed", $r);
        cs_out(['success' => true] + $r);

    default:
        cs_out(['success' => false, 'error' => 'Unknown action'], 400);
}
