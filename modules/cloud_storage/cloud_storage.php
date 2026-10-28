<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

$user = $auth->getCurrentUser();
if (!$user || !in_array(strtolower((string)($user['usr_role'] ?? '')), ['globeadmin', 'admin'], true)) {
    http_response_code(403);
    exit('Access denied');
}
?>
<style>
.cs{display:flex;flex-direction:column;gap:16px;max-width:1000px}
.cs-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px}
.cs-card{border:2px solid var(--border-color);border-radius:var(--radius-md);padding:12px;cursor:pointer;background:var(--bg-card);text-align:left;font:inherit;color:inherit}
.cs-card.sel{border-color:var(--primary);box-shadow:0 0 0 3px rgba(37,99,235,.15)}
.cs-card b{display:block}.cs-card span{font-size:12px;color:var(--text-muted)}
.cs-f{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}
.cs label.l{display:block;font-size:12px;font-weight:600;margin-bottom:3px;color:var(--text-muted)}
.cs-help{font-size:12px;color:var(--text-muted);line-height:1.55}.cs-help code{background:var(--bg-subtle);padding:1px 5px;border-radius:4px;word-break:break-all}
.cs-badge{display:inline-block;font-size:11px;font-weight:600;padding:2px 8px;border-radius:999px;background:var(--bg-subtle)}
.cs-badge.ok{background:#dcfce7;color:#15803d}.cs-badge.bad{background:#fee2e2;color:#b91c1c}
.cs-msg{padding:10px 12px;border-radius:8px;font-size:13px;display:none;white-space:pre-wrap;word-break:break-word}
.cs-msg.ok{background:#dcfce7;color:#14532d}.cs-msg.bad{background:#fee2e2;color:#7f1d1d}
.cs ol{margin:6px 0 0 18px;padding:0}
</style>
<div class="cs" id="cs">
  <div class="cs-msg" id="csMsg"></div>
  <div class="nu-card" style="padding:18px">
    <h3 style="margin:0 0 4px;font-size:16px">Where should uploaded files go?</h3>
    <p class="cs-help" style="margin:0 0 12px">Files are always saved on this server first. If you choose a cloud service they are then copied to it automatically. If the cloud is unreachable the file is kept here and can be retried.</p>
    <div class="cs-grid" id="csProviders"></div>
  </div>

  <div class="nu-card" style="padding:18px" id="csSettings">
    <div class="cs-f" style="margin-bottom:12px">
      <div><label class="l">Folder in the cloud</label><input class="nu-input" id="csFolder" placeholder="Nuvis StudioX"></div>
      <div><label class="l">After a successful upload</label>
        <select class="nu-input" id="csMode"><option value="mirror">Keep a copy on this server too (recommended)</option><option value="cloud_only">Remove the copy from this server</option></select></div>
      <div style="align-self:end"><label style="font-size:13px"><input type="checkbox" id="csByProject"> Separate sub-folder per project</label></div>
    </div>
    <p class="cs-help" id="csModeNote" style="margin:0 0 12px">Files appear in the cloud as <code>Folder / Project / Year / Month / file</code>. "Remove the copy" saves server disk space, but form file fields that link straight to the uploads folder will then not show those files — use the Files and Documents pages (which read from the cloud) or keep a copy.</p>

    <div id="csProv"></div>

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px">
      <button class="nu-btn nu-btn-primary" id="csSave">Save settings</button>
      <button class="nu-btn nu-btn-ghost" id="csTest">Test connection</button>
    </div>
  </div>

  <div class="nu-card" style="padding:18px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px">
      <h3 style="margin:0;font-size:16px">Recent files</h3>
      <span style="display:flex;gap:8px;flex-wrap:wrap"><span id="csCounts" class="cs-help"></span>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" id="csRetry">Retry failed</button>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" id="csBackfill">Copy existing files to cloud</button></span>
    </div>
    <div class="nu-table-wrap"><table class="nu-table"><thead><tr><th>File</th><th>Size</th><th>Where</th><th>Status</th></tr></thead><tbody id="csRecent"></tbody></table></div>
  </div>
</div>

<script>
(function () {
  var API = 'api/cloud_storage.php', cfg = null, labels = {}, selected = 'local';
  var $ = function (id) { return document.getElementById(id); };
  var el = function (t, c, x) { var e = document.createElement(t); if (c) e.className = c; if (x !== undefined && x !== null) e.textContent = x; return e; };
  function msg(text, ok) { var m = $('csMsg'); m.className = 'cs-msg ' + (ok ? 'ok' : 'bad'); m.style.display = 'block'; m.textContent = text; m.scrollIntoView({ block: 'nearest' }); }
  function post(a, b) { return fetch(API + '?action=' + a, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b || {}) }).then(function (r) { return r.json(); }); }
  function get(a) { return fetch(API + '?action=' + a, { credentials: 'same-origin' }).then(function (r) { return r.json(); }); }

  var DEF = {
    gdrive: { title: 'Google Drive', fields: [['client_id', 'Client ID', 'text'], ['client_secret', 'Client secret', 'password']],
      steps: ['Open <code>console.cloud.google.com</code> → create a project → <b>APIs &amp; Services → Library</b> → enable <b>Google Drive API</b>.', '<b>OAuth consent screen</b>: choose External, add yourself as a test user (or publish the app).', '<b>Credentials → Create credentials → OAuth client ID</b> → type <i>Web application</i>.', 'Add the redirect address shown below under <b>Authorised redirect URIs</b>, then paste the client ID and secret here.'],
      note: 'The app can only see files it created itself (drive.file access).' },
    onedrive: { title: 'OneDrive / SharePoint', fields: [['client_id', 'Application (client) ID', 'text'], ['client_secret', 'Client secret value', 'password'], ['tenant', 'Tenant (common, organizations, consumers or a tenant id)', 'text']],
      steps: ['Open <code>entra.microsoft.com</code> → <b>App registrations → New registration</b>.', 'Supported account types: choose what you need; redirect type <i>Web</i> with the address shown below.', '<b>Certificates &amp; secrets</b> → New client secret (copy the <i>Value</i>).', '<b>API permissions</b> → Microsoft Graph → Delegated → <i>Files.ReadWrite</i>, <i>offline_access</i>, <i>User.Read</i>.'],
      note: '' },
    dropbox: { title: 'Dropbox', fields: [['app_key', 'App key', 'text'], ['app_secret', 'App secret', 'password']],
      steps: ['Open <code>dropbox.com/developers/apps</code> → <b>Create app</b> → Scoped access → <i>App folder</i> (or Full Dropbox).', '<b>Permissions</b>: tick <i>files.content.write</i>, <i>files.content.read</i> and <i>account_info.read</i>, then Submit.', '<b>Settings</b>: add the redirect address shown below under <b>Redirect URIs</b>.', 'Copy the App key and App secret here.'],
      note: '' },
    webdav: { title: 'WebDAV', fields: [['url', 'WebDAV address', 'text'], ['username', 'Username', 'text'], ['password', 'Password / app password', 'password']],
      steps: ['Nextcloud / ownCloud: the address looks like <code>https://cloud.example.com/remote.php/dav/files/USERNAME</code>.', 'Create an <b>app password</b> in your account security settings and use it instead of your main password.'],
      note: 'Works with Nextcloud, ownCloud, Synology and most WebDAV servers.' }
  };

  function cards() {
    var box = $('csProviders'); box.replaceChildren();
    ['local', 'gdrive', 'onedrive', 'dropbox', 'webdav'].forEach(function (p) {
      var b = el('button', 'cs-card' + (p === selected ? ' sel' : '')); b.type = 'button';
      b.appendChild(el('b', null, labels[p] || p));
      var s = p === 'local' ? 'Default' : (cfg[p].connected ? 'Connected' + (cfg[p].account ? ': ' + cfg[p].account : '') : 'Not connected');
      b.appendChild(el('span', null, s));
      b.addEventListener('click', function () { selected = p; cards(); provForm(); });
      box.appendChild(b);
    });
  }

  function provForm() {
    var box = $('csProv'); box.replaceChildren();
    $('csSettings').style.opacity = selected === 'local' ? '.6' : '1';
    if (selected === 'local') { box.appendChild(el('p', 'cs-help', 'Files stay on this server only. Choose a cloud service above to also store them there.')); return; }
    var d = DEF[selected], c = cfg[selected];
    var head = el('div'); head.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:8px';
    head.appendChild(el('h4', null, d.title)); head.lastChild.style.margin = '0';
    head.appendChild(el('span', 'cs-badge ' + (c.connected ? 'ok' : ''), c.connected ? 'Connected' + (c.account ? ' · ' + c.account : '') : 'Not connected'));
    box.appendChild(head);
    var grid = el('div', 'cs-f');
    d.fields.forEach(function (f) {
      var w = el('div'); w.appendChild(el('label', 'l', f[1]));
      var i = el('input', 'nu-input'); i.type = f[2]; i.id = 'csf_' + f[0]; i.autocomplete = 'off';
      if (f[2] === 'password') { i.placeholder = c[f[0] + '_set'] ? '•••••••• (saved — leave blank to keep)' : ''; i.autocomplete = 'new-password'; } else i.value = c[f[0]] || '';
      w.appendChild(i); grid.appendChild(w);
    });
    box.appendChild(grid);
    if (selected !== 'webdav') {
      var ru = el('p', 'cs-help'); ru.style.marginTop = '10px';
      ru.appendChild(document.createTextNode('Redirect address to register: '));
      var code = el('code', null, cfg.effective_redirect_uri); ru.appendChild(code); box.appendChild(ru);
      var act = el('div'); act.style.cssText = 'display:flex;gap:8px;margin-top:8px';
      var cb = el('button', 'nu-btn nu-btn-primary nu-btn-sm', c.connected ? 'Reconnect account' : 'Connect account'); cb.type = 'button'; cb.addEventListener('click', connect); act.appendChild(cb);
      if (c.connected) { var db = el('button', 'nu-btn nu-btn-ghost nu-btn-sm', 'Disconnect'); db.type = 'button'; db.addEventListener('click', disconnect); act.appendChild(db); }
      box.appendChild(act);
      box.appendChild(el('p', 'cs-help', 'Save your client ID and secret first, then click Connect — you will be sent to ' + d.title + ' to approve access.'));
    }
    var det = el('details'); det.style.marginTop = '10px'; det.open = !c.connected;
    det.appendChild(el('summary', null, 'How to set this up')); det.firstChild.style.cssText = 'cursor:pointer;font-weight:600;font-size:13px';
    var ol = el('ol', 'cs-help'); d.steps.forEach(function (s) { var li = el('li'); li.innerHTML = s; ol.appendChild(li); }); det.appendChild(ol);
    if (d.note) det.appendChild(el('p', 'cs-help', d.note));
    box.appendChild(det);
  }

  function collect() {
    var out = { provider: selected, mode: $('csMode').value, folder: $('csFolder').value, by_project: $('csByProject').checked };
    if (selected !== 'local') {
      out[selected] = {};
      DEF[selected].fields.forEach(function (f) { var i = $('csf_' + f[0]); if (i) out[selected][f[0]] = i.value; });
    }
    return out;
  }
  function fillCommon() { $('csFolder').value = cfg.folder; $('csMode').value = cfg.mode; $('csByProject').checked = !!cfg.by_project; }

  function save() { return post('save_config', { config: collect() }).then(function (r) { if (!r.success) { msg(r.error || 'Save failed', false); return false; } cfg = r.config; msg('Settings saved.' + (r.warning ? ' ' + r.warning : ''), !r.warning); cards(); provForm(); return true; }); }
  function connect() { save().then(function (ok) { if (!ok) return; post('connect_url', { provider: selected }).then(function (r) { if (r.success) window.location.href = r.url; else msg(r.error, false); }); }); }
  function disconnect() { if (!confirm('Disconnect this account? Existing cloud files stay where they are but cannot be opened from here until you reconnect.')) return; post('disconnect', { provider: selected }).then(function (r) { if (r.success) { cfg = r.config; cards(); provForm(); msg('Disconnected.', true); } else msg(r.error, false); }); }

  $('csSave').addEventListener('click', save);
  $('csTest').addEventListener('click', function () { msg('Testing…', true); save().then(function (ok) { if (!ok) return; post('test', { provider: selected }).then(function (r) { msg(r.success ? r.message : r.error, !!r.success); load(true); }); }); });
  $('csRetry').addEventListener('click', function () { post('retry_failed').then(function (r) { msg(r.success ? (r.synced + ' synced, ' + r.failed + ' still failing' + (r.last_error ? ' (' + r.last_error + ')' : '')) : r.error, !!r.success && !r.failed); load(true); }); });
  $('csBackfill').addEventListener('click', function () { post('backfill').then(function (r) { msg(r.success ? (r.synced + ' copied, ' + r.failed + ' failed, ' + r.remaining + ' remaining. Click again to continue.') : r.error, !!r.success); load(true); }); });

  function recent() {
    get('recent').then(function (r) {
      var tb = $('csRecent'); tb.replaceChildren();
      if (!r.success || !r.rows.length) { var tr0 = el('tr'), td = el('td', null, 'No files yet.'); td.colSpan = 4; td.style.cssText = 'text-align:center;color:var(--text-muted)'; tr0.appendChild(td); tb.appendChild(tr0); return; }
      r.rows.forEach(function (f) {
        var tr = el('tr'); tr.appendChild(el('td', null, f.file_original_name || ''));
        tr.appendChild(el('td', null, f.file_size ? Math.max(1, Math.round(f.file_size / 1024)) + ' KB' : ''));
        tr.appendChild(el('td', null, f.file_remote_status === 'synced' ? (labels[f.file_storage] || f.file_storage) : 'This server'));
        var td = el('td'); var st = f.file_remote_status || 'none';
        var b = el('span', 'cs-badge ' + (st === 'synced' ? 'ok' : st === 'failed' ? 'bad' : ''), st === 'synced' ? 'In cloud' : st === 'failed' ? 'Failed' : 'Local only');
        if (st === 'failed' && f.file_remote_error) b.title = f.file_remote_error;
        td.appendChild(b); if (st === 'failed' && f.file_remote_error) { var s = el('div', 'cs-help', f.file_remote_error); td.appendChild(s); }
        tr.appendChild(td); tb.appendChild(tr);
      });
    });
  }

  function load(keep) {
    get('get_config').then(function (r) {
      if (!r.success) { msg(r.error || 'Failed to load', false); return; }
      cfg = r.config; labels = r.labels;
      if (!keep) selected = cfg.provider;
      if (!r.curl) msg('The PHP curl extension is not enabled on this server, so cloud storage cannot be used.', false);
      else if (!r.writable) msg('The application folder is not writable, so settings cannot be saved.', false);
      if (r.flash) msg(r.flash.message, !!r.flash.ok);
      var c = r.counts || {}; $('csCounts').textContent = (c.synced || 0) + ' in cloud · ' + (c.failed || 0) + ' failed · ' + (c.none || 0) + ' local only';
      fillCommon(); cards(); provForm(); recent();
    });
  }
  load();
})();
</script>
