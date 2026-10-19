<?php
declare(strict_types=1);

namespace Nuvis\Tests\Unit;

use PHPUnit\Framework\TestCase;

class WidgetTest extends TestCase {
    protected function setUp(): void {
        if (!defined('NU_BOOTSTRAP_DONE')) {
            define('NU_BOOTSTRAP_DONE', true);
        }
        require_once dirname(__DIR__, 2) . '/modules/widgets/widgets.php';
    }

    public function testChartTypeResolution(): void {
        $this->assertEquals('pie', wu_chart_type('chart_pie'));
        $this->assertEquals('doughnut', wu_chart_type('chart_doughnut'));
        $this->assertEquals('line', wu_chart_type('chart_line'));
        $this->assertEquals('line', wu_chart_type('chart_area'));
        $this->assertEquals('radar', wu_chart_type('chart_radar'));
        $this->assertEquals('line', wu_chart_type('sparkline'));
        $this->assertEquals('bar', wu_chart_type('chart_bar'));
        $this->assertEquals('bar', wu_chart_type('unknown_type'));
    }

    public function testTypeAccentResolution(): void {
        $this->assertEquals('#01696f', wu_type_accent('stat'));
        $this->assertEquals('#0ea5e9', wu_type_accent('metric_trend'));
        $this->assertEquals('#0284c7', wu_type_accent('sparkline'));
        $this->assertEquals('#006494', wu_type_accent('chart_bar'));
        $this->assertEquals('#7a39bb', wu_type_accent('chart_line'));
        $this->assertEquals('#8b5cf6', wu_type_accent('chart_area'));
        $this->assertEquals('#f59e0b', wu_type_accent('chart_doughnut'));
        $this->assertEquals('#ec4899', wu_type_accent('chart_radar'));
        $this->assertEquals('#10b981', wu_type_accent('quick_actions'));
        $this->assertEquals('#6366f1', wu_type_accent('activity'));
        $this->assertEquals('#64748b', wu_type_accent('iframe'));
    }

    public function testAccentColorResolution(): void {
        $this->assertEquals('#22c55e', wu_accent('success'));
        $this->assertEquals('#f59e0b', wu_accent('warning'));
        $this->assertEquals('#ef4444', wu_accent('error'));
        $this->assertEquals('#3b82f6', wu_accent('info'));
        $this->assertEquals('#0ea5e9', wu_accent('default'));
    }

    public function testDesignTokenDefinition(): void {
        $cssPath = dirname(__DIR__, 2) . '/assets/css/nubuilder-next.css';
        $this->assertFileExists($cssPath);
        $css = file_get_contents($cssPath);
        $this->assertStringContainsString('--color-success:', $css);
        $this->assertStringContainsString('--color-info:', $css);
        $this->assertStringContainsString('--surface-elevated:', $css);
        $this->assertStringContainsString('--focus-ring:', $css);
        $this->assertStringContainsString('--font-3xl:', $css);
    }

    public function testSkeletonLoaderClasses(): void {
        $cssPath = dirname(__DIR__, 2) . '/assets/css/nubuilder-next.css';
        $css = file_get_contents($cssPath);
        $this->assertStringContainsString('.nu-skeleton', $css);
        $this->assertStringContainsString('.nu-skeleton-text', $css);
        $this->assertStringContainsString('.nu-skeleton-card', $css);
    }
}
