// ─── Select2 helpers ─────────────────────────────────────────────────────────
(function () {
  'use strict';

  function _s2BuildFresh(el) {
    var fresh = document.createElement('select');
    var skip = { 'data-select2-id': 1, 'data-nu-s2': 1 };
    for (var i = 0; i < el.attributes.length; i++) {
      var attr = el.attributes[i];
      if (!skip[attr.name]) fresh.setAttribute(attr.name, attr.value);
    }
    for (var j = 0; j < el.childNodes.length; j++) {
      fresh.appendChild(el.childNodes[j].cloneNode(true));
    }
    var next = el.nextElementSibling;
    while (next && next.classList &&
           (next.classList.contains('select2') || next.classList.contains('select2-container'))) {
      var rem = next; next = next.nextElementSibling; rem.parentNode.removeChild(rem);
    }
    el.parentNode.replaceChild(fresh, el);
    return fresh;
  }

  function _s2InitOne(el) {
    if (typeof jQuery === 'undefined' || typeof jQuery.fn.select2 === 'undefined') return;
    var $ = jQuery;
    var fresh = _s2BuildFresh(el);
    var parentOverlay = fresh.closest('.nu-form-overlay, [data-sf-overlay]');
    var opts = {
      width: '100%',
      theme: (window.nuUXOptions && window.nuUXOptions.nuSelect2Theme) || 'default',
      placeholder: fresh.dataset.placeholder || 'Select\u2026',
      allowClear:  fresh.dataset.allowClear !== 'false',
      multiple:    fresh.dataset.selectMode === 'multiple' || fresh.hasAttribute('multiple'),
      dropdownParent: parentOverlay ? $(parentOverlay) : $(document.body),
    };
    try {
      $(fresh).select2(opts);
      fresh.setAttribute('data-nu-s2', '1');
    } catch (err) {
      console.error('[nu-select2] init FAILED', err.message, fresh);
    }
  }

  function nuInitSelect2(scope) {
    if (typeof jQuery === 'undefined' || typeof jQuery.fn.select2 === 'undefined') return;
    var root = (scope instanceof Element) ? scope : document;
    var targets = Array.prototype.slice.call(
      root.querySelectorAll('select[data-select-type="select2"], select.nu-select2')
    );
    targets.forEach(function (el) { _s2InitOne(el); });
  }

  window.nuInitSelect2    = nuInitSelect2;
  window.nuDestroySelect2 = function (el) { _s2BuildFresh(el); };
  window.nuReinitSelect2  = function (el) { _s2InitOne(el); };

  function nuSafeEval(expr) {
    var normalised = expr
      .replace(/×/g, '*')
      .replace(/x/g, '*')
      .replace(/X/g, '*')
      .replace(/÷/g, '/');
    var sanitized = normalised.replace(/[^0-9\s+\-*/.()]/g, '');
    try {
      return Function("return (" + sanitized + ")")();
    } catch (e) {
      return 0;
    }
  }

  function nuCalculateFields(scope) {
    scope = scope || document;
    var calcs = scope.querySelectorAll('input[data-calculated="true"]');
    calcs.forEach(function (calcEl) {
      var expr = calcEl.getAttribute('data-expression') || '';
      if (!expr) return;

      var localScope = calcEl.closest('.nu-sf-inline-row') || calcEl.closest('[data-sf-overlay]') || calcEl.closest('.nu-form-overlay') || scope;

      var resolvedExpr = expr;

      // 0. Resolve SUM(subform_code.field_name) or SUM(field_name) functions
      resolvedExpr = resolvedExpr.replace(/SUM\((?:([a-zA-Z0-9_]+)\.)?([a-zA-Z0-9_]+)\)/gi, function (match, subformCode, fieldName) {
        var subformContainer = null;
        if (subformCode) {
          subformContainer = document.querySelector('.nu-subform-container[data-subform-code="' + subformCode + '"]');
        } else {
          // Find the first subform container that has this field in its fields list
          var containers = document.querySelectorAll('.nu-subform-container');
          for (var i = 0; i < containers.length; i++) {
            if (containers[i]._sfAllFields && containers[i]._sfAllFields.some(function (f) { return (f.name || f.fieldname || '') === fieldName; })) {
              subformContainer = containers[i];
              break;
            }
          }
          if (!subformContainer) {
            subformContainer = document.querySelector('.nu-subform-container');
          }
        }

        if (subformContainer && subformContainer._sfAllRecords) {
          var sum = 0;
          subformContainer._sfAllRecords.forEach(function (r) {
            var val = null;
            var rowId = r[subformContainer._sfPk || 'id'];
            var inlineRow = subformContainer.querySelector('.nu-sf-inline-row[data-sf-row-id="' + CSS.escape(rowId) + '"]');
            if (inlineRow) {
              var inp = inlineRow.querySelector('[name="' + CSS.escape(fieldName) + '"], [data-field="' + CSS.escape(fieldName) + '"]');
              if (inp) {
                val = inp.value;
              }
            }
            if (val === null || val === undefined || val === '') {
              val = r[fieldName];
            }
            var num = parseFloat(val);
            if (!isNaN(num)) {
              sum += num;
            }
          });
          return String(sum);
        }
        return '0';
      });

      // 1. Resolve SQL-style hashes (e.g. ##tax_rate##) from window.nuUserMeta
      resolvedExpr = resolvedExpr.replace(/##([a-zA-Z0-9_]+)##/g, function (match, hashName) {
        if (window.nuUserMeta && window.nuUserMeta[hashName] !== undefined) {
          var val = window.nuUserMeta[hashName];
          var num = parseFloat(val);
          return isNaN(num) ? '0' : String(num);
        }
        return '0';
      });

      // 2. Resolve field-style tokens (e.g. {tax_rate}) from form fields or fallback to window.nuUserMeta
      resolvedExpr = resolvedExpr.replace(/\{([a-zA-Z0-9_]+)\}/g, function (match, fieldName) {
        var inputEl = localScope.querySelector('[name="' + CSS.escape(fieldName) + '"], [data-field="' + CSS.escape(fieldName) + '"]');
        if (inputEl) {
          if (inputEl.type === 'checkbox') {
            return inputEl.checked ? '1' : '0';
          }
          var val = inputEl.value;
          var num = parseFloat(val);
          return isNaN(num) ? '0' : String(num);
        }
        // Fallback to global hashes/user meta if field is not present on the form
        if (window.nuUserMeta && window.nuUserMeta[fieldName] !== undefined) {
          var val = window.nuUserMeta[fieldName];
          var num = parseFloat(val);
          return isNaN(num) ? '0' : String(num);
        }
        return '0';
      });

      var result = nuSafeEval(resolvedExpr);
      if (typeof result === 'number' && !isNaN(result) && isFinite(result)) {
        var rounded = Math.round(result * 10000) / 10000;
        calcEl.value = rounded;
      } else {
        calcEl.value = '';
      }
    });
  }

  window.nuSafeEval = nuSafeEval;
  window.nuCalculateFields = nuCalculateFields;

  // Global listeners to calculate values on input / change
  document.addEventListener('input', function (e) {
    var target = e.target;
    if (target && target.matches('input, select, textarea')) {
      var scope = target.closest('[data-sf-overlay]') || target.closest('.nu-sf-inline-row') || target.closest('.nu-form-overlay') || document;
      nuCalculateFields(scope);
      if (scope !== document) {
        nuCalculateFields(document);
      }
    }
  });
  document.addEventListener('change', function (e) {
    var target = e.target;
    if (target && target.matches('input, select, textarea')) {
      var scope = target.closest('[data-sf-overlay]') || target.closest('.nu-sf-inline-row') || target.closest('.nu-form-overlay') || document;
      nuCalculateFields(scope);
      if (scope !== document) {
        nuCalculateFields(document);
      }
    }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { nuInitSelect2(document); nuCalculateFields(document); });
  } else {
    nuInitSelect2(document);
    nuCalculateFields(document);
  }

  document.addEventListener('nu:form:opened', function (e) {
    var scope = e.detail && e.detail.scope;
    if (scope && scope.dataset && scope.dataset.nuS2Done) return;
    nuInitSelect2(scope);
    nuCalculateFields(scope || document);
  });

}());


// ─── Permission helpers ───────────────────────────────────────────────────────
window.NuPerms = {
  _editRoles: new Set(['globeadmin', 'admin']),

  canEdit() {
    const role = (window.nuUserRole || '').toLowerCase();
    if (this._editRoles.has(role)) return true;
    return !!(window.nuUserPerms && window.nuUserPerms.canEdit);
  },

  canAdd() {
    const role = (window.nuUserRole || '').toLowerCase();
    if (this._editRoles.has(role)) return true;
    return !!(window.nuUserPerms && window.nuUserPerms.canAdd);
  },

  canDelete() {
    const role = (window.nuUserRole || '').toLowerCase();
    if (this._editRoles.has(role)) return true;
    return !!(window.nuUserPerms && window.nuUserPerms.canDelete);
  }
};


