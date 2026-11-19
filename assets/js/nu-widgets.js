/* Nuvis StudioX — dashboard widgets runtime (nu-widgets.js)
 * Loads widget fragments from modules/dashboard/widget_api.php?action=render,
 * draws charts from data-nu-chart, wires drill-down and per-widget refresh.
 * Public API: window.NuWidgets = { init, refresh, customise }.
 * Customise mode (drag/resize/hide/accent) is added in the second half of this file. */
(function () {
  'use strict';

  var API = 'modules/dashboard/widget_api.php';
  var PALETTE = ['#0f766e', '#2563eb', '#7c3aed', '#e11d48', '#d97706', '#16a34a', '#475569', '#0891b2'];
  var timers = [];
  var charts = new WeakMap();

  function csrf() { return window.nuCsrfToken || ''; }

  function api(action, opts) {
    opts = opts || {};
    var url = API + '?action=' + encodeURIComponent(action) + (opts.query ? '&' + opts.query : '');
    var init = { credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (opts.body !== undefined) {
      init.method = 'POST';
      init.headers['Content-Type'] = 'application/json';
      init.headers['X-CSRF-Token'] = csrf();
      init.body = JSON.stringify(opts.body);
    }
    return fetch(url, init).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Bad response' }; }).then(function (d) {
        if (!r.ok && !d.error) d.error = 'Request failed (' + r.status + ')';
        return d;
      });
    });
  }

  function skeleton() { return '<div class="nu-wg-skel" aria-hidden="true"><i></i><i></i><i></i></div>'; }

  function clearTimers() { timers.forEach(clearInterval); timers = []; }

  function reducedMotion() {
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  // ---------- charts ----------
  function drawCharts(scope) {
    if (!window.Chart) return;
    scope.querySelectorAll('[data-nu-chart]').forEach(function (el) {
      var cfg;
      try { cfg = JSON.parse(el.getAttribute('data-nu-chart')); } catch (e) { return; }
      var old = charts.get(el);
      if (old) { old.destroy(); charts.delete(el); }
      el.textContent = '';
      var canvas = document.createElement('canvas');
      canvas.setAttribute('role', 'img');
      canvas.setAttribute('aria-label', cfg.label || 'Chart');
      el.appendChild(canvas);

      var card = el.closest('.nu-wg-card');
      var accent = (card && getComputedStyle(card).getPropertyValue('--nu-wg-accent').trim()) || PALETTE[0];
      var muted = getComputedStyle(el).color;
      var type = cfg.type || 'bar';
      var round = type === 'pie' || type === 'doughnut';
      var compact = !!cfg.compact;
      var bg = round ? cfg.values.map(function (_, i) { return PALETTE[i % PALETTE.length]; }) :
        (type === 'line' && cfg.area ? accent + '33' : (type === 'radar' ? accent + '33' : accent));
      var ds = {
        label: cfg.label || '',
        data: cfg.values,
        backgroundColor: bg,
        borderColor: round ? 'transparent' : accent,
        borderWidth: round ? 0 : 2,
        fill: !!cfg.area || type === 'radar',
        tension: .35,
        pointRadius: compact ? 0 : 3,
        borderRadius: type === 'bar' ? 6 : 0
      };
      var scales = (round || type === 'radar' || compact) ? (compact ? { x: { display: false }, y: { display: false } } : {}) : {
        x: { grid: { display: false }, ticks: { color: muted } },
        y: { beginAtZero: true, grid: { color: 'rgba(148,163,184,.2)' }, ticks: { color: muted, precision: 0 } }
      };
      var chart = new Chart(canvas, {
        type: type,
        data: { labels: cfg.labels, datasets: [ds] },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          animation: reducedMotion() ? false : { duration: 500 },
          plugins: { legend: { display: round, position: 'bottom', labels: { color: muted, boxWidth: 10 } }, tooltip: { enabled: !compact } },
          scales: scales
        }
      });
      charts.set(el, chart);
    });
  }

  // ---------- drill-down ----------
  function openPopup(code) {
    if (window.NuApp) {
      if (typeof NuApp.openPopup === 'function') { NuApp.openPopup(code); return; }
      if (typeof NuApp.showPopup === 'function') { NuApp.showPopup(code); return; }
      if (typeof NuApp.openDialog === 'function') { NuApp.openDialog(code); return; }
    }
    var prev = document.getElementById('nuWgPopup');
    if (prev) prev.remove();
    var ov = document.createElement('div');
    ov.id = 'nuWgPopup';
    ov.className = 'nu-wg-popup';
    ov.innerHTML = '<div class="nu-wg-popup-box" role="dialog" aria-modal="true"><button type="button" class="nu-wg-popup-close" aria-label="Close">&times;</button><iframe></iframe></div>';
    ov.querySelector('iframe').src = 'index.php?form=' + encodeURIComponent(code);
    var close = function () { ov.remove(); document.removeEventListener('keydown', onKey); };
    var onKey = function (e) { if (e.key === 'Escape') close(); };
    ov.addEventListener('click', function (e) { if (e.target === ov || e.target.classList.contains('nu-wg-popup-close')) close(); });
    document.addEventListener('keydown', onKey);
    document.body.appendChild(ov);
  }

  function drill(code, mode) {
    if (!code) return;
    try {
      if (mode === 'popup') { openPopup(code); return; }
      if (window.NuApp) {
        if (typeof NuApp._browseInline === 'function') { NuApp._browseInline(code); return; }
        if (typeof NuApp.openForm === 'function') { NuApp.openForm(code); return; }
        if (typeof NuApp.loadModule === 'function') { NuApp.loadModule(code); return; }
      }
      window.location.href = '?form=' + encodeURIComponent(code);
    } catch (e) { console.error('[NuWidgets drill]', e); }
  }

  // ---------- fragment loading ----------
  function body(card) { return card.querySelector('.nu-wg-body'); }

  function showError(b, msg) {
    b.innerHTML = '<div class="nu-wg-error"><span></span><button type="button" class="nu-wg-retry" data-nu-retry>Retry</button></div>';
    b.querySelector('span').textContent = msg;
  }

  function load(card, silent) {
    var id = card.getAttribute('data-widget-id');
    var b = body(card);
    if (!b) return Promise.resolve();
    if (silent) card.classList.add('is-refreshing'); else { card.classList.add('is-loading'); b.innerHTML = skeleton(); }
    return api('render', { query: 'id=' + encodeURIComponent(id) }).then(function (d) {
      if (!d || d.html === undefined) {
        showError(b, (d && d.error) || 'Could not load this widget.');
      } else {
        b.innerHTML = d.html;
        card.setAttribute('data-refresh', d.refresh || card.getAttribute('data-refresh') || '0');
        drawCharts(b);
      }
    }).catch(function () {
      showError(b, 'Network problem.');
    }).then(function () {
      card.classList.remove('is-loading', 'is-refreshing');
    });
  }

  function schedule(grid) {
    clearTimers();
    grid.querySelectorAll('.nu-wg-card').forEach(function (card) {
      var secs = parseInt(card.getAttribute('data-refresh'), 10) || 0;
      if (secs < 15) return;
      timers.push(setInterval(function () {
        if (!document.body.contains(card)) return;
        if (document.hidden) return;
        load(card, true);
      }, secs * 1000));
    });
  }

  var delegated = false;
  function delegate() {
    if (delegated) return;
    delegated = true;
    document.addEventListener('click', function (e) {
      var retry = e.target.closest('[data-nu-retry]');
      if (retry) {
        var card = retry.closest('.nu-wg-card');
        if (card) load(card, false);
        return;
      }
      var d = e.target.closest('.nu-wg [data-nu-drill]');
      if (d) {
        e.preventDefault();
        drill(d.getAttribute('data-nu-drill'), d.getAttribute('data-nu-mode'));
      }
    });
  }

  var HEX = /^#[0-9a-f]{6}$/;
  // Custom hex accents are applied through the CSSOM (inline style attributes may be blocked by CSP).
  function applyAccents(grid) {
    var g = grid.getAttribute('data-accent') || '';
    if (HEX.test(g)) grid.style.setProperty('--nu-wg-group-accent', g);
    grid.querySelectorAll('.nu-wg-card[data-accent-hex]').forEach(function (c) {
      var h = c.getAttribute('data-accent-hex') || '';
      if (HEX.test(h)) c.style.setProperty('--nu-wg-accent', h);
    });
  }

  function init(root) {
    var grid = (root || document).querySelector('#nuWidgetGrid');
    if (!grid) { clearTimers(); return; }
    if (grid._nuInit) return;
    grid._nuInit = true;
    delegate();
    applyAccents(grid);
    grid.querySelectorAll('.nu-wg-card:not([hidden])').forEach(function (c) { load(c, false); });
    schedule(grid);
    if (window.NuWidgets && typeof window.NuWidgets._afterInit === 'function') window.NuWidgets._afterInit(grid);
  }

  function refresh(id) {
    var sel = id ? '.nu-wg-card[data-widget-id="' + id + '"]' : '.nu-wg-card';
    document.querySelectorAll('#nuWidgetGrid ' + sel).forEach(function (c) { load(c, true); });
  }

  window.NuWidgets = window.NuWidgets || {};
  window.NuWidgets.init = init;
  window.NuWidgets.refresh = refresh;
  window.NuWidgets._api = api;
  window.NuWidgets._applyAccents = applyAccents;
  window.NuWidgets._load = load;
  window.NuWidgets._drawCharts = drawCharts;
  window.NuWidgets.customise = window.NuWidgets.customise || function () {};

  if (document.readyState !== 'loading') init(document);
  else document.addEventListener('DOMContentLoaded', function () { init(document); });
})();

