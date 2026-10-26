<?php
declare(strict_types=1);
require_once __DIR__ . '/WorkflowRules.php';

/**
 * Workflow action runner.
 *
 * An action list is stored as JSON (transition `wft_hook`, stage `wfs_on_enter`). Every action has:
 *   type      update_record | create_record | send_email | notify_user | http_request | call_webhook
 *             | run_procedure | call_agent
 *   label     optional free text shown in logs
 *   when      optional condition (structured or expression) gating the whole action
 *   enabled   false to switch an action off without deleting it
 *   on_error  "continue" (default) or "stop" the remaining actions
 *
 * update_record  — change fields on ANY table:
 *   target   {mode:"linked"}                                   the record this workflow instance is attached to
 *            {mode:"by_id",  table, id:"{{record.cust_id}}", id_field?}   one specific row
 *            {mode:"by_match", table, match:{match,rules}, limit?}        every row matching field rules
 *   set      [{field, mode, value}]   mode: value | record | target | stage | actor | now | today
 *                                          | increment | decrement | append | calc | null
 *   scenarios [{label, when, set}]    first (or all) matching scenario's `set` is applied;
 *   else_set  [...]                   applied when no scenario matches. `when` may use target.<column>.
 * create_record — {table, data:[{field,mode,value}]}
 *
 * Legacy shapes ({"action":"update_record","field":..,"value":..}, bare "send_email", …) still work.
 */
class NuWfActions
{
    public const TYPES = ['update_record', 'create_record', 'send_email', 'notify_user', 'http_request', 'call_webhook', 'run_procedure', 'call_agent'];
    private const MAX_ROWS = 1000;

    private NuDatabase $db;
    private array $colCache = [];
    private array $pkCache  = [];

    public function __construct(NuDatabase $db)
    {
        $this->db = $db;
    }

    // ── normalisation ───────────────────────────────────────────────────────

