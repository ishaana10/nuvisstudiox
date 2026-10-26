<?php
declare(strict_types=1);
require_once __DIR__ . '/WorkflowRules.php';
require_once __DIR__ . '/WorkflowActions.php';

/**
 * WorkflowEngine
 * Starts, advances, rejects and cancels workflow instances and runs their automation:
 *
 *  • Transition rules  — condition (visual rules or expression), allowed roles, required comment.
 *                        Enforced here on the server, not just in the builder.
 *  • Actions           — transition `wft_hook` and stage `wfs_on_enter` hold a list of actions
 *                        (see NuWfActions) that can update/create rows in any data table,
 *                        by linked record, by id or by field match, with per-scenario values.
 *  • Auto transitions  — a transition flagged `auto` fires by itself as soon as its condition holds.
 *  • SLA               — a stage with `wfs_sla_hours` is flagged when it overruns; a transition
 *                        flagged `on_sla` fires automatically. See processTimers() (cli/cron.php).
 *  • Execution log     — every action result is stored in nu_workflow_action_log.
 *
 * wft_options JSON: {"auto":bool,"on_sla":bool,"require_comment":bool,"roles":["manager"],"confirm":"Are you sure?"}
 */
class WorkflowEngine
{
    private const ADMIN_ROLES = ['globeadmin', 'admin'];
    private const MAX_AUTO_STEPS = 10;
    private static bool $schemaChecked = false;

    private NuDatabase $db;
    private NuWfActions $actions;

    public function __construct()
    {
        $this->db      = NuDatabase::getInstance();
        $this->actions = new NuWfActions($this->db);
        self::ensureSchema($this->db, $this->actions);
    }

    // ── Schema upgrades (idempotent, safe on old installs) ─────────────────────
    public static function ensureSchema(NuDatabase $db, ?NuWfActions $actions = null): void
    {
        if (self::$schemaChecked) return;
        self::$schemaChecked = true;
        $actions = $actions ?: new NuWfActions($db);
        $sqlite = $actions->isSqlite();
        $add = static function (string $table, string $col, string $ddl) use ($db, $actions): void {
            try {
                if ($actions->tableExists($table) && !in_array($col, $actions->columns($table), true)) {
                    $db->getPdo()->exec("ALTER TABLE `$table` ADD COLUMN `$col` $ddl");
                }
            } catch (Throwable $e) { error_log('[workflow schema] ' . $e->getMessage()); }
        };
        $add('nu_workflow_transitions', 'wft_options', 'TEXT NULL');
        $add('nu_workflow_stages', 'wfs_on_enter', 'TEXT NULL');
        try {
            // Old installs created wft_hook as VARCHAR(64), far too small for action JSON.
            if (!$sqlite && $actions->tableExists('nu_workflow_transitions')) {
                $col = $db->getPdo()->query("SHOW COLUMNS FROM `nu_workflow_transitions` LIKE 'wft_hook'")->fetch(PDO::FETCH_ASSOC);
                if ($col && stripos((string)$col['Type'], 'varchar') !== false) {
                    $db->getPdo()->exec('ALTER TABLE `nu_workflow_transitions` MODIFY `wft_hook` TEXT NULL');
                }
            }
            if (!$actions->tableExists('nu_workflow_action_log')) {
                $db->getPdo()->exec($sqlite
                    ? 'CREATE TABLE IF NOT EXISTS `nu_workflow_action_log` (
                        `wal_id` INTEGER PRIMARY KEY AUTOINCREMENT, `wal_wfi_id` INTEGER NOT NULL, `wal_source` TEXT,
                        `wal_type` TEXT NOT NULL, `wal_label` TEXT, `wal_status` TEXT NOT NULL, `wal_message` TEXT,
                        `wal_affected` INTEGER DEFAULT 0, `wal_changes` TEXT, `wal_at` DATETIME DEFAULT CURRENT_TIMESTAMP)'
                    : 'CREATE TABLE IF NOT EXISTS `nu_workflow_action_log` (
                        `wal_id` INT NOT NULL AUTO_INCREMENT, `wal_wfi_id` INT NOT NULL, `wal_source` VARCHAR(64) DEFAULT NULL,
                        `wal_type` VARCHAR(32) NOT NULL, `wal_label` VARCHAR(255) DEFAULT NULL, `wal_status` VARCHAR(16) NOT NULL,
                        `wal_message` TEXT, `wal_affected` INT DEFAULT 0, `wal_changes` MEDIUMTEXT,
                        `wal_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`wal_id`), KEY `idx_wal_wfi` (`wal_wfi_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            }
        } catch (Throwable $e) { error_log('[workflow schema] ' . $e->getMessage()); }
    }

