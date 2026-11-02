<?php
declare(strict_types=1);
/**
 * api/customer_requests.php — backend for the Customer Requests module.
 *
 * Thin orchestration over NuCustomerRequests (provisioning/config) and the
 * WorkflowEngine (status tracking). Everything is scoped to the current project.
 *
 * Actions:
 *   templates       list built-in setup templates
 *   list_configs    all request channels in this project (+ public link, stages)
 *   get_config      one channel (+ stages, form fields, public link)
 *   setup           provision a new channel (easy setup)            [admin]
 *   save_config     update a channel's settings                    [admin]
 *   delete_config   remove a channel (keeps the data/tables)        [admin]
 *   board           requests for a channel grouped by status
 *   request         one request: fields + workflow timeline + notes + actions
 *   advance         move a request to another status (workflow transition)
 *   add_note        add an internal note to a request
 *   create_request  create a request manually from inside the app
 *   stats           per-stage counts + overdue totals
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Auth.php';
require_once dirname(__DIR__) . '/core/Audit.php';
require_once dirname(__DIR__) . '/core/Workflow.php';
require_once dirname(__DIR__) . '/core/ProjectContext.php';
require_once dirname(__DIR__) . '/core/CustomerRequests.php';
require_once __DIR__ . '/_form_layout_helpers.php';

header('Content-Type: application/json');

$auth = NuAuth::getInstance();
if (!$auth->checkAuth()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db     = NuDatabase::getInstance();
$audit  = new NuAudit();
$userId = (int)($_SESSION['nu_user_id'] ?? 0);
$role   = (string)($_SESSION['nu_role'] ?? $_SESSION['role'] ?? '');
$isAdmin = in_array($role, ['globeadmin', 'admin'], true);
$pid    = ProjectContext::getId();
$action = (string)($_GET['action'] ?? '');
$body   = (array)(json_decode((string)file_get_contents('php://input'), true) ?? []);

if (!function_exists('cr_json')) {
    function cr_json(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d); }
}
if (!function_exists('cr_fail')) {
    function cr_fail(string $msg, int $code = 200): void { cr_json(['success' => false, 'error' => $msg], $code); }
}

/** Actions that change configuration require an admin (same bar as Forms/Workflow design). */
if (!defined('CR_ADMIN_ACTIONS')) {
    define('CR_ADMIN_ACTIONS', ['setup', 'save_config', 'delete_config']);
}
if (in_array($action, CR_ADMIN_ACTIONS, true) && !$isAdmin) {
    cr_fail('You need an administrator role to change customer-request setup.', 403);
    exit;
}

NuCustomerRequests::ensureSchema($db);

/** Load a config owned by this project or fail. */
$requireConfig = static function (int $id) use ($db, $pid): array {
    return NuCustomerRequests::getConfig($db, $pid, $id); // throws if not found
};

/** Map a workflow instance onto each record of a channel's table. */
$instancesForTable = static function (string $table) use ($db, $pid): array {
    $rows = $db->fetchAll(
        'SELECT i.wfi_id, i.wfi_record_id, i.wfi_stage_id, i.wfi_status, i.wfi_started_at,
                s.wfs_code, s.wfs_name, s.wfs_color, s.wfs_sla_hours
           FROM nu_workflow_instances i
           JOIN nu_workflow_stages s ON s.wfs_id = i.wfi_stage_id
          WHERE i.wfi_record_table = ?
          ORDER BY i.wfi_id DESC',
        [$table]
    );
    $byRecord = [];
    foreach ($rows as $r) {
        $rid = (string)$r['wfi_record_id'];
        if (!isset($byRecord[$rid])) $byRecord[$rid] = $r; // newest instance wins
    }
    return $byRecord;
};

