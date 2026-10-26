/*!
 * nu-workflow-builder.js — visual rule + action editors for the Workflow module.
 *
 *   NuWfBuilder.mountConditions(el, {value, fields, onChange})        → {getValue,setValue,destroy}
 *   NuWfBuilder.mountActions(el, {value, fields, tableName, schema, preview, onChange})
 *                                                                      → {getValue,setValue,setContext,destroy}
 *   NuWfBuilder.normalizeActions(raw)   legacy/JSON/array → clean action array (mirrors core/WorkflowActions.php)
 *   NuWfBuilder.describeCondition(raw)  short human text for badges
 *   NuWfBuilder.evalCondition(raw, data) client-side mirror of NuWfRules (used by the simulator)
 *
 * The editors read and write exactly the JSON the engine stores, so "Advanced JSON" is always available.
 */
(function (global) {
  'use strict';

  var uid = 0;
  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }

  var OPS = [
    ['=', 'equals'], ['!=', 'does not equal'], ['>', 'is greater than'], ['>=', 'is at least'], ['<', 'is less than'], ['<=', 'is at most'],
    ['contains', 'contains'], ['not_contains', 'does not contain'], ['starts_with', 'starts with'], ['ends_with', 'ends with'],
    ['in', 'is one of (a, b, c)'], ['not_in', 'is not one of'], ['between', 'is between'], ['empty', 'is empty'], ['not_empty', 'is not empty']
  ];
  var TYPES = {
    update_record: { icon: '✎', name: 'Update a record', hint: 'Change fields in this workflow’s record or in any other table' },
    create_record: { icon: '＋', name: 'Add a record', hint: 'Insert a new row into a table' },
    send_email:    { icon: '✉', name: 'Send an email', hint: '' },
    notify_user:   { icon: '🔔', name: 'Notify a user', hint: '' },
    http_request:  { icon: '🌐', name: 'Call a web address', hint: 'Webhook / API request' },
    run_procedure: { icon: 'ƒ', name: 'Run a procedure', hint: '' },
    call_agent:    { icon: '🤖', name: 'Ask an AI agent', hint: '' }
  };
  var MODES = [
    ['value', 'Set to…'], ['record', 'Copy from this record’s field…'], ['target', 'Copy from the target row’s field…'],
    ['stage', 'The stage it moved to…'], ['actor', 'The user who acted…'], ['now', 'Current date & time'], ['today', 'Today’s date'],
    ['increment', 'Add to current value…'], ['decrement', 'Subtract from current value…'], ['append', 'Append text…'],
    ['calc', 'Calculate…'], ['null', 'Clear (empty)']
  ];
  var CREATE_MODES = ['value', 'record', 'stage', 'actor', 'now', 'today', 'calc', 'null'];
  var CTX_FIELDS = ['actor.role', 'actor.name', 'actor.id', 'comment', 'from_stage', 'to_stage', 'now', 'today'];

  /* ── path helpers (state is plain JSON; paths look like "a.0.target.table") ── */
  function split(p) { return String(p).split('.').map(function (x) { return /^\d+$/.test(x) ? parseInt(x, 10) : x; }); }
  function getAt(root, path) { var o = root; path = Array.isArray(path) ? path : split(path); for (var i = 0; i < path.length; i++) { if (o == null) return undefined; o = o[path[i]]; } return o; }
  function setAt(root, path, val) {
    path = Array.isArray(path) ? path : split(path);
    var o = root;
    for (var i = 0; i < path.length - 1; i++) {
      if (o[path[i]] == null) o[path[i]] = typeof path[i + 1] === 'number' ? [] : {};
      o = o[path[i]];
    }
    o[path[path.length - 1]] = val;
  }
  function removeAt(root, path) {
    path = split(path);
    var parent = getAt(root, path.slice(0, -1)), k = path[path.length - 1];
    if (Array.isArray(parent)) parent.splice(k, 1); else if (parent) delete parent[k];
  }
  function clone(x) { return x == null ? x : JSON.parse(JSON.stringify(x)); }

  /* ── condition model ── */
  function parseCond(raw) {
    if (raw == null || raw === '') return { match: 'all', rules: [] };
    if (typeof raw === 'object') return raw.rules ? clone(raw) : { match: 'all', rules: [] };
    var s = String(raw).trim();
    if (!s) return { match: 'all', rules: [] };
    if (s[0] === '{') { try { var d = JSON.parse(s); if (d && d.rules) return d; } catch (e) { /* fall through */ } }
    return { expr: s };
  }
  function cleanCond(c) {
    if (!c) return null;
    if (c.expr !== undefined) return c.expr.trim() ? c.expr.trim() : null;
    var rules = (c.rules || []).map(function (r) {
      if (r.rules) { var g = cleanCond(r); return g; }
      return r.field && String(r.field).trim() ? r : null;
    }).filter(Boolean);
    return rules.length ? { match: c.match === 'any' ? 'any' : 'all', rules: rules } : null;
  }
  function describeCondition(raw) {
    var c = cleanCond(parseCond(raw));
    if (!c) return '';
    if (typeof c === 'string') return c;
    function d(g) {
      return g.rules.map(function (r) {
        if (r.rules) return '(' + d(r) + ')';
        var op = (OPS.filter(function (o) { return o[0] === r.op; })[0] || [r.op, r.op])[1];
        return r.field + ' ' + op + (r.op === 'empty' || r.op === 'not_empty' ? '' : ' ' + (r.value == null ? '' : r.value) + (r.op === 'between' ? ' and ' + (r.value2 || '') : ''));
      }).join(g.match === 'any' ? ' or ' : ' and ');
    }
    return d(c);
  }

  /* ── client mirror of NuWfRules ── */
  function lookup(path, ctx) {
    path = String(path).trim();
    var dot = path.indexOf('.');
    if (dot > 0) {
      var root = path.slice(0, dot), rest = path.slice(dot + 1);
      if (['record', 'meta', 'actor', 'instance', 'target'].indexOf(root) >= 0) return ctx[root] ? ctx[root][rest] : undefined;
    }
    if (ctx.record && path in ctx.record) return ctx.record[path];
    if (ctx.meta && path in ctx.meta) return ctx.meta[path];
    return ctx[path];
  }
  function compare(a, op, e, e2) {
    a = a == null ? '' : String(a); e = e == null ? '' : String(e);
    var num = a !== '' && e !== '' && !isNaN(a) && !isNaN(e), na = parseFloat(a), ne = parseFloat(e);
    switch (op) {
      case '=': return num ? na === ne : a.toLowerCase() === e.toLowerCase();
      case '!=': return num ? na !== ne : a.toLowerCase() !== e.toLowerCase();
      case '>': return num ? na > ne : a > e;
      case '>=': return num ? na >= ne : a >= e;
      case '<': return num ? na < ne : a < e;
      case '<=': return num ? na <= ne : a <= e;
      case 'contains': return e === '' || a.toLowerCase().indexOf(e.toLowerCase()) >= 0;
      case 'not_contains': return e !== '' && a.toLowerCase().indexOf(e.toLowerCase()) < 0;
      case 'starts_with': return a.toLowerCase().indexOf(e.toLowerCase()) === 0;
      case 'ends_with': return a.toLowerCase().slice(-e.length) === e.toLowerCase();
      case 'in': case 'not_in':
        var hit = e.split(',').map(function (x) { return x.trim().toLowerCase(); }).indexOf(a.toLowerCase()) >= 0;
        return op === 'in' ? hit : !hit;
      case 'between': return num && !isNaN(e2) && na >= ne && na <= parseFloat(e2);
      case 'empty': return a.trim() === '';
      case 'not_empty': return a.trim() !== '';
    }
    return false;
  }
  function evalGroup(g, ctx) {
    var res = (g.rules || []).map(function (r) {
      if (r.rules) return evalGroup(r, ctx);
      if (!r.field) return null;
      var exp = typeof r.value === 'string' ? r.value.replace(/\{\{\s*([\w.]+)\s*\}\}/g, function (m, k) { var v = lookup(k, ctx); return v == null ? '' : v; }) : r.value;
      return compare(lookup(r.field, ctx), r.op || '=', exp, r.value2);
    }).filter(function (x) { return x !== null; });
    if (!res.length) return true;
    return g.match === 'any' ? res.some(Boolean) : res.every(Boolean);
  }
  function evalCondition(raw, data) {
    var c = parseCond(raw);
    var ctx = { record: data || {}, meta: {}, actor: {} };
    if (c.expr !== undefined) {
      if (!c.expr.trim()) return true;
      try { var keys = Object.keys(data || {}); return !!(new Function(keys.join(','), 'return (' + c.expr + ');')).apply(null, keys.map(function (k) { return data[k]; })); }
      catch (e) { return false; }
    }
    return evalGroup(c, ctx);
  }

  /* ── action model (mirror of NuWfActions::normalize) ── */
  function setList(d) {
    if (!d) return [];
    if (Array.isArray(d)) return d.filter(function (s) { return s && s.field; }).map(function (s) { return { field: String(s.field), mode: s.mode || 'value', value: s.value == null ? '' : s.value }; });
    return Object.keys(d).map(function (k) { return { field: k, mode: 'value', value: d[k] == null ? '' : String(d[k]) }; });
  }
  function normalizeActions(raw) {
    if (raw == null || raw === '') return [];
    if (typeof raw === 'string') {
      var t = raw.trim(); if (!t) return [];
      if (t[0] === '{' || t[0] === '[') { try { raw = JSON.parse(t); } catch (e) { return []; } } else raw = { action: t };
    }
    if (raw && Array.isArray(raw.actions)) raw = raw.actions;
    if (raw && (raw.type || raw.action)) raw = [raw];
    if (!Array.isArray(raw)) return [];
    return raw.filter(function (a) { return a && typeof a === 'object' && (a.type || a.action); }).map(function (a) {
      a = clone(a); a.type = a.type || a.action; delete a.action;
      if (a.type === 'update_record') {
        if (!a.target || typeof a.target !== 'object') {
          a.target = a.table && a.record_id ? { mode: 'by_id', table: a.table, id: String(a.record_id) } : (a.table ? { mode: 'linked', table: a.table } : { mode: 'linked' });
        }
        if (a.set === undefined) {
          if (a.data && typeof a.data === 'object') a.set = setList(a.data);
          else a.set = [a.value != null ? { field: a.field || 'status', mode: 'value', value: a.value } : { field: a.field || 'status', mode: 'stage', value: 'code' }];
        } else a.set = setList(a.set);
        (a.scenarios || []).forEach(function (sc) { sc.set = setList(sc.set); });
        if (a.else_set) a.else_set = setList(a.else_set);
        ['table', 'record_id', 'data', 'field', 'value'].forEach(function (k) { delete a[k]; });
      }
      if (a.type === 'create_record') a.data = setList(a.data);
      return a;
    });
  }

  /* ── generic delegated-event editor ── */
  function Editor(container, state, render, onChange) {
    var self = this;
    this.el = container; this.state = state; this.render = render; this.onChange = onChange || function () {};
    this.acts = {}; this.lastInput = null; this.uid = ++uid;
    function fire() { self.onChange(self.state); }
    this.fire = fire;
    function onInput(e) {
      var el = e.target.closest('[data-p]'); if (!el || !container.contains(el)) return;
      if (e.type === 'input' && (el.tagName === 'SELECT' || el.type === 'checkbox' || el.hasAttribute('data-rr'))) return;
      var v = el.type === 'checkbox' ? el.checked : el.value;
      if (el.hasAttribute('data-num') && v !== '') v = Number(v);
      if (v === '' && el.hasAttribute('data-unset')) removeAt(self.state, el.getAttribute('data-p')); else setAt(self.state, el.getAttribute('data-p'), v);
      if (e.type === 'change' && el.hasAttribute('data-rr')) self.refresh();
      fire();
    }
    this.h = {
      input: onInput, change: onInput,
      click: function (e) {
        var b = e.target.closest('[data-act]'); if (!b || !container.contains(b)) return;
        var fn = self.acts[b.getAttribute('data-act')]; if (!fn) return;
        e.preventDefault();
        if (fn(b.getAttribute('data-p'), b) !== false) { self.refresh(); fire(); }
      },
      focusin: function (e) { if (e.target.classList && e.target.classList.contains('wfb-t')) self.lastInput = e.target; }
    };
    ['input', 'change', 'click', 'focusin'].forEach(function (t) { container.addEventListener(t, self.h[t]); });
    this.refresh();
  }
  Editor.prototype.refresh = function () {
    var active = document.activeElement, focusKey = null, caret = null;
    if (active && this.el.contains(active) && active.getAttribute && active.getAttribute('data-p')) { focusKey = active.getAttribute('data-p'); try { caret = active.selectionStart; } catch (e) { /* not text */ } }
    var lists = {};
    var ctx = { lists: lists, dl: function (key, names) { if (names) lists[key] = names; return 'wfbdl' + key.replace(/[^a-z0-9]/gi, '_') + '_' + this.id; }, id: this.uid };
    var html = this.render(this.state, ctx);
    var dls = Object.keys(lists).map(function (k) { return '<datalist id="' + ctx.dl(k, lists[k]) + '">' + (lists[k] || []).map(function (n) { return '<option value="' + esc(n) + '">'; }).join('') + '</datalist>'; }).join('');
    this.el.innerHTML = html + dls;
    if (focusKey) {
      var again = this.el.querySelector('[data-p="' + focusKey + '"]');
      if (again) { again.focus(); try { if (caret != null) again.setSelectionRange(caret, caret); } catch (e) { /* ignore */ } }
    }
  };
  Editor.prototype.destroy = function () {
    var self = this; ['input', 'change', 'click', 'focusin'].forEach(function (t) { self.el.removeEventListener(t, self.h[t]); }); self.el.innerHTML = '';
  };

  /* ── condition editor ── */
  function condHtml(p, c, ctx, depth, fields) {
    if (!c || typeof c !== 'object') c = { match: 'all', rules: [] };
    var fl = ctx.dl('f:' + fields.key, fields.names);
    var h = '<div class="wfb-cond wfb-depth' + depth + '">';
    h += '<div class="wfb-cond-head">Match <select class="nu-input wfb-mini" data-p="' + p + '.match"><option value="all"' + (c.match !== 'any' ? ' selected' : '') + '>ALL</option><option value="any"' + (c.match === 'any' ? ' selected' : '') + '>ANY</option></select> of these:</div>';
    (c.rules || []).forEach(function (r, i) {
      var rp = p + '.rules.' + i;
      if (r.rules) {
        h += '<div class="wfb-subgroup">' + condHtml(rp, r, ctx, depth + 1, fields) + '<button type="button" class="wfb-x" data-act="rm" data-p="' + rp + '" title="Remove group">×</button></div>';
        return;
      }
      var op = r.op || '=', noVal = op === 'empty' || op === 'not_empty';
      h += '<div class="wfb-rule">' +
        '<input class="nu-input wfb-f wfb-t" list="' + fl + '" data-p="' + rp + '.field" value="' + esc(r.field || '') + '" placeholder="field">' +
        '<select class="nu-input wfb-op" data-p="' + rp + '.op" data-rr>' + OPS.map(function (o) { return '<option value="' + o[0] + '"' + (o[0] === op ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select>' +
        (noVal ? '<span class="wfb-v wfb-muted">—</span>' :
          '<input class="nu-input wfb-v wfb-t" data-p="' + rp + '.value" value="' + esc(r.value == null ? '' : r.value) + '" placeholder="' + (op === 'in' || op === 'not_in' ? 'a, b, c' : 'value or {{record.field}}') + '">' +
          (op === 'between' ? '<span class="wfb-and">and</span><input class="nu-input wfb-v wfb-t" data-p="' + rp + '.value2" value="' + esc(r.value2 == null ? '' : r.value2) + '" placeholder="to">' : '')) +
        '<button type="button" class="wfb-x" data-act="rm" data-p="' + rp + '" title="Remove rule">×</button></div>';
    });
    h += '<div class="wfb-cond-foot"><button type="button" class="wfb-link" data-act="add-rule" data-p="' + p + '">＋ Add rule</button>' +
         (depth < 2 ? '<button type="button" class="wfb-link" data-act="add-group" data-p="' + p + '">＋ Add group</button>' : '') + '</div></div>';
    return h;
  }
  function addCondActs(ed) {
    ed.acts['add-rule'] = function (p) { var g = getAt(ed.state, p); if (!g) { setAt(ed.state, p, { match: 'all', rules: [] }); g = getAt(ed.state, p); } g.rules = g.rules || []; g.rules.push({ field: '', op: '=', value: '' }); };
    ed.acts['add-group'] = function (p) { var g = getAt(ed.state, p); g.rules = g.rules || []; g.rules.push({ match: 'any', rules: [{ field: '', op: '=', value: '' }] }); };
    ed.acts.rm = function (p) { removeAt(ed.state, p); };
  }

  function mountConditions(el, opts) {
    opts = opts || {};
    var fields = { key: 'cond', names: (opts.fields || []).concat(CTX_FIELDS) };
    var state = { c: parseCond(opts.value) };
    var ed = new Editor(el, state, function (s, ctx) {
      if (s.c.expr !== undefined) {
        return '<div class="wfb-cond"><div class="wfb-note">This step uses an advanced expression. <button type="button" class="wfb-link" data-act="to-rules">Switch to the visual rule builder</button> (clears the expression).</div>' +
          '<input class="nu-input wfb-mono wfb-t" data-p="c.expr" value="' + esc(s.c.expr) + '" placeholder="amount > 1000 && dept == \'sales\'"></div>';
      }
      var h = condHtml('c', s.c, ctx, 0, fields);
      if (!(s.c.rules || []).length) h = '<div class="wfb-empty">No rules — this always passes.</div>' + h;
      return h;
    }, function () { if (opts.onChange) opts.onChange(api.getValue()); });
    addCondActs(ed);
    ed.acts['to-rules'] = function () { ed.state.c = { match: 'all', rules: [] }; };
    var api = {
      getValue: function () { return cleanCond(ed.state.c); },
      setValue: function (v) { ed.state.c = parseCond(v); ed.refresh(); },
      setFields: function (names) { fields.names = (names || []).concat(CTX_FIELDS); ed.refresh(); },
      destroy: function () { ed.destroy(); }
    };
    return api;
  }

  /* ── action editor ── */
  function mountActions(el, opts) {
    opts = opts || {};
    var meta = { fields: opts.fields || [], tableName: opts.tableName || '', schema: [], writable: [] };
    var state = { a: normalizeActions(opts.value), json: false, jsonErr: '', preview: null, previewing: false, recId: '' };
    var api;

    function tables() { return meta.writable; }
    function colsOf(t) { var x = meta.schema.filter(function (s) { return s.name === t; })[0]; return x ? x.columns.map(function (c) { return c.name; }) : []; }
    function targetTable(a) { var t = a.target || {}; return t.mode === 'linked' || !t.mode ? (t.table || meta.tableName) : t.table; }

    function opt(list, cur) { return list.map(function (o) { var v = Array.isArray(o) ? o[0] : o, l = Array.isArray(o) ? o[1] : o; return '<option value="' + esc(v) + '"' + (v === cur ? ' selected' : '') + '>' + esc(l) + '</option>'; }).join(''); }
    function text(p, v, ph, extra) { return '<input class="nu-input wfb-t ' + (extra || '') + '" data-p="' + p + '" value="' + esc(v == null ? '' : v) + '" placeholder="' + esc(ph || '') + '">'; }
    function field(label, inner, cls) { return '<label class="wfb-fld ' + (cls || '') + '"><span>' + label + '</span>' + inner + '</label>'; }

    function setRows(sp, sets, ctx, tableKey, allowedModes) {
      var colList = ctx.dl('cols:' + tableKey, colsOf(tableKey));
      var recList = ctx.dl('rec', meta.fields);
      var h = '<div class="wfb-sets">';
      (sets || []).forEach(function (s, i) {
        var rp = sp + '.' + i, mode = s.mode || 'value';
        var modes = MODES.filter(function (m) { return !allowedModes || allowedModes.indexOf(m[0]) >= 0; });
        var val = '';
        if (mode === 'value' || mode === 'append') val = text(rp + '.value', s.value, mode === 'append' ? 'text to add' : 'new value or {{record.field}}');
        else if (mode === 'record') val = '<input class="nu-input wfb-t" list="' + recList + '" data-p="' + rp + '.value" value="' + esc(s.value || '') + '" placeholder="field of this record">';
        else if (mode === 'target') val = '<input class="nu-input wfb-t" list="' + colList + '" data-p="' + rp + '.value" value="' + esc(s.value || '') + '" placeholder="column of the target row">';
        else if (mode === 'stage') val = '<select class="nu-input" data-p="' + rp + '.value"><option value="code"' + (s.value !== 'name' ? ' selected' : '') + '>stage code</option><option value="name"' + (s.value === 'name' ? ' selected' : '') + '>stage name</option></select>';
        else if (mode === 'actor') val = '<select class="nu-input" data-p="' + rp + '.value"><option value="name"' + (s.value !== 'id' ? ' selected' : '') + '>their name</option><option value="id"' + (s.value === 'id' ? ' selected' : '') + '>their user id</option></select>';
        else if (mode === 'increment' || mode === 'decrement') val = text(rp + '.value', s.value, 'amount (default 1)');
        else if (mode === 'calc') val = text(rp + '.value', s.value, 'e.g. target.total + record.amount', 'wfb-mono');
        else val = '<span class="wfb-muted wfb-grow">—</span>';
        h += '<div class="wfb-set"><input class="nu-input wfb-col" list="' + colList + '" data-p="' + rp + '.field" value="' + esc(s.field || '') + '" placeholder="column">' +
             '<span class="wfb-arrow">→</span><select class="nu-input wfb-mode" data-p="' + rp + '.mode" data-rr>' + opt(modes, mode) + '</select>' + val +
             '<button type="button" class="wfb-x" data-act="rm" data-p="' + rp + '" title="Remove">×</button></div>';
      });
      h += '<button type="button" class="wfb-link" data-act="add-set" data-p="' + sp + '">＋ Add a field to change</button></div>';
      return h;
    }

    function whenBlock(p, a, ctx) {
      var f = { key: 'when', names: meta.fields.concat(CTX_FIELDS) };
      if (a.when && typeof a.when === 'object') {
        return '<div class="wfb-when"><div class="wfb-when-head"><b>Only run this step if…</b><button type="button" class="wfb-link" data-act="rm-when" data-p="' + p + '">remove condition</button></div>' + condHtml(p + '.when', a.when, ctx, 0, f) + '</div>';
      }
      return '<button type="button" class="wfb-link" data-act="add-when" data-p="' + p + '">＋ Only run this step when a condition is true…</button>';
    }

    function updateBody(p, a, ctx) {
      a.target = a.target || { mode: 'linked' };
      var t = a.target, mode = t.mode || 'linked', tbl = targetTable(a) || '';
      var tl = ctx.dl('tables', tables());
      var h = '<div class="wfb-block"><div class="wfb-block-title">Which record(s)?</div><div class="wfb-seg">' +
        [['linked', 'This workflow’s record'], ['by_id', 'A specific record (by id)'], ['by_match', 'Records matching a field']].map(function (m) {
          return '<button type="button" class="' + (mode === m[0] ? 'is-active' : '') + '" data-act="target-mode" data-p="' + p + '" data-v="' + m[0] + '">' + m[1] + '</button>';
        }).join('') + '</div>';
      if (mode === 'linked') {
        h += '<p class="wfb-note">Updates the record this workflow instance is attached to' + (meta.tableName ? ' (table <code>' + esc(meta.tableName) + '</code>)' : '') + '.</p>';
      } else if (mode === 'by_id') {
        h += '<div class="wfb-row">' + field('Table', '<input class="nu-input" list="' + tl + '" data-p="' + p + '.target.table" data-rr value="' + esc(t.table || '') + '" placeholder="table">') +
             field('Record id', text(p + '.target.id', t.id, '{{record.customer_id}} or 42')) +
             field('Match on column', '<input class="nu-input" list="' + ctx.dl('cols:' + tbl, colsOf(tbl)) + '" data-p="' + p + '.target.id_field" data-unset value="' + esc(t.id_field || '') + '" placeholder="(primary key)">') + '</div>';
      } else {
        h += '<div class="wfb-row">' + field('Table', '<input class="nu-input" list="' + tl + '" data-p="' + p + '.target.table" data-rr value="' + esc(t.table || '') + '" placeholder="table">') +
             field('At most', '<input type="number" min="1" max="1000" class="nu-input" data-p="' + p + '.target.limit" data-num value="' + esc(t.limit == null ? 100 : t.limit) + '">', 'wfb-narrow') + '</div>' +
             '<div class="wfb-sub">Update rows where…</div>' + condHtml(p + '.target.match', t.match || { match: 'all', rules: [] }, ctx, 0, { key: 'cols:' + tbl, names: colsOf(tbl) }) +
             '<p class="wfb-note">Values can use <code>{{record.field}}</code> to match against this workflow’s record. At least one rule is required.</p>';
      }
      h += '</div>';

      var hasSc = Array.isArray(a.scenarios) && a.scenarios.length;
      h += '<div class="wfb-block"><div class="wfb-block-title">What should change?</div>';
      if (!hasSc) {
        h += setRows(p + '.set', a.set, ctx, tbl) + '<button type="button" class="wfb-link" data-act="make-scenarios" data-p="' + p + '">⚡ Make this conditional — different changes in different situations</button>';
      } else {
        var sf = { key: 'sc:' + tbl, names: meta.fields.concat(colsOf(tbl).map(function (c) { return 'target.' + c; })).concat(CTX_FIELDS) };
        a.scenarios.forEach(function (sc, j) {
          var sp = p + '.scenarios.' + j;
          h += '<div class="wfb-scn"><div class="wfb-scn-head"><b>Scenario ' + (j + 1) + '</b>' + text(sp + '.label', sc.label, 'name (optional)', 'wfb-scn-name') +
               '<button type="button" class="wfb-x" data-act="rm" data-p="' + sp + '" title="Remove scenario">×</button></div>' +
               '<div class="wfb-sub">If…</div>' + condHtml(sp + '.when', sc.when || { match: 'all', rules: [] }, ctx, 0, sf) +
               '<div class="wfb-sub">Then change</div>' + setRows(sp + '.set', sc.set, ctx, tbl) + '</div>';
        });
        h += '<button type="button" class="wfb-link" data-act="add-scenario" data-p="' + p + '">＋ Add scenario</button>' +
             '<div class="wfb-scn wfb-else"><div class="wfb-scn-head"><b>Otherwise</b><span class="wfb-muted">(when no scenario matches)</span></div>' + setRows(p + '.else_set', a.else_set || [], ctx, tbl) + '</div>' +
             '<div class="wfb-row wfb-foot">' + field('When several scenarios match', '<select class="nu-input" data-p="' + p + '.scenario_mode"><option value="first"' + (a.scenario_mode !== 'all' ? ' selected' : '') + '>use the first one only</option><option value="all"' + (a.scenario_mode === 'all' ? ' selected' : '') + '>apply all of them</option></select>') +
             '<button type="button" class="wfb-link" data-act="rm-scenarios" data-p="' + p + '">remove scenarios</button></div>' +
             '<p class="wfb-note">In a scenario condition, use <code>target.column</code> for the row being changed and plain field names for this workflow’s record.</p>';
      }
      return h + '</div>';
    }

    function bodyHtml(p, a, ctx) {
      switch (a.type) {
        case 'update_record': return updateBody(p, a, ctx);
        case 'create_record':
          var tl = ctx.dl('tables', tables());
          return '<div class="wfb-block">' + field('Add a row to table', '<input class="nu-input" list="' + tl + '" data-p="' + p + '.table" data-rr value="' + esc(a.table || '') + '" placeholder="table">') +
                 '<div class="wfb-sub">With these values</div>' + setRows(p + '.data', a.data, ctx, a.table || '', CREATE_MODES) + '</div>';
        case 'send_email':
          return '<div class="wfb-block"><div class="wfb-row">' + field('To', text(p + '.to', a.to, 'name@example.com, {{record.email}}  (blank = person who started it)')) + '</div>' +
                 '<div class="wfb-row">' + field('Subject', text(p + '.subject', a.subject, 'Approved: {{record.title}}')) + '</div>' +
                 field('Message (HTML allowed)', '<textarea class="nu-input wfb-t" rows="5" data-p="' + p + '.body" placeholder="Hi, {{actor_name}} moved {{wf_name}} to {{to_stage}}.">' + esc(a.body || '') + '</textarea>') + '</div>';
        case 'notify_user':
          return '<div class="wfb-block"><div class="wfb-row">' + field('User id', text(p + '.user_id', a.user_id, '{{record.assigned_to}} (blank = person who started it)')) + '</div>' +
                 field('Message', '<textarea class="nu-input wfb-t" rows="3" data-p="' + p + '.message" placeholder="Workflow {{wf_name}} moved to {{to_stage}}">' + esc(a.message || '') + '</textarea>') + '</div>';
        case 'http_request': case 'call_webhook':
          return '<div class="wfb-block"><div class="wfb-row">' + field('Method', '<select class="nu-input" data-p="' + p + '.method">' + opt(['POST', 'GET', 'PUT', 'PATCH', 'DELETE'], a.method || 'POST') + '</select>', 'wfb-narrow') +
                 field('URL', text(p + '.url', a.url, 'https://example.com/hook')) + '</div>' +
                 field('Body (JSON; leave blank for the standard payload)', '<textarea class="nu-input wfb-t wfb-mono" rows="4" data-p="' + p + '._body" placeholder=\'{"id":"{{record.id}}","status":"{{to_stage}}"}\'>' + esc(typeof a.body === 'string' ? a.body : (a.body ? JSON.stringify(a.body, null, 2) : (a.payload ? JSON.stringify(a.payload, null, 2) : ''))) + '</textarea>') + '</div>';
        case 'run_procedure':
          return '<div class="wfb-block"><div class="wfb-row">' + field('Procedure code', text(p + '.procedure', a.procedure || a.code, 'my_procedure')) + '</div>' +
                 field('Parameters (JSON)', '<textarea class="nu-input wfb-t wfb-mono" rows="3" data-p="' + p + '._params" placeholder=\'{"id":"{{record.id}}"}\'>' + esc(a.params ? JSON.stringify(a.params, null, 2) : '') + '</textarea>') + '</div>';
        case 'call_agent':
          return '<div class="wfb-block"><div class="wfb-row">' + field('Agent id', text(p + '.agent_id', a.agent_id, 'agent id')) + '</div>' +
                 field('Instruction', '<textarea class="nu-input wfb-t" rows="3" data-p="' + p + '.prompt" placeholder="Review record #{{record.id}} and summarise it">' + esc(a.prompt || '') + '</textarea>') + '</div>';
      }
      return '<p class="wfb-note">Unknown action type “' + esc(a.type) + '”. Use Advanced JSON to edit it.</p>';
    }

    function previewHtml(pv) {
      if (state.previewing) return '<div class="wfb-prev"><span class="nu-sx-spin"></span> Checking…</div>';
      if (!pv) return '';
      if (pv.error) return '<div class="wfb-prev wfb-prev-err">' + esc(pv.error) + '</div>';
      var h = '<div class="wfb-prev"><div class="wfb-prev-title">Preview — nothing was changed' + (pv.record_id ? ' · using ' + esc(pv.table) + ' #' + esc(pv.record_id) : ' · no sample record found') + '</div>';
      if (!pv.results.length) h += '<p class="wfb-muted">No steps to run.</p>';
      var badge = { preview: ['would run', 'ok'], skipped: ['skipped', 'skip'], error: ['problem', 'bad'], ok: ['ok', 'ok'] };
      pv.results.forEach(function (r, i) {
        var b = badge[r.status] || [r.status, 'skip'];
        h += '<div class="wfb-res"><span class="wfb-badge is-' + b[1] + '">' + b[0] + '</span><b>' + esc((TYPES[r.type] || {}).name || r.type) + (r.label ? ' — ' + esc(r.label) : '') + '</b><div class="wfb-res-msg">' + esc(r.message) + '</div>';
        (r.changes || []).slice(0, 5).forEach(function (c) {
          var keys = Object.keys(c.after || {});
          h += '<table class="wfb-diff"><caption>' + esc(c.table) + (c.id != null ? ' #' + esc(c.id) : ' (new row)') + '</caption>' +
               keys.map(function (k) { return '<tr><th>' + esc(k) + '</th><td class="wfb-old">' + esc(c.before && c.before[k] != null ? c.before[k] : '∅') + '</td><td>→</td><td class="wfb-new">' + esc(c.after[k] == null ? '∅' : c.after[k]) + '</td></tr>'; }).join('') + '</table>';
        });
        h += '</div>';
      });
      return h + '</div>';
    }

    var ed = new Editor(el, state, function (s, ctx) {
      var recList = ctx.dl('rec', meta.fields);
      var phs = meta.fields.map(function (f) { return 'record.' + f; }).concat(['actor_name', 'comment', 'to_stage', 'from_stage', 'wf_name', 'wfi_id', 'now', 'today']);
      var h = '<div class="wfb-actions">';
      if (!s.a.length) h += '<div class="wfb-empty">No actions yet. Add one to update records, send emails, call webhooks…</div>';
      s.a.forEach(function (a, i) {
        var p = 'a.' + i, T = TYPES[a.type] || { icon: '?', name: a.type }, off = a.enabled === false;
        h += '<section class="wfb-act' + (off ? ' is-off' : '') + '"><header class="wfb-act-head"><span class="wfb-ico">' + T.icon + '</span>' +
             '<select class="nu-input wfb-type" data-p="' + p + '.type" data-rr>' + Object.keys(TYPES).map(function (k) { return '<option value="' + k + '"' + ((a.type === k || (a.type === 'call_webhook' && k === 'http_request')) ? ' selected' : '') + '>' + TYPES[k].name + '</option>'; }).join('') + '</select>' +
             text(p + '.label', a.label, 'note to yourself (optional)', 'wfb-label') +
             '<label class="wfb-on" title="Switch this step on or off"><input type="checkbox" data-p="' + p + '.enabled" ' + (off ? '' : 'checked') + '> on</label>' +
             '<button type="button" class="wfb-ib" data-act="up" data-p="' + p + '" title="Move up"' + (i === 0 ? ' disabled' : '') + '>↑</button>' +
             '<button type="button" class="wfb-ib" data-act="down" data-p="' + p + '" title="Move down"' + (i === s.a.length - 1 ? ' disabled' : '') + '>↓</button>' +
             '<button type="button" class="wfb-x" data-act="rm" data-p="' + p + '" title="Delete step">×</button></header>' +
             '<div class="wfb-act-body">' + bodyHtml(p, a, ctx) + whenBlock(p, a, ctx) +
             '<div class="wfb-row wfb-foot">' + field('If this step fails', '<select class="nu-input" data-p="' + p + '.on_error"><option value="continue"' + (a.on_error !== 'stop' ? ' selected' : '') + '>carry on with the next step</option><option value="stop"' + (a.on_error === 'stop' ? ' selected' : '') + '>stop the remaining steps</option></select>') + '</div></div></section>';
      });
      h += '<div class="wfb-addbar"><span>＋ Add step:</span>' + Object.keys(TYPES).filter(function (k) { return k !== 'call_webhook'; }).map(function (k) { return '<button type="button" class="wfb-chip" data-act="add" data-p="' + k + '">' + TYPES[k].icon + ' ' + TYPES[k].name + '</button>'; }).join('') + '</div>';
      h += '<div class="wfb-tools"><select class="nu-input wfb-ph" data-ph><option value="">Insert placeholder…</option>' + phs.map(function (x) { return '<option value="{{' + esc(x) + '}}">{{' + esc(x) + '}}</option>'; }).join('') + '</select>' +
           '<span class="wfb-grow"></span><input class="nu-input wfb-recid" data-recid value="' + esc(s.recId) + '" placeholder="record id (blank = latest)">' +
           '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" data-act="preview">👁 Preview on a real record</button>' +
           '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" data-act="json">{ } ' + (s.json ? 'Hide JSON' : 'Advanced JSON') + '</button></div>';
      if (s.json) h += '<textarea class="nu-input wfb-mono wfb-json" data-json rows="12">' + esc(JSON.stringify(s.a, null, 2)) + '</textarea>' + (s.jsonErr ? '<div class="wfb-prev wfb-prev-err">' + esc(s.jsonErr) + '</div>' : '');
      return h + previewHtml(s.preview) + '</div>';
    }, function () { if (opts.onChange) opts.onChange(api.getValue()); });

    addCondActs(ed);
    ed.acts.add = function (type) {
      var a = { type: type };
      if (type === 'update_record') { a.target = { mode: 'linked' }; a.set = [{ field: '', mode: 'value', value: '' }]; }
      if (type === 'create_record') { a.table = ''; a.data = [{ field: '', mode: 'value', value: '' }]; }
      if (type === 'http_request') { a.method = 'POST'; a.url = ''; }
      ed.state.a.push(a);
    };
    ed.acts.up = function (p) { var i = split(p)[1]; if (i > 0) { var x = ed.state.a.splice(i, 1)[0]; ed.state.a.splice(i - 1, 0, x); } };
    ed.acts.down = function (p) { var i = split(p)[1]; if (i < ed.state.a.length - 1) { var x = ed.state.a.splice(i, 1)[0]; ed.state.a.splice(i + 1, 0, x); } };
    ed.acts['add-set'] = function (p) { var l = getAt(ed.state, p); if (!l) { setAt(ed.state, p, []); l = getAt(ed.state, p); } l.push({ field: '', mode: 'value', value: '' }); };
    ed.acts['target-mode'] = function (p, b) {
      var a = getAt(ed.state, p), m = b.getAttribute('data-v'), old = a.target || {};
      a.target = m === 'linked' ? { mode: 'linked' } : (m === 'by_id' ? { mode: 'by_id', table: old.table || '', id: old.id || '' } : { mode: 'by_match', table: old.table || '', match: old.match || { match: 'all', rules: [{ field: '', op: '=', value: '' }] }, limit: old.limit || 100 });
    };
    ed.acts['add-when'] = function (p) { getAt(ed.state, p).when = { match: 'all', rules: [{ field: '', op: '=', value: '' }] }; };
    ed.acts['rm-when'] = function (p) { delete getAt(ed.state, p).when; };
    ed.acts['make-scenarios'] = function (p) { var a = getAt(ed.state, p); a.scenarios = [{ label: '', when: { match: 'all', rules: [{ field: '', op: '=', value: '' }] }, set: a.set && a.set.length ? a.set : [{ field: '', mode: 'value', value: '' }] }]; a.set = []; a.else_set = []; };
    ed.acts['add-scenario'] = function (p) { getAt(ed.state, p).scenarios.push({ label: '', when: { match: 'all', rules: [{ field: '', op: '=', value: '' }] }, set: [{ field: '', mode: 'value', value: '' }] }); };
    ed.acts['rm-scenarios'] = function (p) { var a = getAt(ed.state, p); a.set = (a.scenarios[0] && a.scenarios[0].set) || []; delete a.scenarios; delete a.else_set; delete a.scenario_mode; };
    ed.acts.json = function () { ed.state.json = !ed.state.json; ed.state.jsonErr = ''; return true; };
    ed.acts.preview = function () {
      if (!opts.preview) return false;
      ed.state.previewing = true; ed.state.preview = null; ed.refresh();
      Promise.resolve(opts.preview(api.getValue() || [], ed.state.recId)).then(function (r) { ed.state.preview = r; }, function (e) { ed.state.preview = { error: e && e.message ? e.message : String(e) }; })
        .then(function () { ed.state.previewing = false; ed.refresh(); });
      return false;
    };

    // Inputs that are not part of the JSON state (placeholders, preview record id, raw JSON, body/params text).
    el.addEventListener('change', function (e) {
      var t = e.target;
      if (t.hasAttribute('data-ph')) {
        if (t.value && ed.lastInput && document.body.contains(ed.lastInput)) {
          var i = ed.lastInput, s = i.selectionStart == null ? i.value.length : i.selectionStart;
          i.value = i.value.slice(0, s) + t.value + i.value.slice(i.selectionEnd == null ? s : i.selectionEnd);
          i.focus(); i.setSelectionRange(s + t.value.length, s + t.value.length);
          i.dispatchEvent(new Event('input', { bubbles: true }));
        }
        t.value = '';
      } else if (t.hasAttribute('data-json')) {
        try { ed.state.a = normalizeActions(JSON.parse(t.value || '[]')); ed.state.jsonErr = ''; if (opts.onChange) opts.onChange(api.getValue()); }
        catch (err) { ed.state.jsonErr = 'Invalid JSON: ' + err.message; }
        ed.refresh();
      }
    });
    el.addEventListener('input', function (e) {
      var t = e.target;
      if (t.hasAttribute('data-recid')) ed.state.recId = t.value;
    });

    // Fields stored as raw text in the UI (_body, _params) are converted back to the JSON the engine expects.
    function finalize(list) {
      return list.map(function (a) {
        a = clone(a);
        if (a._body !== undefined) { var b = String(a._body).trim(); delete a.body; delete a.payload; if (b) { try { a.body = JSON.parse(b); } catch (e) { a.body = b; } } delete a._body; }
        if (a._params !== undefined) { var q = String(a._params).trim(); if (q) { try { a.params = JSON.parse(q); } catch (e) { /* keep previous */ } } else delete a.params; delete a._params; }
        if (a.enabled === true) delete a.enabled;
        if (a.on_error === 'continue') delete a.on_error;
        if (a.label === '') delete a.label;
        if (a.type === 'update_record') {
          a.set = (a.set || []).filter(function (s) { return s.field; });
          if (a.scenarios) { a.scenarios.forEach(function (sc) { sc.set = (sc.set || []).filter(function (s) { return s.field; }); sc.when = cleanCond(sc.when) || undefined; if (!sc.when) delete sc.when; }); a.else_set = (a.else_set || []).filter(function (s) { return s.field; }); }
          if (a.target && a.target.mode === 'by_match') a.target.match = cleanCond(a.target.match) || { match: 'all', rules: [] };
        }
        if (a.type === 'create_record') a.data = (a.data || []).filter(function (s) { return s.field; });
        if (a.when !== undefined) { var w = cleanCond(a.when); if (w) a.when = w; else delete a.when; }
        return a;
      });
    }

    api = {
      getValue: function () { var v = finalize(ed.state.a); return v.length ? v : null; },
      setValue: function (v) { ed.state.a = normalizeActions(v); ed.state.preview = null; ed.refresh(); },
      setContext: function (c) {
        if (c.fields) meta.fields = c.fields;
        if (c.tableName !== undefined) meta.tableName = c.tableName;
        ed.refresh();
      },
      destroy: function () { ed.destroy(); }
    };

    if (opts.schema) {
      Promise.resolve(opts.schema()).then(function (tables) {
        meta.schema = tables || [];
        meta.writable = meta.schema.filter(function (t) { return t.writable !== false; }).map(function (t) { return t.name; });
        ed.refresh();
      }, function () { /* free-text table names still work */ });
    }
    return api;
  }

  global.NuWfBuilder = { mountConditions: mountConditions, mountActions: mountActions, normalizeActions: normalizeActions, describeCondition: describeCondition, evalCondition: evalCondition, parseCond: parseCond, cleanCond: cleanCond, OPS: OPS };
})(window);
