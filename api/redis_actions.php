<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/module_bootstrap.php';
require_once dirname(__DIR__) . '/core/Cache.php';
require_once dirname(__DIR__) . '/core/RedisConfig.php';

header('Content-Type: application/json; charset=utf-8');

function rd_audit(string $a, string $s, array $d = []): void { try { require_once dirname(__DIR__) . '/core/Audit.php'; (new NuAudit())->event($a, $s, $d); } catch (Throwable $t) {} }
function rd_out(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d); exit; }

// Administrative role check (globeadmin or admin)
$currentUser = $auth->getCurrentUser();
$role = strtolower((string)($currentUser['usr_role'] ?? $_SESSION['nu_role'] ?? $_SESSION['usr_role'] ?? $_SESSION['user_role'] ?? ''));
if (!$currentUser && empty($_SESSION['nu_user_id']) && empty($_SESSION['user_id'])) rd_out(['success' => false, 'error' => 'Authentication required'], 401);
if ($role !== 'globeadmin' && $role !== 'admin' && strtolower((string)($_SESSION['nu_username'] ?? '')) !== 'globeadmin') rd_out(['success' => false, 'error' => 'Unauthorized'], 403);

$input = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $decoded = json_decode((string)file_get_contents('php://input'), true);
    $input = is_array($decoded) ? $decoded : $_POST;
}
$action = (string)($_GET['action'] ?? $input['action'] ?? '');

// State-changing calls must be POST + JSON (blocks cross-site form posts).
$readOnly = ['get_redis_status', 'get_config', 'persistence_info'];
if ($action !== '' && !in_array($action, $readOnly, true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') rd_out(['success' => false, 'error' => 'POST required'], 405);
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === false) rd_out(['success' => false, 'error' => 'JSON body required'], 415);
}

$cfg = NuRedisConfig::get();

/** Live client from the saved config (even when "enabled" is off) for diagnostics. */
function rd_client(array $cfg): array { return NuRedisConfig::connect($cfg); }

