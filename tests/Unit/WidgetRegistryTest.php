<?php
declare(strict_types=1);

namespace Nuvis\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NuDatabase;
use NuWidgetRegistry;

class WidgetRegistryTest extends TestCase {
    protected function setUp(): void {
        require_once dirname(__DIR__, 2) . '/core/WidgetSql.php';
        require_once dirname(__DIR__, 2) . '/core/WidgetRegistry.php';
        NuWidgetRegistry::reset();
    }

    private function widget(string $type, array $cfg, string $title = 'T'): array {
        return ['widget_id' => 1, 'widget_type' => $type, 'widget_title' => $title, 'widget_config' => json_encode($cfg)];
    }

    private function render(string $type, array $cfg, string $role = 'user'): array {
        return NuWidgetRegistry::renderWidget($this->widget($type, $cfg), NuDatabase::getInstance(), 1, $role);
    }

    public function testAllLoadsEveryTypeFileAndEachValidates(): void {
        $files = array_filter(glob(dirname(__DIR__, 2) . '/modules/widgets/types/*.php'), fn($f) => $f[strlen(dirname($f)) + 1] !== '_');
        $all = NuWidgetRegistry::all();
        $this->assertCount(count($files), $all);
        $this->assertNotEmpty($all);
        foreach ($all as $type => $def) {
            $this->assertSame([], NuWidgetRegistry::validate($def), $type);
            $this->assertSame($type, $def['type']);
        }
    }

    public function testGetUnknownTypeReturnsNull(): void {
        $this->assertNull(NuWidgetRegistry::get('does_not_exist'));
        $this->assertNull(NuWidgetRegistry::get('../../etc/passwd'));
    }

    public function testRenderUnknownTypeGivesErrorFragment(): void {
        $r = $this->render('nope', []);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('nu-wg-error', $r['html']);
    }

    public function testRenderBrokenSqlGivesErrorFragmentNotException(): void {
        $r = $this->render('stat', ['sql' => 'SELECT * FROM table_that_does_not_exist']);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('nu-wg-error', $r['html']);
        $this->assertStringContainsString('data-nu-retry', $r['html']);
        $this->assertStringNotContainsString('table_that_does_not_exist', $r['html'], 'normal users must not see SQL details');
        $dev = $this->render('stat', ['sql' => 'SELECT * FROM table_that_does_not_exist'], 'globeadmin');
        $this->assertStringContainsString('table_that_does_not_exist', $dev['html']);
    }

    public function testRenderEscapesTitleAndValues(): void {
        $r = $this->render('stat', ['sql' => 'SELECT 5 AS value', 'subtitle' => '<b>x</b>']);
        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $r['html']);
        $this->assertStringNotContainsString('<b>x</b>', $r['html']);
    }

    public function testRegisterRejectsInvalidDefinition(): void {
        $this->expectException(\InvalidArgumentException::class);
        NuWidgetRegistry::register(['type' => 'broken']);
    }

    public function testRegisterAddsPluginType(): void {
        NuWidgetRegistry::register([
            'type' => 'plugin_hello', 'label' => 'Hello', 'icon' => 'star', 'group' => 'Content',
            'defaults' => ['width' => 1, 'height' => 1, 'config' => []],
            'fields' => [['key' => 'text', 'label' => 'Text', 'kind' => 'text']],
            'render' => fn(array $w, array $cfg, array $ctx): string => '<p>hi</p>',
        ]);
        $this->assertNotNull(NuWidgetRegistry::get('plugin_hello'));
        $this->assertSame('<p>hi</p>', $this->render('plugin_hello', [])['html']);
    }

    public function testStatRendersFirstColumnWhenNoValueColumn(): void {
        $r = $this->render('stat', ['sql' => 'SELECT 1234 AS total']);
        $this->assertStringContainsString('1,234', $r['html']);
    }

    public function testStatThresholdsAddStateClass(): void {
        $warn = $this->render('stat', ['sql' => 'SELECT 60 AS value', 'warn_at' => 50, 'error_at' => 90]);
        $err  = $this->render('stat', ['sql' => 'SELECT 95 AS value', 'warn_at' => 50, 'error_at' => 90]);
        $ok   = $this->render('stat', ['sql' => 'SELECT 10 AS value', 'warn_at' => 50, 'error_at' => 90]);
        $this->assertStringContainsString('is-warn', $warn['html']);
        $this->assertStringContainsString('is-error', $err['html']);
        $this->assertStringNotContainsString('is-warn', $ok['html']);
        $this->assertStringNotContainsString('is-error', $ok['html']);
    }

    public function testStatWithoutSqlShowsEmptyHint(): void {
        $r = $this->render('stat', []);
        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('nu-wg-empty', $r['html']);
    }

    public function testStatLinkRejectsUnsafeUrl(): void {
        $bad = $this->render('stat', ['sql' => 'SELECT 1 AS value', 'link_url' => 'javascript:alert(1)']);
        $this->assertStringNotContainsString('javascript:', $bad['html']);
        $good = $this->render('stat', ['sql' => 'SELECT 1 AS value', 'link_url' => 'https://example.com/x']);
        $this->assertStringContainsString('href="https://example.com/x"', $good['html']);
        $mod = $this->render('stat', ['sql' => 'SELECT 1 AS value', 'link_module' => 'forms', 'link_mode' => 'popup']);
        $this->assertStringContainsString('data-nu-drill="forms"', $mod['html']);
        $this->assertStringContainsString('data-nu-mode="popup"', $mod['html']);
    }

    public function testListRendersItemsAndEscapes(): void {
        $r = $this->render('list', ['items' => [['label' => '<i>A</i>', 'module' => 'forms'], ['label' => 'B', 'url' => 'https://x.test']]]);
        $this->assertStringContainsString('&lt;i&gt;A&lt;/i&gt;', $r['html']);
        $this->assertStringContainsString('data-nu-drill="forms"', $r['html']);
        $this->assertStringContainsString('href="https://x.test"', $r['html']);
    }

    public function testListWithoutItemsShowsEmptyHint(): void {
        $this->assertStringContainsString('nu-wg-empty', $this->render('list', ['items' => []])['html']);
    }

    public function testCustomOutputsDeveloperHtml(): void {
        $this->assertStringContainsString('<p>Hello</p>', $this->render('custom', ['html' => '<p>Hello</p>'])['html']);
        $this->assertStringContainsString('nu-wg-empty', $this->render('custom', [])['html']);
    }

    public function testAnnouncementNeverEmitsHtml(): void {
        $r = $this->render('announcement', ['text' => "<b>Maintenance</b><script>x()</script>\nTonight", 'tone' => 'warning']);
        $this->assertStringNotContainsString('<b>', $r['html']);
        $this->assertStringNotContainsString('<script', $r['html']);
        $this->assertStringContainsString('Maintenance', $r['html']);
        $this->assertStringContainsString('tone-warning', $r['html']);
        $bad = $this->render('announcement', ['text' => 'x', 'tone' => 'evil"onload="x']);
        $this->assertStringNotContainsString('evil', $bad['html']);
    }
}
