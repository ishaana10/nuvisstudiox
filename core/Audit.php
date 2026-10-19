<?php
declare(strict_types=1);
// Nuvis StudioX - Audit Trail

class NuAudit {
    private $db;

    public function __construct() {
        $this->db = NuDatabase::getInstance();
    }

    /**
     * Record an audit event.
     *
     * $recordId accepts string, int, or null to support UUID and auto_increment PKs.
     * Never cast to (int) before passing — doing so will silently zero a UUID string.
     *
     * @param string           $action   e.g. 'login', 'create', 'update', 'delete'
     * @param string           $table    e.g. 'nu_users'
     * @param mixed            $recordId PK of the affected row (string|int|null)
     * @param array|null       $oldData  Snapshot before change
     * @param array|null       $newData  Snapshot after change
     */
    public function log(
        string $action,
        string $table,
        $recordId = null,
        ?array $oldData = null,
        ?array $newData = null
    ): void {
        $this->logChange($action, $table, $recordId, $oldData, $newData);
    }

    /**
     * Record an audit event with before/after snapshots, field-level diff calculation, and extra metadata.
     */
    public function logChange(
        string $action,
        string $table,
        $recordId = null,
        ?array $oldData = null,
        ?array $newData = null,
        array $extra = []
    ): void {
        try {
            $driver = method_exists($this->db, 'getDriverName') ? $this->db->getDriverName() : 'mysql';
            if ($driver === 'sqlite') {
                $check = $this->db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name='nu_audit_log'");
            } else {
                $check = $this->db->fetchOne("SHOW TABLES LIKE 'nu_audit_log'");
            }
            if (!$check) return;

            $diff = null;
            if ($oldData !== null && $newData !== null) {
                $diff = [];
                foreach ($newData as $k => $v) {
                    if (!array_key_exists($k, $oldData) || $oldData[$k] !== $v) {
                        $diff[$k] = [
                            'old' => $oldData[$k] ?? null,
                            'new' => $v
                        ];
                    }
                }
            }

            $userId    = $_SESSION['nu_user_id'] ?? null;
            $username  = $_SESSION['nu_username'] ?? 'system';
            $ip        = $_SERVER['REMOTE_ADDR'] ?? 'cli';
            $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

            $data = [
                'audit_action'     => $action,
                'audit_table'      => $table,
                'audit_record_id'  => $recordId,
                'audit_old_data'   => $oldData ? json_encode($oldData) : null,
                'audit_new_data'   => $newData ? json_encode($newData) : null,
                'audit_diff'       => $diff ? json_encode($diff) : null,
                'audit_user_id'    => $userId,
                'audit_username'   => $username,
                'audit_ip'         => $ip,
                'audit_user_agent' => $userAgent,
                'audit_timestamp'  => date('Y-m-d H:i:s'),
            ];

            // If audit_diff column is missing in legacy schema, remove it before insertion
            try {
                $hasDiffCol = false;
                if ($driver === 'sqlite') {
                    $cols = $this->db->fetchAll("PRAGMA table_info(nu_audit_log)");
                    foreach ($cols as $c) {
                        if (($c['name'] ?? '') === 'audit_diff') { $hasDiffCol = true; break; }
                    }
                } else {
                    $hasDiffCol = (bool)$this->db->fetchOne("SHOW COLUMNS FROM nu_audit_log LIKE 'audit_diff'");
                }
                if (!$hasDiffCol) {
                    unset($data['audit_diff']);
                }
            } catch (\Throwable $t) {
                unset($data['audit_diff']);
            }

            $this->db->insert('nu_audit_log', $data);
        } catch (Throwable $e) {
            error_log('[NuAudit] ' . $e->getMessage());
        }
    }

    /**
     * Retrieve audit log entries with optional filters.
     *
     * Filters:
     *   action   string       — exact match on audit_action
     *   table    string       — exact match on audit_table
     *   user_id  string|int   — exact match on audit_user_id; pass as-is (no (int) cast)
     *   from     string       — ISO 8601 / MySQL datetime lower bound
     *   to       string       — ISO 8601 / MySQL datetime upper bound
     *
     * Do NOT cast filters['user_id'] to int before calling — in UUID mode
     * the value is a VARCHAR(36) string and an int cast will produce 0.
     *
     * LIMIT/OFFSET are interpolated directly (after int cast) rather than
     * bound as named params — PDO with EMULATE_PREPARES sends named params as
     * quoted strings, causing MySQL "LIMIT '25'" syntax errors.
     */
    public function getLogs(array $filters = [], int $limit = 100, int $offset = 0): array {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['action']))  { $where[] = 'audit_action = :action';   $params[':action']  = $filters['action']; }
        if (!empty($filters['table']))   { $where[] = 'audit_table = :table';     $params[':table']   = $filters['table'];  }
        if (!empty($filters['user_id'])) { $where[] = 'audit_user_id = :user_id'; $params[':user_id'] = $filters['user_id']; }
        if (!empty($filters['from']))    { $where[] = 'audit_timestamp >= :from'; $params[':from']    = $filters['from'];   }
        if (!empty($filters['to']))      { $where[] = 'audit_timestamp <= :to';   $params[':to']      = $filters['to'];     }

        // Cast to int and interpolate — avoids LIMIT/OFFSET named-param binding issues
        $safeLimit  = (int) $limit;
        $safeOffset = (int) $offset;

        $sql = 'SELECT * FROM nu_audit_log WHERE ' . implode(' AND ', $where)
             . ' ORDER BY audit_timestamp DESC'
             . ' LIMIT ' . $safeLimit . ' OFFSET ' . $safeOffset;

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Count audit log entries matching optional filters.
     * Same filter rules as getLogs() — do not cast user_id to int.
     */
    public function getLogCount(array $filters = []): int {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['action']))  { $where[] = 'audit_action = :action';   $params[':action']  = $filters['action']; }
        if (!empty($filters['table']))   { $where[] = 'audit_table = :table';     $params[':table']   = $filters['table'];  }
        if (!empty($filters['user_id'])) { $where[] = 'audit_user_id = :user_id'; $params[':user_id'] = $filters['user_id']; }

        $sql    = 'SELECT COUNT(*) as count FROM nu_audit_log WHERE ' . implode(' AND ', $where);
        $result = $this->db->fetchOne($sql, $params);
        return (int)($result['count'] ?? 0);
    }
}
