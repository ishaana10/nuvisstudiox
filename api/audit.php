<?php
declare(strict_types=1);
/**
 * Audit trail API (read-only except purge).
 *   GET  ?action=list&page=&per=&<filters>    entries + total
 *   GET  ?action=detail&id=                   one entry with parsed old/new/diff
 *   GET  ?action=stats&<filters>              header numbers and small charts
 *   GET  ?action=facets                       values for filter drop-downs
 *   GET  ?action=export&<filters>             CSV (max 20,000 rows)
 *   POST ?action=verify                       re-check the tamper-evidence chain
 *   POST ?action=purge   {days}               delete entries older than N days (min 30), admin only
 */
require_once dirname(__DIR__) . '/core/module_bootstrap.php';
require_once dirname(__DIR__) . '/core/Audit.php';

header('Content-Type: application/json; charset=utf-8');
function au_out(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_PARTIAL_OUTPUT_ON_ERROR); exit; }

$user = $auth->getCurrentUser();
$role = strtolower((string)($user['usr_role'] ?? ''));
$isAdmin = in_array($role, ['globeadmin', 'admin'], true);
if (!$user || (!$isAdmin && !$auth->hasPermission('audit.view'))) au_out(['success' => false, 'error' => 'Access denied'], 403);

$audit  = new NuAudit();
$action = (string)($_GET['action'] ?? '');

$filters = [];
foreach (['action' => 'f_action', 'table' => 'f_table', 'username' => 'f_user', 'status' => 'f_status', 'ip' => 'f_ip', 'q' => 'q'] as $k => $param) {
    $v = trim((string)($_GET[$param] ?? ''));
    if ($v !== '') $filters[$k] = mb_substr($v, 0, 200);
}
foreach (['from' => 'f_from', 'to' => 'f_to'] as $k => $param) {
    $v = trim((string)($_GET[$param] ?? ''));
    if ($v !== '' && ($ts = strtotime($v)) !== false) $filters[$k] = date('Y-m-d H:i:s', $k === 'to' && strlen($v) <= 10 ? $ts + 86399 : $ts);
}
if (isset($filters['status']) && !in_array($filters['status'], ['success', 'failure'], true)) unset($filters['status']);

function au_row(array $r): array {
    foreach (['audit_old_data', 'audit_new_data', 'audit_diff'] as $k) {
        $r[$k . '_parsed'] = isset($r[$k]) && $r[$k] !== null && $r[$k] !== '' ? json_decode((string)$r[$k], true) : null;
    }
    return $r;
}

switch ($action) {
    case 'list':
        $per  = max(5, min(200, (int)($_GET['per'] ?? 25)));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $total = $audit->getLogCount($filters);
        $rows = $audit->getLogs($filters, $per, ($page - 1) * $per);
        foreach ($rows as &$r) { unset($r['audit_old_data'], $r['audit_new_data']); }   // keep the list light; detail loads them
        au_out(['success' => true, 'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $per))]);

    case 'detail':
        $row = $audit->getLog((int)($_GET['id'] ?? 0));
        if (!$row) au_out(['success' => false, 'error' => 'Entry not found'], 404);
        au_out(['success' => true, 'row' => au_row($row)]);

    case 'stats':
        au_out(['success' => true] + $audit->stats($filters));

    case 'facets':
        au_out(['success' => true] + $audit->facets());

    case 'export':
        if (!$isAdmin) au_out(['success' => false, 'error' => 'Only administrators can export the audit trail.'], 403);
        $audit->event('audit_export', 'Audit trail exported to CSV', ['filters' => $filters]);
        header_remove('Content-Type');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit-trail-' . date('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['id', 'time', 'status', 'action', 'table', 'record', 'user', 'user_id', 'ip', 'request', 'summary', 'changes', 'hash']);
        $safe = function ($v) { $v = (string)$v; return $v !== '' && strpos("=+-@\t\r", $v[0]) !== false ? "'" . $v : $v; };
        for ($off = 0; $off < 20000; $off += 500) {
            $rows = $audit->getLogs($filters, 500, $off);
            if (!$rows) break;
            foreach ($rows as $r) {
                fputcsv($out, [$r['audit_id'], $r['audit_timestamp'], $r['audit_status'] ?? '', $r['audit_action'], $r['audit_table'], $safe($r['audit_record_id']), $safe($r['audit_username']),
                    $r['audit_user_id'], $r['audit_ip'], $safe($r['audit_request'] ?? ''), $safe($r['audit_summary'] ?? ''), $safe($r['audit_diff'] ?? ''), $r['audit_hash'] ?? '']);
            }
        }
        fclose($out);
        exit;

    case 'verify':
    case 'purge':
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') au_out(['success' => false, 'error' => 'POST required'], 405);
        if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === false) au_out(['success' => false, 'error' => 'JSON body required'], 415);
        $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
        if ($action === 'verify') {
            $res = $audit->verifyChain();
            if (!$res['ok']) $audit->event('audit_integrity_failed', 'Audit chain check failed at entry #' . $res['first_bad_id'], $res, 'failure');
            au_out(['success' => true] + $res);
        }
        if (!$isAdmin) au_out(['success' => false, 'error' => 'Only administrators can purge the audit trail.'], 403);
        $days = (int)($body['days'] ?? 0);
        if ($days < 30) au_out(['success' => false, 'error' => 'Entries newer than 30 days cannot be purged.']);
        au_out(['success' => true, 'removed' => $audit->purgeOlderThan($days)]);

    default:
        au_out(['success' => false, 'error' => 'Unknown action'], 400);
}
