<?php
declare(strict_types=1);
// Nuvis StudioX - Audit Trail
//
// Every entry records who / what / when / where, a field-level diff for updates, the outcome
// (success|failure) and is sealed into a hash chain so edits or deletions of past entries can be detected
// (see verifyChain()). Secrets are masked before anything is stored.

class NuAudit {
    private $db;
    private static $schemaChecked = false;

    /** Keys whose values are never stored. */
    private const SECRET_PATTERN = '/pass(word|wd)?|pwd|secret|token|api[_-]?key|private[_-]?key|otp|totp|credential|authorization|cvv|card[_-]?(no|num)/i';
    private const MAX_VALUE_LEN = 2000;

    public function __construct() {
        $this->db = NuDatabase::getInstance();
    }

    private function isSqlite(): bool {
        return method_exists($this->db, 'getDriverName') && $this->db->getDriverName() === 'sqlite';
    }

    // ── schema ───────────────────────────────────────────────────────────────
    /** Create / upgrade nu_audit_log. Returns false when the table cannot be used. */
    public function ensureSchema(): bool {
        if (self::$schemaChecked) return true;
        try {
            $sqlite = $this->isSqlite();
            $pdo = $this->db->getPdo();
            if ($sqlite) {
                $exists = $this->db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name='nu_audit_log'");
                if (!$exists) {
                    $pdo->exec("CREATE TABLE nu_audit_log (audit_id INTEGER PRIMARY KEY AUTOINCREMENT, audit_action TEXT NOT NULL, audit_table TEXT NOT NULL DEFAULT '', audit_record_id TEXT, audit_old_data TEXT, audit_new_data TEXT, audit_diff TEXT, audit_user_id TEXT, audit_username TEXT, audit_ip TEXT, audit_user_agent TEXT, audit_timestamp DATETIME DEFAULT CURRENT_TIMESTAMP)");
                }
                $have = array_column($this->db->fetchAll('PRAGMA table_info(nu_audit_log)'), 'name');
            } else {
                $exists = $this->db->fetchOne("SHOW TABLES LIKE 'nu_audit_log'");
                if (!$exists) {
                    $pdo->exec("CREATE TABLE nu_audit_log (
                        audit_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, audit_action VARCHAR(40) NOT NULL, audit_table VARCHAR(128) NOT NULL DEFAULT '',
                        audit_record_id VARCHAR(64) DEFAULT NULL, audit_old_data LONGTEXT DEFAULT NULL, audit_new_data LONGTEXT DEFAULT NULL, audit_diff LONGTEXT DEFAULT NULL,
                        audit_user_id VARCHAR(64) DEFAULT NULL, audit_username VARCHAR(128) DEFAULT NULL, audit_ip VARCHAR(45) DEFAULT NULL, audit_user_agent VARCHAR(255) DEFAULT NULL,
                        audit_timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_action (audit_action), INDEX idx_table (audit_table), INDEX idx_user (audit_user_id), INDEX idx_timestamp (audit_timestamp)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                }
                $cols = $this->db->fetchAll('SHOW COLUMNS FROM nu_audit_log');
                $have = array_column($cols, 'Field');
                // legacy installs: INT record/user ids cannot hold UUIDs/strings; action/table too short; JSON type rejects redacted text
                foreach ($cols as $c) {
                    $f = $c['Field']; $t = strtolower((string)$c['Type']);
                    if (in_array($f, ['audit_record_id', 'audit_user_id'], true) && strpos($t, 'int') === 0) $pdo->exec("ALTER TABLE nu_audit_log MODIFY `{$f}` VARCHAR(64) NULL");
                    if (in_array($f, ['audit_old_data', 'audit_new_data'], true) && $t === 'json') $pdo->exec("ALTER TABLE nu_audit_log MODIFY `{$f}` LONGTEXT NULL");
                    if ($f === 'audit_action' && preg_match('/varchar\((\d+)\)/', $t, $m) && (int)$m[1] < 40) $pdo->exec("ALTER TABLE nu_audit_log MODIFY `audit_action` VARCHAR(40) NOT NULL");
                    if ($f === 'audit_table' && preg_match('/varchar\((\d+)\)/', $t, $m) && (int)$m[1] < 128) $pdo->exec("ALTER TABLE nu_audit_log MODIFY `audit_table` VARCHAR(128) NOT NULL DEFAULT ''");
                }
            }
            $add = [
                'audit_diff'      => $sqlite ? 'TEXT' : 'LONGTEXT NULL',
                'project_id'      => $sqlite ? 'INTEGER' : 'INT NULL',
                'audit_status'    => $sqlite ? "TEXT DEFAULT 'success'" : "VARCHAR(10) NOT NULL DEFAULT 'success'",
                'audit_summary'   => $sqlite ? 'TEXT' : 'VARCHAR(255) NULL',
                'audit_request'   => $sqlite ? 'TEXT' : 'VARCHAR(255) NULL',
                'audit_session'   => $sqlite ? 'TEXT' : 'VARCHAR(16) NULL',
                'audit_prev_hash' => $sqlite ? 'TEXT' : 'CHAR(64) NULL',
                'audit_hash'      => $sqlite ? 'TEXT' : 'CHAR(64) NULL',
            ];
            foreach ($add as $col => $ddl) {
                if (!in_array($col, $have, true)) $pdo->exec("ALTER TABLE nu_audit_log ADD COLUMN `{$col}` {$ddl}");
            }
            self::$schemaChecked = true;
            return true;
        } catch (\Throwable $e) {
            error_log('[NuAudit schema] ' . $e->getMessage());
            return false;
        }
    }

    // ── writing ──────────────────────────────────────────────────────────────
    /**
     * Record an audit event.
     * $recordId accepts string, int, or null (UUID safe). Never cast it to int.
     */
    public function log(string $action, string $table, $recordId = null, ?array $oldData = null, ?array $newData = null): void {
        $this->logChange($action, $table, $recordId, $oldData, $newData);
    }

    /** Event that is not about one record (settings saved, export run, failed login ...). */
    public function event(string $action, string $summary, array $details = [], string $status = 'success', array $extra = []): void {
        $this->logChange($action, (string)($extra['table'] ?? 'system'), $extra['record_id'] ?? null, null, $details ?: null,
            ['summary' => $summary, 'status' => $status] + $extra);
    }

    /**
     * Record an event with before/after snapshots and a field-level diff.
     * $extra: status (success|failure), summary, user_id, username (override the session), project_id.
     */
    public function logChange(string $action, string $table, $recordId = null, ?array $oldData = null, ?array $newData = null, array $extra = []): void {
        try {
            if (!$this->ensureSchema()) return;

            $old = $oldData !== null ? $this->redact($oldData) : null;
            $new = $newData !== null ? $this->redact($newData) : null;
            $diff = null;
            if ($old !== null && $new !== null) {
                $diff = [];
                foreach ($new as $k => $v) {
                    $o = $old[$k] ?? null;
                    $same = ((is_scalar($o) || $o === null) && (is_scalar($v) || $v === null))
                        ? (($o === null && $v === null) || ($o !== null && $v !== null && (string)$o === (string)$v))
                        : ($o == $v);
                    if (!array_key_exists($k, $old) || !$same) $diff[$k] = ['old' => $o, 'new' => $v];
                }
            }

            $pid = $extra['project_id'] ?? null;
            if ($pid === null && class_exists('ProjectContext')) { try { $pid = ProjectContext::getId(); } catch (\Throwable $t) {} }

            $isCli = PHP_SAPI === 'cli';
            $request = $isCli ? 'cli' : mb_substr(($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?'), 0, 255);
            $sid = session_status() === PHP_SESSION_ACTIVE ? substr(hash('sha256', session_id()), 0, 12) : null;

            $status = ($extra['status'] ?? 'success') === 'failure' ? 'failure' : 'success';
            $data = [
                'audit_action'     => mb_substr($action, 0, 40),
                'audit_table'      => mb_substr($table, 0, 128),
                'audit_record_id'  => $recordId === null ? null : mb_substr((string)$recordId, 0, 64),
                'audit_old_data'   => $old !== null ? json_encode($old, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
                'audit_new_data'   => $new !== null ? json_encode($new, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
                'audit_diff'       => $diff ? json_encode($diff, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
                'audit_user_id'    => isset($extra['user_id']) ? (string)$extra['user_id'] : (isset($_SESSION['nu_user_id']) ? (string)$_SESSION['nu_user_id'] : null),
                'audit_username'   => mb_substr((string)($extra['username'] ?? ($_SESSION['nu_username'] ?? 'system')), 0, 128),
                'audit_ip'         => mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45),
                'audit_user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                'audit_timestamp'  => date('Y-m-d H:i:s'),
                'project_id'       => $pid === null ? null : (int)$pid,
                'audit_status'     => $status,
                'audit_summary'    => isset($extra['summary']) ? mb_substr((string)$extra['summary'], 0, 255) : null,
                'audit_request'    => $request,
                'audit_session'    => $sid,
            ];
            $this->insertSealed($data);
        } catch (\Throwable $e) {
            error_log('[NuAudit] ' . $e->getMessage());
        }
    }

    /** Insert with hash-chain sealing; serialised with a DB lock on MySQL so two requests cannot fork the chain. */
    private function insertSealed(array $data): void {
        $locked = false;
        try {
            if (!$this->isSqlite()) {
                $r = $this->db->fetchOne("SELECT GET_LOCK('nu_audit_chain', 3) AS l");
                $locked = $r && (int)$r['l'] === 1;
            }
            $last = $this->db->fetchOne('SELECT audit_hash FROM nu_audit_log WHERE audit_hash IS NOT NULL ORDER BY audit_id DESC LIMIT 1');
            $prev = $last['audit_hash'] ?? str_repeat('0', 64);
            $data['audit_prev_hash'] = $prev;
            $data['audit_hash'] = self::computeHash($prev, $data);
            $this->db->insert('nu_audit_log', $data);
        } finally {
            if ($locked) { try { $this->db->fetchOne("SELECT RELEASE_LOCK('nu_audit_chain') AS r"); } catch (\Throwable $t) {} }
        }
    }

    private static function computeHash(string $prev, array $r): string {
        $parts = [];
        foreach (['audit_action', 'audit_table', 'audit_record_id', 'audit_old_data', 'audit_new_data', 'audit_user_id', 'audit_username', 'audit_ip', 'audit_timestamp', 'audit_status', 'audit_summary'] as $k) {
            $parts[] = (string)($r[$k] ?? '');
        }
        return hash('sha256', $prev . "\x1f" . implode("\x1f", $parts));
    }

    /** Mask secrets and shorten very large values (recursive). */
    public function redact(array $data, int $depth = 0): array {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_string($k) && preg_match(self::SECRET_PATTERN, $k)) { $out[$k] = ($v === null || $v === '') ? $v : '***'; continue; }
            if (is_array($v)) { $out[$k] = $depth > 4 ? '[nested]' : $this->redact($v, $depth + 1); continue; }
            if (is_string($v) && mb_strlen($v) > self::MAX_VALUE_LEN) $v = mb_substr($v, 0, self::MAX_VALUE_LEN) . '… [truncated]';
            $out[$k] = $v;
        }
        return $out;
    }

    // ── reading ──────────────────────────────────────────────────────────────
    /** @return array{0:string,1:array} where-clause and params */
    private function buildWhere(array $f): array {
        $where = ['1=1']; $p = [];
        foreach (['action' => 'audit_action', 'table' => 'audit_table', 'user_id' => 'audit_user_id', 'username' => 'audit_username', 'ip' => 'audit_ip', 'status' => 'audit_status'] as $key => $col) {
            if (isset($f[$key]) && $f[$key] !== '') { $where[] = "{$col} = :f_{$key}"; $p[":f_{$key}"] = (string)$f[$key]; }
        }
        if (!empty($f['project_id'])) { $where[] = 'project_id = :f_project'; $p[':f_project'] = (int)$f['project_id']; }
        if (!empty($f['from'])) { $where[] = 'audit_timestamp >= :f_from'; $p[':f_from'] = $f['from']; }
        if (!empty($f['to']))   { $where[] = 'audit_timestamp <= :f_to';   $p[':f_to']   = $f['to']; }
        if (isset($f['q']) && trim((string)$f['q']) !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string)$f['q'])) . '%';
            $cols = ['audit_action', 'audit_table', 'audit_record_id', 'audit_username', 'audit_ip', 'audit_summary', 'audit_new_data', 'audit_old_data'];
            $or = [];
            foreach ($cols as $i => $c) { $or[] = "{$c} LIKE :q{$i} ESCAPE '\\'"; $p[":q{$i}"] = $like; }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
        return [implode(' AND ', $where), $p];
    }

    public function getLogs(array $filters = [], int $limit = 100, int $offset = 0): array {
        if (!$this->ensureSchema()) return [];
        [$w, $p] = $this->buildWhere($filters);
        return $this->db->fetchAll('SELECT * FROM nu_audit_log WHERE ' . $w . ' ORDER BY audit_id DESC LIMIT ' . max(1, (int)$limit) . ' OFFSET ' . max(0, (int)$offset), $p);
    }

    public function getLogCount(array $filters = []): int {
        if (!$this->ensureSchema()) return 0;
        [$w, $p] = $this->buildWhere($filters);
        $r = $this->db->fetchOne('SELECT COUNT(*) AS count FROM nu_audit_log WHERE ' . $w, $p);
        return (int)($r['count'] ?? 0);
    }

    public function getLog(int $id): ?array {
        if (!$this->ensureSchema()) return null;
        return $this->db->fetchOne('SELECT * FROM nu_audit_log WHERE audit_id = :id', [':id' => $id]) ?: null;
    }

    /** Summary numbers for the header cards. */
    public function stats(array $filters = []): array {
        if (!$this->ensureSchema()) return ['total' => 0, 'failures' => 0, 'users' => 0, 'today' => 0, 'top_actions' => [], 'top_users' => [], 'by_day' => []];
        [$w, $p] = $this->buildWhere($filters);
        $one = fn($sql, $pp = []) => $this->db->fetchOne($sql, $pp);
        $total = (int)($one("SELECT COUNT(*) c FROM nu_audit_log WHERE $w", $p)['c'] ?? 0);
        $fail  = (int)($one("SELECT COUNT(*) c FROM nu_audit_log WHERE $w AND audit_status = 'failure'", $p)['c'] ?? 0);
        $users = (int)($one("SELECT COUNT(DISTINCT audit_username) c FROM nu_audit_log WHERE $w", $p)['c'] ?? 0);
        $today = (int)($one("SELECT COUNT(*) c FROM nu_audit_log WHERE $w AND audit_timestamp >= :t0", $p + [':t0' => date('Y-m-d 00:00:00')])['c'] ?? 0);
        $topA  = $this->db->fetchAll("SELECT audit_action AS label, COUNT(*) AS value FROM nu_audit_log WHERE $w GROUP BY audit_action ORDER BY value DESC LIMIT 6", $p);
        $topU  = $this->db->fetchAll("SELECT audit_username AS label, COUNT(*) AS value FROM nu_audit_log WHERE $w GROUP BY audit_username ORDER BY value DESC LIMIT 6", $p);
        $since = date('Y-m-d 00:00:00', strtotime('-13 days'));
        $days  = $this->db->fetchAll("SELECT SUBSTR(audit_timestamp, 1, 10) AS label, COUNT(*) AS value FROM nu_audit_log WHERE $w AND audit_timestamp >= :d0 GROUP BY SUBSTR(audit_timestamp, 1, 10) ORDER BY label", $p + [':d0' => $since]);
        return ['total' => $total, 'failures' => $fail, 'users' => $users, 'today' => $today, 'top_actions' => $topA, 'top_users' => $topU, 'by_day' => $days];
    }

    /** Values for the filter drop-downs. */
    public function facets(): array {
        if (!$this->ensureSchema()) return ['actions' => [], 'tables' => [], 'users' => []];
        return [
            'actions' => array_column($this->db->fetchAll('SELECT DISTINCT audit_action a FROM nu_audit_log ORDER BY a LIMIT 300'), 'a'),
            'tables'  => array_column($this->db->fetchAll("SELECT DISTINCT audit_table t FROM nu_audit_log WHERE audit_table <> '' ORDER BY t LIMIT 300"), 't'),
            'users'   => array_column($this->db->fetchAll("SELECT DISTINCT audit_username u FROM nu_audit_log WHERE audit_username IS NOT NULL AND audit_username <> '' ORDER BY u LIMIT 300"), 'u'),
        ];
    }

    // ── integrity & retention ───────────────────────────────────────────────
    /**
     * Re-compute the hash chain. Entries written before sealing existed (no hash) are counted as "unsealed" and skipped.
     * @return array{ok:bool,checked:int,unsealed:int,first_bad_id:?int,reason:string}
     */
    public function verifyChain(): array {
        $res = ['ok' => true, 'checked' => 0, 'unsealed' => 0, 'first_bad_id' => null, 'reason' => ''];
        if (!$this->ensureSchema()) return $res;
        $prev = null; $lastId = 0;
        while (true) {
            $rows = $this->db->fetchAll('SELECT * FROM nu_audit_log WHERE audit_id > :id ORDER BY audit_id ASC LIMIT 500', [':id' => $lastId]);
            if (!$rows) break;
            foreach ($rows as $r) {
                $lastId = (int)$r['audit_id'];
                if (empty($r['audit_hash'])) { $res['unsealed']++; $prev = null; continue; }
                if ($prev !== null && $r['audit_prev_hash'] !== $prev) {
                    return $res = ['ok' => false, 'checked' => $res['checked'], 'unsealed' => $res['unsealed'], 'first_bad_id' => $lastId, 'reason' => 'An earlier entry was removed or the chain was altered before this entry.'];
                }
                $calc = self::computeHash((string)$r['audit_prev_hash'], $r);
                if (!hash_equals($calc, (string)$r['audit_hash'])) {
                    return $res = ['ok' => false, 'checked' => $res['checked'], 'unsealed' => $res['unsealed'], 'first_bad_id' => $lastId, 'reason' => 'This entry was modified after it was written.'];
                }
                $prev = (string)$r['audit_hash'];
                $res['checked']++;
            }
        }
        return $res;
    }

    /** Delete entries older than $days (oldest first, so the remaining chain stays contiguous). Itself audited. */
    public function purgeOlderThan(int $days): int {
        if ($days < 30 || !$this->ensureSchema()) return 0;
        $cut = date('Y-m-d H:i:s', time() - $days * 86400);
        $n = $this->db->delete('nu_audit_log', 'audit_timestamp < :c', [':c' => $cut]);
        $this->event('audit_purge', "Removed {$n} audit entries older than {$days} days", ['older_than_days' => $days, 'removed' => $n]);
        return (int)$n;
    }
}