/* ===== Customise mode (every signed-in user) =====
 * Per-user layout: order, size, visibility, accent; plus look settings
 * (default accent, density, card style). Role widgets themselves stay developer-managed. */
(function () {
  'use strict';
  var NW = window.NuWidgets;
  var TOKENS = { teal: '#0f766e', blue: '#2563eb', violet: '#7c3aed', rose: '#e11d48', amber: '#d97706', green: '#16a34a', slate: '#475569' };
  var SIZES = [['S', 1], ['M', 2], ['L', 3], ['XL', 4]];
  var HEX = /^#[0-9a-f]{6}$/;
  var dirty = {};          // widget_id -> partial pref
  var saveTimer = null;
  var panel = null;
  var statusEl = null;

  function el(tag, attrs) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') n.textContent = attrs[k];
      else if (k === 'class') n.className = attrs[k];
      else if (k.indexOf('on') === 0) n.addEventListener(k.slice(2), attrs[k]);
      else n.setAttribute(k, attrs[k]);
    });
    for (var i = 2; i < arguments.length; i++) if (arguments[i]) n.appendChild(arguments[i]);
    return n;
  }
  function grid() { return document.getElementById('nuWidgetGrid'); }
  function cards(g) { return Array.prototype.slice.call((g || grid()).querySelectorAll('.nu-wg-card')); }
  function titleOf(c) { var t = c.querySelector('.nu-wg-title'); return t ? t.textContent : 'widget'; }
  function status(msg, isErr, retry) {
    statusEl = statusEl || document.getElementById('nuWgStatus');
    if (!statusEl) return;
    statusEl.textContent = msg;
    statusEl.classList.toggle('is-error', !!isErr);
    if (retry) {
      statusEl.appendChild(document.createTextNode(' '));
      statusEl.appendChild(el('button', { type: 'button', class: 'nu-wg-retry', text: 'Retry', onclick: function () { flush(); } }));
    }
  }

  // ---------- saving ----------
  function mark(id, patch) {
    dirty[id] = Object.assign(dirty[id] || {}, patch);
    status('Saving…');
    clearTimeout(saveTimer);
    saveTimer = setTimeout(flush, 600);
  }
  function markPositions() {
    cards().forEach(function (c, i) { mark(c.getAttribute('data-widget-id'), { position: i }); });
  }
  function flush() {
    clearTimeout(saveTimer);
    var ids = Object.keys(dirty);
    if (!ids.length) return Promise.resolve();
    var batch = dirty; dirty = {};
    var items = ids.map(function (id) { return Object.assign({ id: parseInt(id, 10) }, batch[id]); });
    return NW._api('prefs_save', { body: { items: items } }).then(function (d) {
      if (d && d.ok) status('Saved');
      else throw new Error((d && d.error) || 'failed');
    }).catch(function () {
      ids.forEach(function (id) { dirty[id] = Object.assign({}, batch[id], dirty[id] || {}); });
      status('Couldn’t save.', true, true);
    });
  }
  function saveSettings(patch) {
    return NW._api('settings_save', { body: patch }).then(function (d) {
      status(d && d.ok ? 'Saved' : 'Couldn’t save.', !(d && d.ok), !(d && d.ok));
    }).catch(function () { status('Couldn’t save.', true, false); });
  }

  // ---------- per-card edit strip ----------
  function setSize(c, w) {
    c.setAttribute('data-w', w);
    var strip = c.querySelector('.nu-wg-edit');
    if (strip) strip.querySelectorAll('[data-size]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-size') == w ? 'true' : 'false'); });
    mark(c.getAttribute('data-widget-id'), { width: w });
    var ch = c.querySelector('[data-nu-chart]');
    if (ch) setTimeout(function () { NW._drawCharts(c); }, 50);
  }
  function move(c, dir) {
    var g = grid();
    var vis = cards(g).filter(function (x) { return !x.hidden; });
    var i = vis.indexOf(c), j = i + dir;
    if (j < 0 || j >= vis.length) return;
    if (dir < 0) g.insertBefore(c, vis[j]); else g.insertBefore(c, vis[j].nextSibling);
    markPositions();
    announce(titleOf(c) + ' moved to position ' + (j + 1) + ' of ' + vis.length);
    var b = c.querySelector(dir < 0 ? '[data-act="up"]' : '[data-act="down"]');
    if (b) b.focus();
  }
  function announce(msg) {
    var live = document.getElementById('nuWgLive');
    if (!live) { live = el('div', { id: 'nuWgLive', class: 'nu-wg-sr', 'aria-live': 'polite' }); document.body.appendChild(live); }
    live.textContent = msg;
  }
  function setAccent(c, value) {
    c.removeAttribute('data-accent'); c.removeAttribute('data-accent-hex'); c.style.removeProperty('--nu-wg-accent');
    if (TOKENS[value]) c.setAttribute('data-accent', value);
    else if (HEX.test(value)) { c.setAttribute('data-accent-hex', value); c.style.setProperty('--nu-wg-accent', value); }
    mark(c.getAttribute('data-widget-id'), { accent: value || '' });
    var ch = c.querySelector('[data-nu-chart]');
    if (ch) NW._drawCharts(c);
  }
  function hideCard(c) {
    c.hidden = true; c.setAttribute('data-hidden', '1');
    mark(c.getAttribute('data-widget-id'), { hidden: 1 });
    announce(titleOf(c) + ' hidden');
    renderTray();
  }
  function showCard(c) {
    c.hidden = false; c.removeAttribute('data-hidden');
    mark(c.getAttribute('data-widget-id'), { hidden: 0 });
    var b = c.querySelector('.nu-wg-body');
    if (b && !b.children.length) NW._load(c, false);
    announce(titleOf(c) + ' shown');
    renderTray();
  }

  function swatchRow(current, onPick, allowClear) {
    var row = el('div', { class: 'nu-wg-swatches', role: 'group', 'aria-label': 'Accent colour' });
    if (allowClear) row.appendChild(el('button', { type: 'button', class: 'nu-wg-swatch is-clear', title: 'Default', 'aria-label': 'Default colour', 'aria-pressed': current ? 'false' : 'true', onclick: function () { onPick(''); } }));
    Object.keys(TOKENS).forEach(function (t) {
      var b = el('button', { type: 'button', class: 'nu-wg-swatch', 'data-swatch': t, title: t, 'aria-label': t, 'aria-pressed': current === t ? 'true' : 'false', onclick: function () { onPick(t); } });
      b.style.setProperty('--sw', TOKENS[t]);
      row.appendChild(b);
    });
    var pick = el('input', { type: 'color', class: 'nu-wg-swatch-input', 'aria-label': 'Custom colour', value: HEX.test(current || '') ? current : '#0f766e' });
    pick.addEventListener('change', function () { onPick(pick.value.toLowerCase()); });
    row.appendChild(pick);
    return row;
  }

  function buildStrip(c) {
    if (c.querySelector('.nu-wg-edit')) return;
    var locked = c.getAttribute('data-locked') === '1';
    var w = parseInt(c.getAttribute('data-w'), 10);
    var strip = el('div', { class: 'nu-wg-edit' });
    var grip = el('button', { type: 'button', class: 'nu-wg-tool nu-wg-grip', 'aria-label': 'Drag ' + titleOf(c), title: 'Drag to reorder', 'data-act': 'grip' }, svg('M9 5h.01M9 12h.01M9 19h.01M15 5h.01M15 12h.01M15 19h.01'));
    var up = el('button', { type: 'button', class: 'nu-wg-tool', 'data-act': 'up', 'aria-label': 'Move ' + titleOf(c) + ' earlier', title: 'Move earlier', onclick: function () { move(c, -1); } }, svg('M18 15l-6-6-6 6'));
    var down = el('button', { type: 'button', class: 'nu-wg-tool', 'data-act': 'down', 'aria-label': 'Move ' + titleOf(c) + ' later', title: 'Move later', onclick: function () { move(c, 1); } }, svg('M6 9l6 6 6-6'));
    var sizes = el('div', { class: 'nu-wg-sizes', role: 'group', 'aria-label': 'Size' });
    SIZES.forEach(function (s) {
      sizes.appendChild(el('button', { type: 'button', class: 'nu-wg-size', 'data-size': s[1], 'aria-pressed': w === s[1] ? 'true' : 'false', text: s[0], onclick: function () { setSize(c, s[1]); } }));
    });
    var cur = c.getAttribute('data-accent') || c.getAttribute('data-accent-hex') || '';
    strip.appendChild(grip); strip.appendChild(up); strip.appendChild(down); strip.appendChild(sizes);
    var sw = swatchRow(cur, function (v) { setAccent(c, v); sw.querySelectorAll('[aria-pressed]').forEach(function (b) { b.setAttribute('aria-pressed', (b.getAttribute('data-swatch') || '') === v ? 'true' : 'false'); }); }, true);
    strip.appendChild(sw);
    if (!locked) strip.appendChild(el('button', { type: 'button', class: 'nu-wg-tool', 'data-act': 'hide', 'aria-label': 'Hide ' + titleOf(c), title: 'Hide', onclick: function () { hideCard(c); } }, svg('M3 3l18 18M10.6 10.6a2 2 0 0 0 2.8 2.8M9.9 5.1A10 10 0 0 1 12 5c5 0 9 4.5 10 7a12 12 0 0 1-3 4M6.1 6.1A12 12 0 0 0 2 12c1 2.5 5 7 10 7a10 10 0 0 0 4.1-.9')));
    c.insertBefore(strip, c.querySelector('.nu-wg-body'));
    grip.addEventListener('mousedown', function () { c.draggable = true; });
    grip.addEventListener('touchstart', function () { c.draggable = true; }, { passive: true });
  }
  function svg(d) {
    var ns = 'http://www.w3.org/2000/svg';
    var s = document.createElementNS(ns, 'svg');
    s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('width', '16'); s.setAttribute('height', '16');
    s.setAttribute('fill', 'none'); s.setAttribute('stroke', 'currentColor'); s.setAttribute('stroke-width', '2');
    s.setAttribute('stroke-linecap', 'round'); s.setAttribute('stroke-linejoin', 'round'); s.setAttribute('aria-hidden', 'true');
    var p = document.createElementNS(ns, 'path'); p.setAttribute('d', d); s.appendChild(p);
    return s;
  }

  // ---------- drag and drop ----------
  var dragging = null;
  function wireDrag(g) {
    if (g._nuDrag) return;
    g._nuDrag = true;
    g.addEventListener('dragstart', function (e) {
      var c = e.target.closest && e.target.closest('.nu-wg-card');
      if (!c || !c.draggable) return;
      dragging = c; c.classList.add('is-dragging');
      e.dataTransfer.effectAllowed = 'move';
      try { e.dataTransfer.setData('text/plain', c.getAttribute('data-widget-id')); } catch (x) { /* IE */ }
    });
    g.addEventListener('dragover', function (e) {
      if (!dragging) return;
      var over = e.target.closest('.nu-wg-card');
      if (!over || over === dragging || over.hidden) return;
      e.preventDefault();
      var r = over.getBoundingClientRect();
      var before = (e.clientY < r.top + r.height / 2) && (e.clientX < r.left + r.width / 2) || e.clientY < r.top + r.height * .25;
      g.insertBefore(dragging, before ? over : over.nextSibling);
    });
    g.addEventListener('dragend', function () {
      if (!dragging) return;
      dragging.classList.remove('is-dragging'); dragging.draggable = false; dragging = null;
      markPositions();
      announce('Layout updated');
    });
  }

  // ---------- panel ----------
  function renderTray() {
    if (!panel) return;
    var tray = panel.querySelector('.nu-wg-tray');
    tray.textContent = '';
    var hid = cards().filter(function (c) { return c.hidden; });
    if (!hid.length) { tray.appendChild(el('p', { class: 'nu-wg-hint', text: 'Nothing hidden.' })); return; }
    hid.forEach(function (c) {
      tray.appendChild(el('div', { class: 'nu-wg-tray-item' }, el('span', { text: titleOf(c) }),
        el('button', { type: 'button', class: 'nu-btn nu-btn-ghost nu-btn-sm', text: 'Show', 'aria-label': 'Show ' + titleOf(c), onclick: function () { showCard(c); } })));
    });
  }
  function seg(label, key, options, current, onPick) {
    var wrap = el('div', { class: 'nu-wg-seg', role: 'group', 'aria-label': label });
    options.forEach(function (o) {
      wrap.appendChild(el('button', { type: 'button', class: 'nu-wg-seg-btn', 'data-val': o[0], 'aria-pressed': current === o[0] ? 'true' : 'false', text: o[1], onclick: function () {
        wrap.querySelectorAll('button').forEach(function (b) { b.setAttribute('aria-pressed', b === this ? 'true' : 'false'); }, this);
        onPick(o[0]);
      } }));
    });
    return wrap;
  }
  function buildPanel() {
    if (panel) return panel;
    var g = grid();
    var curAccent = g.getAttribute('data-accent') || '';
    panel = el('aside', { id: 'nuWgPanel', class: 'nu-wg-panel', 'aria-label': 'Customise dashboard' });
    panel.appendChild(el('div', { class: 'nu-wg-panel-head' }, el('h3', { text: 'Customise' }),
      el('button', { type: 'button', class: 'nu-btn nu-btn-primary nu-btn-sm', text: 'Done', onclick: function () { NW.customise(false); } })));
    panel.appendChild(el('h4', { text: 'Default colour' }));
    var sw = swatchRow(curAccent, function (v) {
      g.setAttribute('data-accent', v); g.style.removeProperty('--nu-wg-group-accent');
      if (HEX.test(v)) g.style.setProperty('--nu-wg-group-accent', v);
      sw.querySelectorAll('[aria-pressed]').forEach(function (b) { b.setAttribute('aria-pressed', (b.getAttribute('data-swatch') || '') === v ? 'true' : 'false'); });
      cards(g).forEach(function (c) { if (c.querySelector('[data-nu-chart]')) NW._drawCharts(c); });
      saveSettings({ accent: v });
    }, true);
    panel.appendChild(sw);
    panel.appendChild(el('h4', { text: 'Density' }));
    panel.appendChild(seg('Density', 'density', [['comfortable', 'Comfortable'], ['compact', 'Compact']], g.getAttribute('data-density'), function (v) { g.setAttribute('data-density', v); saveSettings({ density: v }); }));
    panel.appendChild(el('h4', { text: 'Card style' }));
    panel.appendChild(seg('Card style', 'card_style', [['soft', 'Soft'], ['flat', 'Flat'], ['outlined', 'Outlined']], g.getAttribute('data-card-style'), function (v) { g.setAttribute('data-card-style', v); saveSettings({ card_style: v }); }));
    panel.appendChild(el('h4', { text: 'Hidden widgets' }));
    panel.appendChild(el('div', { class: 'nu-wg-tray' }));
    panel.appendChild(el('button', { type: 'button', class: 'nu-btn nu-btn-ghost nu-wg-reset', text: 'Reset to role default', onclick: confirmReset }));
    return panel;
  }

  function confirmReset() {
    var dlg = el('dialog', { class: 'nu-dialog', 'aria-labelledby': 'nuWgResetTitle' });
    var done = function (ok) {
      dlg.close(); dlg.remove();
      if (!ok) return;
      clearTimeout(saveTimer); dirty = {};
      NW._api('prefs_reset', { body: {} }).then(function (d) {
        if (d && d.ok) window.location.reload(); else status('Couldn’t reset.', true, false);
      });
    };
    dlg.appendChild(el('div', { class: 'nu-dialog-body' }, el('h3', { class: 'nu-dialog-title', id: 'nuWgResetTitle', text: 'Reset your dashboard?' }),
      el('p', { class: 'nu-dialog-text', text: 'Your order, sizes, colours and hidden widgets go back to what your role provides. Your personal widgets are kept.' })));
    dlg.appendChild(el('div', { class: 'nu-dialog-actions' },
      el('button', { type: 'button', class: 'nu-btn nu-btn-ghost', text: 'Cancel', onclick: function () { done(false); } }),
      el('button', { type: 'button', class: 'nu-btn nu-btn-danger', text: 'Reset', onclick: function () { done(true); } })));
    dlg.addEventListener('cancel', function (e) { e.preventDefault(); done(false); });
    document.body.appendChild(dlg);
    if (typeof dlg.showModal === 'function') dlg.showModal(); else dlg.setAttribute('open', '');
  }

  // ---------- entry point ----------
  NW.editing = false;
  NW.customise = function (on) {
    var root = document.getElementById('nuWidgets'), g = grid();
    if (!root || !g) return;
    on = on !== false;
    NW.editing = on;
    root.classList.toggle('is-customising', on);
    var btn = document.getElementById('nuWgCustomise');
    if (btn) { btn.setAttribute('aria-pressed', on ? 'true' : 'false'); btn.textContent = on ? 'Done' : 'Customise'; }
    if (on) {
      cards(g).forEach(buildStrip);
      wireDrag(g);
      var p = buildPanel();
      if (!p.parentNode) root.appendChild(p);
      renderTray();
      var first = root.querySelector('.nu-wg-edit [data-act="up"]');
      if (first) first.focus();
    } else {
      flush();
      if (panel && panel.parentNode) panel.parentNode.removeChild(panel);
      panel = null;
      cards(g).forEach(function (c) { c.draggable = false; var s = c.querySelector('.nu-wg-edit'); if (s) s.remove(); });
    }
  };

  NW._afterInit = function (g) {
    var host = document.getElementById('nuWgBarActions');
    if (host && !document.getElementById('nuWgCustomise')) {
      host.appendChild(el('button', { type: 'button', id: 'nuWgCustomise', class: 'nu-btn nu-btn-ghost nu-btn-sm', 'aria-pressed': 'false', text: 'Customise', onclick: function () { NW.customise(!NW.editing); } }));
    }
    wireDrag(g);
  };
  // init() already ran before this IIFE registered _afterInit when the script is evaluated with a grid present
  var g0 = grid();
  if (g0 && g0._nuInit) NW._afterInit(g0);

  document.addEventListener('visibilitychange', function () { if (document.hidden) flush(); });
  window.addEventListener('pagehide', function () { flush(); });
})();
