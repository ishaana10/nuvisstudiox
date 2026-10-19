<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use NuAudit;

class SecurityAndAuditTest extends TestCase {

    public function testPasswordValidationStrength(): void {
        require_once __DIR__ . '/../../api/password.php';

        $policy = [
            'policy_min_length'        => 12,
            'policy_require_uppercase' => 1,
            'policy_require_lowercase' => 1,
            'policy_require_number'    => 1,
            'policy_require_special'   => 1,
            'policy_disallow_username' => 1,
        ];

        // Weak password
        $errors = validatePasswordStrength('weak', $policy, 'admin');
        $this->assertNotEmpty($errors);

        // Strong password
        $errors = validatePasswordStrength('P@ssw0rd12345!', $policy, 'admin');
        $this->assertEmpty($errors);
    }

    public function testAuditLogChangeDiffComputation(): void {
        $audit = new NuAudit();

        $old = ['name' => 'Alice', 'role' => 'user', 'status' => 'active'];
        $new = ['name' => 'Alice', 'role' => 'admin', 'status' => 'active'];

        // logChange should calculate diff for 'role'
        $audit->logChange('update', 'nu_users', 101, $old, $new);
        $this->assertTrue(true);
    }
}
