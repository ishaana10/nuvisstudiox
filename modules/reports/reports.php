<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
?>

<!-- WebDataRocks CSS -->
<link rel="stylesheet" href="https://cdn.webdatarocks.com/latest/webdatarocks.min.css">

<div id="rpt-app">

  <!-- ── TOOLBAR ─────────────────────────────────────────────────────── -->
  <div class="rpt-toolbar">
    <div class="rpt-toolbar-left">
      <h2 class="rpt-title">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3h18v4H3z"/><path d="M3 10h11v4H3z"/><path d="M3 17h7v4H3z"/></svg>
        Reports
      </h2>
      <span id="rptBadge" class="rpt-badge">0</span>
    </div>
    <div class="rpt-toolbar-right">
      <input type="search" class="nu-input" id="rptSearch" placeholder="Search reports…" aria-label="Search reports" oninput="rptFilterList(this.value)" style="width:220px;height:32px;">
      <button class="nu-btn nu-btn-ghost nu-btn-sm" id="rptRefreshBtn" onclick="rptLoadList()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
        Refresh
      </button>
      <button class="nu-btn nu-btn-primary nu-btn-sm" onclick="rptOpenBuilder(null)">
        + New Report
      </button>
    </div>
  </div>

  <!-- LIST VIEW -->
  <div id="rptListView">
    <div id="rptTable" class="rpt-table-wrap"></div>
  </div>

  <!-- BUILDER VIEW -->
  <div id="rptBuilderView" style="display:none;">
    <div class="rpt-builder-header">
      <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="rptShowList()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
        Back
      </button>
      <h3 id="rptBuilderTitle" style="margin:0;font-size:var(--text-base);font-weight:600;">New Report</h3>
      <div style="display:flex;gap:8px;">
        <button class="nu-btn nu-btn-ghost nu-btn-sm" id="rptPreviewBtn" onclick="rptPreview()">&#9654; Preview</button>
        <button class="nu-btn nu-btn-primary nu-btn-sm" onclick="rptSave()">Save Report</button>
      </div>
    </div>

    <div class="rpt-builder-body">
      <!-- LEFT: config -->
      <div class="rpt-builder-config">
        <input type="hidden" id="rptId">

        <div class="rpt-field-group">
          <label>Report Name <span style="color:var(--color-error)">*</span></label>
          <input type="text" class="nu-input" id="rptName" placeholder="Sales Summary">
        </div>

        <div class="rpt-field-row">
          <div class="rpt-field-group">
            <label>Report Code</label>
            <input type="text" class="nu-input" id="rptCode" placeholder="auto">
          </div>
          <div class="rpt-field-group">
            <label>Type</label>
            <select class="nu-input" id="rptType">
              <option value="table">Table</option>
              <option value="chart">Chart / Summary</option>
            </select>
          </div>
        </div>

        <div class="rpt-field-group">
          <label>Default View Mode</label>
          <div class="rpt-view-tabs" id="rptViewTabs">
            <button class="rpt-vtab active" data-mode="table" onclick="rptSetViewMode('table',this)">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="3" x2="9" y2="21"/></svg>
              Table
            </button>
            <button class="rpt-vtab" data-mode="webdatarocks" onclick="rptSetViewMode('webdatarocks',this)">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="9" height="9"/><rect x="13" y="2" width="9" height="9"/><rect x="2" y="13" width="9" height="9"/><rect x="13" y="13" width="9" height="9"/></svg>
              Pivot (WDR)
            </button>
            <button class="rpt-vtab" data-mode="chart" onclick="rptSetViewMode('chart',this)">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
              Chart
            </button>
          </div>
          <input type="hidden" id="rptViewMode" value="table">
        </div>

        <div class="rpt-field-group" style="flex:1;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;gap:6px;flex-wrap:wrap;">
            <label>SQL Query <span style="color:var(--color-error)">*</span></label>
            <div style="display:flex;gap:4px;">
              <button type="button" class="nu-btn nu-btn-ghost" style="font-size:11px;padding:2px 8px;" onclick="rptToggleSqlHelper()">Schema</button>
              <button type="button" class="nu-btn nu-btn-ghost" style="font-size:11px;padding:2px 8px;" onclick="rptFormatSql()">Format</button>
            </div>
          </div>
          <div id="rptSqlHelper" style="display:none;height:240px;margin-bottom:8px;"><div id="rptSchema" style="height:100%;"></div></div>
          <div id="rptSqlEditor"></div>
          <textarea id="rptSql" hidden></textarea>
          <p class="rpt-hint">SELECT queries only. <kbd>Ctrl/&#8984;+Enter</kbd> previews, <kbd>Ctrl/&#8984;+S</kbd> saves.</p>
        </div>

        <div class="rpt-field-group">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
            <label>Column Labels <span style="color:var(--color-text-faint);font-weight:400;">(optional)</span></label>
            <span style="display:flex;gap:4px;"><button class="nu-btn nu-btn-ghost" style="font-size:11px;padding:2px 6px;" onclick="rptDetectColumns()" title="Run the SQL and fill in the columns it returns">Detect from SQL</button><button class="nu-btn nu-btn-ghost" style="font-size:11px;padding:2px 6px;" onclick="rptAddColRow()">+ Add column</button></span>
          </div>
          <div id="rptColsList" style="display:flex;flex-direction:column;gap:6px;"></div>
        </div>

        <div class="rpt-field-group">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
            <label>Run-time Filters <span style="color:var(--color-text-faint);font-weight:400;">(optional)</span></label>
            <button class="nu-btn nu-btn-ghost" style="font-size:11px;padding:2px 6px;" onclick="rptAddFilterRow()">+ Add filter</button>
          </div>
          <div id="rptFiltersList" style="display:flex;flex-direction:column;gap:6px;"></div>
          <p class="rpt-hint">Filter fields must match column names in your query result.</p>
        </div>

        <!-- PDF Export Settings Section -->
        <div class="rpt-field-group" style="border-top:1px solid var(--color-border);padding-top:15px;margin-top:10px;">
          <label style="font-size:11px;font-weight:700;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.04em;">PDF Export Settings</label>

          <div class="rpt-field-row" style="margin-top:8px;">
            <div class="rpt-field-group">
              <label>Orientation</label>
              <select class="nu-input" id="rptPdfOrientation">
                <option value="P">Portrait</option>
                <option value="L">Landscape</option>
              </select>
            </div>
            <div class="rpt-field-group">
              <label>Page Format</label>
              <select class="nu-input" id="rptPdfFormat">
                <option value="A4">A4</option>
                <option value="LETTER">Letter</option>
                <option value="LEGAL">Legal</option>
              </select>
            </div>
          </div>

          <div class="rpt-field-group" style="margin-top:8px;">
            <label>Margins (mm - Top, Right, Bottom, Left)</label>
            <div style="display:flex;gap:8px;">
              <input type="number" class="nu-input" id="rptPdfMarginTop" placeholder="Top" value="15" style="width:25%;">
              <input type="number" class="nu-input" id="rptPdfMarginRight" placeholder="Right" value="15" style="width:25%;">
              <input type="number" class="nu-input" id="rptPdfMarginBottom" placeholder="Bottom" value="15" style="width:25%;">
              <input type="number" class="nu-input" id="rptPdfMarginLeft" placeholder="Left" value="15" style="width:25%;">
            </div>
          </div>

          <div class="rpt-field-group" style="margin-top:8px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
              <label>HTML Template</label>
              <div style="display:flex;gap:4px;">
                <button type="button" class="nu-btn nu-btn-ghost" style="font-size:10px;padding:2px 6px;" onclick="rptLoadStarterTemplate('invoice')">Invoice</button>
                <button type="button" class="nu-btn nu-btn-ghost" style="font-size:10px;padding:2px 6px;" onclick="rptLoadStarterTemplate('receipt')">Receipt</button>
                <button type="button" class="nu-btn nu-btn-ghost" style="font-size:10px;padding:2px 6px;" onclick="rptLoadStarterTemplate('certificate')">Cert</button>
              </div>
            </div>
            <textarea class="nu-input" id="rptPdfTemplate" rows="12" placeholder="HTML and CSS template for the PDF..." style="font-family:monospace;font-size:13px;resize:vertical;"></textarea>
            <p class="rpt-hint">Supports HTML/CSS, <code>{{report_name}}</code>, <code>{{current_date}}</code>, <code>{{total_records}}</code>, <code>{{company_name}}</code>, and <code>{{loop}} ... {{column_name}} ... {{/loop}}</code> loops.</p>
          </div>
        </div>
      </div>

      <!-- RIGHT: preview pane -->
      <div class="rpt-builder-preview">
        <div class="rpt-preview-header">
          <span style="font-size:var(--text-sm);font-weight:500;">Preview</span>
          <span id="rptPreviewStatus" class="rpt-hint">Click &#9654; Preview to run</span>
        </div>
        <div id="rptPreviewOutput" style="flex:1;overflow:auto;padding:12px;">
          <div class="rpt-empty-state">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--color-text-faint);"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="3" x2="9" y2="21"/></svg>
            <p>Write a SQL query and click &#9654; Preview to see the data here</p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- RUN VIEW -->
  <div id="rptRunView" style="display:none;">
    <div class="rpt-builder-header">
      <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="rptShowList()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
        Back
      </button>
      <h3 id="rptRunTitle" style="margin:0;font-size:var(--text-base);font-weight:600;"></h3>
      <div style="display:flex;gap:8px;">
        <div id="rptViewSwitcher" style="display:flex;gap:4px;"></div>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" id="rptExportCsvBtn" onclick="rptExportCsv()">&#11015; CSV</button>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" id="rptExportPdfBtn" onclick="rptExportPdf()">&#11015; PDF</button>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" id="rptEmailPdfBtn" onclick="rptOpenEmailModal()">&#9993; Email PDF</button>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="window.print()">&#128424; Print</button>
      </div>
    </div>
    <div id="rptFilterBar" style="display:none;padding:10px 16px;border-bottom:1px solid var(--color-border);display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;"></div>
    <div id="rptRunOutput" style="flex:1;overflow:auto;padding:16px;min-height:300px;"></div>
  </div>

  <!-- EMAIL MODAL -->
  <div id="rptEmailModal" class="nu-modal-backdrop" style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="nu-modal-content" style="background: var(--color-surface); width: 450px; border-radius: var(--radius-lg); overflow: hidden; box-shadow: var(--shadow-lg); border: 1px solid var(--color-border); padding: 20px; display: flex; flex-direction: column; gap: 15px;">
      <div style="display: flex; justify-content: space-between; align-items: center;">
        <h4 style="margin: 0; font-size: 16px; font-weight: 600;">Email Report PDF</h4>
        <button class="rpt-remove-btn" onclick="rptCloseEmailModal()" style="font-size: 16px; font-weight: bold; background: none; border: none; cursor: pointer;">✕</button>
      </div>
      <div class="rpt-field-group">
        <label>Recipient Email <span style="color:var(--color-error)">*</span></label>
        <input type="email" class="nu-input" id="rptEmailTo" placeholder="client@example.com">
      </div>
      <div class="rpt-field-group">
        <label>Subject</label>
        <input type="text" class="nu-input" id="rptEmailSubject" placeholder="Your Report PDF">
      </div>
      <div class="rpt-field-group">
        <label>Optional Message</label>
        <textarea class="nu-input" id="rptEmailMessage" rows="4" placeholder="Hello, please find attached the report PDF..."></textarea>
      </div>
      <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px;">
        <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="rptCloseEmailModal()">Cancel</button>
        <button class="nu-btn nu-btn-primary nu-btn-sm" onclick="rptSendEmailPdf()">Send Email</button>
      </div>
    </div>
  </div>

