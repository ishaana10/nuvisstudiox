/* modules/widgets/widgets.js */
(function () {
  'use strict';

  var API       = 'modules/dashboard/widget_api.php';
  var ROLES_API = 'api/roles.php';
  var chartInstances = {};
  var LS_KEY = 'nuDash_groupCollapsed';
  var WIDGET_DATA = window.NUDASH_WIDGET_DATA || {};

  // ── FA icon list (common solid icons) ────────────────────────────────────
  var FA_ICONS = [
    'fa-address-book','fa-address-card','fa-align-center','fa-align-left','fa-align-right',
    'fa-anchor','fa-angle-down','fa-angle-left','fa-angle-right','fa-angle-up',
    'fa-archive','fa-arrow-down','fa-arrow-left','fa-arrow-right','fa-arrow-up',
    'fa-asterisk','fa-at','fa-award','fa-ban','fa-bar-chart',
    'fa-bars','fa-bell','fa-bolt','fa-book','fa-bookmark',
    'fa-box','fa-briefcase','fa-bug','fa-building','fa-bullhorn',
    'fa-bullseye','fa-calendar','fa-calendar-alt','fa-calendar-check','fa-camera',
    'fa-car','fa-chart-bar','fa-chart-line','fa-chart-pie','fa-check',
    'fa-check-circle','fa-check-square','fa-chess','fa-chevron-down','fa-chevron-left',
    'fa-chevron-right','fa-chevron-up','fa-circle','fa-clipboard','fa-clipboard-check',
    'fa-clipboard-list','fa-clock','fa-cloud','fa-code','fa-cog',
    'fa-cogs','fa-columns','fa-comment','fa-comments','fa-compass',
    'fa-copy','fa-credit-card','fa-crop','fa-crown','fa-cube',
    'fa-cubes','fa-cut','fa-database','fa-desktop','fa-dollar-sign',
    'fa-download','fa-edit','fa-ellipsis-h','fa-ellipsis-v','fa-envelope',
    'fa-envelope-open','fa-eraser','fa-euro-sign','fa-exchange-alt','fa-exclamation',
    'fa-exclamation-circle','fa-exclamation-triangle','fa-expand','fa-eye','fa-eye-slash',
    'fa-file','fa-file-alt','fa-file-code','fa-file-csv','fa-file-excel',
    'fa-file-image','fa-file-pdf','fa-file-upload','fa-filter','fa-flag',
    'fa-flask','fa-folder','fa-folder-open','fa-font','fa-forward',
    'fa-funnel-dollar','fa-gift','fa-globe','fa-graduation-cap','fa-grip-horizontal',
    'fa-grip-vertical','fa-hand-holding','fa-hashtag','fa-heart','fa-history',
    'fa-home','fa-hourglass','fa-id-badge','fa-id-card','fa-image',
    'fa-inbox','fa-info','fa-info-circle','fa-key','fa-laptop',
    'fa-layer-group','fa-leaf','fa-link','fa-list','fa-list-alt',
    'fa-list-ol','fa-list-ul','fa-location-arrow','fa-lock','fa-lock-open',
    'fa-map','fa-map-marker','fa-map-marker-alt','fa-medal','fa-minus',
    'fa-minus-circle','fa-mobile','fa-money-bill','fa-moon','fa-newspaper',
    'fa-paperclip','fa-pause','fa-pen','fa-pencil-alt','fa-percentage',
    'fa-phone','fa-play','fa-plug','fa-plus','fa-plus-circle',
    'fa-poll','fa-print','fa-project-diagram','fa-puzzle-piece','fa-question',
    'fa-question-circle','fa-random','fa-redo','fa-refresh','fa-reply',
    'fa-rocket','fa-rss','fa-save','fa-search','fa-server',
    'fa-share','fa-shield-alt','fa-sign-in-alt','fa-sign-out-alt','fa-signal',
    'fa-sitemap','fa-sliders-h','fa-sort','fa-sort-alpha-down','fa-sort-amount-down',
    'fa-spinner','fa-star','fa-star-half','fa-sticky-note','fa-stop',
    'fa-stopwatch','fa-store','fa-stream','fa-sun','fa-sync',
    'fa-table','fa-tablet','fa-tag','fa-tags','fa-tasks',
    'fa-terminal','fa-th','fa-th-large','fa-th-list','fa-thumbs-down',
    'fa-thumbs-up','fa-ticket-alt','fa-times','fa-times-circle','fa-toggle-off',
    'fa-toggle-on','fa-tools','fa-trash','fa-trash-alt','fa-trophy',
    'fa-truck','fa-undo','fa-upload','fa-user','fa-user-check',
    'fa-user-cog','fa-user-friends','fa-user-lock','fa-user-plus','fa-user-shield',
    'fa-user-times','fa-users','fa-video','fa-wallet','fa-wifi',
    'fa-wrench'
  ];
  var _faFiltered = FA_ICONS.slice();

  // ── Chart.js loader ────────────────────────────────────────────────────────
  // When widgets.php is injected via NuApp._execModuleScripts(), both the
  // Chart.js <script src> and widgets.js are cloned and appended to <head> in
  // sequence, but external scripts load asynchronously. This means Chart may
  // not be defined yet when initCharts() runs. We therefore:
  //   1. If Chart is already defined → run immediately.
  //   2. If a <script src="chart.js"> tag is already in the DOM (injected but
  //      still loading) → wait for its load event.
  //   3. Otherwise → inject Chart.js ourselves and wait for it to load.
  var CHARTJS_CDN = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js';

  function _runInitCharts() {
    document.querySelectorAll('[data-chartjs]').forEach(function (canvas) {
      var id = canvas.id;
      if (chartInstances[id]) { try { chartInstances[id].destroy(); } catch (e) {} }
      try { chartInstances[id] = new Chart(canvas, JSON.parse(canvas.dataset.chartjs)); }
      catch (e) { console.warn('[nuDash chart]', e); }
    });
  }

  function initCharts() {
    // Already loaded — run immediately.
    if (typeof Chart !== 'undefined') { _runInitCharts(); return; }

    // Find an existing Chart.js script tag that is still loading.
    var existing = document.querySelector('script[src*="chart.js"], script[src*="chart.umd"]');
    if (existing) {
      existing.addEventListener('load',  _runInitCharts);
      existing.addEventListener('error', function () { console.warn('[nuDash] Chart.js failed to load'); });
      return;
    }

    // Inject Chart.js ourselves and wait.
    var s = document.createElement('script');
    s.src = CHARTJS_CDN;
    s.addEventListener('load',  _runInitCharts);
    s.addEventListener('error', function () { console.warn('[nuDash] Chart.js failed to load'); });
    document.head.appendChild(s);
  }

  function getCollapsedSet() {
    try { return JSON.parse(localStorage.getItem(LS_KEY) || '[]'); } catch (e) { return []; }
  }
  function saveCollapsedSet(arr) {
    try { localStorage.setItem(LS_KEY, JSON.stringify(arr)); } catch (e) {}
  }
  function setGroupCollapsed(roleCode, collapsed) {
    var arr = getCollapsedSet();
    var idx = arr.indexOf(roleCode);
    if (collapsed && idx === -1) arr.push(roleCode);
    if (!collapsed && idx !== -1) arr.splice(idx, 1);
    saveCollapsedSet(arr);
  }

  function restoreGroupStates() {
    getCollapsedSet().forEach(function (roleCode) {
      var bodyId  = 'nuRoleGroup_' + roleCode.replace(/[^a-z0-9_]/gi, '_');
      var body    = document.getElementById(bodyId);
      var chevron = document.getElementById(bodyId + '_chevron');
      if (body) { body.style.maxHeight = '0px'; body.classList.add('nu-group-collapsed'); }
      if (chevron) chevron.classList.add('nu-group-collapsed');
    });
    document.querySelectorAll('.nu-role-group-body:not(.nu-group-collapsed)').forEach(function (body) {
      body.style.maxHeight = body.scrollHeight + 'px';
    });
  }

  function loadRolesIntoDropdown(selectedValue) {
    var sel = document.getElementById('nuWTargetRole');
    if (!sel) return;
    fetch(ROLES_API + '?action=list')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.success || !Array.isArray(d.roles)) return;
        while (sel.options.length > 1) sel.remove(1);
        d.roles.forEach(function (r) {
          var opt = document.createElement('option');
          opt.value = r.role_code;
          opt.textContent = r.role_name + ' (' + r.role_code + ')';
          sel.appendChild(opt);
        });
        if (selectedValue) sel.value = selectedValue;
      })
      .catch(function (e) { console.warn('[nuDash] roles fetch error', e); });
  }

  // Build FA icon class string for display
  function faClass(icon) {
    if (!icon) return '';
    if (/^(fas|far|fab|fa)\s/.test(icon)) return icon;
    return 'fas ' + icon;
  }

  // Render icon HTML (FA or emoji/text)
  function iconHtml(icon) {
    if (!icon) return '';
    if (/^(fas?|far|fab|fa)\s+fa-|^fa-/.test(icon)) {
      return '<i class="' + faClass(icon) + '" aria-hidden="true"></i>';
    }
    return '<span style="font-size:1rem;line-height:1;">' + icon + '</span>';
  }

  // ── Derive the NuBuilder base URL (origin + directory path + index.php)
  // e.g. https://example.com/nubuilder/ → https://example.com/nubuilder/index.php
  function _nuBaseUrl() {
    // If NuApp exposes its own base URL, trust that first
    if (window.NuApp && NuApp.baseUrl) return NuApp.baseUrl.replace(/\/?$/, '/');
    // Otherwise derive from current location: strip query string, then ensure we
    // end up at the directory root (not inside a sub-path like /modules/...).
    // window.location.pathname for NuBuilder is always the root index.php path.
    var path = window.location.pathname; // e.g. /nubuilder5/ or /nubuilder5/index.php
    // Strip any filename (anything after the last /)
    var dir = path.replace(/\/[^\/]*$/, '/'); // e.g. /nubuilder5/
    return window.location.origin + dir;
  }

  // ── Stat config HTML — includes SQL, subtitle, colour AND drill-down link
  function _statConfigHtml() {
    return [
      '<div class="nu-field" style="margin-bottom:12px;">',
      '<label class="nu-label">SQL <small style="color:#888">must return a <code>value</code> column</small></label>',
      '<textarea class="nu-input" id="nuWSql" rows="3" placeholder="SELECT COUNT(*) as value FROM my_table"></textarea></div>',

      '<div class="nu-field" style="margin-bottom:12px;"><label class="nu-label">Subtitle (optional)</label>',
      '<input class="nu-input" id="nuWSubtitle" placeholder="Pending tasks"></div>',

      '<div class="nu-field" style="margin-bottom:12px;"><label class="nu-label">Accent colour</label>',
      '<select class="nu-input" id="nuWColor">',
      '<option value="primary">Teal</option><option value="success">Green</option>',
      '<option value="warning">Orange</option><option value="error">Red</option>',
      '</select></div>',

      // ── Drill-down link row ──────────────────────────────────────────────
      '<div style="padding:10px 12px;background:var(--color-surface-offset,#f8f9fa);',
      'border-radius:.5rem;border-left:3px solid #006494;margin-bottom:0;">',
      '<label class="nu-label" style="color:#006494;margin-bottom:8px;">',
      '&#10148;&nbsp;Drill-down arrow link <small style="color:#888;font-weight:400;">(optional)</small>',
      '</label>',
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">',
      '<div class="nu-field" style="margin:0;">',
      '<label class="nu-label" style="font-size:.75rem;">Form Code</label>',
      '<input class="nu-input" id="nuWLinkModule" placeholder="e.g. zxy1234567890"',
      '       title="The NuBuilder form code">',
      '</div>',
      '<div class="nu-field" style="margin:0;">',
      '<label class="nu-label" style="font-size:.75rem;">— or — External URL</label>',
      '<input class="nu-input" id="nuWLinkUrl" placeholder="https://…"',
      '       title="External URL (used only if Form Code is blank)">',
      '</div>',
      '</div>',
      // ── Open mode toggle ────────────────────────────────────────────────
      '<div style="display:flex;align-items:center;gap:10px;margin-top:4px;">',
      '<label style="font-size:.8rem;color:#555;font-weight:600;white-space:nowrap;">Open as:</label>',
      '<label style="display:flex;align-items:center;gap:5px;font-size:.8rem;cursor:pointer;">',
      '<input type="radio" name="nuWLinkMode" id="nuWLinkModeInline" value="inline" checked style="accent-color:#006494;"> Inline browse',
      '</label>',
      '<label style="display:flex;align-items:center;gap:5px;font-size:.8rem;cursor:pointer;">',
      '<input type="radio" name="nuWLinkMode" id="nuWLinkModePopup" value="popup" style="accent-color:#006494;"> Popup',
      '</label>',
      '</div>',
      '<small style="color:#888;font-size:11px;display:block;margin-top:6px;">',
      '<strong>Inline</strong> replaces the main view. <strong>Popup</strong> opens the form in a floating modal window.',
      '</small>',
      '</div>'
    ].join('');
  }

  var TYPE_CONFIGS = {
    stat: '', // populated dynamically below
    metric_trend: '<div class="nu-field" style="margin-bottom:12px;"><label class="nu-label">SQL (must return <code>value</code> and optional <code>prev_value</code>)</label><textarea class="nu-input" id="nuWSql" rows="3" placeholder="SELECT 120 AS value, 100 AS prev_value"></textarea></div><div class="nu-field"><label class="nu-label">Subtitle</label><input class="nu-input" id="nuWSubtitle" placeholder="vs last month"></div>',
    sparkline: '<div class="nu-field"><label class="nu-label">SQL (columns: <code>label</code>, <code>value</code>)</label><textarea class="nu-input" id="nuWSql" rows="4" placeholder="SELECT created_at AS label, total AS value FROM sales ORDER BY created_at LIMIT 10"></textarea></div>',
    chart_bar:  '<div class="nu-field"><label class="nu-label">SQL (columns: <code>label</code>, <code>value</code>)</label><textarea class="nu-input" id="nuWSql" rows="4" placeholder="SELECT status AS label, COUNT(*) AS value FROM my_table GROUP BY status"></textarea></div>',
    chart_line: '<div class="nu-field"><label class="nu-label">SQL (columns: <code>label</code>, <code>value</code>)</label><textarea class="nu-input" id="nuWSql" rows="4" placeholder="SELECT DATE(created_at) AS label, COUNT(*) AS value FROM my_table GROUP BY DATE(created_at) ORDER BY label"></textarea></div>',
    chart_area: '<div class="nu-field"><label class="nu-label">SQL (columns: <code>label</code>, <code>value</code>)</label><textarea class="nu-input" id="nuWSql" rows="4" placeholder="SELECT DATE(created_at) AS label, COUNT(*) AS value FROM my_table GROUP BY DATE(created_at) ORDER BY label"></textarea></div>',
    chart_pie:  '<div class="nu-field"><label class="nu-label">SQL (columns: <code>label</code>, <code>value</code>)</label><textarea class="nu-input" id="nuWSql" rows="4" placeholder="SELECT category AS label, COUNT(*) AS value FROM my_table GROUP BY category"></textarea></div>',
    chart_doughnut: '<div class="nu-field"><label class="nu-label">SQL (columns: <code>label</code>, <code>value</code>)</label><textarea class="nu-input" id="nuWSql" rows="4" placeholder="SELECT status AS label, COUNT(*) AS value FROM my_table GROUP BY status"></textarea></div>',
    chart_radar: '<div class="nu-field"><label class="nu-label">SQL (columns: <code>label</code>, <code>value</code>)</label><textarea class="nu-input" id="nuWSql" rows="4" placeholder="SELECT skill AS label, score AS value FROM team_skills"></textarea></div>',
    table:      '<div class="nu-field"><label class="nu-label">SQL <small style="color:#888">use <code>{{user_id}}</code>, <code>{{role}}</code>, <code>{{today}}</code></small></label><textarea class="nu-input" id="nuWSql" rows="4" placeholder="SELECT title AS Task, status AS Status FROM my_tasks LIMIT 10"></textarea></div>',
    list:       '<div class="nu-field"><label class="nu-label">Links (one per line: <code>Label|form_code</code> or <code>Label|https://url</code>)</label><textarea class="nu-input" id="nuWLinks" rows="5" placeholder="Open Forms|zxy1234567890\nMy Reports|abc0987654321"></textarea></div>',
    quick_actions: '<div class="nu-field"><label class="nu-label">Actions (one per line: <code>Label|form_code|fa-icon</code> or <code>Label|https://url|emoji</code>)</label><textarea class="nu-input" id="nuWLinks" rows="5" placeholder="New Request|cust_req_form|fa-plus\nView Users|users_form|fa-users"></textarea></div>',
    activity:   '<div class="nu-field"><label class="nu-label">SQL (columns: <code>time</code>, <code>user</code>, <code>text</code>)</label><textarea class="nu-input" id="nuWSql" rows="4" placeholder="SELECT created_at AS time, user_id AS user, action AS text FROM nu_audit_logs ORDER BY created_at DESC LIMIT 5"></textarea></div>',
    progress:   '<div class="nu-field" style="margin-bottom:12px;"><label class="nu-label">SQL (columns: <code>done</code>, <code>total</code>)</label><textarea class="nu-input" id="nuWSql" rows="3"></textarea></div><div class="nu-field"><label class="nu-label">Label</label><input class="nu-input" id="nuWSubtitle" placeholder="Tasks completed"></div>',
    iframe:     '<div class="nu-field" style="margin-bottom:12px;"><label class="nu-label">iFrame Target URL</label><input class="nu-input" id="nuWLinkUrl" placeholder="https://example.com/embed"></div><div class="nu-field"><label class="nu-label">Height (px)</label><input class="nu-input" id="nuWSubtitle" type="number" placeholder="280"></div>',
    custom:     '<div class="nu-field"><label class="nu-label">HTML Content</label><textarea class="nu-input" id="nuWHtml" rows="6" placeholder="<p>Any HTML here...</p>"></textarea></div>'
  };
  // Set stat config from function (avoids duplicating the long string)
  TYPE_CONFIGS.stat = _statConfigHtml();

  var TYPE_ACCENTS = {
    stat:'#01696f', metric_trend:'#0ea5e9', sparkline:'#0284c7',
    chart_bar:'#006494', chart_line:'#7a39bb', chart_area:'#8b5cf6',
    chart_pie:'#da7101', chart_doughnut:'#f59e0b', chart_radar:'#ec4899',
    table:'#437a22', list:'#006494', quick_actions:'#10b981',
    activity:'#6366f1', progress:'#964219', iframe:'#64748b', custom:'#a12c7b'
  };

  window.nuDash = {
    editMode:  false,
    editingId: null,

    toggleGroup: function (bodyId, roleCode) {
      var body    = document.getElementById(bodyId);
      var chevron = document.getElementById(bodyId + '_chevron');
      if (!body) return;
      var isCollapsed = body.classList.contains('nu-group-collapsed');
      if (isCollapsed) {
        body.style.maxHeight = body.scrollHeight + 'px';
        body.classList.remove('nu-group-collapsed');
        if (chevron) chevron.classList.remove('nu-group-collapsed');
        setGroupCollapsed(roleCode, false);
        body.addEventListener('transitionend', function handler() {
          if (!body.classList.contains('nu-group-collapsed')) body.style.maxHeight = 'none';
          body.removeEventListener('transitionend', handler);
        });
      } else {
        body.style.maxHeight = body.scrollHeight + 'px';
        body.offsetHeight;
        body.classList.add('nu-group-collapsed');
        if (chevron) chevron.classList.add('nu-group-collapsed');
        setGroupCollapsed(roleCode, true);
      }
    },

    // ── FA Picker ───────────────────────────────────────────────────────────
    openFaPicker: function () {
      var modal = document.getElementById('nuFaPickerModal');
      if (!modal) return;
      _faFiltered = FA_ICONS.slice();
      var searchEl = document.getElementById('nuFaPickerSearch');
      if (searchEl) searchEl.value = '';
      this._renderFaGrid(_faFiltered);
      modal.style.display = 'flex';
      if (searchEl) setTimeout(function () { searchEl.focus(); }, 80);
    },

    closeFaPicker: function () {
      var modal = document.getElementById('nuFaPickerModal');
      if (modal) modal.style.display = 'none';
    },

    filterFaPicker: function (q) {
      q = (q || '').toLowerCase().trim();
      _faFiltered = q ? FA_ICONS.filter(function (ic) { return ic.indexOf(q) !== -1; }) : FA_ICONS.slice();
      this._renderFaGrid(_faFiltered);
    },

    _renderFaGrid: function (icons) {
      var grid = document.getElementById('nuFaPickerGrid');
      if (!grid) return;
      var currentIcon = (document.getElementById('nuWIcon') || {}).value || '';
      var html = '';
      icons.forEach(function (ic) {
        var cls = 'fas ' + ic;
        var selected = (currentIcon === ic || currentIcon === cls) ? ' nu-selected' : '';
        var label = ic.replace('fa-', '');
        html += '<button class="nu-fa-picker-btn' + selected + '" type="button" onclick="nuDash.selectFaIcon(\'' + ic + '\')" title="' + ic + '">'
              + '<i class="' + cls + '"></i>' + label + '</button>';
      });
      grid.innerHTML = html || '<p style="padding:16px;color:#888;font-size:.8rem;">No icons found.</p>';
    },

    selectFaIcon: function (ic) {
      var iconEl = document.getElementById('nuWIcon');
      if (iconEl) { iconEl.value = ic; this.updateIconPreview(); }
      this.closeFaPicker();
    },

    clearIcon: function () {
      var iconEl = document.getElementById('nuWIcon');
      if (iconEl) iconEl.value = '';
      var wrap = document.getElementById('nuWIconPreview');
      if (wrap) wrap.style.display = 'none';
    },

    // ── Icon Preview ────────────────────────────────────────────────────────
    updateIconPreview: function () {
      var wrap   = document.getElementById('nuWIconPreview');
      var badge  = document.getElementById('nuWIconPreviewBadge');
      var icon   = ((document.getElementById('nuWIcon') || {}).value || '').trim();
      var title  = ((document.getElementById('nuWTitle') || {}).value || 'Widget Title').trim();
      var type   = ((document.getElementById('nuWType') || {}).value || 'stat');
      var accent = TYPE_ACCENTS[type] || '#01696f';
      if (!wrap || !badge) return;
      if (icon) {
        wrap.style.display = 'block';
        badge.style.background = accent;
        badge.innerHTML = iconHtml(icon) + '<span>' + title + '</span>';
      } else {
        wrap.style.display = 'none';
      }
    },

    openBuilder: function (id) {
      this.editingId = id || null;
      var sid = id ? String(id) : null;
      document.getElementById('nuWid').value = id || '';
      document.getElementById('nuWPreviewWrap').style.display = 'none';
      var iconPreview = document.getElementById('nuWIconPreview');
      if (iconPreview) iconPreview.style.display = 'none';

      if (sid && WIDGET_DATA[sid]) {
        var w = WIDGET_DATA[sid];
        var cfg = {};
        try { cfg = JSON.parse(w.widget_config || '{}'); } catch (e) {}
        document.getElementById('nuWType').value   = w.widget_type   || 'stat';
        document.getElementById('nuWTitle').value  = w.widget_title  || '';
        document.getElementById('nuWWidth').value  = String(w.widget_width  || 2);
        document.getElementById('nuWHeight').value = String(w.widget_height || 1);
        var iconEl = document.getElementById('nuWIcon');
        if (iconEl) { iconEl.value = w.widget_icon || ''; this.updateIconPreview(); }
        this.onTypeChange();
        var sqlEl = document.getElementById('nuWSql');
        var subEl = document.getElementById('nuWSubtitle');
        var colEl = document.getElementById('nuWColor');
        var lnkEl = document.getElementById('nuWLinks');
        var htmEl = document.getElementById('nuWHtml');
        if (sqlEl) sqlEl.value = cfg.sql      || '';
        if (subEl) subEl.value = cfg.subtitle || cfg.label || '';
        if (colEl) colEl.value = cfg.color    || 'primary';
        if (htmEl) htmEl.value = cfg.html     || '';
        if (lnkEl && cfg.items) {
          lnkEl.value = cfg.items.map(function (i) {
            return i.label + '|' + (i.module || i.url || '');
          }).join('\n');
        }
        // Restore drill-down link fields (stat only)
        var lmEl = document.getElementById('nuWLinkModule');
        var luEl = document.getElementById('nuWLinkUrl');
        if (lmEl) lmEl.value = cfg.link_module || '';
        if (luEl) luEl.value = cfg.link_url    || '';
        // Restore open mode (inline vs popup)
        var modePopup  = document.getElementById('nuWLinkModePopup');
        var modeInline = document.getElementById('nuWLinkModeInline');
        if (modePopup && modeInline) {
          if (cfg.link_mode === 'popup') {
            modePopup.checked = true;
          } else {
            modeInline.checked = true;
          }
        }

        loadRolesIntoDropdown(w.widget_role || '');
      } else {
        document.getElementById('nuWType').value   = 'stat';
        document.getElementById('nuWTitle').value  = '';
        document.getElementById('nuWWidth').value  = '2';
        document.getElementById('nuWHeight').value = '1';
        var iconEl2 = document.getElementById('nuWIcon');
        if (iconEl2) iconEl2.value = '';
        this.onTypeChange();
        loadRolesIntoDropdown('');
      }
      document.getElementById('nuBuilderModal').style.display = 'block';
    },

    openBuilderForRole: function (roleCode) {
      this.openBuilder();
      loadRolesIntoDropdown(roleCode);
    },

    closeBuilder: function () {
      document.getElementById('nuBuilderModal').style.display = 'none';
      document.getElementById('nuWPreviewWrap').style.display = 'none';
    },

    onTypeChange: function () {
      var area = document.getElementById('nuWConfigArea');
      if (area) area.innerHTML = TYPE_CONFIGS[document.getElementById('nuWType').value] || '';
      this.updateIconPreview();
    },

    buildConfig: function () {
      var type  = document.getElementById('nuWType').value;
      var sqlEl = document.getElementById('nuWSql');
      var sql   = sqlEl ? sqlEl.value.trim() : '';
      switch (type) {
        case 'stat': {
          var lm   = ((document.getElementById('nuWLinkModule') || {}).value || '').trim();
          var lu   = ((document.getElementById('nuWLinkUrl')    || {}).value || '').trim();
          // Read open-mode radio (inline or popup)
          var modeEl = document.querySelector('input[name="nuWLinkMode"]:checked');
          var mode   = modeEl ? modeEl.value : 'inline';
          var cfg = {
            sql:      sql,
            subtitle: (document.getElementById('nuWSubtitle') || {}).value || '',
            color:    (document.getElementById('nuWColor')    || {}).value || 'primary'
          };
          if (lm) cfg.link_module = lm;
          if (lu && !lm) cfg.link_url = lu; // url only when no module set
          if (lm || lu) cfg.link_mode = mode; // only save when there is a link
          return cfg;
        }
        case 'metric_trend':
          return { sql: sql, subtitle: (document.getElementById('nuWSubtitle')||{}).value||'' };
        case 'chart_bar': case 'chart_line': case 'chart_area': case 'chart_pie': case 'chart_doughnut': case 'chart_radar': case 'sparkline': case 'table': case 'activity':
          return { sql: sql };
        case 'progress':
          return { sql: sql, label: (document.getElementById('nuWSubtitle')||{}).value||'' };
        case 'list': {
          var lines = ((document.getElementById('nuWLinks')||{}).value||'').split('\n').filter(Boolean);
          return { items: lines.map(function (l) {
            var p = l.split('|'); var t = (p[1]||'').trim();
            return t.indexOf('http') === 0 ? { label:(p[0]||'').trim(), url:t } : { label:(p[0]||'').trim(), module:t };
          })};
        }
        case 'quick_actions': {
          var actionLines = ((document.getElementById('nuWLinks')||{}).value||'').split('\n').filter(Boolean);
          return { items: actionLines.map(function (l) {
            var p = l.split('|');
            var lbl = (p[0]||'').trim();
            var target = (p[1]||'').trim();
            var ic = (p[2]||'').trim() || 'fa-bolt';
            var isUrl = target.indexOf('http') === 0;
            return isUrl ? { label: lbl, url: target, icon: ic } : { label: lbl, module: target, icon: ic };
          })};
        }
        case 'iframe':
          return {
            url: ((document.getElementById('nuWLinkUrl')||{}).value||'').trim(),
            height: parseInt((document.getElementById('nuWSubtitle')||{}).value, 10) || 280
          };
        case 'custom':
          return { html: (document.getElementById('nuWHtml')||{}).value||'' };
        default: return {};
      }
    },

    validateConfig: function (type, cfg) {
      var sqlTypes = ['stat','metric_trend','sparkline','chart_bar','chart_line','chart_area','chart_pie','chart_doughnut','chart_radar','table','progress'];
      if (sqlTypes.indexOf(type) !== -1 && !cfg.sql)              return 'Please enter a SQL query.';
      if ((type === 'list' || type === 'quick_actions') && (!cfg.items || !cfg.items.length)) return 'Please add at least one item.';
      if (type === 'iframe' && !cfg.url)                          return 'Please enter an iFrame target URL.';
      if (type === 'custom' && !cfg.html)                         return 'Please enter HTML content.';
      return null;
    },

    runPreview: function () {
      var cfg  = this.buildConfig();
      var wrap = document.getElementById('nuWPreviewWrap');
      var prev = document.getElementById('nuWPreview');
      wrap.style.display = 'block';
      prev.innerHTML = '<div style="padding:8px;"><div class="nu-skeleton nu-skeleton-text" style="width:60%;"></div><div class="nu-skeleton nu-skeleton-card" style="height:60px;"></div></div>';
      if (!cfg.sql) { prev.innerHTML = '<em>No SQL to preview.</em>'; return; }
      fetch(API + '?action=run_sql', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({sql:cfg.sql}) })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          prev.innerHTML = d.error
            ? '<span style="color:#a12c7b">' + d.error + '</span>'
            : '<pre style="font-size:12px;white-space:pre-wrap;">' + JSON.stringify((d.rows||[]).slice(0,3),null,2) + '</pre>';
        })
        .catch(function () { prev.innerHTML = '<span style="color:#a12c7b">Request failed</span>'; });
    },

    saveWidget: function () {
      var self       = this;
      var id         = document.getElementById('nuWid').value;
      var type       = document.getElementById('nuWType').value;
      var title      = (document.getElementById('nuWTitle').value||'').trim();
      var icon       = ((document.getElementById('nuWIcon')||{}).value || '').trim();
      var width      = parseInt(document.getElementById('nuWWidth').value,  10) || 2;
      var height     = parseInt(document.getElementById('nuWHeight').value, 10) || 1;
      var cfg        = this.buildConfig();
      var rEl        = document.getElementById('nuWTargetRole');
      var targetRole = rEl ? rEl.value : null;
      if (!title) { alert('Please enter a title.'); document.getElementById('nuWTitle').focus(); return; }
      var err = this.validateConfig(type, cfg);
      if (err) { alert(err); return; }
      var payload = { type:type, title:title, icon:icon, width:width, height:height, config:cfg };
      if (targetRole) payload.target_role = targetRole;
      if (id) payload.id = id;
      fetch(API + '?action=' + (id ? 'update' : 'add'), {
        method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)
      })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d.ok) { self.closeBuilder(); location.reload(); } else alert('Error: ' + (d.error||'Unknown')); })
        .catch(function (e) { alert('Request failed: ' + e.message); });
    },

    removeWidget: function (id) {
      if (!confirm('Remove this widget?')) return;
      fetch(API + '?action=remove', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({id:id}) })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d.ok) location.reload(); else alert('Error: '+(d.error||'')); })
        .catch(function () { alert('Request failed'); });
    },

    editWidget: function (id) { this.openBuilder(id); },

    toggleEditMode: function () {
      this.editMode = !this.editMode;
      var self = this;
      var btn  = document.getElementById('nuDashEditBtn');
      document.querySelectorAll('.nu-widget-card').forEach(function (el) {
        el.style.outline = self.editMode ? '2px dashed #01696f' : '';
        el.draggable     = self.editMode;
      });
      if (btn) btn.textContent = this.editMode ? '\u2705 Done Editing' : '\u270F\uFE0F Edit Layout';
      if (this.editMode) this.initDrag();
    },

    initDrag: function () {
      var self = this;
      var grid = document.getElementById('nuWidgetGrid');
      var dragSrc = null;
      grid.querySelectorAll('.nu-widget-card').forEach(function (card) {
        card.addEventListener('dragstart', function () { dragSrc = card; card.style.opacity = '.4'; });
        card.addEventListener('dragend',   function () { card.style.opacity = ''; });
        card.addEventListener('dragover',  function (e) { e.preventDefault(); });
        card.addEventListener('drop', function (e) {
          e.preventDefault();
          if (dragSrc && dragSrc !== card) {
            var cards = Array.prototype.slice.call(grid.querySelectorAll('.nu-widget-card'));
            if (cards.indexOf(dragSrc) < cards.indexOf(card)) card.after(dragSrc);
            else card.before(dragSrc);
            self.persistOrder();
          }
        });
      });
    },

    persistOrder: function () {
      var order = Array.prototype.slice.call(document.querySelectorAll('.nu-widget-card')).map(function (c, i) {
        return { id: parseInt(c.dataset.widgetId, 10), position: (i+1)*10 };
      });
      fetch(API + '?action=reorder', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({order:order}) });
    },

    resetLayout: function () {
      if (!confirm('Reset to role default? Personal widgets will be removed.')) return;
      fetch(API + '?action=reset', { method:'POST' })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d.ok) location.reload(); })
        .catch(function () { alert('Request failed'); });
    },

    openRoleDesigner: function () {
      this.openBuilder();
      var rEl = document.getElementById('nuWTargetRole');
      if (rEl) setTimeout(function () { rEl.focus(); }, 150);
    },

    // ── Popup helper ────────────────────────────────────────────────────────
    // Opens a form in a floating modal overlay rather than replacing the main view.
    // Tries NuApp.openPopup / NuApp.showPopup first (native NuBuilder popup if it exists),
    // then falls back to a minimal self-contained overlay built on the fly.
    _openPopup: function (formCode) {
      // 1. Try native NuBuilder popup APIs
      if (window.NuApp) {
        if (typeof NuApp.openPopup  === 'function') { NuApp.openPopup(formCode);  return; }
        if (typeof NuApp.showPopup  === 'function') { NuApp.showPopup(formCode);  return; }
        if (typeof NuApp.openDialog === 'function') { NuApp.openDialog(formCode); return; }
      }
      // 2. Fallback: build a lightweight iframe overlay
      var existingOverlay = document.getElementById('nuDashPopupOverlay');
      if (existingOverlay) existingOverlay.remove();

      var overlay = document.createElement('div');
      overlay.id = 'nuDashPopupOverlay';
      overlay.style.cssText = [
        'position:fixed;inset:0;z-index:2000;',
        'background:rgba(0,0,0,.55);',
        'display:flex;align-items:center;justify-content:center;',
        'padding:20px;'
      ].join('');

      // Close on backdrop click
      overlay.addEventListener('click', function (e) {
        if (e.target === overlay) overlay.remove();
      });

      // Close on Escape key
      var escHandler = function (e) {
        if (e.key === 'Escape') { overlay.remove(); document.removeEventListener('keydown', escHandler); }
      };
      document.addEventListener('keydown', escHandler);

      var box = document.createElement('div');
      box.style.cssText = [
        'background:#fff;border-radius:.75rem;',
        'width:min(960px,100%);height:min(780px,90vh);',
        'display:flex;flex-direction:column;',
        'box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden;'
      ].join('');

      // Header bar
      var header = document.createElement('div');
      header.style.cssText = [
        'display:flex;align-items:center;justify-content:space-between;',
        'padding:12px 16px;',
        'border-bottom:1px solid #e5e7eb;',
        'background:var(--color-surface-offset,#f8f9fa);',
        'flex-shrink:0;'
      ].join('');
      header.innerHTML = '<span style="font-size:.875rem;font-weight:700;color:#111;">Form: ' + formCode + '</span>'
                       + '<button onclick="document.getElementById(\'nuDashPopupOverlay\').remove()" '
                       + 'style="background:none;border:none;cursor:pointer;font-size:1.25rem;color:#888;line-height:1;padding:2px 6px;" '
                       + 'title="Close">&times;</button>';

      // ── Iframe src: use the NuBuilder root URL + index.php?form=<code>
      // _nuBaseUrl() returns e.g. https://example.com/nubuilder5/
      // We then load index.php?form=<formCode> relative to that root.
      var iframe = document.createElement('iframe');
      var base = _nuBaseUrl(); // e.g. https://example.com/nubuilder5/
      var src  = base + 'index.php?form=' + encodeURIComponent(formCode);
      iframe.src = src;
      iframe.style.cssText = 'flex:1;border:none;width:100%;';
      iframe.setAttribute('allowfullscreen', '');

      box.appendChild(header);
      box.appendChild(iframe);
      overlay.appendChild(box);
      document.body.appendChild(overlay);
    },

    // ── Arrow drill-down handler ─────────────────────────────────────────────
    // formCode = NuBuilder form code, mode = 'inline' (default) | 'popup'
    drillDown: function (formCode, mode) {
      if (!formCode) return;
      try {
        // Popup mode
        if (mode === 'popup') {
          this._openPopup(formCode);
          return;
        }
        // Inline browse mode (default)
        if (window.NuApp) {
          if (typeof NuApp._browseInline === 'function') { NuApp._browseInline(formCode); return; }
          if (typeof NuApp.openForm      === 'function') { NuApp.openForm(formCode);      return; }
          if (typeof NuApp.loadModule    === 'function') { NuApp.loadModule(formCode);    return; }
        }
        window.location.href = '?form=' + encodeURIComponent(formCode);
      } catch (e) {
        console.error('[nuDash drillDown]', e);
      }
    }
  };

  function initAutoRefresh() {
    document.querySelectorAll('.nu-widget-card[data-refresh-interval]').forEach(function (card) {
      var interval = parseInt(card.dataset.refreshInterval, 10);
      if (interval > 0) {
        setInterval(function () {
          var wid = card.dataset.widgetId;
          if (wid) {
            fetch(API + '?action=get&id=' + encodeURIComponent(wid))
              .then(function (r) { return r.json(); })
              .then(function (d) {
                if (d && d.widget) {
                  var bodyEl = card.querySelector('.nu-widget-body');
                  if (bodyEl && d.html) {
                    bodyEl.innerHTML = d.html;
                  }
                  // Re-render chart if canvas exists
                  initCharts();
                }
              })
              .catch(function () {});
          }
        }, interval * 1000);
      }
    });
  }

  function onReady() { restoreGroupStates(); initCharts(); initAutoRefresh(); }
  document.addEventListener('DOMContentLoaded', onReady);
  if (document.readyState !== 'loading') onReady();

}());
