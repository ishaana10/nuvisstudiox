<?php
declare(strict_types=1);

/**
 * NuCache - Simple Redis-backed cache with graceful fallback
 * Uses native phpredis extension when available
 */
class NuCache
{
    private static ?self $instance = null;
    private ?object $redis = null;
    private bool $enabled = false;
    private string $prefix = 'nuvis:';
    private int $defaultTtl = 300;

    private function __construct()
    {
        global $nuConfig;
        $cfg = $nuConfig['redis'] ?? [];

        if (empty($cfg['enabled'])) {
            return;
        }

        if (!extension_loaded('redis') || !class_exists('Redis')) {
            error_log('[NuCache] phpredis extension not loaded');
            return;
        }

        try {
            $this->redis = new \Redis();

            if (($cfg['scheme'] ?? 'unix') === 'unix') {
                $connected = $this->redis->connect(
                    $cfg['path'] ?? '/tmp/redis.sock',
                    0,
                    (float)($cfg['timeout'] ?? 2.5)
                );
            } else {
                $connected = $this->redis->connect(
                    $cfg['host'] ?? '127.0.0.1',
                    (int)($cfg['port'] ?? 6379),
                    (float)($cfg['timeout'] ?? 2.5)
                );
            }

            if (!$connected) {
                throw new \RuntimeException('Redis connect failed');
            }

            if (!empty($cfg['password'])) {
                $this->redis->auth($cfg['password']);
            }

            if (isset($cfg['database'])) {
                $this->redis->select((int)$cfg['database']);
            }

            $this->prefix     = (string)($cfg['prefix'] ?? 'nuvis:');
            $this->defaultTtl = (int)($cfg['default_ttl'] ?? 300);
            $this->enabled    = true;

        } catch (\Throwable $e) {
            error_log('[NuCache] ' . $e->getMessage());
            $this->redis   = null;
            $this->enabled = false;
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->redis !== null;
    }

    private function key(string $key): string
    {
        return $this->prefix . $key;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->isEnabled()) {
            return $default;
        }

        try {
            $value = $this->redis->get($this->key($key));
            if ($value === false) {
                return $default;
            }
            $decoded = @unserialize($value);
            return $decoded === false && $value !== serialize(false)
                ? $value
                : $decoded;
        } catch (\Throwable $e) {
            error_log('[NuCache get] ' . $e->getMessage());
            return $default;
        }
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        try {
            $ttl = $ttl ?? $this->defaultTtl;
            $payload = serialize($value);
            if ($ttl > 0) {
                return (bool)$this->redis->setex($this->key($key), $ttl, $payload);
            }
            return (bool)$this->redis->set($this->key($key), $payload);
        } catch (\Throwable $e) {
            error_log('[NuCache set] ' . $e->getMessage());
            return false;
        }
    }

    public function delete(string $key): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        try {
            return (bool)$this->redis->del($this->key($key));
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function deleteByPattern(string $pattern): int
    {
        if (!$this->isEnabled()) {
            return 0;
        }
        try {
            $keys = $this->redis->keys($this->prefix . $pattern);
            if (empty($keys)) {
                return 0;
            }
            return (int)$this->redis->del(...$keys);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $value = $this->get($key);
        if ($value !== null) {
            return $value;
        }
        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    public function flush(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        try {
            $keys = $this->redis->keys($this->prefix . '*');
            if (!empty($keys)) {
                $this->redis->del(...$keys);
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function client(): ?object
    {
        return $this->isEnabled() ? $this->redis : null;
    }
}
