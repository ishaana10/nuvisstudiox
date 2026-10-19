<?php
declare(strict_types=1);

namespace Nuvis\Tests\Security;

use PHPUnit\Framework\TestCase;
use NuAuth;

class SqlInjectionTest extends TestCase {

    public function testHashResolutionEscapesInjectedQuotes(): void {
        $_SESSION['nu_user_meta'] = [
            'station' => "North' OR '1'='1",
        ];
        $auth = new NuAuth();

        $sql = "SELECT * FROM sales WHERE station = '##station##'";
        $resolved = $auth->resolveHashes($sql);

        $this->assertStringNotContainsString("station = 'North' OR '1'='1'", $resolved);
    }
}
