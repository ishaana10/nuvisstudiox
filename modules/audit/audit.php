<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

// Administrators, or roles that have the audit.view permission.
$user = $auth->getCurrentUser();
$role = strtolower((string)($user['usr_role'] ?? ''));
$isAdmin = in_array($role, ['globeadmin', 'admin'], true);
if (!$user || (!$isAdmin && !$auth->hasPermission('audit.view'))) {
    http_response_code(403);
    exit('Access denied');
}
?>
<style>
.au{--au-bad:#dc2626;--au-ok:#16a34a;display:flex;flex-direction:column;gap:16px}
.au-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
.au-kpi{background:var(--bg-card);border:1px solid var(--border-color);border-radius:var(--radius-md);padding:12px 14px}
.au-kpi b{display:block;font-size:22px;line-height:1.2}.au-kpi span{font-size:12px;color:var(--text-muted)}
.au-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.au-bar .nu-input{width:auto;min-width:130px}
.au-spark{display:flex;align-items:flex-end;gap:3px;height:34px;margin-top:6px}
.au-spark i{flex:1;background:var(--primary);opacity:.75;border-radius:2px 2px 0 0;min-height:2px}
.au-pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;background:var(--bg-subtle);color:var(--text-muted)}
.au-pill.bad{background:#fee2e2;color:#b91c1c}.au-pill.ok{background:#dcfce7;color:#15803d}
.au-pill.del{background:#fef3c7;color:#92400e}
.au tr.au-row{cursor:pointer}.au tr.au-row:hover{background:var(--bg-hover)}
.au-sum{font-size:12px;color:var(--text-muted);max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.au-drawer{position:fixed;top:0;right:0;bottom:0;width:min(560px,100%);background:var(--bg-card);border-left:1px solid var(--border-color);box-shadow:var(--shadow-lg);z-index:10000;transform:translateX(100%);transition:transform .2s;overflow:auto;padding:20px}
.au-drawer.open{transform:none}
.au-drawer h4{margin:0 0 12px}.au-dl{display:grid;grid-template-columns:110px 1fr;gap:6px 12px;font-size:13px;margin-bottom:16px}
.au-dl dt{color:var(--text-muted)}.au-dl dd{margin:0;word-break:break-word}
.au-diff{width:100%;border-collapse:collapse;font-size:12px}.au-diff th,.au-diff td{padding:6px 8px;border-bottom:1px solid var(--border-color);text-align:left;vertical-align:top;word-break:break-word}
.au-diff .o{background:#fee2e2}.au-diff .n{background:#dcfce7}
.au pre{background:var(--bg-subtle);padding:10px;border-radius:8px;font-size:12px;white-space:pre-wrap;word-break:break-word;max-height:240px;overflow:auto}
.au-integrity{padding:10px 12px;border-radius:8px;font-size:13px;display:none}
@media(max-width:700px){.au-hide-sm{display:none}}
</style>

<div class="au" id="au">
  <div class="au-kpis">
    <div class="au-kpi"><b id="auTotal">–</b><span>Matching entries</span></div>
    <div class="au-kpi"><b id="auToday">–</b><span>Today</span></div>
    <div class="au-kpi"><b id="auFail" style="color:var(--au-bad)">–</b><span>Failed attempts</span></div>
    <div class="au-kpi"><b id="auUsers">–</b><span>Active users</span><div class="au-spark" id="auSpark" title="Entries per day, last 14 days"></div></div>
  </div>

  <div class="nu-card" style="padding:14px">
    <div class="au-bar">
      <input class="nu-input" id="auQ" type="search" placeholder="Search anything…" style="min-width:200px;flex:1">
      <select class="nu-input" id="auAction"><option value="">All actions</option></select>
      <select class="nu-input" id="auTable"><option value="">All areas</option></select>
      <select class="nu-input" id="auUser"><option value="">All users</option></select>
      <select class="nu-input" id="auStatus"><option value="">Any outcome</option><option value="success">Success</option><option value="failure">Failed</option></select>
      <input class="nu-input" id="auFrom" type="date" title="From"><input class="nu-input" id="auTo" type="date" title="To">
      <button class="nu-btn nu-btn-primary nu-btn-sm" id="auApply">Apply</button>
      <button class="nu-btn nu-btn-ghost nu-btn-sm" id="auReset">Reset</button>
    </div>
    <div class="au-bar" style="margin-top:10px">
      <button class="nu-btn nu-btn-ghost nu-btn-sm" id="auVerify">Verify integrity</button>
      <?php if ($isAdmin): ?>
      <button class="nu-btn nu-btn-ghost nu-btn-sm" id="auExport">Export CSV</button>
      <button class="nu-btn nu-btn-ghost nu-btn-sm" id="auPurge">Purge old entries…</button>
      <?php endif; ?>
      <span style="font-size:12px;color:var(--text-muted)">Entries are chained with a hash so edits or deletions can be detected.</span>
    </div>
    <div class="au-integrity" id="auIntegrity"></div>
  </div>

  <div class="nu-card">
    <div class="nu-table-wrap">
      <table class="nu-table">
        <thead><tr><th>Time</th><th>Action</th><th>Area / record</th><th>User</th><th class="au-hide-sm">IP</th><th class="au-hide-sm">Details</th></tr></thead>
        <tbody id="auBody"><tr><td colspan="6" style="text-align:center;padding:24px;color:var(--text-muted)">Loading…</td></tr></tbody>
      </table>
    </div>
    <div class="au-bar" style="justify-content:space-between;padding:12px">
      <span id="auPageInfo" style="font-size:12px;color:var(--text-muted)"></span>
      <span><button class="nu-btn nu-btn-ghost nu-btn-sm" id="auPrev">‹ Prev</button> <button class="nu-btn nu-btn-ghost nu-btn-sm" id="auNext">Next ›</button></span>
    </div>
  </div>
</div>

<aside class="au-drawer" id="auDrawer" aria-hidden="true"><div id="auDrawerBody"></div></aside>

<script>
(function () {
  var API = 'api/audit.php', page = 1, pages = 1, rows = [];
  var $ = function (id) { return document.getElementById(id); };
  var el = function (tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined && text !== null) e.textContent = text; return e; };

  function params() {
    var p = new URLSearchParams();
    var m = { q: 'auQ', f_action: 'auAction', f_table: 'auTable', f_user: 'auUser', f_status: 'auStatus', f_from: 'auFrom', f_to: 'auTo' };
    Object.keys(m).forEach(function (k) { var v = $(m[k]).value.trim(); if (v) p.set(k, v); });
    return p;
  }
  function get(action, extra) {
    var p = params(); p.set('action', action);
    Object.keys(extra || {}).forEach(function (k) { p.set(k, extra[k]); });
    return fetch(API + '?' + p.toString(), { credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function post(action, body) {
    return fetch(API + '?action=' + action, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) }).then(function (r) { return r.json(); });
  }
  function toast(m, t) { if (window.NuApp && NuApp.toast) NuApp.toast(m, t || 'info'); }

  function pill(row) {
    var a = row.audit_action || '', cls = row.audit_status === 'failure' ? 'bad' : (/delete|purge|remove/.test(a) ? 'del' : (/login|create|upload/.test(a) ? 'ok' : ''));
    return el('span', 'au-pill ' + cls, a.replace(/_/g, ' '));
  }
  function when(ts) { var d = new Date(String(ts).replace(' ', 'T')); return isNaN(d) ? String(ts || '') : d.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' }); }

  function load() {
    var body = $('auBody');
    get('list', { page: page, per: 25 }).then(function (d) {
      if (!d.success) { body.replaceChildren(); var tr = el('tr'), td = el('td', null, d.error || 'Failed to load'); td.colSpan = 6; tr.appendChild(td); body.appendChild(tr); return; }
      rows = d.rows; pages = d.pages;
      body.replaceChildren();
      if (!rows.length) { var tr0 = el('tr'), td0 = el('td', null, 'No audit entries match.'); td0.colSpan = 6; td0.style.cssText = 'text-align:center;padding:24px;color:var(--text-muted)'; tr0.appendChild(td0); body.appendChild(tr0); }
      rows.forEach(function (r) {
        var tr = el('tr', 'au-row');
        tr.appendChild(el('td', null, when(r.audit_timestamp)));
        var tdA = el('td'); tdA.appendChild(pill(r)); tr.appendChild(tdA);
        tr.appendChild(el('td', null, (r.audit_table || '') + (r.audit_record_id ? ' #' + r.audit_record_id : '')));
        tr.appendChild(el('td', null, r.audit_username || ''));
        tr.appendChild(el('td', 'au-hide-sm', r.audit_ip || ''));
        tr.appendChild(el('td', 'au-hide-sm au-sum', r.audit_summary || (r.audit_diff ? Object.keys(safeJson(r.audit_diff)).join(', ') : '')));
        tr.addEventListener('click', function () { openDetail(r.audit_id); });
        body.appendChild(tr);
      });
      $('auPageInfo').textContent = d.total + ' entries · page ' + d.page + ' of ' + d.pages;
      $('auPrev').disabled = page <= 1; $('auNext').disabled = page >= pages;
    }).catch(function (e) { toast('Audit load failed: ' + e.message, 'error'); });
    get('stats').then(function (s) {
      if (!s.success) return;
      $('auTotal').textContent = s.total; $('auToday').textContent = s.today; $('auFail').textContent = s.failures; $('auUsers').textContent = s.users;
      var sp = $('auSpark'); sp.replaceChildren();
      var max = Math.max.apply(null, s.by_day.map(function (x) { return +x.value; }).concat([1]));
      s.by_day.forEach(function (x) { var i = el('i'); i.style.height = Math.max(6, (+x.value / max) * 100) + '%'; i.title = x.label + ': ' + x.value; sp.appendChild(i); });
    });
  }
  function safeJson(s) { try { return JSON.parse(s) || {}; } catch (e) { return {}; } }

  function fill(sel, list, first) { var cur = sel.value; sel.replaceChildren(); var o = el('option', null, first); o.value = ''; sel.appendChild(o); list.forEach(function (v) { var x = el('option', null, v); x.value = v; sel.appendChild(x); }); sel.value = cur; }
  function facets() { get('facets').then(function (f) { if (!f.success) return; fill($('auAction'), f.actions, 'All actions'); fill($('auTable'), f.tables, 'All areas'); fill($('auUser'), f.users, 'All users'); }); }

  function val(v) { return v === null || v === undefined ? '—' : (typeof v === 'object' ? JSON.stringify(v) : String(v)); }
  function openDetail(id) {
    var box = $('auDrawerBody'); box.replaceChildren(el('p', null, 'Loading…'));
    $('auDrawer').classList.add('open'); $('auDrawer').setAttribute('aria-hidden', 'false');
    get('detail', { id: id }).then(function (d) {
      box.replaceChildren();
      if (!d.success) { box.appendChild(el('p', null, d.error || 'Failed')); return; }
      var r = d.row, head = el('div'); head.style.cssText = 'display:flex;justify-content:space-between;align-items:center';
      head.appendChild(el('h4', null, 'Entry #' + r.audit_id));
      var x = el('button', 'nu-btn nu-btn-ghost nu-btn-sm', 'Close'); x.addEventListener('click', closeDrawer); head.appendChild(x); box.appendChild(head);
      var dl = el('dl', 'au-dl');
      [['When', r.audit_timestamp], ['Action', r.audit_action], ['Outcome', r.audit_status || 'success'], ['Area', r.audit_table], ['Record', r.audit_record_id], ['User', (r.audit_username || '') + (r.audit_user_id ? ' (' + r.audit_user_id + ')' : '')],
       ['IP address', r.audit_ip], ['Request', r.audit_request], ['Browser', r.audit_user_agent], ['Session', r.audit_session], ['Summary', r.audit_summary], ['Seal', r.audit_hash ? r.audit_hash.slice(0, 16) + '…' : 'not sealed (older entry)']]
        .forEach(function (p) { if (p[1] === null || p[1] === undefined || p[1] === '') return; dl.appendChild(el('dt', null, p[0])); dl.appendChild(el('dd', null, p[1])); });
      box.appendChild(dl);
      var diff = r.audit_diff_parsed;
      if (diff && Object.keys(diff).length) {
        box.appendChild(el('h4', null, 'What changed'));
        var t = el('table', 'au-diff'), th = el('tr'); ['Field', 'Before', 'After'].forEach(function (h) { th.appendChild(el('th', null, h)); }); t.appendChild(th);
        Object.keys(diff).forEach(function (k) { var tr = el('tr'); tr.appendChild(el('td', null, k)); tr.appendChild(el('td', 'o', val(diff[k].old))); tr.appendChild(el('td', 'n', val(diff[k].new))); t.appendChild(tr); });
        box.appendChild(t);
      } else if (r.audit_new_data_parsed) {
        box.appendChild(el('h4', null, 'Data')); box.appendChild(el('pre', null, JSON.stringify(r.audit_new_data_parsed, null, 2)));
      }
      if (r.audit_old_data_parsed && !(diff && Object.keys(diff).length)) { box.appendChild(el('h4', null, 'Before')); box.appendChild(el('pre', null, JSON.stringify(r.audit_old_data_parsed, null, 2))); }
    });
  }
  function closeDrawer() { $('auDrawer').classList.remove('open'); $('auDrawer').setAttribute('aria-hidden', 'true'); }

  $('auApply').addEventListener('click', function () { page = 1; load(); });
  $('auQ').addEventListener('keydown', function (e) { if (e.key === 'Enter') { page = 1; load(); } });
  $('auReset').addEventListener('click', function () { ['auQ', 'auAction', 'auTable', 'auUser', 'auStatus', 'auFrom', 'auTo'].forEach(function (i) { $(i).value = ''; }); page = 1; load(); });
  $('auPrev').addEventListener('click', function () { if (page > 1) { page--; load(); } });
  $('auNext').addEventListener('click', function () { if (page < pages) { page++; load(); } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDrawer(); });
  $('auVerify').addEventListener('click', function () {
    var box = $('auIntegrity'); box.style.display = 'block'; box.textContent = 'Checking…';
    post('verify').then(function (r) {
      box.style.background = r.ok ? '#dcfce7' : '#fee2e2'; box.style.color = r.ok ? '#14532d' : '#7f1d1d';
      box.textContent = r.ok ? ('✔ Chain intact — ' + r.checked + ' sealed entries verified' + (r.unsealed ? ' (' + r.unsealed + ' older entries pre-date sealing)' : '') + '.')
                             : ('✖ Problem at entry #' + r.first_bad_id + ': ' + r.reason);
      if (!r.ok) load();
    });
  });
  var ex = $('auExport'); if (ex) ex.addEventListener('click', function () { var p = params(); p.set('action', 'export'); window.location.href = API + '?' + p.toString(); });
  var pg = $('auPurge'); if (pg) pg.addEventListener('click', function () {
    var d = prompt('Delete audit entries older than how many days? (minimum 30)', '365'); if (!d) return;
    post('purge', { days: parseInt(d, 10) }).then(function (r) { if (r.success) { toast(r.removed + ' entries removed', 'success'); facets(); load(); } else toast(r.error || 'Failed', 'error'); });
  });

  facets(); load();
})();
</script>
