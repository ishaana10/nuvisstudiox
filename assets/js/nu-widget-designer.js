/* Nuvis StudioX — role layout designer + field-driven widget builder (globeadmin only).
 * Loaded on demand by nu-widgets.js. Every call is also enforced server-side by NuWidgetPolicy. */
(function () {
  'use strict';
  var NW = window.NuWidgets;
  var SIZES = [['S', 1], ['M', 2], ['L', 3], ['XL', 4]];
  var GROUP_ORDER = ['Metrics', 'Charts', 'Data', 'Content', 'Navigation'];
  var REFRESH = [[0, 'Off'], [30, '30 s'], [60, '1 min'], [300, '5 min'], [900, '15 min']];
  var S = { role: '', roles: [], widgets: [], types: {}, icons: {}, dlg: null, grid: null };

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
  function svgFrag(markup) { var t = document.createElement('template'); t.innerHTML = markup; return t.content.firstChild; }
  function btn(text, cls, fn, extra) { return el('button', Object.assign({ type: 'button', class: cls, text: text, onclick: fn }, extra || {})); }
  function cfgOf(w) { try { var c = JSON.parse(w.widget_config || '{}'); return c && typeof c === 'object' ? c : {}; } catch (e) { return {}; } }
  function toast(msg, bad) { var s = document.getElementById('nuWgStatus'); if (s) { s.textContent = msg; s.classList.toggle('is-error', !!bad); } }
  function api(action, o) { return NW._api(action, o); }

  function openDialog(cls, labelId) {
    var d = el('dialog', { class: 'nu-dialog ' + cls, 'aria-labelledby': labelId });
    d.addEventListener('cancel', function (e) { e.preventDefault(); closeDialog(d); });
    document.body.appendChild(d);
    if (typeof d.showModal === 'function') d.showModal(); else d.setAttribute('open', '');
    return d;
  }
  function closeDialog(d) { try { d.close(); } catch (e) { /* not open */ } d.remove(); }

  function confirmBox(title, text, label) {
    return new Promise(function (resolve) {
      var d = el('dialog', { class: 'nu-dialog', 'aria-labelledby': 'nuWgCfT' });
      var fin = function (v) { closeDialog(d); resolve(v); };
      d.appendChild(el('div', { class: 'nu-dialog-body' }, el('h3', { class: 'nu-dialog-title', id: 'nuWgCfT', text: title }), el('p', { class: 'nu-dialog-text', text: text })));
      d.appendChild(el('div', { class: 'nu-dialog-actions' }, btn('Cancel', 'nu-btn nu-btn-ghost', function () { fin(false); }), btn(label, 'nu-btn nu-btn-danger', function () { fin(true); })));
      d.addEventListener('cancel', function (e) { e.preventDefault(); fin(false); });
      document.body.appendChild(d);
      if (typeof d.showModal === 'function') d.showModal(); else d.setAttribute('open', '');
    });
  }

  // ---------- data ----------
  function loadRole(role) {
    return api('designer_load', { query: 'role=' + encodeURIComponent(role) }).then(function (d) {
      if (!d || !d.ok) throw new Error((d && d.error) || 'Could not load');
      S.roles = d.roles.concat([{ role_code: 'globeadmin', role_name: 'Developer (your own dashboard)' }]);
      S.role = d.role; S.widgets = d.widgets || [];
    });
  }
  function reloadRole() { return loadRole(S.role).then(renderGrid); }

  // ---------- designer grid ----------
  function cardFor(w) {
    var def = S.types[w.widget_type] || { label: w.widget_type, group: 'data', icon: '' };
    var title = (w.widget_title || '').trim() || def.label;
    var custom = (w.widget_icon || '').trim();
    var ico = el('span', { class: 'nu-wg-ico' });
    if (custom && S.icons[custom]) ico.appendChild(svgFrag(S.icons[custom]));
    else if (custom && !/^[A-Za-z0-9_\- ]+$/.test(custom)) ico.textContent = custom;
    if (!ico.firstChild && !ico.textContent) ico.appendChild(svgFrag(S.icons[def.icon] || S.icons.chart || '<svg></svg>'));
    var c = el('article', { class: 'nu-wg-card', 'data-widget-id': w.widget_id, 'data-type': w.widget_type, 'data-group': String(def.group || 'data').toLowerCase(),
      'data-w': w.widget_width, 'data-h': w.widget_height, 'data-refresh': 0, 'data-locked': Number(w.widget_locked) ? '1' : '0' });
    c.appendChild(el('header', { class: 'nu-wg-head' }, ico, el('h3', { class: 'nu-wg-title', text: title })));
    c.appendChild(strip(w, title));
    c.appendChild(el('div', { class: 'nu-wg-body' }));
    return c;
  }
  function strip(w, title) {
    var s = el('div', { class: 'nu-wg-edit' });
    s.appendChild(btn('↑', 'nu-wg-tool', function () { moveWidget(w.widget_id, -1); }, { 'aria-label': 'Move ' + title + ' earlier', title: 'Move earlier' }));
    s.appendChild(btn('↓', 'nu-wg-tool', function () { moveWidget(w.widget_id, 1); }, { 'aria-label': 'Move ' + title + ' later', title: 'Move later' }));
    var sizes = el('div', { class: 'nu-wg-sizes', role: 'group', 'aria-label': 'Size' });
    SIZES.forEach(function (z) {
      sizes.appendChild(btn(z[0], 'nu-wg-size', function () {
        api('update', { body: { id: w.widget_id, width: z[1] } }).then(reloadRole);
      }, { 'aria-pressed': Number(w.widget_width) === z[1] ? 'true' : 'false' }));
    });
    s.appendChild(sizes);
    var locked = Number(w.widget_locked) === 1;
    s.appendChild(btn(locked ? 'Locked' : 'Lock', 'nu-wg-size nu-wg-lockbtn', function () {
      api('update', { body: { id: w.widget_id, locked: locked ? 0 : 1 } }).then(reloadRole);
    }, { 'aria-pressed': locked ? 'true' : 'false', title: 'Locked widgets cannot be hidden by users' }));
    s.appendChild(btn('Edit', 'nu-wg-size', function () { openBuilder(w); }));
    s.appendChild(btn('Remove', 'nu-wg-size', function () {
      confirmBox('Remove widget?', '“' + title + '” will disappear from every ' + S.role + ' dashboard.', 'Remove').then(function (ok) {
        if (ok) api('remove', { body: { id: w.widget_id } }).then(reloadRole);
      });
    }));
    return s;
  }
  function moveWidget(id, dir) {
    var i = S.widgets.findIndex(function (w) { return Number(w.widget_id) === Number(id); });
    var j = i + dir;
    if (i < 0 || j < 0 || j >= S.widgets.length) return;
    var t = S.widgets[i]; S.widgets[i] = S.widgets[j]; S.widgets[j] = t;
    var order = S.widgets.map(function (w, k) { return { id: Number(w.widget_id), position: (k + 1) * 10 }; });
    api('reorder', { body: { order: order } }).then(reloadRole);
  }
  function renderGrid() {
    var host = S.dlg.querySelector('.nu-wgd-main');
    host.textContent = '';
    var sel = S.dlg.querySelector('.nu-wgd-role');
    sel.textContent = '';
    S.roles.forEach(function (r) { var o = el('option', { value: r.role_code, text: r.role_name || r.role_code }); if (String(r.role_code).toLowerCase() === S.role) o.selected = true; sel.appendChild(o); });
    if (!S.widgets.length) {
      host.appendChild(el('div', { class: 'nu-wg-blank' }, el('strong', { text: 'No widgets for this role yet' }), document.createTextNode('Add one, copy another role’s layout, or import a saved layout.')));
      return;
    }
    S.grid = el('div', { class: 'nu-wg-grid nu-wgd-grid', 'data-density': 'comfortable', 'data-card-style': 'soft' });
    S.widgets.forEach(function (w) { var c = cardFor(w); S.grid.appendChild(c); NW._load(c, false); });
    host.appendChild(S.grid);
  }

  // ---------- designer shell ----------
  function open() {
    if (S.dlg) return;
    S.dlg = openDialog('nu-dialog--xl nu-wgd', 'nuWgdTitle');
    S.dlg.id = 'nuWgDesigner';
    S.dlg.classList.add('nu-wg');
    var role = el('select', { class: 'nu-wgd-role', 'aria-label': 'Role to design' });
    role.addEventListener('change', function () { loadRole(role.value).then(renderGrid).catch(fail); });
    var file = el('input', { type: 'file', accept: 'application/json,.json', class: 'nu-wg-sr', tabindex: '-1', 'aria-hidden': 'true' });
    file.addEventListener('change', function () { importFile(file); });
    S.dlg.appendChild(el('div', { class: 'nu-wgd-head' },
      el('h3', { id: 'nuWgdTitle', text: 'Design role layout' }), role,
      btn('Add widget', 'nu-btn nu-btn-primary nu-btn-sm', function () { openBuilder(null); }),
      btn('Copy from…', 'nu-btn nu-btn-ghost nu-btn-sm', copyFrom),
      btn('Export', 'nu-btn nu-btn-ghost nu-btn-sm', exportLayout),
      btn('Import', 'nu-btn nu-btn-ghost nu-btn-sm', function () { file.click(); }),
      btn('Close', 'nu-btn nu-btn-ghost nu-btn-sm', close), file));
    S.dlg.appendChild(el('p', { class: 'nu-wg-hint nu-wgd-note', text: 'Everyone with this role gets these widgets. Users can reorder, resize, recolour and hide them for themselves; locked widgets can’t be hidden.' }));
    S.dlg.appendChild(el('div', { class: 'nu-wgd-main' }));
    Promise.all([api('types'), loadRole('user')]).then(function (r) {
      if (!r[0] || !r[0].ok) throw new Error('types');
      S.types = r[0].types; S.icons = r[0].icons || {};
      renderGrid();
    }).catch(fail);
  }
  function close() { if (S.dlg) { closeDialog(S.dlg); S.dlg = null; } }
  function fail(e) { toast((e && e.message) || 'Something went wrong', true); }

  // ---------- copy / export / import ----------
  function addWidget(body) { return api('add', { body: Object.assign({ target_role: S.role }, body) }); }
  function copyFrom() {
    var d = openDialog('', 'nuWgCpT');
    var sel = el('select', { 'aria-label': 'Copy from role' });
    S.roles.forEach(function (r) { if (String(r.role_code).toLowerCase() !== S.role) sel.appendChild(el('option', { value: r.role_code, text: r.role_name || r.role_code })); });
    d.appendChild(el('div', { class: 'nu-dialog-body' }, el('h3', { class: 'nu-dialog-title', id: 'nuWgCpT', text: 'Copy layout' }),
      el('p', { class: 'nu-dialog-text', text: 'Adds a copy of every widget from the chosen role to “' + S.role + '”. Existing widgets stay.' }), sel));
    d.appendChild(el('div', { class: 'nu-dialog-actions' }, btn('Cancel', 'nu-btn nu-btn-ghost', function () { closeDialog(d); }),
      btn('Copy', 'nu-btn nu-btn-primary', function () {
        var from = sel.value; closeDialog(d);
        api('designer_load', { query: 'role=' + encodeURIComponent(from) }).then(function (r) {
          return (r.widgets || []).reduce(function (p, w) { return p.then(function () { return addWidget(rowToBody(w)); }); }, Promise.resolve());
        }).then(reloadRole).catch(fail);
      })));
  }
  function rowToBody(w) {
    return { type: w.widget_type, title: w.widget_title, icon: w.widget_icon || '', config: cfgOf(w), width: Number(w.widget_width), height: Number(w.widget_height),
      locked: Number(w.widget_locked) === 1, refresh: Number(w.widget_refresh) || 0 };
  }
  function exportLayout() {
    var data = { format: 'nuvis-role-layout', version: 1, role: S.role, widgets: S.widgets.map(rowToBody) };
    var a = el('a', { href: URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })), download: 'dashboard-' + S.role + '.json' });
    document.body.appendChild(a); a.click(); a.remove();
  }
  function importFile(input) {
    var f = input.files && input.files[0];
    input.value = '';
    if (!f) return;
    var rd = new FileReader();
    rd.onload = function () {
      var data;
      try { data = JSON.parse(String(rd.result)); } catch (e) { toast('That file isn’t valid JSON.', true); return; }
      if (!data || data.format !== 'nuvis-role-layout' || !Array.isArray(data.widgets)) { toast('Not a Nuvis role layout file.', true); return; }
      var ok = data.widgets.filter(function (w) { return w && S.types[w.type]; });
      ok.reduce(function (p, w) { return p.then(function () { return addWidget(w); }); }, Promise.resolve()).then(function () {
        toast('Imported ' + ok.length + ' of ' + data.widgets.length + ' widgets');
        return reloadRole();
      }).catch(fail);
    };
    rd.readAsText(f);
  }

  // 'lines' fields hold arrays of {label, module|url, icon, mode}; the form edits them as
  // "Label|form_code_or_https_url|icon|popup" lines (icon and popup optional).
  function linesToText(v) {
    if (typeof v === 'string') return v;
    if (!Array.isArray(v)) return '';
    return v.map(function (it) {
      var parts = [it.label || '', it.module || it.url || ''];
      if (it.icon || it.mode === 'popup') parts.push(it.icon || '');
      if (it.mode === 'popup') parts.push('popup');
      return parts.join('|');
    }).join('\n');
  }
  function textToLines(t) {
    return String(t).split(/\r?\n/).map(function (ln) {
      var p = ln.split('|').map(function (x) { return x.trim(); });
      if (!p[0] || !p[1]) return null;
      var it = { label: p[0] };
      if (/^https?:\/\//i.test(p[1])) it.url = p[1]; else it.module = p[1];
      if (p[2]) it.icon = p[2];
      if (p[3] === 'popup') it.mode = 'popup';
      return it;
    }).filter(Boolean);
  }

  // ---------- builder (generated from registry fields) ----------
  function fieldInput(f, value) {
    var id = 'nuWgF_' + f.key, inp;
    var v = value === undefined || value === null ? '' : value;
    if (f.kind === 'lines') v = linesToText(v);
    switch (f.kind) {
      case 'textarea': case 'lines': case 'html': case 'sql':
        inp = el('textarea', { id: id, rows: f.kind === 'sql' ? '5' : '4', spellcheck: f.kind === 'text' ? 'true' : 'false', class: f.kind === 'sql' || f.kind === 'html' ? 'nu-wgd-mono' : '' });
        inp.value = String(v); break;
      case 'number': inp = el('input', { id: id, type: 'number', step: 'any' }); inp.value = String(v); break;
      case 'color': inp = el('input', { id: id, type: 'color' }); inp.value = /^#[0-9a-f]{6}$/i.test(String(v)) ? String(v) : '#0f766e'; break;
      case 'url': inp = el('input', { id: id, type: 'url', placeholder: 'https://' }); inp.value = String(v); break;
      case 'select':
        inp = el('select', { id: id });
        (f.options || []).forEach(function (o) { var opt = el('option', { value: o, text: o }); if (String(o) === String(v)) opt.selected = true; inp.appendChild(opt); });
        break;
      default: inp = el('input', { id: id, type: 'text' }); inp.value = String(v);
    }
    inp.setAttribute('data-field', f.key); inp.setAttribute('data-kind', f.kind);
    return inp;
  }
  function row(label, input, help, forId) {
    return el('div', { class: 'nu-wgd-row' }, el('label', { for: forId || input.id, text: label }), input, help ? el('small', { text: help }) : null);
  }

  function openBuilder(widget) {
    if (widget) return builderForm(widget, widget.widget_type);
    // type picker, grouped by registry group
    var d = openDialog('nu-dialog--wide', 'nuWgBT');
    d.id = 'nuWgBuilder';
    d.appendChild(el('div', { class: 'nu-dialog-body' }, el('h3', { class: 'nu-dialog-title', id: 'nuWgBT', text: 'Choose a widget type' })));
    var body = d.firstChild;
    var groups = GROUP_ORDER.slice();
    Object.keys(S.types).forEach(function (t) { if (groups.indexOf(S.types[t].group) < 0) groups.push(S.types[t].group); });
    groups.forEach(function (g) {
      var types = Object.keys(S.types).filter(function (t) { return S.types[t].group === g; });
      if (!types.length) return;
      body.appendChild(el('h4', { class: 'nu-wgd-group', text: g }));
      var wrap = el('div', { class: 'nu-wgd-types' });
      types.forEach(function (t) {
        var b = el('button', { type: 'button', class: 'nu-wgd-type', 'data-type': t });
        if (S.icons[S.types[t].icon]) b.appendChild(svgFrag(S.icons[S.types[t].icon]));
        b.appendChild(el('span', { text: S.types[t].label }));
        b.addEventListener('click', function () { closeDialog(d); builderForm(null, t); });
        wrap.appendChild(b);
      });
      body.appendChild(wrap);
    });
    d.appendChild(el('div', { class: 'nu-dialog-actions' }, btn('Cancel', 'nu-btn nu-btn-ghost', function () { closeDialog(d); })));
  }

  // draft: values carried over when the developer switches "Display as" inside the form.
  function builderForm(widget, type, draft) {
    var def = S.types[type];
    var cfg = draft ? draft.config : widget ? cfgOf(widget) : JSON.parse(JSON.stringify(def.defaults.config || {}));
    var d = openDialog('nu-dialog--wide', 'nuWgBT');
    d.id = 'nuWgBuilder';
    var form = el('form', { class: 'nu-wgd-form', novalidate: 'novalidate' });
    var title = el('input', { id: 'nuWgF_title', type: 'text', maxlength: '120' });
    title.value = draft ? draft.title : widget ? widget.widget_title : def.label;
    form.appendChild(row('Title', title));

    // Display as: any widget type; switching keeps title, icon, size and the fields both types share.
    var typeSel = el('select', { id: 'nuWgF_type' });
    var seenGroups = [];
    Object.keys(S.types).forEach(function (t) { var g = S.types[t].group; if (seenGroups.indexOf(g) < 0) seenGroups.push(g); });
    seenGroups.forEach(function (g) {
      var og = el('optgroup', { label: g });
      Object.keys(S.types).filter(function (t) { return S.types[t].group === g; }).forEach(function (t) {
        var o = el('option', { value: t, text: S.types[t].label }); if (t === type) o.selected = true; og.appendChild(o);
      });
      typeSel.appendChild(og);
    });
    form.appendChild(row('Display as', typeSel, 'Number, chart, list, table… — fields below change with the type.'));

    // icon: built-in set + "default"; legacy values (emoji / fa-…) stay editable as text
    var curIcon = draft ? draft.icon : widget ? (widget.widget_icon || '') : '';
    var iconVal = curIcon;
    var picker = el('div', { class: 'nu-wgd-icons', role: 'group', 'aria-label': 'Icon' });
    var legacy = el('input', { type: 'text', maxlength: '120', placeholder: 'or emoji / fa-icon-name', 'aria-label': 'Custom icon' });
    var addIcon = function (name, markup, label) {
      var b = el('button', { type: 'button', class: 'nu-wgd-icon', title: label, 'aria-label': label, 'aria-pressed': iconVal === name ? 'true' : 'false' });
      if (markup) b.appendChild(svgFrag(markup)); else b.textContent = '∅';
      b.addEventListener('click', function () { iconVal = name; legacy.value = S.icons[name] ? '' : name; picker.querySelectorAll('.nu-wgd-icon').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); }); });
      picker.appendChild(b);
    };
    addIcon('', '', 'Default for this type');
    Object.keys(S.icons).forEach(function (n) { addIcon(n, S.icons[n], n); });
    legacy.value = curIcon && !S.icons[curIcon] ? curIcon : '';
    legacy.addEventListener('input', function () { iconVal = legacy.value.trim(); picker.querySelectorAll('.nu-wgd-icon').forEach(function (x) { x.setAttribute('aria-pressed', 'false'); }); });
    form.appendChild(el('div', { class: 'nu-wgd-row' }, el('span', { class: 'nu-wgd-label', text: 'Icon' }), picker, legacy));

    var inputs = [];
    (def.fields || []).forEach(function (f) {
      var inp = fieldInput(f, cfg[f.key]);
      inputs.push([f, inp]);
      var r = row(f.label + (f.required ? ' *' : ''), inp, f.help);
      if (f.kind === 'sql') {
        var out = el('div', { class: 'nu-wg-hint', 'aria-live': 'polite' });
        r.appendChild(btn('Test query', 'nu-btn nu-btn-ghost nu-btn-sm', function () {
          out.textContent = 'Running…';
          api('run_sql', { body: { sql: inp.value } }).then(function (res) {
            out.textContent = res.error ? res.error : (res.rows || []).length + ' row(s). First: ' + JSON.stringify((res.rows || [])[0] || {}).slice(0, 200);
          }).catch(function () { out.textContent = 'Request failed.'; });
        }));
        r.appendChild(out);
      }
      form.appendChild(r);
    });

    var w = el('select', { id: 'nuWgF_w' }), h = el('select', { id: 'nuWgF_h' }), rf = el('select', { id: 'nuWgF_rf' });
    SIZES.forEach(function (z) { var o = el('option', { value: z[1], text: z[0] + ' (' + z[1] + '/4 wide)' }); if (z[1] === Number(draft ? draft.width : widget ? widget.widget_width : def.defaults.width)) o.selected = true; w.appendChild(o); });
    [1, 2, 3].forEach(function (n) { var o = el('option', { value: n, text: n + (n === 1 ? ' row' : ' rows') }); if (n === Number(draft ? draft.height : widget ? widget.widget_height : def.defaults.height)) o.selected = true; h.appendChild(o); });
    REFRESH.forEach(function (r) { var o = el('option', { value: r[0], text: r[1] }); if (r[0] === Number(draft ? draft.refresh : widget ? widget.widget_refresh : 0)) o.selected = true; rf.appendChild(o); });
    form.appendChild(el('div', { class: 'nu-wgd-cols' }, row('Width', w), row('Height', h), row('Auto-refresh', rf)));
    var lock = el('input', { id: 'nuWgF_lock', type: 'checkbox' });
    lock.checked = draft ? !!draft.locked : widget ? Number(widget.widget_locked) === 1 : false;
    form.appendChild(el('div', { class: 'nu-wgd-row nu-wgd-check' }, lock, el('label', { for: 'nuWgF_lock', text: 'Lock (users can’t hide this widget)' })));

    var err = el('div', { class: 'nu-wg-error', role: 'alert' });
    form.appendChild(err);
    var save = btn(widget ? 'Save' : 'Add widget', 'nu-btn nu-btn-primary', function () { submit(); });
    save.type = 'submit';
    d.appendChild(el('div', { class: 'nu-dialog-body' }, el('h3', { class: 'nu-dialog-title', id: 'nuWgBT', text: (widget ? 'Edit ' : 'New ') + def.label }), form));
    d.appendChild(el('div', { class: 'nu-dialog-actions' }, btn('Cancel', 'nu-btn nu-btn-ghost', function () { closeDialog(d); }), save));
    form.addEventListener('submit', function (e) { e.preventDefault(); submit(); });

    typeSel.addEventListener('change', function () {
      var g = gather(), nt = typeSel.value;
      var carried = Object.assign({}, g.config);
      var nd = S.types[nt].defaults || {};
      Object.keys(nd.config || {}).forEach(function (k) { if (carried[k] === undefined) carried[k] = nd.config[k]; });
      closeDialog(d);
      builderForm(widget, nt, {
        title: (!g.title.trim() || g.title.trim() === def.label) ? S.types[nt].label : g.title,
        icon: iconVal, config: carried, width: Number(w.value), height: Number(h.value), locked: lock.checked, refresh: Number(rf.value)
      });
    });

    function gather() {
      var config = {};
      inputs.forEach(function (p) {
        var f = p[0], inp = p[1], v = inp.value;
        if (f.kind === 'number') { v = v === '' ? undefined : Number(v); }
        else if (f.kind === 'lines') { v = textToLines(v); if (!v.length) v = undefined; }
        else if (typeof v === 'string') v = v.trim();
        if (v !== undefined && v !== '') config[f.key] = v;
      });
      return { title: title.value, config: config };
    }

    function submit() {
      var config = {}, missing = [];
      inputs.forEach(function (p) {
        var f = p[0], inp = p[1], v = inp.value;
        if (f.kind === 'number') { if (v === '') { v = undefined; } else v = Number(v); }
        else if (f.kind === 'lines') { v = textToLines(v); if (!v.length) v = undefined; }
        else if (typeof v === 'string') v = v.trim();
        if (f.required && (v === undefined || v === '')) missing.push(f.label);
        if (v !== undefined && v !== '') config[f.key] = v;
      });
      if (!title.value.trim()) missing.unshift('Title');
      if (missing.length) { err.textContent = 'Please fill in: ' + missing.join(', ') + '.'; return; }
      var body = { type: type, title: title.value.trim(), icon: iconVal, config: config, width: Number(w.value), height: Number(h.value), locked: lock.checked, refresh: Number(rf.value) };
      var req = widget ? api('update', { body: Object.assign({ id: widget.widget_id }, body) }) : addWidget(body);
      req.then(function (res) {
        if (!res || !res.ok) { err.textContent = (res && res.error) || 'Could not save.'; return; }
        closeDialog(d);
        reloadRole();
      }).catch(function () { err.textContent = 'Request failed.'; });
    }
    var first = form.querySelector('input,textarea,select'); if (first) first.focus();
  }

  window.NuWidgetDesigner = { open: open, close: close };
  NW.openDesigner = open;
})();
