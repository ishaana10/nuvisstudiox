<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Auth.php';
require_once dirname(__DIR__) . '/core/Audit.php';
require_once dirname(__DIR__) . '/core/Workflow.php';
require_once dirname(__DIR__) . '/core/ProjectContext.php';

header('Content-Type: application/json');

$auth = NuAuth::getInstance();
if (!$auth->checkAuth()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db     = NuDatabase::getInstance();
$audit  = new NuAudit();
$engine = new WorkflowEngine();           // also upgrades the schema (wft_options, wfs_on_enter, action log)
$acts   = $engine->actions();
$userId = (int)($_SESSION['nu_user_id'] ?? 0);
$pid    = ProjectContext::getId();
$action = $_GET['action'] ?? '';
$body   = (array)(json_decode((string)file_get_contents('php://input'), true) ?? []);

/** Designing workflows (stages, rules, actions) needs an admin role or the 'workflow' permission, like the Workflow module itself. */
const WF_DESIGN_ACTIONS = ['save', 'delete', 'save_stage', 'delete_stage', 'save_transition', 'delete_transition',
                           'duplicate', 'import', 'schema', 'context', 'preview_actions', 'test_condition', 'export'];

function wf_json(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d); }
function wf_fail(string $msg, int $code = 200): void { wf_json(['success' => false, 'error' => $msg], $code); }

/** Accept JSON text or an already-decoded structure and store compact JSON (or null when empty). */
function wf_json_field($v): ?string
{
    if ($v === null || $v === '' || $v === [] ) return null;
    if (is_array($v)) return json_encode($v);
    $s = trim((string)$v);
    if ($s === '') return null;
    if ($s[0] === '{' || $s[0] === '[') {
        json_decode($s);
        if (json_last_error() !== JSON_ERROR_NONE) throw new InvalidArgumentException('Invalid JSON: ' . json_last_error_msg());
    }
    return $s;
}

$ownWorkflow = static function (int $wfId) use ($db, $pid): ?array {
    return $db->fetchOne('SELECT * FROM nu_workflows WHERE wf_id = :id AND project_id = :pid', [':id' => $wfId, ':pid' => $pid]) ?: null;
};
$ownInstance = static function (int $instanceId) use ($db, $pid): bool {
    return (bool)$db->fetchOne(
        'SELECT i.wfi_id FROM nu_workflow_instances i JOIN nu_workflows w ON w.wf_id = i.wfi_wf_id WHERE i.wfi_id = :id AND w.project_id = :pid',
        [':id' => $instanceId, ':pid' => $pid]
    );
};

/** Copy a workflow bundle (workflow + stages + transitions) into this project as a new workflow. */
$importBundle = static function (array $b, ?string $nameSuffix = null) use ($db, $pid, $userId): int {
    $w = (array)($b['workflow'] ?? []);
    $name = trim((string)($w['wf_name'] ?? ''));
    if ($name === '') throw new InvalidArgumentException('The file has no workflow name');
    $code = strtolower(preg_replace('/[^a-z0-9]+/i', '_', (string)($w['wf_code'] ?? $name)) ?? 'workflow');
    $base = $code; $n = 1;
    while ($db->fetchOne('SELECT wf_id FROM nu_workflows WHERE wf_code = :c AND project_id = :p', [':c' => $code, ':p' => $pid])) {
        $code = $base . '_copy' . ($n > 1 ? "_$n" : ''); $n++;
    }
    $wfId = $db->insert('nu_workflows', [
        'project_id' => $pid, 'wf_code' => $code, 'wf_name' => $name . ($nameSuffix ?? ''),
        'wf_description' => (string)($w['wf_description'] ?? ''), 'wf_form_code' => ($w['wf_form_code'] ?? '') ?: null,
        'wf_active' => 1, 'wf_created_by' => $userId,
    ]);
    $map = [];
    foreach ((array)($b['stages'] ?? []) as $s) {
        $map[(int)($s['wfs_id'] ?? 0)] = $db->insert('nu_workflow_stages', [
            'project_id' => $pid, 'wfs_wf_id' => $wfId, 'wfs_code' => (string)($s['wfs_code'] ?? ''), 'wfs_name' => (string)($s['wfs_name'] ?? 'Stage'),
            'wfs_description' => (string)($s['wfs_description'] ?? ''), 'wfs_color' => (string)($s['wfs_color'] ?? '#6366f1'),
            'wfs_is_start' => (int)($s['wfs_is_start'] ?? 0), 'wfs_is_end' => (int)($s['wfs_is_end'] ?? 0), 'wfs_order' => (int)($s['wfs_order'] ?? 0),
            'wfs_sla_hours' => ($s['wfs_sla_hours'] ?? '') !== '' ? (int)$s['wfs_sla_hours'] : null, 'wfs_role' => ($s['wfs_role'] ?? '') ?: null,
            'wfs_on_enter' => wf_json_field($s['wfs_on_enter'] ?? null),
        ]);
    }
    foreach ((array)($b['transitions'] ?? []) as $t) {
        $from = $map[(int)($t['wft_from_id'] ?? 0)] ?? null; $to = $map[(int)($t['wft_to_id'] ?? 0)] ?? null;
        if (!$from || !$to) continue;
        $db->insert('nu_workflow_transitions', [
            'project_id' => $pid, 'wft_wf_id' => $wfId, 'wft_from_id' => $from, 'wft_to_id' => $to,
            'wft_action' => (string)($t['wft_action'] ?? 'advance'), 'wft_label' => (string)($t['wft_label'] ?? 'Advance'),
            'wft_condition' => wf_json_field($t['wft_condition'] ?? null), 'wft_hook' => wf_json_field($t['wft_hook'] ?? null),
            'wft_options' => wf_json_field($t['wft_options'] ?? null),
        ]);
    }
    return $wfId;
};

