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
