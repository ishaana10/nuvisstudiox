/**
 * Nuvis StudioX — shared builder components (nu-builders.js)
 * Used by the Query Builder (modules/queries) and the Report Builder (modules/reports).
 *
 *   NuSqlEditor.mount(el, opts)   SQL editor (Ace when available, textarea fallback)
 *   NuSqlEditor.format(sql)       light SQL formatter
 *   NuSchema.load()               cached tables+columns (api/queries.php?action=get_schema)
 *   NuSchema.mount(el, opts)      searchable schema browser
 *   NuResultGrid.render(el, opts) sortable / filterable result table with CSV + copy
 *
 * Styles: assets/css/nu-builders.css (classes namespaced `.nu-sx-*`).
 */
(function (window) {
  'use strict';
  var document = window.document;

  // ── helpers ────────────────────────────────────────────────────────────────
  function esc(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }
  function toast(msg, type) {
    if (window.NuApp && typeof window.NuApp.toast === 'function') window.NuApp.toast(msg, type || 'info');
  }
  function isDark() {
    var t = document.documentElement.getAttribute('data-theme');
    if (t === 'dark') return true;
    if (t === 'light') return false;
    return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
  }
  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return false; });
    }
    var ta = el('textarea'); ta.value = text; ta.style.cssText = 'position:fixed;opacity:0';
    document.body.appendChild(ta); ta.select();
    var ok = false; try { ok = document.execCommand('copy'); } catch (e) { /* ignore */ }
    ta.remove();
    return Promise.resolve(ok);
  }
  function downloadCsv(filename, header, rows) {
    var q = function (v) { return '"' + String(v === null || v === undefined ? '' : v).replace(/"/g, '""') + '"'; };
    var csv = [header.map(q).join(',')].concat(rows.map(function (r) { return r.map(q).join(','); })).join('\r\n');
    var blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
    var a = el('a'); a.href = URL.createObjectURL(blob); a.download = filename;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
  }

  // ── SQL formatter ──────────────────────────────────────────────────────────
  var CLAUSES = ['SELECT', 'FROM', 'WHERE', 'GROUP BY', 'HAVING', 'ORDER BY', 'LIMIT', 'OFFSET',
    'LEFT OUTER JOIN', 'RIGHT OUTER JOIN', 'LEFT JOIN', 'RIGHT JOIN', 'INNER JOIN', 'CROSS JOIN', 'JOIN'];
  var KEYWORDS = CLAUSES.concat(['AS', 'ON', 'AND', 'OR', 'NOT', 'IN', 'IS', 'NULL', 'LIKE', 'BETWEEN', 'DISTINCT', 'CASE',
    'WHEN', 'THEN', 'ELSE', 'END', 'ASC', 'DESC', 'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'EXISTS', 'UNION', 'ALL']);

  function formatSql(sql) {
    var keep = [];
    // Protect strings, quoted identifiers and comments from rewriting.
    var s = String(sql || '').replace(/'(?:[^'\\]|\\.|'')*'|"(?:[^"\\]|\\.|"")*"|`[^`]*`|--[^\n]*|\/\*[\s\S]*?\*\//g, function (m) {
      keep.push(m); return '\u0000' + (keep.length - 1) + '\u0000';
    });
    s = s.replace(/\s+/g, ' ').trim();
    KEYWORDS.forEach(function (k) {
      s = s.replace(new RegExp('\\b' + k.replace(/ /g, '\\s+') + '\\b', 'gi'), k);
    });
    CLAUSES.forEach(function (k) {
      if (k === 'SELECT') return;
      s = s.replace(new RegExp('\\s*\\b' + k.replace(/ /g, ' ') + '\\b', 'g'), '\n' + k);
    });
    s = s.replace(/\s+(AND|OR)\s+/g, '\n  $1 ');
    s = s.replace(/\n(LEFT|RIGHT|INNER|CROSS)\n?\s*(OUTER\s+)?JOIN/g, '\n$1 $2JOIN');
    return s.replace(/\u0000(\d+)\u0000/g, function (_, i) { return keep[+i]; }).replace(/[ \t]+\n/g, '\n').trim();
  }

  // ── Schema cache ───────────────────────────────────────────────────────────
  var schemaPromise = null;
  var NuSchema = {
    /** Resolves to [{name, columns:[{name,type,key,nullable}]}]; cached until refresh=true. */
    load: function (refresh) {
      if (refresh || !schemaPromise) {
        schemaPromise = fetch('api/queries.php?action=get_schema', { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (d) { if (!d.success) throw new Error(d.error || 'Failed to load schema'); return d.tables || []; })
          .catch(function (e) { schemaPromise = null; throw e; });
      }
      return schemaPromise;
    },

    /**
     * Mount a searchable schema tree.
     * opts: { onPick(name, kind, table), onInsertSelect(table) }  kind = 'table' | 'column'
     */
    mount: function (container, opts) {
      opts = opts || {};
      container.classList.add('nu-sx-schema');
      container.innerHTML = '';
      var head = el('div', 'nu-sx-schema-head');
      var search = el('input', 'nu-input nu-sx-schema-search');
      search.type = 'search'; search.placeholder = 'Search tables & columns…'; search.setAttribute('aria-label', 'Search schema');
      var refresh = el('button', 'nu-sx-icon-btn'); refresh.type = 'button'; refresh.title = 'Reload schema';
      refresh.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>';
      head.appendChild(search); head.appendChild(refresh);
      var list = el('ul', 'nu-sx-tree'); list.setAttribute('role', 'tree');
      container.appendChild(head); container.appendChild(list);

      var tables = [];
      var open = {};

      function render() {
        var q = search.value.trim().toLowerCase();
        list.innerHTML = '';
        var shown = 0;
        tables.forEach(function (t) {
          var tableHit = !q || t.name.toLowerCase().indexOf(q) !== -1;
          var cols = t.columns.filter(function (c) { return !q || tableHit || c.name.toLowerCase().indexOf(q) !== -1; });
          if (!tableHit && !cols.length) return;
          shown++;
          var expanded = !!open[t.name] || (q && !tableHit);
          var li = el('li', 'nu-sx-table' + (expanded ? ' is-open' : ''));
          li.setAttribute('role', 'treeitem'); li.setAttribute('aria-expanded', expanded ? 'true' : 'false');
          var row = el('div', 'nu-sx-table-row'); row.tabIndex = 0;
          row.innerHTML = '<span class="nu-sx-caret" aria-hidden="true">›</span><span class="nu-sx-table-name">' + esc(t.name) + '</span><span class="nu-sx-count">' + t.columns.length + '</span>';
          var ins = el('button', 'nu-sx-mini', 'SELECT'); ins.type = 'button'; ins.title = 'Insert SELECT for ' + t.name;
          ins.addEventListener('click', function (e) { e.stopPropagation(); if (opts.onInsertSelect) opts.onInsertSelect(t.name, t); });
          row.appendChild(ins);
          var toggle = function () { open[t.name] = !open[t.name]; render(); };
          row.addEventListener('click', function () { toggle(); });
          row.addEventListener('dblclick', function () { if (opts.onPick) opts.onPick(t.name, 'table'); });
          row.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); } });
          li.appendChild(row);
          if (expanded) {
            var ul = el('ul', 'nu-sx-cols'); ul.setAttribute('role', 'group');
            cols.forEach(function (c) {
              var ci = el('li', 'nu-sx-col'); ci.tabIndex = 0; ci.setAttribute('role', 'treeitem');
              ci.title = c.name + ' · ' + c.type + (c.nullable ? ' · nullable' : '');
              ci.innerHTML = (c.key === 'PRI' ? '<span class="nu-sx-key" title="Primary key">🔑</span>' : '<span class="nu-sx-key"></span>') +
                '<span class="nu-sx-col-name">' + esc(c.name) + '</span><span class="nu-sx-col-type">' + esc(c.type.replace(/\(.*/, '')) + '</span>';
              ci.addEventListener('click', function () { if (opts.onPick) opts.onPick(c.name, 'column', t.name); });
              ci.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); if (opts.onPick) opts.onPick(c.name, 'column', t.name); } });
              ul.appendChild(ci);
            });
            li.appendChild(ul);
          }
          list.appendChild(li);
        });
        if (!shown) list.appendChild(el('li', 'nu-sx-empty', tables.length ? 'No matches' : 'No tables found'));
      }

      function load(force) {
        list.innerHTML = '<li class="nu-sx-empty">Loading schema…</li>';
        NuSchema.load(force).then(function (t) { tables = t; render(); if (opts.onLoaded) opts.onLoaded(t); })
          .catch(function (e) { list.innerHTML = ''; list.appendChild(el('li', 'nu-sx-empty', 'Could not load schema: ' + e.message)); });
      }
      search.addEventListener('input', render);
      refresh.addEventListener('click', function () { load(true); });
      load(false);
      return { reload: function () { load(true); } };
    }
  };

  // ── SQL editor ─────────────────────────────────────────────────────────────
  var NuSqlEditor = {
    format: formatSql,

    /**
     * opts: { value, placeholder, minLines, onChange(value), onRun(), onSave() }
     * returns { getValue, setValue, insert(text), focus, setSchema(tables), format(), destroy }
     */
    mount: function (container, opts) {
      opts = opts || {};
      container.classList.add('nu-sx-editor');
      container.innerHTML = '';
      var schema = [];
      var api = {};
      var changeCb = function () { if (opts.onChange) opts.onChange(api.getValue()); };

      if (window.ace && window.ace.edit) {
        var host = el('div', 'nu-sx-ace');
        container.appendChild(host);
        window.ace.require('ace/ext/language_tools');
        var ed = window.ace.edit(host);
        ed.setOptions({
          mode: 'ace/mode/sql',
          theme: isDark() ? 'ace/theme/one_dark' : 'ace/theme/chrome',
          fontSize: '13px', tabSize: 2, useSoftTabs: true, showPrintMargin: false,
          enableBasicAutocompletion: true, enableLiveAutocompletion: true,
          highlightActiveLine: true, wrap: true, minLines: opts.minLines || 8, maxLines: 24,
          fontFamily: 'ui-monospace, "Cascadia Code", "Fira Code", Menlo, monospace'
        });
        ed.renderer.setScrollMargin(8, 8);
        if (opts.placeholder) {
          // Ace has no native placeholder; show one while empty.
          var ph = el('div', 'nu-sx-placeholder', opts.placeholder);
          host.appendChild(ph);
          var syncPh = function () { ph.style.display = ed.session.getValue() ? 'none' : ''; };
          ed.on('input', syncPh); setTimeout(syncPh, 0);
        }
        ed.setValue(opts.value || '', -1);
        ed.session.on('change', changeCb);

        var run = function () { if (opts.onRun) opts.onRun(); };
        ed.commands.addCommand({ name: 'nuRun', bindKey: { win: 'Ctrl-Enter', mac: 'Command-Enter' }, exec: run });
        ed.commands.addCommand({ name: 'nuSave', bindKey: { win: 'Ctrl-S', mac: 'Command-S' }, exec: function () { if (opts.onSave) opts.onSave(); } });
        ed.commands.addCommand({ name: 'nuFormat', bindKey: { win: 'Ctrl-Shift-F', mac: 'Command-Shift-F' }, exec: function () { api.format(); } });

        // Table / column completions from the live schema.
        var completer = {
          getCompletions: function (editor, session, pos, prefix, cb) {
            var items = [];
            schema.forEach(function (t) {
              items.push({ caption: t.name, value: t.name, meta: 'table', score: 1000 });
              t.columns.forEach(function (c) { items.push({ caption: c.name, value: c.name, meta: t.name + ' · ' + c.type.replace(/\(.*/, ''), score: 900 }); });
            });
            cb(null, items);
          }
        };
        // Per-editor completer list, so a destroyed editor never leaves a stale schema completer behind.
        var tools = window.ace.require('ace/ext/language_tools');
        ed.completers = [tools.keyWordCompleter, tools.textCompleter, completer].filter(Boolean);

        var obs = new MutationObserver(function () { ed.setTheme(isDark() ? 'ace/theme/one_dark' : 'ace/theme/chrome'); });
        obs.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

        api.getValue = function () { return ed.getValue(); };
        api.setValue = function (v) { ed.setValue(v || '', -1); ed.clearSelection(); };
        api.insert = function (t) { ed.insert(t); ed.focus(); };
        api.focus = function () { ed.focus(); };
        api.destroy = function () { obs.disconnect(); ed.destroy(); container.innerHTML = ''; };
        api.resize = function () { ed.resize(); };
      } else {
        var ta = el('textarea', 'nu-input nu-sx-textarea');
        ta.rows = opts.minLines || 8; ta.spellcheck = false; ta.value = opts.value || '';
        if (opts.placeholder) ta.placeholder = opts.placeholder;
        ta.addEventListener('input', changeCb);
        ta.addEventListener('keydown', function (e) {
          if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); if (opts.onRun) opts.onRun(); }
          else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); if (opts.onSave) opts.onSave(); }
          else if (e.key === 'Tab' && !e.shiftKey) {
            e.preventDefault();
            var s = ta.selectionStart; ta.value = ta.value.slice(0, s) + '  ' + ta.value.slice(ta.selectionEnd);
            ta.selectionStart = ta.selectionEnd = s + 2; changeCb();
          }
        });
        container.appendChild(ta);
        api.getValue = function () { return ta.value; };
        api.setValue = function (v) { ta.value = v || ''; changeCb(); };
        api.insert = function (t) {
          var s = ta.selectionStart; ta.value = ta.value.slice(0, s) + t + ta.value.slice(ta.selectionEnd);
          ta.selectionStart = ta.selectionEnd = s + t.length; ta.focus(); changeCb();
        };
        api.focus = function () { ta.focus(); };
        api.destroy = function () { container.innerHTML = ''; };
        api.resize = function () { /* n/a */ };
      }
      api.setSchema = function (t) { schema = t || []; };
      api.format = function () {
        var before = api.getValue();
        var after = formatSql(before);
        if (after !== before) api.setValue(after);
      };
      return api;
    }
  };

  // ── Result grid ────────────────────────────────────────────────────────────
  var PAGE = 500;

  /**
   * opts: {
   *   columns: [{field,label}] | [string],  rows: [{...}],
   *   meta: { elapsed_ms, truncated, limit, total_available },
   *   filename: 'results.csv', emptyText: '...'
   * }
   */
  function renderGrid(container, opts) {
    opts = opts || {};
    var cols = (opts.columns || []).map(function (c) { return typeof c === 'string' ? { field: c, label: c } : { field: c.field, label: c.label || c.field }; });
    var all = opts.rows || [];
    var meta = opts.meta || {};
    var sort = { field: null, dir: 1 };
    var shown = PAGE;
    var query = '';

    container.classList.add('nu-sx-grid');
    container.innerHTML = '';

    if (!all.length) {
      var empty = el('div', 'nu-sx-grid-empty');
      empty.innerHTML = '<svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11Z"/></svg><span>' +
        esc(opts.emptyText || 'The query ran successfully but returned no rows.') + '</span>' +
        (meta.elapsed_ms !== undefined ? '<small>' + meta.elapsed_ms + ' ms</small>' : '');
      container.appendChild(empty);
      return { destroy: function () { container.innerHTML = ''; } };
    }

    var bar = el('div', 'nu-sx-grid-bar');
    var filter = el('input', 'nu-input nu-sx-grid-filter');
    filter.type = 'search'; filter.placeholder = 'Filter rows…'; filter.setAttribute('aria-label', 'Filter rows');
    var info = el('span', 'nu-sx-grid-info');
    var btns = el('div', 'nu-sx-grid-btns');
    var copyBtn = el('button', 'nu-btn nu-btn-ghost nu-btn-sm', 'Copy'); copyBtn.type = 'button'; copyBtn.title = 'Copy visible rows as tab-separated text';
    var csvBtn = el('button', 'nu-btn nu-btn-ghost nu-btn-sm', 'CSV'); csvBtn.type = 'button'; csvBtn.title = 'Download visible rows as CSV';
    btns.appendChild(copyBtn); btns.appendChild(csvBtn);
    bar.appendChild(filter); bar.appendChild(info); bar.appendChild(btns);
    var scroller = el('div', 'nu-sx-grid-scroll');
    var table = el('table', 'nu-sx-table-el');
    var thead = el('thead'); var tbody = el('tbody');
    table.appendChild(thead); table.appendChild(tbody); scroller.appendChild(table);
    var more = el('div', 'nu-sx-grid-more'); more.hidden = true;
    container.appendChild(bar); container.appendChild(scroller); container.appendChild(more);

    function isNum(v) { return v !== null && v !== '' && v !== undefined && !isNaN(v) && /^-?\d+(\.\d+)?$/.test(String(v).trim()); }
    var numeric = {};
    cols.forEach(function (c) {
      var sample = all.slice(0, 50).map(function (r) { return r[c.field]; }).filter(function (v) { return v !== null && v !== ''; });
      numeric[c.field] = sample.length > 0 && sample.every(isNum);
    });

    function view() {
      var rows = all;
      if (query) {
        rows = rows.filter(function (r) {
          return cols.some(function (c) { var v = r[c.field]; return v !== null && v !== undefined && String(v).toLowerCase().indexOf(query) !== -1; });
        });
      }
      if (sort.field) {
        var f = sort.field, num = numeric[f];
        rows = rows.slice().sort(function (a, b) {
          var x = a[f], y = b[f];
          if (x === null || x === undefined) return 1;      // NULLs last either way
          if (y === null || y === undefined) return -1;
          if (num) return (parseFloat(x) - parseFloat(y)) * sort.dir;
          return String(x).localeCompare(String(y), undefined, { numeric: true, sensitivity: 'base' }) * sort.dir;
        });
      }
      return rows;
    }

    function drawHead() {
      thead.innerHTML = '';
      var tr = el('tr');
      var corner = el('th', 'nu-sx-rownum', '#'); tr.appendChild(corner);
      cols.forEach(function (c) {
        var th = el('th'); th.tabIndex = 0; th.setAttribute('role', 'columnheader');
        th.setAttribute('aria-sort', sort.field === c.field ? (sort.dir === 1 ? 'ascending' : 'descending') : 'none');
        if (numeric[c.field]) th.classList.add('is-num');
        th.innerHTML = '<span>' + esc(c.label) + '</span><i class="nu-sx-sort">' + (sort.field === c.field ? (sort.dir === 1 ? '▲' : '▼') : '') + '</i>';
        var go = function () {
          if (sort.field !== c.field) { sort.field = c.field; sort.dir = 1; }
          else if (sort.dir === 1) sort.dir = -1;
          else { sort.field = null; sort.dir = 1; }
          draw();
        };
        th.addEventListener('click', go);
        th.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); go(); } });
        tr.appendChild(th);
      });
      thead.appendChild(tr);
    }

    function draw() {
      var rows = view();
      drawHead();
      tbody.innerHTML = '';
      var frag = document.createDocumentFragment();
      rows.slice(0, shown).forEach(function (r, i) {
        var tr = el('tr');
        tr.appendChild(el('td', 'nu-sx-rownum', String(i + 1)));
        cols.forEach(function (c) {
          var v = r[c.field];
          var td = el('td');
          if (v === null || v === undefined) { td.className = 'is-null'; td.textContent = 'NULL'; }
          else {
            var s = String(v);
            td.textContent = s;
            if (numeric[c.field]) td.classList.add('is-num');
            if (s.length > 40) td.title = s.length > 400 ? s.slice(0, 400) + '…' : s;
          }
          td.dataset.copy = v === null || v === undefined ? '' : String(v);
          tr.appendChild(td);
        });
        frag.appendChild(tr);
      });
      tbody.appendChild(frag);

      var parts = [rows.length.toLocaleString() + (query ? ' of ' + all.length.toLocaleString() : '') + ' row' + (rows.length === 1 ? '' : 's')];
      if (meta.elapsed_ms !== undefined) parts.push(meta.elapsed_ms + ' ms');
      info.textContent = parts.join(' · ');
      if (meta.truncated) {
        info.innerHTML += ' <span class="nu-sx-warn" title="The result was capped. Add a LIMIT or filters to narrow it.">capped at ' +
          esc((meta.limit || all.length).toLocaleString()) + (meta.total_available ? ' of ' + esc(meta.total_available.toLocaleString()) : '') + '</span>';
      }
      if (rows.length > shown) {
        more.hidden = false;
        more.innerHTML = '';
        var b = el('button', 'nu-btn nu-btn-ghost nu-btn-sm', 'Show ' + Math.min(PAGE, rows.length - shown).toLocaleString() + ' more (' + (rows.length - shown).toLocaleString() + ' hidden)');
        b.type = 'button'; b.addEventListener('click', function () { shown += PAGE; draw(); });
        more.appendChild(b);
      } else { more.hidden = true; }
    }

    filter.addEventListener('input', function () { query = filter.value.trim().toLowerCase(); shown = PAGE; draw(); });
    tbody.addEventListener('click', function (e) {
      var td = e.target.closest('td');
      if (!td || td.classList.contains('nu-sx-rownum')) return;
      if (window.getSelection && String(window.getSelection())) return;   // don't hijack text selection
      copyText(td.dataset.copy || '').then(function (ok) { if (ok) toast('Copied', 'info'); });
    });
    copyBtn.addEventListener('click', function () {
      var rows = view();
      var text = [cols.map(function (c) { return c.label; }).join('\t')].concat(rows.map(function (r) {
        return cols.map(function (c) { var v = r[c.field]; return v === null || v === undefined ? '' : String(v).replace(/[\t\r\n]+/g, ' '); }).join('\t');
      })).join('\n');
      copyText(text).then(function (ok) { toast(ok ? rows.length + ' rows copied' : 'Copy failed', ok ? 'info' : 'error'); });
    });
    csvBtn.addEventListener('click', function () {
      var rows = view();
      downloadCsv(opts.filename || 'results.csv', cols.map(function (c) { return c.label; }),
        rows.map(function (r) { return cols.map(function (c) { return r[c.field]; }); }));
    });
    draw();
    return { destroy: function () { container.innerHTML = ''; }, rows: view };
  }

  window.NuSchema = NuSchema;
  window.NuSqlEditor = NuSqlEditor;
  window.NuResultGrid = { render: renderGrid, downloadCsv: downloadCsv, copyText: copyText };
})(window);