    public function actions(): NuWfActions
    {
        return $this->actions;
    }

    // ── Start a new workflow instance ──────────────────────────────────────────
    public function start(int $wfId, int $userId, ?string $table = null, ?string $recordId = null, array $meta = []): int
    {
        $startStage = $this->db->fetchOne(
            'SELECT * FROM nu_workflow_stages
              WHERE wfs_wf_id = :wid AND wfs_is_start = 1
              ORDER BY wfs_order ASC LIMIT 1',
            [':wid' => $wfId]
        );
        if (!$startStage) {
            throw new RuntimeException('Workflow has no start stage defined.');
        }

        $instanceId = $this->db->insert('nu_workflow_instances', [
            'wfi_wf_id'        => $wfId,
            'wfi_stage_id'     => $startStage['wfs_id'],
            'wfi_record_table' => $table,
            'wfi_record_id'    => $recordId,
            'wfi_status'       => 'active',
            'wfi_started_by'   => $userId > 0 ? $userId : null,
            'wfi_meta'         => $meta ? json_encode($meta) : null,
        ]);

        $this->logHistory($instanceId, null, (int)$startStage['wfs_id'], 'start', $userId, 'Workflow started');

        try {
            $instance = $this->getInstance($instanceId);
            $this->runStageActions($instance, $startStage, null, $userId, '');
            $this->runAuto($instanceId, $userId);
        } catch (Throwable $e) {
            error_log('[Workflow start automation] ' . $e->getMessage());
        }
        return $instanceId;
    }

    // ── Advance to next stage via a transition ─────────────────────────────────
    public function advance(int $instanceId, int $transitionId, int $userId, string $comment = ''): bool
    {
        $instance = $this->getInstance($instanceId);
        if (!$instance || $instance['wfi_status'] !== 'active') {
            throw new RuntimeException('Instance not found or not active.');
        }

        $transition = $this->db->fetchOne(
            'SELECT * FROM nu_workflow_transitions WHERE wft_id = :tid AND wft_from_id = :from',
            [':tid' => $transitionId, ':from' => $instance['wfi_stage_id']]
        );
        if (!$transition) {
            throw new RuntimeException('Invalid transition for current stage.');
        }

        $fromStage = $this->db->fetchOne('SELECT * FROM nu_workflow_stages WHERE wfs_id = :id', [':id' => $instance['wfi_stage_id']]);
        $toStage   = $this->db->fetchOne('SELECT * FROM nu_workflow_stages WHERE wfs_id = :id', [':id' => $transition['wft_to_id']]);
        if (!$toStage) {
            throw new RuntimeException('The transition points to a stage that no longer exists.');
        }

        $ctx = $this->buildContext($instance, $fromStage, $toStage, $userId, $comment);
        $block = $this->blockedReason($transition, $fromStage, $ctx, $comment);
        if ($block !== null) {
            throw new RuntimeException($block);
        }

        $isEnd     = (bool)($toStage['wfs_is_end'] ?? false);
        $newStatus = $isEnd ? 'completed' : 'active';

        $this->db->update(
            'nu_workflow_instances',
            [
                'wfi_stage_id'     => $transition['wft_to_id'],
                'wfi_status'       => $newStatus,
                'wfi_completed_at' => $isEnd ? date('Y-m-d H:i:s') : null,
            ],
            'wfi_id = :id',
            [':id' => $instanceId]
        );

        $this->logHistory($instanceId, (int)$instance['wfi_stage_id'], (int)$transition['wft_to_id'], (string)$transition['wft_action'], $userId, $comment);

        // Actions: this transition's, then the destination stage's "on enter" list.
        $this->runActionList($transition['wft_hook'] ?? null, $ctx, $instanceId, 'transition:' . $transitionId);
        $this->runActionList($toStage['wfs_on_enter'] ?? null, $ctx, $instanceId, 'stage:' . $toStage['wfs_id']);

        // Outgoing webhooks
        try {
            require_once __DIR__ . '/WebhookSender.php';
            NuWebhookSender::trigger('workflow_advance', [
                'table'     => 'nu_workflow_instances',
                'record_id' => $instanceId,
                'data'      => [
                    'instance_id'   => $instanceId,
                    'workflow_name' => $instance['wf_name'] ?? '',
                    'workflow_code' => $instance['wf_code'] ?? '',
                    'action'        => $transition['wft_label'] ?? '',
                    'from_stage_id' => $transition['wft_from_id'] ?? '',
                    'to_stage_id'   => $transition['wft_to_id'] ?? '',
                    'comment'       => $comment
                ]
            ]);
        } catch (\Throwable $whe) {
            error_log('[Webhook Workflow Advance Trigger Error] ' . $whe->getMessage());
        }

        if (!$isEnd) {
            try { $this->runAuto($instanceId, $userId); } catch (Throwable $e) { error_log('[Workflow auto] ' . $e->getMessage()); }
        }
        return true;
    }