</div>

<style>
#rpt-app {
  display:flex;
  flex-direction:column;
  height:100%;
  font-family:var(--font-body,sans-serif);
}
.rpt-toolbar {
  display:flex;
  justify-content:space-between;
  align-items:center;
  padding:12px 20px;
  border-bottom:1px solid var(--color-border);
  background:var(--color-surface);
  flex-shrink:0;
}
.rpt-toolbar-left { display:flex;align-items:center;gap:10px; }
.rpt-toolbar-right { display:flex;align-items:center;gap:8px; }
.rpt-title {
  margin:0;
  font-size:var(--text-base);
  font-weight:600;
  display:flex;
  align-items:center;
  gap:6px;
}
.rpt-badge {
  background:var(--color-primary);
  color:var(--color-text-inverse,#fff);
  border-radius:var(--radius-full);
  font-size:11px;
  padding:1px 7px;
  font-weight:600;
}
#rptListView { flex:1;overflow:auto;padding:16px 20px; }
.rpt-table-wrap {
  background:var(--color-surface);
  border:1px solid var(--color-border);
  border-radius:var(--radius-lg);
  overflow:hidden;
}
.rpt-list-table { width:100%;border-collapse:collapse; }
.rpt-list-table th {
  background:var(--color-bg);
  padding:10px 16px;
  text-align:left;
  font-size:var(--text-xs);
  font-weight:600;
  color:var(--color-text-muted);
  text-transform:uppercase;
  letter-spacing:0.04em;
  border-bottom:1px solid var(--color-border);
}
.rpt-list-table td {
  padding:11px 16px;
  font-size:var(--text-sm);
  border-bottom:1px solid var(--color-divider);
  vertical-align:middle;
}
.rpt-list-table tr:last-child td { border-bottom:none; }
.rpt-list-table tr:hover td { background:var(--color-surface-offset); }
.rpt-type-badge {
  display:inline-block;
  padding:2px 8px;
  border-radius:var(--radius-full);
  font-size:11px;
  font-weight:600;
  text-transform:uppercase;
  letter-spacing:0.03em;
}
.rpt-type-table  { background:var(--color-primary-highlight);color:var(--color-primary); }
.rpt-type-chart  { background:var(--color-orange-highlight);color:var(--color-orange); }
.rpt-type-summary{ background:var(--color-gold-highlight);color:var(--color-gold); }
.rpt-mode-badge {
  display:inline-block;
  padding:2px 7px;
  border-radius:var(--radius-full);
  font-size:11px;
  background:var(--color-surface-offset-2);
  color:var(--color-text-muted);
}
#rptBuilderView, #rptRunView {
  display:flex;
  flex-direction:column;
  flex:1;
  overflow:hidden;
}
.rpt-builder-header {
  display:flex;
  align-items:center;
  gap:12px;
  padding:10px 16px;
  border-bottom:1px solid var(--color-border);
  background:var(--color-surface);
  flex-shrink:0;
}
.rpt-builder-header h3 { flex:1; }
.rpt-builder-body {
  display:grid;
  grid-template-columns:420px 1fr;
  flex:1;
  overflow:hidden;
}
.rpt-builder-config {
  border-right:1px solid var(--color-border);
  overflow-y:auto;
  padding:16px;
  display:flex;
  flex-direction:column;
  gap:14px;
}
.rpt-builder-preview {
  display:flex;
  flex-direction:column;
  overflow:hidden;
}
.rpt-preview-header {
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding:8px 14px;
  border-bottom:1px solid var(--color-border);
  background:var(--color-bg);
  flex-shrink:0;
}
.rpt-field-group { display:flex;flex-direction:column;gap:4px; }
.rpt-field-group > label {
  font-size:var(--text-xs);
  font-weight:600;
  color:var(--color-text-muted);
  text-transform:uppercase;
  letter-spacing:0.04em;
}
.rpt-field-row { display:grid;grid-template-columns:1fr 1fr;gap:10px; }
.rpt-hint { font-size:var(--text-xs);color:var(--color-text-faint);margin-top:3px; }
.rpt-view-tabs { display:flex;gap:4px; }
.rpt-vtab {
  display:flex;
  align-items:center;
  gap:5px;
  padding:5px 10px;
  border:1px solid var(--color-border);
  border-radius:var(--radius-md);
  font-size:var(--text-xs);
  font-weight:500;
  cursor:pointer;
  background:var(--color-surface);
  color:var(--color-text-muted);
  transition:all 150ms;
}
.rpt-vtab:hover { background:var(--color-surface-offset); color:var(--color-text); }
.rpt-vtab.active {
  background:var(--color-primary);
  color:#fff;
  border-color:var(--color-primary);
}
.rpt-col-row, .rpt-filter-row {
  display:flex;
  gap:6px;
  align-items:center;
}
.rpt-col-row input, .rpt-filter-row input, .rpt-filter-row select {
  flex:1;
  min-width:0;
}
.rpt-remove-btn {
  color:var(--color-text-faint);
  cursor:pointer;
  padding:4px;
  border-radius:var(--radius-sm);
  line-height:1;
  background:none;
  border:none;
}
.rpt-remove-btn:hover { color:var(--color-error); }
.rpt-output-table { width:100%;border-collapse:collapse;font-size:var(--text-sm); }
.rpt-output-table th {
  background:var(--color-bg);
  padding:9px 12px;
  text-align:left;
  font-size:var(--text-xs);
  font-weight:600;
  color:var(--color-text-muted);
  text-transform:uppercase;
  letter-spacing:0.04em;
  border-bottom:2px solid var(--color-border);
  position:sticky;
  top:0;
  z-index:1;
}
.rpt-output-table td {
  padding:9px 12px;
  border-bottom:1px solid var(--color-divider);
  font-variant-numeric:tabular-nums;
}
.rpt-output-table tr:last-child td { border-bottom:none; }
.rpt-output-table tr:hover td { background:var(--color-surface-offset); }
.rpt-output-wrap {
  background:var(--color-surface);
  border:1px solid var(--color-border);
  border-radius:var(--radius-lg);
  overflow:auto;
}
.rpt-empty-state {
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:center;
  gap:12px;
  padding:60px 20px;
  color:var(--color-text-muted);
  text-align:center;
}
.rpt-empty-state p { font-size:var(--text-sm);max-width:300px; }
.rpt-row-actions { display:flex;gap:6px;opacity:0;transition:opacity 150ms; }
.rpt-list-table tr:hover .rpt-row-actions { opacity:1; }
#rptWdrContainer { width:100%;height:100%;min-height:400px; }
@media print {
  .rpt-toolbar, .rpt-builder-header, #rptFilterBar button, .nu-sidebar, .nu-topbar { display:none!important; }
  #rpt-app { height:auto; }
  .rpt-output-table th { background:#f0f0f0!important; }
}
</style>

<script src="https://cdn.webdatarocks.com/latest/webdatarocks.js"></script>

<script>
(function () {

  // ── toast helper ────────────────────────────────────────────────────
  function toast(msg, type) {
    type = type || 'info';
    if (window.NuApp && typeof NuApp.toast === 'function') { NuApp.toast(msg, type); return; }
    if (typeof nuToast === 'function') { nuToast(msg, type); return; }
    console.warn('[RPT]', type, msg);
  }

  // ── SPA-safe reload ──────────────────────────────────────────────
  function reloadModule() {
    if (window.NuApp && typeof NuApp.loadModule === 'function') {
      NuApp.loadModule('reports');
    } else {
      location.reload();
    }
  }

  // ── state ─────────────────────────────────────────────────────────
  var _currentViewMode  = 'table';
  var _runData          = [];
  var _runColumns       = [];
  var _wdrPivot         = null;
  var _currentReportId  = null;
  var _editor           = null;
  var _chart            = null;
  var _chartCfg         = { type: 'bar', label: '', value: '' };
  var _reports          = [];
  var _runElapsed       = null;

  var API = 'api/report.php';

  // ── boot (defer so DOM is injected by SPA) ──────────────────────────
  setTimeout(function () {
    rptLoadList();
    rptMountSqlEditor();
  }, 0);

  // ── SQL editor (shared NuSqlEditor + NuSchema) ──────────────────────
  function rptMountSqlEditor() {
    var host = document.getElementById('rptSqlEditor');
    if (!host || !window.NuSqlEditor) return;
    if (_editor) { try { _editor.destroy(); } catch (e) {} }
    _editor = NuSqlEditor.mount(host, {
      value: document.getElementById('rptSql').value, minLines: 8,
      placeholder: 'SELECT status, COUNT(*) AS total FROM nu_forms GROUP BY status',
      onChange: function (v) { document.getElementById('rptSql').value = v; },
      onRun: function () { window.rptPreview(); },
      onSave: function () { window.rptSave(); }
    });
    if (window.NuSchema) {
      NuSchema.mount(document.getElementById('rptSchema'), {
        onLoaded: function (t) { if (_editor) _editor.setSchema(t); },
        onPick: function (name) { if (_editor) _editor.insert('`' + name + '`'); },
        onInsertSelect: function (table) { rptSetSql('SELECT *\nFROM `' + table + '`\nLIMIT 100'); }
      });
    }
  }
  function rptSetSql(v) {
    document.getElementById('rptSql').value = v || '';
    if (_editor) _editor.setValue(v || '');
  }
  function rptGetSql() { return (_editor ? _editor.getValue() : document.getElementById('rptSql').value).trim(); }
  window.rptFormatSql = function () { if (_editor) _editor.format(); };

  // ── list ────────────────────────────────────────────────────────────
  window.rptLoadList = async function () {
    try {
      var res  = await fetch(API + '?action=list', { credentials: 'same-origin' });
      var j    = await res.json();
      var rows = j.data || [];
      _reports = rows;
      document.getElementById('rptBadge').textContent = rows.length;
      var tbody = rows.length
        ? rows.map(function (r) {
            return '<tr data-search="' + esc((r.report_name + ' ' + r.report_code + ' ' + r.report_type).toLowerCase()) + '">' +
              '<td><code style="background:var(--color-surface-offset);padding:2px 6px;border-radius:var(--radius-sm);font-size:12px;">' + esc(r.report_code) + '</code></td>' +
              '<td style="font-weight:500;">' + esc(r.report_name) + '</td>' +
              '<td><span class="rpt-type-badge rpt-type-' + esc(r.report_type) + '">' + esc(r.report_type) + '</span></td>' +
              '<td><span class="rpt-mode-badge">' + esc(r.report_view_mode || 'table') + '</span></td>' +
              '<td style="color:var(--color-text-muted);">' + fmtDate(r.report_created_at) + '</td>' +
              '<td><div class="rpt-row-actions">' +
                '<button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="rptRun(' + r.report_id + ')">&#9654; Run</button>' +
                '<button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="rptQuickPdf(' + r.report_id + ')">PDF</button>' +
                '<button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="rptOpenBuilder(' + r.report_id + ')">Edit</button>' +
                '<button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="rptDuplicate(' + r.report_id + ')">Copy</button>' +
                '<button class="nu-btn nu-btn-ghost nu-btn-sm" style="color:var(--color-error);" onclick="rptDelete(' + r.report_id + ',\'' + esc(r.report_name) + '\')">Del</button>' +
              '</div></td>' +
            '</tr>';
          }).join('')
        : '<tr><td colspan="6" style="padding:48px;text-align:center;color:var(--color-text-muted);">No reports yet — click + New Report to create one.</td></tr>';
      document.getElementById('rptTable').innerHTML =
        '<table class="rpt-list-table"><thead><tr>' +
        '<th>Code</th><th>Name</th><th>Type</th><th>View</th><th>Created</th><th>Actions</th>' +
        '</tr></thead><tbody>' + tbody + '</tbody></table>';
      var sb = document.getElementById('rptSearch'); if (sb && sb.value) rptFilterList(sb.value);
    } catch (e) {
      document.getElementById('rptTable').innerHTML =
        '<p style="padding:20px;color:var(--color-error);">Error loading reports: ' + e.message + '</p>';
    }
  };

  window.rptFilterList = function (q) {
    q = String(q || '').trim().toLowerCase();
    document.querySelectorAll('#rptTable tbody tr[data-search]').forEach(function (tr) {
      tr.style.display = (!q || tr.getAttribute('data-search').indexOf(q) !== -1) ? '' : 'none';
    });
  };

  window.rptDuplicate = async function (id) {
    try {
      var res = await fetch(API + '?action=duplicate&id=' + id, { credentials: 'same-origin' });
      var j   = await res.json();
      if (!j.success) throw new Error(j.error);
      toast('Report duplicated', 'success');
      rptLoadList();
    } catch (e) { toast('Duplicate failed: ' + e.message, 'error'); }
  };

  // ── navigation ───────────────────────────────────────────────────
  window.rptShowList = function () {
    document.getElementById('rptListView').style.display    = '';
    document.getElementById('rptBuilderView').style.display = 'none';
    document.getElementById('rptRunView').style.display     = 'none';
    if (_wdrPivot) { try { _wdrPivot.dispose(); } catch (e) {} _wdrPivot = null; }
    if (_chart) { try { _chart.destroy(); } catch (e) {} _chart = null; }
    rptLoadList();
  };

  // ── open builder ────────────────────────────────────────────────
  window.rptOpenBuilder = async function (id) {
    rptResetBuilder();
    if (id) {
      try {
        var res = await fetch(API + '?action=get&id=' + id, { credentials: 'same-origin' });
        var j   = await res.json();
        if (!j.success) throw new Error(j.error);
        var r = j.data;
        document.getElementById('rptId').value   = r.report_id;
        document.getElementById('rptName').value = r.report_name;
        document.getElementById('rptCode').value = r.report_code;
        document.getElementById('rptType').value = r.report_type;
        rptSetSql(r.report_sql || '');
        rptSetViewMode(r.report_view_mode || 'table');
        (r.report_columns || []).forEach(function (c) { rptAddColRow(c.field, c.label); });
        (r.report_filters || []).forEach(function (f) { rptAddFilterRow(f.field, f.label, f.operator); });

        // Populate PDF Export Settings
        var pdfSettings = r.report_pdf_settings || {};
        document.getElementById('rptPdfOrientation').value = pdfSettings.orientation || 'P';
        document.getElementById('rptPdfFormat').value      = pdfSettings.format || 'A4';
        document.getElementById('rptPdfMarginTop').value   = (pdfSettings.margins && pdfSettings.margins.top !== undefined) ? pdfSettings.margins.top : '15';
        document.getElementById('rptPdfMarginRight').value = (pdfSettings.margins && pdfSettings.margins.right !== undefined) ? pdfSettings.margins.right : '15';
        document.getElementById('rptPdfMarginBottom').value= (pdfSettings.margins && pdfSettings.margins.bottom !== undefined) ? pdfSettings.margins.bottom : '15';
        document.getElementById('rptPdfMarginLeft').value  = (pdfSettings.margins && pdfSettings.margins.left !== undefined) ? pdfSettings.margins.left : '15';
        document.getElementById('rptPdfTemplate').value    = r.report_pdf_template || '';

        document.getElementById('rptBuilderTitle').textContent = 'Edit: ' + r.report_name;
      } catch (e) {
        toast('Could not load report: ' + e.message, 'error');
        return;
      }
    }
    document.getElementById('rptListView').style.display    = 'none';
    document.getElementById('rptRunView').style.display     = 'none';
    document.getElementById('rptBuilderView').style.display = '';
    if (_editor && _editor.resize) setTimeout(function () { _editor.resize(); }, 30);
  };

  function rptResetBuilder() {
    document.getElementById('rptId').value    = '';
    document.getElementById('rptName').value  = '';
    document.getElementById('rptCode').value  = '';
    document.getElementById('rptType').value  = 'table';
    rptSetSql('');
    document.getElementById('rptColsList').innerHTML    = '';
    document.getElementById('rptFiltersList').innerHTML = '';

    // Reset PDF fields
    document.getElementById('rptPdfOrientation').value = 'P';
    document.getElementById('rptPdfFormat').value      = 'A4';
    document.getElementById('rptPdfMarginTop').value   = '15';
    document.getElementById('rptPdfMarginRight').value = '15';
    document.getElementById('rptPdfMarginBottom').value= '15';
    document.getElementById('rptPdfMarginLeft').value  = '15';
    document.getElementById('rptPdfTemplate').value    = '';

    document.getElementById('rptPreviewOutput').innerHTML =
      '<div class="rpt-empty-state"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--color-text-faint);"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="3" x2="9" y2="21"/></svg><p>Write a SQL query and click &#9654; Preview to see the data here</p></div>';
    document.getElementById('rptPreviewStatus').textContent = 'Click &#9654; Preview to run';
    document.getElementById('rptBuilderTitle').textContent  = 'New Report';
    rptSetViewMode('table');
  }

  // ── view mode ────────────────────────────────────────────────────
  window.rptSetViewMode = function (mode, btn) {
    _currentViewMode = mode;
    document.getElementById('rptViewMode').value = mode;
    document.querySelectorAll('.rpt-vtab').forEach(function (b) { b.classList.remove('active'); });
    var target = btn || document.querySelector('.rpt-vtab[data-mode="' + mode + '"]');
    if (target) target.classList.add('active');
  };

  // ── SQL helper ──────────────────────────────────────────────────
  window.rptToggleSqlHelper = function () {
    var el = document.getElementById('rptSqlHelper');
    el.style.display = el.style.display === 'none' ? '' : 'none';
  };

  window.rptDetectColumns = async function () {
    var sql = rptGetSql();
    if (!sql) { toast('Enter a SQL query first', 'error'); return; }
    try {
      var res = await fetch(API + '?action=preview', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ report_sql: sql, report_columns: [], report_filters: [], limit: 1 }) });
      var j = await res.json();
      if (!j.success) throw new Error(j.error);
      var have = {};
      document.querySelectorAll('#rptColsList [data-col-field]').forEach(function (i) { have[i.value.trim()] = 1; });
      var added = 0;
      (j.columns || []).forEach(function (c) { if (!have[c.field]) { rptAddColRow(c.field, c.label || c.field); added++; } });
      toast(added ? added + ' column(s) added' : 'Columns already listed', 'success');
    } catch (e) { toast('Could not detect columns: ' + e.message, 'error'); }
  };

  // ── col / filter rows ─────────────────────────────────────────────
  window.rptAddColRow = function (field, label) {
    field = field || ''; label = label || '';
    var div = document.createElement('div');
    div.className = 'rpt-col-row';
    div.innerHTML =
      '<input type="text" class="nu-input" placeholder="field_name" value="' + esc(field) + '" data-col-field>' +
      '<input type="text" class="nu-input" placeholder="Label" value="' + esc(label) + '" data-col-label>' +
      '<button class="rpt-remove-btn" onclick="this.parentElement.remove()" title="Remove">✕</button>';
    document.getElementById('rptColsList').appendChild(div);
  };

  window.rptAddFilterRow = function (field, label, op) {
    field = field || ''; label = label || ''; op = op || '=';
    var div = document.createElement('div');
    div.className = 'rpt-filter-row';
    div.innerHTML =
      '<input type="text" class="nu-input" placeholder="field" value="' + esc(field) + '" data-f-field>' +
      '<input type="text" class="nu-input" placeholder="Label" value="' + esc(label) + '" data-f-label>' +
      '<select class="nu-input" data-f-op style="max-width:90px;">' +
        '<option value="="'  + (op === '='    ? ' selected' : '') + '>= exact</option>' +
        '<option value="LIKE"' + (op === 'LIKE' ? ' selected' : '') + '>LIKE</option>' +
        '<option value=">="' + (op === '>='   ? ' selected' : '') + '>&ge;</option>' +
        '<option value="<="' + (op === '<='   ? ' selected' : '') + '>&le;</option>' +
      '</select>' +
      '<button class="rpt-remove-btn" onclick="this.parentElement.remove()" title="Remove">✕</button>';
    document.getElementById('rptFiltersList').appendChild(div);
  };

  // ── save ──────────────────────────────────────────────────────────
  window.rptSave = async function () {
    var columns = Array.from(document.querySelectorAll('#rptColsList .rpt-col-row')).map(function (row) {
      return { field: row.querySelector('[data-col-field]').value.trim(), label: row.querySelector('[data-col-label]').value.trim() };
    }).filter(function (c) { return c.field; });

    var filters = Array.from(document.querySelectorAll('#rptFiltersList .rpt-filter-row')).map(function (row) {
      return {
        field:    row.querySelector('[data-f-field]').value.trim(),
        label:    row.querySelector('[data-f-label]').value.trim(),
        operator: row.querySelector('[data-f-op]').value,
      };
    }).filter(function (f) { return f.field; });

    var pdfSettings = {
      orientation: document.getElementById('rptPdfOrientation').value,
      format:      document.getElementById('rptPdfFormat').value,
      margins: {
        top:    parseFloat(document.getElementById('rptPdfMarginTop').value) || 15,
        right:  parseFloat(document.getElementById('rptPdfMarginRight').value) || 15,
        bottom: parseFloat(document.getElementById('rptPdfMarginBottom').value) || 15,
        left:   parseFloat(document.getElementById('rptPdfMarginLeft').value) || 15
      }
    };

    var payload = {
      report_id:        document.getElementById('rptId').value || 0,
      report_name:      document.getElementById('rptName').value.trim(),
      report_code:      document.getElementById('rptCode').value.trim(),
      report_type:      document.getElementById('rptType').value,
      report_view_mode: document.getElementById('rptViewMode').value,
      report_sql:       rptGetSql(),
      report_columns:   columns,
      report_filters:   filters,
      report_pdf_template: document.getElementById('rptPdfTemplate').value,
      report_pdf_settings: pdfSettings
    };

    if (!payload.report_name) { toast('Report name is required', 'error'); return; }
    if (!payload.report_sql)  { toast('SQL query is required', 'error');   return; }

    try {
      var res = await fetch(API + '?action=save', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      var j = await res.json();
      if (!j.success) throw new Error(j.error);
      toast('Report saved', 'success');
      setTimeout(function () { rptShowList(); }, 300);
    } catch (e) {
      toast('Save failed: ' + e.message, 'error');
    }
  };

  // ── delete ─────────────────────────────────────────────────────────
  window.rptDelete = async function (id, name) {
    if (!confirm('Delete report "' + name + '"?')) return;
    try {
      var res = await fetch(API + '?action=delete', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: id }),
      });
      var j = await res.json();
      if (!j.success) throw new Error(j.error);
      toast('Report deleted', 'success');
      rptLoadList();
    } catch (e) { toast('Delete failed: ' + e.message, 'error'); }
  };

  // ── preview ─────────────────────────────────────────────────────
  window.rptPreview = async function () {
    var sql = rptGetSql();
    if (!sql) { toast('Enter a SQL query first', 'error'); return; }
    document.getElementById('rptPreviewStatus').textContent = 'Running…';
    document.getElementById('rptPreviewOutput').innerHTML = '<div class="rpt-empty-state"><p>Loading…</p></div>';
    try {
      // Use action=preview (correct endpoint) with POST body containing the raw SQL
      var res = await fetch(API + '?action=preview', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          limit: 200,
          report_sql:     sql,
          report_columns: [],
          report_filters: [],
        }),
      });
      var j = await res.json();
      if (!j.success) throw new Error(j.error);
      _runData    = j.data    || [];
      _runColumns = j.columns || [];
      document.getElementById('rptPreviewStatus').textContent = '';
      NuResultGrid.render(document.getElementById('rptPreviewOutput'), { columns: _runColumns, rows: _runData, filename: (document.getElementById('rptCode').value || 'report') + '_preview.csv', meta: { elapsed_ms: j.elapsed_ms, limit: j.limit, truncated: j.limit && _runData.length >= j.limit } });
    } catch (e) {
      document.getElementById('rptPreviewStatus').textContent = 'Error';
      document.getElementById('rptPreviewOutput').innerHTML   =
        '<p style="color:var(--color-error);padding:12px;">' + esc(e.message) + '</p>';
    }
  };

  // ── run report ───────────────────────────────────────────────────
  window.rptRun = async function (id) {
    _currentReportId = id;
    try {
      var defRes = await fetch(API + '?action=get&id=' + id, { credentials: 'same-origin' });
      var defJ   = await defRes.json();
      if (!defJ.success) { toast(defJ.error, 'error'); return; }
      var def = defJ.data;

      document.getElementById('rptListView').style.display    = 'none';
      document.getElementById('rptBuilderView').style.display = 'none';
      document.getElementById('rptRunView').style.display     = '';
      document.getElementById('rptRunTitle').textContent      = def.report_name;
      _currentViewMode = def.report_view_mode || 'table';

      var filters    = def.report_filters || [];
      var filterBar  = document.getElementById('rptFilterBar');
      filterBar.innerHTML = '';
      if (filters.length) {
        filterBar.style.display = 'flex';
        filters.forEach(function (f) {
          var label = f.label || f.field;
          var wrap  = document.createElement('div');
          wrap.style.cssText = 'display:flex;flex-direction:column;gap:3px;';
          wrap.innerHTML =
            '<label style="font-size:11px;font-weight:600;color:var(--color-text-muted);text-transform:uppercase;">' + esc(label) + '</label>' +
            '<input type="text" class="nu-input" id="rptFilter_' + esc(f.field) + '" placeholder="' + esc(f.label || f.field) + '" style="width:160px;">';
          filterBar.appendChild(wrap);
        });
        var runBtn = document.createElement('button');
        runBtn.className   = 'nu-btn nu-btn-primary nu-btn-sm';
        runBtn.textContent = 'Apply Filters';
        runBtn.style.alignSelf = 'flex-end';
        runBtn.onclick = function () { rptFetchAndRender(id); };
        filterBar.appendChild(runBtn);
      } else {
        filterBar.style.display = 'none';
      }

      var switcher = document.getElementById('rptViewSwitcher');
      switcher.innerHTML = '';
      [['table','Table'],['webdatarocks','Pivot'],['chart','Chart']].forEach(function (pair) {
        var mode = pair[0], label = pair[1];
        var b = document.createElement('button');
        b.className    = 'rpt-vtab' + (mode === _currentViewMode ? ' active' : '');
        b.dataset.mode = mode;
        b.textContent  = label;
        b.onclick = function () {
          _currentViewMode = mode;
          switcher.querySelectorAll('.rpt-vtab').forEach(function (x) { x.classList.remove('active'); });
          b.classList.add('active');
          rptRenderOutput();
        };
        switcher.appendChild(b);
      });

      await rptFetchAndRender(id);
    } catch (e) {
      toast('Failed to run report: ' + e.message, 'error');
    }
  };

  async function rptFetchAndRender(id) {
    var filterBar = document.getElementById('rptFilterBar');
    var params    = new URLSearchParams({ action: 'run', id: id });
    filterBar.querySelectorAll('input[id^="rptFilter_"]').forEach(function (inp) {
      var field = inp.id.replace('rptFilter_', '');
      if (inp.value.trim()) params.append(field, inp.value.trim());
    });
    document.getElementById('rptRunOutput').innerHTML = '<div class="rpt-empty-state"><p>Loading…</p></div>';
    try {
      var res = await fetch(API + '?' + params.toString(), { credentials: 'same-origin' });
      var j   = await res.json();
      if (!j.success) throw new Error(j.error);
      _runData    = j.data    || [];
      _runColumns = j.columns || [];
      _runElapsed = j.elapsed_ms;
      rptRenderOutput();
    } catch (e) {
      document.getElementById('rptRunOutput').innerHTML =
        '<p style="color:var(--color-error);padding:16px;">' + esc(e.message) + '</p>';
    }
  }

  function rptRenderOutput() {
    var out = document.getElementById('rptRunOutput');
    if (_currentViewMode === 'webdatarocks') {
      rptRenderWDR(out);
    } else if (_currentViewMode === 'chart') {
      rptRenderChart(out);
    } else {
      NuResultGrid.render(out, { columns: _runColumns, rows: _runData, filename: 'report_' + new Date().toISOString().slice(0, 10) + '.csv', meta: { elapsed_ms: _runElapsed } });
    }
  }

  // ── WebDataRocks render ──────────────────────────────────────────
  function rptRenderWDR(container) {
    if (_wdrPivot) { try { _wdrPivot.dispose(); } catch (e) {} _wdrPivot = null; }
    container.innerHTML = '<div id="rptWdrContainer"></div>';
    _wdrPivot = new WebDataRocks({
      container: '#rptWdrContainer',
      toolbar: true,
      report: {
        dataSource: { data: _runData },
        options: { grid: { type: 'flat', showGrandTotals: 'off' } },
        formats: [{ name: '', thousandsSeparator: ',', decimalPlaces: 2 }],
      },
    });
  }

  // ── Chart render (Chart.js, loaded on demand) ─────────────────────
  var CHARTJS = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js';
  function rptLoadChartJs() {
    if (window.Chart) return Promise.resolve();
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script'); s.src = CHARTJS; s.onload = resolve; s.onerror = function () { reject(new Error('Could not load Chart.js')); };
      document.head.appendChild(s);
    });
  }
  function rptRenderChart(container) {
    if (_chart) { try { _chart.destroy(); } catch (e) {} _chart = null; }
    if (!_runData.length || !_runColumns.length) {
      container.innerHTML = '<div class="rpt-empty-state"><p>No data for chart.</p></div>';
      return;
    }
    var fields = _runColumns.map(function (c) { return c.field; });
    var numeric = fields.filter(function (f) { return _runData.some(function (r) { return r[f] !== null && r[f] !== '' && !isNaN(parseFloat(r[f])); }); });
    if (!_chartCfg.label || fields.indexOf(_chartCfg.label) < 0) _chartCfg.label = fields[0];
    if (!_chartCfg.value || fields.indexOf(_chartCfg.value) < 0) _chartCfg.value = numeric.filter(function (f) { return f !== _chartCfg.label; })[0] || numeric[0] || fields[fields.length > 1 ? 1 : 0];
    var lab = function (f) { var c = _runColumns.filter(function (x) { return x.field === f; })[0]; return (c && c.label) || f; };
    var opts = function (list, sel) { return list.map(function (f) { return '<option value="' + esc(f) + '"' + (f === sel ? ' selected' : '') + '>' + esc(lab(f)) + '</option>'; }).join(''); };
    var types = [['bar', 'Bar'], ['line', 'Line'], ['pie', 'Pie'], ['doughnut', 'Doughnut']];
    container.innerHTML =
      '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:12px;">' +
        '<label style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-tertiary);display:flex;flex-direction:column;gap:3px;">Type<select class="nu-input" id="rptChType" style="height:32px;">' + types.map(function (t) { return '<option value="' + t[0] + '"' + (t[0] === _chartCfg.type ? ' selected' : '') + '>' + t[1] + '</option>'; }).join('') + '</select></label>' +
        '<label style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-tertiary);display:flex;flex-direction:column;gap:3px;">Labels<select class="nu-input" id="rptChLabel" style="height:32px;">' + opts(fields, _chartCfg.label) + '</select></label>' +
        '<label style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-tertiary);display:flex;flex-direction:column;gap:3px;">Values<select class="nu-input" id="rptChValue" style="height:32px;">' + opts(fields, _chartCfg.value) + '</select></label>' +
      '</div><div style="position:relative;height:380px;"><canvas id="rptChartCanvas"></canvas></div>';
    ['rptChType', 'rptChLabel', 'rptChValue'].forEach(function (id) {
      document.getElementById(id).addEventListener('change', function () {
        _chartCfg.type = document.getElementById('rptChType').value;
        _chartCfg.label = document.getElementById('rptChLabel').value;
        _chartCfg.value = document.getElementById('rptChValue').value;
        rptRenderChart(container);
      });
    });
    rptLoadChartJs().then(function () {
      var canvas = document.getElementById('rptChartCanvas'); if (!canvas) return;
      var cs = getComputedStyle(document.documentElement);
      var primary = cs.getPropertyValue('--primary').trim() || '#2563eb';
      var text = cs.getPropertyValue('--text-secondary').trim() || '#555';
      var grid = cs.getPropertyValue('--border-color').trim() || '#ddd';
      var palette = [primary, '#16a34a', '#d97706', '#9333ea', '#0891b2', '#dc2626', '#65a30d', '#db2777', '#4f46e5', '#0d9488'];
      var rows = _runData.slice(0, 200);
      var labels = rows.map(function (r) { return r[_chartCfg.label] === null ? '(null)' : String(r[_chartCfg.label]); });
      var values = rows.map(function (r) { return parseFloat(r[_chartCfg.value]) || 0; });
      var round = _chartCfg.type === 'pie' || _chartCfg.type === 'doughnut';
      _chart = new Chart(canvas, {
        type: _chartCfg.type,
        data: { labels: labels, datasets: [{ label: lab(_chartCfg.value), data: values,
          backgroundColor: round ? values.map(function (v, i) { return palette[i % palette.length]; }) : primary + (_chartCfg.type === 'line' ? '33' : 'cc'),
          borderColor: round ? 'transparent' : primary, borderWidth: round ? 0 : 2, fill: _chartCfg.type === 'line', tension: .3, borderRadius: _chartCfg.type === 'bar' ? 4 : 0 }] },
        options: { responsive: true, maintainAspectRatio: false,
          plugins: { legend: { display: round, labels: { color: text } } },
          scales: round ? {} : { x: { ticks: { color: text }, grid: { color: grid } }, y: { beginAtZero: true, ticks: { color: text }, grid: { color: grid } } } }
      });
    }).catch(function (e) { container.innerHTML = '<div class="rpt-empty-state"><p>' + esc(e.message) + '</p></div>'; });
  }

  // ── CSV export ──────────────────────────────────────────────────
  window.rptExportCsv = function () {
    if (!_runData.length) { toast('No data to export', 'error'); return; }
    var header = _runColumns.map(function (c) { return '"' + (c.label || c.field) + '"'; }).join(',');
    var rows   = _runData.map(function (row) {
      return _runColumns.map(function (c) {
        return '"' + String(row[c.field] !== null && row[c.field] !== undefined ? row[c.field] : '').replace(/"/g, '""') + '"';
      }).join(',');
    });
    var csv  = [header].concat(rows).join('\n');
    var blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    var a    = document.createElement('a');
    a.href   = URL.createObjectURL(blob);
    a.download = 'report_' + (new Date().toISOString().slice(0,10)) + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(a.href);
  };

  window.rptQuickPdf = function (id) {
    window.open(API + '?action=export_pdf&id=' + id, '_blank');
  };

  window.rptExportPdf = function () {
    if (!_currentReportId) { toast('No report loaded', 'error'); return; }
    var filterBar = document.getElementById('rptFilterBar');
    var params    = new URLSearchParams({ action: 'export_pdf', id: _currentReportId });
    filterBar.querySelectorAll('input[id^="rptFilter_"]').forEach(function (inp) {
      var field = inp.id.replace('rptFilter_', '');
      if (inp.value.trim()) params.append(field, inp.value.trim());
    });
    window.open(API + '?' + params.toString(), '_blank');
  };

  window.rptLoadStarterTemplate = async function (type) {
    if (!confirm('Load the starter template for ' + type + '? This will overwrite your current PDF template.')) return;
    try {
      var res = await fetch(API + '?action=starter_templates', { credentials: 'same-origin' });
      var j   = await res.json();
      if (j.success && j.data && j.data[type]) {
        document.getElementById('rptPdfTemplate').value = j.data[type];
        toast('Starter template loaded', 'success');
      } else {
        throw new Error(j.message || 'Template not found');
      }
    } catch (e) {
      toast('Failed to load template: ' + e.message, 'error');
    }
  };

  window.rptOpenEmailModal = function () {
    document.getElementById('rptEmailTo').value = '';
    document.getElementById('rptEmailSubject').value = 'Report PDF: ' + document.getElementById('rptRunTitle').textContent;
    document.getElementById('rptEmailMessage').value = '';
    document.getElementById('rptEmailModal').style.display = 'flex';
  };

  window.rptCloseEmailModal = function () {
    document.getElementById('rptEmailModal').style.display = 'none';
  };

  window.rptSendEmailPdf = async function () {
    var to = document.getElementById('rptEmailTo').value.trim();
    var subject = document.getElementById('rptEmailSubject').value.trim();
    var message = document.getElementById('rptEmailMessage').value.trim();

    if (!to) { toast('Recipient email is required', 'error'); return; }

    var filterBar = document.getElementById('rptFilterBar');
    var filters = {};
    filterBar.querySelectorAll('input[id^="rptFilter_"]').forEach(function (inp) {
      var field = inp.id.replace('rptFilter_', '');
      if (inp.value.trim()) filters[field] = inp.value.trim();
    });

    toast('Sending email...', 'info');
    try {
      var res = await fetch(API, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'email_pdf',
          id: _currentReportId,
          to: to,
          subject: subject,
          message: message,
          filters: filters
        })
      });
      var j = await res.json();
      if (j.success) {
        toast('Email sent successfully!', 'success');
        rptCloseEmailModal();
      } else {
        throw new Error(j.message || 'Unknown error');
      }
    } catch (e) {
      toast('Failed to send email: ' + e.message, 'error');
    }
  };

  // ── utils ────────────────────────────────────────────────────────
  function esc(s) {
    return String(s !== null && s !== undefined ? s : '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function fmtDate(d) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
  }

})();
</script>
