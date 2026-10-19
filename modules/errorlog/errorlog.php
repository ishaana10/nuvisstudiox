<?php
// Guard — loaded inside index.php which already has session + DB
/*if (!isset($_SESSION['nu_user_id'])) {
    echo '<p class="nu-alert nu-alert-danger">Access denied.</p>';
    return;
}*/
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
?>
<!-- ═══════════════════════════════════════════════════════════════════════════
     Nuvis StudioX Error Log Viewer
     Captures PHP / SQL / JS / APP errors and displays them in a searchable
     table. Full context + stack trace visible on row click.
══════════════════════════════════════════════════════════════════════════════ -->
<div id="nu-errorlog-module">

  <!-- ── Toolbar ──────────────────────────────────────────────────────────── -->
  <div class="nu-toolbar" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:16px;">
    <h2 style="margin:0;flex:1;font-size:1.3rem;">🪲 Error Log</h2>

    <!-- Type filter -->
    <select id="el-filter-type" class="nu-input" style="width:110px;">
      <option value="">All Types</option>
      <option value="PHP">PHP</option>
      <option value="SQL">SQL</option>
      <option value="JS">JS</option>
      <option value="APP">APP</option>
    </select>

    <!-- Severity filter -->
    <select id="el-filter-sev" class="nu-input" style="width:120px;">
      <option value="">All Severities</option>
      <option value="fatal">Fatal</option>
      <option value="error">Error</option>
      <option value="warning">Warning</option>
      <option value="info">Info</option>
      <option value="debug">Debug</option>
    </select>

    <!-- Search -->
    <input id="el-search" type="text" class="nu-input" placeholder="Search message / file…" style="width:220px;">
    <button class="nu-btn nu-btn-primary" onclick="elLoad(1)">🔍 Search</button>
    <button class="nu-btn" onclick="elClear()">🗑 Clear All</button>
    <button class="nu-btn" onclick="elLoad(1)" title="Refresh">↻ Refresh</button>
  </div>

  <!-- ── Stats bar ────────────────────────────────────────────────────────── -->
  <div id="el-stats" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px;"></div>

  <!-- ── Table ────────────────────────────────────────────────────────────── -->
  <div style="overflow-x:auto;">
    <table class="nu-table" id="el-table" style="width:100%;font-size:0.85rem;">
      <thead>
        <tr>
          <th style="width:50px;">#</th>
          <th style="width:60px;">Type</th>
          <th style="width:80px;">Severity</th>
          <th>Message</th>
          <th style="width:200px;">File : Line</th>
          <th style="width:160px;">Request URI</th>
          <th style="width:80px;">User</th>
          <th style="width:140px;">Date / Time</th>
          <th style="width:60px;">Action</th>
        </tr>
      </thead>
      <tbody id="el-tbody">
        <tr><td colspan="9" style="text-align:center;padding:30px;">Loading…</td></tr>
      </tbody>
    </table>
  </div>

  <!-- ── Pagination ───────────────────────────────────────────────────────── -->
  <div id="el-pagination" style="display:flex;gap:8px;align-items:center;margin-top:12px;flex-wrap:wrap;"></div>

  <!-- ── Detail drawer ────────────────────────────────────────────────────── -->
  <div id="el-drawer" style="
      display:none;
      position:fixed;right:0;top:0;bottom:0;width:520px;max-width:100vw;
      background:var(--nu-surface,#fff);border-left:2px solid var(--nu-border,#ddd);
      box-shadow:-4px 0 20px rgba(0,0,0,.15);z-index:9999;
      overflow-y:auto;padding:24px;font-size:0.85rem;
  ">
    <button onclick="elCloseDrawer()" style="float:right;background:none;border:none;font-size:1.4rem;cursor:pointer;">&times;</button>
    <h3 id="el-drawer-title" style="margin-top:0;">Error Detail</h3>
    <div id="el-drawer-body"></div>
  </div>
  <div id="el-drawer-bg" onclick="elCloseDrawer()" style="
      display:none;position:fixed;inset:0;background:rgba(0,0,0,.3);z-index:9998;
  "></div>

</div>

<style>
#nu-errorlog-module .nu-table { border-collapse:collapse;width:100%; }
#nu-errorlog-module .nu-table th,
#nu-errorlog-module .nu-table td { border:1px solid var(--nu-border,#e0e0e0);padding:6px 8px;vertical-align:top; }
#nu-errorlog-module .nu-table thead th { background:var(--nu-surface-alt,#f5f5f5);font-weight:600; }
#nu-errorlog-module .nu-table tbody tr:hover { background:var(--nu-hover,#f0f4ff);cursor:pointer; }
.el-badge { display:inline-block;padding:2px 7px;border-radius:10px;font-size:0.75rem;font-weight:600;white-space:nowrap; }
.el-type-PHP  { background:#dbeafe;color:#1d4ed8; }
.el-type-SQL  { background:#fef3c7;color:#92400e; }
.el-type-JS   { background:#fce7f3;color:#9d174d; }
.el-type-APP  { background:#d1fae5;color:#065f46; }
.el-sev-fatal   { background:#fee2e2;color:#991b1b; }
.el-sev-error   { background:#fed7aa;color:#9a3412; }
.el-sev-warning { background:#fef9c3;color:#713f12; }
.el-sev-info    { background:#dbeafe;color:#1e40af; }
.el-sev-debug   { background:#f3f4f6;color:#374151; }
.el-stat-card { background:var(--nu-surface-alt,#f5f5f5);border:1px solid var(--nu-border,#e0e0e0);border-radius:8px;padding:8px 16px;min-width:90px;text-align:center; }
.el-stat-card .el-stat-num { font-size:1.5rem;font-weight:700; }
.el-stat-card .el-stat-label { font-size:0.72rem;color:#666; }
pre.el-pre { background:#1e1e1e;color:#d4d4d4;padding:12px;border-radius:6px;overflow-x:auto;font-size:0.78rem;white-space:pre-wrap;word-break:break-all; }
.el-detail-row { margin-bottom:10px; }
.el-detail-row label { display:block;font-size:0.72rem;font-weight:600;color:#666;text-transform:uppercase;margin-bottom:2px; }
</style>

<script>
(function() {
  'use strict';

  let elCurrentPage = 1;

  // ── Load / render table ─────────────────────────────────────────────────
  window.elLoad = function(page) {
    page = page || 1;
    elCurrentPage = page;
    const type     = document.getElementById('el-filter-type').value;
    const sev      = document.getElementById('el-filter-sev').value;
    const search   = document.getElementById('el-search').value.trim();
    const params   = new URLSearchParams({
      action: 'list', page, per_page: 50,
      ...(type   ? {type}     : {}),
      ...(sev    ? {severity: sev} : {}),
      ...(search ? {search}   : {}),
    });

    fetch('api/errorlog.php?' + params)
      .then(r => r.json())
      .then(data => {
        if (!data.success) { elTableMsg(data.error || 'Error'); return; }
        elRenderTable(data.rows);
        elRenderPagination(data.page, data.pages, data.total);
      })
      .catch(e => elTableMsg('Fetch error: ' + e.message));

    elLoadStats();
  };

  function elRenderTable(rows) {
    const tbody = document.getElementById('el-tbody');
    if (!rows || rows.length === 0) {
      tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:30px;color:#888;">No errors recorded 🎉</td></tr>';
      return;
    }
    tbody.innerHTML = rows.map(r => `
      <tr onclick="elOpenDrawer(${r.errlog_id})">
        <td>${r.errlog_id}</td>
        <td><span class="el-badge el-type-${r.errlog_type}">${r.errlog_type}</span></td>
        <td><span class="el-badge el-sev-${r.errlog_severity}">${r.errlog_severity}</span></td>
        <td style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${elEsc(r.errlog_message)}">${elEsc(r.errlog_message)}</td>
        <td style="font-family:monospace;font-size:0.78rem;">${elEsc(r.errlog_file || '')}${ r.errlog_line ? ':' + r.errlog_line : ''}</td>
        <td style="font-size:0.78rem;word-break:break-all;">${elEsc(r.errlog_request_uri || '')}</td>
        <td>${elEsc(r.errlog_user_name || '')}</td>
        <td style="white-space:nowrap;">${r.errlog_created_at || ''}</td>
        <td><button class="nu-btn" style="padding:2px 8px;font-size:0.75rem;" onclick="event.stopPropagation();elDeleteRow(${r.errlog_id})">✕</button></td>
      </tr>
    `).join('');
  }

  function elRenderPagination(page, pages, total) {
    const el = document.getElementById('el-pagination');
    let html = `<span style="font-size:0.82rem;color:#666;">${total} record${total !== 1 ? 's' : ''}</span>`;
    if (pages > 1) {
      html += `<button class="nu-btn" ${ page <= 1 ? 'disabled' : ''} onclick="elLoad(${page-1})">‹ Prev</button>`;
      const start = Math.max(1, page - 2);
      const end   = Math.min(pages, page + 2);
      for (let i = start; i <= end; i++) {
        html += `<button class="nu-btn ${ i === page ? 'nu-btn-primary' : '' }" onclick="elLoad(${i})">${i}</button>`;
      }
      html += `<button class="nu-btn" ${ page >= pages ? 'disabled' : ''} onclick="elLoad(${page+1})">Next ›</button>`;
    }
    el.innerHTML = html;
  }

  function elTableMsg(msg) {
    document.getElementById('el-tbody').innerHTML =
      `<tr><td colspan="9" style="text-align:center;padding:30px;color:#c00;">${elEsc(msg)}</td></tr>`;
  }

  // ── Stats bar ───────────────────────────────────────────────────────────
  function elLoadStats() {
    fetch('api/errorlog.php?action=stats')
      .then(r => r.json())
      .then(data => {
        if (!data.success) return;
        const totals = { PHP:0, SQL:0, JS:0, APP:0 };
        const sevTotals = {};
        (data.counts || []).forEach(c => {
          totals[c.errlog_type] = (totals[c.errlog_type] || 0) + parseInt(c.cnt);
          if (['error','fatal'].includes(c.errlog_severity)) {
            sevTotals[c.errlog_severity] = (sevTotals[c.errlog_severity] || 0) + parseInt(c.cnt);
          }
        });
        const cards = [
          ...Object.entries(totals).map(([t,n]) =>
            `<div class="el-stat-card"><div class="el-stat-num">${n}</div><div class="el-stat-label">${t} errors</div></div>`),
          ...(sevTotals.fatal ? [`<div class="el-stat-card" style="border-color:#f87171;"><div class="el-stat-num" style="color:#dc2626;">${sevTotals.fatal}</div><div class="el-stat-label">FATAL</div></div>`] : []),
        ];
        document.getElementById('el-stats').innerHTML = cards.join('');
        if (data.last_error_at) {
          document.getElementById('el-stats').innerHTML +=
            `<div class="el-stat-card" style="min-width:180px;"><div class="el-stat-label">Last Error</div><div style="font-size:0.82rem;">${data.last_error_at}</div></div>`;
        }
      }).catch(() => {});
  }

  // ── Drawer ──────────────────────────────────────────────────────────────
  window.elOpenDrawer = function(id) {
    fetch('api/errorlog.php?action=get&id=' + id)
      .then(r => r.json())
      .then(data => {
        if (!data.success) return;
        const r = data.row;
        let ctx = '';
        if (r.errlog_context) {
          const obj = (typeof r.errlog_context === 'object') ? r.errlog_context : JSON.parse(r.errlog_context);
          ctx = `<div class="el-detail-row"><label>Context</label><pre class="el-pre">${elEsc(JSON.stringify(obj, null, 2))}</pre></div>`;
        }
        let trace = '';
        if (r.errlog_trace) {
          trace = `<div class="el-detail-row"><label>Stack Trace</label><pre class="el-pre">${elEsc(r.errlog_trace)}</pre></div>`;
        }
        document.getElementById('el-drawer-title').innerHTML =
          `<span class="el-badge el-type-${r.errlog_type}">${r.errlog_type}</span>
           <span class="el-badge el-sev-${r.errlog_severity}" style="margin-left:6px;">${r.errlog_severity}</span>
           <span style="margin-left:8px;font-size:0.9rem;">#${r.errlog_id}</span>`;
        document.getElementById('el-drawer-body').innerHTML = `
          <div class="el-detail-row"><label>Message</label><div style="word-break:break-word;">${elEsc(r.errlog_message)}</div></div>
          <div class="el-detail-row"><label>File</label><code>${elEsc(r.errlog_file || '')}${ r.errlog_line ? ' : line ' + r.errlog_line : ''}</code></div>
          <div class="el-detail-row"><label>Request</label><code>${elEsc(r.errlog_request_method || '')} ${elEsc(r.errlog_request_uri || '')}</code></div>
          <div class="el-detail-row"><label>User</label>${elEsc(r.errlog_user_name || '(guest)')}</div>
          <div class="el-detail-row"><label>Time</label>${r.errlog_created_at}</div>
          ${ctx}${trace}
        `;
        document.getElementById('el-drawer').style.display    = 'block';
        document.getElementById('el-drawer-bg').style.display = 'block';
      }).catch(() => {});
  };

  window.elCloseDrawer = function() {
    document.getElementById('el-drawer').style.display    = 'none';
    document.getElementById('el-drawer-bg').style.display = 'none';
  };

  // ── Actions ─────────────────────────────────────────────────────────────
  window.elClear = function() {
    if (!confirm('Delete ALL error log entries? This cannot be undone.')) return;
    fetch('api/errorlog.php?action=clear', { method: 'POST' })
      .then(r => r.json())
      .then(d => { if (d.success) elLoad(1); })
      .catch(() => {});
  };

  window.elDeleteRow = function(id) {
    fetch('api/errorlog.php?action=delete&id=' + id, { method: 'POST' })
      .then(r => r.json())
      .then(d => { if (d.success) elLoad(elCurrentPage); })
      .catch(() => {});
  };

  // ── Keyboard ────────────────────────────────────────────────────────────
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') elCloseDrawer();
  });
  document.getElementById('el-search').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') elLoad(1);
  });

  // ── XSS helper ──────────────────────────────────────────────────────────
  function elEsc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  // ── Auto-load on mount ──────────────────────────────────────────────────
  elLoad(1);

})();
</script>
