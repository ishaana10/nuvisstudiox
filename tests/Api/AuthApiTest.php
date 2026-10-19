<?php
declare(strict_types=1);

namespace Nuvis\Tests\Api;

use PHPUnit\Framework\TestCase;

class AuthApiTest extends TestCase {

    public function testApiGatewayPathResolution(): void {
        $this->assertTrue(file_exists(__DIR__ . '/../../api/gateway.php'));
    }
}
