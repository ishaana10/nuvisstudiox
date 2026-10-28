<?php
declare(strict_types=1);

/**
 * NuRedisConfig - persistent, editable Redis settings.
 *
 * Precedence: built-in defaults <- $nuConfig['redis'] (config.php / config.local.php) <- saved settings.
 *
 * Saved settings live in two places:
 *   - config.redis.php (PHP file returning an array; read at boot, before the database/session exists;
 *     holds the password; written with 0600 permissions and git-ignored)
 *   - nu_system_settings key "redis_config" (everything EXCEPT secrets) - this is what project export carries.
 *
 * Nothing here is tied to a particular host: the "a2hosting" preset only fills in a unix socket under $HOME.
 */
final class NuRedisConfig
{
    public const SETTING_KEY = 'redis_config';
    private const SECRET_KEYS = ['password'];

    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    public static function file(): string
    {
        return dirname(__DIR__) . '/config.redis.php';
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'enabled'          => false,
            'preset'           => 'unix',     // a2hosting | unix | tcp | tls | url (informational; the fields below are what count)
            'scheme'           => 'unix',     // unix | tcp | tls
            'path'             => '',         // unix socket; empty = $HOME/.redis/redis.sock
            'host'             => '127.0.0.1',
            'port'             => 6379,
            'username'         => '',         // Redis 6+ ACL user (optional)
            'password'         => '',
            'database'         => 0,
            'prefix'           => 'nuvis:',
            'timeout'          => 2.5,
            'default_ttl'      => 300,
            'persistent'       => true,       // reuse the connection between requests (pconnect)
            'use_for_sessions' => true,       // store PHP sessions in Redis when enabled
            'tls_verify'       => true,
            'start_flag_file'  => '',         // optional file to touch so a host-managed daemon starts (A2 preset: ~/.redis.on)
        ];
    }

    /** Preset values a UI can apply. */
    public static function presets(): array
    {
        return [
            'a2hosting' => ['scheme' => 'unix', 'path' => '~/.redis/redis.sock', 'start_flag_file' => '~/.redis.on', 'persistent' => true],
            'unix'      => ['scheme' => 'unix', 'path' => '', 'start_flag_file' => ''],
            'tcp'       => ['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 6379, 'start_flag_file' => ''],
            'tls'       => ['scheme' => 'tls', 'port' => 6380, 'start_flag_file' => ''],
        ];
    }

    public static function home(): string
    {
        $h = getenv('HOME');
        if (!$h && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $pw = @posix_getpwuid(posix_geteuid());
            $h = $pw['dir'] ?? '';
        }
        return rtrim((string)$h, '/');
    }

    public static function expand(string $p): string
    {
        $p = trim($p);
        if ($p === '~' || strncmp($p, '~/', 2) === 0) return self::home() . substr($p, 1);
        return $p;
    }

    /** Effective settings (paths expanded). */
    public static function get(bool $fresh = false): array
    {
        if (self::$cache !== null && !$fresh) return self::$cache;
        global $nuConfig;
        $cfg = self::defaults();
        $legacy = is_array($nuConfig['redis'] ?? null) ? $nuConfig['redis'] : [];
        foreach ($legacy as $k => $v) if (array_key_exists($k, $cfg)) $cfg[$k] = $v;
        // config.php used to ship a host-specific socket path; treat that as "unset" so it is never forced on other hosts
        if (isset($legacy['path']) && strpos((string)$legacy['path'], '/home/ictfjcom') === 0) $cfg['path'] = '';
        $saved = self::readFile();
        if ($saved) foreach ($saved as $k => $v) if (array_key_exists($k, $cfg)) $cfg[$k] = $v;
        $cfg = self::normalize($cfg);
        return self::$cache = $cfg;
    }

    public static function normalize(array $c): array
    {
        $d = self::defaults();
        $c = array_merge($d, array_intersect_key($c, $d));
        $c['enabled']          = filter_var($c['enabled'], FILTER_VALIDATE_BOOLEAN);
        $c['persistent']       = filter_var($c['persistent'], FILTER_VALIDATE_BOOLEAN);
        $c['use_for_sessions'] = filter_var($c['use_for_sessions'], FILTER_VALIDATE_BOOLEAN);
        $c['tls_verify']       = filter_var($c['tls_verify'], FILTER_VALIDATE_BOOLEAN);
        $c['scheme']  = in_array($c['scheme'], ['unix', 'tcp', 'tls'], true) ? $c['scheme'] : 'unix';
        $c['preset']  = preg_replace('/[^a-z0-9_]/', '', strtolower((string)$c['preset'])) ?: 'unix';
        $c['host']    = trim((string)$c['host']) ?: '127.0.0.1';
        $c['port']    = max(1, min(65535, (int)$c['port']));
        $c['database']= max(0, min(255, (int)$c['database']));
        $c['timeout'] = max(0.2, min(30.0, (float)$c['timeout']));
        $c['default_ttl'] = max(1, min(31536000, (int)$c['default_ttl']));
        $c['prefix']  = preg_replace('/[^A-Za-z0-9_:\-\.]/', '', (string)$c['prefix']);
        if ($c['prefix'] === '') $c['prefix'] = 'nuvis:';
        $c['username'] = trim((string)$c['username']);
        $c['password'] = (string)$c['password'];
        $c['path'] = trim((string)$c['path']);
        $c['start_flag_file'] = trim((string)$c['start_flag_file']);
        return $c;
    }

    /** Resolved unix socket path ("~" expanded; default $HOME/.redis/redis.sock). */
    public static function socketPath(?array $c = null): string
    {
        $c = $c ?? self::get();
        $p = self::expand((string)$c['path']);
        return $p !== '' ? $p : self::home() . '/.redis/redis.sock';
    }

    /** Same as get() but with secrets removed and a flag telling whether one is stored. */
    public static function publicView(?array $c = null): array
    {
        $c = $c ?? self::get();
        $has = $c['password'] !== '';
        foreach (self::SECRET_KEYS as $k) unset($c[$k]);
        $c['password_set'] = $has;
        $c['effective_socket'] = self::socketPath();
        return $c;
    }

    /** @return array<string,mixed> */
    private static function readFile(): array
    {
        $f = self::file();
        if (!is_file($f)) return [];
        try { $v = include $f; } catch (\Throwable $e) { return []; }
        return is_array($v) ? $v : [];
    }

    /**
     * Persist settings. Blank/absent password keeps the existing one; $clearPassword removes it.
     * @return array<string,mixed> the stored (normalised) config
     */
    public static function save(array $in, bool $clearPassword = false): array
    {
        $cur = self::get(true);
        $new = $cur;
        foreach (self::defaults() as $k => $_) {
            if (!array_key_exists($k, $in)) continue;
            if ($k === 'password') continue;
            $new[$k] = $in[$k];
        }
        if ($clearPassword) $new['password'] = '';
        elseif (isset($in['password']) && $in['password'] !== '' && $in['password'] !== '********') $new['password'] = (string)$in['password'];
        $new = self::normalize($new);

        $php = "<?php\n// Generated by Nuvis StudioX (Developer Settings > Redis). Safe to edit; do not commit.\nreturn " . var_export($new, true) . ";\n";
        $f = self::file();
        if (@file_put_contents($f, $php, LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write ' . basename($f) . '. Make the application folder writable by the web server.');
        }
        @chmod($f, 0600);
        if (function_exists('opcache_invalidate')) @opcache_invalidate($f, true);
        self::$cache = null;
        self::saveToDb($new);
        return self::get(true);
    }

    /** Portable (secret-free) copy in the database; used by project export. */
    public static function saveToDb(array $c): void
    {
        try {
            $db = NuDatabase::getInstance();
            foreach (self::SECRET_KEYS as $k) unset($c[$k]);
            $json = json_encode($c);
            $row = $db->fetchOne('SELECT 1 AS x FROM nu_system_settings WHERE setting_key = ?', [self::SETTING_KEY]);
            if ($row) $db->query('UPDATE nu_system_settings SET setting_value = ? WHERE setting_key = ?', [$json, self::SETTING_KEY]);
            else $db->query('INSERT INTO nu_system_settings (setting_key, setting_value) VALUES (?, ?)', [self::SETTING_KEY, $json]);
        } catch (\Throwable $e) {
            error_log('[NuRedisConfig] db save: ' . $e->getMessage());
        }
    }

    /** Make the file match the database copy (after a project import). Keeps the local password. */
    public static function syncFromDb(): bool
    {
        try {
            $row = NuDatabase::getInstance()->fetchOne('SELECT setting_value FROM nu_system_settings WHERE setting_key = ?', [self::SETTING_KEY]);
            $data = $row ? json_decode((string)$row['setting_value'], true) : null;
            if (!is_array($data)) return false;
            unset($data['password']);
            // an imported project must not silently switch Redis on for a different host
            $data['enabled'] = false;
            self::save($data);
            return true;
        } catch (\Throwable $e) {
            error_log('[NuRedisConfig] sync: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Open a connection with the given config.
     * @return array{0:?\Redis,1:string} [client|null, error message]
     */
    public static function connect(array $c): array
    {
        if (!extension_loaded('redis') || !class_exists('Redis')) {
            return [null, 'The PHP "redis" extension (phpredis) is not installed or not enabled for this site.'];
        }
        try {
            $r = new \Redis();
            $timeout = (float)$c['timeout'];
            if ($c['scheme'] === 'unix') {
                $target = self::socketPath($c);
                if (!file_exists($target)) {
                    return [null, "Redis socket not found at {$target}. Start the Redis service (on A2 Hosting enable Redis in cPanel and create the start flag) or correct the path."];
                }
                $id = 'nu_' . md5($target . $c['database']);
                $ok = $c['persistent'] ? $r->pconnect($target, 0, $timeout, $id) : $r->connect($target, 0, $timeout);
            } else {
                $host = ($c['scheme'] === 'tls' ? 'tls://' : '') . $c['host'];
                $id = 'nu_' . md5($host . $c['port'] . $c['database']);
                $ctx = [];
                if ($c['scheme'] === 'tls' && !$c['tls_verify']) $ctx = ['stream' => ['verify_peer' => false, 'verify_peer_name' => false]];
                if ($c['persistent']) $ok = $ctx ? $r->pconnect($host, (int)$c['port'], $timeout, $id, 0, 0, $ctx) : $r->pconnect($host, (int)$c['port'], $timeout, $id);
                else $ok = $ctx ? $r->connect($host, (int)$c['port'], $timeout, null, 0, 0, $ctx) : $r->connect($host, (int)$c['port'], $timeout);
            }
            if (!$ok) return [null, 'Could not connect to Redis.'];
            if ($c['password'] !== '') {
                $cred = $c['username'] !== '' ? [$c['username'], $c['password']] : $c['password'];
                if (!$r->auth($cred)) return [null, 'Redis rejected the username/password.'];
            }
            if ((int)$c['database'] > 0 && !$r->select((int)$c['database'])) return [null, 'Cannot select database ' . (int)$c['database'] . '.'];
            $r->ping();
            return [$r, ''];
        } catch (\Throwable $e) {
            return [null, $e->getMessage()];
        }
    }

    /** Touch the configured start-flag file (A2 Hosting style daemon trigger). */
    public static function touchStartFlag(?array $c = null): ?string
    {
        $c = $c ?? self::get();
        $f = self::expand((string)$c['start_flag_file']);
        if ($f === '') return null;
        return @touch($f) ? $f : null;
    }
}
