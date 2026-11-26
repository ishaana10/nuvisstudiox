<?php
declare(strict_types=1);

/**
 * Central request gate. Runs once per request from config.php, before any endpoint code.
 *
 * A signed-in browser session making a state-changing request (POST, PUT, PATCH, DELETE) must
 *  1. come from this site (Origin, else Referer, must match the Host or `allowedOrigins`), and
 *  2. carry the session CSRF token (X-CSRF-Token header, or an `nu_csrf` form field / query parameter).
 *
 * Requests with no signed-in session are not gated: login, public forms and bearer-token API calls
 * have no cookie for an attacker to ride. Config keys (all optional):
 *   csrfEnforce        bool   default true
 *   csrfExemptScripts  array  script basenames to skip
 *   allowedOrigins     array  extra hosts accepted in Origin/Referer
 */
final class NuSecurityGate
{
    private const UNSAFE = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** @return array{status:int,error:string}|null null = allowed */
    public static function check(array $server, array $post, array $get, array $session, array $config): ?array
    {
        $method = strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, self::UNSAFE, true)) return null;
        if (($config['csrfEnforce'] ?? true) === false) return null;
        if (!isset($session['nu_user_id']) || $session['nu_user_id'] === '' || $session['nu_user_id'] === null) return null;

        $script = basename((string)($server['SCRIPT_NAME'] ?? ''));
        if ($script !== '' && in_array($script, (array)($config['csrfExemptScripts'] ?? []), true)) return null;

        if (!self::originOk($server, (array)($config['allowedOrigins'] ?? []))) {
            return ['status' => 403, 'error' => 'Cross-site request refused.'];
        }

        $expected = (string)($session['nu_csrf'] ?? '');
        $given = (string)($server['HTTP_X_CSRF_TOKEN'] ?? ($post['nu_csrf'] ?? ($get['nu_csrf'] ?? '')));
        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            return ['status' => 403, 'error' => 'Invalid or missing security token. Reload the page and try again.'];
        }
        return null;
    }

    public static function originOk(array $server, array $extraHosts = []): bool
    {
        $src = (string)($server['HTTP_ORIGIN'] ?? '');
        if ($src === '' || $src === 'null') $src = (string)($server['HTTP_REFERER'] ?? '');
        if ($src === '') return true; // token check still applies
        $host = strtolower((string)parse_url($src, PHP_URL_HOST));
        $port = parse_url($src, PHP_URL_PORT);
        if ($host === '') return false;
        $own = strtolower((string)($server['HTTP_HOST'] ?? ''));
        $ownHost = preg_replace('/:\d+$/', '', $own);
        if ($host === $ownHost) return true;
        if ($port !== null && $host . ':' . $port === $own) return true;
        foreach ($extraHosts as $h) {
            if (strtolower((string)$h) === $host) return true;
        }
        return false;
    }

    /** Called from config.php after the session starts. Only gates web requests to /api and /modules. */
    public static function enforce(): void
    {
        if (PHP_SAPI === 'cli') return;
        $uri = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        if (strpos($uri, '/api/') === false && strpos($uri, '/modules/') === false) return;
        global $nuConfig;
        $r = self::check($_SERVER, $_POST, $_GET, $_SESSION ?? [], is_array($nuConfig ?? null) ? $nuConfig : []);
        if ($r === null) return;
        http_response_code($r['status']);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $r['error'], 'csrf' => true]);
        exit;
    }
}
