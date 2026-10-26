<?php
declare(strict_types=1);

namespace Nuvis\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NuWfRules;
use NuWfExpr;

require_once NU_ROOT . '/core/WorkflowRules.php';

class WorkflowRulesTest extends TestCase {
    private array $ctx = [
        'record' => ['total' => 1500, 'status' => 'open', 'name' => 'Acme Ltd'],
        'actor'  => ['id' => 2, 'name' => 'Sam', 'role' => 'manager'],
    ];

    public function testStructuredAll(): void {
        $c = ['match' => 'all', 'rules' => [
            ['field' => 'record.total', 'op' => '>', 'value' => 1000],
            ['field' => 'record.status', 'op' => '=', 'value' => 'open'],
        ]];
        $this->assertTrue(NuWfRules::passes($c, $this->ctx));
    }

    public function testStructuredAny(): void {
        $c = ['match' => 'any', 'rules' => [
            ['field' => 'record.total', 'op' => '<', 'value' => 10],
            ['field' => 'record.name', 'op' => 'contains', 'value' => 'Acme'],
        ]];
        $this->assertTrue(NuWfRules::passes($c, $this->ctx));
    }

    public function testFailingRule(): void {
        $c = ['match' => 'all', 'rules' => [['field' => 'record.total', 'op' => 'between', 'value' => 1, 'value2' => 10]]];
        $this->assertFalse(NuWfRules::passes($c, $this->ctx));
    }

    public function testExpression(): void {
        $this->assertTrue((bool)NuWfExpr::evaluate('record.total > 1000 && record.status == "open"', $this->ctx));
        $this->assertEquals(3000, NuWfExpr::evaluate('record.total * 2', $this->ctx));
    }

    public function testRenderTemplates(): void {
        $this->assertSame('Hi Sam, Acme Ltd', NuWfRules::render('Hi {{actor.name}}, {{record.name}}', $this->ctx));
        $this->assertSame('x', NuWfRules::render('x{{nope.missing}}', $this->ctx));
    }
}
