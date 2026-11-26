<?php
declare(strict_types=1);

namespace Nuvis\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** version.json (stamped by the release workflow) is the only place a version number is written. */
class SingleVersionTest extends TestCase
{
    public function testConstantMatchesVersionJson(): void
    {
        $json = json_decode((string)file_get_contents(NU_ROOT . '/version.json'), true);
        $this->assertSame($json['version'], NU_VERSION);
    }

    public function testAppVersionReportsTheSameRelease(): void
    {
        require_once NU_ROOT . '/core/AppVersion.php';
        $this->assertSame(NU_VERSION, \AppVersion::get('version'));
    }

    public function testNoOtherFileHardcodesAVersionConstant(): void
    {
        $this->assertSame(1, preg_match_all("/define\\('NU_VERSION'/", (string)file_get_contents(NU_ROOT . '/config.php')));
        $this->assertStringNotContainsString("define('NU_VERSION', '5", (string)file_get_contents(NU_ROOT . '/config.php'));
    }
}
