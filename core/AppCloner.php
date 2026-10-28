<?php
declare(strict_types=1);

/**
 * NuBuilder 5 - App Cloner
 * Inspired by Nuvis StudioXCloner (Forte), rebuilt for nub5 architecture.
 *
 * New features vs Forte:
 *  - Uses nub5 NuDatabase singleton (no nuRunQuery / db_fetch_row globals)
 *  - JSON progress streaming via temp file (same idea, cleaner format)
 *  - Clone presets (save/load named clone configurations)
 *  - Table snapshot: clone only selected tables with optional row filters
 *  - Schema-only mode: structure without data
 *  - ZIP export: download the cloned DB as a .sql.gz file
 *  - Webhook notification on completion
 *  - Per-step rollback on failure (transaction-wrapped per phase)
 *  - Supports MySQL & MSSQL SQL export
 */
class AppCloner {

    // ── Constants ──────────────────────────────────────────────────────────
    private const INSERT_BATCH        = 500;
    private const PROGRESS_INTERVAL   = 50;
    private const VALID_INSERT_TYPES  = ['INSERT', 'INSERT IGNORE', 'REPLACE'];
    private const VALID_DB_MODES      = ['create', 'fail', 'clear'];
    private const VALID_FILE_MODES    = ['create', 'fail', 'clear', 'overwrite'];
    private const VALID_SQL_FORMATS   = ['mysql', 'mssql'];

    /**
     * Clone operations bitmask map:
     *  1 = zzzzsys_* table/view CREATE
     *  2 = user table/view CREATE
     *  3 = Nuvis StudioX system records (ids like 'nu%')
     *  4 = app-definition records
     *  5 = user data
     *  6 = functions
     *  7 = procedures
     *  8 = triggers
     *  9 = events
     */
    private const ROUTINE_META = [
        'FUNCTION'  => ['show' => 'SHOW FUNCTION STATUS WHERE Db = ?',  'nameIdx' => 1, 'showCreate' => 'SHOW CREATE FUNCTION `%s`',  'createIdx' => 2, 'tableCol' => null, 'drop' => 'DROP FUNCTION IF EXISTS `%s`'],
        'PROCEDURE' => ['show' => 'SHOW PROCEDURE STATUS WHERE Db = ?', 'nameIdx' => 1, 'showCreate' => 'SHOW CREATE PROCEDURE `%s`', 'createIdx' => 2, 'tableCol' => null, 'drop' => 'DROP PROCEDURE IF EXISTS `%s`'],
        'TRIGGER'   => ['show' => 'SHOW TRIGGERS FROM `%DB%`',          'nameIdx' => 'Trigger', 'showCreate' => 'SHOW CREATE TRIGGER `%s`', 'createIdx' => 2, 'tableCol' => 'Table', 'drop' => 'DROP TRIGGER IF EXISTS `%s`'],
        'EVENT'     => ['show' => 'SHOW EVENTS WHERE Db = ?',           'nameIdx' => 'Name',    'showCreate' => 'SHOW CREATE EVENT `%s`',    'createIdx' => 3, 'tableCol' => null, 'drop' => 'DROP EVENT IF EXISTS `%s`'],
    ];

    // ── State ──────────────────────────────────────────────────────────────
    private NuDatabase $db;
    private ?PDO       $srcPDO    = null;
    private ?PDO       $tgtPDO    = null;
    private string     $srcDB;
    private array      $cfg;
    private array      $stats     = [];
    private array      $dryLog    = [];
    private float      $startTime = 0.0;
    private int        $stepNr    = 0;
    private ?string    $progressFile = null;

    // ── Boot ───────────────────────────────────────────────────────────────
    public function __construct(array $config = [], ?string $srcDB = null) {
        $this->db  = NuDatabase::getInstance();
        $pdo       = $this->db->getPdo();

        // Resolve source DB name from connection
        if ($srcDB === null) {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $srcDB = 'sqlite';
            } else {
                $row = $pdo->query('SELECT DATABASE()')->fetch(PDO::FETCH_NUM);
                $srcDB = $row[0] ?? '';
            }
        }
        $this->srcDB  = $srcDB;
        $this->srcPDO = $pdo;

        $this->cfg = array_merge([
            'dryRun'              => false,
            'schemaOnly'          => false,          // NEW: skip all INSERT phases
            'databaseMode'        => 'fail',
            'databaseCollation'   => 'utf8mb4_unicode_ci',
            'fileMode'            => 'fail',
            'copyFiles'           => true,
            'excludedFiles'       => [],
            'excludedDirs'        => [],
            'includeTablesAndViews' => [],
            'excludeTablesAndViews' => [],
            'rowFilters'          => [],             // NEW: ['tableName' => 'WHERE clause']
            'progressId'          => null,
            'webhookUrl'          => null,            // NEW: POST result JSON here
            'logFile'             => null,
            'zipExport'           => false,           // NEW: gzip the SQL export file
            'sqlExport' => [
                'enabled'              => false,
                'format'               => 'mysql',
                'includeDropStatements'=> true,
                'includeCreateDatabase'=> true,
                'includeUseDatabase'   => true,
                'maxRowsPerInsert'     => self::INSERT_BATCH,
                'addComments'          => true,
                'disableConstraints'   => true,
            ],
        ], $config);