/** Resolve the table/record to test against: explicit, else the workflow's bound form table (latest row). */
$sampleRecord = static function (?array $wf, string $table, string $recordId) use ($db, $acts): array {
    $table = NuWfActions::cleanIdent($table);
    if ($table === '' && $wf && !empty($wf['wf_form_code'])) {
        $f = $db->fetchOne('SELECT form_table FROM nu_forms WHERE LOWER(form_code) = LOWER(:c) LIMIT 1', [':c' => $wf['wf_form_code']]);
        $table = NuWfActions::cleanIdent((string)($f['form_table'] ?? ''));
    }
    if ($table === '' || !$acts->tableExists($table)) return [$table ?: null, null, []];
    $pk = $acts->primaryKey($table);
    if (!$pk) return [$table, null, []];
    if ($recordId !== '') $row = $db->fetchOne("SELECT * FROM `$table` WHERE `$pk` = :id", [':id' => $recordId]);
    else                  $row = $db->fetchOne("SELECT * FROM `$table` ORDER BY `$pk` DESC LIMIT 1");
    return [$table, $row ? (string)$row[$pk] : null, $row ?: []];
};

try {
    if (in_array($action, WF_DESIGN_ACTIONS, true)
        && !in_array($auth->getCurrentRole(), ['globeadmin', 'admin'], true)
        && !$auth->hasPermission('workflow')) {
        wf_fail('You do not have permission to design workflows', 403);
        exit;
    }

    switch ($action) {

        // ── LIST workflows ─────────────────────────────────────────────────────
        case 'list':
            $rows = $db->fetchAll(
                'SELECT w.*,
                        (SELECT COUNT(*) FROM nu_workflow_stages WHERE wfs_wf_id = w.wf_id) AS stage_count,
                        (SELECT COUNT(*) FROM nu_workflow_instances WHERE wfi_wf_id = w.wf_id AND wfi_status = "active") AS active_instances
                   FROM nu_workflows w
                  WHERE w.project_id = ?
                  ORDER BY w.wf_updated_at DESC',
                [$pid]
            );
            wf_json(['success' => true, 'workflows' => $rows]);
            break;

        // ── GET single workflow + its stages + transitions ──────────────────────
        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            $wf = $ownWorkflow($id);
            if (!$wf) { wf_fail('Not found or access denied'); break; }
            $stages = $db->fetchAll(
                'SELECT * FROM nu_workflow_stages WHERE wfs_wf_id = :id AND project_id = :pid ORDER BY wfs_order ASC, wfs_id ASC',
                [':id' => $id, ':pid' => $pid]
            );
            $transitions = $db->fetchAll(
                'SELECT t.*, fs.wfs_name AS from_name, ts.wfs_name AS to_name
                   FROM nu_workflow_transitions t
                   JOIN nu_workflow_stages fs ON fs.wfs_id = t.wft_from_id
                   JOIN nu_workflow_stages ts ON ts.wfs_id = t.wft_to_id
                  WHERE t.wft_wf_id = :id AND t.project_id = :pid
                  ORDER BY t.wft_id ASC',
                [':id' => $id, ':pid' => $pid]
            );
            wf_json(['success' => true, 'workflow' => $wf, 'stages' => $stages, 'transitions' => $transitions]);
            break;

        // ── SAVE workflow (create or update) ───────────────────────────────────
        case 'save':
            $name = trim((string)($body['wf_name'] ?? ''));
            if ($name === '') { wf_fail('Name required'); break; }
            $code = trim((string)($body['wf_code'] ?? ''));
            if ($code === '') $code = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name));
            $data = [
                'project_id'     => $pid,
                'wf_name'        => $name,
                'wf_code'        => $code,
                'wf_description' => trim((string)($body['wf_description'] ?? '')),
                'wf_form_code'   => trim((string)($body['wf_form_code'] ?? '')) ?: null,
                'wf_active'      => isset($body['wf_active']) ? (int)(bool)$body['wf_active'] : 1,
                'wf_created_by'  => $userId,
            ];
            $wfId = (int)($body['wf_id'] ?? 0);
            if ($wfId > 0) {
                if (!$ownWorkflow($wfId)) { wf_fail('Workflow not found or access denied'); break; }
                unset($data['wf_created_by']);
                $db->update('nu_workflows', $data, 'wf_id = :id AND project_id = :pid', [':id' => $wfId, ':pid' => $pid]);
                $audit->log('workflow_update', 'nu_workflows', $wfId);
            } else {
                $wfId = $db->insert('nu_workflows', $data);
                $audit->log('workflow_create', 'nu_workflows', $wfId);
            }
            wf_json(['success' => true, 'wf_id' => $wfId]);
            break;

        // ── DELETE workflow ────────────────────────────────────────────────────
        case 'delete':
            $id = (int)($_GET['id'] ?? 0);
            if (!$ownWorkflow($id)) { wf_fail('Workflow not found or access denied'); break; }
            $active = $db->fetchOne('SELECT COUNT(*) AS n FROM nu_workflow_instances WHERE wfi_wf_id = :id AND wfi_status = "active"', [':id' => $id]);
            if ($active && (int)$active['n'] > 0) { wf_fail('Cannot delete: workflow has active instances.'); break; }
            $db->query('DELETE FROM nu_workflows WHERE wf_id = :id AND project_id = :pid', [':id' => $id, ':pid' => $pid]);
            $audit->log('workflow_delete', 'nu_workflows', $id);
            wf_json(['success' => true]);
            break;

        // ── DUPLICATE / EXPORT / IMPORT ────────────────────────────────────────
        case 'export':
        case 'duplicate':
            $id = (int)($_GET['id'] ?? 0);
            $wf = $ownWorkflow($id);
            if (!$wf) { wf_fail('Not found or access denied'); break; }
            $bundle = [
                'format'      => 'nuvis-workflow/1',
                'workflow'    => $wf,
                'stages'      => $db->fetchAll('SELECT * FROM nu_workflow_stages WHERE wfs_wf_id = :id ORDER BY wfs_order, wfs_id', [':id' => $id]),
                'transitions' => $db->fetchAll('SELECT * FROM nu_workflow_transitions WHERE wft_wf_id = :id ORDER BY wft_id', [':id' => $id]),
            ];
            if ($action === 'export') { wf_json(['success' => true, 'bundle' => $bundle]); break; }
            $newId = $importBundle($bundle, ' (copy)');
            $audit->log('workflow_duplicate', 'nu_workflows', $newId);
            wf_json(['success' => true, 'wf_id' => $newId]);
            break;

        case 'import':
            $bundle = (array)($body['bundle'] ?? []);
            if (($bundle['format'] ?? '') !== 'nuvis-workflow/1') { wf_fail('This is not a Nuvis workflow export file'); break; }
            $newId = $importBundle($bundle);
            $audit->log('workflow_import', 'nu_workflows', $newId);
            wf_json(['success' => true, 'wf_id' => $newId]);
            break;

        // ── SAVE stage ─────────────────────────────────────────────────────────
        case 'save_stage':
            $wfId    = (int)($body['wfs_wf_id'] ?? 0);
            $stageId = (int)($body['wfs_id']    ?? 0);
            $name    = trim((string)($body['wfs_name'] ?? ''));
            if (!$wfId || $name === '') { wf_fail('wf_id and name required'); break; }
            if (!$ownWorkflow($wfId)) { wf_fail('Workflow not found or access denied'); break; }
            $code = trim((string)($body['wfs_code'] ?? ''));
            if ($code === '') $code = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name));
            try { $onEnter = wf_json_field($body['wfs_on_enter'] ?? null); }
            catch (InvalidArgumentException $e) { wf_fail('On-enter actions: ' . $e->getMessage()); break; }
            $data = [
                'project_id'      => $pid,
                'wfs_wf_id'       => $wfId,
                'wfs_code'        => $code,
                'wfs_name'        => $name,
                'wfs_description' => trim((string)($body['wfs_description'] ?? '')),
                'wfs_color'       => trim((string)($body['wfs_color'] ?? '#6366f1')),
                'wfs_is_start'    => (int)(bool)($body['wfs_is_start'] ?? 0),
                'wfs_is_end'      => (int)(bool)($body['wfs_is_end']   ?? 0),
                'wfs_order'       => (int)($body['wfs_order'] ?? 0),
                'wfs_sla_hours'   => (($body['wfs_sla_hours'] ?? '') !== '') ? (int)$body['wfs_sla_hours'] : null,
                'wfs_role'        => trim((string)($body['wfs_role'] ?? '')) ?: null,
                'wfs_on_enter'    => $onEnter,
            ];
            if ($stageId > 0) {
                $db->update('nu_workflow_stages', $data, 'wfs_id = :id AND wfs_wf_id = :wf', [':id' => $stageId, ':wf' => $wfId]);
                wf_json(['success' => true, 'wfs_id' => $stageId]);
            } else {
                wf_json(['success' => true, 'wfs_id' => $db->insert('nu_workflow_stages', $data)]);
            }
            break;

        // ── DELETE stage ───────────────────────────────────────────────────────
        case 'delete_stage':
            $id = (int)($_GET['id'] ?? 0);
            $st = $db->fetchOne('SELECT s.wfs_id FROM nu_workflow_stages s JOIN nu_workflows w ON w.wf_id = s.wfs_wf_id WHERE s.wfs_id = :id AND w.project_id = :pid', [':id' => $id, ':pid' => $pid]);
            if (!$st) { wf_fail('Stage not found or access denied'); break; }
            $inUse = $db->fetchOne('SELECT COUNT(*) AS n FROM nu_workflow_instances WHERE wfi_stage_id = :id AND wfi_status = "active"', [':id' => $id]);
            if ($inUse && (int)$inUse['n'] > 0) { wf_fail('Cannot delete: ' . (int)$inUse['n'] . ' active instance(s) are in this stage.'); break; }
            $db->query('DELETE FROM nu_workflow_stages WHERE wfs_id = :id', [':id' => $id]);
            wf_json(['success' => true]);
            break;

        // ── SAVE transition ────────────────────────────────────────────────────
        case 'save_transition':
            $wfId   = (int)($body['wft_wf_id']   ?? 0);
            $fromId = (int)($body['wft_from_id']  ?? 0);
            $toId   = (int)($body['wft_to_id']    ?? 0);
            $tId    = (int)($body['wft_id']       ?? 0);
            if (!$wfId || !$fromId || !$toId) { wf_fail('wf_id, from_id, to_id required'); break; }
            if (!$ownWorkflow($wfId)) { wf_fail('Workflow not found or access denied'); break; }
            $ok = $db->fetchAll('SELECT wfs_id FROM nu_workflow_stages WHERE wfs_wf_id = :w AND wfs_id IN (:a, :b)', [':w' => $wfId, ':a' => $fromId, ':b' => $toId]);
            if (count($ok) < ($fromId === $toId ? 1 : 2)) { wf_fail('Both stages must belong to this workflow'); break; }
            try {
                $cond = wf_json_field($body['wft_condition'] ?? null);
                $hook = wf_json_field($body['wft_hook'] ?? null);
                $opts = wf_json_field($body['wft_options'] ?? null);
            } catch (InvalidArgumentException $e) { wf_fail($e->getMessage()); break; }
            $data = [
                'project_id'    => $pid,
                'wft_wf_id'     => $wfId,
                'wft_from_id'   => $fromId,
                'wft_to_id'     => $toId,
                'wft_action'    => trim((string)($body['wft_action'] ?? 'advance')) ?: 'advance',
                'wft_label'     => trim((string)($body['wft_label']  ?? 'Advance')) ?: 'Advance',
                'wft_condition' => $cond,
                'wft_hook'      => $hook,
                'wft_options'   => $opts,
            ];
            if ($tId > 0) {
                $db->update('nu_workflow_transitions', $data, 'wft_id = :id AND wft_wf_id = :wf', [':id' => $tId, ':wf' => $wfId]);
                wf_json(['success' => true, 'wft_id' => $tId]);
            } else {
                wf_json(['success' => true, 'wft_id' => $db->insert('nu_workflow_transitions', $data)]);
            }
            break;

        // ── DELETE transition ──────────────────────────────────────────────────
        case 'delete_transition':
            $id = (int)($_GET['id'] ?? 0);
            $tr = $db->fetchOne('SELECT t.wft_id FROM nu_workflow_transitions t JOIN nu_workflows w ON w.wf_id = t.wft_wf_id WHERE t.wft_id = :id AND w.project_id = :pid', [':id' => $id, ':pid' => $pid]);
            if (!$tr) { wf_fail('Transition not found or access denied'); break; }
            $db->query('DELETE FROM nu_workflow_transitions WHERE wft_id = :id', [':id' => $id]);
            wf_json(['success' => true]);
            break;

        // ── BUILDER SUPPORT: tables/columns, fields of the bound record, roles ─
        case 'schema':
            $rows = $db->fetchAll('SELECT TABLE_NAME AS t, COLUMN_NAME AS c, DATA_TYPE AS ty, COLUMN_KEY AS k
                                     FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION');
            $tables = [];
            foreach ($rows as $r) { $tables[$r['t']]['name'] = $r['t']; $tables[$r['t']]['writable'] = stripos($r['t'], 'nu_') !== 0; $tables[$r['t']]['columns'][] = ['name' => $r['c'], 'type' => $r['ty'], 'key' => $r['k']]; }
            wf_json(['success' => true, 'tables' => array_values($tables)]);
            break;

        case 'context':
            $wf = $ownWorkflow((int)($_GET['wf_id'] ?? 0));
            [$table, , ] = $sampleRecord($wf, '', '__none__');
            $fields = $table ? array_map(static fn($c) => ['name' => $c], $acts->columns($table)) : [];
            $roles = $db->fetchAll('SELECT role_code, role_name FROM nu_roles WHERE role_active = 1 ORDER BY role_name');
            $forms = $db->fetchAll('SELECT form_code, form_name, form_table FROM nu_forms WHERE project_id = :p AND form_active = 1 ORDER BY form_name', [':p' => $pid]);
            wf_json(['success' => true, 'table' => $table, 'fields' => $fields, 'roles' => $roles, 'forms' => $forms]);
            break;

        // Dry-run an action list (nothing is written) so the builder can show exactly what would change.
        case 'preview_actions':
            $wfId = (int)($body['wf_id'] ?? 0);
            $wf = $ownWorkflow($wfId);
            if (!$wf) { wf_fail('Workflow not found'); break; }
            [$table, $rid, $row] = $sampleRecord($wf, (string)($body['table'] ?? ''), trim((string)($body['record_id'] ?? '')));
            $toStage = (int)($body['to_stage_id'] ?? 0) ?: null;
            $res = $engine->previewActions($body['actions'] ?? [], $wfId, $table, $rid, $toStage, $userId, (array)($body['meta'] ?? []));
            wf_json(['success' => true, 'results' => $res, 'table' => $table, 'record_id' => $rid, 'record' => $row, 'has_record' => $rid !== null]);
            break;

        case 'test_condition':
            $wfId = (int)($body['wf_id'] ?? 0);
            $wf = $ownWorkflow($wfId);
            [$table, $rid, $row] = $sampleRecord($wf, (string)($body['table'] ?? ''), trim((string)($body['record_id'] ?? '')));
            $inst = ['wfi_id' => 0, 'wfi_record_table' => $table, 'wfi_record_id' => $rid, 'wf_name' => $wf['wf_name'] ?? '', 'wf_code' => $wf['wf_code'] ?? ''];
            $ctx = $engine->buildContext($inst, null, null, $userId, '');
            if (!empty($body['data']) && is_array($body['data'])) $ctx['record'] = array_merge($ctx['record'], $body['data']);
            $r = NuWfRules::explain($body['condition'] ?? null, $ctx);
            wf_json(['success' => true, 'pass' => $r['pass'], 'reason' => $r['reason'], 'table' => $table, 'record_id' => $rid]);
            break;

        // ── START instance ─────────────────────────────────────────────────────
        case 'start':
            $wfId     = (int)($body['wf_id'] ?? 0);
            $table    = trim((string)($body['record_table'] ?? '')) ?: null;
            $recordId = trim((string)($body['record_id']    ?? '')) ?: null;
            $meta     = (array)($body['meta'] ?? []);
            if (!$wfId) { wf_fail('wf_id required'); break; }
            $wf = $ownWorkflow($wfId);
            if (!$wf) { wf_fail('Workflow not found or access denied'); break; }
            if (!(int)$wf['wf_active']) { wf_fail('This workflow is switched off'); break; }
            $instanceId = $engine->start($wfId, $userId, $table, $recordId, $meta);
            $audit->log('workflow_instance_start', 'nu_workflow_instances', $instanceId);
            wf_json(['success' => true, 'instance_id' => $instanceId]);
            break;

        // ── ADVANCE instance ───────────────────────────────────────────────────
        case 'advance':
            $instanceId   = (int)($body['instance_id']   ?? 0);
            $transitionId = (int)($body['transition_id'] ?? 0);
            $comment      = trim((string)($body['comment'] ?? ''));
            if (!$ownInstance($instanceId)) { wf_fail('Instance not found or access denied'); break; }
            $engine->advance($instanceId, $transitionId, $userId, $comment);
            $audit->log('workflow_advance', 'nu_workflow_instances', $instanceId);
            wf_json(['success' => true]);
            break;

        case 'reject':
        case 'cancel':
            $instanceId = (int)($body['instance_id'] ?? 0);
            $comment    = trim((string)($body['comment'] ?? ''));
            if (!$ownInstance($instanceId)) { wf_fail('Instance not found or access denied'); break; }
            $action === 'reject' ? $engine->reject($instanceId, $userId, $comment) : $engine->cancel($instanceId, $userId, $comment);
            $audit->log('workflow_' . $action, 'nu_workflow_instances', $instanceId);
            wf_json(['success' => true]);
            break;

        // Re-check automatic transitions for one instance (e.g. after the record was edited).
        case 'evaluate':
            $instanceId = (int)($body['instance_id'] ?? 0);
            if (!$ownInstance($instanceId)) { wf_fail('Instance not found or access denied'); break; }
            wf_json(['success' => true, 'steps' => $engine->runAuto($instanceId, $userId)]);
            break;

        // ── LIST instances for a workflow ──────────────────────────────────────
        case 'instances':
            $wfId   = (int)($_GET['wf_id'] ?? 0);
            $status = trim((string)($_GET['status'] ?? ''));
            if (!$ownWorkflow($wfId)) { wf_fail('Workflow not found or access denied'); break; }
            $sql    = 'SELECT i.*, s.wfs_name AS stage_name, s.wfs_color AS stage_color,
                              u.usr_username AS started_by_name
                         FROM nu_workflow_instances i
                         JOIN nu_workflow_stages s ON s.wfs_id = i.wfi_stage_id
                         LEFT JOIN nu_users u ON u.usr_id = i.wfi_started_by
                        WHERE i.wfi_wf_id = :wfid';
            $params = [':wfid' => $wfId];
            if ($status) { $sql .= ' AND i.wfi_status = :status'; $params[':status'] = $status; }
            $sql .= ' ORDER BY i.wfi_started_at DESC LIMIT 200';
            wf_json(['success' => true, 'instances' => $db->fetchAll($sql, $params)]);
            break;

        // ── HISTORY (+ action log + available steps) for an instance ───────────
        case 'history':
            $instanceId = (int)($_GET['instance_id'] ?? 0);
            if (!$ownInstance($instanceId)) { wf_fail('Instance not found or access denied'); break; }
            wf_json([
                'success'     => true,
                'instance'    => $engine->getInstance($instanceId),
                'history'     => $engine->getHistory($instanceId),
                'transitions' => $engine->getAvailableTransitions($instanceId, $userId),
                'actions'     => $engine->getActionLog($instanceId),
            ]);
            break;

        case 'transitions':
            $instanceId = (int)($_GET['instance_id'] ?? 0);
            if (!$ownInstance($instanceId)) { wf_fail('Instance not found or access denied'); break; }
            wf_json(['success' => true, 'transitions' => $engine->getAvailableTransitions($instanceId, $userId)]);
            break;

        default:
            wf_fail('Unknown action: ' . htmlspecialchars($action), 400);
    }
} catch (RuntimeException | InvalidArgumentException $e) {
    // Expected, user-facing problems (blocked step, missing comment, bad config): not a server fault.
    wf_fail($e->getMessage());
} catch (Throwable $e) {
    error_log('[workflow.php] ' . $e->getMessage());
    wf_fail($e->getMessage(), 500);
}
