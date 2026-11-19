<?php
declare(strict_types=1);

namespace Nuvis\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NuDatabase;
use NuWidgetSql;

class WidgetSqlTest extends TestCase {
    protected function setUp(): void {
        require_once dirname(__DIR__, 2) . '/core/WidgetSql.php';
    }

    public function testSubstituteReplacesAllPlaceholders(): void {
        $sql = NuWidgetSql::substitute('{{user_id}}|{{role}}|{{today}}|{{this_month}}|{{this_year}}', 42, 'manager');
        $this->assertSame(
            '42|manager|' . date('Y-m-d') . '|' . date('Y-m-01') . '|' . date('Y-01-01'),
            $sql
        );
    }

    public function testRunReturnsRowsForSelect(): void {
        $rows = NuWidgetSql::run(NuDatabase::getInstance(), 'SELECT {{user_id}} AS value', 7, 'user');
        $this->assertSame(7, (int)$rows[0]['value']);
    }

    public function testRunRejectsNonSelect(): void {
        $db = NuDatabase::getInstance();
        $this->assertSame([], NuWidgetSql::run($db, 'DELETE FROM nu_dashboard_widgets', 1, 'user'));
        $this->assertSame([], NuWidgetSql::run($db, 'UPDATE nu_users SET x=1', 1, 'user'));
    }

    public function testRunRejectsMultipleStatements(): void {
        $this->assertSame([], NuWidgetSql::run(NuDatabase::getInstance(), 'SELECT 1; DROP TABLE nu_users', 1, 'user'));
    }

    public function testRunAllowsSemicolonInsideStringLiteral(): void {
        $rows = NuWidgetSql::run(NuDatabase::getInstance(), "SELECT 'a;b' AS value", 1, 'user');
        $this->assertSame('a;b', $rows[0]['value']);
    }

    public function testRunThrowsWithDbMessageOnQueryFailure(): void {
        $this->expectException(\RuntimeException::class);
        NuWidgetSql::run(NuDatabase::getInstance(), 'SELECT * FROM table_that_does_not_exist', 1, 'user');
    }
}
