<?php
declare(strict_types=1);

namespace Nuvis\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NuDatabase;
use NuWidgetRegistry;

class WidgetTypesTest extends TestCase {
    private const EXPECTED_TYPES = [
        'stat', 'metric_trend', 'sparkline', 'chart_bar', 'chart_line', 'chart_area', 'chart_pie', 'chart_doughnut',
        'chart_radar', 'table', 'list', 'quick_actions', 'activity', 'progress', 'gauge', 'iframe', 'custom', 'announcement',
    ];
    private const MULTI_SQL = "SELECT 'a' AS label, 120 AS value, 100 AS prev_value, 3 AS done, 4 AS total, 'now' AS time, 'ann' AS user, 'did it' AS text";

    protected function setUp(): void {
        require_once dirname(__DIR__, 2) . '/core/WidgetSql.php';
        require_once dirname(__DIR__, 2) . '/core/WidgetRegistry.php';
        NuWidgetRegistry::reset();
    }

    private function render(string $type, array $cfg, string $role = 'user'): array {
        $w = ['widget_id' => 1, 'widget_type' => $type, 'widget_title' => 'T', 'widget_config' => json_encode($cfg)];
        return NuWidgetRegistry::renderWidget($w, NuDatabase::getInstance(), 1, $role);
    }

    private function chartData(string $html): array {
        $this->assertSame(1, preg_match('/data-nu-chart="([^"]*)"/', $html, $m), 'chart data attribute missing');
        return json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
    }

    public static function typeProvider(): array {
        return array_map(fn($t) => [$t], self::EXPECTED_TYPES);
    }

    /** @dataProvider typeProvider */
    public function testEachTypeRendersWithMinimalConfig(string $type): void {
        $def = NuWidgetRegistry::get($type);
        $this->assertNotNull($def, "type {$type} is registered");
        $cfg = $def['defaults']['config'];
        foreach ($def['fields'] as $f) {
            $cfg[$f['key']] = match ($f['kind']) {
                'sql' => self::MULTI_SQL,
                'url' => 'https://example.com',
                'lines' => [['label' => 'Forms', 'module' => 'forms', 'icon' => 'fa-bolt']],
                'html' => '<p>x</p>',
                'number' => 10,
                'select' => $f['options'][0] ?? '',
                default => 'x',
            };
        }
        $r = $this->render($type, $cfg);
        $this->assertTrue($r['ok'], $type . ': ' . $r['html']);
        $this->assertNotSame('', trim($r['html']));
        if ($type !== 'custom') {
            $this->assertStringNotContainsString(' style="', $r['html'], 'no inline styles in widget markup');
        }
    }

    public function testAllExpectedTypesAreRegistered(): void {
        $this->assertEqualsCanonicalizing(self::EXPECTED_TYPES, array_keys(NuWidgetRegistry::all()));
    }

    public function testChartTypeEmitsDataAttributeJson(): void {
        $sql = "SELECT 'a' AS label, 3 AS value UNION ALL SELECT 'b', 5";
        $bar = $this->chartData($this->render('chart_bar', ['sql' => $sql])['html']);
        $this->assertSame(['type' => 'bar', 'labels' => ['a', 'b'], 'values' => [3, 5]], array_intersect_key($bar, array_flip(['type', 'labels', 'values'])));
        $area = $this->chartData($this->render('chart_area', ['sql' => $sql])['html']);
        $this->assertSame('line', $area['type']);
        $this->assertTrue($area['area']);
        $this->assertSame('doughnut', $this->chartData($this->render('chart_doughnut', ['sql' => $sql])['html'])['type']);
        $this->assertSame('radar', $this->chartData($this->render('chart_radar', ['sql' => $sql])['html'])['type']);
        $spark = $this->chartData($this->render('sparkline', ['sql' => $sql])['html']);
        $this->assertSame('line', $spark['type']);
        $this->assertTrue($spark['compact']);
    }

