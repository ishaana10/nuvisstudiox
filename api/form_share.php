<?php
declare(strict_types=1);

/**
 * Manage the public (no sign-in) link of a form.
 *   GET  ?action=get&code=FORM            current settings, field list, stats, link
 *   POST ?action=save&code=FORM           create / update settings (JSON body)
 *   POST ?action=regenerate&code=FORM     new link, old one stops working
 *   POST ?action=delete&code=FORM         remove the share entirely
 *
 * Needs an admin role or the 'forms' permission. Guest test sessions are never allowed.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Auth.php';
require_once dirname(__DIR__) . '/core/ProjectContext.php';
require_once dirname(__DIR__) . '/core/PublicForm.php';

header('Content-Type: application/json; charset=utf-8');

function fs_audit(string $a, string $code, ?array $share, array $d = []): void { try { require_once dirname(__DIR__) . '/core/Audit.php'; (new NuAudit())->event($a, "Public link for form {$code}", $d + ['form' => $code], 'success', ['table' => 'nu_form_shares', 'record_id' => $share['fs_id'] ?? null]); } catch (Throwable $t) {} }
function fs_out(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d); exit; }

$auth = NuAuth::getInstance();
if (!$auth->checkAuth()) fs_out(['success' => false, 'error' => 'Unauthorized'], 401);
if (($_SESSION['nu_user_id'] ?? '') === 'guest_tester') fs_out(['success' => false, 'error' => 'Not allowed'], 403);

$user = $auth->getCurrentUser();
$role = strtolower((string)(is_array($user) ? ($user['usr_role'] ?? '') : ''));
$allowed = in_array($role, ['admin', 'globeadmin'], true);
if (!$allowed) { try { $allowed = (bool)$auth->hasPermission('forms'); } catch (Throwable $e) { $allowed = false; } }
if (!$allowed) fs_out(['success' => false, 'error' => 'You do not have permission to share forms.'], 403);

$db = NuDatabase::getInstance();
NuPublicForm::ensureSchema($db);
$pid    = ProjectContext::getId();
$action = (string)($_GET['action'] ?? '');
$code   = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($_GET['code'] ?? ''));
$body   = json_decode((string)file_get_contents('php://input'), true);
$body   = is_array($body) ? $body : [];

if ($action !== '' && $action !== 'get' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fs_out(['success' => false, 'error' => 'POST required'], 405);
}
// CSRF: state-changing calls must be JSON (a cross-site <form> cannot send that) and, when a token is sent, it must match.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === false) {
        fs_out(['success' => false, 'error' => 'JSON body required'], 415);
    }
    $tok = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($tok !== '' && !$auth->verifyCsrf($tok)) fs_out(['success' => false, 'error' => 'Invalid security token. Reload the page and try again.'], 403);
}

if ($code === '') fs_out(['success' => false, 'error' => 'Missing form code'], 400);
$form = $db->fetchOne('SELECT * FROM nu_forms WHERE form_code = ? AND project_id = ? LIMIT 1', [$code, $pid]);
if (!$form) fs_out(['success' => false, 'error' => 'Form not found in this project'], 404);

function fs_base_url(): string
{
    global $nuConfig;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir  = rtrim(str_replace('\\', '/', dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/api/x.php')))), '/');
    return ($https ? 'https://' : 'http://') . $host . $dir;
}

function fs_describe(NuDatabase $db, array $form, ?array $share): array
{
    $fields = [];
    $selected = $share ? json_decode((string)($share['fs_fields'] ?? ''), true) : null;
    $selected = is_array($selected) && $selected ? array_flip(array_map('strval', $selected)) : null;
    $cfg = $share ? NuPublicForm::fieldCfg($share) : [];
    foreach (NuPublicForm::describeFields($form) as $d) {
        unset($d['raw']);
        $d['selected'] = $d['public'] && ($selected === null || isset($selected[$d['name']]));
        $d['cfg'] = $cfg[$d['name']] ?? new stdClass();   // overrides: label, placeholder, help, required, col, default
        $fields[] = $d;
    }
    $out = ['fields' => $fields, 'form_name' => $form['form_name'], 'form_code' => $form['form_code']];
    if ($share) {
        $out['share'] = [
            'enabled'         => (int)$share['fs_enabled'],
            'title'           => (string)($share['fs_title'] ?? ''),
            'intro'           => (string)($share['fs_intro'] ?? ''),
            'submit_label'    => (string)($share['fs_submit_label'] ?? ''),
            'success_message' => (string)($share['fs_success_message'] ?? ''),
            'redirect_url'    => (string)($share['fs_redirect_url'] ?? ''),
            'notify_email'    => (string)($share['fs_notify_email'] ?? ''),
            'confirm_email'   => (int)$share['fs_confirm_email'],
            'email_field'     => (string)($share['fs_email_field'] ?? ''),
            'confirm_message' => (string)($share['fs_confirm_message'] ?? ''),
            'expires_at'      => $share['fs_expires_at'] ? date('Y-m-d\TH:i', strtotime((string)$share['fs_expires_at'])) : '',
            'max_submissions' => $share['fs_max_submissions'] !== null ? (int)$share['fs_max_submissions'] : null,
            'submit_count'    => (int)$share['fs_submit_count'],
            'theme'           => NuPublicForm::theme($share),
            'state'           => NuPublicForm::state($share, $form),
            'url'             => fs_base_url() . '/public_form.php?t=' . $share['fs_token'],
        ];
        $out['recent'] = $db->fetchAll('SELECT fl_record_id AS record_id, fl_at AS at FROM nu_form_share_log WHERE fl_fs_id = ? ORDER BY fl_id DESC LIMIT 5', [$share['fs_id']]);
    } else {
        $out['share'] = null;
    }
    return $out;
}

$share = NuPublicForm::shareForForm($db, $pid, $code);

switch ($action) {
    case 'get':
        fs_out(['success' => true] + fs_describe($db, $form, $share));

    case 'save':
        $title = trim((string)($body['title'] ?? ''));
        $intro = trim((string)($body['intro'] ?? ''));
        $label = trim((string)($body['submit_label'] ?? ''));
        $okMsg = trim((string)($body['success_message'] ?? ''));
        $redir = trim((string)($body['redirect_url'] ?? ''));
        $emails = trim((string)($body['notify_email'] ?? ''));
        if (mb_strlen($title) > 200 || mb_strlen($label) > 60 || mb_strlen($intro) > 5000 || mb_strlen($okMsg) > 2000) {
            fs_out(['success' => false, 'error' => 'One of the texts is too long.']);
        }
        if ($redir !== '' && (!preg_match('#^https?://#i', $redir) || !filter_var($redir, FILTER_VALIDATE_URL) || mb_strlen($redir) > 500)) {
            fs_out(['success' => false, 'error' => 'The redirect address must be a full http(s) URL.']);
        }
        $list = preg_split('/[,;\s]+/', $emails, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($list as $e) if (!filter_var($e, FILTER_VALIDATE_EMAIL)) fs_out(['success' => false, 'error' => "\"$e\" is not a valid email address."]);
        $emails = implode(', ', $list);

        $expires = null;
        if (!empty($body['expires_at'])) {
            $ts = strtotime((string)$body['expires_at']);
            if ($ts === false) fs_out(['success' => false, 'error' => 'Invalid expiry date.']);
            $expires = date('Y-m-d H:i:s', $ts);
        }
        $max = null;
        if (isset($body['max_submissions']) && $body['max_submissions'] !== '' && $body['max_submissions'] !== null) {
            $max = (int)$body['max_submissions'];
            if ($max < 1 || $max > 1000000) fs_out(['success' => false, 'error' => 'The submission limit must be 1 or more.']);
        }

        // Selected fields: must be known public-capable names; "all" is stored as NULL.
        $pub = [];
        foreach (NuPublicForm::describeFields($form) as $d) if ($d['public']) $pub[] = $d['name'];
        $sel = null;
        if (isset($body['fields']) && is_array($body['fields'])) {
            $pick = array_values(array_intersect($pub, array_map('strval', $body['fields'])));
            if (!$pick && !empty($body['enabled'])) fs_out(['success' => false, 'error' => 'Choose at least one field to show.']);
            $sel = count($pick) === count($pub) ? null : $pick;
        }

        $vals = [
            'fs_enabled' => !empty($body['enabled']) ? 1 : 0,
            'fs_title' => $title, 'fs_intro' => $intro, 'fs_submit_label' => $label,
            'fs_success_message' => $okMsg, 'fs_redirect_url' => $redir,
            'fs_notify_email' => $emails, 'fs_confirm_email' => !empty($body['confirm_email']) ? 1 : 0,
            'fs_email_field' => in_array((string)($body['email_field'] ?? ''), $pub, true) ? (string)$body['email_field'] : null,
            'fs_confirm_message' => mb_substr(trim((string)($body['confirm_message'] ?? '')), 0, 2000),
            'fs_fields' => $sel === null ? null : json_encode($sel),
            'fs_expires_at' => $expires, 'fs_max_submissions' => $max,
            'fs_updated_at' => date('Y-m-d H:i:s'),
        ];
        if (isset($body['theme']) && is_array($body['theme'])) {
            $vals['fs_theme'] = json_encode(NuPublicForm::cleanTheme($body['theme']));
        }
        if (isset($body['field_cfg']) && is_array($body['field_cfg'])) {
            $vals['fs_field_cfg'] = json_encode(NuPublicForm::cleanFieldCfg($body['field_cfg'], $pub), JSON_FORCE_OBJECT);
        }
        if ($share) {
            $set = []; $p = [];
            foreach ($vals as $k => $v) { $set[] = "`$k` = ?"; $p[] = $v; }
            $p[] = $share['fs_id'];
            $db->query('UPDATE nu_form_shares SET ' . implode(', ', $set) . ' WHERE fs_id = ?', $p);
        } else {
            $vals += [
                'project_id' => $pid, 'fs_form_code' => $code,
                'fs_token' => NuPublicForm::newToken(), 'fs_secret' => bin2hex(random_bytes(24)),
                'fs_created_by' => (string)($_SESSION['nu_user_id'] ?? ''),
            ];
            $cols = array_keys($vals);
            $db->query('INSERT INTO nu_form_shares (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($vals));
        }
        $share = NuPublicForm::shareForForm($db, $pid, $code);
        fs_audit('form_share_save', $code, $share, ['enabled' => (int)($share['fs_enabled'] ?? 0)]);
        fs_out(['success' => true] + fs_describe($db, $form, $share));

    case 'regenerate':
        if (!$share) fs_out(['success' => false, 'error' => 'Nothing to regenerate yet.']);
        $db->query('UPDATE nu_form_shares SET fs_token = ?, fs_secret = ?, fs_updated_at = ? WHERE fs_id = ?',
            [NuPublicForm::newToken(), bin2hex(random_bytes(24)), date('Y-m-d H:i:s'), $share['fs_id']]);
        $share = NuPublicForm::shareForForm($db, $pid, $code);
        fs_audit('form_share_regenerate', $code, $share);
        fs_out(['success' => true] + fs_describe($db, $form, $share));

    case 'delete':
        if ($share) {
            $db->query('DELETE FROM nu_form_share_log WHERE fl_fs_id = ?', [$share['fs_id']]);
            $db->query('DELETE FROM nu_form_shares WHERE fs_id = ?', [$share['fs_id']]);
        }
        fs_audit('form_share_delete', $code, null);
        fs_out(['success' => true] + fs_describe($db, $form, null));

    default:
        fs_out(['success' => false, 'error' => 'Unknown action'], 400);
}
