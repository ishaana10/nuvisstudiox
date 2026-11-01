<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
// $auth, $nuConfig available. Session open under 'nu5sess'.
?>
<style>
/* ── Customer Requests module (scoped .cr-*) ─────────────────────────────── */
#crRoot { --cr-gap:16px; }
.cr-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
.cr-title { display:flex; align-items:center; gap:10px; }
.cr-title h2 { margin:0; font-size:20px; font-weight:750; color:var(--text-primary); }
.cr-title .cr-ico { font-size:24px; line-height:1; }
.cr-sub { font-size:13px; color:var(--text-tertiary); margin-top:2px; }
.cr-actions { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }

/* channel switcher */
.cr-chan { display:flex; align-items:center; gap:6px; }
.cr-chan select { font-size:13px; padding:7px 10px; border:1px solid var(--border-color); border-radius:8px; background:var(--bg-card); color:var(--text-primary); }

/* stat tiles */
.cr-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:10px; margin-bottom:16px; }
.cr-stat { background:var(--bg-card); border:1px solid var(--border-color); border-radius:12px; padding:12px 14px; }
.cr-stat-n { font-size:22px; font-weight:750; color:var(--text-primary); line-height:1.1; }
.cr-stat-l { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.05em; color:var(--text-tertiary); margin-top:3px; }
.cr-stat.warn .cr-stat-n { color:#ef4444; }

/* toolbar */
.cr-toolbar { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
.cr-search { flex:1; min-width:200px; max-width:360px; position:relative; }
.cr-search input { width:100%; box-sizing:border-box; padding:8px 12px 8px 32px; border:1px solid var(--border-color); border-radius:9px; background:var(--bg-card); color:var(--text-primary); font-size:13px; }
.cr-search svg { position:absolute; left:10px; top:50%; transform:translateY(-50%); color:var(--text-tertiary); }

/* Kanban board */
.cr-board { display:flex; gap:12px; overflow-x:auto; padding-bottom:10px; align-items:flex-start; }
.cr-col { flex:0 0 280px; background:var(--bg-subtle,#f1f5f9); border:1px solid var(--border-color); border-radius:12px; display:flex; flex-direction:column; max-height:calc(100vh - 320px); }
.cr-col-head { display:flex; align-items:center; justify-content:space-between; gap:8px; padding:10px 12px; border-bottom:1px solid var(--border-color); position:sticky; top:0; }
.cr-col-name { display:flex; align-items:center; gap:7px; font-size:12.5px; font-weight:700; color:var(--text-primary); }
.cr-col-dot { width:9px; height:9px; border-radius:50%; flex:none; }
.cr-col-count { font-size:11px; font-weight:700; color:var(--text-tertiary); background:var(--bg-card); border:1px solid var(--border-color); border-radius:20px; padding:1px 8px; }
.cr-col-body { padding:10px; display:flex; flex-direction:column; gap:8px; overflow-y:auto; }
.cr-col-empty { font-size:12px; color:var(--text-tertiary); text-align:center; padding:16px 8px; }

.cr-card { background:var(--bg-card); border:1px solid var(--border-color); border-left:3px solid var(--cr-card-accent,#6366f1); border-radius:10px; padding:10px 12px; cursor:pointer; transition:box-shadow .15s, transform .1s; }
.cr-card:hover { box-shadow:0 3px 10px rgba(15,23,42,.1); transform:translateY(-1px); }
.cr-card-top { display:flex; align-items:center; justify-content:space-between; gap:6px; margin-bottom:5px; }
.cr-card-ref { font-size:11px; font-weight:700; color:var(--text-tertiary); font-family:ui-monospace,monospace; }
.cr-card-age { font-size:10.5px; color:var(--text-tertiary); }
.cr-card-subj { font-size:13px; font-weight:650; color:var(--text-primary); margin-bottom:3px; line-height:1.3; word-break:break-word; }
.cr-card-cust { font-size:12px; color:var(--text-secondary); display:flex; align-items:center; gap:5px; }
.cr-badge-od { display:inline-block; margin-top:6px; font-size:10px; font-weight:700; color:#fff; background:#ef4444; border-radius:5px; padding:1px 7px; }

/* setup screen */
.cr-setup { max-width:860px; margin:0 auto; }
.cr-setup-hero { text-align:center; padding:8px 0 20px; }
.cr-setup-hero .cr-ico { font-size:40px; }
.cr-setup-hero h2 { margin:10px 0 6px; font-size:22px; font-weight:750; color:var(--text-primary); }
.cr-setup-hero p { margin:0; font-size:14px; color:var(--text-tertiary); }
.cr-tpls { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px; margin:18px 0; }
.cr-tpl { border:1.5px solid var(--border-color); border-radius:12px; padding:16px; cursor:pointer; background:var(--bg-card); transition:border-color .15s, box-shadow .15s, transform .1s; }
.cr-tpl:hover { border-color:var(--color-primary); transform:translateY(-1px); }
.cr-tpl.on { border-color:var(--color-primary); box-shadow:0 0 0 3px color-mix(in srgb,var(--color-primary) 20%,transparent); }
.cr-tpl-ico { font-size:26px; }
.cr-tpl-name { font-size:15px; font-weight:700; color:var(--text-primary); margin:8px 0 4px; }
.cr-tpl-desc { font-size:12px; color:var(--text-tertiary); line-height:1.4; min-height:34px; }
.cr-tpl-meta { font-size:11px; color:var(--text-tertiary); margin-top:8px; display:flex; gap:10px; }
.cr-tpl-stages { display:flex; gap:3px; margin-top:8px; flex-wrap:wrap; }
.cr-tpl-stage { font-size:10px; color:#fff; border-radius:4px; padding:1px 6px; font-weight:600; }
.cr-setup-form { background:var(--bg-card); border:1px solid var(--border-color); border-radius:12px; padding:18px; }
.cr-field { margin-bottom:14px; }
.cr-field label { display:block; font-size:12px; font-weight:600; color:var(--text-secondary); margin-bottom:5px; }
.cr-field .nu-input, .cr-field input, .cr-field select { width:100%; box-sizing:border-box; }
.cr-field-hint { font-size:11px; color:var(--text-tertiary); margin-top:4px; }
.cr-row2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
@media (max-width:560px){ .cr-row2 { grid-template-columns:1fr; } }
.cr-switch { display:flex; align-items:center; gap:10px; }
.cr-switch input { width:38px; height:22px; appearance:none; -webkit-appearance:none; background:color-mix(in srgb,var(--text-muted,#64748b) 35%,transparent); border-radius:999px; position:relative; cursor:pointer; transition:background .18s; flex:none; }
.cr-switch input::after { content:""; position:absolute; top:2px; left:2px; width:18px; height:18px; border-radius:50%; background:#fff; transition:transform .18s; }
.cr-switch input:checked { background:var(--color-primary); }
.cr-switch input:checked::after { transform:translateX(16px); }

/* drawer (detail + settings) */
.cr-drawer-ov { position:fixed; inset:0; background:rgba(15,23,42,.45); z-index:1000; display:none; }
.cr-drawer-ov.open { display:block; }
.cr-drawer { position:fixed; top:0; right:0; height:100vh; width:min(520px,100%); background:var(--bg-card); box-shadow:-10px 0 40px rgba(15,23,42,.25); z-index:1001; transform:translateX(100%); transition:transform .2s ease; display:flex; flex-direction:column; }
.cr-drawer.open { transform:translateX(0); }
.cr-drawer-head { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; padding:16px 18px; border-bottom:1px solid var(--border-color); }
.cr-drawer-head h3 { margin:0; font-size:16px; font-weight:750; color:var(--text-primary); }
.cr-drawer-body { flex:1; overflow-y:auto; padding:16px 18px; }
.cr-x { background:none; border:0; font-size:22px; line-height:1; cursor:pointer; color:var(--text-tertiary); padding:0 4px; }
.cr-x:hover { color:var(--text-primary); }
.cr-pill { display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:700; color:#fff; border-radius:20px; padding:3px 12px; }
.cr-pill .cr-col-dot { background:rgba(255,255,255,.9); }
.cr-sec { margin-top:20px; }
.cr-sec-h { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--text-tertiary); margin-bottom:8px; }
.cr-kv { display:grid; grid-template-columns:130px 1fr; gap:6px 12px; font-size:13px; }
.cr-kv dt { color:var(--text-tertiary); }
.cr-kv dd { margin:0; color:var(--text-primary); word-break:break-word; }
.cr-adv { display:flex; flex-wrap:wrap; gap:8px; }
.cr-adv button { border:0; border-radius:8px; padding:8px 13px; font-size:12.5px; font-weight:650; color:#fff; cursor:pointer; }
.cr-adv button:disabled { opacity:.45; cursor:not-allowed; }
.cr-timeline { border-left:2px solid var(--border-color); padding-left:14px; margin-left:4px; display:flex; flex-direction:column; gap:14px; }
.cr-tl-item { position:relative; }
.cr-tl-item::before { content:""; position:absolute; left:-21px; top:3px; width:10px; height:10px; border-radius:50%; background:var(--color-primary); border:2px solid var(--bg-card); }
.cr-tl-head { font-size:12.5px; font-weight:650; color:var(--text-primary); }
.cr-tl-meta { font-size:11px; color:var(--text-tertiary); margin-top:1px; }
.cr-tl-comment { font-size:12px; color:var(--text-secondary); margin-top:4px; background:var(--bg-subtle,#f8fafc); border-radius:6px; padding:6px 9px; }
.cr-note { background:var(--bg-subtle,#f8fafc); border:1px solid var(--border-color); border-radius:8px; padding:9px 11px; margin-bottom:8px; }
.cr-note-txt { font-size:12.5px; color:var(--text-primary); white-space:pre-wrap; word-break:break-word; }
.cr-note-meta { font-size:10.5px; color:var(--text-tertiary); margin-top:4px; }
.cr-linkbox { display:flex; gap:6px; align-items:center; }
.cr-linkbox input { flex:1; font-size:12px; font-family:ui-monospace,monospace; }
.cr-deeplinks { display:flex; flex-direction:column; gap:8px; }
.cr-deeplink { display:flex; align-items:center; justify-content:space-between; gap:10px; border:1px solid var(--border-color); border-radius:9px; padding:10px 12px; background:var(--bg-card); }
.cr-deeplink-t { font-size:13px; font-weight:600; color:var(--text-primary); }
.cr-deeplink-d { font-size:11px; color:var(--text-tertiary); }
.cr-empty { text-align:center; padding:60px 20px; color:var(--text-tertiary); }
.cr-empty svg { color:var(--text-tertiary); margin-bottom:12px; }
.cr-spin { margin:60px auto; }
</style>

<div id="crRoot">
  <div class="nu-spinner cr-spin"></div>
</div>

<!-- Detail / Settings drawer -->
<div class="cr-drawer-ov" id="crOverlay" onclick="if(event.target===this)NuCR.closeDrawer()"></div>
<div class="cr-drawer" id="crDrawer" role="dialog" aria-modal="true">
  <div class="cr-drawer-head">
    <div id="crDrawerTitle"><h3>Request</h3></div>
    <button type="button" class="cr-x" aria-label="Close" onclick="NuCR.closeDrawer()">&times;</button>
  </div>
  <div class="cr-drawer-body" id="crDrawerBody"></div>
</div>

<script>
(function () {
  'use strict';
  const API = 'api/customer_requests.php';
  const S = { configs: [], current: null, board: null, stats: null, search: '', setupTpl: '', templates: [], forms: [] };

  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
  const toast = (m, t) => { if (window.NuApp && NuApp.toast) NuApp.toast(m, t || 'info'); };
  const root = () => document.getElementById('crRoot');
  const ageText = h => h == null ? '' : (h < 1 ? 'just now' : h < 24 ? Math.round(h) + 'h ago' : Math.round(h / 24) + 'd ago');

  async function api(action, { method = 'GET', body = null, query = {} } = {}) {
    let url = API + '?action=' + encodeURIComponent(action);
    for (const k in query) url += '&' + k + '=' + encodeURIComponent(query[k]);
    const opt = { method, credentials: 'same-origin' };
    if (body) { opt.headers = { 'Content-Type': 'application/json' }; opt.body = JSON.stringify(body); }
    const r = await fetch(url, opt);
    const txt = await r.text();
    let j; try { j = JSON.parse(txt); } catch (e) { throw new Error('Unexpected server response'); }
    if (!j.success) throw new Error(j.error || 'Request failed');
    return j;
  }

  /* ── Boot ──────────────────────────────────────────────────────────────── */
  async function init() {
    try {
      const j = await api('list_configs');
      S.configs = j.configs || [];
      S.forms = j.forms || [];
      if (!S.configs.length) return renderSetup();
      S.current = S.configs[0];
      await loadBoard();
    } catch (e) {
      root().innerHTML = '<div class="cr-empty"><p>Could not load customer requests: ' + esc(e.message) + '</p></div>';
    }
  }

  /* ── Setup screen ──────────────────────────────────────────────────────── */
  async function renderSetup(forceShow) {
    if (!S.templates.length) {
      try { S.templates = (await api('templates')).templates || []; } catch (e) {}
    }
    const tpls = S.templates.map(t =>
      '<div class="cr-tpl" data-tpl="' + t.key + '" onclick="NuCR.pickTpl(\'' + t.key + '\')">' +
        '<div class="cr-tpl-ico">' + esc(t.icon) + '</div>' +
        '<div class="cr-tpl-name">' + esc(t.label) + '</div>' +
        '<div class="cr-tpl-desc">' + esc(t.description) + '</div>' +
        '<div class="cr-tpl-meta"><span>' + t.field_count + ' fields</span><span>' + t.stage_count + ' statuses</span></div>' +
        '<div class="cr-tpl-stages">' + (t.stages || []).map(s => '<span class="cr-tpl-stage" style="background:' + esc(s.color) + '">' + esc(s.name) + '</span>').join('') + '</div>' +
      '</div>'
    ).join('');

    const existingOpt = S.forms.length
      ? '<div class="cr-tpl" data-tpl="existing" onclick="NuCR.pickTpl(\'existing\')">' +
          '<div class="cr-tpl-ico">🔗</div><div class="cr-tpl-name">Use an existing form</div>' +
          '<div class="cr-tpl-desc">Attach customer-request tracking to a form you already built.</div>' +
          '<div class="cr-tpl-meta"><span>' + S.forms.length + ' forms available</span></div></div>'
      : '';

    const backBtn = forceShow ? '<button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="NuCR.cancelSetup()">← Back</button>' : '';

    root().innerHTML =
      '<div class="cr-setup">' +
        '<div class="cr-head" style="justify-content:flex-start">' + backBtn + '</div>' +
        '<div class="cr-setup-hero"><div class="cr-ico">🎫</div>' +
          '<h2>Set up customer requests</h2>' +
          '<p>Pick a starting point. You get an intake form, a public link, a status pipeline and email alerts — all editable afterwards.</p>' +
        '</div>' +
        '<div class="cr-tpls">' + tpls + existingOpt + '</div>' +
        '<div class="cr-setup-form" id="crSetupForm" style="display:none"></div>' +
      '</div>';
  }

  function pickTpl(key) {
    S.setupTpl = key;
    document.querySelectorAll('#crRoot .cr-tpl').forEach(el => el.classList.toggle('on', el.getAttribute('data-tpl') === key));
    const tpl = S.templates.find(t => t.key === key);
    const box = document.getElementById('crSetupForm');
    const existing = key === 'existing';
    const defName = existing ? '' : (tpl ? tpl.label : '');
    box.style.display = 'block';
    box.innerHTML =
      '<h3 style="margin:0 0 14px;font-size:15px;font-weight:700">Configure "' + esc(existing ? 'this channel' : (tpl ? tpl.label : '')) + '"</h3>' +
      (existing
        ? '<div class="cr-field"><label>Which form holds the requests?</label><select id="crExForm" class="nu-input">' +
            '<option value="">— choose a form —</option>' +
            S.forms.map(f => '<option value="' + esc(f.form_code) + '">' + esc(f.form_name) + ' (' + esc(f.form_table) + ')</option>').join('') +
          '</select><div class="cr-field-hint">A status workflow is optional for existing forms — you can design one later in the Workflow module.</div></div>'
        : '') +
      '<div class="cr-field"><label>Channel name</label><input type="text" id="crName" class="nu-input" placeholder="e.g. Help Desk" value="' + esc(defName) + '"></div>' +
      '<div class="cr-row2">' +
        '<div class="cr-field"><label>Reference prefix</label><input type="text" id="crPrefix" class="nu-input" maxlength="16" placeholder="REQ-" value="' + esc(tpl && tpl.reference_prefix ? tpl.reference_prefix : '') + '"></div>' +
        '<div class="cr-field"><label>Notify staff email(s)</label><input type="text" id="crNotify" class="nu-input" placeholder="ops@example.com"></div>' +
      '</div>' +
      '<div class="cr-field"><div class="cr-switch"><input type="checkbox" id="crPublic" checked><label for="crPublic" style="margin:0;cursor:pointer">Create a public intake link customers can use</label></div>' +
        '<div class="cr-field-hint">Turns the form into a shareable page. Each submission starts the pipeline and emails your team.</div></div>' +
      '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:4px">' +
        '<button class="nu-btn nu-btn-primary" id="crCreateBtn" onclick="NuCR.doSetup()">Create channel</button>' +
      '</div>';
    box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  async function doSetup() {
    const name = (document.getElementById('crName') || {}).value || '';
    if (!name.trim()) { toast('Give the channel a name', 'error'); return; }
    const payload = {
      name: name.trim(), template: S.setupTpl,
      reference_prefix: (document.getElementById('crPrefix') || {}).value || '',
      notify_email: (document.getElementById('crNotify') || {}).value || '',
      public_enabled: (document.getElementById('crPublic') || {}).checked ? 1 : 0,
    };
    if (S.setupTpl === 'existing') payload.form_code = (document.getElementById('crExForm') || {}).value || '';
    const btn = document.getElementById('crCreateBtn');
    if (btn) { btn.disabled = true; btn.textContent = 'Creating…'; }
    try {
      const j = await api('setup', { method: 'POST', body: payload });
      toast('Customer request channel created', 'success');
      const list = await api('list_configs');
      S.configs = list.configs || []; S.forms = list.forms || [];
      S.current = S.configs.find(c => c.cr_id === j.config.cr_id) || S.configs[0];
      await loadBoard();
    } catch (e) {
      toast(e.message, 'error');
      if (btn) { btn.disabled = false; btn.textContent = 'Create channel'; }
    }
  }

  function cancelSetup() { if (S.configs.length) { S.current = S.current || S.configs[0]; loadBoard(); } else init(); }

  /* ── Board ─────────────────────────────────────────────────────────────── */
  async function loadBoard() {
    root().innerHTML = '<div class="nu-spinner cr-spin"></div>';
    try {
      const [b, st, cfg] = await Promise.all([
        api('board', { query: { id: S.current.cr_id, q: S.search } }),
        api('stats', { query: { id: S.current.cr_id } }),
        api('get_config', { query: { id: S.current.cr_id } }),
      ]);
      S.board = b; S.stats = st.stats; S.current = cfg.config;
      renderBoard();
    } catch (e) {
      root().innerHTML = '<div class="cr-empty"><p>' + esc(e.message) + '</p></div>';
    }
  }

  function renderBoard() {
    const c = S.current, st = S.stats;
    const chan = S.configs.length > 1
      ? '<div class="cr-chan"><select onchange="NuCR.switchChannel(this.value)">' +
          S.configs.map(x => '<option value="' + x.cr_id + '"' + (x.cr_id === c.cr_id ? ' selected' : '') + '>' + esc(x.cr_icon || '🎫') + ' ' + esc(x.cr_name) + '</option>').join('') +
        '</select></div>'
      : '';

    const pub = c.public && c.public.enabled && c.public.url;
    const stat = (n, l, warn) => '<div class="cr-stat' + (warn ? ' warn' : '') + '"><div class="cr-stat-n">' + n + '</div><div class="cr-stat-l">' + l + '</div></div>';

    const cols = (S.board.columns || []).map(col => {
      const cards = (col.cards || []).map(card =>
        '<div class="cr-card" style="--cr-card-accent:' + esc(col.color) + '" onclick="NuCR.openRequest(\'' + esc(card.id) + '\')">' +
          '<div class="cr-card-top"><span class="cr-card-ref">' + esc(card.reference) + '</span><span class="cr-card-age">' + esc(ageText(card.age_hours)) + '</span></div>' +
          (card.subject ? '<div class="cr-card-subj">' + esc(card.subject) + '</div>' : '') +
          (card.customer ? '<div class="cr-card-cust">👤 ' + esc(card.customer) + '</div>' : '') +
          (card.overdue ? '<span class="cr-badge-od">Overdue</span>' : '') +
        '</div>'
      ).join('') || '<div class="cr-col-empty">No requests</div>';
      return '<div class="cr-col">' +
        '<div class="cr-col-head"><span class="cr-col-name"><span class="cr-col-dot" style="background:' + esc(col.color) + '"></span>' + esc(col.name) + '</span>' +
          '<span class="cr-col-count">' + (col.cards ? col.cards.length : 0) + '</span></div>' +
        '<div class="cr-col-body">' + cards + '</div></div>';
    }).join('');

    root().innerHTML =
      '<div class="cr-head">' +
        '<div class="cr-title"><span class="cr-ico">' + esc(c.cr_icon || '🎫') + '</span><div><h2>' + esc(c.cr_name) + '</h2>' +
          (c.cr_description ? '<div class="cr-sub">' + esc(c.cr_description) + '</div>' : '') + '</div></div>' +
        '<div class="cr-actions">' + chan +
          (pub ? '<a class="nu-btn nu-btn-ghost nu-btn-sm" href="' + esc(c.public.url) + '" target="_blank" rel="noopener">🔗 Public form</a>' : '') +
          '<button class="nu-btn nu-btn-secondary nu-btn-sm" onclick="NuCR.openCreate()">+ New request</button>' +
          '<button class="nu-btn nu-btn-secondary nu-btn-sm" onclick="NuCR.openSettings()">⚙ Settings</button>' +
          '<button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="NuCR.startNewChannel()">+ New channel</button>' +
        '</div>' +
      '</div>' +
      '<div class="cr-stats">' +
        stat(st.total, 'Total') + stat(st.open, 'Open') + stat(st.overdue, 'Overdue', st.overdue > 0) +
        (st.by_stage || []).slice(0, 4).map(s => stat(s.count, s.name)).join('') +
      '</div>' +
      '<div class="cr-toolbar"><div class="cr-search">' +
        '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>' +
        '<input type="text" placeholder="Search requests…" value="' + esc(S.search) + '" oninput="NuCR.onSearch(this.value)"></div></div>' +
      (S.board.count === 0
        ? '<div class="cr-empty"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>' +
            '<p>No requests yet.' + (pub ? ' Share the public form to start collecting them, or add one manually.' : ' Add one manually, or enable the public form in Settings.') + '</p></div>'
        : '<div class="cr-board">' + cols + '</div>');
  }

  let _searchT;
  function onSearch(v) { S.search = v; clearTimeout(_searchT); _searchT = setTimeout(() => api('board', { query: { id: S.current.cr_id, q: S.search } }).then(b => { S.board = b; renderBoard(); }), 250); }
  function switchChannel(id) { S.current = S.configs.find(c => String(c.cr_id) === String(id)) || S.current; S.search = ''; loadBoard(); }
  function startNewChannel() { renderSetup(true); }

  /* ── Drawer plumbing ───────────────────────────────────────────────────── */
  function openDrawer(title) {
    document.getElementById('crDrawerTitle').innerHTML = title;
    document.getElementById('crOverlay').classList.add('open');
    document.getElementById('crDrawer').classList.add('open');
  }
  function closeDrawer() {
    document.getElementById('crOverlay').classList.remove('open');
    document.getElementById('crDrawer').classList.remove('open');
  }

  /* ── Request detail ────────────────────────────────────────────────────── */
  async function openRequest(recordId) {
    openDrawer('<h3>Request</h3>');
    const body = document.getElementById('crDrawerBody');
    body.innerHTML = '<div class="nu-spinner" style="margin:40px auto"></div>';
    try {
      const j = await api('request', { query: { id: S.current.cr_id, record: recordId } });
      S._req = j.request; S._reqId = recordId;
      renderRequest();
    } catch (e) { body.innerHTML = '<p>' + esc(e.message) + '</p>'; }
  }

  function renderRequest() {
    const r = S._req, fields = (S.current.fields || []);
    document.getElementById('crDrawerTitle').innerHTML =
      '<h3>' + esc(r.reference) + '</h3>' +
      '<span class="cr-pill" style="background:' + esc(r.status_color) + ';margin-top:6px"><span class="cr-col-dot"></span>' + esc(r.status) + '</span>';

    // field values
    const labelFor = {}; fields.forEach(f => labelFor[f.name] = f.label);
    const skip = { id: 1, request_id: 1, user_id: 1, location: 1, updated_at: 1 };
    let kv = '';
    Object.keys(r.record).forEach(k => {
      if (skip[k] || r.record[k] == null || r.record[k] === '') return;
      kv += '<dt>' + esc(labelFor[k] || k.replace(/_/g, ' ')) + '</dt><dd>' + esc(r.record[k]) + '</dd>';
    });

    // advance buttons
    const adv = (r.transitions || []).length
      ? '<div class="cr-adv">' + r.transitions.map(t =>
          '<button style="background:' + esc(t.to_color) + '"' + (t.can_run ? '' : ' disabled title="' + esc(t.blocked_reason) + '"') +
            ' onclick="NuCR.advance(' + r.instance_id + ',' + t.id + ',' + (t.require_comment ? 'true' : 'false') + ')">' + esc(t.label) + '</button>'
        ).join('') + '</div>'
      : '<div class="cr-sub">' + (r.instance_id ? 'No further steps from here.' : 'This request is not tracked by a workflow.') + '</div>';

    // timeline
    const tl = (r.timeline || []).length
      ? '<div class="cr-timeline">' + r.timeline.slice().reverse().map(h =>
          '<div class="cr-tl-item"><div class="cr-tl-head">' + esc(h.to ? ('→ ' + h.to) : h.action) + '</div>' +
            '<div class="cr-tl-meta">' + esc(h.actor || 'System') + ' · ' + esc(fmtDate(h.at)) + '</div>' +
            (h.comment ? '<div class="cr-tl-comment">' + esc(h.comment) + '</div>' : '') + '</div>'
        ).join('') + '</div>'
      : '<div class="cr-sub">No history yet.</div>';

    // notes
    const notes = (r.notes || []).map(n =>
      '<div class="cr-note"><div class="cr-note-txt">' + esc(n.crn_note) + '</div>' +
        '<div class="cr-note-meta">' + esc(n.crn_author_name || 'Staff') + ' · ' + esc(fmtDate(n.crn_created_at)) + '</div></div>'
    ).join('');

    document.getElementById('crDrawerBody').innerHTML =
      '<div class="cr-sec"><div class="cr-sec-h">Update status</div>' + adv + '</div>' +
      '<div class="cr-sec"><div class="cr-sec-h">Details</div><dl class="cr-kv">' + (kv || '<dd>No field data.</dd>') + '</dl></div>' +
      '<div class="cr-sec"><div class="cr-sec-h">Internal notes</div>' +
        '<div style="display:flex;gap:6px;margin-bottom:10px">' +
          '<input type="text" id="crNoteIn" class="nu-input" placeholder="Add a private note…" style="flex:1" onkeydown="if(event.key===\'Enter\')NuCR.addNote()">' +
          '<button class="nu-btn nu-btn-secondary nu-btn-sm" onclick="NuCR.addNote()">Add</button></div>' +
        (notes || '<div class="cr-sub">No notes yet.</div>') +
      '</div>' +
      '<div class="cr-sec"><div class="cr-sec-h">History</div>' + tl + '</div>';
  }

  async function advance(instanceId, transitionId, requireComment) {
    let comment = '';
    if (requireComment) { comment = prompt('Add a comment for this step:') || ''; if (!comment.trim()) { toast('A comment is required', 'error'); return; } }
    try {
      await api('advance', { method: 'POST', body: { id: S.current.cr_id, instance_id: instanceId, transition_id: transitionId, comment } });
      toast('Status updated', 'success');
      await openRequest(S._reqId);           // refresh drawer
      loadBoard();                            // refresh board behind it
    } catch (e) { toast(e.message, 'error'); }
  }

  async function addNote() {
    const el = document.getElementById('crNoteIn'); const note = (el.value || '').trim();
    if (!note) return;
    try { await api('add_note', { method: 'POST', body: { id: S.current.cr_id, record: S._reqId, note } }); el.value = ''; openRequest(S._reqId); }
    catch (e) { toast(e.message, 'error'); }
  }

  /* ── Manual create ─────────────────────────────────────────────────────── */
  function openCreate() {
    openDrawer('<h3>New request</h3>');
    const fields = (S.current.fields || []).filter(f => !['file', 'signaturepad', 'uploadbutton'].includes(f.type));
    const inputs = fields.map(f => {
      let ctl;
      if (f.type === 'textarea') ctl = '<textarea class="nu-input" data-f="' + esc(f.name) + '" rows="3"></textarea>';
      else if (f.type === 'select' && f.options.length) ctl = '<select class="nu-input" data-f="' + esc(f.name) + '"><option value="">—</option>' + f.options.map(o => '<option value="' + esc(o.value) + '">' + esc(o.label) + '</option>').join('') + '</select>';
      else ctl = '<input type="' + (f.type === 'email' ? 'email' : f.type === 'number' ? 'number' : f.type === 'date' ? 'date' : 'text') + '" class="nu-input" data-f="' + esc(f.name) + '">';
      return '<div class="cr-field"><label>' + esc(f.label) + (f.required ? ' *' : '') + '</label>' + ctl + '</div>';
    }).join('');
    document.getElementById('crDrawerBody').innerHTML =
      '<p class="cr-sub" style="margin-bottom:14px">Log a request received by phone, email or in person. It enters the pipeline like any other.</p>' +
      inputs +
      '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:6px"><button class="nu-btn nu-btn-primary" onclick="NuCR.doCreate()">Create request</button></div>';
  }

  async function doCreate() {
    const values = {};
    document.querySelectorAll('#crDrawerBody [data-f]').forEach(el => { if (el.value !== '') values[el.getAttribute('data-f')] = el.value; });
    if (!Object.keys(values).length) { toast('Fill in at least one field', 'error'); return; }
    try { await api('create_request', { method: 'POST', body: { id: S.current.cr_id, values } }); toast('Request created', 'success'); closeDrawer(); loadBoard(); }
    catch (e) { toast(e.message, 'error'); }
  }

  /* ── Settings ──────────────────────────────────────────────────────────── */
  function openSettings() {
    const c = S.current;
    openDrawer('<h3>⚙ ' + esc(c.cr_name) + ' settings</h3>');
    const pub = c.public || {};
    document.getElementById('crDrawerBody').innerHTML =
      '<div class="cr-sec"><div class="cr-sec-h">Channel</div>' +
        '<div class="cr-field"><label>Name</label><input type="text" id="setName" class="nu-input" value="' + esc(c.cr_name) + '"></div>' +
        '<div class="cr-row2">' +
          '<div class="cr-field"><label>Icon</label><input type="text" id="setIcon" class="nu-input" maxlength="4" value="' + esc(c.cr_icon || '') + '"></div>' +
          '<div class="cr-field"><label>Reference prefix</label><input type="text" id="setPrefix" class="nu-input" maxlength="16" value="' + esc(c.cr_reference_prefix || '') + '"></div>' +
        '</div>' +
        '<div class="cr-field"><label>Description</label><input type="text" id="setDesc" class="nu-input" value="' + esc(c.cr_description || '') + '"></div>' +
      '</div>' +
      '<div class="cr-sec"><div class="cr-sec-h">Notifications & public intake</div>' +
        '<div class="cr-field"><label>Notify staff email(s)</label><input type="text" id="setNotify" class="nu-input" value="' + esc(c.cr_notify_email || '') + '" placeholder="ops@example.com"></div>' +
        '<div class="cr-field"><div class="cr-switch"><input type="checkbox" id="setPublic"' + (pub.enabled ? ' checked' : '') + '><label for="setPublic" style="margin:0;cursor:pointer">Public intake link enabled</label></div></div>' +
        (pub.url ? '<div class="cr-field"><div class="cr-linkbox"><input type="text" class="nu-input" readonly value="' + esc(pub.url) + '" id="setUrl"><button class="nu-btn nu-btn-secondary nu-btn-sm" onclick="NuCR.copyUrl()">Copy</button></div></div>' : '') +
      '</div>' +
      '<div style="display:flex;gap:8px;margin:6px 0 4px"><button class="nu-btn nu-btn-primary" onclick="NuCR.saveSettings()">Save changes</button></div>' +
      '<div class="cr-sec"><div class="cr-sec-h">Customise</div><div class="cr-deeplinks">' +
        deeplink('📝', 'Edit the intake form', 'Fields, validation, layout & theme', "NuCR.goModule('forms')") +
        (c.cr_workflow_code ? deeplink('🔀', 'Edit the status pipeline', 'Stages, transitions, SLA & automations', "NuCR.goModule('workflow')") : deeplink('🔀', 'Add a status pipeline', 'Design stages in the Workflow module, bound to this form', "NuCR.goModule('workflow')")) +
        deeplink('✉️', 'Edit email templates', 'Customer & staff notification wording', "NuCR.goModule('email_settings')") +
      '</div></div>' +
      '<div class="cr-sec"><div class="cr-sec-h">Connect to other modules</div>' +
        '<p class="cr-sub">In the Workflow module, add a <b>transition hook</b> (update_record / create_record / call_webhook) to a stage — e.g. mark a linked order “Completed” when a request is Resolved. These run automatically as requests move through the pipeline.</p></div>' +
      '<div class="cr-sec"><div class="cr-sec-h" style="color:#ef4444">Danger zone</div>' +
        '<button class="nu-btn nu-btn-ghost nu-btn-sm" style="color:#ef4444;border-color:#ef4444" onclick="NuCR.deleteChannel()">Remove this channel</button>' +
        '<div class="cr-field-hint">The form, table and workflow are kept — only the channel link is removed.</div></div>';
  }
  function deeplink(ico, t, d, onclick) {
    return '<div class="cr-deeplink"><div><div class="cr-deeplink-t">' + ico + ' ' + esc(t) + '</div><div class="cr-deeplink-d">' + esc(d) + '</div></div>' +
      '<button class="nu-btn nu-btn-secondary nu-btn-sm" onclick="' + onclick + '">Open →</button></div>';
  }
  async function saveSettings() {
    const body = {
      id: S.current.cr_id,
      name: val('setName'), icon: val('setIcon'), reference_prefix: val('setPrefix'),
      description: val('setDesc'), notify_email: val('setNotify'),
      public_enabled: (document.getElementById('setPublic') || {}).checked ? 1 : 0,
    };
    try {
      const j = await api('save_config', { method: 'POST', body });
      S.current = j.config;
      S.configs = S.configs.map(c => c.cr_id === j.config.cr_id ? j.config : c);
      toast('Settings saved', 'success'); closeDrawer(); renderBoard();
    } catch (e) { toast(e.message, 'error'); }
  }
  function copyUrl() { const i = document.getElementById('setUrl'); if (!i) return; i.select(); (navigator.clipboard ? navigator.clipboard.writeText(i.value) : Promise.reject()).then(() => toast('Link copied', 'success'), () => { document.execCommand('copy'); toast('Link copied', 'success'); }); }
  function goModule(m) { closeDrawer(); if (window.NuApp && NuApp.loadModule) NuApp.loadModule(m); }
  async function deleteChannel() {
    if (!confirm('Remove this customer-request channel? The underlying form, table and workflow are kept.')) return;
    try {
      await api('delete_config', { method: 'POST', body: { id: S.current.cr_id } });
      toast('Channel removed', 'success'); closeDrawer();
      const list = await api('list_configs'); S.configs = list.configs || []; S.forms = list.forms || [];
      if (S.configs.length) { S.current = S.configs[0]; loadBoard(); } else renderSetup();
    } catch (e) { toast(e.message, 'error'); }
  }

  /* ── utils ─────────────────────────────────────────────────────────────── */
  function val(id) { const e = document.getElementById(id); return e ? e.value.trim() : ''; }
  function fmtDate(s) { if (!s) return ''; const d = new Date(s.replace(' ', 'T')); return isNaN(d) ? s : d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }); }

  window.NuCR = {
    init, pickTpl, doSetup, cancelSetup, switchChannel, startNewChannel,
    openRequest, advance, addNote, openCreate, doCreate,
    openSettings, saveSettings, copyUrl, goModule, deleteChannel,
    closeDrawer, onSearch,
  };
  init();
})();
</script>