// ─── NuApp ────────────────────────────────────────────────────────────────────
window.NuApp = {
  currentModule: 'dashboard',
  _previewModalSize: 'standard',

  _systemModules: new Set([
    'dashboard','forms','reports','queries','calendar','ai','integrations',
    'menus','users','roles','audit','files','workflow','inspector','errorlog',
    'password_policy','appcloner','password','report_dashboards','email_settings',
    'updater','import_export','developer_settings','word_certificates','api_manager',
    'barcode',
    'procedures',
    'projects',
    'system_demo_files'
  ]),

  init() {
    this.bindEvents();
    this.loadTheme();
    this.initNavGroups();
  },

  initNavGroups() {
    document.querySelectorAll('.nu-nav-group').forEach((group) => {
      const btn = group.querySelector('.nu-nav-group-label');
      const ul  = group.querySelector('.nu-nav-children');
      if (!btn || !ul) return;
      ul.classList.add('nu-nav-children--collapsed');
      btn.setAttribute('aria-expanded', 'false');
    });
  },

  bindEvents() {
    const themeToggle = document.getElementById('themeToggle');
    if (themeToggle) themeToggle.addEventListener('click', () => this.toggleTheme());
    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) logoutBtn.addEventListener('click', () => this.logout());

    const nav = document.querySelector('.nu-nav');
    if (nav) {
      nav.addEventListener('click', (e) => {
        const btn = e.target.closest('.nu-nav-group-label');
        if (!btn) return;
        const group = btn.closest('.nu-nav-group');
        if (!group) return;
        const ul = group.querySelector('.nu-nav-children');
        if (!ul) return;
        const isOpen = btn.getAttribute('aria-expanded') !== 'false';
        btn.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
        ul.classList.toggle('nu-nav-children--collapsed', isOpen);
      });
    }

    document.addEventListener('click', (e) => {
      if (!e.target.closest('.nu-nav-form-item')) {
        document.querySelectorAll('.nu-nav-form-dropdown').forEach(d => d.classList.remove('open'));
      }
    });
  },

  async logout() {
    try { await fetch('api/auth.php?action=logout', { method: 'POST', credentials: 'same-origin' }); } catch (e) {}
    window.location.reload();
  },

  loadTheme() {
    let saved = 'auto';
    try { saved = localStorage.getItem('nu-theme') || 'auto'; } catch (e) {}
    document.documentElement.setAttribute('data-theme', saved);
  },

  toggleTheme() {
    const current = document.documentElement.getAttribute('data-theme');
    const next = current === 'light' ? 'dark' : current === 'dark' ? 'auto' : 'light';
    document.documentElement.setAttribute('data-theme', next);
    try { localStorage.setItem('nu-theme', next); } catch (e) {}
  },

  setActiveNavByModule(module) {
    document.querySelectorAll('.nu-nav-item').forEach((item) => item.classList.remove('active'));
    const active = document.querySelector('.nu-nav-item[data-module="' + module + '"]');
    if (active) {
      active.classList.add('active');
      const group = active.closest('.nu-nav-group');
      if (group) {
        const ul  = group.querySelector('.nu-nav-children');
        const btn = group.querySelector('.nu-nav-group-label');
        if (ul)  ul.classList.remove('nu-nav-children--collapsed');
        if (btn) btn.setAttribute('aria-expanded', 'true');
      }
    }
  },

  toast(message, type) {
    let container = document.querySelector('.nu-toast-container');
    if (!container) {
      container = document.createElement('div');
      container.className = 'nu-toast-container';
      document.body.appendChild(container);
    }
    const toast = document.createElement('div');
    toast.className = 'nu-toast ' + (type || 'success');
    toast.textContent = message;
    container.appendChild(toast);
    setTimeout(() => { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 4000);
  },

  callPHP(code, params, callback) {
    return fetch('api/procedure.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ code: code, params: params || {} }),
      credentials: 'same-origin'
    })
    .then(res => res.json())
    .then(data => {
      if (typeof callback === 'function') {
        callback(data);
      }
      return data;
    })
    .catch(err => {
      console.error('callPHP Error:', err);
      const res = { success: false, error: err.message || String(err) };
      if (typeof callback === 'function') {
        callback(res);
      }
      return res;
    });
  },

  runProcedure(code, params, callback) {
    return this.callPHP(code, params, callback);
  },

  _execModuleScripts(container) {
    container.querySelectorAll('script').forEach(function (oldScript) {
      try {
        const s = document.createElement('script');
        Array.from(oldScript.attributes).forEach(function (attr) { s.setAttribute(attr.name, attr.value); });
        s.textContent = oldScript.textContent;
        if (oldScript.parentNode) {
          oldScript.parentNode.replaceChild(s, oldScript);
        }
      } catch (err) {
        console.error('Error executing module script:', err, oldScript);
      }
    });
  },

  _initFormWidgets(scope) {
    if (scope) scope.dataset.nuS2Done = '1';
    if (typeof window.nuInitSelect2 === 'function') window.nuInitSelect2(scope);
    if (scope && scope.querySelectorAll) {
      scope.querySelectorAll('[required]').forEach(el => {
        const id = el.id || el.getAttribute('name');
        if (id) {
          const label = scope.querySelector(`label[for="${id}"]`);
          if (label && !label.querySelector('.nu-required-asterisk')) {
            const star = document.createElement('span');
            star.className = 'nu-required-asterisk';
            star.style.cssText = 'color:#ef4444;font-weight:bold;margin-left:2px;';
            star.title = 'Required field';
            star.textContent = ' *';
            label.appendChild(star);
          }
        }
      });
    }
  },

  _dispatchFormOpened(box) {
    if (window.nuSubform && typeof window.nuSubform.initAll === 'function') {
      window.nuSubform.initAll(box);
    }
    document.dispatchEvent(new CustomEvent('nu:form:opened', { detail: { scope: box } }));
    if (window.nuInitAskAgent) {
      window.nuInitAskAgent(box);
    }
  },

  _renderBreadcrumb(crumbs) {
    const nav = document.createElement('nav');
    nav.className = 'nu-breadcrumb';
    nav.setAttribute('aria-label', 'breadcrumb');
    nav.style.cssText = 'margin-bottom:14px;display:flex;align-items:center;flex-wrap:wrap;gap:6px;font-size:13px;';
    crumbs.forEach((crumb, i) => {
      if (i > 0) {
        const sep = document.createElement('span');
        sep.className = 'nu-breadcrumb-separator';
        sep.textContent = '›';
        sep.style.cssText = 'color:var(--text-muted,#94a3b8);margin:0 2px;font-weight:bold;';
        nav.appendChild(sep);
      }
      if (crumb.action && i < crumbs.length - 1) {
        const a = document.createElement('a');
        a.className = 'nu-breadcrumb-item nu-breadcrumb-link';
        a.href = '#';
        a.textContent = crumb.label;
        a.style.cssText = 'color:var(--primary,#4f6bed);text-decoration:none;font-weight:500;transition:color 0.2s;';
        a.addEventListener('mouseenter', () => a.style.textDecoration = 'underline');
        a.addEventListener('mouseleave', () => a.style.textDecoration = 'none');
        a.addEventListener('click', (e) => { e.preventDefault(); crumb.action(); });
        nav.appendChild(a);
      } else {
        const span = document.createElement('span');
        span.className = 'nu-breadcrumb-item nu-breadcrumb-active';
        span.textContent = crumb.label;
        span.style.cssText = 'color:var(--text-muted,#64748b);font-weight:600;';
        nav.appendChild(span);
      }
    });
    return nav;
  },

  _enterFullPage() {
    const sidebar = document.querySelector('.nu-sidebar, #sidebar, [class*="sidebar"]');
    const header  = document.querySelector('.nu-header, #header, header');
    const main    = document.getElementById('contentArea');
    if (sidebar) { sidebar.dataset.nuFpHidden = sidebar.style.display; sidebar.style.display = 'none'; }
    if (header)  { header.dataset.nuFpHidden  = header.style.display;  header.style.display  = 'none'; }
    if (main) {
      main.dataset.nuFpStyle = main.getAttribute('style') || '';
      main.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;z-index:9000;overflow-y:auto;padding:24px;background:var(--bg-page,#f5f6fa);';
    }
    document.body.dataset.nuFullPage = '1';
  },

  _exitFullPage() {
    if (!document.body.dataset.nuFullPage) return;
    const sidebar = document.querySelector('.nu-sidebar, #sidebar, [class*="sidebar"]');
    const header  = document.querySelector('.nu-header, #header, header');
    const main    = document.getElementById('contentArea');
    if (sidebar && sidebar.dataset.nuFpHidden !== undefined) { sidebar.style.display = sidebar.dataset.nuFpHidden; delete sidebar.dataset.nuFpHidden; }
    if (header  && header.dataset.nuFpHidden  !== undefined) { header.style.display  = header.dataset.nuFpHidden;  delete header.dataset.nuFpHidden;  }
    if (main    && main.dataset.nuFpStyle     !== undefined) { main.setAttribute('style', main.dataset.nuFpStyle);  delete main.dataset.nuFpStyle;     }
    delete document.body.dataset.nuFullPage;
  },

  async loadModule(module, defaultView, browseMode, previewMode) {
    module      = (module      || 'dashboard').trim();
    defaultView = ['browse','preview'].includes(defaultView) ? defaultView : 'browse';
    browseMode  = ['inline','popup'].includes(browseMode)    ? browseMode  : 'inline';
    previewMode = ['inline','popup'].includes(previewMode)   ? previewMode : 'inline';

    if (window.innerWidth <= 768) {
      const sidebarEl = document.getElementById('sidebar');
      const overlayEl = document.getElementById('overlay');
      if (sidebarEl) sidebarEl.classList.remove('open');
      if (overlayEl) overlayEl.classList.remove('open');
    }

    this._exitFullPage();
    this.currentModule = module;

    const container = document.getElementById('contentArea');
    const pageTitle  = document.getElementById('pageTitle');
    if (!container) { console.error('contentArea not found'); return; }
    if (pageTitle) pageTitle.textContent = module.charAt(0).toUpperCase() + module.slice(1);
    this.setActiveNavByModule(module);

    if (this._systemModules.has(module)) {
      container.innerHTML = '<div class="nu-spinner" style="margin:40px auto;"></div>';
      try {
        const res  = await fetch('modules/' + module + '/' + module + '.php?_t=' + Date.now(), { credentials: 'same-origin' });
        const html = await res.text();
        if (!res.ok) {
          container.innerHTML = '<div style="padding:24px;border:2px solid red;background:#fee;"><h3>Module load failed</h3><p>Status: ' + res.status + '</p><pre style="font-size:12px;overflow:auto;">' + html.substring(0, 2000) + '</pre></div>';
          return;
        }
        container.innerHTML = html;
        container.style.display    = 'block';
        container.style.visibility = 'visible';
        container.style.opacity    = '1';
        this._execModuleScripts(container);
        this.initModuleScripts(module);
      } catch (err) {
        console.error('loadModule error', err);
        container.innerHTML = '<div style="padding:24px;border:2px solid red;background:#fee;"><h3>Error</h3><p>' + String(err.message || err) + '</p></div>';
      }
      return;
    }

    if (defaultView === 'preview') {
      return previewMode === 'popup'
        ? this.previewForm(module, module, 'modal')
        : this._openFormInline(module, module, null, true);
    } else {
      return browseMode === 'popup'
        ? this._browseModal(module, 1, '', module)
        : this._browseInline(module, 1, '', module);
    }
  },

  initModuleScripts(module) {
    if (module === 'forms' && window.nbFormBuilder && typeof window.nbFormBuilder._initAfterLoad === 'function') {
      window.nbFormBuilder._initAfterLoad();
    }
  },

  async apiJson(url, options) {
    const res  = await fetch(url, options || {});
    const text = await res.text();
    console.log('api raw response:', url, text);
    let json = null;
    try { json = JSON.parse(text); } catch (e) { throw new Error('Invalid JSON response'); }
    return json;
  },

  async previewForm(code, formLabel, displayMode) {
    const mode = (displayMode || 'modal').toLowerCase();
    if (mode === 'inline')   return this._openFormInline(code, formLabel, null, true);
    if (mode === 'fullpage') return this._openFormFullPage(code, formLabel, null, true);

    try {
      const json = await this.apiJson('api/form.php?action=render&code=' + encodeURIComponent(code), { credentials: 'same-origin' });
      if (!json.success) { this.toast(json.error || 'Preview failed', 'error'); return; }

      const label = formLabel || code;
      const sizes = {
        compact:  { maxWidth: '560px',  maxHeight: '80vh' },
        standard: { maxWidth: '900px',  maxHeight: '90vh' },
        full:     { maxWidth: '98vw',   maxHeight: '96vh' },
      };
      let currentSize = this._previewModalSize || 'standard';

      const overlay = document.createElement('div');
      overlay.className = 'nu-form-overlay';
      overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;display:flex;align-items:center;justify-content:center;';

      const box = document.createElement('div');
      box.style.cssText = 'background:var(--card-bg,#fff);border-radius:12px;padding:24px;width:92%;overflow-y:auto;transition:max-width 0.2s,max-height 0.2s;max-width:' + sizes[currentSize].maxWidth + ';max-height:' + sizes[currentSize].maxHeight + ';';

      const applySize = (s) => {
        currentSize = s; this._previewModalSize = s;
        box.style.maxWidth  = sizes[s].maxWidth;
        box.style.maxHeight = sizes[s].maxHeight;
        box.querySelectorAll('.nu-size-btn').forEach(b => {
          b.style.fontWeight = b.dataset.size === s ? '700' : '400';
          b.style.background = b.dataset.size === s ? 'var(--primary,#4f6bed)' : 'transparent';
          b.style.color      = b.dataset.size === s ? '#fff' : 'var(--text,#333)';
        });
      };

      const header = document.createElement('div');
      header.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;gap:8px;';
      const titleWrap = document.createElement('div');
      titleWrap.style.cssText = 'display:flex;flex-direction:column;gap:4px;min-width:0;';
      const bc = this._renderBreadcrumb([
        { label: 'Forms', action: () => { overlay.remove(); this.loadModule('forms'); } },
        { label: label }
      ]);
      bc.style.marginBottom = '0';
      titleWrap.appendChild(bc);
      header.appendChild(titleWrap);

      const controls = document.createElement('div');
      controls.style.cssText = 'display:flex;align-items:center;gap:4px;flex-shrink:0;';
      ['compact','standard','full'].forEach(s => {
        const btn = document.createElement('button');
        btn.type = 'button'; btn.className = 'nu-size-btn'; btn.dataset.size = s;
        btn.textContent = s === 'compact' ? '\u25a3 Sm' : s === 'standard' ? '\u25a3 Md' : '\u26f6 Lg';
        btn.title = s.charAt(0).toUpperCase() + s.slice(1);
        btn.style.cssText = 'border:1px solid var(--border-color,#ddd);border-radius:6px;padding:3px 8px;font-size:11px;cursor:pointer;background:transparent;color:var(--text,#333);transition:all 0.15s;';
        btn.addEventListener('click', () => applySize(s));
        controls.appendChild(btn);
      });
      const closeBtn = document.createElement('button');
      closeBtn.type = 'button'; closeBtn.innerHTML = '&times;';
      closeBtn.style.cssText = 'background:none;border:none;font-size:22px;cursor:pointer;line-height:1;padding:0 4px;color:var(--text,#333);margin-left:4px;';
      closeBtn.addEventListener('click', () => overlay.remove());
      controls.appendChild(closeBtn);
      header.appendChild(controls);
      box.appendChild(header);

      const formWrap = document.createElement('div');
      formWrap.innerHTML = json.html;
      box.appendChild(formWrap);

      const formEl = box.querySelector('.nu-generated-form');
      if (formEl) formEl.dataset.displayMode = 'modal';

      overlay.appendChild(box);
      document.body.appendChild(overlay);
      applySize(currentSize);
      overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });

      this._initFormWidgets(box);
      this._execModuleScripts(box);
      this._dispatchFormOpened(box);
      if (window.nuForm && typeof window.nuForm.init === 'function') {
        if (formEl) window.nuForm.init(formEl.dataset.formCode || code, {}, true);
      }
    } catch (err) {
      console.error('previewForm error', err);
      this.toast('Preview error: ' + err.message, 'error');
    }
  },

  async editRecord(code, id, fromBrowseLabel, displayMode) {
    const mode        = (displayMode || 'modal').toLowerCase();
    const browseLabel = fromBrowseLabel || code;
    if (mode === 'inline')   return this._openFormInline(code, browseLabel, id, false);
    if (mode === 'fullpage') return this._openFormFullPage(code, browseLabel, id, false);

    try {
      const json = await this.apiJson('api/form.php?action=render&code=' + encodeURIComponent(code) + '&id=' + encodeURIComponent(id), { credentials: 'same-origin' });
      if (!json.success) { this.toast(json.error || 'Failed', 'error'); return; }

      const overlay = document.createElement('div');
      overlay.className = 'nu-form-overlay';
      overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;display:flex;align-items:center;justify-content:center;';

      const box = document.createElement('div');
      box.style.cssText = 'background:var(--card-bg,#fff);border-radius:12px;padding:24px;max-width:900px;max-height:90vh;overflow-y:auto;width:92%;';

      const header = document.createElement('div');
      header.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;';
      const bc = this._renderBreadcrumb([
        { label: 'Forms',     action: () => { overlay.remove(); this.loadModule('forms'); } },
        { label: browseLabel, action: () => { overlay.remove(); this.browseForm(code, 1, '', browseLabel, mode); } },
        { label: 'Edit #' + id }
      ]);
      bc.style.marginBottom = '0';
      header.appendChild(bc);
      const closeBtn = document.createElement('button');
      closeBtn.type = 'button'; closeBtn.innerHTML = '&times;';
      closeBtn.style.cssText = 'background:none;border:none;font-size:22px;cursor:pointer;line-height:1;padding:0 4px;color:var(--text,#333);';
      closeBtn.addEventListener('click', () => overlay.remove());
      header.appendChild(closeBtn);
      box.appendChild(header);

      const formWrap = document.createElement('div');
      formWrap.innerHTML = json.html;
      box.appendChild(formWrap);

      const formEl = box.querySelector('.nu-generated-form');
      if (formEl) formEl.dataset.displayMode = 'modal';

      overlay.appendChild(box);
      document.body.appendChild(overlay);
      overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });

      this._initFormWidgets(box);
      this._execModuleScripts(box);
      this._dispatchFormOpened(box);
      if (window.nuForm && typeof window.nuForm.init === 'function') {
        if (formEl) window.nuForm.init(formEl.dataset.formCode || code, {}, false);
      }
    } catch (err) {
      console.error('editRecord error', err);
      this.toast('Error: ' + err.message, 'error');
    }
  },

  async viewRecord(code, id, fromBrowseLabel, displayMode) {
    const mode        = (displayMode || 'modal').toLowerCase();
    const browseLabel = fromBrowseLabel || code;

    try {
      const json = await this.apiJson(
        'api/form.php?action=render&code=' + encodeURIComponent(code) + '&id=' + encodeURIComponent(id),
        { credentials: 'same-origin' }
      );
      if (!json.success) { this.toast(json.error || 'Failed', 'error'); return; }

      const overlay = document.createElement('div');
      overlay.className = 'nu-form-overlay';
      overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;display:flex;align-items:center;justify-content:center;';

      const box = document.createElement('div');
      box.style.cssText = 'background:var(--card-bg,#fff);border-radius:12px;padding:24px;max-width:900px;max-height:90vh;overflow-y:auto;width:92%;';

      const header = document.createElement('div');
      header.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;';
      const bc = this._renderBreadcrumb([
        { label: browseLabel, action: () => { overlay.remove(); this.browseForm(code, 1, '', browseLabel, mode); } },
        { label: 'View #' + id }
      ]);
      bc.style.marginBottom = '0';
      header.appendChild(bc);

      const badge = document.createElement('span');
      badge.textContent = '\uD83D\uDD12 View Only';
      badge.style.cssText = 'font-size:11px;padding:3px 8px;border-radius:10px;background:var(--bg-elevated,#f0f0f0);color:var(--text-muted,#888);margin-right:8px;';
      header.appendChild(badge);

      const closeBtn = document.createElement('button');
      closeBtn.type = 'button'; closeBtn.innerHTML = '&times;';
      closeBtn.style.cssText = 'background:none;border:none;font-size:22px;cursor:pointer;line-height:1;padding:0 4px;color:var(--text,#333);';
      closeBtn.addEventListener('click', () => overlay.remove());
      header.appendChild(closeBtn);
      box.appendChild(header);

      const formWrap = document.createElement('div');
      formWrap.innerHTML = json.html;
      box.appendChild(formWrap);

      box.querySelectorAll('input, textarea, select, button[type="submit"]').forEach(el => {
        if (el.type === 'submit') { el.style.display = 'none'; return; }
        el.setAttribute('disabled', 'disabled');
        el.style.opacity = '0.7';
        el.style.cursor  = 'default';
      });
      box.querySelectorAll('button.nu-btn-ghost').forEach(el => {
        if (el.textContent.trim() === 'Cancel') el.textContent = 'Close';
      });

      const formEl = box.querySelector('.nu-generated-form');
      if (formEl) {
        formEl.dataset.displayMode = 'modal';
        formEl.dataset.viewOnly    = '1';
      }

      overlay.appendChild(box);
      document.body.appendChild(overlay);
      overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });

      this._initFormWidgets(box);
      this._execModuleScripts(box);
      this._dispatchFormOpened(box);
    } catch (err) {
      console.error('viewRecord error', err);
      this.toast('Error: ' + err.message, 'error');
    }
  },

  addRecord(code, formLabel, displayMode) {
    return this._openFormInline(code, formLabel, null, false);
  },

  async browseForm(code, page, query, formLabel, displayMode, sort, dir) {
    const mode = (displayMode || 'inline').toLowerCase();
    if (mode === 'modal')    return this._browseModal(code, page, query, formLabel, sort, dir);
    if (mode === 'fullpage') return this._browseFullPage(code, page, query, formLabel, sort, dir);
    return this._browseInline(code, page, query, formLabel, sort, dir);
  },

  async _fetchBrowseData(code, page, query, sort, dir) {
    page  = page  || 1;
    query = query || '';
    let url = 'api/form.php?action=list&code=' + encodeURIComponent(code) +
      '&page=' + encodeURIComponent(page) + '&q=' + encodeURIComponent(query);
    if (sort) url += '&sort=' + encodeURIComponent(sort) + '&dir=' + encodeURIComponent(dir || 'ASC');
    const json = await this.apiJson(url, { credentials: 'same-origin' });
    if (!json.success) throw new Error(json.error || 'Browse failed');

    // Pre-fetch certificate templates for this form asynchronously
    try {
      const certRes = await this.apiJson('api/word_certificates.php?action=list_templates&form_code=' + encodeURIComponent(code), { credentials: 'same-origin' });
      if (certRes && certRes.success) {
        json.certificate_templates = certRes.templates;
      }
    } catch (e) {
      console.error('Failed to pre-fetch certificate templates', e);
    }

    return json;
  },

  // ── Browse table builder ─────────────────────────────────────────────────
  // Implementation lives in assets/js/nu-browse-table.js (NuBrowseTable).
  // The signature is unchanged so existing callers keep working.
  _buildBrowseTable(json, code, page, query, label, displayMode, container, onEdit, canEdit, canAdd, currentSortField, currentSortDir) {
    if (!window.NuBrowseTable) {
      console.error('NuBrowseTable is not loaded (assets/js/nu-browse-table.js)');
      this.toast('Browse table failed to load', 'error');
      return null;
    }
    return window.NuBrowseTable.build(this, {
      json: json, code: code, page: page, query: query, label: label, displayMode: displayMode,
      container: container, onEdit: onEdit, canEdit: canEdit, canAdd: canAdd,
      sort: currentSortField, dir: currentSortDir
    });
  },

  /**
   * Start a browse request: invalidates any in-flight one (so a slow, stale
   * response can't overwrite a newer view) and shows a loading state — dimming
   * the existing table when there is one, a skeleton otherwise.
   */
  _browseBegin(container) {
    this._browseToken = (this._browseToken || 0) + 1;
    const prev = container.__nuBt;
    if (prev && prev.root && prev.root.isConnected) {
      prev.root.classList.add('is-loading');
      prev.root.setAttribute('aria-busy', 'true');
    } else if (window.NuBrowseTable) {
      container.innerHTML = '';
      window.NuBrowseTable.skeleton(container);
    }
    return this._browseToken;
  },

  async _browseInline(code, page, query, formLabel, sort, dir) {
    const container = document.getElementById('contentArea');
    if (!container) { this.toast('Content area not found', 'error'); return; }
    const token = this._browseBegin(container);
    try {
      const _canAdd  = NuPerms.canAdd();
      const _canEdit = NuPerms.canEdit();
      const json  = await this._fetchBrowseData(code, page, query, sort, dir);
      if (token !== this._browseToken) return;
      const label = formLabel || (json.data || {}).form_name || code;
      this._buildBrowseTable(json, code, page, query, label, 'inline', container, null, _canEdit, _canAdd, sort, dir);
    } catch (err) {
      if (token !== this._browseToken) return;
      console.error('_browseInline error', err);
      this.toast('Error: ' + err.message, 'error');
      if (window.NuBrowseTable) window.NuBrowseTable.errorState(container, err.message, () => this._browseInline(code, page, query, formLabel, sort, dir));
    }
  },

  async _browseModal(code, page, query, formLabel, sort, dir) {
    try {
      const _canAdd  = NuPerms.canAdd();
      const _canEdit = NuPerms.canEdit();
      const json  = await this._fetchBrowseData(code, page, query, sort, dir);
      const data  = json.data || {};
      const label = formLabel || data.form_name || code;
      let overlay = document.querySelector('.nu-browse-overlay');
      let isNew = false;
      if (!overlay) {
        isNew = true;
        overlay = document.createElement('div');
        overlay.className = 'nu-browse-overlay nu-form-overlay nu-bt-modal-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', label + ' records');
      }
      const box = document.createElement('div');
      box.className = 'nu-bt-modal';
      const header = document.createElement('div');
      header.className = 'nu-bt-modal-head';
      const bc = this._renderBreadcrumb([
        { label: 'Forms', action: () => { overlay.remove(); this.loadModule('forms'); } },
        { label: label,   action: () => this._browseModal(code, 1, '', label, sort, dir) },
        { label: 'Browse' }
      ]);
      bc.style.marginBottom = '0'; header.appendChild(bc);
      const closeBtn = document.createElement('button');
      closeBtn.type = 'button'; closeBtn.className = 'nu-bt-icon-btn';
      closeBtn.setAttribute('aria-label', 'Close'); closeBtn.title = 'Close (Esc)';
      closeBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';
      closeBtn.addEventListener('click', () => overlay.remove());
      header.appendChild(closeBtn);
      box.appendChild(header);
      const tableContainer = document.createElement('div');
      this._buildBrowseTable(json, code, page, query, label, 'modal', tableContainer, null, _canEdit, _canAdd, sort, dir);
      box.appendChild(tableContainer);
      overlay.innerHTML = ''; overlay.appendChild(box);
      if (isNew) {
        document.body.appendChild(overlay);
        overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });
        overlay.addEventListener('keydown', (e) => { if (e.key === 'Escape') overlay.remove(); });
      }
      // Move focus into the dialog so Esc / keyboard users land inside it (re-focused after each re-render).
      box.setAttribute('tabindex', '-1');
      box.focus({ preventScroll: true });
    } catch (err) { console.error('_browseModal error', err); this.toast('Error: ' + err.message, 'error'); }
  },

  async _browseFullPage(code, page, query, formLabel, sort, dir) {
    const container = document.getElementById('contentArea');
    if (!container) { this.toast('Content area not found', 'error'); return; }
    try {
      const _canAdd  = NuPerms.canAdd();
      const _canEdit = NuPerms.canEdit();
      const token = this._browseBegin(container);
      const json  = await this._fetchBrowseData(code, page, query, sort, dir);
      if (token !== this._browseToken) return;
      const data  = json.data || {};
      const label = formLabel || data.form_name || code;
      this._enterFullPage();
      container.innerHTML = '';

      // Full-page mode hides the app chrome, so it carries its own title + exit.
      const head = document.createElement('div');
      head.className = 'nu-bt-pagehead';
      const title = document.createElement('h2');
      title.className = 'nu-bt-pagetitle'; title.textContent = label;
      const actions = document.createElement('div');
      actions.className = 'nu-bt-pagehead-actions';
      const exitBtn = document.createElement('button');
      exitBtn.type = 'button'; exitBtn.className = 'nu-btn nu-btn-ghost nu-btn-sm';
      exitBtn.textContent = 'Exit full page';
      exitBtn.onclick = () => { this._exitFullPage(); this.loadModule('forms'); };
      actions.appendChild(exitBtn);
      head.append(title, actions);
      container.appendChild(head);

      const mount = document.createElement('div');
      container.appendChild(mount);
      this._buildBrowseTable(json, code, page, query, label, 'fullpage', mount, null, _canEdit, _canAdd, sort, dir);
    } catch (err) {
      this._exitFullPage();
      console.error('_browseFullPage error', err);
      this.toast('Error: ' + err.message, 'error');
      if (window.NuBrowseTable) window.NuBrowseTable.errorState(container, err.message, () => this._browseFullPage(code, page, query, formLabel, sort, dir));
    }
  },

  async _openFormInline(code, formLabel, id, isPreview) {
    try {
      const url = 'api/form.php?action=render&code=' + encodeURIComponent(code) + (id ? '&id=' + encodeURIComponent(id) : '');
      const json = await this.apiJson(url, { credentials: 'same-origin' });
      if (!json.success) { this.toast(json.error || 'Failed', 'error'); return; }
      const label     = formLabel || code;
      const container = document.getElementById('contentArea');
      if (!container) { this.toast('Content area not found', 'error'); return; }
      container.innerHTML = '';
      const crumbs = [
        { label: 'Forms', action: () => this.loadModule('forms') },
        { label: label,   action: () => this.browseForm(code, 1, '', label, 'inline') },
        { label: isPreview ? 'Preview' : (id ? 'Edit #' + id : 'New Record') }
      ];
      container.appendChild(this._renderBreadcrumb(crumbs));
      const formWrap = document.createElement('div');
      formWrap.innerHTML = json.html;
      container.appendChild(formWrap);
      const formEl = container.querySelector('.nu-generated-form');
      if (formEl) {
        formEl.dataset.displayMode = 'inline';
        formEl.dataset.fromBrowse  = label;
      }
      this._initFormWidgets(container);
      this._execModuleScripts(container);
      this._dispatchFormOpened(container);
      if (window.nuForm && typeof window.nuForm.init === 'function') {
        if (formEl) window.nuForm.init(formEl.dataset.formCode || code, {}, isPreview);
      }
    } catch (err) { console.error('_openFormInline error', err); this.toast('Error: ' + err.message, 'error'); }
  },

  _openSelectionLookupModal(col, row, callback) {
    const table = col.btn_table || 'nu_users';
    const idField = col.btn_id || 'usr_id';
    const dispField = col.btn_disp || 'usr_name';

    // Resolve ## hashes in where clause
    let filter = col.btn_where || '';
    if (window.nuUserMeta) {
      Object.keys(window.nuUserMeta).forEach(key => {
        filter = filter.replace(new RegExp('##' + key + '##', 'g'), window.nuUserMeta[key]);
      });
    }
    // Check global hashes fallback
    if (window.nuUserLocation) {
      filter = filter.replace(/##location##/g, window.nuUserLocation);
    }

    const url = 'api/crud.php?table=' + encodeURIComponent(table) + '&page=1&per_page=100' + (filter ? '&where=' + encodeURIComponent(filter) : '');

    this.apiJson(url, { credentials: 'same-origin' }).then(res => {
      if (!res || !res.success) {
        this.toast('Failed to load lookup items: ' + ((res && res.error) || 'unknown error'), 'error');
        return;
      }
      const records = Array.isArray(res.data) ? res.data : (res.records || []);

      // Create Lookup Modal DOM
      const overlay = document.createElement('div');
      overlay.className = 'nu-form-overlay nu-lookup-overlay';
      overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:200000;display:flex;align-items:center;justify-content:center;';

      const box = document.createElement('div');
      box.style.cssText = 'background:var(--card-bg,#fff);border-radius:12px;padding:24px;width:90%;max-width:500px;max-height:80vh;display:flex;flex-direction:column;gap:16px;box-shadow:0 8px 30px rgba(0,0,0,0.3);';

      const header = document.createElement('div');
      header.style.cssText = 'display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border-color);padding-bottom:12px;';
      const h3 = document.createElement('h3');
      h3.style.cssText = 'margin:0;font-size:16px;font-weight:600;';
      h3.textContent = 'Select ' + (col.fieldlabel || 'Item');
      const closeBtn = document.createElement('button');
      closeBtn.type = 'button'; closeBtn.innerHTML = '&times;';
      closeBtn.style.cssText = 'background:none;border:none;font-size:24px;cursor:pointer;line-height:1;color:var(--text-secondary);';
      closeBtn.onclick = () => overlay.remove();
      header.appendChild(h3); header.appendChild(closeBtn);
      box.appendChild(header);

      // Search Box
      const searchInput = document.createElement('input');
      searchInput.type = 'text'; searchInput.className = 'nu-input';
      searchInput.placeholder = 'Search items...';
      searchInput.style.fontSize = '13px'; searchInput.style.padding = '6px 10px';
      box.appendChild(searchInput);

      // Items List area
      const listArea = document.createElement('div');
      listArea.style.cssText = 'flex:1;overflow-y:auto;display:flex;flex-direction:column;gap:4px;border:1px solid var(--border-color);border-radius:6px;padding:6px;background:var(--bg-elevated);';

      const renderList = (filterText) => {
        listArea.innerHTML = '';
        const q = (filterText || '').toLowerCase().trim();
        const filtered = records.filter(r => {
          const disp = String(r[dispField] || '').toLowerCase();
          const idVal = String(r[idField] || '').toLowerCase();
          return disp.indexOf(q) !== -1 || idVal.indexOf(q) !== -1;
        });

        if (!filtered.length) {
          const empty = document.createElement('div');
          empty.style.cssText = 'padding:20px;text-align:center;color:var(--text-muted);font-size:12px;';
          empty.textContent = 'No matching items';
          listArea.appendChild(empty);
          return;
        }

        filtered.forEach(r => {
          const item = document.createElement('div');
          item.style.cssText = 'padding:8px 12px;font-size:13px;border-radius:4px;cursor:pointer;transition:all 0.15s;color:var(--text-primary);';
          item.textContent = r[dispField] || r[idField] || 'Item';
          item.addEventListener('mouseover', () => item.style.background = 'var(--bg-hover,#f5f7ff)');
          item.addEventListener('mouseout', () => item.style.background = 'none');
          item.onclick = () => {
            overlay.remove();
            callback({
              id: r[idField],
              display: r[dispField],
              ...r
            });
          };
          listArea.appendChild(item);
        });
      };

      searchInput.oninput = () => renderList(searchInput.value);
      renderList('');

      box.appendChild(listArea);
      overlay.appendChild(box);
      document.body.appendChild(overlay);

      // Auto focus search
      searchInput.focus();
    }).catch(err => {
      this.toast('Error opening lookup modal: ' + err.message, 'error');
    });
  },

  async _openFormFullPage(code, formLabel, id, isPreview) {
    try {
      const url = 'api/form.php?action=render&code=' + encodeURIComponent(code) + (id ? '&id=' + encodeURIComponent(id) : '');
      const json = await this.apiJson(url, { credentials: 'same-origin' });
      if (!json.success) { this.toast(json.error || 'Failed', 'error'); return; }
      const label = formLabel || code;
      this._enterFullPage();
      const container = document.getElementById('contentArea');
      if (!container) { this.toast('Content area not found', 'error'); return; }
      container.innerHTML = '';
      const exitAction = () => { this._exitFullPage(); this.loadModule('forms'); };
      container.appendChild(this._renderBreadcrumb([
        { label: 'Forms', action: exitAction },
        { label: label,   action: () => { this._exitFullPage(); this.browseForm(code, 1, '', label, 'fullpage'); } },
        { label: isPreview ? 'Preview' : (id ? 'Edit #' + id : 'New Record') }
      ]));
      const exitBtn = document.createElement('button');
      exitBtn.className = 'nu-btn nu-btn-ghost nu-btn-sm'; exitBtn.style.cssText = 'margin-bottom:12px;';
      exitBtn.textContent = '\u2715 Exit Full Page'; exitBtn.onclick = exitAction;
      container.appendChild(exitBtn);
      const formWrap = document.createElement('div');
      formWrap.innerHTML = json.html;
      container.appendChild(formWrap);
      const formEl = container.querySelector('.nu-generated-form');
      if (formEl) formEl.dataset.displayMode = 'fullpage';
      this._initFormWidgets(container);
      this._execModuleScripts(container);
      this._dispatchFormOpened(container);
      if (window.nuForm && typeof window.nuForm.init === 'function') {
        if (formEl) window.nuForm.init(formEl.dataset.formCode || code, {}, isPreview);
      }
    } catch (err) { this._exitFullPage(); console.error('_openFormFullPage error', err); this.toast('Error: ' + err.message, 'error'); }
  }
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', function () { NuApp.init(); });
} else {
  NuApp.init();
}

