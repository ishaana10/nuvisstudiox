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

    public function testThemeIsSanitised(): void {
        $t = NuPublicForm::cleanTheme(['width' => 'wide', 'accent' => '#0d9488', 'bg' => 'javascript:x', 'logo_url' => 'http://insecure/x.png', 'custom_css' => '@import url(x); .a{color:red}</style>']);
        $this->assertSame('wide', $t['width']);
        $this->assertSame('#0d9488', $t['accent']);
        $this->assertSame('', $t['bg']);
        $this->assertSame('', $t['logo_url']);
        $this->assertStringNotContainsString('@import', $t['custom_css']);
        $this->assertStringNotContainsString('<', $t['custom_css']);
    }

    public function testHtmlBlocksAreSanitised(): void {
        $h = NuPublicForm::sanitizeHtml('<p onclick="x()">Hi <b>there</b><script>alert(1)</script><a href="javascript:alert(1)">bad</a></p>');
        $this->assertStringContainsString('<b>there</b>', $h);
        $this->assertStringNotContainsString('script', $h);
        $this->assertStringNotContainsString('onclick', $h);
        $this->assertStringNotContainsString('javascript', $h);
    }

    public function testLayoutKeepsTabsSectionsAndDropsEmptyOnes(): void {
        $layout = ['items' => [
            ['type' => 'section', 'label' => 'S', 'children' => [['type' => 'row', 'fields' => [['name' => 'a', 'type' => 'text'], ['name' => 'b', 'type' => 'text']]]]],
            ['type' => 'tab', 'tabs' => [
                ['name' => 'One', 'rows' => [['type' => 'row', 'fields' => [['name' => 'c', 'type' => 'text']]]]],
                ['name' => 'Empty', 'rows' => [['type' => 'row', 'fields' => [['name' => 'hidden', 'type' => 'text']]]]],
            ]],
        ]];
        $visible = array_map(fn($n) => ['name' => $n], ['a', 'b', 'c']);
        $tree = NuPublicForm::layoutTree(['form_layout' => json_encode($layout)], $visible);
        $this->assertSame(['section', 'tabs'], array_column($tree, 'kind'));
        $this->assertCount(1, $tree[1]['tabs']);
        $this->assertSame('One', $tree[1]['tabs'][0]['label']);
    }
}
