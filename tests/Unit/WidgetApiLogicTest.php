<?php
declare(strict_types=1);

namespace Nuvis\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NuDatabase;
use NuWidgetPrefs;
use NuWidgetSchema;
use NuWidgetStore;

class WidgetApiLogicTest extends TestCase {
    private NuDatabase $db;

    protected function setUp(): void {
        foreach (['WidgetSchema', 'WidgetPrefs', 'WidgetStore'] as $f) require_once dirname(__DIR__, 2) . "/core/{$f}.php";
        $this->db = NuDatabase::getInstance();
        foreach (['nu_dashboard_widgets', 'nu_dashboard_user_prefs', 'nu_dashboard_user_settings'] as $t) {
            $this->db->exec("DROP TABLE IF EXISTS {$t}");
        }
        $this->db->exec("CREATE TABLE nu_dashboard_widgets (
            widget_id INTEGER PRIMARY KEY AUTOINCREMENT, widget_user_id INTEGER NULL, widget_role TEXT NULL,
            widget_type TEXT NOT NULL, widget_title TEXT NOT NULL DEFAULT '', widget_icon TEXT NULL, widget_config TEXT NOT NULL,
            widget_width INTEGER NOT NULL DEFAULT 1, widget_height INTEGER NOT NULL DEFAULT 1,
            widget_position INTEGER NOT NULL DEFAULT 0, widget_active INTEGER NOT NULL DEFAULT 1)");
        NuWidgetSchema::ensure($this->db);
    }

    private function add(?int $user, ?string $role, int $pos, int $active = 1): int {
        $this->db->query(
            "INSERT INTO nu_dashboard_widgets (widget_user_id, widget_role, widget_type, widget_title, widget_config, widget_position, widget_active)
             VALUES (?, ?, 'stat', 'W', '{}', ?, ?)",
            [$user, $role, $pos, $active]
        );
        return $this->db->lastInsertId();
    }

    public function testVisibleWidgetsAreOwnRoleWidgetsPlusOwnPersonalOnly(): void {
        $mine   = $this->add(null, 'user', 10);
        $other  = $this->add(null, 'manager', 10);
        $off    = $this->add(null, 'user', 20, 0);
        $pers   = $this->add(7, null, 30);
        $theirs = $this->add(8, null, 30);
        $ids = array_column(NuWidgetStore::visibleWidgets($this->db, 7, 'user'), 'widget_id');
        $this->assertEqualsCanonicalizing([$mine, $pers], array_map('intval', $ids));
        $this->assertNotContains($other, array_map('intval', $ids));
        $this->assertNotContains($off, array_map('intval', $ids));
        $this->assertNotContains($theirs, array_map('intval', $ids));
    }

    public function testPrefsRoundTripOnSqlite(): void {
        $a = $this->add(null, 'user', 10); $b = $this->add(null, 'user', 20); $c = $this->add(null, 'user', 30);
        NuWidgetStore::savePrefs($this->db, 7, [['id' => $c, 'position' => 5, 'width' => 4, 'accent' => 'teal']]);
        $r = NuWidgetPrefs::resolve(NuWidgetStore::visibleWidgets($this->db, 7, 'user'), NuWidgetStore::loadPrefs($this->db, 7));
        $this->assertSame([$c, $a, $b], array_map('intval', array_column($r['visible'], 'widget_id')));
        $this->assertSame(4, $r['visible'][0]['widget_width']);
        $this->assertSame('teal', $r['visible'][0]['accent']);
        $other = NuWidgetPrefs::resolve(NuWidgetStore::visibleWidgets($this->db, 8, 'user'), NuWidgetStore::loadPrefs($this->db, 8));
        $this->assertSame([$a, $b, $c], array_map('intval', array_column($other['visible'], 'widget_id')), 'other users are unaffected');
    }

    public function testSavePrefsMergesPartialUpdates(): void {
        $a = $this->add(null, 'user', 10);
        NuWidgetStore::savePrefs($this->db, 7, [['id' => $a, 'hidden' => 1]]);
        NuWidgetStore::savePrefs($this->db, 7, [['id' => $a, 'accent' => 'rose']]);
        $p = NuWidgetStore::loadPrefs($this->db, 7)[$a];
        $this->assertSame(1, (int)$p['hidden']);
        $this->assertSame('rose', $p['accent']);
    }

    public function testPrefsResetDeletesOnlyThatUsersRows(): void {
        $a = $this->add(null, 'user', 10);
        NuWidgetStore::savePrefs($this->db, 7, [['id' => $a, 'hidden' => 1]]);
        NuWidgetStore::savePrefs($this->db, 8, [['id' => $a, 'hidden' => 1]]);
        NuWidgetStore::saveSettings($this->db, 7, ['density' => 'compact']);
        $this->assertSame(1, NuWidgetStore::resetPrefs($this->db, 7));
        $this->assertSame([], NuWidgetStore::loadPrefs($this->db, 7));
        $this->assertCount(1, NuWidgetStore::loadPrefs($this->db, 8));
        $this->assertSame('comfortable', NuWidgetStore::loadSettings($this->db, 7)['density'], 'reset also clears look settings');
    }

    public function testSettingsSaveStoresNormalizedJson(): void {
        $saved = NuWidgetStore::saveSettings($this->db, 7, ['accent' => 'bad;}', 'density' => 'compact', 'card_style' => 'glass']);
        $this->assertSame(['accent' => null, 'density' => 'compact', 'card_style' => 'soft'], $saved);
        $raw = $this->db->fetchOne("SELECT settings FROM nu_dashboard_user_settings WHERE user_id = 7")['settings'];
        $this->assertSame($saved, json_decode($raw, true));
        NuWidgetStore::saveSettings($this->db, 7, ['accent' => 'violet']);
        $this->assertSame('violet', NuWidgetStore::loadSettings($this->db, 7)['accent'], 'second save updates the same row');
    }

    public function testLoadSettingsDefaultsWhenNoRow(): void {
        $this->assertSame(['accent' => null, 'density' => 'comfortable', 'card_style' => 'soft'], NuWidgetStore::loadSettings($this->db, 99));
    }

    public function testVisibleWidgetsForRoleWithNoWidgetsIsEmpty(): void {
        $this->assertSame([], NuWidgetStore::visibleWidgets($this->db, 7, 'brand_new_role'));
    }

    public function testRoleCodesAreNormalisedSoDesignerAndUsersAgree(): void {
        $this->assertSame('sales_clerk', NuWidgetStore::normalizeRole('  Sales_Clerk '));
        $this->assertSame('user', NuWidgetStore::normalizeRole('USER'));
        $this->assertSame('', NuWidgetStore::normalizeRole(''));
    }
}
