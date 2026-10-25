/**
 * Nuvis StudioX — Browse Table  (nu-browse-table.js)
 *
 * Renders the record grid for a form's Browse view. Replaces the former
 * inline-styled NuApp._buildBrowseTable(); NuApp now delegates here.
 *
 * Server-driven:  search, sort, pagination (via NuApp.browseForm)
 * Client-side:    per-column page filters, column visibility/order/width,
 *                 density, row selection + bulk delete, export, keyboard nav
 *
 * Styles live in assets/css/nu-browse.css (classes are namespaced `.nu-bt*`).
 * Public API:  NuBrowseTable.build(app, ctx) · .skeleton(container)
 *              .errorState(container, message, retry) · .confirm(message, opts)
 *              .lightbox(src)
 */
(function (window) {
  'use strict';

  var document = window.document;

  // ── Icons (stroke icons, 24×24 viewBox, inherit currentColor) ──────────────
  var ICON_PATHS = {
    search:   '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
    x:        '<path d="M18 6 6 18M6 6l12 12"/>',
    filter:   '<path d="M22 3H2l8 9.46V19l4 2v-8.54z"/>',
    columns:  '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18M15 3v18"/>',
    density:  '<path d="M3 6h18M3 12h18M3 18h18"/>',
    download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
    plus:     '<path d="M12 5v14M5 12h14"/>',
    edit:     '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
    eye:      '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/>',
    trash:    '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>',
    award:    '<circle cx="12" cy="8" r="6"/><path d="M15.5 13.5 17 22l-5-3-5 3 1.5-8.5"/>',
    arrowUp:  '<path d="M12 19V5M5 12l7-7 7 7"/>',
    check:    '<path d="m20 6-11 11-5-5"/>',
    code:     '<path d="m16 18 6-6-6-6M8 6l-6 6 6 6"/>',
    up:       '<path d="m18 15-6-6-6 6"/>',
    down:     '<path d="m6 9 6 6 6-6"/>',
    inbox:    '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11Z"/>',
    reset:    '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>',
    alert:    '<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>'
  };
  function icon(name) {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
      (ICON_PATHS[name] || '') + '</svg>';
  }

  // ── Small helpers ──────────────────────────────────────────────────────────
  /** Create an element. attrs: class, text, html (trusted icons only), on<event>, dataset, anything else → attribute. */
  function el(tag, attrs) {
    var n = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (k) {
        var v = attrs[k];
        if (v === undefined || v === null || v === false) return;
        if (k === 'class') n.className = v;
        else if (k === 'text') n.textContent = v;
        else if (k === 'html') n.innerHTML = v;
        else if (k === 'dataset') Object.keys(v).forEach(function (d) { n.dataset[d] = v[d]; });
        else if (k.slice(0, 2) === 'on' && typeof v === 'function') n.addEventListener(k.slice(2), v);
        else n.setAttribute(k, v === true ? '' : v);
      });
    }
    for (var i = 2; i < arguments.length; i++) {
      var kid = arguments[i];
      if (Array.isArray(kid)) kid.forEach(function (k) { if (k != null) n.append(k); });
      else if (kid != null) n.append(kid);
    }
    return n;
  }

  function formatMoney(n) {
    return '$' + Number(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  /** Accept only simple CSS colours so admin-supplied rule colours can't inject CSS. */
  function safeColor(c, fallback) {
    var s = String(c == null ? '' : c).trim();
    return /^(#[0-9a-f]{3,8}|[a-z]{3,20}|(rgb|hsl)a?\([0-9.,%\s\/]+\))$/i.test(s) ? s : fallback;
  }

  function debounce(fn, ms) {
    var t;
    return function () {
      var a = arguments, ctx = this;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(ctx, a); }, ms);
    };
  }

  function lsGet(key) { try { return window.localStorage.getItem(key); } catch (e) { return null; } }
  function lsSet(key, val) { try { window.localStorage.setItem(key, val); } catch (e) { /* storage unavailable */ } }
  function lsDel(key) { try { window.localStorage.removeItem(key); } catch (e) { /* storage unavailable */ } }

  var PREFS_KEY = 'nu.browse.v1.';
  var DENSITY_KEY = 'nu.browse.density';

  function loadPrefs(code) {
    try {
      var p = JSON.parse(lsGet(PREFS_KEY + code) || '{}') || {};
      return {
        order: Array.isArray(p.order) ? p.order : [],
        hidden: Array.isArray(p.hidden) ? p.hidden : [],
        widths: (p.widths && typeof p.widths === 'object') ? p.widths : {}
      };
    } catch (e) { return { order: [], hidden: [], widths: {} }; }
  }

  // ── Dialogs ────────────────────────────────────────────────────────────────
  /** Promise-based confirm built on <dialog>; falls back to window.confirm. */
  function confirmDialog(message, opts) {
    opts = opts || {};
    if (typeof HTMLDialogElement === 'undefined' || typeof document.createElement('dialog').showModal !== 'function') {
      return Promise.resolve(window.confirm(message));
    }
    return new Promise(function (resolve) {
      var done = false;
      var dlg = el('dialog', { class: 'nu-dialog', 'aria-labelledby': 'nuDlgTitle' });
      var finish = function (v) { if (done) return; done = true; dlg.close(); dlg.remove(); resolve(v); };
      var cancel = el('button', { type: 'button', class: 'nu-btn nu-btn-ghost', text: opts.cancelLabel || 'Cancel', onclick: function () { finish(false); } });
      var ok = el('button', { type: 'button', class: 'nu-btn ' + (opts.danger ? 'nu-btn-danger' : 'nu-btn-primary'), text: opts.confirmLabel || 'Confirm', onclick: function () { finish(true); } });
      dlg.append(
        el('div', { class: 'nu-dialog-body' },
          el('h3', { class: 'nu-dialog-title', id: 'nuDlgTitle', text: opts.title || 'Please confirm' }),
          el('p', { class: 'nu-dialog-text', text: message })),
        el('div', { class: 'nu-dialog-actions' }, cancel, ok));
      dlg.addEventListener('cancel', function (e) { e.preventDefault(); finish(false); });
      dlg.addEventListener('click', function (e) { if (e.target === dlg) finish(false); });
      document.body.appendChild(dlg);
      dlg.showModal();
      (opts.danger ? cancel : ok).focus();
    });
  }

  function showLightbox(src) {
    if (typeof HTMLDialogElement === 'undefined') { window.open(src, '_blank', 'noopener'); return; }
    var dlg = el('dialog', { class: 'nu-lightbox', 'aria-label': 'Image preview' });
    dlg.append(el('img', { src: src, alt: '' }));
    dlg.addEventListener('click', function () { dlg.close(); dlg.remove(); });
    dlg.addEventListener('cancel', function () { dlg.remove(); });
    document.body.appendChild(dlg);
    dlg.showModal();
  }
  // Keep the legacy global (used by older modules) pointing at the new implementation.
  if (!window._showImageLightbox) window._showImageLightbox = showLightbox;

  function showSqlDialog(debug) {
    var fmt = function (p) { return (!p || (Array.isArray(p) && !p.length)) ? '' : JSON.stringify(p, null, 2); };
    var dlg = el('dialog', { class: 'nu-dialog nu-dialog--wide', 'aria-labelledby': 'nuSqlTitle' });
    var close = function () { dlg.close(); dlg.remove(); };
    var block = function (title, sql, params) {
      var copy = el('button', { type: 'button', class: 'nu-btn nu-btn-ghost nu-btn-sm', text: 'Copy SQL' });
      copy.addEventListener('click', function () {
        if (!navigator.clipboard) return;
        navigator.clipboard.writeText(sql).then(function () {
          copy.textContent = 'Copied';
          setTimeout(function () { copy.textContent = 'Copy SQL'; }, 1800);
        });
      });
      var wrap = el('div', null,
        el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-bottom:6px' },
          el('span', { class: 'nu-dialog-label', text: title }), copy),
        el('pre', { class: 'nu-dialog-code', text: sql }));
      var ps = fmt(params);
      if (ps) wrap.append(el('div', { class: 'nu-dialog-label', style: 'margin:8px 0 6px', text: 'Bound parameters' }), el('pre', { class: 'nu-dialog-code', text: ps }));
      return wrap;
    };
    dlg.append(
      el('div', { class: 'nu-dialog-head' },
        el('h3', { class: 'nu-dialog-title', id: 'nuSqlTitle', text: 'Generated browse SQL' }),
        el('button', { type: 'button', class: 'nu-bt-icon-btn', 'aria-label': 'Close', html: icon('x'), onclick: close })),
      el('div', { class: 'nu-dialog-scroll' },
        block('1. Base SQL query', debug.base_sql || '', debug.params),
        block('2. Final paginated query (sorting, limit, offset)', debug.final_sql || '', debug.params)),
      el('div', { class: 'nu-dialog-actions' }, el('button', { type: 'button', class: 'nu-btn nu-btn-ghost', text: 'Close', onclick: close })));
    dlg.addEventListener('cancel', function () { dlg.remove(); });
    dlg.addEventListener('click', function (e) { if (e.target === dlg) close(); });
    document.body.appendChild(dlg);
    dlg.showModal();
  }

  // ── Column model ───────────────────────────────────────────────────────────
  function resolveColumns(data, prefs) {
    var layout = Array.isArray(data.layout) ? data.layout : [];
    var cols = [];
    if (data.browse_layout) {
      try { cols = JSON.parse(data.browse_layout); } catch (e) { cols = []; }
    }
    if (!Array.isArray(cols) || !cols.length) {
      cols = layout.map(function (f) {
        return {
          fieldname: f.fieldname || f.name,
          fieldlabel: f.fieldlabel || f.label || f.fieldname || f.name,
          width: '', align: 'left', formatter: 'text', sortable: true, frozen: false
        };
      });
    }
    cols = cols.filter(function (c) { return c && c.fieldname; }).map(function (c) {
      return Object.assign({}, c, {
        fieldlabel: c.fieldlabel || c.fieldname,
        align: c.align || 'left',
        formatter: c.formatter || 'text'
      });
    });
    // Saved per-user view preferences (order, widths).
    if (prefs.order.length) {
      var by = {};
      cols.forEach(function (c) { by[c.fieldname] = c; });
      var ordered = [];
      prefs.order.forEach(function (n) { if (by[n]) { ordered.push(by[n]); delete by[n]; } });
      cols.forEach(function (c) { if (by[c.fieldname]) ordered.push(c); });
      cols = ordered;
    }
    cols.forEach(function (c) { if (prefs.widths[c.fieldname]) c.width = prefs.widths[c.fieldname]; });
    return cols;
  }

  function colWidthPx(col) { return parseInt(col.width, 10) || 160; }

  /** The value shown for a cell: lookup display value first, then the raw one. */
  function cellValue(row, col) {
    var dk = col.fieldname + '_display';
    if (row[dk] != null) return row[dk];
    if (row[col.fieldname] != null) return row[col.fieldname];
    return '';
  }

  // ── The table component ────────────────────────────────────────────────────
  function BrowseTable(app, ctx) {
    this.app = app;
    this.ctx = ctx;
    this.code = ctx.code;
    this.label = ctx.label;
    this.mode = ctx.displayMode || 'inline';
    this.container = ctx.container;
    this.json = ctx.json || {};
    this.data = this.json.data || {};
    this.records = Array.isArray(this.data.records) ? this.data.records : [];
    this.certTemplates = this.json.certificate_templates || [];
    this.query = this.data.query || ctx.query || '';
    this.sort = ctx.sort || '';
    this.dir = String(ctx.dir || 'ASC').toUpperCase() === 'DESC' ? 'DESC' : 'ASC';
    this.canEdit = ctx.canEdit !== undefined ? ctx.canEdit : window.NuPerms.canEdit();
    this.canAdd = ctx.canAdd !== undefined ? ctx.canAdd : window.NuPerms.canAdd();
    this.isGlobeAdmin = String(window.nuUserRole || '').toLowerCase() === 'globeadmin';
    this.deleteEnabled = this.data.browse_delete_enabled !== undefined ? parseInt(this.data.browse_delete_enabled, 10) === 1 : true;
    this.canDelete = this.deleteEnabled && !!(window.NuPerms && window.NuPerms.canDelete && window.NuPerms.canDelete());
    this.bulkEnabled = this.deleteEnabled && this.isGlobeAdmin;
    this.searchEnabled = String(this.data.browsesearchenabled || 0) === '1';
    this.formTable = this.data.form_table || '';

    this.prefs = loadPrefs(this.code);
    this.cols = resolveColumns(this.data, this.prefs);
    this.filters = {};            // fieldname → { kind:'text', q } | { kind:'date', from, to }
    this.selected = new Set();    // record ids
    this.lastCheckedIndex = -1;
    this.ac = new AbortController();
  }

  BrowseTable.prototype.visibleCols = function () {
    var hidden = this.prefs.hidden;
    return this.cols.filter(function (c) { return hidden.indexOf(c.fieldname) === -1; });
  };

  BrowseTable.prototype.filteredRecords = function () {
    var self = this, names = Object.keys(this.filters);
    if (!names.length) return this.records;
    var colBy = {};
    this.cols.forEach(function (c) { colBy[c.fieldname] = c; });
    return this.records.filter(function (row) {
      return names.every(function (n) {
        var f = self.filters[n], col = colBy[n];
        if (!col) return true;
        var v = cellValue(row, col);
        if (f.kind === 'date') {
          if (!v) return !f.from && !f.to;
          var d = new Date(v);
          if (isNaN(d)) return false;
          if (f.from && d < new Date(f.from)) return false;
          if (f.to && d > new Date(f.to)) return false;
          return true;
        }
        return String(v).toLowerCase().indexOf(f.q) !== -1;
      });
    });
  };

  BrowseTable.prototype.savePrefs = function () {
    lsSet(PREFS_KEY + this.code, JSON.stringify({
      order: this.cols.map(function (c) { return c.fieldname; }),
      hidden: this.prefs.hidden,
      widths: this.prefs.widths
    }));
  };

  BrowseTable.prototype.destroy = function () {
    this.ac.abort();
    if (this._ro) this._ro.disconnect();
  };

  BrowseTable.prototype.go = function (page, query, sort, dir) {
    this.app.browseForm(this.code, page, query, this.label, this.mode, sort, dir);
  };

  // ── Rendering: shell ───────────────────────────────────────────────────────
  BrowseTable.prototype.render = function () {
    var self = this, c = this.container;
    if (c.__nuBt) c.__nuBt.destroy();
    c.__nuBt = this;
    c.innerHTML = '';

    var density = lsGet(DENSITY_KEY) === 'compact' ? 'compact' : 'comfortable';
    this.root = el('div', { class: 'nu-bt', dataset: { density: density, mode: this.mode } });

    this.toolbar = this.buildToolbar();
    this.chips = el('div', { class: 'nu-bt-chips', hidden: true, 'aria-label': 'Active page filters' });
    this.filterPanel = this.buildFilterPanel();
    this.tableHost = el('div', { class: 'nu-bt-surface' });
    this.footer = el('div', { class: 'nu-bt-footer' });
    this.bulkBar = this.buildBulkBar();

    this.root.append(this.toolbar, this.chips, this.filterPanel, this.tableHost, this.footer, this.bulkBar);
    c.appendChild(this.root);

    this.renderTable();
    this.renderFooter();
    this.bindKeyboard();
    return this;
  };

  // ── Toolbar ────────────────────────────────────────────────────────────────
  BrowseTable.prototype.buildToolbar = function () {
    var self = this;
    var bar = el('div', { class: 'nu-bt-toolbar', role: 'toolbar', 'aria-label': 'Table tools' });

    if (this.searchEnabled) {
      var input = el('input', {
        type: 'search', class: 'nu-input', value: this.query, 'aria-label': 'Search records',
        placeholder: this.data.browsesearchplaceholder || 'Search…', autocomplete: 'off', enterkeyhint: 'search'
      });
      var clear = el('button', { type: 'button', class: 'nu-bt-search-clear', 'aria-label': 'Clear search', html: icon('x') });
      var form = el('form', { class: 'nu-bt-search' + (this.query ? ' has-value' : ''), role: 'search' },
        el('span', { class: 'nu-bt-search-icon', html: icon('search') }), input, clear);
      input.addEventListener('input', function () { form.classList.toggle('has-value', !!input.value); });
      form.addEventListener('submit', function (e) { e.preventDefault(); self.go(1, input.value.trim(), self.sort, self.dir); });
      clear.addEventListener('click', function () { self.go(1, '', self.sort, self.dir); });
      this.searchInput = input;
      bar.append(form);
    }
    bar.append(el('span', { class: 'nu-bt-spacer' }));

    var tools = el('div', { class: 'nu-bt-tools' });

    // Page filters toggle
    this.filterCount = el('span', { class: 'nu-bt-count', hidden: true });
    this.filterBtn = el('button', {
      type: 'button', class: 'nu-btn nu-btn-ghost nu-bt-btn', 'aria-expanded': 'false', 'aria-controls': 'nuBtFilters'
    }, el('span', { html: icon('filter') }), el('span', { class: 'nu-bt-btn-label', text: 'Filters' }), this.filterCount);
    this.filterBtn.addEventListener('click', function () {
      var open = self.filterPanel.hidden;
      self.filterPanel.hidden = !open;
      self.filterBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    tools.append(this.filterBtn);

    // Columns menu
    this.columnsMenu = el('div', { class: 'nu-bt-menu', role: 'menu', hidden: true, 'aria-label': 'Columns' });
    var colsBtn = el('button', { type: 'button', class: 'nu-btn nu-btn-ghost nu-bt-btn', 'aria-haspopup': 'true', 'aria-expanded': 'false' },
      el('span', { html: icon('columns') }), el('span', { class: 'nu-bt-btn-label', text: 'Columns' }));
    tools.append(this.menuWrap(colsBtn, this.columnsMenu));
    this.renderColumnsMenu();

    // Density
    var denBtn = el('button', {
      type: 'button', class: 'nu-bt-icon-btn', title: 'Toggle compact rows', 'aria-label': 'Compact rows',
      'aria-pressed': String(lsGet(DENSITY_KEY) === 'compact'), html: icon('density')
    });
    denBtn.addEventListener('click', function () {
      var compact = self.root.dataset.density !== 'compact';
      self.root.dataset.density = compact ? 'compact' : 'comfortable';
      denBtn.setAttribute('aria-pressed', String(compact));
      lsSet(DENSITY_KEY, compact ? 'compact' : 'comfortable');
      self.layoutSticky();
    });
    tools.append(denBtn);

    // Export menu
    var expMenu = el('div', { class: 'nu-bt-menu', role: 'menu', hidden: true, 'aria-label': 'Export' });
    var addItem = function (menu, ic, text, fn) {
      var b = el('button', { type: 'button', role: 'menuitem', class: 'nu-bt-menu-item' }, el('span', { html: icon(ic) }), text);
      b.addEventListener('click', function () { self.closeMenus(); fn(); });
      menu.append(b);
    };
    addItem(expMenu, 'download', 'This page as CSV', function () { self.exportPage('csv'); });
    addItem(expMenu, 'download', 'This page as JSON', function () { self.exportPage('json'); });
    addItem(expMenu, 'download', 'Whole table as CSV', function () {
      window.location.href = 'api/export.php?code=' + encodeURIComponent(self.code);
    });
    var expBtn = el('button', { type: 'button', class: 'nu-btn nu-btn-ghost nu-bt-btn', 'aria-haspopup': 'true', 'aria-expanded': 'false' },
      el('span', { html: icon('download') }), el('span', { class: 'nu-bt-btn-label', text: 'Export' }));
    tools.append(this.menuWrap(expBtn, expMenu));

    if (this.isGlobeAdmin && this.data.browse_sql_debug) {
      var sqlBtn = el('button', { type: 'button', class: 'nu-bt-icon-btn', title: 'View generated SQL', 'aria-label': 'View generated SQL', html: icon('code') });
      sqlBtn.addEventListener('click', function () { showSqlDialog(self.data.browse_sql_debug); });
      tools.append(sqlBtn);
    }

    if (this.canAdd) {
      tools.append(el('button', {
        type: 'button', class: 'nu-btn nu-btn-primary nu-bt-btn',
        onclick: function () { self.app.addRecord(self.code, self.label, self.mode); }
      }, el('span', { html: icon('plus') }), el('span', { class: 'nu-bt-btn-label', text: 'Add record' })));
    }

    bar.append(tools);
    return bar;
  };

  /** Wrap a button + popover so only one is open at a time and Esc/outside-click closes it. */
  BrowseTable.prototype.menuWrap = function (btn, menu) {
    var self = this;
    var wrap = el('div', { class: 'nu-bt-menu-wrap' }, btn, menu);
    this._menus = this._menus || [];
    this._menus.push({ btn: btn, menu: menu });
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = menu.hidden;
      self.closeMenus();
      if (open) {
        menu.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
        var first = menu.querySelector('button, input');
        if (first) first.focus();
      }
    });
    menu.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { e.stopPropagation(); self.closeMenus(); btn.focus(); }
    });
    if (!this._menuDocBound) {
      this._menuDocBound = true;
      document.addEventListener('click', function (e) {
        if (!e.target.closest || !e.target.closest('.nu-bt-menu-wrap')) self.closeMenus();
      }, { signal: this.ac.signal });
    }
    return wrap;
  };

  BrowseTable.prototype.closeMenus = function () {
    (this._menus || []).forEach(function (m) {
      m.menu.hidden = true;
      m.btn.setAttribute('aria-expanded', 'false');
    });
  };

  BrowseTable.prototype.renderColumnsMenu = function () {
    var self = this, menu = this.columnsMenu;
    var keepScroll = menu.scrollTop;
    menu.innerHTML = '';
    menu.append(el('div', { class: 'nu-bt-menu-title', text: 'Show & order columns' }));
    this.cols.forEach(function (col, i) {
      var visible = self.prefs.hidden.indexOf(col.fieldname) === -1;
      var cb = el('input', { type: 'checkbox', class: 'nu-bt-check', checked: visible });
      cb.checked = visible;
      cb.addEventListener('change', function () {
        var h = self.prefs.hidden, idx = h.indexOf(col.fieldname);
        if (cb.checked && idx !== -1) h.splice(idx, 1);
        if (!cb.checked && idx === -1) {
          if (self.visibleCols().length <= 1) { cb.checked = true; return; }   // keep at least one column
          h.push(col.fieldname);
        }
        self.savePrefs(); self.renderTable(); self.renderFilterPanel();
      });
      var up = el('button', { type: 'button', 'aria-label': 'Move ' + col.fieldlabel + ' up', html: icon('up'), disabled: i === 0 });
      var dn = el('button', { type: 'button', 'aria-label': 'Move ' + col.fieldlabel + ' down', html: icon('down'), disabled: i === self.cols.length - 1 });
      up.addEventListener('click', function () { self.moveColumn(i, i - 1); self.renderColumnsMenu(); });
      dn.addEventListener('click', function () { self.moveColumn(i, i + 1); self.renderColumnsMenu(); });
      menu.append(el('div', { class: 'nu-bt-col-row' },
        el('label', null, cb, el('span', { text: col.fieldlabel })),
        el('span', { class: 'nu-bt-col-move' }, up, dn)));
    });
    menu.append(el('div', { class: 'nu-bt-menu-sep' }));
    var reset = el('button', { type: 'button', role: 'menuitem', class: 'nu-bt-menu-item' }, el('span', { html: icon('reset') }), 'Reset to default view');
    reset.addEventListener('click', function () {
      lsDel(PREFS_KEY + self.code);
      self.prefs = { order: [], hidden: [], widths: {} };
      self.cols = resolveColumns(self.data, self.prefs);
      self.renderColumnsMenu(); self.renderTable(); self.renderFilterPanel();
    });
    menu.append(reset);
    menu.scrollTop = keepScroll;
  };

  BrowseTable.prototype.moveColumn = function (from, to) {
    if (to < 0 || to >= this.cols.length || from === to) return;
    var moved = this.cols.splice(from, 1)[0];
    this.cols.splice(to, 0, moved);
    this.savePrefs();
    this.renderTable();
    this.renderFilterPanel();
  };

  // ── Page filters ───────────────────────────────────────────────────────────
  BrowseTable.prototype.buildFilterPanel = function () {
    var panel = el('div', { class: 'nu-bt-filters', id: 'nuBtFilters', hidden: true });
    this.filterPanelEl = panel;
    this._fillFilterPanel(panel);
    return panel;
  };

  BrowseTable.prototype.renderFilterPanel = function () {
    this._fillFilterPanel(this.filterPanel);
  };

  BrowseTable.prototype._fillFilterPanel = function (panel) {
    var self = this;
    panel.innerHTML = '';
    this.visibleCols().forEach(function (col) {
      var f = self.filters[col.fieldname];
      var id = 'nuBtF_' + col.fieldname.replace(/\W/g, '_');
      var field = el('div', { class: 'nu-bt-filter' }, el('label', { for: id, text: col.fieldlabel }));
      if (col.formatter === 'date') {
        var from = el('input', { type: 'date', class: 'nu-input', id: id, 'aria-label': col.fieldlabel + ' from', value: f && f.from || '' });
        var to = el('input', { type: 'date', class: 'nu-input', 'aria-label': col.fieldlabel + ' to', value: f && f.to || '' });
        var apply = function () {
          if (!from.value && !to.value) delete self.filters[col.fieldname];
          else self.filters[col.fieldname] = { kind: 'date', from: from.value, to: to.value };
          self.onFiltersChanged();
        };
        from.addEventListener('change', apply); to.addEventListener('change', apply);
        field.append(el('div', { class: 'nu-bt-filter-range' }, from, to));
      } else {
        var inp = el('input', { type: 'text', class: 'nu-input', id: id, placeholder: 'Contains…', autocomplete: 'off', value: f && f.q || '' });
        inp.addEventListener('input', debounce(function () {
          var q = inp.value.toLowerCase().trim();
          if (!q) delete self.filters[col.fieldname];
          else self.filters[col.fieldname] = { kind: 'text', q: q, raw: inp.value.trim() };
          self.onFiltersChanged();
        }, 120));
        field.append(inp);
      }
      panel.append(field);
    });
    panel.append(el('p', { class: 'nu-bt-filters-note', text: 'Filters only narrow the rows loaded on this page. Use search to look through the whole table.' }));
  };

  BrowseTable.prototype.onFiltersChanged = function () {
    this.renderChips();
    this.renderTable();
    this.renderFooter();
  };

  BrowseTable.prototype.renderChips = function () {
    var self = this, names = Object.keys(this.filters);
    this.chips.innerHTML = '';
    this.chips.hidden = !names.length;
    this.filterCount.hidden = !names.length;
    this.filterCount.textContent = String(names.length);
    if (!names.length) return;
    var colBy = {};
    this.cols.forEach(function (c) { colBy[c.fieldname] = c; });
    names.forEach(function (n) {
      var f = self.filters[n], lbl = colBy[n] ? colBy[n].fieldlabel : n;
      var text = f.kind === 'date'
        ? lbl + ': ' + (f.from || '…') + ' → ' + (f.to || '…')
        : lbl + ': “' + (f.raw || f.q) + '”';
      var rm = el('button', { type: 'button', 'aria-label': 'Remove filter ' + lbl, html: icon('x') });
      rm.addEventListener('click', function () { delete self.filters[n]; self.renderFilterPanel(); self.onFiltersChanged(); });
      self.chips.append(el('span', { class: 'nu-bt-chip' }, text, rm));
    });
    var clearAll = el('button', { type: 'button', class: 'nu-bt-linkbtn', text: 'Clear all' });
    clearAll.addEventListener('click', function () { self.filters = {}; self.renderFilterPanel(); self.onFiltersChanged(); });
    this.chips.append(clearAll, el('span', { class: 'nu-bt-chips-note', text: '(this page only)' }));
  };

  // ── Table ──────────────────────────────────────────────────────────────────
  BrowseTable.prototype.renderTable = function () {
    var self = this;
    var cols = this.visibleCols();
    var rows = this.filteredRecords();
    var host = this.tableHost;
    var prevScroll = this.scrollEl ? { l: this.scrollEl.scrollLeft, t: this.scrollEl.scrollTop } : null;
    host.innerHTML = '';

    if (!rows.length) {
      host.append(this.buildEmptyState());
      return;
    }

    var scroll = el('div', { class: 'nu-bt-scroll', tabindex: '-1' });
    var table = el('table', { class: 'nu-bt-table', 'aria-rowcount': String(rows.length + 1) });
    this.table = table;
    this.scrollEl = scroll;

    // Column widths
    var colgroup = el('colgroup');
    var total = 0;
    if (this.bulkEnabled) { colgroup.append(el('col', { style: 'width:44px' })); total += 44; }
    var actW = this.actionsWidth();
    colgroup.append(el('col', { style: 'width:' + actW + 'px' })); total += actW;
    cols.forEach(function (c) {
      var w = colWidthPx(c);
      c._colEl = el('col', { style: 'width:' + w + 'px' });
      colgroup.append(c._colEl);
      total += Math.max(w, 60);
    });
    table.style.minWidth = total + 'px';
    table.append(colgroup);

    table.append(this.buildHead(cols, rows));
    table.append(this.buildBody(cols, rows));
    var foot = this.buildFoot(cols, rows);
    if (foot) table.append(foot);

    scroll.append(table);
    host.append(scroll);
    this.layoutSticky();
    this.updateSelectionUI();

    if (prevScroll) { scroll.scrollLeft = prevScroll.l; scroll.scrollTop = prevScroll.t; }
    if (typeof ResizeObserver !== 'undefined') {
      if (this._ro) this._ro.disconnect();
      this._ro = new ResizeObserver(debounce(function () { self.layoutSticky(); }, 60));
      this._ro.observe(scroll);
    }
  };

  BrowseTable.prototype.actionsWidth = function () {
    var n = 1;                                   // edit / view
    if (this.canDelete) n++;
    n += (Array.isArray(this.certTemplates) ? this.certTemplates.length : 0);
    return Math.max(96, Math.min(n, 5) * 32 + 28);
  };

  BrowseTable.prototype.buildHead = function (cols, rows) {
    var self = this;
    var thead = el('thead');
    var tr = el('tr');

    if (this.bulkEnabled) {
      var all = el('input', { type: 'checkbox', class: 'nu-bt-check nu-bt-select-all', 'aria-label': 'Select all rows on this page' });
      all.addEventListener('change', function () {
        rows.forEach(function (r) { if (all.checked) self.selected.add(String(r.id)); else self.selected.delete(String(r.id)); });
        self.updateSelectionUI();
      });
      this.selectAllEl = all;
      tr.append(el('th', { class: 'nu-bt-col-select is-frozen', scope: 'col' }, all));
    }
    tr.append(el('th', { class: 'nu-bt-col-actions is-frozen', scope: 'col', text: 'Actions' }));

    cols.forEach(function (col) {
      var th = el('th', { scope: 'col', dataset: { col: col.fieldname }, style: 'text-align:' + col.align });
      if (col.frozen) th.classList.add('is-frozen');
      var inner = el('div', { class: 'nu-bt-th-inner', style: col.align === 'right' ? 'justify-content:flex-end' : (col.align === 'center' ? 'justify-content:center' : '') });
      if (col.sortable !== false) {
        var active = self.sort === col.fieldname;
        th.setAttribute('aria-sort', active ? (self.dir === 'ASC' ? 'ascending' : 'descending') : 'none');
        var btn = el('button', { type: 'button', class: 'nu-bt-sort', title: 'Sort by ' + col.fieldlabel },
          el('span', { text: col.fieldlabel }), el('span', { class: 'nu-bt-sort-icon', html: icon('arrowUp') }));
        btn.addEventListener('click', function () {
          var next = (self.sort === col.fieldname && self.dir === 'ASC') ? 'DESC' : 'ASC';
          self.go(1, self.query, col.fieldname, next);
        });
        inner.append(btn);
      } else {
        inner.append(el('span', { class: 'nu-bt-label-static', text: col.fieldlabel }));
      }
      th.append(inner);
      th.title = col.fieldlabel;

      // Resize
      var rz = el('span', { class: 'nu-bt-resizer', role: 'separator', 'aria-orientation': 'vertical', 'aria-label': 'Resize ' + col.fieldlabel });
      rz.addEventListener('pointerdown', function (e) {
        e.preventDefault(); e.stopPropagation();
        rz.setPointerCapture(e.pointerId);
        rz.classList.add('is-active');
        th.draggable = false;
        var startX = e.clientX, startW = th.offsetWidth;
        var move = function (ev) {
          var w = Math.max(60, Math.round(startW + (ev.clientX - startX)));
          col.width = w + 'px';
          if (col._colEl) col._colEl.style.width = w + 'px';
        };
        var up = function () {
          rz.classList.remove('is-active');
          rz.removeEventListener('pointermove', move);
          rz.removeEventListener('pointerup', up);
          rz.removeEventListener('pointercancel', up);
          th.draggable = true;
          self.prefs.widths[col.fieldname] = col.width;
          self.savePrefs();
          self.layoutSticky();
        };
        rz.addEventListener('pointermove', move);
        rz.addEventListener('pointerup', up);
        rz.addEventListener('pointercancel', up);
      });
      rz.addEventListener('click', function (e) { e.stopPropagation(); });
      th.append(rz);

      // Drag to reorder
      th.draggable = true;
      th.addEventListener('dragstart', function (e) {
        self._dragCol = col.fieldname;
        th.classList.add('is-dragging');
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', col.fieldname); } catch (x) { /* ignore */ }
      });
      th.addEventListener('dragend', function () {
        th.classList.remove('is-dragging');
        tr.querySelectorAll('.is-drop-target').forEach(function (n) { n.classList.remove('is-drop-target'); });
      });
      th.addEventListener('dragover', function (e) {
        if (!self._dragCol || self._dragCol === col.fieldname) return;
        e.preventDefault();
        th.classList.add('is-drop-target');
      });
      th.addEventListener('dragleave', function () { th.classList.remove('is-drop-target'); });
      th.addEventListener('drop', function (e) {
        e.preventDefault();
        th.classList.remove('is-drop-target');
        var from = self.cols.findIndex(function (c) { return c.fieldname === self._dragCol; });
        var to = self.cols.findIndex(function (c) { return c.fieldname === col.fieldname; });
        self._dragCol = null;
        if (from > -1 && to > -1) { self.moveColumn(from, to); self.renderColumnsMenu(); }
      });

      tr.append(th);
    });
    thead.append(tr);
    return thead;
  };

  BrowseTable.prototype.buildBody = function (cols, rows) {
    var self = this;
    var tbody = el('tbody');
    var app = this.app;

    rows.forEach(function (row, rowIdx) {
      var rid = String(row.id);
      var tr = el('tr', { dataset: { rid: rid }, 'aria-rowindex': String(rowIdx + 2), tabindex: rowIdx === 0 ? '0' : '-1' });
      tr.classList.add('is-clickable');

      if (self.bulkEnabled) {
        var cb = el('input', { type: 'checkbox', class: 'nu-bt-check nu-row-checkbox', 'aria-label': 'Select row ' + (rowIdx + 1), dataset: { recordId: rid } });
        cb.checked = self.selected.has(rid);
        cb.addEventListener('click', function (e) {
          // Shift-click selects the range since the last toggled row.
          if (e.shiftKey && self.lastCheckedIndex > -1) {
            var a = Math.min(self.lastCheckedIndex, rowIdx), b = Math.max(self.lastCheckedIndex, rowIdx);
            for (var i = a; i <= b; i++) {
              if (cb.checked) self.selected.add(String(rows[i].id)); else self.selected.delete(String(rows[i].id));
            }
          } else {
            if (cb.checked) self.selected.add(rid); else self.selected.delete(rid);
          }
          self.lastCheckedIndex = rowIdx;
          self.updateSelectionUI();
        });
        tr.append(el('td', { class: 'nu-bt-col-select nu-bt-cell-select is-frozen' }, cb));
      }

      tr.append(self.buildActionsCell(row));

      cols.forEach(function (col) {
        var td = el('td', { dataset: { col: col.fieldname, label: col.fieldlabel }, style: 'text-align:' + col.align });
        if (col.frozen) td.classList.add('is-frozen');
        var value = cellValue(row, col);
        self.renderCell(td, col, row, value);
        tr.append(td);
      });

      // Row click opens the record (ignored for interactive children / text selection).
      tr.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('a,button,input,select,textarea,label,[data-nu-stop]')) return;
        var sel = window.getSelection && window.getSelection();
        if (sel && String(sel).length) return;
        self.openRow(row);
      });
      tbody.append(tr);
    });
    return tbody;
  };

  BrowseTable.prototype.openRow = function (row) {
    var ctx = this.ctx, app = this.app;
    if (ctx.onEdit) return ctx.onEdit(row);
    if (this.canEdit) return app.editRecord(this.code, row.id, this.label, this.mode);
    return app.viewRecord(this.code, row.id, this.label, this.mode);
  };

  BrowseTable.prototype.buildActionsCell = function (row) {
    var self = this, app = this.app;
    var td = el('td', { class: 'nu-bt-cell-actions is-frozen' });
    var box = el('div', { class: 'nu-bt-actions' });

    var main = el('button', {
      type: 'button', class: 'nu-bt-act',
      title: this.canEdit ? 'Edit record' : 'View record',
      'aria-label': this.canEdit ? 'Edit record' : 'View record',
      html: icon(this.canEdit ? 'edit' : 'eye')
    });
    main.addEventListener('click', function (e) { e.stopPropagation(); self.openRow(row); });
    box.append(main);

    (Array.isArray(this.certTemplates) ? this.certTemplates : []).forEach(function (t) {
      var name = (t.cert_button_label && String(t.cert_button_label).trim()) || 'Certificates';
      var b = el('button', { type: 'button', class: 'nu-bt-act nu-bt-act--cert', title: name, 'aria-label': name, html: icon('award') });
      b.addEventListener('click', function (e) { e.stopPropagation(); app.openRecordCertificatesModal(self.code, row.id, t.cert_id); });
      box.append(b);
    });

    if (this.canDelete) {
      var del = el('button', { type: 'button', class: 'nu-bt-act nu-bt-act--danger', title: 'Delete record', 'aria-label': 'Delete record', html: icon('trash') });
      del.addEventListener('click', function (e) {
        e.stopPropagation();
        confirmDialog('This record will be deleted permanently. This cannot be undone.', { title: 'Delete record?', confirmLabel: 'Delete', danger: true }).then(function (ok) {
          if (!ok) return;
          app.apiJson('api/crud.php?table=' + encodeURIComponent(self.formTable) + '&id=' + encodeURIComponent(row.id), {
            method: 'DELETE', credentials: 'same-origin'
          }).then(function (res) {
            if (res && res.success) {
              app.toast('Record deleted', 'success');
              self.go(self.data.page || 1, self.query, self.sort, self.dir);
            } else {
              app.toast((res && res.error) || 'Delete failed', 'error');
            }
          }).catch(function (err) { app.toast('Delete failed: ' + err.message, 'error'); });
        });
      });
      box.append(del);
    }
    td.append(box);
    return td;
  };

  // ── Cell formatting ────────────────────────────────────────────────────────
  BrowseTable.prototype.renderCell = function (td, col, row, value) {
    var self = this, str = String(value);
    switch (col.formatter) {
      case 'currency': {
        var n = parseFloat(value);
        td.classList.add('nu-bt-num');
        td.textContent = isNaN(n) ? str : formatMoney(n);
        break;
      }
      case 'badge': {
        var key = str.toLowerCase().trim();
        var known = /^(active|completed|pending|rejected|cancelled)$/.test(key);
        td.append(el('span', { class: 'nu-bt-badge' + (known ? ' nu-bt-badge--' + key : ''), text: str }));
        break;
      }
      case 'progress_bar': {
        var pct = parseInt(value, 10);
        if (isNaN(pct)) pct = 0;
        pct = Math.max(0, Math.min(100, pct));
        var bar = el('div', { class: 'nu-bt-progress-bar' });
        bar.style.width = pct + '%';
        td.append(el('div', { class: 'nu-bt-progress', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(pct) },
          el('div', { class: 'nu-bt-progress-track' }, bar),
          el('span', { class: 'nu-bt-progress-label', text: pct + '%' })));
        break;
      }
      case 'checkbox_toggle': {
        var on = !!value && value !== '0' && value !== 'false';
        td.append(el('span', {
          class: 'nu-bt-bool nu-bt-bool--' + (on ? 'true' : 'false'), role: 'img',
          'aria-label': on ? 'Yes' : 'No', title: on ? 'Yes' : 'No', html: icon(on ? 'check' : 'x')
        }));
        break;
      }
      case 'image': {
        if (!value) break;
        var src = 'uploads/' + str;
        var img = el('img', { src: src, alt: '', loading: 'lazy' });
        var btn = el('button', { type: 'button', class: 'nu-bt-thumb', 'aria-label': 'Preview image' }, img);
        btn.addEventListener('click', function (e) { e.stopPropagation(); showLightbox(src); });
        td.append(btn);
        break;
      }
      case 'date': {
        var out = str;
        if (value && !isNaN(Date.parse(value))) out = new Date(value).toLocaleDateString();
        td.textContent = out;
        break;
      }
      case 'html':
        // Admin-authored HTML formatter: rendered as-is by design (unchanged behaviour).
        td.innerHTML = str;
        break;
      case 'custom_button': {
        var cb = el('button', { type: 'button', class: col.btn_class || 'nu-btn nu-btn-primary nu-btn-sm', text: col.btn_label || 'Click' });
        cb.addEventListener('click', function (e) {
          e.stopPropagation();
          var run = function (selection) {
            if (!col.btn_js) return;
            try {
              var rowContext = Object.assign({ _form_code: self.code, _form_label: self.label, _form_table: self.formTable }, row);
              var fn = new Function('row', 'selection', 'nu', col.btn_js);
              fn(rowContext, selection, self.app);
            } catch (err) {
              console.error('Custom button click error:', err);
              self.app.toast('JS Error: ' + err.message, 'error');
            }
          };
          if (col.btn_has_lookup) self.app._openSelectionLookupModal(col, row, run); else run(null);
        });
        td.append(cb);
        break;
      }
      default:
        td.textContent = str;
        if (str.length > 28) td.title = str;
    }
    this.applyRules(col, value, td);
  };

  /** Conditional-formatting rules: first matching rule turns the cell into a coloured pill. */
  BrowseTable.prototype.applyRules = function (col, val, td) {
    var rules = col.rules || [];
    if (!rules.length && col.cond_op) rules = [{ op: col.cond_op, val: col.cond_val, fg: col.cond_fg, bg: col.cond_bg }];
    if (!rules.length) return;
    var vStr = String(val).toLowerCase().trim(), vNum = parseFloat(val);
    for (var i = 0; i < rules.length; i++) {
      var r = rules[i], rStr = String(r.val).toLowerCase().trim(), rNum = parseFloat(r.val), hit = false;
      switch (r.op) {
        case '=': hit = vStr === rStr; break;
        case '!=': hit = vStr !== rStr; break;
        case '>': hit = !isNaN(vNum) && !isNaN(rNum) && vNum > rNum; break;
        case '<': hit = !isNaN(vNum) && !isNaN(rNum) && vNum < rNum; break;
        case 'contains': hit = vStr.indexOf(rStr) !== -1; break;
      }
      if (hit) {
        var pill = el('span', { class: 'nu-bt-badge', text: String(val) });
        pill.style.setProperty('--bt-fg', safeColor(r.fg, '#15803d'));
        pill.style.setProperty('--bt-bg', safeColor(r.bg, '#dcfce7'));
        td.textContent = '';
        td.append(pill);
        return;
      }
    }
  };

  BrowseTable.prototype.buildFoot = function (cols, rows) {
    var any = cols.some(function (c) { return c.aggregate && c.aggregate !== 'none'; });
    if (!any) return null;
    var tfoot = el('tfoot'), tr = el('tr');
    if (this.bulkEnabled) tr.append(el('td', { class: 'nu-bt-col-select is-frozen', text: 'Σ' }));
    tr.append(el('td', { class: 'is-frozen' }));
    cols.forEach(function (col) {
      var td = el('td', { style: 'text-align:' + col.align });
      if (col.frozen) td.classList.add('is-frozen');
      var type = col.aggregate || 'none', text = '';
      if (type !== 'none') {
        var vals = rows.map(function (r) { return r[col.fieldname]; }).filter(function (v) { return v !== null && v !== undefined && v !== ''; });
        var nums = vals.map(parseFloat).filter(function (v) { return !isNaN(v); });
        var money = col.formatter === 'currency';
        if (type === 'sum') {
          var s = nums.reduce(function (a, b) { return a + b; }, 0);
          text = 'Sum: ' + (money ? formatMoney(s) : Number(s.toFixed(2)));
        } else if (type === 'avg') {
          var avg = nums.length ? nums.reduce(function (a, b) { return a + b; }, 0) / nums.length : 0;
          text = 'Avg: ' + (money ? formatMoney(avg) : Number(avg.toFixed(2)));
        } else if (type === 'count') {
          text = 'Count: ' + vals.length;
        }
      }
      td.textContent = text;
      tr.append(td);
    });
    tfoot.append(tr);
    return tfoot;
  };

  /** Compute left offsets for sticky columns from their real rendered widths. */
  BrowseTable.prototype.layoutSticky = function () {
    var table = this.table;
    if (!table || !table.isConnected) return;
    var headRow = table.tHead && table.tHead.rows[0];
    if (!headRow) return;
    var offsets = [], left = 0, lastFrozen = -1, cells = Array.prototype.slice.call(headRow.cells);
    cells.forEach(function (th, i) {
      if (th.classList.contains('is-frozen')) {
        offsets[i] = left;
        left += th.offsetWidth || 0;
        lastFrozen = i;
      }
    });
    Array.prototype.forEach.call(table.rows, function (row) {
      Array.prototype.forEach.call(row.cells, function (cell, i) {
        if (offsets[i] !== undefined) {
          cell.style.left = offsets[i] + 'px';
          cell.classList.toggle('is-frozen-edge', i === lastFrozen);
        }
      });
    });
  };

  BrowseTable.prototype.buildEmptyState = function () {
    var self = this;
    var filtered = Object.keys(this.filters).length > 0 && this.records.length > 0;
    var title, text, actions = el('div', { class: 'nu-bt-empty-actions' });
    if (filtered) {
      title = 'No rows match these filters';
      text = 'Nothing on this page matches. Try removing a filter.';
      actions.append(el('button', { type: 'button', class: 'nu-btn nu-btn-ghost', text: 'Clear filters', onclick: function () { self.filters = {}; self.renderFilterPanel(); self.onFiltersChanged(); } }));
    } else if (this.query) {
      title = 'No matching records';
      text = 'Nothing found for “' + this.query + '”. Check the spelling or try a broader search.';
      actions.append(el('button', { type: 'button', class: 'nu-btn nu-btn-ghost', text: 'Clear search', onclick: function () { self.go(1, '', self.sort, self.dir); } }));
    } else {
      title = 'No records yet';
      text = this.canAdd ? 'Get started by adding the first record.' : 'There is nothing to show here yet.';
      if (this.canAdd) actions.append(el('button', { type: 'button', class: 'nu-btn nu-btn-primary', text: 'Add record', onclick: function () { self.app.addRecord(self.code, self.label, self.mode); } }));
    }
    return el('div', { class: 'nu-bt-empty' },
      el('span', { html: icon('inbox') }),
      el('p', { class: 'nu-bt-empty-title', text: title }),
      el('p', { class: 'nu-bt-empty-text', text: text }),
      actions);
  };

  // ── Footer / pagination ────────────────────────────────────────────────────
  BrowseTable.prototype.renderFooter = function () {
    var self = this, d = this.data;
    var page = parseInt(d.page, 10) || 1, pages = parseInt(d.pages, 10) || 1, total = parseInt(d.total, 10) || 0;
    var shown = this.filteredRecords().length, loaded = this.records.length;
    var size = parseInt(d.page_size, 10) || (page === 1 ? loaded : 0);

    var summary;
    if (!total) summary = '0 records';
    else if (size) {
      var from = (page - 1) * size + 1, to = (page - 1) * size + loaded;
      summary = 'Showing ' + from + '–' + to + ' of ' + total + ' records';
    } else summary = 'Page ' + page + ' of ' + pages + ' · ' + total + ' records';
    if (shown !== loaded) summary += ' · ' + shown + ' match page filters';

    this.footer.innerHTML = '';
    this.footer.append(el('div', { class: 'nu-bt-summary', role: 'status', 'aria-live': 'polite', text: summary }));
    if (pages <= 1) return;

    var nav = el('nav', { class: 'nu-bt-pages', 'aria-label': 'Pagination' });
    var go = function (p) { self.go(p, self.query, self.sort, self.dir); };
    var step = function (text, label, target, disabled) {
      var b = el('button', { type: 'button', class: 'nu-bt-page', text: text, 'aria-label': label, disabled: disabled });
      if (!disabled) b.addEventListener('click', function () { go(target); });
      return b;
    };
    nav.append(step('«', 'First page', 1, page <= 1), step('‹', 'Previous page', page - 1, page <= 1));
    var list = [];
    if (pages <= 10) { for (var i = 1; i <= pages; i++) list.push(i); }
    else {
      list.push(1);
      var s = Math.max(2, page - 2), e = Math.min(pages - 1, page + 2);
      if (s > 2) list.push('…');
      for (var j = s; j <= e; j++) list.push(j);
      if (e < pages - 1) list.push('…');
      list.push(pages);
    }
    list.forEach(function (p) {
      if (p === '…') { nav.append(el('span', { class: 'nu-bt-ellipsis', text: '…', 'aria-hidden': 'true' })); return; }
      var b = el('button', { type: 'button', class: 'nu-bt-page', text: String(p), 'aria-label': 'Page ' + p });
      if (p === page) b.setAttribute('aria-current', 'page'); else b.addEventListener('click', function () { go(p); });
      nav.append(b);
    });
    nav.append(step('›', 'Next page', page + 1, page >= pages), step('»', 'Last page', pages, page >= pages));
    this.footer.append(nav);
  };

  // ── Selection + bulk actions ───────────────────────────────────────────────
  BrowseTable.prototype.buildBulkBar = function () {
    var self = this;
    var count = el('strong');
    this.bulkCount = count;
    var del = el('button', { type: 'button', class: 'nu-btn nu-btn-danger nu-btn-sm', text: 'Delete selected' });
    del.addEventListener('click', function () { self.bulkDelete(); });
    var clear = el('button', { type: 'button', class: 'nu-btn nu-btn-ghost nu-btn-sm nu-bt-bulk-clear', text: 'Clear' });
    clear.addEventListener('click', function () { self.selected.clear(); self.lastCheckedIndex = -1; self.updateSelectionUI(); });
    return el('div', { class: 'nu-bt-bulkbar', role: 'region', 'aria-label': 'Bulk actions', hidden: true }, count, del, clear);
  };

  BrowseTable.prototype.updateSelectionUI = function () {
    var self = this;
    if (!this.bulkEnabled) return;
    var n = this.selected.size;
    this.bulkBar.hidden = n === 0;
    this.bulkCount.textContent = n + (n === 1 ? ' record selected' : ' records selected');
    if (!this.table) return;
    var pageRows = Array.prototype.slice.call(this.table.querySelectorAll('tbody tr'));
    var checked = 0;
    pageRows.forEach(function (tr) {
      var on = self.selected.has(tr.dataset.rid);
      tr.classList.toggle('is-selected', on);
      var cb = tr.querySelector('.nu-row-checkbox');
      if (cb) cb.checked = on;
      if (on) checked++;
    });
    if (this.selectAllEl) {
      this.selectAllEl.checked = pageRows.length > 0 && checked === pageRows.length;
      this.selectAllEl.indeterminate = checked > 0 && checked < pageRows.length;
    }
  };

  BrowseTable.prototype.bulkDelete = function () {
    var self = this, app = this.app, ids = Array.from(this.selected);
    if (!ids.length) return;
    confirmDialog('Delete ' + ids.length + (ids.length === 1 ? ' record' : ' records') + ' permanently? This cannot be undone.', {
      title: 'Delete selected records?', confirmLabel: 'Delete ' + ids.length, danger: true
    }).then(function (ok) {
      if (!ok) return;
      var okCount = 0, failCount = 0, firstErr = '';
      return ids.reduce(function (chain, id) {
        return chain.then(function () {
          return app.apiJson('api/crud.php?table=' + encodeURIComponent(self.formTable) + '&id=' + encodeURIComponent(id), {
            method: 'DELETE', credentials: 'same-origin'
          }).then(function (res) {
            if (res && res.success) okCount++; else { failCount++; firstErr = firstErr || (res && res.error) || 'Failed'; }
          }).catch(function (err) { failCount++; firstErr = firstErr || err.message; });
        });
      }, Promise.resolve()).then(function () {
        if (okCount && !failCount) app.toast('Deleted ' + okCount + (okCount === 1 ? ' record' : ' records'), 'success');
        else if (okCount) app.toast('Deleted ' + okCount + ', but ' + failCount + ' failed: ' + firstErr, 'error');
        else app.toast('Bulk delete failed: ' + firstErr, 'error');
        if (okCount) self.go(self.data.page || 1, self.query, self.sort, self.dir);
      });
    });
  };

  // ── Export ─────────────────────────────────────────────────────────────────
  BrowseTable.prototype.exportPage = function (type) {
    var rows = this.filteredRecords();
    if (!rows.length) { this.app.toast('No records to export', 'error'); return; }
    var name = this.code + '_page_' + (this.data.page || 1), blob;
    if (type === 'json') {
      blob = new Blob([JSON.stringify(rows, null, 2)], { type: 'application/json' });
      name += '.json';
    } else {
      var cols = this.visibleCols();
      // Neutralise spreadsheet formula injection (=, +, -, @ at the start of a cell).
      var q = function (v) {
        var s = String(v);
        if (/^[=+\-@\t\r]/.test(s)) s = "'" + s;
        return '"' + s.replace(/"/g, '""') + '"';
      };
      var lines = [cols.map(function (c) { return q(c.fieldlabel); }).join(',')];
      rows.forEach(function (r) { lines.push(cols.map(function (c) { return q(cellValue(r, c)); }).join(',')); });
      blob = new Blob(['﻿' + lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
      name += '.csv';
    }
    var a = el('a', { href: URL.createObjectURL(blob), download: name });
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
    this.app.toast('Exported ' + rows.length + (rows.length === 1 ? ' record' : ' records'));
  };

  // ── Keyboard ───────────────────────────────────────────────────────────────
  BrowseTable.prototype.bindKeyboard = function () {
    var self = this;
    var isTyping = function (t) { return /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName) || t.isContentEditable; };

    // Page-level shortcuts: "/" focuses search, Esc clears the selection. Ignored while typing,
    // while another dialog/overlay sits on top, or when this table is no longer on screen.
    document.addEventListener('keydown', function (e) {
      if (!self.root.isConnected || e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey) return;
      if (isTyping(e.target)) return;
      var blocker = document.querySelector('dialog[open], .nu-form-overlay, .nu-lookup-overlay');
      if (blocker && !blocker.contains(self.root)) return;
      if (e.key === '/' && self.searchInput) { e.preventDefault(); self.searchInput.focus(); self.searchInput.select(); }
      else if (e.key === 'Escape' && self.selected.size && !(blocker && blocker.tagName === 'DIALOG')) { self.selected.clear(); self.updateSelectionUI(); }
    }, { signal: this.ac.signal });

    // Row-level keys (roving focus).
    this.root.addEventListener('keydown', function (e) {
      var tr = e.target.closest && e.target.closest('tbody tr');
      if (!tr || e.target !== tr) return;
      var rows = Array.prototype.slice.call(self.table.querySelectorAll('tbody tr'));
      var i = rows.indexOf(tr);
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        var next = rows[i + (e.key === 'ArrowDown' ? 1 : -1)];
        if (next) { tr.tabIndex = -1; next.tabIndex = 0; next.focus(); }
      } else if (e.key === 'Enter') {
        e.preventDefault();
        var rec = self.filteredRecords()[i];
        if (rec) self.openRow(rec);
      } else if (e.key === ' ' && self.bulkEnabled) {
        e.preventDefault();
        var id = tr.dataset.rid;
        if (self.selected.has(id)) self.selected.delete(id); else self.selected.add(id);
        self.lastCheckedIndex = i;
        self.updateSelectionUI();
      }
    }, { signal: this.ac.signal });
  };

  // ── Public API ─────────────────────────────────────────────────────────────
  var NuBrowseTable = {
    /** Render the browse table into ctx.container. */
    build: function (app, ctx) { return new BrowseTable(app, ctx).render(); },

    /** Lightweight loading placeholder shown while records are fetched. */
    skeleton: function (container, columns, rows) {
      columns = columns || 5; rows = rows || 8;
      if (container.__nuBt) { container.__nuBt.destroy(); container.__nuBt = null; }
      var list = el('div', { class: 'nu-bt-surface', 'aria-hidden': 'true' });
      for (var r = 0; r < rows; r++) {
        var row = el('div', { class: 'nu-bt-skel-row' });
        for (var c = 0; c < columns; c++) row.append(el('span', { class: 'nu-bt-skel-cell' }));
        list.append(row);
      }
      var wrap = el('div', { class: 'nu-bt', 'aria-busy': 'true', 'aria-label': 'Loading records' },
        el('div', { class: 'nu-bt-toolbar' }, el('span', { class: 'nu-bt-skel-cell', style: 'max-width:320px;height:36px;border-radius:8px' })),
        list);
      container.appendChild(wrap);
    },

    /** Inline error panel with an optional retry. */
    errorState: function (container, message, retry) {
      container.innerHTML = '';
      var actions = el('div', { class: 'nu-bt-empty-actions' });
      if (retry) actions.append(el('button', { type: 'button', class: 'nu-btn nu-btn-primary', text: 'Try again', onclick: retry }));
      container.appendChild(el('div', { class: 'nu-bt-surface' }, el('div', { class: 'nu-bt-empty', role: 'alert' },
        el('span', { html: icon('alert') }),
        el('p', { class: 'nu-bt-empty-title', text: 'Could not load records' }),
        el('p', { class: 'nu-bt-empty-text', text: message || 'Something went wrong.' }),
        actions)));
    },

    confirm: confirmDialog,
    lightbox: showLightbox
  };

  window.NuBrowseTable = NuBrowseTable;
})(window);
