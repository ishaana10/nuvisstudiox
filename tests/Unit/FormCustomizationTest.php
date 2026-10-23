<?php
declare(strict_types=1);

namespace Nuvis\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NuPage;

require_once __DIR__ . '/../../api/_form_layout_helpers.php';
require_once __DIR__ . '/../../core/NuPage.php';

final class FormCustomizationTest extends TestCase
{
    public function testFormStyleParsingDefault(): void
    {
        $layout = [];
        $style = nu_parse_form_style($layout);

        $this->assertEquals('comfortable', $style['density']);
        $this->assertEquals('default', $style['card_style']);
        $this->assertEquals('top', $style['label_align']);
    }

    public function testFormStyleParsingFromNode(): void
    {
        $layout = [
            [
                'type' => '_form_style',
                'density' => 'compact',
                'card_style' => 'elevated',
                'label_align' => 'left',
                'header_bg' => '#1e293b',
                'accent_color' => '#0ea5e9',
                'border_radius' => '8px',
            ]
        ];

        $style = nu_parse_form_style($layout);

        $this->assertEquals('compact', $style['density']);
        $this->assertEquals('elevated', $style['card_style']);
        $this->assertEquals('left', $style['label_align']);
        $this->assertEquals('#1e293b', $style['header_bg']);
        $this->assertEquals('#0ea5e9', $style['accent_color']);
        $this->assertEquals('8px', $style['border_radius']);

        $cssAttrs = nu_build_form_style_css($style);
        $this->assertStringContainsString('nu-density-compact', $cssAttrs);
        $this->assertStringContainsString('nu-card-elevated', $cssAttrs);
        $this->assertStringContainsString('nu-labels-left', $cssAttrs);
        $this->assertStringContainsString('--nu-form-header-bg: #1e293b', $cssAttrs);
    }

    public function testNuPageHelperApi(): void
    {
        $nu = new NuPage();
        $this->assertEquals(1, $nu->apiVersion());

        $url = $nu->formUrl('customers', '101');
        $this->assertEquals('?p=form&code=customers&id=101', $url);
    }
}