/** Inspect a table's columns and determine its primary key. */
$tableMeta = static function (string $table) use ($db): array {
    $cleanTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($cleanTable === '') {
        return ['table' => '', 'pk' => 'id', 'columns' => [], 'pk_type' => 'text'];
    }
    $sqlite = $db->getDriverName() === 'sqlite';
    $cols = [];
    $pk = null;
    $pkType = 'text';

    try {
        if ($sqlite) {
            $rows = $db->fetchAll("PRAGMA table_info(`{$cleanTable}`)");
            foreach ($rows as $r) {
                $name = (string)($r['name'] ?? '');
                $type = strtolower((string)($r['type'] ?? ''));
                $cols[$name] = [
                    'name' => $name,
                    'type' => $type,
                    'pk'   => !empty($r['pk']),
                ];
                if (!empty($r['pk']) && $pk === null) {
                    $pk = $name;
                    $pkType = $type;
                }
            }
        } else {
            $rows = $db->fetchAll("DESCRIBE `{$cleanTable}`");
            foreach ($rows as $r) {
                $name = (string)($r['Field'] ?? '');
                $type = strtolower((string)($r['Type'] ?? ''));
                $isPk = strtoupper((string)($r['Key'] ?? '')) === 'PRI';
                $cols[$name] = [
                    'name' => $name,
                    'type' => $type,
                    'pk'   => $isPk,
                ];
                if ($isPk && $pk === null) {
                    $pk = $name;
                    $pkType = $type;
                }
            }
        }
    } catch (Throwable $e) {}

    // Fallbacks if PK not explicitly defined in schema
    if (!$pk) {
        $candidates = ['id', 'request_id', 'service_type_id', 'service_log_id'];
        foreach ($candidates as $cand) {
            if (isset($cols[$cand])) {
                $pk = $cand;
                $pkType = $cols[$cand]['type'] ?? 'text';
                break;
            }
        }
        if (!$pk) {
            $firstCol = array_key_first($cols);
            $pk = $firstCol ?: 'id';
            $pkType = $cols[$pk]['type'] ?? 'text';
        }
    }

    return [
        'table'   => $cleanTable,
        'pk'      => $pk,
        'pk_type' => $pkType,
        'columns' => $cols,
    ];
};

/** Extract record ID using table meta or fallback candidates. */
$recordId = static function (array $rec, ?string $pkCol = null): string {
    if ($pkCol && isset($rec[$pkCol]) && $rec[$pkCol] !== '') {
        return (string)$rec[$pkCol];
    }
    if (isset($rec['id']) && $rec['id'] !== '') return (string)$rec['id'];
    if (isset($rec['request_id']) && $rec['request_id'] !== '') return (string)$rec['request_id'];
    foreach ($rec as $k => $v) {
        if (str_ends_with(strtolower($k), '_id') && $v !== null && $v !== '') {
            return (string)$v;
        }
    }
    return '';
};

/** A short, friendly reference for a request. */
$reference = static function (array $cfg, array $rec, ?string $pkCol = null) use ($recordId): string {
    $prefix = (string)($cfg['cr_reference_prefix'] ?? 'REQ-');
    $id = $recordId($rec, $pkCol);
    $short = (strlen($id) > 8 && strpos($id, '-') !== false) ? strtoupper(substr($id, 0, 6)) : $id;
    return $prefix . $short;
};

/** Hours since a timestamp (for SLA/age). */
$hoursSince = static function (?string $ts): ?float {
    if (!$ts) return null;
    $t = strtotime($ts);
    return $t ? round((time() - $t) / 3600, 1) : null;
};