    /** Turn whatever is stored in wft_hook / wfs_on_enter into a clean list of action arrays. */
    public static function normalize($raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) return [];
        $list = [];
        if (is_string($raw)) {
            $t = trim($raw);
            if ($t === '') return [];
            if ($t[0] === '{' || $t[0] === '[') {
                $d = json_decode($t, true);
                if (!is_array($d)) return [];
                $raw = $d;
            } else {
                $raw = ['action' => $t];             // legacy bare action name
            }
        }
        if (isset($raw['actions']) && is_array($raw['actions'])) $raw = $raw['actions'];
        if (isset($raw['type']) || isset($raw['action'])) $raw = [$raw];
        foreach ($raw as $a) {
            if (!is_array($a)) continue;
            $type = (string)($a['type'] ?? $a['action'] ?? '');
            if ($type === '') continue;
            $a['type'] = $type;
            unset($a['action']);
            if ($type === 'update_record') $a = self::normalizeUpdate($a);
            if ($type === 'create_record') $a['data'] = self::setList($a['data'] ?? []);
            $list[] = $a;
        }
        return $list;
    }

    private static function setList($data): array
    {
        if (!is_array($data)) return [];
        $out = [];
        $isList = array_keys($data) === range(0, count($data) - 1);
        if ($isList) {
            foreach ($data as $s) {
                if (is_array($s) && !empty($s['field'])) $out[] = ['field' => (string)$s['field'], 'mode' => (string)($s['mode'] ?? 'value'), 'value' => $s['value'] ?? ''];
            }
        } else {
            foreach ($data as $k => $v) $out[] = ['field' => (string)$k, 'mode' => 'value', 'value' => is_scalar($v) || $v === null ? (string)$v : json_encode($v)];
        }
        return $out;
    }

    private static function normalizeUpdate(array $a): array
    {
        if (empty($a['target']) || !is_array($a['target'])) {
            $table = $a['table'] ?? null; $rid = $a['record_id'] ?? null;
            if ($table && $rid) $a['target'] = ['mode' => 'by_id', 'table' => $table, 'id' => (string)$rid];
            elseif ($table)     $a['target'] = ['mode' => 'linked', 'table' => $table];
            else                $a['target'] = ['mode' => 'linked'];
        }
        if (!isset($a['set'])) {
            if (!empty($a['data']) && is_array($a['data'])) {
                $a['set'] = self::setList($a['data']);
            } else {
                $field = $a['field'] ?? 'status';
                $a['set'] = [array_key_exists('value', $a) && $a['value'] !== null
                    ? ['field' => $field, 'mode' => 'value', 'value' => $a['value']]
                    : ['field' => $field, 'mode' => 'stage', 'value' => 'code']];
            }
        } else {
            $a['set'] = self::setList($a['set']);
        }
        foreach (($a['scenarios'] ?? []) as $i => $sc) $a['scenarios'][$i]['set'] = self::setList($sc['set'] ?? []);
        if (isset($a['else_set'])) $a['else_set'] = self::setList($a['else_set']);
        return $a;
    }

    // ── schema helpers (portable MySQL / SQLite) ────────────────────────────

    public function isSqlite(): bool
    {
        return strtolower($this->db->getDriverName()) === 'sqlite';
    }

    public static function cleanIdent(string $s): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '', $s) ?? '';
    }

    public function tableExists(string $table): bool
    {
        if ($table === '') return false;
        $pdo = $this->db->getPdo();
        try {
            if ($this->isSqlite()) {
                $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?");
                $st->execute([$table]);
                return (bool)$st->fetchColumn();
            }
            $st = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $st->execute([$table]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Why a table may not be written to by a workflow, or null when allowed. */
    public function tablePolicyError(string $table): ?string
    {
        $clean = self::cleanIdent($table);
        if ($clean === '' || $clean !== $table) return 'Invalid table name';
        if (!$this->tableExists($clean)) return "Table '$clean' does not exist";
        $allowSystem = defined('NU_WORKFLOW_ALLOW_SYSTEM_TABLES') && NU_WORKFLOW_ALLOW_SYSTEM_TABLES;
        if (!$allowSystem && stripos($clean, 'nu_') === 0) {
            return "Table '$clean' is a platform table; workflows can only change your own data tables";
        }
        return null;
    }

    /** @return string[] column names */
    public function columns(string $table): array
    {
        if (isset($this->colCache[$table])) return $this->colCache[$table];
        $cols = [];
        try {
            $pdo = $this->db->getPdo();
            if ($this->isSqlite()) {
                foreach ($pdo->query("PRAGMA table_info(`$table`)")->fetchAll(PDO::FETCH_ASSOC) as $c) $cols[] = $c['name'];
            } else {
                foreach ($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $c) $cols[] = $c['Field'];
            }
        } catch (Throwable $e) { /* leave empty */ }
        return $this->colCache[$table] = $cols;
    }

    public function primaryKey(string $table): ?string
    {
        if (array_key_exists($table, $this->pkCache)) return $this->pkCache[$table];
        $pk = null;
        try {
            $pdo = $this->db->getPdo();
            if ($this->isSqlite()) {
                foreach ($pdo->query("PRAGMA table_info(`$table`)")->fetchAll(PDO::FETCH_ASSOC) as $c) if (!empty($c['pk'])) { $pk = $c['name']; break; }
            } else {
                $r = $pdo->query("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'")->fetch(PDO::FETCH_ASSOC);
                if ($r && !empty($r['Column_name'])) $pk = $r['Column_name'];
            }
        } catch (Throwable $e) { /* fall through */ }
        if (!$pk) { foreach (['id', $table . '_id'] as $guess) if (in_array($guess, $this->columns($table), true)) { $pk = $guess; break; } }
        return $this->pkCache[$table] = $pk;
    }

    // ── running ─────────────────────────────────────────────────────────────

    /**
     * Run a list of actions.
     * @param callable|null $logger fn(array $result, int $index): void
     * @return array[] one result per action: type,label,status(ok|skipped|error|preview),message,affected,changes
     */
    public function run(array $actions, array $ctx, bool $dry = false, ?callable $logger = null): array
    {
        $results = [];
        foreach ($actions as $i => $a) {
            if (array_key_exists('enabled', $a) && !NuWfRules::truthy($a['enabled'])) continue;
            $res = ['type' => $a['type'], 'label' => (string)($a['label'] ?? ''), 'status' => 'ok', 'message' => '', 'affected' => 0, 'changes' => []];
            try {
                $gate = NuWfRules::explain($a['when'] ?? null, $ctx);
                if (!$gate['pass']) {
                    $res['status'] = 'skipped';
                    $res['message'] = $gate['reason'] ?: 'Condition not met';
                } else {
                    $res = array_merge($res, $this->runOne($a, $ctx, $dry));
                }
            } catch (Throwable $e) {
                $res['status'] = 'error';
                $res['message'] = $e->getMessage();
            }
            if ($dry && $res['status'] === 'ok') $res['status'] = 'preview';
            $results[] = $res;
            if ($logger) { try { $logger($res, $i); } catch (Throwable $e) { error_log('[workflow action log] ' . $e->getMessage()); } }
            if ($res['status'] === 'error' && ($a['on_error'] ?? 'continue') === 'stop') break;
        }
        return $results;
    }

    private function runOne(array $a, array $ctx, bool $dry): array
    {
        switch ($a['type']) {
            case 'update_record': return $this->updateRecord($a, $ctx, $dry);
            case 'create_record': return $this->createRecord($a, $ctx, $dry);
            case 'send_email':    return $this->sendEmail($a, $ctx, $dry);
            case 'notify_user':   return $this->notifyUser($a, $ctx, $dry);
            case 'http_request':
            case 'call_webhook':  return $this->httpRequest($a, $ctx, $dry);
            case 'run_procedure': return $this->runProcedure($a, $ctx, $dry);
            case 'call_agent':    return $this->callAgent($a, $ctx, $dry);
        }
        throw new InvalidArgumentException('Unknown action type: ' . $a['type']);
    }

    // ── value computation ───────────────────────────────────────────────────

    /** Compute the value a `set` entry resolves to. $target is the row being changed (or []). */
    private function computeValue(array $s, array $ctx, array $target)
    {
        $mode = (string)($s['mode'] ?? 'value');
        $raw  = $s['value'] ?? '';
        $c    = $ctx; $c['target'] = $target;
        $field = (string)($s['field'] ?? '');
        switch ($mode) {
            case 'null':   return null;
            case 'now':    return date('Y-m-d H:i:s');
            case 'today':  return date('Y-m-d');
            case 'record': return NuWfRules::lookup((string)$raw, $c);
            case 'target': return $target[(string)$raw] ?? null;
            case 'stage':  return ($raw === 'name') ? ($ctx['to_stage'] ?? '') : ($ctx['to_stage_code'] ?? ($ctx['to_stage'] ?? ''));
            case 'actor':  return ($raw === 'id') ? ($ctx['actor']['id'] ?? null) : ($ctx['actor']['name'] ?? '');
            case 'increment':
            case 'decrement':
                $n = NuWfRules::render((string)$raw, $c); $n = is_numeric($n) ? (float)$n : 1.0;
                $cur = is_numeric($target[$field] ?? null) ? (float)$target[$field] : 0.0;
                return self::tidy($mode === 'increment' ? $cur + $n : $cur - $n);
            case 'append':
                return (string)($target[$field] ?? '') . NuWfRules::render((string)$raw, $c);
            case 'calc':
                return self::tidy(NuWfExpr::evaluate((string)$raw, $c));
            case 'value':
            default:
                return is_string($raw) ? NuWfRules::render($raw, $c) : $raw;
        }
    }

    private static function tidy($v)
    {
        if (is_float($v) || is_int($v)) { $r = round((float)$v, 6); return floor($r) == $r && abs($r) < 1e15 ? (int)$r : $r; }
        return $v;
    }

    // ── update_record ───────────────────────────────────────────────────────

    private function updateRecord(array $a, array $ctx, bool $dry): array
    {
        $t     = $a['target'] ?? ['mode' => 'linked'];
        $mode  = (string)($t['mode'] ?? 'linked');
        $table = self::cleanIdent((string)($t['table'] ?? ''));
        if ($mode === 'linked' && $table === '') $table = self::cleanIdent((string)($ctx['instance']['wfi_record_table'] ?? ''));
        if ($table === '') throw new RuntimeException('No target table (this workflow is not linked to a record)');
        if ($err = $this->tablePolicyError($table)) throw new RuntimeException($err);
        $pk = $this->primaryKey($table);
        if (!$pk) throw new RuntimeException("Table '$table' has no primary key to update by");
        $cols = $this->columns($table);

        $rows = $this->findTargets($t, $mode, $table, $pk, $cols, $ctx);
        if (!$rows) return ['status' => 'skipped', 'message' => 'No matching rows in `' . $table . '`', 'affected' => 0];

        $changesOut = []; $affected = 0; $notes = [];
        foreach ($rows as $row) {
            $sets = $this->chooseSets($a, $ctx, $row, $notes);
            if (!$sets) continue;
            $upd = []; $before = []; $after = [];
            foreach ($sets as $s) {
                $f = (string)($s['field'] ?? '');
                if ($f === '') continue;
                if (!in_array($f, $cols, true)) { $notes[] = "column '$f' not found in $table"; continue; }
                if ($f === $pk) { $notes[] = "primary key '$f' cannot be changed"; continue; }
                $c2 = $ctx; $row2 = $row;
                $val = $this->computeValue($s, $c2, $row2);
                $upd[$f] = $val; $before[$f] = $row[$f] ?? null; $after[$f] = $val;
            }
            if (!$upd) continue;
            if (!$dry) {
                $assign = []; $params = [];
                $n = 0;
                foreach ($upd as $f => $v) { $assign[] = "`$f` = :v$n"; $params[":v$n"] = $v; $n++; }
                $params[':pkid'] = $row[$pk];
                $this->db->query("UPDATE `$table` SET " . implode(', ', $assign) . " WHERE `$pk` = :pkid", $params);
            }
            $affected++;
            if (count($changesOut) < 25) $changesOut[] = ['table' => $table, 'id' => $row[$pk], 'before' => $before, 'after' => $after];
        }
        $msg = ($dry ? 'Would update ' : 'Updated ') . $affected . ' row(s) in `' . $table . '`';
        if ($notes) $msg .= ' — ' . implode('; ', array_unique($notes));
        if ($affected === 0) return ['status' => 'skipped', 'message' => 'Nothing to change in `' . $table . '`' . ($notes ? ' — ' . implode('; ', array_unique($notes)) : ''), 'affected' => 0];
        return ['message' => $msg, 'affected' => $affected, 'changes' => $changesOut];
    }

    /** Pick the set-list for this row: first/all matching scenario(s), else `else_set`, else top-level `set`. */
    private function chooseSets(array $a, array $ctx, array $row, array &$notes): array
    {
        $scenarios = $a['scenarios'] ?? [];
        if (!$scenarios) return $a['set'] ?? [];
        $c = $ctx; $c['target'] = $row;
        $mode = ($a['scenario_mode'] ?? 'first') === 'all' ? 'all' : 'first';
        $sets = []; $matched = false;
        foreach ($scenarios as $sc) {
            if (!NuWfRules::passes($sc['when'] ?? null, $c)) continue;
            $matched = true;
            $sets = array_merge($sets, $sc['set'] ?? []);
            if ($mode === 'first') break;
        }
        if ($matched) return $sets;
        return $a['else_set'] ?? [];
    }

    private function findTargets(array $t, string $mode, string $table, string $pk, array $cols, array $ctx): array
    {
        if ($mode === 'linked') {
            $linkedTable = self::cleanIdent((string)($ctx['instance']['wfi_record_table'] ?? ''));
            $rid = $ctx['instance']['wfi_record_id'] ?? null;
            if ($linkedTable === '' || $rid === null || $rid === '') throw new RuntimeException('This workflow instance is not linked to a record');
            if ($table !== $linkedTable) throw new RuntimeException("Linked record is in `$linkedTable`, not `$table`");
            $r = $this->db->fetchOne("SELECT * FROM `$table` WHERE `$pk` = :id", [':id' => $rid]);
            return $r ? [$r] : [];
        }
        if ($mode === 'by_id') {
            $id = NuWfRules::render((string)($t['id'] ?? ''), $ctx);
            if ($id === '') throw new RuntimeException('The record id resolved to empty (check the id field/placeholder)');
            $idField = self::cleanIdent((string)($t['id_field'] ?? '')) ?: $pk;
            if (!in_array($idField, $cols, true)) throw new RuntimeException("Column '$idField' not found in `$table`");
            return $this->db->fetchAll("SELECT * FROM `$table` WHERE `$idField` = :id LIMIT " . self::MAX_ROWS, [':id' => $id]);
        }
        if ($mode === 'by_match') {
            $match = $t['match'] ?? [];
            if (empty($match['rules'])) throw new RuntimeException('Add at least one field rule so the update cannot touch every row');
            $params = []; $n = 0;
            $where = $this->buildWhere($match, $cols, $ctx, $params, $n);
            if ($where === '') throw new RuntimeException('The match rules resolved to nothing; refusing to update every row');
            $limit = max(1, min(self::MAX_ROWS, (int)($t['limit'] ?? 100)));
            return $this->db->fetchAll("SELECT * FROM `$table` WHERE $where LIMIT $limit", $params);
        }
        throw new RuntimeException("Unknown target mode '$mode'");
    }

    private function buildWhere(array $group, array $cols, array $ctx, array &$params, int &$n): string
    {
        $parts = [];
        foreach (($group['rules'] ?? []) as $r) {
            if (isset($r['rules'])) { $sub = $this->buildWhere($r, $cols, $ctx, $params, $n); if ($sub !== '') $parts[] = "($sub)"; continue; }
            $f = (string)($r['field'] ?? '');
            if ($f === '') continue;
            if (!in_array($f, $cols, true)) throw new RuntimeException("Column '$f' not found for matching");
            $op = (string)($r['op'] ?? '=');
            $v  = NuWfRules::render((string)($r['value'] ?? ''), $ctx);
            $v2 = NuWfRules::render((string)($r['value2'] ?? ''), $ctx);
            $like = static fn(string $s): string => addcslashes($s, '%_\\');
            $p = ':m' . (++$n);
            switch ($op) {
                case '=':  $parts[] = "`$f` = $p";  $params[$p] = $v; break;
                case '!=': $parts[] = "(`$f` <> $p OR `$f` IS NULL)"; $params[$p] = $v; break;
                case '>': case '>=': case '<': case '<=': $parts[] = "`$f` $op $p"; $params[$p] = $v; break;
                case 'contains':     $parts[] = "`$f` LIKE $p"; $params[$p] = '%' . $like($v) . '%'; break;
                case 'not_contains': $parts[] = "(`$f` NOT LIKE $p OR `$f` IS NULL)"; $params[$p] = '%' . $like($v) . '%'; break;
                case 'starts_with':  $parts[] = "`$f` LIKE $p"; $params[$p] = $like($v) . '%'; break;
                case 'ends_with':    $parts[] = "`$f` LIKE $p"; $params[$p] = '%' . $like($v); break;
                case 'in': case 'not_in':
                    $items = array_values(array_filter(array_map('trim', explode(',', $v)), static fn($x) => $x !== ''));
                    if (!$items) { $parts[] = $op === 'in' ? '1 = 0' : '1 = 1'; break; }
                    $ph = [];
                    foreach ($items as $it) { $q = ':m' . (++$n); $ph[] = $q; $params[$q] = $it; }
                    $parts[] = "`$f` " . ($op === 'in' ? 'IN' : 'NOT IN') . ' (' . implode(',', $ph) . ')';
                    break;
                case 'between':
                    $q = ':m' . (++$n); $parts[] = "`$f` BETWEEN $p AND $q"; $params[$p] = $v; $params[$q] = $v2; break;
                case 'empty':     $parts[] = "(`$f` IS NULL OR `$f` = '')"; break;
                case 'not_empty': $parts[] = "(`$f` IS NOT NULL AND `$f` <> '')"; break;
                default: throw new RuntimeException("Unsupported match operator '$op'");
            }
        }
        $glue = (($group['match'] ?? 'all') === 'any') ? ' OR ' : ' AND ';
        return implode($glue, $parts);
    }

    // ── create_record ───────────────────────────────────────────────────────

    private function createRecord(array $a, array $ctx, bool $dry): array
    {
        $table = self::cleanIdent((string)($a['table'] ?? ''));
        if ($table === '') throw new RuntimeException('Choose a table to add the record to');
        if ($err = $this->tablePolicyError($table)) throw new RuntimeException($err);
        $cols = $this->columns($table); $pk = $this->primaryKey($table);
        $data = []; $notes = [];
        foreach (($a['data'] ?? []) as $s) {
            $f = (string)($s['field'] ?? '');
            if ($f === '') continue;
            if (!in_array($f, $cols, true)) { $notes[] = "column '$f' not found"; continue; }
            $data[$f] = $this->computeValue($s, $ctx, []);
        }
        if (!$data) throw new RuntimeException('No valid fields to insert' . ($notes ? ' (' . implode('; ', $notes) . ')' : ''));
        $changes = [['table' => $table, 'id' => null, 'before' => [], 'after' => $data]];
        if ($dry) return ['message' => 'Would add 1 row to `' . $table . '`' . ($notes ? ' — ' . implode('; ', $notes) : ''), 'affected' => 1, 'changes' => $changes];
        $id = $this->db->insert($table, $data);
        $changes[0]['id'] = $id;
        return ['message' => 'Added row ' . $id . ' to `' . $table . '`' . ($notes ? ' — ' . implode('; ', $notes) : ''), 'affected' => 1, 'changes' => $changes];
    }

    // ── notifications / integrations ────────────────────────────────────────

    private function sendEmail(array $a, array $ctx, bool $dry): array
    {
        $recipients = [];
        $toRaw = (string)($a['to'] ?? '');
        foreach (preg_split('/[,;]\s*/', NuWfRules::render($toRaw, $ctx)) ?: [] as $cand) {
            $cand = trim($cand);
            if ($cand === '') continue;
            if (filter_var($cand, FILTER_VALIDATE_EMAIL)) { $recipients[] = $cand; continue; }
            $viaField = $ctx['record'][$cand] ?? null;                      // legacy: "to" names a record column
            if ($viaField && filter_var($viaField, FILTER_VALIDATE_EMAIL)) $recipients[] = $viaField;
        }
        if (!$recipients && (!empty($a['to_starter']) || $toRaw === '' || $toRaw === 'started_by')) {
            $r = $this->db->fetchOne('SELECT usr_email FROM nu_users WHERE usr_id = :id', [':id' => (int)($ctx['instance']['wfi_started_by'] ?? 0)]);
            if (!empty($r['usr_email'])) $recipients[] = $r['usr_email'];
        }
        $recipients = array_values(array_unique($recipients));
        if (!$recipients) throw new RuntimeException('No valid recipient email (check the To field)');

        $inst = $ctx['instance'] ?? [];
        $subject = NuWfRules::render((string)($a['subject'] ?? 'Workflow: {{wf_name}} #{{wfi_id}}'), $ctx);
        $default = '<h2>Workflow notification</h2><p><b>{{wf_name}}</b> (#{{wfi_id}}) moved to <b>{{to_stage}}</b> by {{actor_name}}.</p>{{comment}}';
        $body = NuWfRules::render((string)($a['body'] ?? $default), $ctx);
        if ($dry) return ['message' => 'Would email ' . implode(', ', $recipients) . ' — “' . $subject . '”', 'affected' => count($recipients)];

        require_once __DIR__ . '/EmailService.php';
        $cls = class_exists('NuEmailService') ? 'NuEmailService' : (class_exists('EmailService') ? 'EmailService' : null);
        if (!$cls) throw new RuntimeException('Email service is not available');
        $svc = new $cls();
        $sent = 0;
        foreach ($recipients as $to) {
            $ok = method_exists($svc, 'sendEmail') ? $svc->sendEmail($to, $subject, $body) : $svc->send($to, $subject, $body);
            if ($ok !== false) $sent++;
        }
        if ($sent === 0) throw new RuntimeException('Email could not be sent (check email settings)');
        return ['message' => 'Emailed ' . implode(', ', $recipients), 'affected' => $sent];
    }

    private function notifyUser(array $a, array $ctx, bool $dry): array
    {
        $uid = NuWfRules::render((string)($a['user_id'] ?? ''), $ctx);
        if ($uid === '' && !empty($a['user_field'])) $uid = (string)($ctx['record'][$a['user_field']] ?? '');
        if ($uid === '') $uid = (string)($ctx['instance']['wfi_started_by'] ?? '');
        if ($uid === '') throw new RuntimeException('No user to notify');
        $msg = NuWfRules::render((string)($a['message'] ?? $a['content'] ?? 'Workflow {{wf_name}} #{{wfi_id}} moved to {{to_stage}}'), $ctx);
        if ($dry) return ['message' => 'Would notify user #' . $uid . ': ' . $msg, 'affected' => 1];
        if (!$this->tableExists('nu_notifications')) throw new RuntimeException('Notifications table is not installed');
        $this->db->insert('nu_notifications', ['user_id' => $uid, 'message' => $msg, 'status' => 'unread', 'created_at' => date('Y-m-d H:i:s')]);
        return ['message' => 'Notified user #' . $uid, 'affected' => 1];
    }

    private function httpRequest(array $a, array $ctx, bool $dry): array
    {
        $isLegacy = $a['type'] === 'call_webhook';
        $url = NuWfRules::render((string)($a['url'] ?? ''), $ctx);
        if ($url === '' && $isLegacy) $url = rtrim((string)(getenv('NU_BASE_URL') ?: 'http://127.0.0.1'), '/') . '/api/webhook.php';
        elseif ($isLegacy && !empty($a['url'])) $url = rtrim($url, '/') . '/api/webhook.php';
        if (!preg_match('#^https?://#i', $url)) throw new RuntimeException('A valid http(s) URL is required');
        $method = strtoupper((string)($a['method'] ?? 'POST'));
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) $method = 'POST';
        $payload = $a['body'] ?? $a['payload'] ?? ['event' => 'workflow_advance', 'workflow' => $ctx['instance']['wf_name'] ?? '', 'instance_id' => $ctx['instance']['wfi_id'] ?? null,
            'to_stage' => $ctx['to_stage'] ?? '', 'actor_id' => $ctx['actor']['id'] ?? null, 'timestamp' => date('c')];
        $body = is_array($payload) ? json_encode(NuWfRules::renderDeep($payload, $ctx)) : NuWfRules::render((string)$payload, $ctx);
        $headers = $a['headers'] ?? ['Content-Type: application/json'];
        if ($dry) return ['message' => "Would $method $url", 'affected' => 1];
        if (!function_exists('curl_init')) throw new RuntimeException('cURL is not available');
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => (array)$headers]);
        if ($method !== 'GET' && $body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) throw new RuntimeException("$method $url failed: $err");
        if ($code >= 400) throw new RuntimeException("$method $url returned HTTP $code");
        return ['message' => "$method $url → HTTP $code", 'affected' => 1];
    }

    private function runProcedure(array $a, array $ctx, bool $dry): array
    {
        $code = (string)($a['procedure'] ?? $a['code'] ?? '');
        if ($code === '') throw new RuntimeException('Choose a procedure');
        $params = NuWfRules::renderDeep(is_array($a['params'] ?? null) ? $a['params'] : ($a['arguments'] ?? []), $ctx);
        if (!is_array($params)) $params = [];
        if ($dry) return ['message' => "Would run procedure '$code'", 'affected' => 1];
        if (!function_exists('nu_run_procedure')) throw new RuntimeException('Procedures are not available');
        $params['_workflow_instance'] = $ctx['instance'] ?? [];
        $params['_record'] = $ctx['record'] ?? [];
        nu_run_procedure($code, $params);
        return ['message' => "Ran procedure '$code'", 'affected' => 1];
    }

    private function callAgent(array $a, array $ctx, bool $dry): array
    {
        $agentId = $a['agent_id'] ?? null;
        if (!$agentId) throw new RuntimeException('Choose an agent');
        $prompt = NuWfRules::render((string)($a['prompt'] ?? $a['instruction'] ?? 'Process record #{{record.id}} for workflow instance #{{wfi_id}}'), $ctx);
        if ($dry) return ['message' => "Would call agent $agentId", 'affected' => 1];
        if (!class_exists('AgentRuntime') && file_exists(__DIR__ . '/AgentRuntime.php')) require_once __DIR__ . '/AgentRuntime.php';
        if (!class_exists('AgentRuntime')) throw new RuntimeException('Agents are not available');
        $rt = new AgentRuntime($this->db, class_exists('NuAuth') ? NuAuth::getInstance() : null);
        $rt->run($agentId, $prompt, ['workflow_instance_id' => $ctx['instance']['wfi_id'] ?? null, 'record_id' => $ctx['instance']['wfi_record_id'] ?? null,
            'table' => $ctx['instance']['wfi_record_table'] ?? null, 'triggered_by' => 'workflow_transition']);
        return ['message' => "Called agent $agentId", 'affected' => 1];
    }
}
