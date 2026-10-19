<?php
/**
 * Nuvis StudioX – App Cloner Module
 * Admin UI: full clone wizard with live progress, SQL export, and dry-run.
 * Uses nub5 design system (nu-card, nu-btn, nu-input, etc.) – no own HTML wrapper.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../core/module_bootstrap.php';
?>

<div class="nu-module-header" style="margin-bottom:20px">
  <h2 class="nu-module-title">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:6px">
      <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
      <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
    </svg>
    App Cloner
  </h2>
  <p style="color:var(--text-muted);font-size:13px;margin-top:4px">Clone this Nuvis StudioX application to a new database and/or file path.</p>
</div>

<!-- TARGET DATABASE -->
<div class="nu-card" style="margin-bottom:16px">
  <div class="nu-card-header"><h3 class="nu-card-title">Target Database</h3></div>
  <div class="nu-card-body">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div class="nu-field"><label>Host</label><input id="tgtHost" type="text" class="nu-input" value="localhost"></div>
      <div class="nu-field"><label>Port</label><input id="tgtPort" type="number" class="nu-input" value="3306"></div>
      <div class="nu-field"><label>Database Name (new)</label><input id="tgtDB" type="text" class="nu-input" placeholder="my_cloned_app"></div>
      <div class="nu-field"><label>Charset</label>
        <select id="tgtCharset" class="nu-input">
          <option value="utf8mb4" selected>utf8mb4 (recommended)</option>
          <option value="utf8">utf8</option>
          <option value="latin1">latin1</option>
        </select>
      </div>
      <div class="nu-field"><label>Username</label><input id="tgtUser" type="text" class="nu-input"></div>
      <div class="nu-field"><label>Password</label><input id="tgtPass" type="password" class="nu-input"></div>
      <div class="nu-field">
        <label>If DB Exists</label>
        <select id="dbMode" class="nu-input">
          <option value="fail">Abort (fail)</option>
          <option value="create">Use existing</option>
          <option value="clear">Clear &amp; overwrite</option>
        </select>
      </div>
      <div class="nu-field">
        <label>File Mode</label>
        <select id="fileMode" class="nu-input">
          <option value="fail">Abort if target dir exists</option>
          <option value="create">Create / use existing</option>
          <option value="clear">Clear then copy</option>
          <option value="overwrite">Overwrite files</option>
        </select>
      </div>
    </div>
  </div>
</div>

<!-- FILE PATHS -->
<div class="nu-card" style="margin-bottom:16px">
  <div class="nu-card-header"><h3 class="nu-card-title">File Paths</h3></div>
  <div class="nu-card-body">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div class="nu-field"><label>Source Path (this install)</label><input id="srcPath" type="text" class="nu-input" value="<?= htmlspecialchars(dirname(__DIR__, 2)) ?>"></div>
      <div class="nu-field"><label>Target Path</label><input id="tgtPath" type="text" class="nu-input" placeholder="/var/www/myapp_clone"></div>
    </div>
    <label style="display:flex;align-items:center;gap:8px;margin-top:10px;font-size:13px;font-weight:400">
      <input type="checkbox" id="copyFiles" checked> Copy files to target path
    </label>
  </div>
</div>

<!-- CLONE OPTIONS -->
<div class="nu-card" style="margin-bottom:16px">
  <div class="nu-card-header"><h3 class="nu-card-title">What to Clone</h3></div>
  <div class="nu-card-body">
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px">
      <?php
      $optDefs = [
        1 => ['icon'=>'\uD83C\uDFD7\uFE0F', 'label'=>'System Tables/Views',  'desc'=>'nu_* & system tables'],
        2 => ['icon'=>'\uD83D\uDCE6', 'label'=>'User Tables/Views',   'desc'=>'Your app tables'],
        3 => ['icon'=>'\u2699\uFE0F',  'label'=>'System Records',      'desc'=>'Nuvis StudioX core data'],
        4 => ['icon'=>'\uD83D\uDCCB', 'label'=>'App Definitions',     'desc'=>'Forms, reports, menus'],
        5 => ['icon'=>'\uD83D\uDCCA', 'label'=>'User Data',           'desc'=>'Your table rows'],
        6 => ['icon'=>'\u0192',       'label'=>'Functions',           'desc'=>'DB functions'],
        7 => ['icon'=>'\uD83D\uDD27', 'label'=>'Procedures',          'desc'=>'Stored procedures'],
        8 => ['icon'=>'\u26A1',       'label'=>'Triggers',            'desc'=>'DB triggers'],
        9 => ['icon'=>'\uD83D\uDD50', 'label'=>'Events',              'desc'=>'Scheduled events'],
      ];
      foreach ($optDefs as $n => $d): ?>
      <label style="display:flex;align-items:flex-start;gap:8px;background:var(--bg-page,#f5f6fa);border:1px solid var(--border-color,#e0e4ef);border-radius:8px;padding:10px;cursor:pointer;font-size:12px;transition:border .2s" onclick="this.classList.toggle('nu-opt-active')">
        <input type="checkbox" class="opt-cb" value="<?= $n ?>" <?= in_array($n,[1,2,3,4]) ? 'checked' : '' ?>>
        <span><?= $d['icon'] ?></span>
        <span>
          <strong style="font-size:12px"><?= htmlspecialchars($d['label']) ?></strong><br>
          <span style="color:var(--text-muted);font-size:11px"><?= htmlspecialchars($d['desc']) ?></span>
        </span>
      </label>
      <?php endforeach; ?>
    </div>

    <div style="margin-top:14px">
      <label class="nu-label" style="margin-bottom:6px">Insert Method</label>
      <select id="insertType" class="nu-input" style="max-width:240px">
        <option value="INSERT">INSERT</option>
        <option value="INSERT IGNORE">INSERT IGNORE (skip dupes)</option>
        <option value="REPLACE">REPLACE (upsert)</option>
      </select>
    </div>

    <div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:14px">
      <label style="display:flex;align-items:center;gap:8px;font-size:13px"><input type="checkbox" id="dryRun"> Dry Run (simulate only)</label>
      <label style="display:flex;align-items:center;gap:8px;font-size:13px"><input type="checkbox" id="schemaOnly"> Schema Only (no data)</label>
    </div>
  </div>
</div>

<!-- TABLE FILTER -->
<div class="nu-card" style="margin-bottom:16px">
  <div class="nu-card-header">
    <h3 class="nu-card-title">Table Filter <span style="font-weight:400;font-size:12px;color:var(--text-muted)">(optional — leave empty to clone all)</span></h3>
  </div>
  <div class="nu-card-body">
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px">Select specific tables to include. System tables (nu_*) are controlled by the checkboxes above.</p>
    <div id="tablePicker" style="max-height:200px;overflow-y:auto;border:1px solid var(--border-color,#e0e4ef);border-radius:6px;padding:8px">
      <em style="color:var(--text-muted);font-size:12px">Loading tables…</em>
    </div>
  </div>
</div>

<!-- SQL EXPORT -->
<div class="nu-card" style="margin-bottom:16px">
  <div class="nu-card-header"><h3 class="nu-card-title">SQL Export</h3></div>
  <div class="nu-card-body">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div class="nu-field">
        <label>Export Format</label>
        <select id="sqlFormat" class="nu-input">
          <option value="mysql">MySQL</option>
          <option value="mssql">MS SQL Server</option>
        </select>
      </div>
      <div class="nu-field">
        <label>Batch Size (rows per INSERT)</label>
        <input type="number" id="batchSize" class="nu-input" value="500" min="1" max="5000">
      </div>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:10px">
      <label style="display:flex;align-items:center;gap:8px;font-size:13px"><input type="checkbox" id="inclDrops" checked> Include DROP statements</label>
      <label style="display:flex;align-items:center;gap:8px;font-size:13px"><input type="checkbox" id="gzipExport"> Compress as .sql.gz</label>
      <label style="display:flex;align-items:center;gap:8px;font-size:13px"><input type="checkbox" id="schemaExport"> Schema only (no data)</label>
    </div>
  </div>
</div>

<!-- WEBHOOK -->
<div class="nu-card" style="margin-bottom:16px">
  <div class="nu-card-header"><h3 class="nu-card-title">Webhook <span style="font-weight:400;font-size:12px;color:var(--text-muted)">(optional)</span></h3></div>
  <div class="nu-card-body">
    <div class="nu-field">
      <label>POST result JSON to URL</label>
      <input type="text" id="webhookUrl" class="nu-input" placeholder="https://your-app.com/clone-done">
    </div>
  </div>
</div>

<!-- ACTIONS -->
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px">
  <button class="nu-btn nu-btn-primary" onclick="acStartClone()">&#x25B6; Start Clone</button>
  <button class="nu-btn nu-btn-success" onclick="acExportSQL()">&darr; Download SQL</button>
  <button class="nu-btn nu-btn-ghost" onclick="acTestConn()">&#x1F50C; Test Connection</button>
</div>

<!-- PROGRESS -->
<div class="nu-card" id="ac-progress-panel" style="display:none">
  <div class="nu-card-header">
    <h3 class="nu-card-title">Progress <span id="ac-progress-badge"></span></h3>
  </div>
  <div class="nu-card-body">
    <ul id="ac-progress-log" style="list-style:none;font-size:12px;max-height:320px;overflow-y:auto;background:var(--bg-sidebar,#0b1020);color:#c9d1d9;border-radius:6px;padding:12px"></ul>
  </div>
</div>

<script>
(function(){
  // Load table picker — exclude no tables by default; backend filters sys tables
  fetch('api/appcloner.php?action=list_tables')
    .then(function(r){ return r.json(); })
    .then(function(d){
      var el = document.getElementById('tablePicker');
      el.innerHTML = '';
      var tables = (d.tables || []);
      if (!tables.length) {
        el.innerHTML = '<em style="color:var(--text-muted);font-size:12px">No user tables found.</em>';
        return;
      }
      tables.forEach(function(t){
        // Skip internal Nuvis StudioX system tables from the picker;
        // they are handled by the "System Tables" checkbox (option 1)
        if (/^nu_/.test(t.TABLE_NAME)) return;
        var lbl = document.createElement('label');
        lbl.style.cssText = 'display:flex;align-items:center;gap:8px;font-size:12px;font-weight:400;padding:4px 6px;border-radius:4px;cursor:pointer;';
        lbl.innerHTML = '<input type="checkbox" class="tbl-cb" value="'+t.TABLE_NAME+'"> '
          + t.TABLE_NAME
          + ' <span style="color:var(--text-muted);font-size:11px">(~'+(t.TABLE_ROWS||0)+' rows)</span>';
        el.appendChild(lbl);
      });
    })
    .catch(function(){
      document.getElementById('tablePicker').innerHTML = '<em style="color:var(--text-muted)">Could not load tables.</em>';
    });

  function getOpts(){   return [...document.querySelectorAll('.opt-cb:checked')].map(function(e){ return +e.value; }); }
  function getTables(){ return [...document.querySelectorAll('.tbl-cb:checked')].map(function(e){ return e.value; }); }

  function payload(){
    return {
      targetDB:      document.getElementById('tgtDB').value.trim(),
      targetHost:    document.getElementById('tgtHost').value.trim(),
      targetUser:    document.getElementById('tgtUser').value.trim(),
      targetPass:    document.getElementById('tgtPass').value,
      targetCharset: document.getElementById('tgtCharset').value,
      targetPort:    +document.getElementById('tgtPort').value,
      targetPath:    document.getElementById('tgtPath').value.trim(),
      sourcePath:    document.getElementById('srcPath').value.trim(),
      databaseMode:  document.getElementById('dbMode').value,
      fileMode:      document.getElementById('fileMode').value,
      copyFiles:     document.getElementById('copyFiles').checked,
      opts:          getOpts(),
      insertType:    document.getElementById('insertType').value,
      dryRun:        document.getElementById('dryRun').checked,
      schemaOnly:    document.getElementById('schemaOnly').checked,
      includeTables: getTables(),
      webhookUrl:    document.getElementById('webhookUrl').value.trim(),
    };
  }

  window.acTestConn = function(){
    var p = payload();
    fetch('api/appcloner.php?action=list_databases',{
      method:'POST', body: new URLSearchParams({host:p.targetHost, user:p.targetUser, pass:p.targetPass, port:p.targetPort})
    }).then(function(r){ return r.json(); }).then(function(d){
      if(d.databases) alert('\u2705 Connected! Found ' + d.databases.length + ' database(s) on ' + p.targetHost);
      else alert('\u274C Connection failed: ' + (d.error||'unknown'));
    });
  };

  window.acStartClone = function(){
    var p = payload();
    if(!p.targetDB){ alert('Please enter a target database name.'); return; }
    var panel = document.getElementById('ac-progress-panel');
    var log   = document.getElementById('ac-progress-log');
    panel.style.display = 'block';
    log.innerHTML = '<li>Starting clone job\u2026</li>';
    document.getElementById('ac-progress-badge').innerHTML = '<span class="nu-badge nu-badge-warning">Running</span>';
    fetch('api/appcloner.php?action=start',{
      method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(p)
    }).then(function(r){ return r.json(); }).then(function(d){
      if(d.error){ log.innerHTML += '<li style="color:#f44336">\u274C ' + d.error + '</li>'; return; }
      acPollProgress(d.jobId, log);
    });
  };

  function acPollProgress(jobId, log){
    var lastLen = 0;
    var iv = setInterval(function(){
      fetch('api/appcloner.php?action=progress&jobId='+jobId)
        .then(function(r){ return r.json(); })
        .then(function(d){
          var steps = d.steps || [];
          for(var i = lastLen; i < steps.length; i++){
            var s  = steps[i];
            var li = document.createElement('li');
            li.style.cssText = 'padding:3px 0;border-bottom:1px solid rgba(255,255,255,.06);line-height:1.5;';
            li.style.color = s.status === 'done' ? '#4caf50' : s.status === 'error' ? '#f44336' : '#90caf9';
            li.textContent = '[' + s.status.toUpperCase() + '] ' + s.msg;
            log.appendChild(li);
            log.scrollTop = log.scrollHeight;
          }
          lastLen = steps.length;
          if(d.done){
            clearInterval(iv);
            var last  = steps[steps.length-1];
            var badge = document.getElementById('ac-progress-badge');
            badge.innerHTML = (last && last.status === 'done')
              ? '<span class="nu-badge nu-badge-success">Done \u2713</span>'
              : '<span class="nu-badge nu-badge-error">Error \u2717</span>';
          }
        });
    }, 1500);
  }

  window.acExportSQL = function(){
    var p = payload();
    var body = JSON.stringify({
      opts:         getOpts(),
      targetDB:     p.targetDB || 'export',
      format:       document.getElementById('sqlFormat').value,
      insertType:   p.insertType,
      batchSize:    +document.getElementById('batchSize').value,
      includeDrops: document.getElementById('inclDrops').checked,
      zip:          document.getElementById('gzipExport').checked,
      schemaOnly:   document.getElementById('schemaExport').checked,
      includeTables: getTables(),
    });
    fetch('api/appcloner.php?action=export_sql',{
      method:'POST', headers:{'Content-Type':'application/json'}, body: body
    }).then(function(r){
      var cd = r.headers.get('Content-Disposition') || '';
      var fn = (cd.match(/filename="([^"]+)"/) || [])[1] || 'export.sql';
      return r.blob().then(function(blob){ return {blob:blob, fn:fn}; });
    }).then(function(o){
      var a = document.createElement('a');
      a.href = URL.createObjectURL(o.blob);
      a.download = o.fn;
      a.click();
    });
  };
})();
</script>