window.closeNuForm = function (btn) {
  const overlay = btn ? btn.closest('.nu-form-overlay') : null;
  if (overlay) { overlay.remove(); return; }
  NuApp.loadModule('forms');
};

window.submitNuForm = async function (formElement) {
  if (!formElement) { NuApp.toast('Form element not found', 'error'); return; }

  if (formElement.dataset.viewOnly === '1') { NuApp.toast('View only \u2014 saving is disabled', 'error'); return; }

  // Clear existing validation highlights & error messages
  formElement.querySelectorAll('.nu-validation-error-msg').forEach(el => el.remove());
  formElement.querySelectorAll('.nu-input-error').forEach(el => {
    el.classList.remove('nu-input-error');
    el.style.borderColor = '';
  });

  // Real-time Visual validation feedback
  let isFormValid = true;
  let firstInvalidElement = null;
  formElement.querySelectorAll('[required]').forEach((el) => {
    const isSelect2 = el.classList.contains('nu-select2');
    const targetEl = isSelect2 ? el.nextElementSibling || el : el;
    const value = el.value ? el.value.trim() : '';
    if (value === '') {
      isFormValid = false;
      el.classList.add('nu-input-error');
      targetEl.style.borderColor = '#ef4444';
      targetEl.style.boxShadow = '0 0 0 2px rgba(239, 68, 68, 0.2)';

      const errorMsg = document.createElement('div');
      errorMsg.className = 'nu-validation-error-msg';
      errorMsg.style.cssText = 'color:#ef4444;font-size:11px;font-weight:600;margin-top:4px;display:flex;align-items:center;gap:4px;transition:all 0.2s;';
      errorMsg.innerHTML = '⚠️ This field is required';
      targetEl.parentNode.appendChild(errorMsg);

      if (!firstInvalidElement) firstInvalidElement = el;
    }
  });

  if (!isFormValid) {
    NuApp.toast('Please fill out all required fields.', 'error');
    if (firstInvalidElement) {
      firstInvalidElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
      firstInvalidElement.focus();
    }
    return;
  }

  const formCode    = formElement.dataset.formCode;
  const recordId    = formElement.dataset.recordId;
  const displayMode = formElement.dataset.displayMode || 'inline';
  const fromBrowse  = formElement.dataset.fromBrowse  || null;
  const url = 'api/form.php?action=save&code=' + encodeURIComponent(formCode) +
    (recordId ? '&id=' + encodeURIComponent(recordId) : '');

  const formData = new FormData(formElement);
  const data = {};

  formData.forEach((value, key) => {
    if (Object.prototype.hasOwnProperty.call(data, key)) {
      if (!Array.isArray(data[key])) data[key] = [data[key]];
      data[key].push(value);
    } else {
      data[key] = value;
    }
  });

  formElement.querySelectorAll('select[name]').forEach((sel) => {
    const name = sel.name;
    if (!name) return;
    const selected = Array.from(sel.options)
      .filter(function (o) { return o.selected; })
      .map(function (o) { return o.value; });
    if (sel.multiple) {
      data[name] = selected;
    } else {
      data[name] = selected.length ? selected[0] : '';
    }
  });

  formElement.querySelectorAll('input[type="checkbox"]').forEach((el) => {
    if (!Object.prototype.hasOwnProperty.call(data, el.name)) data[el.name] = '';
  });

  if (window._nuFormBeforeSave && typeof window._nuFormBeforeSave[formCode] === 'function') {
    const result = window._nuFormBeforeSave[formCode](formElement, data);
    if (result === false) return;
    if (result && typeof result.then === 'function') {
      try { const v = await result; if (v === false) return; } catch (e) { return; }
    }
  }
  try {
    const json = await NuApp.apiJson(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    });
    if (!json.success) { NuApp.toast(json.error || 'Save failed', 'error'); return; }
    NuApp.toast(recordId ? 'Updated' : 'Saved');
    if (window._nuFormAfterSave && typeof window._nuFormAfterSave[formCode] === 'function') {
      window._nuFormAfterSave[formCode](formElement, json);
    }
    const overlay = formElement.closest('.nu-form-overlay');
    if (overlay) overlay.remove();
    if (typeof NuApp.browseForm === 'function' && formCode) {
      NuApp.browseForm(formCode, 1, '', fromBrowse || null, 'inline');
    } else {
      NuApp.loadModule('forms');
    }
  } catch (e) { console.error('submitNuForm error', e); NuApp.toast('Error: ' + e.message, 'error'); }
};

