<?php
declare(strict_types=1);

/**
 * Public (no sign-in) page for a shared form:  public_form.php?t=<share token>
 *
 * GET  → renders the form.
 * POST → validates and stores a new record (INSERT only), then answers JSON or an HTML page.
 *
 * Nothing on this page reads existing records or touches the visitor's session.
 */

$boot = null;
try {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/core/Database.php';
    require_once __DIR__ . '/core/PublicForm.php';
    $db = NuDatabase::getInstance();
    NuPublicForm::ensureSchema($db);
} catch (Throwable $e) {
    error_log('[public_form boot] ' . $e->getMessage());
    $boot = 'unavailable';
}

function pf_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$nonce = base64_encode(random_bytes(16));
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'nonce-$nonce'; script-src 'nonce-$nonce'; connect-src 'self'; form-action 'self'; base-uri 'none'; img-src data: https:");

$token = (string)($_GET['t'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ctype = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
$wantsJson = strpos($ctype, 'application/json') !== false || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;

$share = null; $form = null; $state = 'missing';
if ($boot === null) {
    $share = NuPublicForm::shareByToken($db, $token);
    if ($share) {
        $form = NuPublicForm::loadForm($db, (int)$share['project_id'], (string)$share['fs_form_code']);
        $state = NuPublicForm::state($share, $form);
    }
}

function pf_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// ─── POST: submit ────────────────────────────────────────────────────────────
if ($method === 'POST') {
    if ($boot !== null) {
        $wantsJson ? pf_json(['success' => false, 'message' => 'Service unavailable.'], 503) : null;
    }
    $input = [];
    if (strpos($ctype, 'application/json') !== false) {
        $d = json_decode((string)file_get_contents('php://input', false, null, 0, 262144), true);
        $input = is_array($d) ? $d : [];
    } else {
        $input = $_POST;
    }
    if (!$share || !$form || $state !== 'ok') {
        $msg = $share ? NuPublicForm::stateMessage($state) : 'This link is not valid.';
        if ($wantsJson) pf_json(['success' => false, 'message' => $msg], $share ? 410 : 404);
        $result = ['ok' => false, 'message' => $msg, 'status' => $share ? 410 : 404];
    } else {
        $result = NuPublicForm::submit($db, $share, $form, $input);
        if ($wantsJson) {
            $out = [
                'success'   => !empty($result['ok']),
                'message'   => $result['message'] ?? '',
                'reference' => $result['reference'] ?? '',
                'errors'    => $result['errors'] ?? new stdClass(),
                'redirect'  => $result['redirect'] ?? '',
            ];
            if (!empty($result['detail']) && !empty($_SESSION['nu_role']) && in_array(strtolower((string)$_SESSION['nu_role']), ['admin', 'globeadmin'], true)) {
                $out['detail'] = (string)$result['detail'];   // technical reason, shown only to signed-in admins
            }
            if (!empty($result['retry'])) $out['token'] = NuPublicForm::signToken($share);
            pf_json($out, !empty($result['ok']) ? 200 : (int)($result['status'] ?? 400));
        }
    }
    // Plain HTML post (JavaScript disabled): show the outcome as a page.
    http_response_code(!empty($result['ok']) ? 200 : (int)($result['status'] ?? 400));
    $page = ['kind' => !empty($result['ok']) ? 'done' : 'error', 'message' => $result['message'] ?? '', 'reference' => $result['reference'] ?? ''];
} elseif ($state !== 'ok') {
    http_response_code($share ? 410 : 404);
    $page = ['kind' => 'error', 'message' => $share ? NuPublicForm::stateMessage($state) : 'This link is not valid.', 'reference' => ''];
} else {
    $page = ['kind' => 'form'];
}

$theme = $share ? NuPublicForm::theme($share) : NuPublicForm::THEME_DEFAULTS;
$title = $share && $form ? (trim((string)$share['fs_title']) ?: (string)$form['form_name']) : 'Form';
$fields = ($page['kind'] === 'form' && $share && $form) ? NuPublicForm::visibleFields($form, $share) : [];
$tree = $fields ? NuPublicForm::layoutTree($form, $fields) : [];
$fieldMap = [];
foreach ($fields as $_f) $fieldMap[$_f['name']] = $_f;
$isAdminViewer = in_array(strtolower((string)($_SESSION['nu_role'] ?? '')), ['admin', 'globeadmin'], true);
$formToken = ($page['kind'] === 'form') ? NuPublicForm::signToken($share) : '';
$submitLabel = $share ? (trim((string)$share['fs_submit_label']) ?: 'Submit request') : 'Submit';

/** Render one input control (value = default from settings, or ?field=value from the link). */
function pf_field(array $f, string $val): string
{
    $n = $f['name']; $id = 'f_' . $n; $t = $f['type'];
    $req = $f['required'] ? ' required' : '';
    $ph = $f['placeholder'] !== '' ? ' placeholder="' . pf_h($f['placeholder']) . '"' : '';
    $reqMark = $f['required'] ? ' <span class="req" aria-hidden="true">*</span>' : '';
    $vals = array_map('trim', explode(',', $val));
    $h = '<div class="f c' . (int)$f['col'] . '" data-f="' . pf_h($n) . '">';
    if ($t === 'checkbox') {
        $h .= '<label class="opt"><input type="checkbox" id="' . pf_h($id) . '" name="' . pf_h($n) . '" value="1"' . $req . (in_array($val, ['1', 'true', 'yes', 'on'], true) ? ' checked' : '') . '> <span>' . pf_h($f['label']) . $reqMark . '</span></label>';
    } elseif ($t === 'radio' || $t === 'checkbox_group') {
        $multi = $t === 'checkbox_group';
        $h .= '<div class="lbl" id="' . pf_h($id) . '_l">' . pf_h($f['label']) . $reqMark . '</div><div class="opts" role="' . ($multi ? 'group' : 'radiogroup') . '" aria-labelledby="' . pf_h($id) . '_l">';
        foreach ($f['options'] as $o) {
            $on = in_array((string)$o['value'], $vals, true) && $val !== '' ? ' checked' : '';
            $h .= '<label class="opt"><input type="' . ($multi ? 'checkbox' : 'radio') . '" name="' . pf_h($n) . ($multi ? '[]' : '') . '" value="' . pf_h($o['value']) . '"' . (!$multi ? $req : '') . $on . '> ' . pf_h($o['label']) . '</label>';
        }
        $h .= '</div>';
    } else {
        $h .= '<label for="' . pf_h($id) . '">' . pf_h($f['label']) . $reqMark . '</label>';
        if ($t === 'textarea') {
            $h .= '<textarea id="' . pf_h($id) . '" name="' . pf_h($n) . '"' . $req . $ph . '>' . pf_h($val) . '</textarea>';
        } elseif ($t === 'select') {
            $h .= '<select id="' . pf_h($id) . '" name="' . pf_h($n) . '"' . $req . '><option value="">Select…</option>';
            foreach ($f['options'] as $o) $h .= '<option value="' . pf_h($o['value']) . '"' . ($val !== '' && (string)$o['value'] === $val ? ' selected' : '') . '>' . pf_h($o['label']) . '</option>';
            $h .= '</select>';
        } else {
            $it = ['phone' => 'tel', 'datetime' => 'datetime-local'][$t] ?? $t;
            $extra = '';
            if ($t === 'number') {
                if ($f['min'] !== null && $f['min'] !== '') $extra .= ' min="' . pf_h($f['min']) . '"';
                if ($f['max'] !== null && $f['max'] !== '') $extra .= ' max="' . pf_h($f['max']) . '"';
                $extra .= ' step="any" inputmode="decimal"';
            }
            if ($t === 'email') $extra .= ' autocomplete="email" inputmode="email"';
            if ($t === 'tel' || $t === 'phone') $extra .= ' autocomplete="tel" inputmode="tel"';
            if ($t === 'datetime' && $val !== '') $val = str_replace(' ', 'T', substr($val, 0, 16));
            $h .= '<input type="' . pf_h($it) . '" id="' . pf_h($id) . '" name="' . pf_h($n) . '" value="' . pf_h($val) . '"' . $req . $ph . $extra . '>';
        }
    }
    if ($f['help'] !== '') $h .= '<div class="help">' . pf_h($f['help']) . '</div>';
    return $h . '<div class="err" aria-live="polite"></div></div>';
}

function pf_nodes(array $nodes, array $fieldMap, array $theme, array $prefill): string
{
    static $uid = 0;
    $h = '';
    foreach ($nodes as $n) {
        switch ($n['kind']) {
            case 'row':
                $h .= '<div class="grid">';
                foreach ($n['items'] as $it) {
                    $f = $fieldMap[$it['name']] ?? null;
                    if (!$f) continue;
                    $v = $prefill[$f['name']] ?? $f['default'];
                    $h .= pf_field($f, (string)$v);
                }
                $h .= '</div>';
                break;
            case 'section':
            case 'group':
                $id = 'cn' . (++$uid);
                $coll = !empty($n['collapsible']);
                $h .= '<section class="cn cn-' . pf_h($theme['section_style']) . ' ' . ($n['kind'] === 'group' ? 'is-group' : 'is-section') . ($coll && !empty($n['collapsed']) ? ' closed' : '') . '">';
                if ($n['label'] !== '') {
                    $h .= $coll
                        ? '<button type="button" class="cn-h" data-toggle="' . $id . '" aria-expanded="' . (!empty($n['collapsed']) ? 'false' : 'true') . '"><span>' . pf_h($n['label']) . '</span><i aria-hidden="true">▾</i></button>'
                        : '<div class="cn-h"><span>' . pf_h($n['label']) . '</span></div>';
                }
                $h .= '<div class="cn-b" id="' . $id . '">' . pf_nodes($n['children'], $fieldMap, $theme, $prefill) . '</div></section>';
                break;
            case 'tabs':
                $id = 'tb' . (++$uid);
                $h .= '<div class="tabs tabs-' . pf_h($theme['tab_style']) . '" id="' . $id . '"><div class="tab-bar" role="tablist">';
                foreach ($n['tabs'] as $i => $tab) {
                    $h .= '<button type="button" role="tab" class="tab-btn' . ($i === 0 ? ' on' : '') . '" data-tab="' . $id . '-' . $i . '" aria-selected="' . ($i === 0 ? 'true' : 'false') . '">' . pf_h($tab['label']) . '</button>';
                }
                $h .= '</div>';
                foreach ($n['tabs'] as $i => $tab) {
                    $h .= '<div class="tab-panel' . ($i === 0 ? ' on' : '') . '" role="tabpanel" id="' . $id . '-' . $i . '">' . pf_nodes($tab['children'], $fieldMap, $theme, $prefill) . '</div>';
                }
                $h .= '</div>';
                break;
            case 'heading':
                $lv = (int)$n['level'];
                $h .= '<h' . $lv . ' class="sub">' . pf_h($n['label']) . '</h' . $lv . '>';
                break;
            case 'html':
                $h .= '<div class="rich">' . $n['html'] . '</div>';   // sanitised in NuPublicForm::sanitizeHtml
                break;
            case 'divider':
                $h .= '<hr class="div">';
                break;
        }
    }
    return $h;
}

$prefill = [];
if ($page['kind'] === 'form') {
    foreach ($fieldMap as $_n => $_f) {
        if (isset($_GET[$_n]) && is_string($_GET[$_n]) && mb_strlen($_GET[$_n]) <= 500) $prefill[$_n] = $_GET[$_n];
    }
}
$mode = $theme['mode'];
?><!doctype html>
<html lang="en" data-mode="<?= pf_h($mode) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= pf_h($title) ?></title>
<style nonce="<?= pf_h($nonce) ?>">
:root{--bg:#f4f6fb;--card:#fff;--text:#14213d;--muted:#5b6785;--border:#d5dbea;--primary:#2563eb;--primary-d:#1d4ed8;--danger:#dc2626;--ok:#16a34a;--shadow:0 10px 30px rgba(20,33,61,.08)}
html[data-mode=auto]{color-scheme:light dark}
@media (prefers-color-scheme:dark){html[data-mode=auto]{--bg:#0e1424;--card:#161e33;--text:#e8ecf8;--muted:#9aa6c6;--border:#2b3656;--primary:#4f8bff;--primary-d:#3b78ee;--danger:#f87171;--ok:#4ade80;--shadow:0 10px 30px rgba(0,0,0,.4)}}
html[data-mode=dark]{--bg:#0e1424;--card:#161e33;--text:#e8ecf8;--muted:#9aa6c6;--border:#2b3656;--primary:#4f8bff;--primary-d:#3b78ee;--danger:#f87171;--ok:#4ade80;--shadow:0 10px 30px rgba(0,0,0,.4)}
<?= NuPublicForm::themeCss($theme) ?>

*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:16px/1.5 var(--font,system-ui,sans-serif);padding:24px 16px}
.wrap{max-width:var(--w,680px);margin:0 auto}
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--r-card,16px);box-shadow:var(--shadow);padding:var(--pad,28px)}
.logo{display:block;max-width:100%;height:auto;margin:0 0 14px}
.logo.center{margin-left:auto;margin-right:auto}
.head.center{text-align:center}
h1{font-size:24px;line-height:1.25;margin:0 0 6px}
.intro{color:var(--muted);margin:0 0 22px;white-space:pre-line}
.grid{display:grid;grid-template-columns:repeat(12,1fr);gap:var(--gap,16px);margin-bottom:var(--gap,16px)}
.f{grid-column:span 12;min-width:0}
@media (min-width:600px){.c1{grid-column:span 1}.c2{grid-column:span 2}.c3{grid-column:span 3}.c4{grid-column:span 4}.c5{grid-column:span 5}.c6{grid-column:span 6}.c7{grid-column:span 7}.c8{grid-column:span 8}.c9{grid-column:span 9}.c10{grid-column:span 10}.c11{grid-column:span 11}}
label,.lbl{display:block;font-weight:600;font-size:14px;margin-bottom:6px}
.req{color:var(--danger)}
input[type=text],input[type=email],input[type=tel],input[type=url],input[type=number],input[type=date],input[type=time],input[type=datetime-local],select,textarea{width:100%;font:inherit;color:inherit;background:transparent;border:1px solid var(--border);border-radius:var(--r-in,10px);padding:10px 13px;min-height:var(--in-h,44px)}
textarea{min-height:110px;resize:vertical}
input:focus,select:focus,textarea:focus,button:focus-visible{outline:2px solid var(--primary);outline-offset:1px;border-color:var(--primary)}
.f.bad input,.f.bad select,.f.bad textarea{border-color:var(--danger)}
.opts{display:flex;flex-direction:column;gap:8px}
.opt{display:flex;align-items:center;gap:10px;font-weight:400;font-size:15px;margin:0;min-height:32px}
.opt input{width:20px;height:20px;accent-color:var(--primary)}
.help{font-size:12.5px;color:var(--muted);margin-top:5px}
.err{font-size:13px;color:var(--danger);margin-top:5px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;background:var(--primary);color:#fff;border:0;border-radius:var(--r-in,10px);padding:12px 22px;font:inherit;font-weight:600;cursor:pointer;min-height:46px}
.btn:hover{background:var(--primary-d)}
.btn[disabled]{opacity:.65;cursor:progress}
.actions{margin-top:24px;display:flex;gap:14px}
.actions.center{justify-content:center}.actions.right{justify-content:flex-end}
.actions.full .btn{width:100%}
.banner{border-radius:var(--r-in,10px);padding:12px 14px;margin-bottom:18px;font-size:14px;border:1px solid var(--danger);color:var(--danger);display:none}
.banner.show{display:block}
.banner small{display:block;margin-top:6px;color:var(--muted);font-family:ui-monospace,Menlo,monospace;word-break:break-word}
.done,.stop{text-align:center;padding:12px 4px}
.tick{width:56px;height:56px;border-radius:50%;background:color-mix(in srgb,var(--ok) 18%,transparent);color:var(--ok);display:grid;place-items:center;font-size:30px;margin:0 auto 14px}
.stop .tick{background:color-mix(in srgb,var(--muted) 18%,transparent);color:var(--muted)}
.ref{display:inline-block;margin-top:8px;padding:6px 12px;border:1px dashed var(--border);border-radius:8px;font-family:ui-monospace,Menlo,monospace}
.hide{display:none}.mb0{margin-bottom:0!important}
.hp{position:absolute!important;left:-9999px;width:1px;height:1px;overflow:hidden}
.foot{text-align:center;color:var(--muted);font-size:12px;margin-top:16px}
.spin{width:16px;height:16px;border:2px solid #fff6;border-top-color:#fff;border-radius:50%;animation:s .7s linear infinite;display:none}
.btn[disabled] .spin{display:inline-block}
@keyframes s{to{transform:rotate(360deg)}}
/* layout containers */
.cn{margin:0 0 var(--gap,16px)}
.cn-h{display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;font:inherit;font-weight:700;font-size:15px;color:inherit;background:none;border:0;padding:0;text-align:left}
button.cn-h{cursor:pointer}
button.cn-h i{font-style:normal;transition:transform .2s}
.cn.closed .cn-b{display:none}.cn.closed button.cn-h i{transform:rotate(-90deg)}
.cn-boxed{border:1px solid var(--border);border-radius:var(--r-in,10px);overflow:hidden}
.cn-boxed .cn-h{padding:12px 16px;background:color-mix(in srgb,var(--primary) 7%,transparent);border-bottom:1px solid var(--border)}
.cn-boxed.closed .cn-h{border-bottom:0}
.cn-boxed .cn-b{padding:16px}
.cn-boxed .cn-b>.grid:last-child{margin-bottom:0}
.cn-flat .cn-h{padding:0 0 8px;border-bottom:2px solid var(--primary);margin-bottom:var(--gap,16px)}
.cn-plain .cn-h{padding:0 0 6px;margin-bottom:6px;color:var(--muted);font-size:13px;text-transform:uppercase;letter-spacing:.05em}
.tabs{margin:0 0 var(--gap,16px)}
.tab-bar{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:var(--gap,16px)}
.tab-btn{font:inherit;font-size:14px;font-weight:600;color:var(--muted);background:none;border:0;padding:10px 16px;cursor:pointer;min-height:44px}
.tab-btn.on{color:var(--primary)}
.tabs-underline .tab-bar{border-bottom:2px solid var(--border)}
.tabs-underline .tab-btn{border-bottom:2px solid transparent;margin-bottom:-2px}
.tabs-underline .tab-btn.on{border-bottom-color:var(--primary)}
.tabs-pills .tab-btn{border-radius:99px}
.tabs-pills .tab-btn.on{background:color-mix(in srgb,var(--primary) 14%,transparent)}
.tab-panel{display:none}.tab-panel.on{display:block}
h2.sub,h3.sub,h4.sub{margin:6px 0 10px;font-size:17px}
.rich{margin:0 0 var(--gap,16px);color:var(--muted);font-size:15px}
.rich p{margin:0 0 8px}.rich a{color:var(--primary)}
hr.div{border:0;border-top:1px solid var(--border);margin:var(--gap,16px) 0}
@media (max-width:599px){input,select,textarea{font-size:16px}.tab-btn{padding:10px 12px}}
<?= $theme['custom_css'] ?>
</style>
</head>
<body>
<div class="wrap"><div class="card">
<?php if ($page['kind'] === 'done'): ?>
  <div class="done" role="status">
    <div class="tick">✓</div>
    <h1>Request received</h1>
    <p class="intro mb0"><?= pf_h($page['message']) ?></p>
    <?php if (!empty($page['reference'])): ?><div class="ref">Reference: <?= pf_h($page['reference']) ?></div><?php endif; ?>
  </div>
<?php elseif ($page['kind'] === 'error'): ?>
  <div class="stop" role="alert">
    <div class="tick">!</div>
    <h1>Not available</h1>
    <p class="intro mb0"><?= pf_h($page['message']) ?></p>
  </div>
<?php else: ?>
  <?php $center = $theme['title_align'] === 'center'; ?>
  <div class="head<?= $center ? ' center' : '' ?>" id="head">
    <?php if ($theme['logo_url'] !== ''): ?><img class="logo<?= $center ? ' center' : '' ?>" src="<?= pf_h($theme['logo_url']) ?>" alt="" height="<?= (int)$theme['logo_height'] ?>"><?php endif; ?>
    <?php if (!empty($theme['show_title'])): ?><h1><?= pf_h($title) ?></h1><?php endif; ?>
    <?php if (trim((string)$share['fs_intro']) !== ''): ?><p class="intro"><?= pf_h($share['fs_intro']) ?></p><?php endif; ?>
  </div>
  <div id="banner" class="banner" role="alert"></div>
  <form id="pf" method="post" action="?t=<?= pf_h(rawurlencode($token)) ?>" novalidate>
    <input type="hidden" name="_t" value="<?= pf_h($formToken) ?>">
    <div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
    <?= pf_nodes($tree, $fieldMap, $theme, $prefill) ?>
    <?php if (!$fields): ?><p class="intro">This form has no fields to fill in yet.</p><?php endif; ?>
    <div class="actions <?= pf_h($theme['button_align']) ?><?= $theme['button_full'] ? ' full' : '' ?>">
      <button class="btn" type="submit" id="go"<?= $fields ? '' : ' disabled' ?>><span class="spin"></span><span><?= pf_h($submitLabel) ?></span></button>
    </div>
  </form>
  <div id="ok" class="done hide" role="status">
    <div class="tick">✓</div>
    <h1>Request received</h1>
    <p class="intro mb0" id="okmsg"></p>
    <div class="ref hide" id="okref"></div>
  </div>
<?php endif; ?>
</div>
<?php if (empty($theme['hide_footer'])): ?>
<div class="foot"><?= $theme['footer_text'] !== '' ? pf_h($theme['footer_text']) : 'Your answers are sent securely to the form owner.' ?></div>
<?php endif; ?>
</div>
<?php if ($page['kind'] === 'form'): ?>
<script nonce="<?= pf_h($nonce) ?>">
(function(){
  var form=document.getElementById('pf'), btn=document.getElementById('go'), banner=document.getElementById('banner');
  // Tabs
  function showTab(btnEl){
    var bar=btnEl.parentNode, wrap=bar.parentNode;
    bar.querySelectorAll('.tab-btn').forEach(function(b){var on=b===btnEl;b.classList.toggle('on',on);b.setAttribute('aria-selected',on?'true':'false');});
    wrap.querySelectorAll(':scope > .tab-panel').forEach(function(p){p.classList.toggle('on',p.id===btnEl.getAttribute('data-tab'));});
  }
  // Make sure a field's container(s) are visible so errors can be seen
  function reveal(el){
    var n=el;
    while(n&&n!==form){
      if(n.classList&&n.classList.contains('tab-panel')&&!n.classList.contains('on')){var b=document.querySelector('[data-tab="'+n.id+'"]'); if(b) showTab(b);}
      if(n.classList&&n.classList.contains('cn')&&n.classList.contains('closed')){n.classList.remove('closed');var t=n.querySelector(':scope > button.cn-h'); if(t) t.setAttribute('aria-expanded','true');}
      n=n.parentNode;
    }
  }
  form.addEventListener('click',function(e){
    var tb=e.target.closest('.tab-btn'); if(tb){showTab(tb);return;}
    var tg=e.target.closest('button.cn-h'); if(tg){var s=tg.parentNode; var c=s.classList.toggle('closed'); tg.setAttribute('aria-expanded',c?'false':'true');}
  });
  function clear(){banner.className='banner';banner.textContent='';form.querySelectorAll('.f').forEach(function(f){f.classList.remove('bad');f.querySelector('.err').textContent='';});}
  function showErr(map,msg,detail){
    banner.textContent=msg||'Please check the form.';
    if(detail){var sm=document.createElement('small');sm.textContent='Admin detail: '+detail;banner.appendChild(sm);}
    banner.className='banner show';
    var first=null;
    Object.keys(map||{}).forEach(function(k){
      var box=form.querySelector('.f[data-f="'+k+'"]'); if(!box) return;
      box.classList.add('bad'); box.querySelector('.err').textContent=map[k]; if(!first) first=box;
    });
    if(first) reveal(first);
    (first||banner).scrollIntoView({behavior:'smooth',block:'center'});
  }
  function collect(){
    var o={}; var fd=new FormData(form);
    fd.forEach(function(v,k){
      if(k.slice(-2)==='[]'){k=k.slice(0,-2);(o[k]=o[k]||[]).push(v);} else o[k]=v;
    });
    return o;
  }
  form.addEventListener('submit',function(e){
    e.preventDefault(); clear();
    var missing={};
    form.querySelectorAll('.f').forEach(function(box){
      var req=box.querySelector('[required]'); if(!req) return;
      var els=box.querySelectorAll('input,select,textarea'); var name=box.getAttribute('data-f'); var ok=false;
      els.forEach(function(el){ if(el.type==='radio'||el.type==='checkbox'){ if(el.checked) ok=true; } else if((el.value||'').trim()!=='') ok=true; });
      if(!ok) missing[name]='This field is required.';
    });
    if(Object.keys(missing).length){showErr(missing,'Please fill in the required fields.');return;}
    btn.disabled=true;
    fetch(form.getAttribute('action'),{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(collect())})
      .then(function(r){return r.json().catch(function(){return {success:false,message:'Unexpected response. Please try again.'};});})
      .then(function(d){
        if(d.success){
          form.style.display='none'; var hd=document.getElementById('head'); if(hd) hd.style.display='none';
          banner.style.display='none';
          document.getElementById('okmsg').textContent=d.message||'';
          if(d.reference){var r=document.getElementById('okref');r.textContent='Reference: '+d.reference;r.style.display='inline-block';}
          document.getElementById('ok').style.display='block'; window.scrollTo({top:0,behavior:'smooth'});
          if(d.redirect&&/^https?:\/\//i.test(d.redirect)) setTimeout(function(){location.href=d.redirect;},2500);
        } else {
          if(d.token) form.querySelector('[name=_t]').value=d.token;
          showErr(d.errors,d.message,d.detail); btn.disabled=false;
        }
      })
      .catch(function(){showErr({}, 'Network problem. Please check your connection and try again.');btn.disabled=false;});
  });
})();
</script>
<?php endif; ?>
</body>
</html>