switch ($action) {
    case 'get_config':
        rd_out(['success' => true, 'config' => NuRedisConfig::publicView($cfg), 'presets' => NuRedisConfig::presets(),
                'extension' => extension_loaded('redis'), 'home' => NuRedisConfig::home(), 'file_writable' => is_writable(dirname(NuRedisConfig::file()))]);

    case 'save_config':
        try {
            $saved = NuRedisConfig::save(is_array($input['config'] ?? null) ? $input['config'] : [], !empty($input['clear_password']));
        } catch (Throwable $e) {
            rd_out(['success' => false, 'error' => $e->getMessage()]);
        }
        NuRedisConfig::touchStartFlag($saved);
        rd_audit('redis_settings_save', 'Redis settings changed', ['enabled' => $saved['enabled'], 'scheme' => $saved['scheme'], 'prefix' => $saved['prefix']]);
        rd_out(['success' => true, 'message' => 'Redis settings saved.', 'config' => NuRedisConfig::publicView($saved)]);

    case 'test_config':
        // Try the values in the form without saving them (password: blank = use the saved one)
        $try = is_array($input['config'] ?? null) ? $input['config'] : [];
        $merged = $cfg;
        foreach (array_keys(NuRedisConfig::defaults()) as $k) {
            if (!array_key_exists($k, $try) || $k === 'password') continue;
            $merged[$k] = $try[$k];
        }
        if (isset($try['password']) && $try['password'] !== '' && $try['password'] !== '********') $merged['password'] = (string)$try['password'];
        $merged = NuRedisConfig::normalize($merged);
        $t0 = microtime(true);
        [$cl, $err] = rd_client($merged);
        if (!$cl) rd_out(['success' => false, 'error' => $err]);
        try {
            $info = $cl->info();
            $k = $merged['prefix'] . 'selftest:' . bin2hex(random_bytes(4));
            $cl->setex($k, 10, 'ok');
            $ok = $cl->get($k) === 'ok';
            $cl->del($k);
            $ms = round((microtime(true) - $t0) * 1000, 1);
            rd_out(['success' => $ok, 'latency_ms' => $ms, 'version' => $info['redis_version'] ?? '?',
                    'message' => $ok ? "Connected to Redis {$info['redis_version']} and read/write succeeded ({$ms} ms)." : 'Connected, but a write could not be read back.']);
        } catch (Throwable $e) {
            rd_out(['success' => false, 'error' => $e->getMessage()]);
        }

    case 'get_redis_status':
        NuRedisConfig::touchStartFlag($cfg);   // lets host-managed daemons (A2 Hosting) start on demand
        $isSock = $cfg['scheme'] === 'unix';
        $sock = NuRedisConfig::socketPath($cfg);
        $cache = NuCache::getInstance();
        $connected = $cache->isEnabled();
        $res = [
            'success' => true, 'enabled' => $cfg['enabled'], 'redis_extension' => extension_loaded('redis'),
            'scheme' => $cfg['scheme'], 'preset' => $cfg['preset'],
            'socket_path' => $isSock ? $sock : ($cfg['host'] . ':' . $cfg['port']),
            'socket_exists' => $isSock ? file_exists($sock) : null,
            'is_connected' => $connected, 'persistent' => $cfg['persistent'], 'use_for_sessions' => $cfg['use_for_sessions'],
            'prefix' => $cfg['prefix'], 'last_error' => $connected ? '' : ($cache->lastError() ?: ($cfg['enabled'] ? '' : 'Redis is switched off in the settings.')),
            'used_memory' => 'N/A', 'redis_version' => 'N/A', 'cached_keys_count' => 0, 'active_sessions_count' => 0, 'keys_sample' => [],
        ];
        if ($connected && ($nr = $cache->client())) {
            try {
                $info = $nr->info();
                $res['used_memory'] = $info['used_memory_human'] ?? '0B';
                $res['redis_version'] = $info['redis_version'] ?? 'Unknown';
                $px = $cfg['prefix'];
                $keys = [];
                $it = null;
                while (($batch = $nr->scan($it, $px . '*', 500)) !== false && count($keys) < 5000) {
                    foreach ($batch as $kk) $keys[] = $kk;
                    if ($it === 0 || $it === null) break;
                }
                $res['cached_keys_count'] = count($keys);
                $res['active_sessions_count'] = count(array_filter($keys, fn($k) => strpos($k, $px . 'sess:') === 0));
                foreach (array_slice($keys, 0, 15) as $full) {
                    $ttl = $nr->ttl($full);
                    $res['keys_sample'][] = ['key' => substr($full, strlen($px)), 'full_key' => $full, 'ttl' => ($ttl === -1 ? '∞' : ($ttl >= 0 ? $ttl . 's' : 'Expired'))];
                }
            } catch (Throwable $e) { $res['last_error'] = $e->getMessage(); }
        }
        rd_out($res);

    case 'test_redis_ping':
        $t0 = microtime(true);
        $cache = NuCache::getInstance();
        if (!$cache->isEnabled()) rd_out(['success' => false, 'error' => $cache->lastError() ?: 'Redis is not connected. Enable it and save the settings first.']);
        $k = 'ping_test_' . time();
        $cache->set($k, 'ok', 10); $v = $cache->get($k); $cache->delete($k);
        $ms = round((microtime(true) - $t0) * 1000, 2);
        rd_out($v === 'ok' ? ['success' => true, 'latency_ms' => $ms, 'message' => "SET/GET/DEL successful. Latency: {$ms} ms"] : ['success' => false, 'error' => 'Redis write verification failed.']);

    case 'persistence_info':
        [$cl, $err] = rd_client($cfg);
        if (!$cl) rd_out(['success' => false, 'error' => $err]);
        try {
            $p = $cl->info('persistence'); $s = $cl->info('server');
            $aof = ($p['aof_enabled'] ?? 0) == 1;
            $save = ''; try { $sv = $cl->config('GET', 'save'); $save = is_array($sv) ? (string)(reset($sv) ?: '') : ''; } catch (Throwable $e) { $save = 'n/a (CONFIG disabled by host)'; }
            rd_out(['success' => true, 'aof_enabled' => $aof, 'rdb_last_save' => isset($p['rdb_last_save_time']) ? date('Y-m-d H:i:s', (int)$p['rdb_last_save_time']) : null,
                    'rdb_last_status' => $p['rdb_last_bgsave_status'] ?? null, 'changes_since_save' => $p['rdb_changes_since_last_save'] ?? null,
                    'save_policy' => $save, 'dir' => $s['config_file'] ?? '', 'uptime_days' => $s['uptime_in_days'] ?? null]);
        } catch (Throwable $e) { rd_out(['success' => false, 'error' => $e->getMessage()]); }

    case 'bgsave':
        [$cl, $err] = rd_client($cfg);
        if (!$cl) rd_out(['success' => false, 'error' => $err]);
        try { $cl->bgsave(); rd_out(['success' => true, 'message' => 'Background save started (data written to disk).']); }
        catch (Throwable $e) { rd_out(['success' => false, 'error' => 'Host does not allow BGSAVE: ' . $e->getMessage()]); }

    case 'set_persistence':
        [$cl, $err] = rd_client($cfg);
        if (!$cl) rd_out(['success' => false, 'error' => $err]);
        try {
            $aof = !empty($input['aof']);
            $cl->config('SET', 'appendonly', $aof ? 'yes' : 'no');
            $cl->config('SET', 'save', '900 1 300 10 60 10000');
            rd_out(['success' => true, 'message' => 'Persistence updated for the running server' . ($aof ? ' (append-only file on)' : '') . '. If the host restarts Redis from its own config, set the same options there.']);
        } catch (Throwable $e) { rd_out(['success' => false, 'error' => 'The Redis server refused the change (often disabled on shared hosting): ' . $e->getMessage()]); }

    case 'start_redis_daemon':
        $f = NuRedisConfig::touchStartFlag($cfg);
        rd_out($f ? ['success' => true, 'message' => "Start flag created at {$f}. The host will start Redis shortly."]
                  : ['success' => false, 'error' => 'No start-flag file is configured (Developer Settings > Redis > Start flag file), or it is not writable.']);

    case 'toggle_redis_config':
        try { $saved = NuRedisConfig::save(['enabled' => !empty($input['enable'])]); }
        catch (Throwable $e) { rd_out(['success' => false, 'error' => $e->getMessage()]); }
        NuRedisConfig::touchStartFlag($saved);
        rd_out(['success' => true, 'message' => 'Redis ' . ($saved['enabled'] ? 'enabled' : 'disabled') . '.']);

    case 'flush_cache':
        $cache = NuCache::getInstance();
        if (!$cache->isEnabled()) rd_out(['success' => false, 'error' => 'Redis cache is not active.']);
        $cache->flush();
        rd_audit('redis_flush', 'Application cache flushed');
        rd_out(['success' => true, 'message' => 'Application cache cleared (sessions are kept).']);

    default:
        rd_out(['success' => false, 'error' => 'Invalid action'], 400);
}
