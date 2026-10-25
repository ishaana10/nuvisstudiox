/**
 * Nuvis StudioX — Shell enhancements (nu-shell.js)
 *
 *  • Command palette  (Ctrl/Cmd+K, or "/" outside inputs) — jump to any sidebar
 *    destination or run a quick action. Items are read from the live sidebar,
 *    so it follows whatever menu the project/role actually has.
 *  • Toasts           — icons, dismiss button, hover-to-pause, stack limit,
 *    screen-reader announcements. Replaces NuApp.toast (same signature).
 *  • Shortcuts        — "[" toggles the sidebar; "?" inside the palette lists them.
 *
 * Additive: nothing here is required for the app to work. Styles: nu-shell.css.
 */
(function (window) {
  'use strict';
  var document = window.document;
  var isMac = /Mac|iPhone|iPad/i.test(window.navigator.platform || window.navigator.userAgent || '');

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }

  // ── Toasts ────────────────────────────────────────────────────────────────
  var TOAST_ICONS = {
    success: '<path d="m20 6-11 11-5-5"/>',
    error:   '<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>',
    warning: '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0zM12 9v4M12 17h.01"/>',
    info:    '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>'
  };
  var MAX_TOASTS = 4;

  function toast(message, type) {
    type = TOAST_ICONS[type] ? type : (type === 'danger' ? 'error' : 'success');
    var container = document.querySelector('.nu-toast-container');
    if (!container) {
      container = el('div', 'nu-toast-container');
      container.setAttribute('role', 'region');
      container.setAttribute('aria-label', 'Notifications');
      document.body.appendChild(container);
    }
    while (container.children.length >= MAX_TOASTS) container.removeChild(container.firstChild);

    var t = el('div', 'nu-toast ' + type);
    t.setAttribute('role', type === 'error' ? 'alert' : 'status');
    var ic = el('span', 'nu-toast-icon');
    ic.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + TOAST_ICONS[type] + '</svg>';
    var msg = el('span', 'nu-toast-msg', String(message == null ? '' : message));
    var close = el('button', 'nu-toast-close', '×');
    close.type = 'button';
    close.setAttribute('aria-label', 'Dismiss');
    t.appendChild(ic); t.appendChild(msg); t.appendChild(close);
    container.appendChild(t);

    var timer = null, remaining = type === 'error' ? 7000 : 4000, started = 0;
    function dismiss() {
      clearTimeout(timer);
      t.classList.add('is-leaving');
      setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 180);
    }
    function start() { started = Date.now(); timer = setTimeout(dismiss, remaining); }
    function pause() { clearTimeout(timer); remaining -= Date.now() - started; if (remaining < 800) remaining = 800; }
    close.addEventListener('click', dismiss);
    t.addEventListener('mouseenter', pause);
    t.addEventListener('mouseleave', start);
    start();
    return t;
  }

  // ── Command palette ───────────────────────────────────────────────────────
  var palette = null;

  /** Fuzzy-ish score: all query chars must appear in order; contiguous/prefix matches rank higher. */
  function score(label, q) {
    if (!q) return 1;
    label = label.toLowerCase(); q = q.toLowerCase();
    var idx = label.indexOf(q);
    if (idx === 0) return 100;
    if (idx > 0) return 80 - Math.min(idx, 40);
    var i = 0, gaps = 0, last = -1;
    for (var c = 0; c < label.length && i < q.length; c++) {
      if (label[c] === q[i]) { if (last >= 0 && c - last > 1) gaps++; last = c; i++; }
    }
    return i === q.length ? Math.max(5, 40 - gaps * 4) : 0;
  }

  function collectItems() {
    var items = [], seen = {};
    document.querySelectorAll('.nu-sidebar a.nu-nav-item, .nu-sidebar .nu-nav-children a, .nu-sidebar button.nu-nav-item').forEach(function (a) {
      var label = (a.textContent || '').replace(/\s+/g, ' ').trim();
      if (!label || seen[label.toLowerCase()]) return;
      seen[label.toLowerCase()] = 1;
      var group = a.closest('.nu-nav-group');
      var gl = group && group.querySelector('.nu-nav-group-label');
      items.push({
        label: label,
        hint: gl ? gl.textContent.replace(/\s+/g, ' ').trim() : 'Go to',
        run: function () { a.click(); }
      });
    });
    var app = window.NuApp;
    var theme = function (mode) {
      return function () {
        document.documentElement.setAttribute('data-theme', mode);
        try { localStorage.setItem('nu-theme', mode); } catch (e) {}
        toast('Theme: ' + mode.charAt(0).toUpperCase() + mode.slice(1), 'info');
      };
    };
    items.push(
      { label: 'Toggle sidebar', hint: 'Action', kbd: '[', run: toggleSidebar },
      { label: 'Theme: Light', hint: 'Action', run: theme('light') },
      { label: 'Theme: Dark', hint: 'Action', run: theme('dark') },
      { label: 'Theme: Auto (system)', hint: 'Action', run: theme('auto') },
      { label: 'Reload page', hint: 'Action', run: function () { window.location.reload(); } }
    );
    if (app && typeof app.loadModule === 'function' && !seen['dashboard']) {
      items.unshift({ label: 'Dashboard', hint: 'Go to', run: function () { app.loadModule('dashboard'); } });
    }
    return items;
  }

  function toggleSidebar() {
    var appEl = document.getElementById('nuApp');
    if (!appEl) return;
    if (window.innerWidth <= 768) {
      var sb = document.getElementById('sidebar'), ov = document.getElementById('overlay');
      if (sb) sb.classList.toggle('open');
      if (ov) ov.classList.toggle('open');
    } else {
      appEl.classList.toggle('sidebar-collapsed');
      try { localStorage.setItem('nu-sidebar-collapsed', appEl.classList.contains('sidebar-collapsed') ? '1' : '0'); } catch (e) {}
    }
  }

  function openPalette() {
    if (palette) { palette.input.focus(); return; }
    var returnFocus = document.activeElement;
    var all = collectItems(), results = [], active = 0;

    var overlay = el('div', 'nu-cmdk-overlay');
    var box = el('div', 'nu-cmdk');
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-label', 'Command palette');
    var input = el('input', 'nu-cmdk-input');
    input.type = 'text';
    input.placeholder = 'Jump to a page or run an action…';
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-expanded', 'true');
    input.setAttribute('aria-controls', 'nuCmdkList');
    input.setAttribute('autocomplete', 'off');
    input.setAttribute('spellcheck', 'false');
    var list = el('ul', 'nu-cmdk-list');
    list.id = 'nuCmdkList';
    list.setAttribute('role', 'listbox');
    var foot = el('div', 'nu-cmdk-foot');
    foot.innerHTML = '<span><kbd>↑</kbd><kbd>↓</kbd> navigate</span><span><kbd>↵</kbd> open</span><span><kbd>esc</kbd> close</span>';
    box.appendChild(input); box.appendChild(list); box.appendChild(foot);
    overlay.appendChild(box);
    document.body.appendChild(overlay);

    function close() {
      if (!palette) return;
      document.removeEventListener('keydown', onKey, true);
      overlay.remove();
      palette = null;
      if (returnFocus && returnFocus.focus && document.contains(returnFocus)) returnFocus.focus();
    }
    function run(item) { close(); if (item) setTimeout(item.run, 0); }

    function render() {
      var q = input.value.trim();
      results = all.map(function (it) { return { it: it, s: score(it.label, q) }; })
        .filter(function (r) { return r.s > 0; })
        .sort(function (a, b) { return b.s - a.s; })
        .slice(0, 50).map(function (r) { return r.it; });
      if (active >= results.length) active = Math.max(0, results.length - 1);
      list.innerHTML = '';
      if (!results.length) {
        var empty = el('li', 'nu-cmdk-empty', 'No matches for “' + q + '”');
        list.appendChild(empty);
        input.removeAttribute('aria-activedescendant');
        return;
      }
      results.forEach(function (it, i) {
        var li = el('li', 'nu-cmdk-item' + (i === active ? ' is-active' : ''));
        li.id = 'nuCmdkItem' + i;
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', i === active ? 'true' : 'false');
        li.appendChild(el('span', 'nu-cmdk-label', it.label));
        li.appendChild(el('span', 'nu-cmdk-hint', it.hint));
        if (it.kbd) li.appendChild(el('kbd', '', it.kbd));
        li.addEventListener('mousemove', function () { if (active !== i) { active = i; mark(); } });
        li.addEventListener('click', function () { run(it); });
        list.appendChild(li);
      });
      input.setAttribute('aria-activedescendant', 'nuCmdkItem' + active);
    }
    function mark() {
      Array.prototype.forEach.call(list.children, function (li, i) {
        var on = i === active;
        li.classList.toggle('is-active', on);
        li.setAttribute('aria-selected', on ? 'true' : 'false');
        if (on) { li.scrollIntoView({ block: 'nearest' }); input.setAttribute('aria-activedescendant', li.id); }
      });
    }
    function onKey(e) {
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); }
      else if (e.key === 'ArrowDown') { e.preventDefault(); if (results.length) { active = (active + 1) % results.length; mark(); } }
      else if (e.key === 'ArrowUp') { e.preventDefault(); if (results.length) { active = (active - 1 + results.length) % results.length; mark(); } }
      else if (e.key === 'Enter') { e.preventDefault(); run(results[active]); }
      else if (e.key === 'Tab') { e.preventDefault(); input.focus(); }
    }
    document.addEventListener('keydown', onKey, true);
    overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) close(); });
    input.addEventListener('input', function () { active = 0; render(); });

    palette = { input: input, close: close };
    render();
    input.focus();
  }

  // ── Wiring ────────────────────────────────────────────────────────────────
  function isTyping(t) {
    if (!t) return false;
    var tag = (t.tagName || '').toLowerCase();
    return tag === 'input' || tag === 'textarea' || tag === 'select' || t.isContentEditable ||
      !!(t.closest && t.closest('.ace_editor'));
  }

  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      palette ? palette.close() : openPalette();
      return;
    }
    if (palette || e.ctrlKey || e.metaKey || e.altKey || isTyping(e.target)) return;
    if (e.key === '/') { e.preventDefault(); openPalette(); }
    else if (e.key === '[') { toggleSidebar(); }
  });

  document.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('[data-nu-cmdk]')) { e.preventDefault(); openPalette(); }
  });

  // Show the platform-appropriate shortcut on the header trigger.
  document.querySelectorAll('[data-nu-cmdk] kbd').forEach(function (k) { k.textContent = isMac ? '⌘K' : 'Ctrl K'; });

  if (window.NuApp) window.NuApp.toast = toast;
  else window.addEventListener('DOMContentLoaded', function () { if (window.NuApp) window.NuApp.toast = toast; });

  window.NuShell = { openPalette: openPalette, toast: toast, toggleSidebar: toggleSidebar };
})(window);