try {
    switch ($action) {

        /* ── Setup templates ─────────────────────────────────────────────── */
        case 'templates':
            cr_json(['success' => true, 'templates' => NuCustomerRequests::templateSummaries()]);
            break;

        /* ── Channels ────────────────────────────────────────────────────── */
        case 'list_configs':
            $configs = NuCustomerRequests::listConfigs($db, $pid);
            // attach a quick total per channel
            foreach ($configs as &$c) {
                try { $c['total'] = (int)($db->fetchOne('SELECT COUNT(*) AS n FROM `' . preg_replace('/[^a-zA-Z0-9_]/', '', $c['cr_table']) . '`')['n'] ?? 0); }
                catch (Throwable $e) { $c['total'] = 0; }
            }
            unset($c);
            // for the "attach existing form" setup path
            $forms = $db->fetchAll('SELECT form_code, form_name, form_table FROM nu_forms WHERE project_id = ? AND form_active = 1 AND form_table IS NOT NULL AND form_table <> "" ORDER BY form_name', [$pid]);
            cr_json(['success' => true, 'configs' => $configs, 'forms' => $forms]);
            break;

        case 'get_config':
            $cfg = $requireConfig((int)($_GET['id'] ?? 0));
            // form fields (for manual create + column labels)
            $form = $db->fetchOne('SELECT form_code, form_name, form_layout FROM nu_forms WHERE form_code = ? AND project_id = ?', [$cfg['cr_form_code'], $pid]);
            $fields = [];
            if ($form) {
                $rawLayout = json_decode((string)$form['form_layout'], true) ?: [];
                $flatLayout = function_exists('nu_flatten_layout_fields')
                    ? nu_flatten_layout_fields($rawLayout)
                    : $rawLayout;
                foreach ($flatLayout as $n) {
                    $t = (string)($n['type'] ?? 'text');
                    if (in_array($t, ['divider', 'html', 'group', 'tab', 'button', '_form_style'], true)) continue;
                    $nm = (string)($n['name'] ?? ($n['fieldname'] ?? ''));
                    if ($nm === '') continue;
                    $opts = [];
                    if (is_array($n['options'] ?? null)) {
                        foreach ($n['options'] as $o) {
                            if (is_array($o)) {
                                $opts[] = ['value' => (string)($o['value'] ?? ''), 'label' => (string)($o['label'] ?? $o['value'] ?? '')];
                            } else {
                                $opts[] = ['value' => (string)$o, 'label' => (string)$o];
                            }
                        }
                    }
                    $fields[] = [
                        'name' => $nm, 'label' => (string)($n['label'] ?? ($n['fieldlabel'] ?? $nm)), 'type' => $t,
                        'required' => !empty($n['required']),
                        'options' => $opts,
                    ];
                }
            }
            $cfg['fields'] = $fields;
            cr_json(['success' => true, 'config' => $cfg]);
            break;

        case 'setup':
            $cfg = NuCustomerRequests::provision($db, $pid, $userId, $body);
            $audit->log('cr_setup', 'nu_cr_configs', $cfg['cr_id']);
            cr_json(['success' => true, 'config' => $cfg]);
            break;

        case 'save_config':
            $cfg = $requireConfig((int)($body['id'] ?? 0));
            $fields = [];
            if (isset($body['name']))            $fields['cr_name'] = trim((string)$body['name']);
            if (isset($body['description']))     $fields['cr_description'] = (string)$body['description'];
            if (isset($body['icon']))            $fields['cr_icon'] = (string)$body['icon'];
            if (isset($body['reference_prefix']))$fields['cr_reference_prefix'] = trim((string)$body['reference_prefix']);
            if (isset($body['notify_email']))    $fields['cr_notify_email'] = trim((string)$body['notify_email']) ?: null;
            if (isset($body['customer_email_field'])) $fields['cr_customer_email_field'] = trim((string)$body['customer_email_field']) ?: null;
            if (isset($body['customer_name_field']))  $fields['cr_customer_name_field']  = trim((string)$body['customer_name_field']) ?: null;
            if (isset($body['subject_field']))        $fields['cr_subject_field']        = trim((string)$body['subject_field']) ?: null;
            if (isset($body['active']))          $fields['cr_active'] = (int)(bool)$body['active'];
            if (isset($body['public_enabled']))  $fields['cr_public_enabled'] = (int)(bool)$body['public_enabled'];
            if (isset($body['options']) && is_array($body['options'])) {
                $merged = array_merge((array)$cfg['cr_options'], $body['options']);
                $fields['cr_options'] = json_encode($merged);
            }
            if ($fields) {
                $db->update('nu_cr_configs', $fields, 'cr_id = ? AND project_id = ?', [$cfg['cr_id'], $pid]);
            }
            // keep the public share in step with notify email / enabled flag
            if (isset($body['notify_email']) || isset($body['public_enabled'])) {
                $share = $db->fetchOne('SELECT fs_id FROM nu_form_shares WHERE project_id = ? AND fs_form_code = ?', [$pid, $cfg['cr_form_code']]);
                if ($share) {
                    $su = [];
                    if (isset($body['notify_email']))   $su['fs_notify_email'] = trim((string)$body['notify_email']) ?: null;
                    if (isset($body['public_enabled'])) $su['fs_enabled'] = (int)(bool)$body['public_enabled'];
                    if ($su) $db->update('nu_form_shares', $su, 'fs_id = ?', [$share['fs_id']]);
                }
            }
            $audit->log('cr_save_config', 'nu_cr_configs', $cfg['cr_id']);
            cr_json(['success' => true, 'config' => NuCustomerRequests::getConfig($db, $pid, (int)$cfg['cr_id'])]);
            break;

        case 'delete_config':
            $cfg = $requireConfig((int)($body['id'] ?? 0));
            $db->delete('nu_cr_configs', 'cr_id = ? AND project_id = ?', [$cfg['cr_id'], $pid]);
            $audit->log('cr_delete_config', 'nu_cr_configs', $cfg['cr_id']);
            cr_json(['success' => true, 'note' => 'Channel removed. The form, table and workflow were kept — delete them separately if you no longer need them.']);
            break;

        /* ── Tracking board ──────────────────────────────────────────────── */
        case 'board':
            $cfg = $requireConfig((int)($_GET['id'] ?? 0));
            $tMeta = $tableMeta((string)$cfg['cr_table']);
            $table = $tMeta['table'];
            $pkCol = $tMeta['pk'];
            $hasCreated = isset($tMeta['columns']['created_at']);
            $orderClause = '';
            if ($hasCreated && $pkCol) {
                $orderClause = "ORDER BY `created_at` DESC, `{$pkCol}` DESC";
            } elseif ($hasCreated) {
                $orderClause = "ORDER BY `created_at` DESC";
            } elseif ($pkCol) {
                $orderClause = "ORDER BY `{$pkCol}` DESC";
            }

            $search = trim((string)($_GET['q'] ?? ''));
            $query = 'SELECT * FROM `' . $table . '` ' . $orderClause . ' LIMIT 1000';
            $records = $db->fetchAll($query);
            $instances = $instancesForTable($table);

            // Build columns from workflow stages (fallback: a single "All" column).
            $columns = [];
            $stageByCode = [];
            if (!empty($cfg['stages'])) {
                foreach ($cfg['stages'] as $s) {
                    $columns[(string)$s['wfs_code']] = [
                        'code' => $s['wfs_code'], 'name' => $s['wfs_name'], 'color' => $s['wfs_color'],
                        'is_end' => (int)$s['wfs_is_end'], 'sla_hours' => $s['wfs_sla_hours'], 'cards' => [],
                    ];
                    $stageByCode[(string)$s['wfs_code']] = $s;
                }
            } else {
                $columns['all'] = ['code' => 'all', 'name' => 'All requests', 'color' => '#6366f1', 'is_end' => 0, 'sla_hours' => null, 'cards' => []];
            }

            $nameField  = (string)($cfg['cr_customer_name_field'] ?? '');
            $emailField = (string)($cfg['cr_customer_email_field'] ?? '');
            $subjField  = (string)($cfg['cr_subject_field'] ?? '');

            foreach ($records as $rec) {
                $rid = $recordId($rec, $pkCol);
                $inst = $instances[$rid] ?? null;
                $colCode = $inst ? (string)$inst['wfs_code'] : (array_key_first($columns));
                if (!isset($columns[$colCode])) $colCode = array_key_first($columns);

                $hay = strtolower(json_encode($rec));
                if ($search !== '' && strpos($hay, strtolower($search)) === false) continue;

                $age = $hoursSince((string)($rec['created_at'] ?? ''));
                $sla = $inst ? ($inst['wfs_sla_hours'] ?? null) : null;
                $stageAge = $inst ? $hoursSince((string)$inst['wfi_started_at']) : null;
                $overdue = ($sla !== null && $stageAge !== null && $stageAge > (float)$sla);

                $columns[$colCode]['cards'][] = [
                    'id'        => $rid,
                    'reference' => $reference($cfg, $rec, $pkCol),
                    'customer'  => $nameField && isset($rec[$nameField]) ? (string)$rec[$nameField] : '',
                    'email'     => $emailField && isset($rec[$emailField]) ? (string)$rec[$emailField] : '',
                    'subject'   => $subjField && isset($rec[$subjField]) ? (string)$rec[$subjField] : '',
                    'status'    => $inst ? (string)$inst['wfs_name'] : 'Unmanaged',
                    'status_color' => $inst ? (string)$inst['wfs_color'] : '#94a3b8',
                    'created_at'=> (string)($rec['created_at'] ?? ''),
                    'age_hours' => $age,
                    'overdue'   => $overdue,
                ];
            }
            cr_json(['success' => true, 'columns' => array_values($columns), 'count' => count($records)]);
            break;

        /* ── One request (detail) ────────────────────────────────────────── */
        case 'request':
            $cfg = $requireConfig((int)($_GET['id'] ?? 0));
            $rid = (string)($_GET['record'] ?? '');
            $tMeta = $tableMeta((string)$cfg['cr_table']);
            $table = $tMeta['table'];
            $pkCol = $tMeta['pk'];
            $rec = null;
            if ($pkCol) {
                $rec = $db->fetchOne('SELECT * FROM `' . $table . '` WHERE `' . $pkCol . '` = ? LIMIT 1', [$rid]);
            }
            if (!$rec && isset($tMeta['columns']['id'])) {
                $rec = $db->fetchOne('SELECT * FROM `' . $table . '` WHERE id = ? LIMIT 1', [$rid]);
            }
            if (!$rec && isset($tMeta['columns']['request_id'])) {
                $rec = $db->fetchOne('SELECT * FROM `' . $table . '` WHERE request_id = ? LIMIT 1', [$rid]);
            }
            if (!$rec) { cr_fail('Request not found.'); break; }

            // workflow instance + history + available actions
            $instanceRow = $db->fetchOne('SELECT * FROM nu_workflow_instances WHERE wfi_record_table = ? AND wfi_record_id = ? ORDER BY wfi_id DESC LIMIT 1', [$table, $rid]);
            $timeline = []; $transitions = []; $instance = null;
            if ($instanceRow) {
                $engine = new WorkflowEngine();
                $instance = $engine->getInstance((int)$instanceRow['wfi_id']);
                foreach ($engine->getHistory((int)$instanceRow['wfi_id']) as $h) {
                    $timeline[] = [
                        'action'  => (string)($h['wfh_action'] ?? ''),
                        'from'    => (string)($h['from_stage'] ?? ''),
                        'to'      => (string)($h['to_stage'] ?? ''),
                        'actor'   => (string)($h['actor_name'] ?? 'System'),
                        'comment' => (string)($h['wfh_comment'] ?? ''),
                        'at'      => (string)($h['wfh_acted_at'] ?? ''),
                    ];
                }
                foreach ($engine->getAvailableTransitions((int)$instanceRow['wfi_id'], $userId) as $t) {
                    $transitions[] = [
                        'id' => (int)$t['wft_id'], 'label' => (string)$t['wft_label'],
                        'action' => (string)$t['wft_action'], 'to' => (string)($t['to_stage_name'] ?? ''),
                        'to_color' => (string)($t['to_stage_color'] ?? '#6366f1'),
                        'can_run' => !empty($t['can_run']), 'blocked_reason' => (string)($t['blocked_reason'] ?? ''),
                        'require_comment' => !empty($t['require_comment']),
                    ];
                }
            }

            // notes
            $notes = $db->fetchAll('SELECT crn_id, crn_note, crn_author_name, crn_pinned, crn_created_at FROM nu_cr_notes WHERE crn_cr_id = ? AND crn_record_id = ? ORDER BY crn_pinned DESC, crn_created_at DESC', [$cfg['cr_id'], $rid]);

            cr_json(['success' => true, 'request' => [
                'record'      => $rec,
                'reference'   => $reference($cfg, $rec, $pkCol),
                'instance_id' => $instanceRow ? (int)$instanceRow['wfi_id'] : 0,
                'status'      => $instance ? (string)($instance['stage_name'] ?? $instance['wfs_name'] ?? '') : 'Unmanaged',
                'status_color'=> $instance ? (string)($instance['stage_color'] ?? $instance['wfs_color'] ?? '#94a3b8') : '#94a3b8',
                'timeline'    => $timeline,
                'transitions' => $transitions,
                'notes'       => $notes,
            ]]);
            break;

        case 'advance':
            $cfg = $requireConfig((int)($body['id'] ?? 0));
            $instanceId = (int)($body['instance_id'] ?? 0);
            $transitionId = (int)($body['transition_id'] ?? 0);
            $comment = trim((string)($body['comment'] ?? ''));
            if (!$instanceId || !$transitionId) { cr_fail('Missing instance or transition.'); break; }
            // ownership check: instance must belong to this project
            $own = $db->fetchOne('SELECT i.wfi_id FROM nu_workflow_instances i JOIN nu_workflows w ON w.wf_id = i.wfi_wf_id WHERE i.wfi_id = ? AND w.project_id = ?', [$instanceId, $pid]);
            if (!$own) { cr_fail('Request not found in this project.'); break; }
            (new WorkflowEngine())->advance($instanceId, $transitionId, $userId, $comment);
            $audit->log('cr_advance', 'nu_workflow_instances', $instanceId);
            cr_json(['success' => true]);
            break;

        case 'add_note':
            $cfg = $requireConfig((int)($body['id'] ?? 0));
            $rid = (string)($body['record'] ?? '');
            $note = trim((string)($body['note'] ?? ''));
            if ($rid === '' || $note === '') { cr_fail('A note is required.'); break; }
            $uname = (string)($_SESSION['nu_user_name'] ?? $_SESSION['username'] ?? 'Staff');
            $db->insert('nu_cr_notes', [
                'project_id' => $pid, 'crn_cr_id' => $cfg['cr_id'], 'crn_record_id' => $rid,
                'crn_note' => $note, 'crn_author_id' => $userId > 0 ? $userId : null,
                'crn_author_name' => $uname, 'crn_pinned' => (int)(bool)($body['pinned'] ?? 0),
            ]);
            cr_json(['success' => true]);
            break;

        case 'create_request':
            $cfg = $requireConfig((int)($body['id'] ?? 0));
            $tMeta = $tableMeta((string)$cfg['cr_table']);
            $table = $tMeta['table'];
            $pkCol = $tMeta['pk'];
            $cols  = $tMeta['columns'];
            $values = is_array($body['values'] ?? null) ? $body['values'] : [];

            $save = [];
            foreach ($values as $k => $v) {
                if (isset($cols[$k]) && $k !== $pkCol) {
                    $save[$k] = is_scalar($v) ? (string)$v : json_encode($v);
                }
            }
            if ($pkCol && isset($cols[$pkCol]) && !isset($save[$pkCol])) {
                $isString = preg_match('/char|text|uuid|string/i', (string)($cols[$pkCol]['type'] ?? ''));
                if ($isString) {
                    $save[$pkCol] = NuCustomerRequests::uuid();
                }
            }
            $now = date('Y-m-d H:i:s');
            if (isset($cols['created_at'])) $save['created_at'] = $now;
            if (isset($cols['updated_at'])) $save['updated_at'] = $now;
            if (!$save) { cr_fail('Nothing to save.'); break; }
            $db->insert($table, $save);
            $newId = ($pkCol && isset($save[$pkCol])) ? (string)$save[$pkCol] : (string)$db->lastInsertId();

            // start the bound workflow for this new record (same as a public submission)
            if (!empty($cfg['cr_workflow_code']) && !empty($cfg['wf_id'])) {
                try { (new WorkflowEngine())->start((int)$cfg['wf_id'], $userId, $table, $newId, ['source' => 'manual']); }
                catch (Throwable $e) { error_log('[cr create workflow] ' . $e->getMessage()); }
            }
            $audit->log('cr_create_request', $table, $newId);
            cr_json(['success' => true, 'id' => $newId]);
            break;

        /* ── Stats ───────────────────────────────────────────────────────── */
        case 'stats':
            $cfg = $requireConfig((int)($_GET['id'] ?? 0));
            $tMeta = $tableMeta((string)$cfg['cr_table']);
            $table = $tMeta['table'];
            $pkCol = $tMeta['pk'];
            $instances = $instancesForTable($table);
            $total = 0; $open = 0; $overdue = 0; $byStage = [];
            foreach ($cfg['stages'] as $s) $byStage[(string)$s['wfs_code']] = ['name' => $s['wfs_name'], 'color' => $s['wfs_color'], 'count' => 0];

            $selectCols = [];
            if ($pkCol) $selectCols[] = '`' . $pkCol . '`';
            if (isset($tMeta['columns']['id']) && !in_array('`id`', $selectCols, true)) $selectCols[] = '`id`';
            if (isset($tMeta['columns']['request_id']) && !in_array('`request_id`', $selectCols, true)) $selectCols[] = '`request_id`';
            if (isset($tMeta['columns']['created_at'])) $selectCols[] = '`created_at`';
            if (empty($selectCols)) $selectCols = ['*'];

            $records = $db->fetchAll('SELECT ' . implode(', ', $selectCols) . ' FROM `' . $table . '` LIMIT 5000');
            foreach ($records as $rec) {
                $total++;
                $rid = $recordId($rec, $pkCol);
                $inst = $instances[$rid] ?? null;
                if ($inst) {
                    $code = (string)$inst['wfs_code'];
                    if (isset($byStage[$code])) $byStage[$code]['count']++;
                    if ((int)($inst['wfi_status'] ?? 0) !== 0 && $inst['wfi_status'] === 'active') $open++;
                    $sla = $inst['wfs_sla_hours'] ?? null;
                    $stageAge = $hoursSince((string)$inst['wfi_started_at']);
                    if ($sla !== null && $stageAge !== null && $stageAge > (float)$sla) $overdue++;
                }
            }
            cr_json(['success' => true, 'stats' => ['total' => $total, 'open' => $open, 'overdue' => $overdue, 'by_stage' => array_values($byStage)]]);
            break;

        default:
            cr_fail('Unknown action: ' . $action, 400);
    }
} catch (InvalidArgumentException $e) {
    cr_fail($e->getMessage());
} catch (Throwable $e) {
    error_log('[customer_requests api] ' . $e->getMessage());
    cr_fail($e->getMessage());
}
