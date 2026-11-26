<?php
declare(strict_types=1);

namespace Nuvis\Tests\Security;

use PHPUnit\Framework\TestCase;
use NuDatabase;
use ProjectContext;

require_once NU_ROOT . '/core/QueryExecutor.php';

/** Saved queries are project data: a code must only resolve inside the active project. */
class QueryProjectScopeTest extends TestCase
{
    protected function setUp(): void
    {
        $db = NuDatabase::getInstance();
        $db->exec('DROP TABLE IF EXISTS nu_queries');
        $db->exec("CREATE TABLE nu_queries (query_id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER DEFAULT 1,
            query_code TEXT, query_name TEXT, query_sql TEXT, query_description TEXT, query_parameters TEXT, query_active INTEGER DEFAULT 1)");
        $db->exec("INSERT INTO nu_queries (project_id, query_code, query_name, query_sql) VALUES
            (2, 'mine',   'Mine',   'SELECT 1 AS one'),
            (3, 'theirs', 'Theirs', 'SELECT 2 AS two'),
            (2, 'shared', 'P2',     'SELECT 20 AS v'),
            (3, 'shared', 'P3',     'SELECT 30 AS v')");
        $db->exec('DROP TABLE IF EXISTS nu_projects');
        $db->exec("CREATE TABLE nu_projects (project_id INTEGER PRIMARY KEY AUTOINCREMENT, project_code TEXT, project_name TEXT, project_owner_id TEXT NULL,
            project_active INTEGER DEFAULT 1, project_is_default INTEGER DEFAULT 0, project_is_public_test INTEGER DEFAULT 0,
            project_share_token TEXT NULL, project_share_mode TEXT NULL, tenant_code TEXT NOT NULL DEFAULT 'default')");
        $db->exec("INSERT INTO nu_projects (project_id, project_code, project_name, project_is_default) VALUES (1,'default','Default',1), (2,'p2','P2',0), (3,'p3','P3',0)");
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $_SESSION['nu_role'] = 'globeadmin';
        $_SESSION['nu_project_id'] = 2;
        ProjectContext::resetCache();
    }

    public function testQueryOfAnotherProjectIsNotFound(): void
    {
        $r = (new \NuQueryExecutor())->execute('theirs');
        $this->assertSame('Query not found', $r['error'] ?? null);
    }

    public function testQueryOfTheActiveProjectRuns(): void
    {
        $r = (new \NuQueryExecutor())->execute('mine');
        $this->assertArrayNotHasKey('error', $r, (string)($r['error'] ?? ''));
    }

    public function testSameCodeResolvesToTheActiveProjectsQuery(): void
    {
        $r = (new \NuQueryExecutor())->execute('shared');
        $this->assertSame('P2', $r['query']['query_name']);
    }
}
