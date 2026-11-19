<?php
declare(strict_types=1);

namespace Nuvis\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NuDatabase;
use NuWidgetSchema;

class WidgetSchemaTest extends TestCase {
    private NuDatabase $db;

    protected function setUp(): void {
        require_once dirname(__DIR__, 2) . '/core/WidgetSchema.php';
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
    }

    private function addRow(?int $user, ?string $role, string $title, string $cfg = '{"sql":"SELECT 1"}'): void {
        $this->db->query(
            "INSERT INTO nu_dashboard_widgets (widget_user_id, widget_role, widget_type, widget_title, widget_config)
             VALUES (?, ?, 'stat', ?, ?)",
            [$user, $role, $title, $cfg]
        );
    }

    private function rows(): int {
        return (int)$this->db->fetchOne("SELECT COUNT(*) AS c FROM nu_dashboard_widgets")['c'];
    }

    public function testDedupeKeepsLowestIdOfExactDuplicates(): void {
        for ($i = 0; $i < 4; $i++) $this->addRow(null, 'user', 'Available Forms');
        $removed = NuWidgetSchema::dedupeRoleWidgets($this->db);
        $this->assertSame(3, $removed);
        $this->assertSame(1, $this->rows());
        $this->assertSame(1, (int)$this->db->fetchOne("SELECT MIN(widget_id) AS m FROM nu_dashboard_widgets")['m']);
    }

    public function testDedupeKeepsRowsWithDifferentConfig(): void {
        $this->addRow(null, 'user', 'Available Forms', '{"sql":"SELECT 1"}');
        $this->addRow(null, 'user', 'Available Forms', '{"sql":"SELECT 2"}');
        $this->assertSame(0, NuWidgetSchema::dedupeRoleWidgets($this->db));
        $this->assertSame(2, $this->rows());
    }

    public function testDedupeDoesNotTouchPersonalRows(): void {
        $this->addRow(7, null, 'Mine');
        $this->addRow(7, null, 'Mine');
        $this->assertSame(0, NuWidgetSchema::dedupeRoleWidgets($this->db));
        $this->assertSame(2, $this->rows());
    }

    public function testEnsureIsIdempotent(): void {
        for ($i = 0; $i < 3; $i++) $this->addRow(null, 'user', 'Available Forms');
        NuWidgetSchema::ensure($this->db);
        $cols1 = array_column($this->db->fetchAll("PRAGMA table_info(nu_dashboard_widgets)"), 'name');
        $n1 = $this->rows();
        NuWidgetSchema::ensure($this->db);
        $cols2 = array_column($this->db->fetchAll("PRAGMA table_info(nu_dashboard_widgets)"), 'name');
        $this->assertSame($cols1, $cols2);
        $this->assertSame($n1, $this->rows());
        foreach (['widget_key', 'widget_locked', 'widget_refresh'] as $c) $this->assertContains($c, $cols2);
        $this->assertNotEmpty($this->db->fetchAll("SELECT name FROM sqlite_master WHERE name='nu_dashboard_user_prefs'"));
        $this->assertNotEmpty($this->db->fetchAll("SELECT name FROM sqlite_master WHERE name='nu_dashboard_user_settings'"));
    }

    public function testEnsureBackfillsWidgetKeyForSeededTitles(): void {
        $this->addRow(null, 'user', 'Available Forms');
        NuWidgetSchema::ensure($this->db);
        $row = $this->db->fetchOne("SELECT widget_key FROM nu_dashboard_widgets WHERE widget_role='user'");
        $this->assertSame('user.available_forms', $row['widget_key']);
    }

    public function testUniqueKeyStopsReSeedingFromInsertIgnore(): void {
        $this->addRow(null, 'user', 'Available Forms');
        NuWidgetSchema::ensure($this->db);
        $this->db->query(
            "INSERT OR IGNORE INTO nu_dashboard_widgets (widget_user_id, widget_role, widget_key, widget_type, widget_title, widget_config)
             VALUES (NULL, 'user', 'user.available_forms', 'stat', 'Available Forms', '{}')"
        );
        $this->assertSame(1, $this->rows());
    }
}