window.openFormBuilder = function ()                               { return NuApp.openFormBuilder ? NuApp.openFormBuilder() : (window.nbFormBuilder ? window.nbFormBuilder.open() : null); };
window.previewForm     = function (code, label, mode)              { return NuApp.previewForm(code, label, mode); };
window.editForm        = function (id)                             { return window.nbFormBuilder ? window.nbFormBuilder.edit(id) : null; };
window.addRecord       = function (code, label, mode)              { return NuApp.addRecord(code, label, mode); };
window.editRecord      = function (code, id, label, mode)          { return NuApp.editRecord(code, id, label, mode); };
window.viewRecord      = function (code, id, label, mode)          { return NuApp.viewRecord(code, id, label, mode); };
window.browseForm      = function (code, page, query, label, mode) { return NuApp.browseForm(code, page, query, label, mode); };
window.browseFormPage  = function (code, page, query, label, mode) { return NuApp.browseForm(code, page, query, label, mode); };
window.deleteForm      = function (id, name) {
  var msg = name ? 'Are you sure you want to delete ' + name + ' permanently?' : 'Are you sure you want to delete this permanently?';
  if (!confirm(msg)) return;
  NuApp.apiJson('api/crud.php?table=nu_forms&id=' + encodeURIComponent(id), {
    method: 'DELETE', credentials: 'same-origin'
  }).then(function (json) {
    if (json.success) { NuApp.toast('Deleted'); NuApp.loadModule('forms'); }
    else NuApp.toast(json.error || 'Failed', 'error');
  }).catch(function (e) { NuApp.toast('Error: ' + e.message, 'error'); });
};

