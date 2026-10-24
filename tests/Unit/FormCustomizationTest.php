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

    public function testFlattenLayoutFieldsExtraction(): void
    {
        $layout = [
            [
                'type' => 'text',
                'name' => 'first_name',
                'label' => 'First Name',
                'col' => 6,
                'row_index' => 0
            ],
            [
                'type' => 'text',
                'name' => 'last_name',
                'label' => 'Last Name',
                'col' => 6,
                'row_index' => 0
            ],
            [
                'type' => 'group',
                'name' => 'contact_group',
                'label' => 'Contact Information',
                'children' => [
                    [
                        'type' => 'email',
                        'name' => 'email',
                        'label' => 'Email Address',
                        'col' => 12
                    ]
                ]
            ],
            [
                'type' => '_form_style',
                'density' => 'relaxed'
            ]
        ];

        $flattened = nu_flatten_layout_fields($layout);

        $this->assertCount(3, $flattened);
        $names = array_column($flattened, 'name');
        $this->assertContains('first_name', $names);
        $this->assertContains('last_name', $names);
        $this->assertContains('email', $names);
    }

    public function testRowColumnSpanDistribution(): void
    {
        // 1 Col -> span 12, 2 Cols -> span 6, 3 Cols -> span 4, 4 Cols -> span 3
        $calculateSpan = fn(int $cols): int => (int) floor(12 / max(1, min(4, $cols)));

        $this->assertEquals(12, $calculateSpan(1));
        $this->assertEquals(6, $calculateSpan(2));
        $this->assertEquals(4, $calculateSpan(3));
        $this->assertEquals(3, $calculateSpan(4));
    }
}
