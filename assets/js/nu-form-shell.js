/**
 * Nuvis StudioX — Form Shell  (nu-form-shell.js)
 *
 * One chrome for every way a generated form is shown (modal, inline, full page):
 * add / edit / view-only / preview. Replaces three hand-rolled, inline-styled
 * modal builders in nubuilder-next.js.
 *
 *   NuFormShell.openModal(app, opts)  → { overlay, box, formEl, close }
 *   NuFormShell.attach(formEl, opts)  → decorate a rendered form (any container)
 *   NuFormShell.guard(formEl, proceed)→ run proceed() unless the user keeps unsaved edits
 *   NuFormShell.markClean(formEl)     → call after a successful save
 *
 * Behaviour added by attach():
 *   • mode badge (New / Editing #id / View only / Preview) + "Unsaved changes" indicator
 *   • dirty tracking with a confirm before Cancel / ✕ / Esc / backdrop / navigation away
 *   • Ctrl/Cmd+S saves, Esc closes (guarded), first editable field gets focus
 *   • preview: device switcher (desktop / tablet / mobile) and a "nothing is saved
 *     until you press Save" banner
 *   • view-only: inputs locked, Save hidden, Cancel → Close, Edit shortcut if allowed
 * Styles: assets/css/nu-form-shell.css (classes namespaced `.nu-fs*`).
 */