// ══════════════════════════════════════════════════════════════════
// Word Certificates Dialog Overlay & Interaction Manager
// ══════════════════════════════════════════════════════════════════
NuApp._currentCertContext = null;

NuApp.openRecordCertificatesModal = function(formCode, recordId, preSelectedCertId) {
  // Ensure modal DOM elements exist
  let modal = document.getElementById('recordCertsModal');
  if (!modal) {
    const div = document.createElement('div');
    div.innerHTML = `
      <div class="nu-modal-overlay" id="recordCertsModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:10000; align-items:center; justify-content:center;">
          <div class="nu-modal" style="background:var(--bg-elevated); border:1px solid var(--border-color); border-radius:var(--radius-lg); width:100%; max-width:550px; padding:24px; position:relative; box-shadow:var(--shadow-lg);">
              <div class="nu-modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:1px solid var(--border-color); padding-bottom:12px;">
                  <h3 class="nu-modal-title">Record Certificates</h3>
                  <button class="nu-modal-close" onclick="document.getElementById('recordCertsModal').style.display='none'" style="background:none; border:none; cursor:pointer; color:var(--text-secondary);">
                      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                  </button>
              </div>
              <div class="nu-modal-body" style="display:flex; flex-direction:column; gap:16px;">
                  <div class="nu-field">
                      <label style="font-weight:600; font-size:12px; margin-bottom:6px; display:block;">Select a Certificate Template</label>
                      <select class="nu-input" id="recordCertTemplateSelect" style="width:100%;" onchange="NuApp.onRecordCertTemplateChanged(this.value)"></select>
                  </div>

                  <div id="recordCertActionsContainer" style="display:none; flex-direction:column; gap:16px; border-top:1px solid var(--border-color); padding-top:16px;">
                      <!-- Download options -->
                      <div>
                          <label style="font-weight:600; font-size:12px; margin-bottom:8px; display:block;">Print &amp; Download Options</label>
                          <div style="display:flex; gap:10px;">
                              <button type="button" class="nu-btn nu-btn-ghost" style="flex:1; display:flex; align-items:center; justify-content:center; gap:6px;" onclick="NuApp.downloadRecordCert('docx')">
                                  📥 Word (.docx)
                              </button>
                              <button type="button" class="nu-btn nu-btn-ghost" style="flex:1; display:flex; align-items:center; justify-content:center; gap:6px;" onclick="NuApp.downloadRecordCert('pdf')">
                                  📄 PDF (.pdf)
                              </button>
                          </div>
                      </div>

                      <!-- Email option -->
                      <div style="border-top:1px dashed var(--border-color); padding-top:12px;">
                          <label style="font-weight:600; font-size:12px; margin-bottom:8px; display:block;">Email Certificate Options</label>
                          <div style="display:flex; gap:10px; margin-bottom:12px;">
                              <button type="button" class="nu-btn nu-btn-ghost" style="flex:1;" onclick="NuApp.showRecordCertEmailForm('docx')">
                                  ✉️ Email Word (.docx)
                              </button>
                              <button type="button" class="nu-btn nu-btn-ghost" style="flex:1;" onclick="NuApp.showRecordCertEmailForm('pdf')">
                                  ✉️ Email PDF (.pdf)
                              </button>
                          </div>

                          <!-- Email input form -->
                          <div id="recordCertEmailForm" style="display:none; flex-direction:column; gap:10px; background:var(--bg-secondary); padding:12px; border-radius:6px; border:1px solid var(--border-color);">
                              <div class="nu-field">
                                  <label style="font-size:11px; font-weight:600;">Recipient Email <span style="color:#ef4444;">*</span></label>
                                  <input type="email" class="nu-input" id="recordCertEmailTo" placeholder="client@example.com" style="width:100%; font-size:12px;">
                              </div>
                              <div class="nu-field">
                                  <label style="font-size:11px; font-weight:600;">Subject (Optional)</label>
                                  <input type="text" class="nu-input" id="recordCertEmailSubject" placeholder="Your Certificate is Ready" style="width:100%; font-size:12px;">
                              </div>
                              <div class="nu-field">
                                  <label style="font-size:11px; font-weight:600;">Message / Body (Optional)</label>
                                  <textarea class="nu-input" id="recordCertEmailBody" rows="3" placeholder="Please find your certificate attached." style="width:100%; font-size:12px;"></textarea>
                              </div>
                              <div style="display:flex; justify-content:flex-end; gap:8px;">
                                  <button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" onclick="document.getElementById('recordCertEmailForm').style.display='none'">Cancel</button>
                                  <button type="button" class="nu-btn nu-btn-primary nu-btn-sm" id="recordCertEmailSendBtn" onclick="NuApp.sendRecordCertEmail()">Send Email</button>
                              </div>
                          </div>
                      </div>
                  </div>

                  <div id="recordCertNoTemplatesMessage" style="display:none; text-align:center; color:var(--text-secondary); padding:12px;">
                      No certificate templates available for this form.
                  </div>
              </div>
          </div>
      </div>
    `.trim();
    document.body.appendChild(div.firstChild);
    modal = document.getElementById('recordCertsModal');
  }

  // Clear previous email form values
  document.getElementById('recordCertEmailForm').style.display = 'none';
  document.getElementById('recordCertEmailTo').value = '';
  document.getElementById('recordCertEmailSubject').value = '';
  document.getElementById('recordCertEmailBody').value = '';

  NuApp._currentCertContext = { formCode, recordId };

  const modalTitle = modal.querySelector('.nu-modal-title');
  if (modalTitle) {
    modalTitle.textContent = 'Record Certificates';
  }

  // Fetch available templates for this form
  fetch(`api/word_certificates.php?action=list_templates&form_code=${encodeURIComponent(formCode)}`, { credentials: 'same-origin' })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        const select = document.getElementById('recordCertTemplateSelect');
        select.innerHTML = '<option value="">-- choose certificate template --</option>';
        data.templates.forEach(t => {
          const opt = document.createElement('option');
          opt.value = t.cert_id;
          opt.textContent = t.cert_title;
          select.appendChild(opt);
        });

        const selectWrapper = select.closest('.nu-field');
        if (preSelectedCertId) {
          const t = data.templates.find(x => x.cert_id == preSelectedCertId);
          if (t && modalTitle) {
            modalTitle.textContent = t.cert_button_label && t.cert_button_label.trim() !== ''
              ? t.cert_button_label.trim()
              : t.cert_title;
          }
          select.value = preSelectedCertId;
          if (selectWrapper) selectWrapper.style.display = 'none';
          NuApp.onRecordCertTemplateChanged(preSelectedCertId);
        } else {
          select.value = '';
          if (selectWrapper) selectWrapper.style.display = 'block';
          document.getElementById('recordCertActionsContainer').style.display = 'none';
        }

        const hasTemplates = data.templates.length > 0;
        document.getElementById('recordCertNoTemplatesMessage').style.display = hasTemplates ? 'none' : 'block';

        modal.style.display = 'flex';
      } else {
        NuApp.toast('Failed to load certificate templates: ' + data.error, 'error');
      }
    })
    .catch(err => {
      console.error(err);
      NuApp.toast('Connection error', 'error');
    });
};