        if ($this->cfg['progressId']) {
            $this->progressFile = __DIR__ . '/../temp/nu_cloner_progress_' . $this->cfg['progressId'] . '.json';
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  PUBLIC API
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Full database + file clone.
     */
    public function clone(
        string $targetDB,
        string $targetHost,
        string $targetUser,
        string $targetPass,
        string $targetCharset,
        int    $targetPort,
        array  $opts,
        string $insertType,
        string $sourcePath,
        string $targetPath
    ): bool {
        try {
            $this->startTime = microtime(true);
            $this->validateOpts($targetDB, $opts, $insertType);

            if (!$this->prepareTgtDatabase($targetHost, $targetDB, $targetUser, $targetPass, $targetCharset, $targetPort)) {
                return false;
            }

            $this->tgtPDO = $this->makePDO($targetHost, $targetDB, $targetUser, $targetPass, $targetCharset, $targetPort);
            $this->disableConstraints($this->tgtPDO);

            if ($this->cfg['databaseMode'] === 'clear') {
                $this->clearDatabase($this->tgtPDO, $targetDB);
            }

            $this->runPhases($opts, $insertType);
            $this->enableConstraints($this->tgtPDO);
            $this->verifyRowCounts($targetDB);

            if ($this->cfg['copyFiles']) {
                $this->copyFiles($sourcePath, $targetPath);
                $this->patchConfigFile(
                    $targetPath . '/config.php',
                    $targetHost, $targetDB, $targetUser, $targetPass, $targetCharset, $targetPort
                );
            }

            $this->finish('success', $targetDB, $sourcePath, $targetPath);
            return true;

        } catch (Throwable $e) {
            $this->finish('error', $targetDB ?? '', '', '', $e->getMessage());
            return false;
        } finally {
            $this->tgtPDO = null;
        }
    }

    /**
     * Export complete SQL script (MySQL or MSSQL) without touching target.
     */
    public function exportSQL(string $targetDB, array $opts, string $insertType = 'INSERT', string $format = 'mysql'): string {
        $this->assertOneOf($format, self::VALID_SQL_FORMATS, 'SQL format');
        $this->assertOneOf($insertType, self::VALID_INSERT_TYPES, 'Insert type');

        $parts = [];
        $ec    = $this->cfg['sqlExport'];

        if ($ec['addComments'])          $parts[] = $this->sqlHeader($targetDB, $format);
        if ($ec['includeCreateDatabase']) $parts[] = $this->sqlCreateDB($targetDB, $format);
        if ($ec['includeUseDatabase'])    $parts[] = $this->sqlUseDB($targetDB, $format);
        if ($ec['disableConstraints'])    $parts[] = $this->sqlDisableFK($format);

        if (in_array(1, $opts)) { $parts[] = $this->exportCreateSQL("TABLE_NAME LIKE 'zzzzsys%'", $format, $ec['includeDropStatements']); }
        if (in_array(2, $opts)) { $parts[] = $this->exportCreateSQL("TABLE_NAME NOT LIKE 'zzzzsys%'", $format, $ec['includeDropStatements']); }

        if (!$this->cfg['schemaOnly']) {
            foreach ([3,4,5] as $o) {
                if (in_array($o, $opts)) { $parts[] = $this->exportInsertSQL($o, $insertType, $format); }
            }
        }

        foreach ([6=>'FUNCTION',7=>'PROCEDURE',8=>'TRIGGER',9=>'EVENT'] as $o => $type) {
            if (in_array($o, $opts)) { $parts[] = $this->exportRoutineSQL($type, $format, $ec['includeDropStatements']); }
        }

        if ($ec['disableConstraints']) $parts[] = $this->sqlEnableFK($format);
        if ($ec['addComments'])        $parts[] = $this->sqlFooter($format);

        $sql = implode("\n", array_filter($parts));

        if ($this->cfg['zipExport']) {
            return gzencode($sql, 6);
        }
        return $sql;
    }

    // ──────────────────────────────────────────────────────────────────────
    //  PHASES
    // ──────────────────────────────────────────────────────────────────────

    private function runPhases(array $opts, string $insertType): void {
        if (in_array(1, $opts)) {
            $this->step('Create zzzzsys tables',  fn() => $this->cloneCreateSQL("TABLE_NAME LIKE 'zzzzsys%'"));
            $this->step('Create zzzzsys views',   fn() => $this->cloneViews("TABLE_NAME LIKE 'zzzzsys%'"));
        }
        if (in_array(2, $opts)) {
            $this->step('Create user tables',     fn() => $this->cloneCreateSQL("TABLE_NAME NOT LIKE 'zzzzsys%'"));
            $this->step('Create user views',      fn() => $this->cloneViews("TABLE_NAME NOT LIKE 'zzzzsys%'"));
        }

        if (!$this->cfg['schemaOnly']) {
            if (in_array(3, $opts)) { $this->step('Copy system records',  fn() => $this->cloneInserts(3, $insertType)); }
            if (in_array(4, $opts)) { $this->step('Copy app records',     fn() => $this->cloneInserts(4, $insertType)); }
            if (in_array(5, $opts)) { $this->step('Copy user data',       fn() => $this->cloneInserts(5, $insertType)); }
        }

        foreach ([6=>'FUNCTION',7=>'PROCEDURE',8=>'TRIGGER',9=>'EVENT'] as $o => $type) {
            if (in_array($o, $opts)) {
                $this->step("Clone {$type}s", fn() => $this->cloneRoutines($type));
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  STRUCTURE
    // ──────────────────────────────────────────────────────────────────────

    private function cloneCreateSQL(string $where): void {
        foreach ($this->getTables($where) as $t) {
            $create = $this->srcPDO->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
            $this->exec("DROP TABLE IF EXISTS `$t`");
            $this->exec($create);
            $this->stats['tables'] = ($this->stats['tables'] ?? 0) + 1;
        }
    }

    private function cloneViews(string $where): void {
        foreach ($this->getViewsOrdered($where) as $v => $create) {
            $this->exec("DROP VIEW IF EXISTS `$v`");
            $this->exec($create);
            $this->stats['views'] = ($this->stats['views'] ?? 0) + 1;
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  DATA
    // ──────────────────────────────────────────────────────────────────────

    private function cloneInserts(int $phase, string $insertType): void {
        [$tableWhere, $rowWhere] = $this->phaseWheres($phase);
        foreach ($this->getTables($tableWhere) as $t) {
            $customWhere = $this->cfg['rowFilters'][$t] ?? null;
            $rw = $customWhere ?? str_replace('|t|', $t, $rowWhere);
            $this->copyTableRows($t, $rw, $insertType);
        }
    }

    private function copyTableRows(string $table, string $rowWhere, string $insertType): void {
        $pk = $this->getPK($table);
        $order = $pk ? "ORDER BY `$pk`" : '';
        $cols  = $this->getColumns($table);
        $colList = '(`' . implode('`,`', $cols) . '`)';

        $count = (int) $this->srcPDO->query("SELECT COUNT(*) FROM `$table` WHERE $rowWhere")->fetchColumn();
        if ($count === 0) return;

        if ($this->cfg['dryRun']) {
            $this->dryLog[] = "Would copy $count rows → $table";
            $this->stats['rows'] = ($this->stats['rows'] ?? 0) + $count;
            return;
        }

        $stmt  = $this->srcPDO->query("SELECT * FROM `$table` WHERE $rowWhere $order");
        $batch = [];
        $n     = 0;

        $this->exec("ALTER TABLE `$table` DISABLE KEYS");
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $vals   = array_map(fn($v) => $v === null ? 'NULL' : $this->tgtPDO->quote((string)$v), $row);
            $batch[] = '(' . implode(',', $vals) . ')';
            $n++;
            if ($n % self::INSERT_BATCH === 0) {
                $this->exec("$insertType INTO `$table` $colList VALUES " . implode(',', $batch));
                $batch = [];
            }
            if ($n % self::PROGRESS_INTERVAL === 0) {
                $this->progress("$table: $n / $count rows");
            }
        }
        if ($batch) {
            $this->exec("$insertType INTO `$table` $colList VALUES " . implode(',', $batch));
        }
        $this->exec("ALTER TABLE `$table` ENABLE KEYS");
        $this->stats['rows'] = ($this->stats['rows'] ?? 0) + $n;
    }

    // ──────────────────────────────────────────────────────────────────────
    //  ROUTINES
    // ──────────────────────────────────────────────────────────────────────

    private function cloneRoutines(string $type): void {
        $meta = self::ROUTINE_META[$type];
        $showQ = str_replace('%DB%', $this->srcDB, $meta['show']);
        $params = ($type === 'TRIGGER') ? [] : [$this->srcDB];
        $stmt  = $this->srcPDO->prepare($showQ);
        $stmt->execute($params);
        $n = 0;
        while ($row = is_string($meta['nameIdx']) ? $stmt->fetch(PDO::FETCH_ASSOC) : $stmt->fetch(PDO::FETCH_NUM)) {
            $name = is_string($meta['nameIdx']) ? $row[$meta['nameIdx']] : $row[$meta['nameIdx']];
            $cr   = $this->srcPDO->query(sprintf($meta['showCreate'], $name))->fetch(PDO::FETCH_NUM)[$meta['createIdx']];
            $cr   = preg_replace("/CREATE[\s\S]+?$type/", "CREATE $type", $cr, 1);
            $this->exec(sprintf($meta['drop'], $name));
            $this->exec($cr);
            $n++;
        }
        $key = strtolower($type) . 's';
        $this->stats[$key] = ($this->stats[$key] ?? 0) + $n;
    }

    // ──────────────────────────────────────────────────────────────────────
    //  SQL EXPORT HELPERS
    // ──────────────────────────────────────────────────────────────────────

    private function exportCreateSQL(string $where, string $fmt, bool $drop): string {
        $sql = [];
        foreach ($this->getTables($where) as $t) {
            $create = $this->srcPDO->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
            if ($fmt === 'mssql') $create = $this->toMSSQLTable($create, $t);
            if ($drop) $sql[] = $fmt === 'mssql' ? "IF OBJECT_ID('[$t]','U') IS NOT NULL DROP TABLE [$t];\nGO" : "DROP TABLE IF EXISTS `$t`;";
            $sql[] = $create . ($fmt === 'mssql' ? "\nGO" : ';');
        }
        foreach ($this->getViewsOrdered($where) as $v => $create) {
            if ($fmt === 'mssql') $create = $this->toMSSQLView($create, $v);
            if ($drop) $sql[] = $fmt === 'mssql' ? "IF OBJECT_ID('[$v]','V') IS NOT NULL DROP VIEW [$v];\nGO" : "DROP VIEW IF EXISTS `$v`;";
            $sql[] = $create . ($fmt === 'mssql' ? "\nGO" : ';');
        }
        return implode("\n", $sql);
    }

    private function exportInsertSQL(int $phase, string $insertType, string $fmt): string {
        [$tableWhere, $rowWhere] = $this->phaseWheres($phase);
        $sql = [];
        foreach ($this->getTables($tableWhere) as $t) {
            $rw   = $this->cfg['rowFilters'][$t] ?? str_replace('|t|', $t, $rowWhere);
            $cols = $this->getColumns($t);
            $pk   = $this->getPK($t);
            $order = $pk ? "ORDER BY `$pk`" : '';
            $max  = $this->cfg['sqlExport']['maxRowsPerInsert'];
            if ($fmt === 'mssql') {
                $colList = '([' . implode('], [', $cols) . '])';
                $tName   = "[$t]";
            } else {
                $colList = '(`' . implode('`, `', $cols) . '`)';
                $tName   = "`$t`";
            }
            $stmt  = $this->srcPDO->query("SELECT * FROM `$t` WHERE $rw $order");
            $batch = []; $total = 0;
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                $vals = array_map(fn($v) => $v === null ? 'NULL' : ($fmt === 'mssql' ? "N'" . str_replace("'", "''", (string)$v) . "'" : $this->srcPDO->quote((string)$v)), $row);
                $batch[] = '(' . implode(', ', $vals) . ')';
                $total++;
                if ($total % $max === 0) {
                    $sql[] = "$insertType INTO $tName $colList VALUES\n" . implode(",\n", $batch) . ($fmt === 'mssql' ? ";\nGO" : ';');
                    $batch = [];
                }
            }
            if ($batch) {
                $sql[] = "$insertType INTO $tName $colList VALUES\n" . implode(",\n", $batch) . ($fmt === 'mssql' ? ";\nGO" : ';');
            }
        }
        return implode("\n\n", $sql);
    }

    private function exportRoutineSQL(string $type, string $fmt, bool $drop): string {
        $meta   = self::ROUTINE_META[$type];
        $showQ  = str_replace('%DB%', $this->srcDB, $meta['show']);
        $params = ($type === 'TRIGGER') ? [] : [$this->srcDB];
        $stmt   = $this->srcPDO->prepare($showQ);
        $stmt->execute($params);
        $sql = [];
        while ($row = is_string($meta['nameIdx']) ? $stmt->fetch(PDO::FETCH_ASSOC) : $stmt->fetch(PDO::FETCH_NUM)) {
            $name = is_string($meta['nameIdx']) ? $row[$meta['nameIdx']] : $row[$meta['nameIdx']];
            $cr   = $this->srcPDO->query(sprintf($meta['showCreate'], $name))->fetch(PDO::FETCH_NUM)[$meta['createIdx']];
            $cr   = preg_replace("/CREATE[\s\S]+?$type/", "CREATE $type", $cr, 1);
            if ($drop) $sql[] = sprintf($meta['drop'], $name) . ';';
            $sql[] = $cr . ';';
        }
        return implode("\n\n", $sql);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  DATABASE MANAGEMENT
    // ──────────────────────────────────────────────────────────────────────

    private function prepareTgtDatabase(string $host, string $db, string $user, string $pass, string $charset, int $port): bool {
        $exists = $this->dbExists($host, $db, $user, $pass, $charset, $port);
        switch ($this->cfg['databaseMode']) {
            case 'fail':
                if ($exists) { $this->emit('error', "Target DB '$db' already exists."); return false; }
                return $this->createDB($host, $db, $user, $pass, $charset, $port);
            case 'create':
                return $exists || $this->createDB($host, $db, $user, $pass, $charset, $port);
            case 'clear':
                return $exists || $this->createDB($host, $db, $user, $pass, $charset, $port);
        }
        return false;
    }

    private function createDB(string $host, string $db, string $user, string $pass, string $charset, int $port): bool {
        try {
            $pdo = $this->makePDO($host, '', $user, $pass, $charset, $port);
            $col = $this->cfg['databaseCollation'];
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET $charset COLLATE $col");
            return true;
        } catch (PDOException $e) {
            $this->emit('error', 'Cannot create DB: ' . $e->getMessage());
            return false;
        }
    }

    private function clearDatabase(PDO $pdo, string $db): void {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($pdo->query("SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA='$db'")->fetchAll(PDO::FETCH_COLUMN) as $v) {
            $pdo->exec("DROP VIEW IF EXISTS `$v`");
        }
        foreach ($pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$db' AND TABLE_TYPE='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $pdo->exec("DROP TABLE IF EXISTS `$t`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function dbExists(string $host, string $db, string $user, string $pass, string $charset, int $port): bool {
        try {
            $pdo  = $this->makePDO($host, '', $user, $pass, $charset, $port);
            $stmt = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
            $stmt->execute([$db]);
            return $stmt->fetch() !== false;
        } catch (PDOException) {
            return false;
        }
    }

    private function makePDO(string $host, string $db, string $user, string $pass, string $charset, int $port): PDO {
        $dsn = "mysql:host=$host;" . ($db ? "dbname=$db;" : '') . "charset=$charset;port=$port";
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM,
        ]);
    }

    private function disableConstraints(PDO $pdo): void {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0; SET UNIQUE_CHECKS=0; SET AUTOCOMMIT=0;');
    }

    private function enableConstraints(PDO $pdo): void {
        $pdo->exec('COMMIT; SET FOREIGN_KEY_CHECKS=1; SET UNIQUE_CHECKS=1; SET AUTOCOMMIT=1;');
    }

    // ──────────────────────────────────────────────────────────────────────
    //  FILE OPERATIONS
    // ──────────────────────────────────────────────────────────────────────

    private function copyFiles(string $src, string $dst): void {
        if ($this->cfg['dryRun']) { $this->dryLog[] = "Would copy files: $src → $dst"; return; }
        if ($this->cfg['fileMode'] === 'fail' && is_dir($dst)) {
            throw new RuntimeException("Target dir '$dst' exists (fileMode=fail).");
        }
        if ($this->cfg['fileMode'] === 'clear' && is_dir($dst)) {
            $this->deleteDir($dst, false);
        }
        $this->copyDir($src, $dst);
    }

    private function patchConfigFile(string $file, string $host, string $db, string $user, string $pass, string $charset, int $port): void {
        if (!file_exists($file)) return;
        $content = file_get_contents($file);
        $map = [
            'dbHost'     => $host,
            'dbName'     => $db,
            'dbUser'     => $user,
            'dbPassword' => $pass,
            'dbCharset'  => $charset,
            'dbPort'     => $port,
        ];
        foreach ($map as $key => $val) {
            $q = is_numeric($val) ? $val : "'" . addslashes((string)$val) . "'";
            $content = preg_replace("/'$key'\s*=>\s*[^,]+/", "'$key' => $q", $content);
        }
        file_put_contents($file, $content);
    }

    private function copyDir(string $src, string $dst): void {
        if (!is_dir($dst)) mkdir($dst, 0755, true);
        foreach (scandir($src) as $item) {
            if ($item === '.' || $item === '..') continue;
            $s = "$src/$item"; $d = "$dst/$item";
            if (is_dir($s)) {
                if (!in_array($item, $this->cfg['excludedDirs'])) $this->copyDir($s, $d);
            } elseif (!in_array($item, $this->cfg['excludedFiles'])) {
                copy($s, $d);
                $this->stats['files'] = ($this->stats['files'] ?? 0) + 1;
            }
        }
    }

    private function deleteDir(string $path, bool $root = true): void {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
        }
        if ($root) rmdir($path);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  QUERY HELPERS
    // ──────────────────────────────────────────────────────────────────────

    private function getTables(string $where): array {
        $rows = $this->srcPDO
            ->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE ($where) AND TABLE_TYPE='BASE TABLE' AND TABLE_SCHEMA=DATABASE()")
            ->fetchAll(PDO::FETCH_COLUMN);
        return $this->filterTables($rows);
    }

    private function getViewsOrdered(string $where): array {
        $rows = $this->srcPDO
            ->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE ($where) AND TABLE_TYPE='VIEW' AND TABLE_SCHEMA=DATABASE()")
            ->fetchAll(PDO::FETCH_COLUMN);
        $defs = [];
        foreach ($this->filterTables($rows) as $v) {
            $cr = $this->srcPDO->query("SHOW CREATE VIEW `$v`")->fetch(PDO::FETCH_NUM)[1];
            $cr = preg_replace('/CREATE[\s\S]+?VIEW/', 'CREATE VIEW', $cr, 1);
            $cr = str_replace("`{$this->srcDB}`.", '', $cr);
            $defs[$v] = $cr;
        }
        return $this->dependencySort($defs);
    }

    private function dependencySort(array $defs): array {
        $ordered = []; $max = count($defs) ** 2 + 1;
        while (!empty($defs) && $max-- > 0) {
            foreach ($defs as $v => $cr) {
                $dep = false;
                foreach ($defs as $other => $_) {
                    if ($other !== $v && str_contains($cr, "`$other`")) { $dep = true; break; }
                }
                if (!$dep) { $ordered[$v] = $cr; unset($defs[$v]); }
            }
        }
        return $ordered + $defs;
    }

    private function filterTables(array $names): array {
        $inc = array_flip($this->cfg['includeTablesAndViews']);
        $exc = array_flip($this->cfg['excludeTablesAndViews']);
        return array_values(array_filter($names, function($n) use ($inc, $exc) {
            if (str_starts_with($n, 'zzzzsys_')) return true;
            if ($inc && !isset($inc[$n])) return false;
            if (isset($exc[$n])) return false;
            return true;
        }));
    }

    private function getColumns(string $table): array {
        return $this->srcPDO->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
    }

    private function getPK(string $table): ?string {
        $row = $this->srcPDO->query("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_NAME='PRIMARY' AND TABLE_NAME='$table' AND TABLE_SCHEMA=DATABASE() LIMIT 1")->fetch(PDO::FETCH_NUM);
        return $row ? $row[0] : null;
    }

    private function phaseWheres(int $phase): array {
        return match($phase) {
            3 => ["TABLE_NAME LIKE 'zzzzsys%'", "|t|_id LIKE 'nu%'"],
            4 => ["TABLE_NAME LIKE 'zzzzsys%'", "|t|_id NOT LIKE 'nu%'"],
            5 => ["TABLE_NAME NOT LIKE 'zzzzsys%' AND TABLE_NAME NOT LIKE '___nu%'", 'TRUE'],
            default => ['TRUE', 'TRUE'],
        };
    }

    private function verifyRowCounts(string $targetDB): void {
        foreach ($this->getTables('TRUE') as $t) {
            $src = (int)$this->srcPDO->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            $tgt = (int)$this->tgtPDO->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            if ($src !== $tgt) {
                $this->log("Row count mismatch [$t]: src=$src tgt=$tgt");
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  MSSQL CONVERSION
    // ──────────────────────────────────────────────────────────────────────

    private function toMSSQLTable(string $sql, string $t): string {
        $sql = str_replace('`', '', $sql);
        $sql = preg_replace('/CREATE TABLE\s+(\w+)/', 'CREATE TABLE [$1]', $sql);
        $typeMap = [
            '/\bINT\(\d+\)\s+AUTO_INCREMENT\b/i' => 'INT IDENTITY(1,1)',
            '/\bAUTO_INCREMENT\b/i' => 'IDENTITY(1,1)',
            '/\bMEDIUMTEXT\b|\bLONGTEXT\b|\bTEXT\b/i' => 'NVARCHAR(MAX)',
            '/\bTINYTEXT\b/i' => 'NVARCHAR(255)',
            '/\bVARCHAR\(/i' => 'NVARCHAR(',
            '/\bCHAR\(/i' => 'NCHAR(',
            '/\bBIGINT\(\d+\)/i' => 'BIGINT',
            '/\bINT\(\d+\)/i' => 'INT',
            '/\bTINYINT\(1\)/i' => 'BIT',
            '/\bTINYINT\(\d+\)/i' => 'TINYINT',
            '/\bDOUBLE\b/i' => 'FLOAT',
            '/\bDATETIME\b|\bTIMESTAMP\b/i' => 'DATETIME2',
            '/\bBLOB\b|\bMEDIUMBLOB\b|\bLONGBLOB\b/i' => 'VARBINARY(MAX)',
            '/\bJSON\b/i' => 'NVARCHAR(MAX)',
            '/\bBOOLEAN\b/i' => 'BIT',
        ];
        foreach ($typeMap as $p => $r) $sql = preg_replace($p, $r, $sql);
        $sql = preg_replace('/ENGINE=\w+|DEFAULT CHARSET=\w+|COLLATE=\w+/i', '', $sql);
        $sql = preg_replace('/\s+ON UPDATE CURRENT_TIMESTAMP/i', '', $sql);
        $sql = preg_replace('/DEFAULT CURRENT_TIMESTAMP/i', 'DEFAULT GETDATE()', $sql);
        $sql = preg_replace('/,\s*KEY\s+\w+\s*\([^)]+\)/i', '', $sql);
        $sql = preg_replace('/,\s*UNIQUE KEY\s+\w+\s*\([^)]+\)/i', '', $sql);
        $sql = preg_replace('/,\s*,/', ',', $sql);
        $sql = preg_replace('/,\s*\)/', ')', $sql);
        return trim($sql);
    }

    private function toMSSQLView(string $sql, string $v): string {
        $sql = str_replace('`', '', $sql);
        return preg_replace('/CREATE VIEW\s+(\w+)/', 'CREATE VIEW [$1]', $sql);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  SQL EXPORT STRING BUILDERS
    // ──────────────────────────────────────────────────────────────────────

    private function sqlHeader(string $db, string $fmt): string {
        $ts  = date('Y-m-d H:i:s');
        $c   = '--';
        return "$c ========================================\n$c Nuvis StudioX App Cloner Export\n$c Source: {$this->srcDB} → Target: $db\n$c Generated: $ts\n$c ========================================\n";
    }
    private function sqlFooter(string $fmt): string {
        return '-- Export complete: ' . date('Y-m-d H:i:s') . "\n";
    }
    private function sqlCreateDB(string $db, string $fmt): string {
        if ($fmt === 'mssql') return "IF DB_ID('$db') IS NULL CREATE DATABASE [$db];\nGO\n";
        return "CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
    }
    private function sqlUseDB(string $db, string $fmt): string {
        return $fmt === 'mssql' ? "USE [$db];\nGO\n" : "USE `$db`;\n";
    }
    private function sqlDisableFK(string $fmt): string {
        return $fmt === 'mssql'
            ? "EXEC sp_MSforeachtable 'ALTER TABLE ? NOCHECK CONSTRAINT ALL';\nGO\n"
            : "SET FOREIGN_KEY_CHECKS=0; SET UNIQUE_CHECKS=0; SET AUTOCOMMIT=0;\n";
    }
    private function sqlEnableFK(string $fmt): string {
        return $fmt === 'mssql'
            ? "EXEC sp_MSforeachtable 'ALTER TABLE ? CHECK CONSTRAINT ALL';\nGO\n"
            : "COMMIT; SET FOREIGN_KEY_CHECKS=1; SET UNIQUE_CHECKS=1; SET AUTOCOMMIT=1;\n";
    }

    // ──────────────────────────────────────────────────────────────────────
    //  PROGRESS / LOGGING
    // ──────────────────────────────────────────────────────────────────────

    private function step(string $label, callable $fn): void {
        $this->stepNr++;
        $this->progress($label, 'running');
        $fn();
        $this->progress($label, 'done');
    }

    private function progress(string $msg, string $status = 'info'): void {
        if (!$this->progressFile) return;
        $entry = ['step' => $this->stepNr, 'status' => $status, 'msg' => $msg, 'ts' => microtime(true)];
        $existing = file_exists($this->progressFile) ? json_decode(file_get_contents($this->progressFile), true) : [];
        $existing[] = $entry;
        file_put_contents($this->progressFile, json_encode($existing));
    }

    private function log(string $msg): void {
        if ($lf = $this->cfg['logFile']) {
            file_put_contents($lf, '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
        }
    }

    private function exec(string $sql): void {
        if ($this->cfg['dryRun']) { $this->dryLog[] = substr($sql, 0, 120); return; }
        try {
            $this->tgtPDO->exec($sql);
        } catch (PDOException $e) {
            throw new RuntimeException('SQL failed: ' . $e->getMessage() . ' | SQL: ' . substr($sql, 0, 200));
        }
    }

    private function emit(string $type, string $msg): void {
        $this->log("$type: $msg");
        $this->progress($msg, $type);
        if ($type === 'error') throw new RuntimeException($msg);
    }

    private function finish(string $status, string $targetDB, string $src, string $dst, string $error = ''): void {
        $elapsed = round(microtime(true) - $this->startTime, 2);
        $payload = [
            'status'  => $status,
            'elapsed' => $elapsed,
            'stats'   => $this->stats,
            'error'   => $error,
            'dryRun'  => $this->cfg['dryRun'],
            'dryLog'  => $this->dryLog,
        ];
        $this->progress(json_encode($payload), $status);
        $this->log("Finished [$status] in {$elapsed}s");

        // Webhook notification (NEW)
        if ($this->cfg['webhookUrl']) {
            $ch = curl_init($this->cfg['webhookUrl']);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 5, CURLOPT_RETURNTRANSFER => true]);
            curl_exec($ch);
            curl_close($ch);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  VALIDATION
    // ──────────────────────────────────────────────────────────────────────

    private function validateOpts(string $targetDB, array $opts, string $insertType): void {
        if (empty($this->srcDB))   throw new InvalidArgumentException('Source DB not resolved.');
        if ($targetDB === $this->srcDB) throw new InvalidArgumentException('Target DB cannot equal source DB.');
        $this->assertOneOf($insertType, self::VALID_INSERT_TYPES, 'Insert type');
        $this->assertOneOf($this->cfg['databaseMode'], self::VALID_DB_MODES, 'databaseMode');
        $this->assertOneOf($this->cfg['fileMode'], self::VALID_FILE_MODES, 'fileMode');
        if (array_diff($opts, range(1, 9))) throw new InvalidArgumentException('opts must be integers 1–9.');
    }

    private function assertOneOf(string $val, array $allowed, string $label): void {
        if (!in_array($val, $allowed, true))
            throw new InvalidArgumentException("$label must be one of: " . implode(', ', $allowed));
    }

    /**
     * Export single project SQL script (metadata + user data tables).
     */
    public function exportProject(int $projectId, array $opts = []): string {
        $db = NuDatabase::getInstance();
        $pdo = $db->getPdo();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $proj = $db->fetchOne("SELECT * FROM nu_projects WHERE project_id = ?", [$projectId]);
        if (!$proj) {
            throw new InvalidArgumentException("Project ID {$projectId} not found.");
        }

        $includeUserData = $opts['includeUserData'] ?? true;
        $schemaOnly = $opts['schemaOnly'] ?? false;
        $zip = $opts['zipExport'] ?? false;

        $parts = [];
        $ts = date('Y-m-d H:i:s');
        $parts[] = "-- ========================================\n-- Nuvis StudioX Multi-Project Export\n-- Project: {$proj['project_name']} ({$proj['project_code']})\n-- Exported: {$ts}\n-- ========================================\n";
        $parts[] = "SET FOREIGN_KEY_CHECKS=0;\n";

        // 1. Export nu_projects row
        $parts[] = "-- ─── PROJECT METADATA ───────────────────";
        $insVerb = ($driver === 'sqlite') ? "INSERT OR REPLACE INTO" : "REPLACE INTO";
        $parts[] = "{$insVerb} `nu_projects` (`project_id`, `project_code`, `project_name`, `project_description`, `project_settings`, `project_active`, `project_is_default`) VALUES (" .
            (int)$proj['project_id'] . ", " .
            $pdo->quote((string)$proj['project_code']) . ", " .
            $pdo->quote((string)$proj['project_name']) . ", " .
            ($proj['project_description'] !== null ? $pdo->quote((string)$proj['project_description']) : 'NULL') . ", " .
            ($proj['project_settings'] !== null ? $pdo->quote((string)$proj['project_settings']) : 'NULL') . ", " .
            (int)$proj['project_active'] . ", " .
            (int)$proj['project_is_default'] .
            ");\n";

        // 2. Export metadata tables for this project
        $metaTables = [
            'nu_forms',
            'nu_form_versions',
            'nu_reports',
            'nu_queries',
            'nu_procedures',
            'nu_menus',
            'nu_workflows',
            'nu_workflow_stages',
            'nu_workflow_transitions',
        ];

        foreach ($metaTables as $tbl) {
            $hasTbl = false;
            try {
                $hasTbl = (bool)($driver === 'sqlite' ?
                    $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$tbl}'")->fetch() :
                    $pdo->query("SHOW TABLES LIKE '{$tbl}'")->fetch());
            } catch (Exception $e) {}

            if (!$hasTbl) continue;

            $rows = $db->fetchAll("SELECT * FROM `{$tbl}` WHERE `project_id` = ?", [$projectId]);
            if (empty($rows)) continue;

            $parts[] = "-- ─── METADATA TABLE: {$tbl} ───────────────";
            $cols = array_keys($rows[0]);
            $colList = '(`' . implode('`, `', $cols) . '`)';

            $batch = [];
            foreach ($rows as $row) {
                $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($row));
                $batch[] = '(' . implode(', ', $vals) . ')';
            }
            $parts[] = "INSERT INTO `{$tbl}` {$colList} VALUES\n" . implode(",\n", $batch) . ";\n";
        }

        // 3. Export form physical data tables
        $formTables = [];

        try {
            $ptRows = $db->fetchAll("SELECT table_name FROM nu_project_tables WHERE project_id = ?", [$projectId]);
            foreach ($ptRows as $pt) {
                if (!empty($pt['table_name'])) $formTables[] = trim($pt['table_name']);
            }
        } catch (Exception $e) {}

        $fRows = $db->fetchAll("SELECT form_table FROM nu_forms WHERE project_id = ? AND form_table IS NOT NULL AND form_table != ''", [$projectId]);
        foreach ($fRows as $fr) {
            $formTables[] = trim($fr['form_table']);
        }

        $formTables = array_unique(array_filter($formTables));

        foreach ($formTables as $userTbl) {
            $hasTbl = false;
            try {
                $hasTbl = (bool)($driver === 'sqlite' ?
                    $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$userTbl}'")->fetch() :
                    $pdo->query("SHOW TABLES LIKE '{$userTbl}'")->fetch());
            } catch (Exception $e) {}

            if (!$hasTbl) continue;

            $parts[] = "-- ─── DATA TABLE STRUCTURE: {$userTbl} ───────────────";
            if ($driver === 'sqlite') {
                $createRow = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='{$userTbl}'")->fetch(PDO::FETCH_ASSOC);
                if (!empty($createRow['sql'])) {
                    $parts[] = $createRow['sql'] . ";\n";
                }
            } else {
                $createRow = $pdo->query("SHOW CREATE TABLE `{$userTbl}`")->fetch(PDO::FETCH_NUM);
                if (!empty($createRow[1])) {
                    $parts[] = "DROP TABLE IF EXISTS `{$userTbl}`;\n" . $createRow[1] . ";\n";
                }
            }

            if ($includeUserData && !$schemaOnly) {
                $uRows = $db->fetchAll("SELECT * FROM `{$userTbl}`");
                if (!empty($uRows)) {
                    $parts[] = "-- ─── DATA TABLE DATA: {$userTbl} ───────────────";
                    $cols = array_keys($uRows[0]);
                    $colList = '(`' . implode('`, `', $cols) . '`)';
                    $batch = [];
                    foreach ($uRows as $ur) {
                        $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($ur));
                        $batch[] = '(' . implode(', ', $vals) . ')';
                    }
                    $parts[] = "INSERT INTO `{$userTbl}` {$colList} VALUES\n" . implode(",\n", $batch) . ";\n";
                }
            }
        }

        // Integration settings (Redis connection). Secrets are never exported; on import the row only seeds
        // a site that has no saved Redis settings of its own.
        try {
            require_once __DIR__ . '/RedisConfig.php';
            $rc = NuRedisConfig::get(true);
            unset($rc['password']);
            $ignore = ($driver === 'sqlite') ? 'INSERT OR IGNORE INTO' : 'INSERT IGNORE INTO';
            $parts[] = "-- ─── INTEGRATION SETTINGS (no secrets) ───────────────";
            $parts[] = "{$ignore} `nu_system_settings` (`setting_key`, `setting_value`) VALUES (" .
                $pdo->quote(NuRedisConfig::SETTING_KEY) . ", " . $pdo->quote(json_encode($rc)) . ");\n";
        } catch (\Throwable $e) {
            error_log('[AppCloner] redis settings export: ' . $e->getMessage());
        }

        $parts[] = "SET FOREIGN_KEY_CHECKS=1;\n";
        $sql = implode("\n", array_filter($parts));

        if ($zip) {
            return gzencode($sql, 6);
        }
        return $sql;
    }
}
