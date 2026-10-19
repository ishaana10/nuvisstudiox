<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/module_bootstrap.php';
require_once dirname(__DIR__) . '/core/Cache.php';

header('Content-Type: application/json; charset=utf-8');

// Strict administrative role check (globeadmin or admin)
$currentUser = $auth->getCurrentUser();
$role = strtolower((string)($currentUser['usr_role'] ?? $_SESSION['nu_role'] ?? $_SESSION['usr_role'] ?? $_SESSION['user_role'] ?? ''));

if (!$currentUser && empty($_SESSION['nu_user_id']) && empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

if ($role !== 'globeadmin' && $role !== 'admin' && strtolower((string)($_SESSION['nu_username'] ?? '')) !== 'globeadmin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if (empty($action) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (is_array($input) && isset($input['action'])) {
        $action = $input['action'];
    }
}

$homeDir = getenv('HOME') ?: '/home/' . get_current_user();
$socketPath = $nuConfig['redis']['path'] ?? '/home/ictfjcom/.redis/redis.sock';

if ($action === 'get_redis_status') {
    // Touch ~/.redis.on flag on status check to trigger daemon if offline
    @touch($homeDir . '/.redis.on');
    if (file_exists('/home/ictfjcom')) {
        @touch('/home/ictfjcom/.redis.on');
    }

    $socketExists = file_exists($socketPath);
    $redisCache = \NuCache::getInstance();
    $isConnected = $redisCache->isEnabled();

    $usedMemoryHuman = 'N/A';
    $redisVersion = 'N/A';
    $cachedKeysCount = 0;
    $activeSessionsCount = 0;
    $keysSample = [];

    if ($isConnected) {
        $nativeRedis = $redisCache->client();
        if ($nativeRedis) {
            try {
                $info = $nativeRedis->info();
                $usedMemoryHuman = $info['used_memory_human'] ?? '0B';
                $redisVersion = $info['redis_version'] ?? 'Unknown';

                $cachedKeys = $nativeRedis->keys('nuvis:*');
                $cachedKeysCount = count($cachedKeys);

                $sessionKeys = $nativeRedis->keys('nuvis:sess:*');
                $activeSessionsCount = count($sessionKeys);

                $sampleList = array_slice($cachedKeys, 0, 15);
                foreach ($sampleList as $fullKey) {
                    $ttl = $nativeRedis->ttl($fullKey);
                    $shortKey = preg_replace('/^nuvis:/', '', $fullKey);
                    $keysSample[] = [
                        'key' => $shortKey,
                        'full_key' => $fullKey,
                        'ttl' => ($ttl === -1 ? '∞' : ($ttl >= 0 ? $ttl . 's' : 'Expired'))
                    ];
                }
            } catch (\Throwable $e) {
                // Ignore query errors
            }
        }
    }

    echo json_encode([
        'success' => true,
        'redis_extension' => extension_loaded('redis'),
        'socket_path' => $socketPath,
        'socket_exists' => $socketExists,
        'is_connected' => $isConnected,
        'scheme' => ($nuConfig['redis']['scheme'] ?? 'unix'),
        'used_memory' => $usedMemoryHuman,
        'redis_version' => $redisVersion,
        'cached_keys_count' => $cachedKeysCount,
        'active_sessions_count' => $activeSessionsCount,
        'keys_sample' => $keysSample
    ]);
    exit;
}

if ($action === 'test_redis_ping') {
    $start = microtime(true);
    $redisCache = \NuCache::getInstance();

    if (!$redisCache->isEnabled()) {
        echo json_encode([
            'success' => false,
            'error' => 'Redis is not connected. Enable Redis or ensure socket daemon is running.'
        ]);
        exit;
    }

    $testKey = 'ping_test_' . time();
    $redisCache->set($testKey, 'ok', 10);
    $val = $redisCache->get($testKey);
    $redisCache->delete($testKey);

    $durationMs = round((microtime(true) - $start) * 1000, 2);

    if ($val === 'ok') {
        echo json_encode([
            'success' => true,
            'latency_ms' => $durationMs,
            'message' => "SET/GET/DEL Benchmark Successful! Response latency: {$durationMs} ms"
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Redis write verification failed.'
        ]);
    }
    exit;
}

if ($action === 'start_redis_daemon') {
    @touch($homeDir . '/.redis.on');
    if (file_exists('/home/ictfjcom')) {
        @touch('/home/ictfjcom/.redis.on');
    }

    // Persist enable flag to config files if present
    $configFiles = [
        dirname(__DIR__) . '/config.php',
        dirname(__DIR__) . '/config.local.php'
    ];
    foreach ($configFiles as $configFilePath) {
        if (file_exists($configFilePath)) {
            $configContent = file_get_contents($configFilePath);
            if ($configContent !== false) {
                $updatedContent = preg_replace(
                    "/('enabled'\s*=>\s*)(false|true)/i",
                    '$1true',
                    $configContent
                );
                if ($updatedContent !== null && $updatedContent !== $configContent) {
                    file_put_contents($configFilePath, $updatedContent);
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Created ~/.redis.on flag and enabled Redis in configuration. Redis daemon will initialize at ' . $socketPath . '.'
    ]);
    exit;
}

if ($action === 'flush_cache') {
    $redisCache = \NuCache::getInstance();
    if ($redisCache->isEnabled()) {
        $redisCache->flush();
        echo json_encode(['success' => true, 'message' => 'Nuvis application cache cleared successfully.']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Redis cache is not active.']);
    }
    exit;
}

if ($action === 'toggle_redis_config') {
    $input = json_decode(file_get_contents('php://input'), true);
    $enable = !empty($input['enable']);

    $configFiles = [
        dirname(__DIR__) . '/config.php',
        dirname(__DIR__) . '/config.local.php'
    ];
    $targetVal = $enable ? 'true' : 'false';
    foreach ($configFiles as $configFilePath) {
        if (file_exists($configFilePath)) {
            $configContent = file_get_contents($configFilePath);
            if ($configContent !== false) {
                $updatedContent = preg_replace(
                    "/('enabled'\s*=>\s*)(false|true)/i",
                    '$1' . $targetVal,
                    $configContent
                );
                if ($updatedContent !== null && $updatedContent !== $configContent) {
                    file_put_contents($configFilePath, $updatedContent);
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Redis configuration updated: ' . ($enable ? 'Enabled' : 'Disabled')
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
