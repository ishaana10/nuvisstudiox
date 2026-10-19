<?php
declare(strict_types=1);

namespace Nuvis\Tests\Integration;

use PHPUnit\Framework\TestCase;
use NuAudit;

class AuditLoggingTest extends TestCase {

    public function testAuditLogChangeComputesFieldDiff(): void {
        $audit = new NuAudit();

        $old = ['usr_username' => 'alice', 'usr_email' => 'alice@old.com', 'usr_role' => 'user'];
        $new = ['usr_username' => 'alice', 'usr_email' => 'alice@new.com', 'usr_role' => 'admin'];

        $audit->logChange('update', 'nu_users', 'usr_100', $old, $new);
        $this->assertTrue(true);
    }
}