    // ── Rules ───────────────────────────────────────────────────────────────────

    /** Null when the transition may be taken now, else a human readable reason. */
    private function blockedReason(array $transition, ?array $fromStage, array $ctx, string $comment): ?string
    {
        $opts = $this->options($transition);
        $role = (string)($ctx['actor']['role'] ?? '');
        $isAdmin = in_array($role, self::ADMIN_ROLES, true) || ($ctx['actor']['id'] ?? 0) === 0;

        $allowed = array_values(array_filter(array_map('strval', (array)($opts['roles'] ?? []))));
        if (!$allowed && !empty($fromStage['wfs_role'])) {
            $allowed = array_values(array_filter(array_map('trim', explode(',', (string)$fromStage['wfs_role']))));
        }
        if ($allowed && !$isAdmin && !in_array($role, $allowed, true)) {
            return 'Your role (' . ($role ?: 'none') . ') cannot take this step. Allowed: ' . implode(', ', $allowed) . '.';
        }
        if (!empty($opts['require_comment']) && trim($comment) === '') {
            return 'A comment is required for this step.';
        }
        $c = NuWfRules::explain($transition['wft_condition'] ?? null, $ctx);
        if (!$c['pass']) return $c['reason'];
        return null;
    }

    private function options(array $transition): array
    {
        $raw = $transition['wft_options'] ?? null;
        if (!$raw) return [];
        $d = is_array($raw) ? $raw : json_decode((string)$raw, true);
        return is_array($d) ? $d : [];
    }

    /** Context used by conditions, templates and actions. */
    public function buildContext(array $instance, ?array $fromStage, ?array $toStage, int $userId, string $comment = ''): array
    {
        $actor = ['id' => $userId, 'name' => 'System', 'role' => ''];
        if ($userId > 0) {
            try {
                $u = $this->db->fetchOne('SELECT * FROM nu_users WHERE usr_id = :id', [':id' => $userId]);
                if ($u) $actor = ['id' => $userId, 'name' => $u['usr_name'] ?? ($u['usr_username'] ?? 'User'), 'role' => $u['usr_role'] ?? ''];
            } catch (Throwable $e) { /* keep default */ }
        }
        $meta = [];
        if (!empty($instance['wfi_meta'])) {
            $m = json_decode((string)$instance['wfi_meta'], true);
            if (is_array($m)) $meta = $m;
        }
        return [
            'record'        => $this->loadLinkedRecord($instance),
            'meta'          => $meta,
            'actor'         => $actor,
            'instance'      => $instance,
            'comment'       => $comment,
            'from_stage'    => $fromStage['wfs_name'] ?? '',
            'to_stage'      => $toStage['wfs_name'] ?? '',
            'to_stage_code' => $toStage['wfs_code'] ?? '',
            'now'           => date('Y-m-d H:i:s'),
            'today'         => date('Y-m-d'),
        ];
    }

