<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/RedisConfig.php';

final class RedisConfigTest extends TestCase
{
    public function testNormalizeClampsAndCleans(): void
    {
        $c = NuRedisConfig::normalize(['scheme' => 'bogus', 'port' => 99999, 'database' => -3, 'prefix' => 'my app!:', 'timeout' => 0, 'enabled' => '1']);
        $this->assertSame('unix', $c['scheme']);
        $this->assertSame(65535, $c['port']);
        $this->assertSame(0, $c['database']);
        $this->assertSame('myapp:', $c['prefix']);
        $this->assertGreaterThan(0, $c['timeout']);
        $this->assertTrue($c['enabled']);
    }

    public function testExpandHome(): void
    {
        putenv('HOME=/home/someone');
        $this->assertSame('/home/someone/.redis/redis.sock', NuRedisConfig::expand('~/.redis/redis.sock'));
        $this->assertSame('/abs/path', NuRedisConfig::expand('/abs/path'));
    }

    public function testPublicViewHidesPassword(): void
    {
        $v = NuRedisConfig::publicView(NuRedisConfig::normalize(['password' => 'secret']));
        $this->assertArrayNotHasKey('password', $v);
        $this->assertTrue($v['password_set']);
    }
}
