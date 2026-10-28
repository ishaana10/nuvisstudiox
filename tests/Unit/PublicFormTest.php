<?php
declare(strict_types=1);

namespace Nuvis\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NuPublicForm;

require_once NU_ROOT . '/core/PublicForm.php';

class PublicFormTest extends TestCase {
    private function fields(): array {
        return [
            ['name' => 'name',  'label' => 'Name',  'type' => 'text',   'required' => true,  'options' => [], 'min' => null, 'max' => null],
            ['name' => 'email', 'label' => 'Email', 'type' => 'email',  'required' => true,  'options' => [], 'min' => null, 'max' => null],
            ['name' => 'qty',   'label' => 'Qty',   'type' => 'number', 'required' => false, 'options' => [], 'min' => 1, 'max' => 10],
            ['name' => 'kind',  'label' => 'Kind',  'type' => 'select', 'required' => false, 'options' => [['value' => 'a', 'label' => 'A']], 'min' => null, 'max' => null],
        ];
    }

    public function testValidSubmissionIsCleaned(): void {
        [$clean, $err] = NuPublicForm::validate($this->fields(), ['name' => ' Ann ', 'email' => 'a@b.co', 'qty' => '3', 'kind' => 'a']);
        $this->assertSame([], $err);
        $this->assertSame('Ann', $clean['name']);
        $this->assertEquals(3, $clean['qty']);
    }

    public function testInvalidValuesAreRejected(): void {
        [, $err] = NuPublicForm::validate($this->fields(), ['name' => '', 'email' => 'nope', 'qty' => '99', 'kind' => 'zzz']);
        $this->assertArrayHasKey('name', $err);
        $this->assertArrayHasKey('email', $err);
        $this->assertArrayHasKey('qty', $err);
        $this->assertArrayHasKey('kind', $err);
    }

    public function testFieldsNotOnTheFormAreIgnored(): void {
        [$clean] = NuPublicForm::validate($this->fields(), ['name' => 'A', 'email' => 'a@b.co', 'id' => 5, 'is_admin' => 1]);
        $this->assertArrayNotHasKey('id', $clean);
        $this->assertArrayNotHasKey('is_admin', $clean);
    }

    public function testTokenNeedsAgeAndValidSignature(): void {
        $share = ['fs_token' => str_repeat('a', 32), 'fs_secret' => 'secret'];
        $this->assertFalse(NuPublicForm::checkToken($share, NuPublicForm::signToken($share)), 'too fresh');
        $ts = (string)(time() - 10);
        $good = $ts . '.' . hash_hmac('sha256', $ts . '|' . $share['fs_token'], 'secret');
        $this->assertTrue(NuPublicForm::checkToken($share, $good));
        $this->assertFalse(NuPublicForm::checkToken($share, $ts . '.' . str_repeat('0', 64)));
    }
}
