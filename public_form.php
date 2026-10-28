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
header("Content-Security-Policy: default-src 'none'; style-src 'nonce-$nonce'; script-src 'nonce-$nonce'; connect-src 'self'; form-action 'self'; base-uri 'none'; img-src data:");

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

$title = $share && $form ? (trim((string)$share['fs_title']) ?: (string)$form['form_name']) : 'Form';
$fields = ($page['kind'] === 'form' && $share && $form) ? NuPublicForm::visibleFields($form, $share) : [];
$formToken = ($page['kind'] === 'form') ? NuPublicForm::signToken($share) : '';
$submitLabel = $share ? (trim((string)$share['fs_submit_label']) ?: 'Submit request') : 'Submit';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= pf_h($title) ?></title>
<style nonce="<?= pf_h($nonce) ?>">
:root{--bg:#f4f6fb;--card:#fff;--text:#14213d;--muted:#5b6785;--border:#d5dbea;--primary:#2563eb;--primary-d:#1d4ed8;--danger:#dc2626;--ok:#16a34a;--shadow:0 10px 30px rgba(20,33,61,.08)}
@media (prefers-color-scheme:dark){:root{--bg:#0e1424;--card:#161e33;--text:#e8ecf8;--muted:#9aa6c6;--border:#2b3656;--primary:#4f8bff;--primary-d:#3b78ee;--danger:#f87171;--ok:#4ade80;--shadow:0 10px 30px rgba(0,0,0,.4)}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;padding:24px 16px}
.wrap{max-width:680px;margin:0 auto}
.card{background:var(--card);border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow);padding:28px}
h1{font-size:24px;line-height:1.25;margin:0 0 6px}
.intro{color:var(--muted);margin:0 0 22px;white-space:pre-line}
.grid{display:grid;grid-template-columns:repeat(12,1fr);gap:16px}
.f{grid-column:span 12}
@media (min-width:600px){.c1{grid-column:span 1}.c2{grid-column:span 2}.c3{grid-column:span 3}.c4{grid-column:span 4}.c5{grid-column:span 5}.c6{grid-column:span 6}.c7{grid-column:span 7}.c8{grid-column:span 8}.c9{grid-column:span 9}.c10{grid-column:span 10}.c11{grid-column:span 11}}
label,.lbl{display:block;font-weight:600;font-size:14px;margin-bottom:6px}
.req{color:var(--danger)}
input[type=text],input[type=email],input[type=tel],input[type=url],input[type=number],input[type=date],input[type=time],input[type=datetime-local],select,textarea{width:100%;font:inherit;color:inherit;background:transparent;border:1px solid var(--border);border-radius:10px;padding:11px 13px;min-height:44px}
textarea{min-height:110px;resize:vertical}
input:focus,select:focus,textarea:focus{outline:2px solid var(--primary);outline-offset:1px;border-color:var(--primary)}
.f.bad input,.f.bad select,.f.bad textarea{border-color:var(--danger)}
.opts{display:flex;flex-direction:column;gap:8px}
.opt{display:flex;align-items:center;gap:10px;font-weight:400;font-size:15px;margin:0;min-height:32px}
.opt input{width:20px;height:20px;accent-color:var(--primary)}
.help{font-size:12.5px;color:var(--muted);margin-top:5px}
.err{font-size:13px;color:var(--danger);margin-top:5px;min-height:0}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;background:var(--primary);color:#fff;border:0;border-radius:10px;padding:12px 22px;font:inherit;font-weight:600;cursor:pointer;min-height:46px}
.btn:hover{background:var(--primary-d)}
.btn[disabled]{opacity:.65;cursor:progress}
.actions{margin-top:24px;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.banner{border-radius:10px;padding:12px 14px;margin-bottom:18px;font-size:14px;border:1px solid var(--danger);color:var(--danger);display:none}
.banner.show{display:block}
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
</style>
</head>
<body>
<div class="wrap"><div class="card">
<?php if ($page['kind'] === 'done'): ?>
  <div class="done" role="status">
    <div class="tick">✓</div>
    <h1>Request received</h1>
    <p class="intro"><?= pf_h($page['message']) ?></p>
    <?php if (!empty($page['reference'])): ?><div class="ref">Reference: <?= pf_h($page['reference']) ?></div><?php endif; ?>
  </div>
<?php elseif ($page['kind'] === 'error'): ?>
  <div class="stop" role="alert">
    <div class="tick">!</div>
    <h1>Not available</h1>
    <p class="intro"><?= pf_h($page['message']) ?></p>
  </div>
<?php else: ?>
  <h1><?= pf_h($title) ?></h1>
  <?php if (trim((string)$share['fs_intro']) !== ''): ?><p class="intro"><?= pf_h($share['fs_intro']) ?></p><?php endif; ?>
  <div id="banner" class="banner" role="alert"></div>
  <form id="pf" method="post" action="?t=<?= pf_h(rawurlencode($token)) ?>" novalidate>
    <input type="hidden" name="_t" value="<?= pf_h($formToken) ?>">
    <div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
    <div class="grid">
    <?php foreach ($fields as $f):
        $n = $f['name']; $id = 'f_' . $n; $t = $f['type'];
        $req = $f['required'] ? ' required' : '';
        $ph = $f['placeholder'] !== '' ? ' placeholder="' . pf_h($f['placeholder']) . '"' : '';
        $reqMark = $f['required'] ? ' <span class="req" aria-hidden="true">*</span>' : '';
    ?>
      <div class="f c<?= (int)$f['col'] ?>" data-f="<?= pf_h($n) ?>">
        <?php if ($t === 'checkbox'): ?>
          <label class="opt"><input type="checkbox" id="<?= pf_h($id) ?>" name="<?= pf_h($n) ?>" value="1"<?= $req ?>> <span><?= pf_h($f['label']) ?><?= $reqMark ?></span></label>
        <?php elseif ($t === 'radio'): ?>
          <div class="lbl" id="<?= pf_h($id) ?>_l"><?= pf_h($f['label']) ?><?= $reqMark ?></div>
          <div class="opts" role="radiogroup" aria-labelledby="<?= pf_h($id) ?>_l">
            <?php foreach ($f['options'] as $i => $o): ?>
              <label class="opt"><input type="radio" name="<?= pf_h($n) ?>" value="<?= pf_h($o['value']) ?>"<?= $req ?>> <?= pf_h($o['label']) ?></label>
            <?php endforeach; ?>
          </div>
        <?php elseif ($t === 'checkbox_group'): ?>
          <div class="lbl" id="<?= pf_h($id) ?>_l"><?= pf_h($f['label']) ?><?= $reqMark ?></div>
          <div class="opts" role="group" aria-labelledby="<?= pf_h($id) ?>_l">
            <?php foreach ($f['options'] as $o): ?>
              <label class="opt"><input type="checkbox" name="<?= pf_h($n) ?>[]" value="<?= pf_h($o['value']) ?>"> <?= pf_h($o['label']) ?></label>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <label for="<?= pf_h($id) ?>"><?= pf_h($f['label']) ?><?= $reqMark ?></label>
          <?php if ($t === 'textarea'): ?>
            <textarea id="<?= pf_h($id) ?>" name="<?= pf_h($n) ?>"<?= $req . $ph ?>></textarea>
          <?php elseif ($t === 'select'): ?>
            <select id="<?= pf_h($id) ?>" name="<?= pf_h($n) ?>"<?= $req ?>>
              <option value="">Select…</option>
              <?php foreach ($f['options'] as $o): ?><option value="<?= pf_h($o['value']) ?>"><?= pf_h($o['label']) ?></option><?php endforeach; ?>
            </select>
          <?php else:
              $map = ['phone' => 'tel', 'datetime' => 'datetime-local'];
              $it = $map[$t] ?? $t;
              $extra = '';
              if ($t === 'number') {
                  if ($f['min'] !== null && $f['min'] !== '') $extra .= ' min="' . pf_h($f['min']) . '"';
                  if ($f['max'] !== null && $f['max'] !== '') $extra .= ' max="' . pf_h($f['max']) . '"';
                  $extra .= ' step="any" inputmode="decimal"';
              }
              if ($t === 'email') $extra .= ' autocomplete="email" inputmode="email"';
              if ($t === 'tel' || $t === 'phone') $extra .= ' autocomplete="tel" inputmode="tel"';
          ?>
            <input type="<?= pf_h($it) ?>" id="<?= pf_h($id) ?>" name="<?= pf_h($n) ?>"<?= $req . $ph . $extra ?>>
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($f['help'] !== ''): ?><div class="help"><?= pf_h($f['help']) ?></div><?php endif; ?>
        <div class="err" aria-live="polite"></div>
      </div>
    <?php endforeach; ?>
    </div>
    <?php if (!$fields): ?><p class="intro">This form has no fields to fill in yet.</p><?php endif; ?>
    <div class="actions">
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
<div class="foot">Your answers are sent securely to the form owner.</div>
</div>
<?php if ($page['kind'] === 'form'): ?>
<script nonce="<?= pf_h($nonce) ?>">
(function(){
  var form=document.getElementById('pf'), btn=document.getElementById('go'), banner=document.getElementById('banner');
  function clear(){banner.className='banner';banner.textContent='';form.querySelectorAll('.f').forEach(function(f){f.classList.remove('bad');f.querySelector('.err').textContent='';});}
  function showErr(map,msg){
    banner.textContent=msg||'Please check the form.';banner.className='banner show';
    var first=null;
    Object.keys(map||{}).forEach(function(k){
      var box=form.querySelector('.f[data-f="'+k+'"]'); if(!box) return;
      box.classList.add('bad'); box.querySelector('.err').textContent=map[k]; if(!first) first=box;
    });
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
    // Browser-native required check first, with our own message placement
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
          form.style.display='none'; document.querySelector('h1').style.display='none';
          var intro=document.querySelector('.intro'); if(intro) intro.style.display='none';
          banner.style.display='none';
          document.getElementById('okmsg').textContent=d.message||'';
          if(d.reference){var r=document.getElementById('okref');r.textContent='Reference: '+d.reference;r.style.display='inline-block';}
          document.getElementById('ok').style.display='block';
          if(d.redirect&&/^https?:\/\//i.test(d.redirect)) setTimeout(function(){location.href=d.redirect;},2500);
        } else {
          if(d.token) form.querySelector('[name=_t]').value=d.token;
          showErr(d.errors,d.message); btn.disabled=false;
        }
      })
      .catch(function(){showErr({}, 'Network problem. Please check your connection and try again.');btn.disabled=false;});
  });
})();
</script>
<?php endif; ?>
</body>
</html>
