<?php
declare(strict_types=1);

namespace Nuvis\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NuDatabase;
use NuWidgetSchema;
use NuWidgetStore;
use NuWidgetSql;

/** Installs with userIdType = 'uuid' have string user ids (usr_id VARCHAR(36)). */
class WidgetUuidUserTest extends TestCase {
    private const U1 = 'e3aa59b0-84b9-11f1-90f3-3cecef001126';
    private const U2 = '16899624-be53-49cf-a80f-37e01574caae';
    private NuDatabase $db;

    protected function setUp(): void {
        foreach (['WidgetSchema', 'WidgetPrefs', 'WidgetStore', 'WidgetSql'] as $f) require_once dirname(__DIR__, 2) . "/core/{$f}.php";
        $this->db = NuDatabase::getInstance();
        foreach (['nu_dashboard_widgets', 'nu_dashboard_user_prefs', 'nu_dashboard_user_settings'] as $t) {
            $this->db->exec("DROP TABLE IF EXISTS {$t}");
        }
        $this->db->exec("CREATE TABLE nu_dashboard_widgets (
            widget_id INTEGER PRIMARY KEY AUTOINCREMENT,
            widget_user_id INTEGER NULL, widget_role TEXT NULL,
            widget_type TEXT NOT NULL, widget_title TEXT NOT NULL DEFAULT '',
            widget_icon TEXT NULL, widget_config TEXT NOT NULL,
            widget_width INTEGER NOT NULL DEFAULT 1, widget_height INTEGER NOT NULL DEFAULT 1,
            widget_position INTEGER NOT NULL DEFAULT 0, widget_active INTEGER NOT NULL DEFAULT 1)");
        NuWidgetSchema::ensure($this->db);
    }

    private function colType(string $table, string $col): string {
        foreach ($this->db->fetchAll("PRAGMA table_info({$table})") as $r) {
            if ($r['name'] === $col) return strtoupper((string)$r['type']);
        }
        return '';
    }

    public function testNormalizeUserId(): void {
        $this->assertSame('5', NuWidgetStore::normalizeUserId(5));
        $this->assertSame('5', NuWidgetStore::normalizeUserId('5'));
        $this->assertSame(self::U1, NuWidgetStore::normalizeUserId(self::U1));
        foreach ([null, '', 0, '0', "a'b", 'x y', str_repeat('a', 65), []] as $bad) {
            $this->assertNull(NuWidgetStore::normalizeUserId($bad), var_export($bad, true));
        }
    }

    public function testEnsureMakesUserIdColumnsText(): void {
        $this->assertSame('TEXT', $this->colType('nu_dashboard_user_prefs', 'user_id'));
        $this->assertSame('TEXT', $this->colType('nu_dashboard_user_settings', 'user_id'));
    }

    public function testPrefsAndSettingsRoundTripWithUuid(): void {
        NuWidgetStore::savePrefs($this->db, self::U1, [['id' => 1, 'hidden' => 1]]);
        NuWidgetStore::savePrefs($this->db, self::U2, [['id' => 1, 'hidden' => 0]]);
        $this->assertSame(1, (int)NuWidgetStore::loadPrefs($this->db, self::U1)[1]['hidden']);
        $this->assertSame(0, (int)NuWidgetStore::loadPrefs($this->db, self::U2)[1]['hidden']);
        NuWidgetStore::saveSettings($this->db, self::U1, ['density' => 'compact']);
        $this->assertSame('compact', NuWidgetStore::loadSettings($this->db, self::U1)['density']);
        $this->assertSame('comfortable', NuWidgetStore::loadSettings($this->db, self::U2)['density']);
        NuWidgetStore::resetPrefs($this->db, self::U1);
        $this->assertSame([], NuWidgetStore::loadPrefs($this->db, self::U1));
        $this->assertCount(1, NuWidgetStore::loadPrefs($this->db, self::U2));
    }

    public function testVisibleWidgetsIncludesOwnPersonalRowForUuid(): void {
        $ins = "INSERT INTO nu_dashboard_widgets (widget_user_id, widget_role, widget_type, widget_title, widget_config) VALUES (?, ?, 'stat', ?, '{}')";
        $this->db->query($ins, [null, 'user', 'Role']);
        $this->db->query($ins, [self::U1, null, 'Mine']);
        $this->db->query($ins, [self::U2, null, 'Theirs']);
        $titles = array_column(NuWidgetStore::visibleWidgets($this->db, self::U1, 'user'), 'widget_title');
        $this->assertSame(['Role', 'Mine'], $titles);
    }

    public function testSqlSubstitutesUuidAsQuotedLiteral(): void {
        $this->assertSame("'" . self::U1 . "'", NuWidgetSql::substitute('{{user_id}}', self::U1, 'user'));
        $this->assertSame('42', NuWidgetSql::substitute('{{user_id}}', 42, 'user'));
        $rows = NuWidgetSql::run($this->db, 'SELECT {{user_id}} AS value', self::U1, 'user');
        $this->assertSame(self::U1, $rows[0]['value']);
    }
}