(function (window) {
  'use strict';
  var document = window.document;

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }

  var ICONS = {
    x:       '<path d="M18 6 6 18M6 6l12 12"/>',
    desktop: '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
    tablet:  '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M12 18h.01"/>',
    mobile:  '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M12 18h.01"/>',
    lock:    '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
    eye:     '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/>',
    edit:    '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
    plus:    '<path d="M12 5v14M5 12h14"/>'
  };
  function icon(name) {
    var s = el('span', 'nu-fs-ico');
    s.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (ICONS[name] || '') + '</svg>';
    return s;
  }

  // ── Dirty tracking ─────────────────────────────────────────────────────────
  function serialize(form) {
    var out = [];
    Array.prototype.forEach.call(form.elements, function (f) {
      if (!f.name || f.disabled || f.type === 'file' || f.type === 'button' || f.type === 'submit') return;
      if ((f.type === 'checkbox' || f.type === 'radio') && !f.checked) return;
      if (f.tagName === 'SELECT' && f.multiple) {
        Array.prototype.forEach.call(f.selectedOptions, function (o) { out.push(f.name + '=' + o.value); });
      } else {
        out.push(f.name + '=' + f.value);
      }
    });
    return out.join('\u0001');
  }

  function state(form) { return form && form.__nuFs; }
  function isDirty(form) { var s = state(form); return !!(s && s.dirty); }

  function setDirty(form, dirty) {
    var s = state(form);
    if (!s || s.dirty === dirty) return;
    s.dirty = dirty;
    form.classList.toggle('is-dirty', dirty);
    if (s.dirtyEl) s.dirtyEl.hidden = !dirty;
    if (s.footNote) s.footNote.textContent = dirty ? 'You have unsaved changes' : '';
    if (s.root) s.root.classList.toggle('is-dirty', dirty);
  }

  function markClean(form) {
    var s = state(form);
    if (!s) return;
    s.snapshot = serialize(form);
    setDirty(form, false);
  }

  function confirmDiscard() {
    var msg = 'You have unsaved changes. Discard them?';
    var opts = { title: 'Discard changes?', confirmLabel: 'Discard', cancelLabel: 'Keep editing', danger: true };
    if (window.NuBrowseTable && typeof window.NuBrowseTable.confirm === 'function') {
      return window.NuBrowseTable.confirm(msg, opts);
    }
    return Promise.resolve(window.confirm(msg));
  }

  /** Run proceed() now, or after the user agrees to discard unsaved edits. */
  function guard(form, proceed) {
    if (!isDirty(form)) { proceed(); return Promise.resolve(true); }
    return confirmDiscard().then(function (ok) { if (ok) { setDirty(form, false); proceed(); } return ok; });
  }

  // One beforeunload handler for all open forms.
  var openForms = [];
  window.addEventListener('beforeunload', function (e) {
    if (openForms.some(function (f) { return f.isConnected && isDirty(f); })) {
      e.preventDefault(); e.returnValue = '';
    }
  });

  // ── attach(): decorate a rendered form ─────────────────────────────────────
  /**
   * opts: { mode:'new'|'edit'|'view'|'preview', id, root, close(), onEdit() }
   * `root` is the element that scopes keyboard shortcuts (modal box or content area).
   */
  function attach(form, opts) {
    if (!form || form.__nuFs) return form && form.__nuFs;
    opts = opts || {};
    var mode = opts.mode || (form.dataset.recordId ? 'edit' : 'new');
    var s = form.__nuFs = { mode: mode, dirty: false, snapshot: '', root: opts.root || form.parentNode, close: opts.close };
    form.classList.add('nu-fs-form');
    form.noValidate = true;   // our own required-field validation (inline messages) runs instead of the browser bubble
    form.dataset.fsMode = mode;
    openForms.push(form);

    // Footer: replace the inline-styled bar with a sticky, classed one.
    var footer = null;
    Array.prototype.forEach.call(form.children, function (c) {
      if (c.querySelector && c.querySelector('button[type="submit"]')) footer = c;
    });
    if (footer) {
      footer.removeAttribute('style');
      footer.classList.add('nu-fs-actions');
      s.footNote = el('span', 'nu-fs-foot-note');
      s.footNote.setAttribute('aria-live', 'polite');
      footer.insertBefore(s.footNote, footer.firstChild);
      s.saveBtn = footer.querySelector('button[type="submit"]');
      s.cancelBtn = footer.querySelector('button.nu-btn-ghost');
      if (s.saveBtn) {
        s.saveBtn.dataset.label = s.saveBtn.textContent;
        var hint = el('kbd', 'nu-fs-kbd', /Mac|iPhone|iPad/i.test(navigator.platform || '') ? '⌘S' : 'Ctrl S');
        s.saveBtn.appendChild(hint);
      }
    }

    if (mode === 'view') lockForm(form, s, opts);
    if (mode === 'preview') s.preview = true;

    // Dirty tracking (skip for view-only).
    if (mode !== 'view') {
      var check = function () { setDirty(form, serialize(form) !== s.snapshot); };
      form.addEventListener('input', check);
      form.addEventListener('change', check);
      // Snapshot after widgets (select2, lookups, custom onLoad) had a chance to set values.
      setTimeout(function () { s.snapshot = serialize(form); }, 250);
    }

    // Shortcuts, scoped to this form's root.
    var onKey = function (e) {
      if (!form.isConnected) { document.removeEventListener('keydown', onKey, true); return; }
      var top = openForms.filter(function (f) { return f.isConnected; }).pop();
      if (top !== form) return;                      // only the top-most form reacts
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault();
        if (mode !== 'view' && s.saveBtn && !s.saveBtn.disabled) form.requestSubmit ? form.requestSubmit() : s.saveBtn.click();
      } else if (e.key === 'Escape' && s.close && opts.escClose !== false && !document.querySelector('dialog[open], .nu-cmdk-overlay, .select2-container--open')) {
        e.preventDefault();
        guard(form, s.close);
      }
    };
    document.addEventListener('keydown', onKey, true);

    if (mode !== 'view') {
      setTimeout(function () {
        var first = form.querySelector('.nu-input:not([disabled]):not([readonly]):not([type="hidden"]), input:not([type="hidden"]):not([disabled]):not([readonly]), select:not([disabled]), textarea:not([disabled])');
        if (first && first.offsetParent !== null && !window.matchMedia('(max-width: 768px)').matches) {
          try { first.focus({ preventScroll: true }); } catch (e) { first.focus(); }
        }
      }, 60);
    }
    return s;
  }

  function lockForm(form, s, opts) {
    form.dataset.viewOnly = '1';
    form.querySelectorAll('input, textarea, select').forEach(function (f) {
      f.setAttribute('disabled', 'disabled');
    });
    if (s.saveBtn) s.saveBtn.hidden = true;
    if (s.cancelBtn) s.cancelBtn.textContent = 'Close';
    if (typeof opts.onEdit === 'function' && s.cancelBtn) {
      var edit = el('button', 'nu-btn nu-btn-primary');
      edit.type = 'button';
      edit.appendChild(icon('edit'));
      edit.appendChild(document.createTextNode(' Edit'));
      edit.addEventListener('click', opts.onEdit);
      s.cancelBtn.parentNode.appendChild(edit);
    }
  }

  // ── Header pieces ──────────────────────────────────────────────────────────
  var MODE_META = {
    'new':     { label: 'New record', cls: 'is-new',     ico: 'plus' },
    'edit':    { label: 'Editing',    cls: 'is-edit',    ico: 'edit' },
    'view':    { label: 'View only',  cls: 'is-view',    ico: 'lock' },
    'preview': { label: 'Preview',    cls: 'is-preview', ico: 'eye' }
  };

  function modeBadge(mode, id) {
    var m = MODE_META[mode] || MODE_META.edit;
    var b = el('span', 'nu-fs-badge ' + m.cls);
    b.appendChild(icon(m.ico));
    b.appendChild(document.createTextNode(m.label + (mode === 'edit' && id ? ' #' + id : '')));
    return b;
  }

  function dirtyPill() {
    var d = el('span', 'nu-fs-dirty', 'Unsaved');
    d.hidden = true;
    d.title = 'This form has unsaved changes';
    return d;
  }

  /** Preview banner with the device switcher; `viewport` is the element that gets data-device. */
  function previewBar(viewport) {
    var bar = el('div', 'nu-fs-previewbar');
    bar.appendChild(el('span', 'nu-fs-previewbar-text', 'Preview — nothing is saved until you press Save.'));
    var seg = el('div', 'nu-fs-seg');
    seg.setAttribute('role', 'group');
    seg.setAttribute('aria-label', 'Preview device');
    [['desktop', 'Desktop'], ['tablet', 'Tablet'], ['mobile', 'Mobile']].forEach(function (d) {
      var b = el('button', 'nu-fs-seg-btn');
      b.type = 'button'; b.dataset.device = d[0]; b.title = d[1]; b.setAttribute('aria-label', d[1]);
      b.appendChild(icon(d[0]));
      b.addEventListener('click', function () { setDevice(d[0]); });
      seg.appendChild(b);
    });
    function setDevice(dev) {
      viewport.dataset.device = dev;
      Array.prototype.forEach.call(seg.children, function (c) {
        c.classList.toggle('is-active', c.dataset.device === dev);
        c.setAttribute('aria-pressed', c.dataset.device === dev ? 'true' : 'false');
      });
    }
    bar.appendChild(seg);
    setDevice('desktop');
    return bar;
  }

  // ── openModal ──────────────────────────────────────────────────────────────
  var SIZES = { compact: '560px', standard: '900px', full: '98vw' };

  /**
   * opts: { mode, id, html, code, crumbs:[{label,action}], onEdit }
   * `crumbs` actions receive nothing; the modal is closed for you first.
   */
  function openModal(app, opts) {
    var mode = opts.mode || 'edit';
    var overlay = el('div', 'nu-form-overlay nu-fs-overlay');
    var box = el('div', 'nu-fs-dialog');
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-label', (mode === 'preview' ? 'Preview: ' : '') + (opts.title || opts.code || 'Form'));
    var size = (mode === 'preview' && app._previewModalSize) || 'standard';
    box.style.setProperty('--nu-fs-w', SIZES[size]);
    box.dataset.size = size;

    var returnFocus = document.activeElement;
    var formEl = null;
    var closed = false;
    function close() {
      if (closed) return;
      closed = true;
      var i = openForms.indexOf(formEl); if (i > -1) openForms.splice(i, 1);
      overlay.classList.add('is-leaving');
      setTimeout(function () { overlay.remove(); }, 120);
      if (returnFocus && returnFocus.focus && document.contains(returnFocus)) returnFocus.focus();
    }
    function requestClose() { return formEl ? guard(formEl, close) : (close(), Promise.resolve(true)); }

    // Header
    var head = el('div', 'nu-fs-head');
    var left = el('div', 'nu-fs-head-main');
    if (Array.isArray(opts.crumbs) && opts.crumbs.length) {
      var bc = app._renderBreadcrumb(opts.crumbs.map(function (c) {
        return c.action ? { label: c.label, action: function () { guard(formEl, function () { close(); c.action(); }); } } : { label: c.label };
      }));
      bc.style.margin = '0';
      left.appendChild(bc);
    }
    var meta = el('div', 'nu-fs-head-meta');
    meta.appendChild(modeBadge(mode, opts.id));
    var dirty = dirtyPill();
    meta.appendChild(dirty);
    left.appendChild(meta);
    head.appendChild(left);

    var ctl = el('div', 'nu-fs-head-ctl');
    if (mode === 'preview') {
      var sizeSeg = el('div', 'nu-fs-seg');
      [['compact', 'S'], ['standard', 'M'], ['full', 'L']].forEach(function (z) {
        var b = el('button', 'nu-fs-seg-btn nu-fs-seg-txt', z[1]);
        b.type = 'button'; b.title = z[0].charAt(0).toUpperCase() + z[0].slice(1) + ' dialog';
        b.classList.toggle('is-active', z[0] === size);
        b.addEventListener('click', function () {
          app._previewModalSize = z[0];
          box.style.setProperty('--nu-fs-w', SIZES[z[0]]);
          box.dataset.size = z[0];
          Array.prototype.forEach.call(sizeSeg.children, function (c) { c.classList.toggle('is-active', c === b); });
        });
        sizeSeg.appendChild(b);
      });
      ctl.appendChild(sizeSeg);
    }
    var x = el('button', 'nu-fs-close');
    x.type = 'button'; x.title = 'Close (Esc)'; x.setAttribute('aria-label', 'Close');
    x.appendChild(icon('x'));
    x.addEventListener('click', requestClose);
    ctl.appendChild(x);
    head.appendChild(ctl);
    box.appendChild(head);

    // Body
    var body = el('div', 'nu-fs-body');
    var viewport = el('div', 'nu-fs-viewport');
    viewport.dataset.device = 'desktop';
    if (mode === 'preview') body.appendChild(previewBar(viewport));
    var wrap = el('div', 'nu-fs-formwrap');
    wrap.innerHTML = opts.html;
    viewport.appendChild(wrap);
    body.appendChild(viewport);
    box.appendChild(body);

    overlay.appendChild(box);
    document.body.appendChild(overlay);
    overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) requestClose(); });

    formEl = box.querySelector('.nu-generated-form');
    if (formEl) {
      formEl.dataset.displayMode = 'modal';
      if (mode === 'view') formEl.dataset.viewOnly = '1';
    }

    app._initFormWidgets(box);
    app._execModuleScripts(box);
    app._dispatchFormOpened(box);
    if (window.nuForm && typeof window.nuForm.init === 'function' && formEl && mode !== 'view') {
      window.nuForm.init(formEl.dataset.formCode || opts.code, {}, mode === 'preview');
    }
    if (formEl) {
      var st = attach(formEl, { mode: mode, id: opts.id, root: box, close: close, onEdit: opts.onEdit && function () { close(); opts.onEdit(); } });
      if (st) st.dirtyEl = dirty;
    }
    return { overlay: overlay, box: box, formEl: formEl, close: close, requestClose: requestClose };
  }

  // ── Inline / full page: header strip above an already-inserted form ────────
  /** Adds the mode badge + dirty pill + (preview) device bar to a page-mode form. */
  function decoratePage(container, opts) {
    var form = container.querySelector('.nu-generated-form');
    if (!form) return null;
    var mode = opts.mode;
    var strip = el('div', 'nu-fs-pagebar');
    strip.appendChild(modeBadge(mode, opts.id));
    var dirty = dirtyPill();
    strip.appendChild(dirty);

    var holder = form.parentNode;                 // the wrapper div holding the form HTML
    holder.classList.add('nu-fs-viewport', 'nu-fs-pagecard');
    holder.dataset.device = 'desktop';
    holder.parentNode.insertBefore(strip, holder);
    if (mode === 'preview') holder.parentNode.insertBefore(previewBar(holder), holder);

    var st = attach(form, { mode: mode, id: opts.id, root: container, close: opts.close, onEdit: opts.onEdit, escClose: false });
    if (st) st.dirtyEl = dirty;
    return st;
  }

  window.NuFormShell = {
    openModal: openModal,
    decoratePage: decoratePage,
    attach: attach,
    guard: guard,
    isDirty: isDirty,
    markClean: markClean
  };
})(window);