    public function testChartWithNoRowsShowsEmptyNotBlankCanvas(): void {
        $r = $this->render('chart_bar', ['sql' => "SELECT 'a' AS label, 1 AS value WHERE 1=0"]);
        $this->assertStringContainsString('nu-wg-empty', $r['html']);
    }

    public function testMetricTrendComputesChangeAndNeverDividesByZero(): void {
        $up = $this->render('metric_trend', ['sql' => 'SELECT 120 AS value, 100 AS prev_value']);
        $this->assertStringContainsString('is-up', $up['html']);
        $this->assertStringContainsString('20%', $up['html']);
        $zero = $this->render('metric_trend', ['sql' => 'SELECT 50 AS value, 0 AS prev_value']);
        $this->assertTrue($zero['ok']);
        $this->assertStringContainsString('0%', $zero['html']);
    }

    public function testProgressComputesPercent(): void {
        $r = $this->render('progress', ['sql' => 'SELECT 3 AS done, 4 AS total']);
        $this->assertStringContainsString('value="75"', $r['html']);
        $this->assertStringContainsString('75%', $r['html']);
        $zero = $this->render('progress', ['sql' => 'SELECT 0 AS done, 0 AS total']);
        $this->assertStringContainsString('value="0"', $zero['html']);
    }

    public function testGaugeClampsTo100(): void {
        $r = $this->render('gauge', ['sql' => 'SELECT 500 AS done, 100 AS total']);
        $this->assertStringContainsString('data-pct="100"', $r['html']);
    }

    public function testIframeRejectsNonHttpUrl(): void {
        $bad = $this->render('iframe', ['url' => 'javascript:alert(1)']);
        $this->assertStringNotContainsString('<iframe', $bad['html']);
        $this->assertStringContainsString('nu-wg-empty', $bad['html']);
        $ok = $this->render('iframe', ['url' => 'https://example.com/dash', 'height' => 300]);
        $this->assertStringContainsString('<iframe', $ok['html']);
        $this->assertStringContainsString('src="https://example.com/dash"', $ok['html']);
        $this->assertStringContainsString('height="300"', $ok['html']);
    }

    public function testTableEscapesCells(): void {
        $r = $this->render('table', ['sql' => "SELECT '<b>x</b>' AS name"]);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $r['html']);
        $this->assertStringNotContainsString('<b>x</b>', $r['html']);
        $this->assertStringContainsString('Name', $r['html']);
    }

    public function testActivityMapsTimeUserText(): void {
        $r = $this->render('activity', ['sql' => "SELECT 'now' AS time, 'ann' AS user, 'did <x>' AS text"]);
        $this->assertStringContainsString('ann', $r['html']);
        $this->assertStringContainsString('did &lt;x&gt;', $r['html']);
        $this->assertStringContainsString('>A<', $r['html']);
    }

    public function testQuickActionsRenderLegacyFontAwesomeAndEscape(): void {
        $r = $this->render('quick_actions', ['items' => [['label' => '<i>Go</i>', 'module' => 'forms', 'icon' => 'fa-bolt'], ['label' => 'Web', 'url' => 'https://x.test', 'icon' => '🚀']]]);
        $this->assertStringContainsString('class="fas fa-bolt"', $r['html']);
        $this->assertStringContainsString('🚀', $r['html']);
        $this->assertStringContainsString('&lt;i&gt;Go&lt;/i&gt;', $r['html']);
        $evil = $this->render('quick_actions', ['items' => [['label' => 'x', 'module' => 'forms', 'icon' => 'fa-bolt" onclick="x']]]);
        $this->assertStringNotContainsString('onclick', $evil['html']);
    }

    public function testLegacyHelpersKeepTheirOutputs(): void {
        $this->assertSame('pie', wu_chart_type('chart_pie'));
        $this->assertSame('line', wu_chart_type('chart_area'));
        $this->assertSame('bar', wu_chart_type('unknown'));
        $this->assertSame('#01696f', wu_type_accent('stat'));
        $this->assertSame('#22c55e', wu_accent('success'));
        $this->assertSame('#0ea5e9', wu_accent('default'));
    }
}