    private function loadLinkedRecord(array $instance): array
    {
        $table = NuWfActions::cleanIdent((string)($instance['wfi_record_table'] ?? ''));
        $rid   = $instance['wfi_record_id'] ?? null;
        if ($table === '' || $rid === null || $rid === '' || !$this->actions->tableExists($table)) return [];
        $pk = $this->actions->primaryKey($table);
        if (!$pk) return [];
        try {
            return $this->db->fetchOne("SELECT * FROM `$table` WHERE `$pk` = :id", [':id' => $rid]) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    // ── Actions ─────────────────────────────────────────────────────────────────

    private function runActionList($raw, array $ctx, int $instanceId, string $source): void
    {
        $list = NuWfActions::normalize($raw);
        if (!$list) return;
        $this->actions->run($list, $ctx, false, function (array $res) use ($instanceId, $source): void {
            $this->logAction($instanceId, $source, $res);
        });
    }

    private function runStageActions(array $instance, array $stage, ?array $fromStage, int $userId, string $comment): void
    {
        if (empty($stage['wfs_on_enter'])) return;
        $ctx = $this->buildContext($instance, $fromStage, $stage, $userId, $comment);
        $this->runActionList($stage['wfs_on_enter'], $ctx, (int)$instance['wfi_id'], 'stage:' . $stage['wfs_id']);
    }

    /**
     * Dry-run an action list against a real record without writing anything.
     * @return array[] results with status "preview" | "skipped" | "error"
     */
    public function previewActions($raw, int $wfId, ?string $table, ?string $recordId, ?int $toStageId, int $userId, array $meta = []): array
    {
        $wf = $this->db->fetchOne('SELECT wf_name, wf_code FROM nu_workflows WHERE wf_id = :id', [':id' => $wfId]) ?: [];
        $instance = ['wfi_id' => 0, 'wfi_wf_id' => $wfId, 'wfi_record_table' => $table, 'wfi_record_id' => $recordId,
                     'wfi_started_by' => $userId, 'wf_name' => $wf['wf_name'] ?? '', 'wf_code' => $wf['wf_code'] ?? '',
                     'wfi_meta' => $meta ? json_encode($meta) : null];
        $to = $toStageId ? $this->db->fetchOne('SELECT * FROM nu_workflow_stages WHERE wfs_id = :id', [':id' => $toStageId]) : null;
        $ctx = $this->buildContext($instance, null, $to ?: null, $userId, '(preview)');
        return $this->actions->run(NuWfActions::normalize($raw), $ctx, true, null);
    }

    // ── Auto transitions & SLA ──────────────────────────────────────────────────

    /** Fire any `auto` transitions whose condition holds. Returns the number of steps taken. */
    public function runAuto(int $instanceId, int $userId = 0): int
    {
        $steps = 0;
        while ($steps < self::MAX_AUTO_STEPS) {
            $instance = $this->getInstance($instanceId);
            if (!$instance || $instance['wfi_status'] !== 'active') break;
            $fromStage = $this->db->fetchOne('SELECT * FROM nu_workflow_stages WHERE wfs_id = :id', [':id' => $instance['wfi_stage_id']]);
            $outs = $this->db->fetchAll('SELECT * FROM nu_workflow_transitions WHERE wft_from_id = :s ORDER BY wft_id ASC', [':s' => $instance['wfi_stage_id']]);
            $fired = false;
            foreach ($outs as $t) {
                $o = $this->options($t);
                if (empty($o['auto']) || !empty($o['require_comment'])) continue;
                $to = $this->db->fetchOne('SELECT * FROM nu_workflow_stages WHERE wfs_id = :id', [':id' => $t['wft_to_id']]);
                $ctx = $this->buildContext($instance, $fromStage, $to, $userId, 'Automatic');
                if (!NuWfRules::passes($t['wft_condition'] ?? null, $ctx)) continue;
                $this->advance($instanceId, (int)$t['wft_id'], $userId, 'Automatic');
                $steps++; $fired = true;
                break;
            }
            if (!$fired) break;
        }
        return $steps;
    }

    /**
     * Cron entry point: auto transitions that became true after a record changed, and SLA overruns.
     * @return string[] human readable log lines
     */
    public function processTimers(): array
    {
        $log = [];
        // 1) auto transitions (the record may have changed since the last step)
        try {
            $rows = $this->db->fetchAll(
                "SELECT DISTINCT i.wfi_id FROM nu_workflow_instances i
                   JOIN nu_workflow_transitions t ON t.wft_from_id = i.wfi_stage_id
                  WHERE i.wfi_status = 'active' AND t.wft_options LIKE '%\"auto\"%' LIMIT 500"
            );
            foreach ($rows as $r) {
                $n = $this->runAuto((int)$r['wfi_id'], 0);
                if ($n) $log[] = "Instance #{$r['wfi_id']}: {$n} automatic step(s)";
            }
        } catch (Throwable $e) { $log[] = 'Auto transitions error: ' . $e->getMessage(); }

        // 2) SLA overruns
        try {
            $rows = $this->db->fetchAll(
                "SELECT i.wfi_id, i.wfi_stage_id, i.wfi_started_at, i.wfi_meta, s.wfs_sla_hours, s.wfs_name
                   FROM nu_workflow_instances i JOIN nu_workflow_stages s ON s.wfs_id = i.wfi_stage_id
                  WHERE i.wfi_status = 'active' AND s.wfs_sla_hours IS NOT NULL AND s.wfs_sla_hours > 0 LIMIT 500"
            );
            foreach ($rows as $r) {
                $meta = json_decode((string)($r['wfi_meta'] ?? ''), true) ?: [];
                if ((int)($meta['sla_breached_stage'] ?? 0) === (int)$r['wfi_stage_id']) continue;
                $entered = $this->db->fetchOne(
                    'SELECT MAX(wfh_acted_at) AS at FROM nu_workflow_history WHERE wfh_wfi_id = :i AND wfh_to_id = :s',
                    [':i' => $r['wfi_id'], ':s' => $r['wfi_stage_id']]
                );
                $since = strtotime((string)($entered['at'] ?? $r['wfi_started_at']));
                if (!$since || time() < $since + (int)$r['wfs_sla_hours'] * 3600) continue;

                $meta['sla_breached_stage'] = (int)$r['wfi_stage_id'];
                $this->db->update('nu_workflow_instances', ['wfi_meta' => json_encode($meta)], 'wfi_id = :id', [':id' => $r['wfi_id']]);
                $this->logHistory((int)$r['wfi_id'], (int)$r['wfi_stage_id'], (int)$r['wfi_stage_id'], 'sla_breach', 0, "SLA of {$r['wfs_sla_hours']}h exceeded in {$r['wfs_name']}");
                $log[] = "Instance #{$r['wfi_id']}: SLA exceeded in {$r['wfs_name']}";

                $outs = $this->db->fetchAll('SELECT * FROM nu_workflow_transitions WHERE wft_from_id = :s ORDER BY wft_id', [':s' => $r['wfi_stage_id']]);
                foreach ($outs as $t) {
                    if (empty($this->options($t)['on_sla'])) continue;
                    try {
                        $this->advance((int)$r['wfi_id'], (int)$t['wft_id'], 0, 'SLA exceeded');
                        $log[] = "  → escalated via “{$t['wft_label']}”";
                    } catch (Throwable $e) { $log[] = '  → escalation failed: ' . $e->getMessage(); }
                    break;
                }
            }
        } catch (Throwable $e) { $log[] = 'SLA error: ' . $e->getMessage(); }
        return $log;
    }

    // ── Reject / cancel ────────────────────────────────────────────────────────
    public function reject(int $instanceId, int $userId, string $comment = ''): bool
    {
        return $this->terminate($instanceId, 'rejected', $userId, $comment);
    }

    public function cancel(int $instanceId, int $userId, string $comment = ''): bool
    {
        return $this->terminate($instanceId, 'cancelled', $userId, $comment);
    }

    private function terminate(int $instanceId, string $status, int $userId, string $comment): bool
    {
        $instance = $this->getInstance($instanceId);
        if (!$instance || $instance['wfi_status'] !== 'active') {
            throw new RuntimeException('Instance not found or not active.');
        }
        $this->db->update(
            'nu_workflow_instances',
            ['wfi_status' => $status, 'wfi_completed_at' => date('Y-m-d H:i:s')],
            'wfi_id = :id',
            [':id' => $instanceId]
        );
        $this->logHistory($instanceId, (int)$instance['wfi_stage_id'], (int)$instance['wfi_stage_id'], $status, $userId, $comment);
        return true;
    }

    // ── Helpers ────────────────────────────────────────────────────────────────
    public function getInstance(int $instanceId): ?array
    {
        return $this->db->fetchOne(
            'SELECT i.*, w.wf_name, w.wf_code, s.wfs_name AS stage_name, s.wfs_color AS stage_color
               FROM nu_workflow_instances i
               JOIN nu_workflows w ON w.wf_id = i.wfi_wf_id
               JOIN nu_workflow_stages s ON s.wfs_id = i.wfi_stage_id
              WHERE i.wfi_id = :id',
            [':id' => $instanceId]
        ) ?: null;
    }

    public function getHistory(int $instanceId): array
    {
        return $this->db->fetchAll(
            'SELECT h.*, u.usr_username AS actor_name,
                    fs.wfs_name AS from_stage, ts.wfs_name AS to_stage
               FROM nu_workflow_history h
               LEFT JOIN nu_users u  ON u.usr_id  = h.wfh_actor_id
               LEFT JOIN nu_workflow_stages fs ON fs.wfs_id = h.wfh_from_id
               LEFT JOIN nu_workflow_stages ts ON ts.wfs_id = h.wfh_to_id
              WHERE h.wfh_wfi_id = :id
              ORDER BY h.wfh_acted_at ASC, h.wfh_id ASC',
            [':id' => $instanceId]
        );
    }

    public function getActionLog(int $instanceId): array
    {
        try {
            $rows = $this->db->fetchAll('SELECT * FROM nu_workflow_action_log WHERE wal_wfi_id = :id ORDER BY wal_id ASC', [':id' => $instanceId]);
        } catch (Throwable $e) {
            return [];
        }
        foreach ($rows as &$r) { $r['wal_changes'] = $r['wal_changes'] ? json_decode((string)$r['wal_changes'], true) : []; }
        return $rows;
    }

    /**
     * Transitions out of the instance's current stage, each flagged with can_run / blocked_reason so the
     * UI can disable (and explain) steps that the record, the user's role or a missing comment block.
     */
    public function getAvailableTransitions(int $instanceId, ?int $userId = null): array
    {
        $instance = $this->getInstance($instanceId);
        if (!$instance) return [];
        $userId = $userId ?? (int)($_SESSION['nu_user_id'] ?? 0);
        $fromStage = $this->db->fetchOne('SELECT * FROM nu_workflow_stages WHERE wfs_id = :id', [':id' => $instance['wfi_stage_id']]);
        $rows = $this->db->fetchAll(
            'SELECT t.*, ts.wfs_name AS to_stage_name, ts.wfs_color AS to_stage_color, ts.wfs_code AS to_stage_code
               FROM nu_workflow_transitions t
               JOIN nu_workflow_stages ts ON ts.wfs_id = t.wft_to_id
              WHERE t.wft_from_id = :sid
              ORDER BY t.wft_id ASC',
            [':sid' => $instance['wfi_stage_id']]
        );
        foreach ($rows as &$t) {
            $opts = $this->options($t);
            $ctx = $this->buildContext($instance, $fromStage, ['wfs_name' => $t['to_stage_name'], 'wfs_code' => $t['to_stage_code']], $userId, '');
            // The comment requirement is checked when the button is pressed; do not block the button for it.
            $reason = $this->blockedReason($t, $fromStage, $ctx, 'x');
            $t['can_run']         = $reason === null;
            $t['blocked_reason']  = $reason ?? '';
            $t['require_comment'] = !empty($opts['require_comment']);
            $t['confirm']         = (string)($opts['confirm'] ?? '');
            $t['auto']            = !empty($opts['auto']);
            $t['on_sla']          = !empty($opts['on_sla']);
        }
        return $rows;
    }

    private function logAction(int $instanceId, string $source, array $res): void
    {
        try {
            $this->db->insert('nu_workflow_action_log', [
                'wal_wfi_id'   => $instanceId,
                'wal_source'   => $source,
                'wal_type'     => $res['type'] ?? '',
                'wal_label'    => ($res['label'] ?? '') !== '' ? $res['label'] : null,
                'wal_status'   => $res['status'] ?? 'ok',
                'wal_message'  => $res['message'] ?? '',
                'wal_affected' => (int)($res['affected'] ?? 0),
                'wal_changes'  => !empty($res['changes']) ? json_encode($res['changes']) : null,
            ]);
        } catch (Throwable $e) {
            error_log('[workflow action log] ' . $e->getMessage());
        }
        if (($res['status'] ?? '') === 'error') {
            error_log('[Workflow action error] ' . ($res['type'] ?? '') . ': ' . ($res['message'] ?? ''));
        }
    }

    private function logHistory(int $instanceId, ?int $fromId, int $toId, string $action, int $userId, string $comment): void
    {
        $this->db->insert('nu_workflow_history', [
            'wfh_wfi_id'   => $instanceId,
            'wfh_from_id'  => $fromId,
            'wfh_to_id'    => $toId,
            'wfh_action'   => $action,
            'wfh_actor_id' => $userId > 0 ? $userId : null,
            'wfh_comment'  => $comment ?: null,
        ]);
    }
}
