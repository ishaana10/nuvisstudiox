/**
 * Nuvis StudioX — Dashboard behaviours (nu-dashboard.js)
 * Pairs with modules/dashboard/*.php and assets/css/nu-dashboard.css.
 * Event-delegated, so it survives the dashboard being re-injected by NuApp.loadModule().
 *
 *  • Refresh button           → reload the dashboard module
 *  • Auto-refresh (1 min)     → opt-in, remembered; pauses while the tab is hidden,
 *                               while editing the widget layout, or while a dialog is open
 *  • Activity feed filters    → client-side, with an empty state
 */
(function (window) {
  'use strict';
  var document = window.document;
  var KEY = 'nu-dash-auto';
  var INTERVAL_MS = 60000;

  function lsGet(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } }
  function lsSet(k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* storage unavailable */ } }

  function reload(btn) {
    if (!window.NuApp || typeof window.NuApp.loadModule !== 'function') return;
    if (btn) { btn.classList.add('is-spinning'); btn.disabled = true; }
    window.NuApp.loadModule('dashboard');
  }

  function busy() {
    if (document.hidden) return true;
    if (window.nuDash && window.nuDash.editMode) return true;
    return !!document.querySelector('dialog[open], .nu-form-overlay, .nu-modal-overlay[style*="flex"], .nu-cmdk-overlay, #nuDashBuilderOverlay');
  }

  setInterval(function () {
    if (lsGet(KEY) !== '1') return;
    var dash = document.querySelector('[data-nu-dash]');
    if (!dash || !dash.isConnected || busy()) return;
    reload(null);
  }, INTERVAL_MS);

  // Initialise controls whenever a dashboard is (re)inserted into the page.
  function init(root) {
    var cb = root.querySelector('[data-nu-dash-auto]');
    if (cb) cb.checked = lsGet(KEY) === '1';
  }
  var host = document.getElementById('contentArea');
  if (host && window.MutationObserver) {
    new MutationObserver(function () {
      var d = host.querySelector('[data-nu-dash]');
      if (d && !d.__nuInit) { d.__nuInit = true; init(d); }
    }).observe(host, { childList: true, subtree: true });
  }
  var first = document.querySelector('[data-nu-dash]');
  if (first) { first.__nuInit = true; init(first); }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t.closest) return;
    var refresh = t.closest('[data-nu-dash-refresh]');
    if (refresh) { reload(refresh); return; }

    var chip = t.closest('[data-nu-dash-filters] button');
    if (chip) {
      var panel = chip.closest('.nu-dash-panel');
      var kind = chip.dataset.filter;
      chip.parentNode.querySelectorAll('button').forEach(function (b) { b.classList.toggle('is-active', b === chip); });
      var shown = 0;
      panel.querySelectorAll('[data-nu-dash-feed] > li').forEach(function (li) {
        var on = kind === 'all' || li.dataset.kind === kind;
        li.hidden = !on; if (on) shown++;
      });
      var empty = panel.querySelector('[data-nu-dash-empty]');
      if (empty) {
        empty.hidden = shown > 0;
        var label = empty.querySelector('span');
        if (label) label.textContent = 'Nothing matches this filter';
      }
    }
  });

  document.addEventListener('change', function (e) {
    if (e.target && e.target.matches && e.target.matches('[data-nu-dash-auto]')) {
      lsSet(KEY, e.target.checked ? '1' : '0');
      if (window.NuApp && window.NuApp.toast) {
        window.NuApp.toast(e.target.checked ? 'Dashboard will refresh every minute' : 'Auto-refresh off', 'info');
      }
    }
  });
})(window);
