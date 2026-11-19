<?php
declare(strict_types=1);

namespace Nuvis\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NuWidgetPrefs;

class WidgetPrefsTest extends TestCase {
    protected function setUp(): void {
        require_once dirname(__DIR__, 2) . '/core/WidgetPrefs.php';
    }

    private function w(int $id, int $pos, int $locked = 0, int $width = 2, int $height = 1): array {
        return ['widget_id' => $id, 'widget_position' => $pos, 'widget_locked' => $locked,
                'widget_width' => $width, 'widget_height' => $height];
    }

    public function testValidAccentAcceptsHexAndTokens(): void {
        $this->assertSame('#0ea5e9', NuWidgetPrefs::validAccent('#0ea5e9'));
        $this->assertSame('violet', NuWidgetPrefs::validAccent('violet'));
        $this->assertNull(NuWidgetPrefs::validAccent(null));
        $this->assertNull(NuWidgetPrefs::validAccent(''));
    }

    public function testValidAccentRejectsInjection(): void {
        foreach (['red;}</style><script>', '#GGGGGG', '#ABCDEF', '#abc', 'javascript:1', 'blue ', 'url(x)'] as $bad) {
            $this->assertNull(NuWidgetPrefs::validAccent($bad), $bad);
        }
    }

    public function testNormalizeSettingsDefaults(): void {
        $this->assertSame(
            ['accent' => null, 'density' => 'comfortable', 'card_style' => 'soft'],
            NuWidgetPrefs::normalizeSettings([])
        );
    }

    public function testNormalizeSettingsRejectsUnknownValues(): void {
        $s = NuWidgetPrefs::normalizeSettings(['accent' => 'nope;', 'density' => 'huge', 'card_style' => 'glass']);
        $this->assertSame(['accent' => null, 'density' => 'comfortable', 'card_style' => 'soft'], $s);
        $ok = NuWidgetPrefs::normalizeSettings(['accent' => 'rose', 'density' => 'compact', 'card_style' => 'outlined']);
        $this->assertSame(['accent' => 'rose', 'density' => 'compact', 'card_style' => 'outlined'], $ok);
    }

    public function testResolveAppliesUserOrderSizeAndAccent(): void {
        $widgets = [$this->w(1, 10), $this->w(2, 20), $this->w(3, 30)];
        $prefs = [
            3 => ['hidden' => 0, 'position' => 5, 'width' => 4, 'height' => 2, 'accent' => 'teal'],
        ];
        $r = NuWidgetPrefs::resolve($widgets, $prefs);
        $this->assertSame([3, 1, 2], array_column($r['visible'], 'widget_id'));
        $this->assertSame(4, $r['visible'][0]['widget_width']);
        $this->assertSame(2, $r['visible'][0]['widget_height']);
        $this->assertSame('teal', $r['visible'][0]['accent']);
        $this->assertNull($r['visible'][1]['accent']);
        $this->assertSame([], $r['hidden']);
    }

    public function testResolveMovesHiddenWidgetsToHiddenList(): void {
        $r = NuWidgetPrefs::resolve([$this->w(1, 10), $this->w(2, 20)], [2 => ['hidden' => 1]]);
        $this->assertSame([1], array_column($r['visible'], 'widget_id'));
        $this->assertSame([2], array_column($r['hidden'], 'widget_id'));
    }

    public function testResolveCannotHideLockedWidget(): void {
        $r = NuWidgetPrefs::resolve([$this->w(1, 10, 1)], [1 => ['hidden' => 1]]);
        $this->assertSame([1], array_column($r['visible'], 'widget_id'));
        $this->assertSame([], $r['hidden']);
    }

    public function testResolveIgnoresOrphanPrefs(): void {
        $r = NuWidgetPrefs::resolve([$this->w(1, 10)], [99 => ['hidden' => 1, 'position' => 1]]);
        $this->assertSame([1], array_column($r['visible'], 'widget_id'));
    }

    public function testResolveWithNoWidgetsIsEmpty(): void {
        $this->assertSame(['visible' => [], 'hidden' => []], NuWidgetPrefs::resolve([], [1 => ['hidden' => 1]]));
    }

    public function testResolveClampsWidthAndHeight(): void {
        $r = NuWidgetPrefs::resolve([$this->w(1, 10), $this->w(2, 20)], [
            1 => ['width' => 9, 'height' => 9],
            2 => ['width' => 0, 'height' => 0],
        ]);
        $this->assertSame([4, 3], [$r['visible'][0]['widget_width'], $r['visible'][0]['widget_height']]);
        $this->assertSame([1, 1], [$r['visible'][1]['widget_width'], $r['visible'][1]['widget_height']]);
    }

    public function testSaveItemsIgnoreHiddenForLockedWidget(): void {
        $byId = [5 => $this->w(5, 10, 1), 6 => $this->w(6, 20, 0)];
        $out = NuWidgetPrefs::sanitizeSaveItems([['id' => 5, 'hidden' => true], ['id' => 6, 'hidden' => true]], $byId);
        $this->assertSame(0, $out[0]['hidden']);
        $this->assertSame(1, $out[1]['hidden']);
    }

    public function testSaveItemsDropUnknownWidgetIds(): void {
        $out = NuWidgetPrefs::sanitizeSaveItems([['id' => 1, 'position' => 3], ['id' => 404, 'position' => 1], ['nope' => 1]], [1 => $this->w(1, 10)]);
        $this->assertSame([1], array_column($out, 'id'));
    }

    public function testSaveItemsInvalidAccentBecomesNull(): void {
        $out = NuWidgetPrefs::sanitizeSaveItems([['id' => 1, 'accent' => 'red;}</style>']], [1 => $this->w(1, 10)]);
        $this->assertArrayHasKey('accent', $out[0]);
        $this->assertNull($out[0]['accent']);
    }

    public function testSaveItemsOnlyKeepProvidedKeysAndClamp(): void {
        $out = NuWidgetPrefs::sanitizeSaveItems([['id' => 1, 'width' => 12, 'height' => -3]], [1 => $this->w(1, 10)]);
        $this->assertSame(['id' => 1, 'width' => 4, 'height' => 1], $out[0]);
    }
}
