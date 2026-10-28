<?php
// core/SessionBootstrap.php
declare(strict_types=1);

global $nuConfig;

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');

$cookieSecure = isset($nuConfig['sessionCookieSecure']) && $nuConfig['sessionCookieSecure'] === false ? '0' : '1';
ini_set('session.cookie_secure', $cookieSecure);

$sameSite = $nuConfig['sessionCookieSameSite'] ?? 'Strict';
ini_set('session.cookie_samesite', (string)$sameSite);

ini_set('session.cookie_lifetime', '0');        // session cookie (dies on browser close)
ini_set('session.gc_maxlifetime', (string)($nuConfig['sessionTimeout'] ?? 1800));

if (isset($nuConfig['baseUrl'])) {
    $_cookiePath = rtrim(parse_url((string)$nuConfig['baseUrl'], PHP_URL_PATH) ?: '/', '/') . '/';
    ini_set('session.cookie_path', $_cookiePath);
}

session_name('nuvisstudioxssid');               // avoid default PHPSESSID

if (session_status() === PHP_SESSION_NONE) {
    // Register Redis session handler if Redis is enabled and extension exists
    require_once NU_ROOT . '/core/RedisConfig.php';
    $_redisCfg = NuRedisConfig::get();
    if (!empty($_redisCfg['enabled']) && !empty($_redisCfg['use_for_sessions']) && extension_loaded('redis')) {
        try {
            require_once NU_ROOT . '/core/Cache.php';
            require_once NU_ROOT . '/core/RedisSessionHandler.php';
            $cache = NuCache::getInstance();
            if ($cache->isEnabled() && $cache->client()) {
                $handler = new NuRedisSessionHandler(
                    $cache->client(),
                    $_redisCfg['prefix'] . 'sess:',
                    (int)($nuConfig['sessionTimeout'] ?? 1800)
                );
                session_set_save_handler($handler, true);
            }
        } catch (\Throwable $e) {
            error_log('[SessionBootstrap] Redis sessions fallback: ' . $e->getMessage());
        }
    }

    session_start();
}
