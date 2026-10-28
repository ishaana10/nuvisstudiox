<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/cloud/CloudConfig.php';

final class CloudAndAuditTest extends TestCase
{
    public function testFolderIsSanitised(): void
    {
        $this->assertSame('Evil/Folder_x', NuCloudConfig::cleanFolder('../Evil//Folder:x'));
        $this->assertSame('', NuCloudConfig::cleanFolder('..'));
    }

    public function testPublicViewHidesSecrets(): void
    {
        $c = NuCloudConfig::defaults();
        $c['gdrive']['client_secret'] = 'top-secret';
        $c['gdrive']['refresh_token'] = 'rt';
        $v = NuCloudConfig::publicView($c);
        $this->assertArrayNotHasKey('client_secret', $v['gdrive']);
        $this->assertArrayNotHasKey('refresh_token', $v['gdrive']);
        $this->assertTrue($v['gdrive']['client_secret_set']);
        $this->assertTrue($v['gdrive']['connected']);
        $this->assertStringNotContainsString('top-secret', json_encode($v));
    }
}