NuApp.onRecordCertTemplateChanged = function(certId) {
  const container = document.getElementById('recordCertActionsContainer');
  if (certId) {
    container.style.display = 'flex';
    NuApp._currentCertContext.certId = certId;
  } else {
    container.style.display = 'none';
  }
};

NuApp.downloadRecordCert = function(format) {
  const ctx = NuApp._currentCertContext;
  if (!ctx || !ctx.certId) return;

  const url = `api/word_certificates.php?action=generate&cert_id=${ctx.certId}&record_id=${encodeURIComponent(ctx.recordId)}&form_code=${encodeURIComponent(ctx.formCode)}&format=${format}`;
  window.open(url, '_blank');
};

NuApp.showRecordCertEmailForm = function(format) {
  document.getElementById('recordCertEmailForm').style.display = 'flex';
  NuApp._currentCertContext.format = format;

  // Set default subject
  const select = document.getElementById('recordCertTemplateSelect');
  const title = select.options[select.selectedIndex].text;
  document.getElementById('recordCertEmailSubject').value = `Certificate: ${title}`;
};

NuApp.sendRecordCertEmail = function() {
  const ctx = NuApp._currentCertContext;
  if (!ctx || !ctx.certId || !ctx.format) return;

  const emailTo = document.getElementById('recordCertEmailTo').value.trim();
  const subject = document.getElementById('recordCertEmailSubject').value.trim();
  const body = document.getElementById('recordCertEmailBody').value.trim();

  if (!emailTo) {
    NuApp.toast('Recipient email is required', 'error');
    return;
  }

  const sendBtn = document.getElementById('recordCertEmailSendBtn');
  sendBtn.disabled = true;
  sendBtn.textContent = 'Sending...';

  const payload = {
    email: emailTo,
    subject: subject,
    body: body
  };

  fetch(`api/word_certificates.php?action=send_email&cert_id=${ctx.certId}&record_id=${encodeURIComponent(ctx.recordId)}&form_code=${encodeURIComponent(ctx.formCode)}&format=${ctx.format}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
    credentials: 'same-origin'
  })
  .then(r => r.json())
  .then(data => {
    sendBtn.disabled = false;
    sendBtn.textContent = 'Send Email';
    if (data.success) {
      NuApp.toast('Email sent successfully!', 'success');
      document.getElementById('recordCertEmailForm').style.display = 'none';
    } else {
      NuApp.toast('Failed to send email: ' + (data.message || 'Unknown error'), 'error');
    }
  })
  .catch(err => {
    sendBtn.disabled = false;
    sendBtn.textContent = 'Send Email';
    console.error(err);
    NuApp.toast('Connection error while sending email', 'error');
  });
};

window.callPHP = function(code, params, callback) {
  return NuApp.callPHP(code, params, callback);
};
window.runProcedure = function(code, params, callback) {
  return NuApp.runProcedure(code, params, callback);
};

window.nuApplyExtraMappings = function(extra, r, container) {
  if (!extra || typeof extra !== 'string' || extra.trim() === '') return;
  const activeContainer = container || document;
  const mappings = extra.split(',');
  mappings.forEach(m => {
    const parts = m.split(':');
    if (parts.length === 2) {
      const part0 = parts[0].trim();
      const part1 = parts[1].trim();

      // Check if part1 is the target element (standard sourceField:targetField)
      let targetEl = activeContainer.querySelector('input[name="' + part1 + '"]') || activeContainer.querySelector('[data-field="' + part1 + '"]') || document.querySelector('input[name="' + part1 + '"]');
      if (targetEl) {
        if (r && r[part0] !== undefined) {
          targetEl.value = r[part0];
        } else {
          // Treat part0 as a constant default value
          targetEl.value = part0;
        }
        targetEl.dispatchEvent(new Event('change', { bubbles: true }));
        targetEl.dispatchEvent(new Event('input', { bubbles: true }));
        return;
      }

      // Check if part0 is the target element (alternative targetField:defaultValue or vice versa)
      targetEl = activeContainer.querySelector('input[name="' + part0 + '"]') || activeContainer.querySelector('[data-field="' + part0 + '"]') || document.querySelector('input[name="' + part0 + '"]');
      if (targetEl) {
        if (r && r[part1] !== undefined) {
          targetEl.value = r[part1];
        } else {
          // Treat part1 as a constant default value
          targetEl.value = part1;
        }
        targetEl.dispatchEvent(new Event('change', { bubbles: true }));
        targetEl.dispatchEvent(new Event('input', { bubbles: true }));
        return;
      }
    }
  });
};

window.openLookupModal = function(name, table, idCol, displayCol, filter, extra, additionalFields) {
  // Try to find target inputs relative to the active overlay first
  const activeOverlay = document.querySelector('[data-sf-overlay]') || document.querySelector('.nu-form-overlay');
  const container = activeOverlay || document;

  let hiddenEl = container.querySelector('input[name="' + name + '"]') || container.querySelector('[data-field="' + name + '"]');
  let displayEl = container.querySelector('input[name="' + name + '_display"]') || container.querySelector('[data-field="' + name + '_display"]');

  // Fallback to searching the entire document
  if (!hiddenEl) {
    hiddenEl = document.querySelector('input[name="' + name + '"]') || document.querySelector('[data-field="' + name + '"]');
  }
  if (!displayEl) {
    displayEl = document.querySelector('input[name="' + name + '_display"]') || document.querySelector('[data-field="' + name + '_display"]') || document.querySelector('input[onclick*="openLookupModal(\'' + name + '\'"]');
  }

  let filterVal = filter || '';
  if (window.nuUserMeta) {
    Object.keys(window.nuUserMeta).forEach(key => {
      filterVal = filterVal.replace(new RegExp('##' + key + '##', 'g'), window.nuUserMeta[key]);
    });
  }
  if (window.nuUserLocation) {
    filterVal = filterVal.replace(/##location##/g, window.nuUserLocation);
  }

  let url = 'api/crud.php?table=' + encodeURIComponent(table) + '&page=1&per_page=100';
  if (filterVal) {
    url += '&where=' + encodeURIComponent(filterVal);
  }

  const apiCall = (window.NuApp && typeof window.NuApp.apiJson === 'function')
    ? window.NuApp.apiJson(url, { credentials: 'same-origin' })
    : fetch(url).then(r => r.json());

  apiCall.then(res => {
    if (!res || !res.success) {
      const msg = 'Failed to load lookup items: ' + ((res && res.error) || 'unknown error');
      if (window.NuApp && window.NuApp.toast) window.NuApp.toast(msg, 'error');
      else alert(msg);
      return;
    }
    const records = Array.isArray(res.data) ? res.data : (res.records || []);

    const overlay = document.createElement('div');
    overlay.className = 'nu-form-overlay nu-lookup-overlay';
    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:200000;display:flex;align-items:center;justify-content:center;';

    const box = document.createElement('div');
    box.style.cssText = 'background:var(--card-bg,#fff);border-radius:12px;padding:24px;width:90%;max-width:500px;max-height:80vh;display:flex;flex-direction:column;gap:16px;box-shadow:0 8px 30px rgba(0,0,0,0.3);';

    const header = document.createElement('div');
    header.style.cssText = 'display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border-color,#ddd);padding-bottom:12px;';
    const h3 = document.createElement('h3');
    h3.style.cssText = 'margin:0;font-size:16px;font-weight:600;color:var(--text-primary,#333);';
    h3.textContent = 'Select ' + (name.replace(/_id$/, '').toUpperCase() || 'Item');
    const closeBtn = document.createElement('button');
    closeBtn.type = 'button'; closeBtn.innerHTML = '&times;';
    closeBtn.style.cssText = 'background:none;border:none;font-size:24px;cursor:pointer;line-height:1;color:var(--text-secondary,#888);';
    closeBtn.onclick = () => overlay.remove();
    header.appendChild(h3); header.appendChild(closeBtn);
    box.appendChild(header);

    const searchInput = document.createElement('input');
    searchInput.type = 'text'; searchInput.className = 'nu-input';
    searchInput.placeholder = 'Search items...';
    searchInput.style.fontSize = '13px'; searchInput.style.padding = '6px 10px'; searchInput.style.width = '100%';
    box.appendChild(searchInput);

    const listArea = document.createElement('div');
    listArea.style.cssText = 'flex:1;overflow-y:auto;display:flex;flex-direction:column;gap:4px;border:1px solid var(--border-color,#ddd);border-radius:6px;padding:6px;background:var(--bg-elevated,#fafafa);';

    const renderList = (filterText) => {
      listArea.innerHTML = '';
      const q = (filterText || '').toLowerCase().trim();
      const filtered = records.filter(r => {
        const disp = String(r[displayCol] || '').toLowerCase();
        const idVal = String(r[idCol] || '').toLowerCase();
        let addFieldsMatch = false;
        if (additionalFields && typeof additionalFields === 'string') {
          const addCols = additionalFields.split(',').map(s => s.trim()).filter(Boolean);
          addCols.forEach(col => {
            if (r[col] !== undefined && r[col] !== null && String(r[col]).toLowerCase().indexOf(q) !== -1) {
              addFieldsMatch = true;
            }
          });
        }
        return disp.indexOf(q) !== -1 || idVal.indexOf(q) !== -1 || addFieldsMatch;
      });

      if (!filtered.length) {
        const empty = document.createElement('div');
        empty.style.cssText = 'padding:20px;text-align:center;color:var(--text-muted,#888);font-size:12px;';
        empty.textContent = 'No matching items';
        listArea.appendChild(empty);
        return;
      }

      if (additionalFields && typeof additionalFields === 'string' && additionalFields.trim() !== '') {
        // Expand the container box's width dynamically for table layout
        box.style.maxWidth = '800px';

        const tableEl = document.createElement('table');
        tableEl.style.cssText = 'width:100%;border-collapse:collapse;font-size:13px;text-align:left;';

        const thead = document.createElement('thead');
        thead.style.cssText = 'position:sticky;top:0;background:var(--bg-elevated,#fafafa);z-index:10;border-bottom:2px solid var(--border-color,#ddd);';
        const hRow = document.createElement('tr');

        const thMain = document.createElement('th');
        thMain.style.cssText = 'padding:8px 12px;font-weight:600;color:var(--text-secondary,#555);';
        thMain.textContent = (displayCol || 'NAME').replace(/_/g, ' ').toUpperCase();
        hRow.appendChild(thMain);

        const addCols = additionalFields.split(',').map(s => s.trim()).filter(Boolean);
        addCols.forEach(col => {
          const thAdd = document.createElement('th');
          thAdd.style.cssText = 'padding:8px 12px;font-weight:600;color:var(--text-secondary,#555);';
          thAdd.textContent = col.replace(/_/g, ' ').toUpperCase();
          hRow.appendChild(thAdd);
        });
        thead.appendChild(hRow);
        tableEl.appendChild(thead);

        const tbody = document.createElement('tbody');
        filtered.forEach(r => {
          const tr = document.createElement('tr');
          tr.style.cssText = 'border-bottom:1px solid var(--border-color,#eee);cursor:pointer;transition:background 0.15s;';
          tr.addEventListener('mouseover', () => tr.style.background = 'var(--bg-hover,#f5f7ff)');
          tr.addEventListener('mouseout', () => tr.style.background = 'none');

          const tdMain = document.createElement('td');
          tdMain.style.cssText = 'padding:8px 12px;color:var(--text-primary,#333);font-weight:500;';
          tdMain.textContent = r[displayCol] || r[idCol] || '';
          tr.appendChild(tdMain);

          addCols.forEach(col => {
            const tdAdd = document.createElement('td');
            tdAdd.style.cssText = 'padding:8px 12px;color:var(--text-secondary,#666);';
            tdAdd.textContent = r[col] !== undefined && r[col] !== null ? String(r[col]) : '';
            tr.appendChild(tdAdd);
          });

          tr.onclick = () => {
            overlay.remove();
            if (hiddenEl) {
              hiddenEl.value = r[idCol];
              hiddenEl.dispatchEvent(new Event('change', { bubbles: true }));
              hiddenEl.dispatchEvent(new Event('input', { bubbles: true }));
            }
            if (displayEl) {
              displayEl.value = r[displayCol] || r[idCol];
              displayEl.dispatchEvent(new Event('change', { bubbles: true }));
              displayEl.dispatchEvent(new Event('input', { bubbles: true }));
            }

            window.nuApplyExtraMappings(extra, r, container);
          };

          tbody.appendChild(tr);
        });
        tableEl.appendChild(tbody);
        listArea.appendChild(tableEl);
      } else {
        box.style.maxWidth = '500px';
        filtered.forEach(r => {
          const item = document.createElement('div');
          item.style.cssText = 'padding:8px 12px;font-size:13px;border-radius:4px;cursor:pointer;transition:all 0.15s;color:var(--text-primary,#333);';
          item.textContent = r[displayCol] || r[idCol] || 'Item';
          item.addEventListener('mouseover', () => item.style.background = 'var(--bg-hover,#f5f7ff)');
          item.addEventListener('mouseout', () => item.style.background = 'none');
          item.onclick = () => {
            overlay.remove();
            if (hiddenEl) {
              hiddenEl.value = r[idCol];
              hiddenEl.dispatchEvent(new Event('change', { bubbles: true }));
              hiddenEl.dispatchEvent(new Event('input', { bubbles: true }));
            }
            if (displayEl) {
              displayEl.value = r[displayCol] || r[idCol];
              displayEl.dispatchEvent(new Event('change', { bubbles: true }));
              displayEl.dispatchEvent(new Event('input', { bubbles: true }));
            }

            window.nuApplyExtraMappings(extra, r, container);
          };
          listArea.appendChild(item);
        });
      }
    };

    searchInput.oninput = () => renderList(searchInput.value);
    renderList('');

    box.appendChild(listArea);
    overlay.appendChild(box);
    document.body.appendChild(overlay);

    searchInput.focus();
  }).catch(err => {
    const msg = 'Error opening lookup modal: ' + err.message;
    if (window.NuApp && window.NuApp.toast) window.NuApp.toast(msg, 'error');
    else alert(msg);
  });
};

window.clearLookup = function(name) {
  const activeOverlay = document.querySelector('[data-sf-overlay]') || document.querySelector('.nu-form-overlay');
  const container = activeOverlay || document;

  let hiddenEl = container.querySelector('input[name="' + name + '"]') || container.querySelector('[data-field="' + name + '"]');
  let displayEl = container.querySelector('input[name="' + name + '_display"]') || container.querySelector('[data-field="' + name + '_display"]');

  if (!hiddenEl) {
    hiddenEl = document.querySelector('input[name="' + name + '"]') || document.querySelector('[data-field="' + name + '"]');
  }
  if (!displayEl) {
    displayEl = document.querySelector('input[name="' + name + '_display"]') || document.querySelector('[data-field="' + name + '_display"]') || document.querySelector('input[onclick*="openLookupModal(\'' + name + '\'"]');
  }

  if (hiddenEl) {
    hiddenEl.value = '';
    hiddenEl.dispatchEvent(new Event('change', { bubbles: true }));
    hiddenEl.dispatchEvent(new Event('input', { bubbles: true }));
  }
  if (displayEl) {
    displayEl.value = '';
    displayEl.dataset.lastVal = '';
    displayEl.dispatchEvent(new Event('change', { bubbles: true }));
    displayEl.dispatchEvent(new Event('input', { bubbles: true }));
  }
};

window.triggerAutoLookup = function(el) {
  const name = el.dataset.lookupName;
  const table = el.dataset.lookupTable;
  const idCol = el.dataset.lookupId;
  const displayCol = el.dataset.lookupDisplay;
  const filter = el.dataset.lookupFilter || '';
  const extra = el.dataset.lookupExtra || '';
  const additionalFields = el.dataset.lookupAdditionalFields || '';

  const query = el.value.trim();

  // Find corresponding hidden element to check current selection
  const activeOverlay = document.querySelector('[data-sf-overlay]') || document.querySelector('.nu-form-overlay');
  const container = activeOverlay || document;
  const hiddenEl = container.querySelector('input[name="' + name + '"]') || container.querySelector('[data-field="' + name + '"]') || document.querySelector('input[name="' + name + '"]') || document.querySelector('[data-field="' + name + '"]');

  if (query === '') {
    window.clearLookup(name);
    return;
  }

  let filterVal = filter || '';
  if (window.nuUserMeta) {
    Object.keys(window.nuUserMeta).forEach(key => {
      filterVal = filterVal.replace(new RegExp('##' + key + '##', 'g'), window.nuUserMeta[key]);
    });
  }
  if (window.nuUserLocation) {
    filterVal = filterVal.replace(/##location##/g, window.nuUserLocation);
  }

  let url = 'api/crud.php?table=' + encodeURIComponent(table) + '&page=1&per_page=100';
  if (filterVal) {
    url += '&where=' + encodeURIComponent(filterVal);
  }

  const apiCall = (window.NuApp && typeof window.NuApp.apiJson === 'function')
    ? window.NuApp.apiJson(url, { credentials: 'same-origin' })
    : fetch(url).then(r => r.json());

  apiCall.then(res => {
    if (!res || !res.success) return;
    const records = Array.isArray(res.data) ? res.data : (res.records || []);

    const qLower = query.toLowerCase();

    // First try finding an exact match
    let matches = records.filter(r => {
      const disp = String(r[displayCol] || '').toLowerCase();
      const idVal = String(r[idCol] || '').toLowerCase();
      return disp === qLower || idVal === qLower;
    });

    // Fallback to partial matches
    if (matches.length === 0) {
      matches = records.filter(r => {
        const disp = String(r[displayCol] || '').toLowerCase();
        const idVal = String(r[idCol] || '').toLowerCase();
        return disp.indexOf(qLower) !== -1 || idVal.indexOf(qLower) !== -1;
      });
    }

    if (matches.length === 1) {
      const r = matches[0];
      if (hiddenEl) {
        hiddenEl.value = r[idCol];
        hiddenEl.dispatchEvent(new Event('change', { bubbles: true }));
        hiddenEl.dispatchEvent(new Event('input', { bubbles: true }));
      }
      el.value = r[displayCol] || r[idCol];
      el.dataset.lastVal = el.value;
      el.dispatchEvent(new Event('change', { bubbles: true }));
      el.dispatchEvent(new Event('input', { bubbles: true }));

      // Extra mapping
      window.nuApplyExtraMappings(extra, r, container);
    } else {
      // Open modal automatically
      window.openLookupModal(name, table, idCol, displayCol, filter, extra, additionalFields);
      setTimeout(() => {
        // Find newly opened lookup overlay (z-index: 200000)
        const allOverlays = document.querySelectorAll('div[style*="z-index"]');
        let topOverlay = null;
        allOverlays.forEach(ov => {
          if (ov.style.zIndex === '200000' || ov.style.cssText.indexOf('z-index:200000') !== -1 || ov.style.cssText.indexOf('z-index: 200000') !== -1) {
            topOverlay = ov;
          }
        });
        if (topOverlay) {
          const searchInp = topOverlay.querySelector('input[type="text"]');
          if (searchInp) {
            searchInp.value = query;
            searchInp.dispatchEvent(new Event('input', { bubbles: true }));
          }
        }
      }, 100);
    }
  }).catch(err => {
    console.error('Auto lookup error:', err);
  });
};

// Global Event Delegation for Autocomplete / Auto Lookup
document.addEventListener('change', function(e) {
  if (e.target && e.target.dataset && e.target.dataset.lookupName) {
    if (e.target.value !== e.target.dataset.lastVal) {
      e.target.dataset.lastVal = e.target.value;
      window.triggerAutoLookup(e.target);
    }
  }
});

// AI Agent Form Assistant ("Ask Agent") Injection
window.nuInitAskAgent = function(scope) {
  scope = scope || document;
  const isGlobeAdmin = (window.nuUserRole || '').toLowerCase() === 'globeadmin' || (window.nuUserMeta && window.nuUserMeta.role === 'globeadmin');
  if (!isGlobeAdmin) return;

  const formEl = scope.querySelector('.nu-generated-form');
  if (!formEl) return;

  const actionsBar = formEl.querySelector('.nu-form-actions') || formEl.querySelector('.form-actions') || formEl;
  if (actionsBar.querySelector('.nu-ask-agent-btn')) return;

  const askBtn = document.createElement('button');
  askBtn.type = 'button';
  askBtn.className = 'btn btn-outline-info btn-sm mr-2 nu-ask-agent-btn';
  askBtn.innerHTML = '<i class="fas fa-robot mr-1"></i> Ask Agent';
  askBtn.style.cssText = 'margin-left:8px;font-weight:600;display:inline-flex;align-items:center;gap:4px;';

  askBtn.addEventListener('click', function(e) {
    e.preventDefault();
    window.nuOpenAgentChatOverlay(formEl);
  });

  if (actionsBar.lastChild) {
    actionsBar.appendChild(askBtn);
  } else {
    actionsBar.prepend(askBtn);
  }
};

window.nuOpenAgentChatOverlay = function(formEl) {
  let existingOverlay = document.getElementById('nuAgentChatOverlay');
  if (existingOverlay) existingOverlay.remove();

  const formCode = formEl.dataset.formCode || '';
  const pkInput = formEl.querySelector('input[type="hidden"][name^="id"], input[type="hidden"][name$="_id"], input[name="id"]');
  const recordId = pkInput ? pkInput.value : '';

  const overlay = document.createElement('div');
  overlay.id = 'nuAgentChatOverlay';
  overlay.style.cssText = 'position:fixed;bottom:20px;right:20px;width:380px;height:520px;background:#fff;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,0.25);z-index:99999;display:flex;flex-direction:column;overflow:hidden;border:1px solid #e2e8f0;font-family:sans-serif;';

  overlay.innerHTML = `
    <div style="background:#1e293b;color:#fff;padding:12px 16px;display:flex;justify-content:space-between;align-items:center;">
      <div style="display:flex;align-items:center;gap:8px;font-weight:600;font-size:14px;">
        <i class="fas fa-robot text-info"></i> Nuvis AI Assistant
      </div>
      <button type="button" onclick="document.getElementById('nuAgentChatOverlay').remove()" style="background:none;border:none;color:#94a3b8;font-size:18px;cursor:pointer;">&times;</button>
    </div>
    <div id="nuAgentChatMessages" style="flex:1;padding:12px;overflow-y:auto;background:#f8fafc;display:flex;flex-direction:column;gap:8px;font-size:13px;">
      <div style="background:#e2e8f0;color:#334155;padding:8px 12px;border-radius:8px;align-self:flex-start;max-width:85%;">
        Hello! I am your AI assistant. How can I help you with this record (${formCode} #${recordId || 'New'})?
      </div>
    </div>
    <div style="padding:10px;background:#fff;border-top:1px solid #e2e8f0;display:flex;gap:6px;">
      <input type="text" id="nuAgentChatInput" placeholder="Ask agent..." style="flex:1;border:1px solid #cbd5e1;border-radius:6px;padding:6px 10px;font-size:13px;outline:none;">
      <button type="button" id="nuAgentSendBtn" style="background:#0284c7;color:#fff;border:none;padding:6px 12px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;">Send</button>
    </div>
  `;

  document.body.appendChild(overlay);

  const inputEl = document.getElementById('nuAgentChatInput');
  const sendBtn = document.getElementById('nuAgentSendBtn');
  const msgArea = document.getElementById('nuAgentChatMessages');

  const sendMessage = function() {
    const text = inputEl.value.trim();
    if (!text) return;

    const userBubble = document.createElement('div');
    userBubble.style.cssText = 'background:#0284c7;color:#fff;padding:8px 12px;border-radius:8px;align-self:flex-end;max-width:85%;';
    userBubble.textContent = text;
    msgArea.appendChild(userBubble);
    inputEl.value = '';
    msgArea.scrollTop = msgArea.scrollHeight;

    const botBubble = document.createElement('div');
    botBubble.style.cssText = 'background:#e2e8f0;color:#334155;padding:8px 12px;border-radius:8px;align-self:flex-start;max-width:85%;';
    botBubble.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Thinking...';
    msgArea.appendChild(botBubble);
    msgArea.scrollTop = msgArea.scrollHeight;

    fetch('api/agent.php?action=run', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        agent_id: 1,
        message: text,
        context: {
          form_code: formCode,
          record_id: recordId,
          user_role: window.nuUserRole || 'globeadmin'
        }
      })
    })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        botBubble.textContent = data.answer || 'Done.';
      } else {
        botBubble.textContent = 'Error: ' + (data.error || 'Request failed');
      }
      msgArea.scrollTop = msgArea.scrollHeight;
    })
    .catch(err => {
      botBubble.textContent = 'Network error occurred.';
    });
  };

  sendBtn.addEventListener('click', sendMessage);
  inputEl.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      sendMessage();
    }
  });
};

// window.nuGroupToggle function for collapsible layout sections
window.nuGroupToggle = function(headerEl) {
  const container = headerEl.closest('.nu-form-group');
  if (!container) return;
  const body = container.querySelector('.nu-group-body');
  if (!body) return;

  const isExpanded = headerEl.getAttribute('aria-expanded') === 'true';
  const newExpanded = !isExpanded;

  headerEl.setAttribute('aria-expanded', newExpanded ? 'true' : 'false');
  body.classList.toggle('nu-group-body-collapsed', !newExpanded);

  const chevron = headerEl.querySelector('.nu-group-chevron');
  if (chevron) {
    chevron.classList.toggle('nu-chevron-collapsed', !newExpanded);
  }
};

document.addEventListener('keydown', function(e) {
  if (e.target && e.target.dataset && e.target.dataset.lookupName) {
    if (e.key === 'Enter') {
      e.preventDefault();
      e.target.blur();
    }
  }
});
