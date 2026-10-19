<?php
declare(strict_types=1);

namespace Nuvis\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NuCache;
use NuRedisSessionHandler;

class CacheTest extends TestCase
{
    public function testNuCacheDisabledFallback(): void
    {
        $cache = NuCache::getInstance();
        $this->assertFalse($cache->isEnabled());
        $this->assertEquals('default_value', $cache->get('non_existent_key', 'default_value'));
        $this->assertFalse($cache->set('key', 'val'));
        $this->assertFalse($cache->delete('key'));
        $this->assertEquals(0, $cache->deleteByPattern('key:*'));
        $this->assertFalse($cache->flush());
        $this->assertNull($cache->client());
    }

    public function testNuCacheRememberCallback(): void
    {
        $cache = NuCache::getInstance();
        $executed = false;
        $result = $cache->remember('test_remember', function () use (&$executed) {
            $executed = true;
            return 'computed_val';
        });

        $this->assertTrue($executed);
        $this->assertEquals('computed_val', $result);
    }

    public function testNuRedisSessionHandlerInterface(): void
    {
        $dummyRedis = new class {
            public array $storage = [];
            public function get(string $k) { return $this->storage[$k] ?? false; }
            public function setex(string $k, int $ttl, string $v) { $this->storage[$k] = $v; return true; }
            public function del(string $k) { unset($this->storage[$k]); return 1; }
        };

        $handler = new NuRedisSessionHandler($dummyRedis, 'test:sess:', 1800);
        $this->assertTrue($handler->open('', ''));
        $this->assertTrue($handler->close());
        $this->assertEquals('', $handler->read('sess123'));
        $this->assertTrue($handler->write('sess123', 'a:1:{s:4:"user";s:5:"admin";}'));
        $this->assertEquals('a:1:{s:4:"user";s:5:"admin";}', $handler->read('sess123'));
        $this->assertTrue($handler->destroy('sess123'));
        $this->assertEquals('', $handler->read('sess123'));
        $this->assertEquals(0, $handler->gc(1440));
    }
}
