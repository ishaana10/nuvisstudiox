/*
 * NuViewer - in-app document viewer.
 * Opens uploaded files in a pop-up inside the app. The file is fetched with fetch() (session cookie) and shown
 * from a temporary in-memory blob, so the browser never navigates to the file and no file address is ever shown.
 * No third-party libraries: PDFs and images use the browser, Word (.docx), Excel (.xlsx), CSV and text are
 * rendered here with DOM calls only (never innerHTML), so file content cannot inject markup or script.
 *
 *   NuViewer.open({ id: 12 })                     by file id
 *   NuViewer.open({ name: 'abc123_scan.pdf' })    by stored name
 *   NuViewer.openList([{id:1},{id:2}], 0)         with previous / next
 *   <button data-nu-view-id="12">View</button>    declarative
 */
(function () {
  'use strict';
  var API = 'api/file.php';
  var MAX_TEXT = 1024 * 1024, MAX_ENTRY = 40 * 1024 * 1024, MAX_ROWS = 1000, MAX_COLS = 60;
  var IMG = { png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif', webp: 'image/webp', bmp: 'image/bmp' };
  var TEXT = ['txt', 'log', 'md', 'json', 'xml', 'ini', 'yml', 'yaml', 'sql'];
  var state = null;   // { dlg, items, index, url, token, zoom, rot }

  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined && text !== null) e.textContent = text; return e; }
  function ext(n) { var m = /\.([A-Za-z0-9]+)$/.exec(n || ''); return m ? m[1].toLowerCase() : ''; }
  function bytes(n) { if (!n) return ''; return n < 1024 ? n + ' B' : n < 1048576 ? Math.round(n / 1024) + ' KB' : (n / 1048576).toFixed(1) + ' MB'; }
  function kindOf(name, mime) {
    var e = ext(name), m = (mime || '').toLowerCase();
    if (IMG[e] || /^image\/(png|jpeg|gif|webp|bmp)$/.test(m)) return 'image';
    if (e === 'pdf' || m === 'application/pdf') return 'pdf';
    if (e === 'docx') return 'docx';
    if (e === 'xlsx') return 'xlsx';
    if (e === 'csv' || e === 'tsv') return 'csv';
    if (TEXT.indexOf(e) > -1 || m.indexOf('text/') === 0) return 'text';
    return 'other';
  }
  function q(spec, extra) { var p = []; if (spec.id) p.push('id=' + encodeURIComponent(spec.id)); else p.push('name=' + encodeURIComponent(spec.name)); return API + '?' + p.concat(extra || []).join('&'); }

  /* ── minimal zip reader (central directory + DecompressionStream) ─────────── */
  async function inflate(u8) {
    var ds = new DecompressionStream('deflate-raw');
    var rd = new Blob([u8]).stream().pipeThrough(ds).getReader(), chunks = [], total = 0;
    for (;;) { var r = await rd.read(); if (r.done) break; total += r.value.length; if (total > MAX_ENTRY) { rd.cancel(); throw new Error('File is too large to preview.'); } chunks.push(r.value); }
    var out = new Uint8Array(total), o = 0; chunks.forEach(function (c) { out.set(c, o); o += c.length; }); return out;
  }
  async function unzip(buf) {
    if (typeof DecompressionStream === 'undefined') throw new Error('This browser cannot preview Office files. Please download the file.');
    var dv = new DataView(buf), u8 = new Uint8Array(buf), eocd = -1;
    for (var i = u8.length - 22; i >= Math.max(0, u8.length - 65557); i--) if (dv.getUint32(i, true) === 0x06054b50) { eocd = i; break; }
    if (eocd < 0) throw new Error('Not a valid Office file.');
    var count = dv.getUint16(eocd + 10, true), p = dv.getUint32(eocd + 16, true), entries = {}, dec = new TextDecoder();
    for (var n = 0; n < count && n < 5000; n++) {
      if (dv.getUint32(p, true) !== 0x02014b50) break;
      var method = dv.getUint16(p + 10, true), csize = dv.getUint32(p + 20, true), usize = dv.getUint32(p + 24, true), nl = dv.getUint16(p + 28, true), el_ = dv.getUint16(p + 30, true), cl = dv.getUint16(p + 32, true), off = dv.getUint32(p + 42, true);
      var name = dec.decode(u8.subarray(p + 46, p + 46 + nl));
      entries[name] = { method: method, csize: csize, usize: usize, off: off };
      p += 46 + nl + el_ + cl;
    }
    return {
      has: function (name) { return !!entries[name]; },
      names: function () { return Object.keys(entries); },
      read: async function (name) {
        var e = entries[name]; if (!e) return null;
        if (e.usize > MAX_ENTRY) throw new Error('File is too large to preview.');
        var lo = e.off, start = lo + 30 + dv.getUint16(lo + 26, true) + dv.getUint16(lo + 28, true), raw = u8.subarray(start, start + e.csize);
        return e.method === 0 ? raw : e.method === 8 ? inflate(raw) : Promise.reject(new Error('Unsupported compression.'));
      },
      text: async function (name) { var d = await this.read(name); return d ? new TextDecoder().decode(d) : null; }
    };
  }
  function xml(str) {
    var d = new DOMParser().parseFromString(str, 'application/xml');
    if (d.getElementsByTagName('parsererror').length) throw new Error('The document could not be read.');
    return d;
  }
  function kids(node, name) { var out = []; for (var c = node.firstElementChild; c; c = c.nextElementSibling) if (c.localName === name) out.push(c); return out; }
  function kid(node, name) { return node ? kids(node, name)[0] || null : null; }
  function wattr(node, name) { return node ? (node.getAttribute('w:' + name) || node.getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', name)) : null; }

  /* ── DOCX ─────────────────────────────────────────────────────────────────── */
  async function renderDocx(buf, root, urls) {
    var z = await unzip(buf), docXml = await z.text('word/document.xml');
    if (!docXml) throw new Error('Not a valid Word document.');
    var doc = xml(docXml), body = doc.getElementsByTagName('w:body')[0] || doc.getElementsByTagNameNS('*', 'body')[0];
    var rels = {};
    var relXml = await z.text('word/_rels/document.xml.rels');
    if (relXml) Array.prototype.forEach.call(xml(relXml).getElementsByTagName('Relationship'), function (r) { rels[r.getAttribute('Id')] = r.getAttribute('Target'); });
    var page = el('div', 'nuv-doc'); root.appendChild(page);

    async function image(drawing) {
      var blip = drawing.getElementsByTagNameNS('*', 'blip')[0]; if (!blip) return null;
      var rid = blip.getAttribute('r:embed') || blip.getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed');
      var t = rels[rid]; if (!t) return null;
      var path = t.charAt(0) === '/' ? t.slice(1) : 'word/' + t.replace(/^\.\//, ''), e = ext(path);
      if (!IMG[e] || e === 'webp') return null;
      var data = await z.read(path); if (!data) return null;
      var u = URL.createObjectURL(new Blob([data], { type: IMG[e] })); urls.push(u);
      var img = el('img', 'nuv-doc-img'); img.src = u; img.alt = ''; return img;
    }
    async function runs(p, into) {
      for (var n = p.firstElementChild; n; n = n.nextElementSibling) {
        var ln = n.localName;
        if (ln === 'hyperlink' || ln === 'smartTag' || ln === 'ins' || ln === 'sdt') { await runs(ln === 'sdt' ? (kid(n, 'sdtContent') || n) : n, into); continue; }
        if (ln !== 'r') continue;
        var rpr = kid(n, 'rPr'), flag = function (k) { var f = kid(rpr, k); return !!f && wattr(f, 'val') !== '0' && wattr(f, 'val') !== 'false'; };
        var wrap = into, make = function (tag) { var e = el(tag); wrap.appendChild(e); wrap = e; };
        if (rpr) { if (flag('b')) make('strong'); if (flag('i')) make('em'); if (flag('u')) make('u'); if (flag('strike')) make('s'); }
        for (var c = n.firstElementChild; c; c = c.nextElementSibling) {
          if (c.localName === 't') wrap.appendChild(document.createTextNode(c.textContent));
          else if (c.localName === 'tab') wrap.appendChild(document.createTextNode(' '));
          else if (c.localName === 'br' || c.localName === 'cr') wrap.appendChild(el('br'));
          else if (c.localName === 'drawing' || c.localName === 'pict') { var im = await image(c); wrap.appendChild(im || el('span', 'nuv-muted', '[image]')); }
        }
      }
    }
    async function para(p) {
      var ppr = kid(p, 'pPr'), st = ppr && kid(ppr, 'pStyle') ? (wattr(kid(ppr, 'pStyle'), 'val') || '') : '';
      var tag = 'p', m = /^heading\s*([1-6])$/i.exec(st);
      if (m) tag = 'h' + m[1]; else if (/^title$/i.test(st)) tag = 'h1'; else if (/^subtitle$/i.test(st)) tag = 'h2';
      var e = el(tag, 'nuv-p');
      var num = ppr && kid(ppr, 'numPr');
      var styleList = !num && /^List/i.test(st);
      if (styleList) { e.classList.add('nuv-li'); e.style.marginLeft = '1.4em'; e.appendChild(el('span', 'nuv-bullet', /Number/i.test(st) ? '–' : '•')); }
      if (num) { var lvl = parseInt(wattr(kid(num, 'ilvl'), 'val') || '0', 10) || 0; e.classList.add('nuv-li'); e.style.marginLeft = (1.4 + lvl * 1.4) + 'em'; e.appendChild(el('span', 'nuv-bullet', lvl % 2 ? '◦' : '•')); }
      var jc = ppr && kid(ppr, 'jc'); if (jc) { var a = wattr(jc, 'val'); if (a === 'center' || a === 'right') e.style.textAlign = a; else if (a === 'both') e.style.textAlign = 'justify'; }
      await runs(p, e);
      if (!e.textContent && !e.querySelector('img')) e.appendChild(el('br'));
      return e;
    }
    async function table(t) {
      var tb = el('table', 'nuv-tbl'), tbody = el('tbody'); tb.appendChild(tbody);
      for (var r of kids(t, 'tr')) {
        var tr = el('tr');
        for (var c of kids(r, 'tc')) {
          var tc = el('td'), pr = kid(c, 'tcPr'), gs = pr && kid(pr, 'gridSpan');
          if (gs) tc.colSpan = parseInt(wattr(gs, 'val') || '1', 10) || 1;
          await block(c, tc); tr.appendChild(tc);
        }
        tbody.appendChild(tr);
      }
      return tb;
    }
    async function block(parent, into) {
      for (var n = parent.firstElementChild; n; n = n.nextElementSibling) {
        if (n.localName === 'p') into.appendChild(await para(n));
        else if (n.localName === 'tbl') into.appendChild(await table(n));
        else if (n.localName === 'sdt') await block(kid(n, 'sdtContent') || n, into);
      }
    }
    await block(body, page);
    if (!page.textContent.trim() && !page.querySelector('img')) page.appendChild(el('p', 'nuv-muted', 'This document has no visible content.'));
  }

  /* ── XLSX ─────────────────────────────────────────────────────────────────── */
  function colIdx(ref) { var m = /^([A-Z]+)(\d+)$/.exec(ref || ''); if (!m) return null; var c = 0; for (var i = 0; i < m[1].length; i++) c = c * 26 + (m[1].charCodeAt(i) - 64); return { c: c - 1, r: parseInt(m[2], 10) - 1 }; }
  function serialToDate(v, withTime) {
    var ms = Math.round((v - 25569) * 86400000), d = new Date(ms), p = function (x) { return String(x).padStart(2, '0'); };
    var s = d.getUTCFullYear() + '-' + p(d.getUTCMonth() + 1) + '-' + p(d.getUTCDate());
    return withTime ? s + ' ' + p(d.getUTCHours()) + ':' + p(d.getUTCMinutes()) : s;
  }
  async function renderXlsx(buf, root) {
    var z = await unzip(buf), wbXml = await z.text('xl/workbook.xml');
    if (!wbXml) throw new Error('Not a valid Excel workbook.');
    var rels = {}, relXml = await z.text('xl/_rels/workbook.xml.rels');
    if (relXml) Array.prototype.forEach.call(xml(relXml).getElementsByTagName('Relationship'), function (r) { rels[r.getAttribute('Id')] = r.getAttribute('Target'); });
    var shared = [], ss = await z.text('xl/sharedStrings.xml');
    if (ss) Array.prototype.forEach.call(xml(ss).getElementsByTagNameNS('*', 'si'), function (si) {
      var t = ''; Array.prototype.forEach.call(si.getElementsByTagNameNS('*', 't'), function (x) { if (x.parentNode.localName !== 'rPh') t += x.textContent; }); shared.push(t);
    });
    // number formats -> which cell styles are dates
    var dateXf = {}, st = await z.text('xl/styles.xml');
    if (st) {
      var sd = xml(st), custom = {};
      Array.prototype.forEach.call(sd.getElementsByTagNameNS('*', 'numFmt'), function (n) { custom[n.getAttribute('numFmtId')] = n.getAttribute('formatCode') || ''; });
      var xfs = sd.getElementsByTagNameNS('*', 'cellXfs')[0];
      if (xfs) Array.prototype.forEach.call(xfs.getElementsByTagNameNS('*', 'xf'), function (xf, i) {
        var id = parseInt(xf.getAttribute('numFmtId'), 10), isDate = (id >= 14 && id <= 22) || (id >= 45 && id <= 47);
        if (custom[id] !== undefined) isDate = /[dmyhs]/i.test(custom[id].replace(/"[^"]*"|\[[^\]]*\]|\\./g, '')) && !/^(general|0|#)/i.test(custom[id]);
        if (isDate) dateXf[i] = /h|s/i.test(custom[id] || '') || id === 22 || (id >= 45 && id <= 47);
      });
    }
    var sheets = Array.prototype.map.call(xml(wbXml).getElementsByTagNameNS('*', 'sheet'), function (s) {
      var rid = s.getAttribute('r:id') || s.getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
      var t = rels[rid] || ''; return { name: s.getAttribute('name'), path: t.charAt(0) === '/' ? t.slice(1) : 'xl/' + t };
    });
    if (!sheets.length) throw new Error('This workbook has no sheets.');
    var tabs = el('div', 'nuv-tabs'), panes = el('div', 'nuv-sheets'); root.appendChild(tabs); root.appendChild(panes);
    var built = {};
    async function build(i) {
      if (built[i]) return built[i];
      var sx = await z.text(sheets[i].path), wrap = el('div', 'nuv-sheet');
      if (!sx) { wrap.appendChild(el('p', 'nuv-muted', 'Empty sheet.')); return (built[i] = wrap); }
      var grid = [], maxC = 0, maxR = 0, trunc = false;
      Array.prototype.forEach.call(xml(sx).getElementsByTagNameNS('*', 'c'), function (c) {
        var pos = colIdx(c.getAttribute('r')); if (!pos) return;
        if (pos.r >= MAX_ROWS || pos.c >= MAX_COLS) { trunc = true; return; }
        var t = c.getAttribute('t'), vEl = kid(c, 'v'), v = vEl ? vEl.textContent : '', out = '';
        if (t === 's') out = shared[parseInt(v, 10)] || '';
        else if (t === 'inlineStr') out = Array.prototype.map.call(c.getElementsByTagNameNS('*', 't'), function (x) { return x.textContent; }).join('');
        else if (t === 'b') out = v === '1' ? 'TRUE' : 'FALSE';
        else if (v !== '' && (t === 'n' || !t)) {
          var num = Number(v), sidx = parseInt(c.getAttribute('s') || '0', 10);
          out = !isFinite(num) ? v : (dateXf[sidx] !== undefined ? serialToDate(num, dateXf[sidx] && num % 1 !== 0) : String(parseFloat(num.toPrecision(12))));
        } else out = v;
        (grid[pos.r] = grid[pos.r] || [])[pos.c] = out; maxC = Math.max(maxC, pos.c + 1); maxR = Math.max(maxR, pos.r + 1);
      });
      if (!maxR) wrap.appendChild(el('p', 'nuv-muted', 'This sheet is empty.'));
      else {
        var tb = el('table', 'nuv-tbl nuv-grid'), hr = el('tr'); hr.appendChild(el('th', 'nuv-corner'));
        for (var c = 0; c < maxC; c++) { var l = '', n = c; do { l = String.fromCharCode(65 + n % 26) + l; n = Math.floor(n / 26) - 1; } while (n >= 0); hr.appendChild(el('th', null, l)); }
        tb.appendChild(el('thead')).appendChild(hr);
        var body = el('tbody');
        for (var r = 0; r < maxR; r++) { var tr = el('tr'); tr.appendChild(el('th', null, r + 1)); for (var k = 0; k < maxC; k++) tr.appendChild(el('td', null, (grid[r] && grid[r][k] !== undefined) ? grid[r][k] : '')); body.appendChild(tr); }
        tb.appendChild(body); wrap.appendChild(tb);
        if (trunc) wrap.appendChild(el('p', 'nuv-muted', 'Showing the first ' + MAX_ROWS + ' rows and ' + MAX_COLS + ' columns. Download the file to see everything.'));
      }
      return (built[i] = wrap);
    }
    async function show(i) {
      Array.prototype.forEach.call(tabs.children, function (b, j) { b.classList.toggle('on', j === i); });
      panes.replaceChildren(await build(i));
    }
    sheets.forEach(function (s, i) { var b = el('button', 'nuv-tab', s.name); b.type = 'button'; b.addEventListener('click', function () { show(i).catch(fail); }); tabs.appendChild(b); });
    if (sheets.length === 1) tabs.style.display = 'none';
    function fail(e) { panes.replaceChildren(el('p', 'nuv-muted', e.message)); }
    await show(0);
  }

  /* ── CSV / text ───────────────────────────────────────────────────────────── */
  function parseCsv(text, delim) {
    var rows = [], row = [], cur = '', inQ = false;
    for (var i = 0; i < text.length && rows.length <= MAX_ROWS; i++) {
      var ch = text[i];
      if (inQ) { if (ch === '"') { if (text[i + 1] === '"') { cur += '"'; i++; } else inQ = false; } else cur += ch; }
      else if (ch === '"') inQ = true;
      else if (ch === delim) { row.push(cur); cur = ''; }
      else if (ch === '\n' || ch === '\r') { if (ch === '\r' && text[i + 1] === '\n') i++; row.push(cur); cur = ''; rows.push(row); row = []; }
      else cur += ch;
    }
    if (cur !== '' || row.length) { row.push(cur); rows.push(row); }
    return rows;
  }
  function renderCsv(text, root, tsv) {
    var first = text.split(/\r?\n/, 1)[0] || '', delim = tsv ? '\t' : [',', ';', '\t'].map(function (d) { return [d, first.split(d).length]; }).sort(function (a, b) { return b[1] - a[1]; })[0][0];
    var rows = parseCsv(text, delim), cols = Math.min(MAX_COLS, rows.reduce(function (m, r) { return Math.max(m, r.length); }, 0));
    var tb = el('table', 'nuv-tbl nuv-grid'), body = el('tbody');
    rows.slice(0, MAX_ROWS).forEach(function (r, i) {
      var tr = el('tr'); tr.appendChild(el('th', null, i + 1));
      for (var c = 0; c < cols; c++) tr.appendChild(el(i === 0 ? 'td' : 'td', i === 0 ? 'nuv-head' : '', r[c] === undefined ? '' : r[c]));
      body.appendChild(tr);
    });
    tb.appendChild(body); var w = el('div', 'nuv-sheet'); w.appendChild(tb);
    if (rows.length > MAX_ROWS) w.appendChild(el('p', 'nuv-muted', 'Showing the first ' + MAX_ROWS + ' rows.'));
    root.appendChild(w);
  }

  /* ── dialog ───────────────────────────────────────────────────────────────── */
  function btn(label, title, fn) { var b = el('button', 'nuv-btn', label); b.type = 'button'; b.title = title; b.setAttribute('aria-label', title); b.addEventListener('click', fn); return b; }

  function build() {
    var dlg = el('dialog', 'nuv'); dlg.setAttribute('aria-label', 'Document viewer');
    var bar = el('div', 'nuv-bar'), title = el('div', 'nuv-title'), sub = el('span', 'nuv-sub');
    var left = el('div', 'nuv-grow'); title.appendChild(el('span', 'nuv-name')); left.appendChild(title); left.appendChild(sub);
    var tools = el('div', 'nuv-tools');
    var prev = btn('‹', 'Previous file (←)', function () { go(-1); }), next = btn('›', 'Next file (→)', function () { go(1); });
    var zin = btn('+', 'Zoom in', function () { zoom(1.25); }), zout = btn('−', 'Zoom out', function () { zoom(0.8); }), rot = btn('⟳', 'Rotate', function () { state.rot = (state.rot + 90) % 360; applyImg(); });
    var dl = btn('Download', 'Download this file', download), x = btn('✕', 'Close (Esc)', close);
    dl.classList.add('nuv-dl'); x.classList.add('nuv-x');
    [prev, next, zout, zin, rot, dl, x].forEach(function (b) { tools.appendChild(b); });
    bar.appendChild(left); bar.appendChild(tools);
    var stage = el('div', 'nuv-stage'); stage.tabIndex = 0;
    dlg.appendChild(bar); dlg.appendChild(stage);
    dlg.addEventListener('close', cleanup);
    dlg.addEventListener('cancel', function (e) { e.preventDefault(); close(); });
    dlg.addEventListener('click', function (e) { if (e.target === dlg) close(); });
    dlg.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft') go(-1); else if (e.key === 'ArrowRight') go(1);
      else if (e.key === '+' || e.key === '=') zoom(1.25); else if (e.key === '-') zoom(0.8);
    });
    // wheel zoom on images
    stage.addEventListener('wheel', function (e) { if (state && state.kind === 'image' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); zoom(e.deltaY < 0 ? 1.15 : 0.87); } }, { passive: false });
    document.body.appendChild(dlg);
    return { dlg: dlg, name: title.firstChild, sub: sub, stage: stage, prev: prev, next: next, zin: zin, zout: zout, rot: rot, dl: dl };
  }

  function zoom(f) { if (!state || state.kind !== 'image') return; state.zoom = Math.max(0.1, Math.min(8, state.zoom * f)); applyImg(); }
  function applyImg() { var i = state && state.img; if (!i) return; i.style.transform = 'rotate(' + state.rot + 'deg) scale(' + state.zoom + ')'; }
  function revokeAll() { if (!state) return; (state.urls || []).forEach(function (u) { try { URL.revokeObjectURL(u); } catch (e) {} }); state.urls = []; }
  function cleanup() { if (!state) return; revokeAll(); state.ui.stage.replaceChildren(); state.ui.dlg.remove(); state = null; }
  function close() { if (state) { state.token++; var d = state.ui.dlg; if (d.open) d.close(); else cleanup(); } }
  function go(d) { if (!state || state.items.length < 2) return; state.index = (state.index + d + state.items.length) % state.items.length; load(); }

  function message(text, withDownload) {
    var box = el('div', 'nuv-msg'); box.appendChild(el('div', 'nuv-ico', '📄')); box.appendChild(el('p', null, text));
    if (withDownload) { var b = el('button', 'nu-btn nu-btn-primary', 'Download'); b.type = 'button'; b.addEventListener('click', download); box.appendChild(b); }
    state.ui.stage.replaceChildren(box);
  }

  async function load() {
    var s = state, ui = s.ui, spec = s.items[s.index], token = ++s.token;
    revokeAll(); s.img = null; s.zoom = 1; s.rot = 0; s.kind = 'other';
    ui.prev.style.display = ui.next.style.display = s.items.length > 1 ? '' : 'none';
    ui.name.textContent = spec.title || spec.name || 'Loading…'; ui.sub.textContent = s.items.length > 1 ? (s.index + 1) + ' of ' + s.items.length : '';
    var loading = el('div', 'nuv-msg'); loading.appendChild(el('div', 'nuv-spin')); loading.appendChild(el('p', null, 'Opening…')); ui.stage.replaceChildren(loading);
    ['zin', 'zout', 'rot'].forEach(function (k) { ui[k].style.display = 'none'; });
    try {
      var meta = await (await fetch(q(spec, ['meta=1']), { credentials: 'same-origin' })).json().catch(function () { return null; });
      if (token !== s.token) return;
      if (!meta || !meta.success) throw new Error('This file could not be found, or you do not have access to it.');
      spec.meta = meta; ui.name.textContent = meta.name; ui.sub.textContent = [bytes(meta.size), s.items.length > 1 ? (s.index + 1) + ' of ' + s.items.length : ''].filter(Boolean).join(' · ');
      var kind = kindOf(meta.name, meta.mime); s.kind = kind;
      if (kind === 'other') { message('A preview is not available for this type of file.', true); return; }
      var res = await fetch(q(spec), { credentials: 'same-origin' });
      if (token !== s.token) return;
      if (!res.ok) throw new Error(res.status === 403 ? 'You do not have permission to view this file.' : res.status === 404 ? 'The file is no longer available.' : 'The file could not be loaded (' + res.status + ').');
      var stage = el('div', 'nuv-body');
      if (kind === 'image' || kind === 'pdf') {
        var blob = await res.blob(); if (token !== s.token) return;
        var url = URL.createObjectURL(new Blob([blob], { type: kind === 'pdf' ? 'application/pdf' : (IMG[ext(meta.name)] || blob.type || 'image/png') })); s.urls.push(url);
        if (kind === 'image') {
          var img = el('img', 'nuv-img'); img.alt = meta.name; img.draggable = false; img.src = url; s.img = img; stage.className = 'nuv-body nuv-center'; stage.appendChild(img);
          ['zin', 'zout', 'rot'].forEach(function (k) { ui[k].style.display = ''; });
          img.addEventListener('error', function () { message('This image could not be displayed.', true); });
        } else {
          var fr = el('iframe', 'nuv-pdf'); fr.title = meta.name; fr.src = url + '#toolbar=0&navpanes=0'; stage.className = 'nuv-body nuv-flush'; stage.appendChild(fr);
        }
      } else if (kind === 'docx' || kind === 'xlsx') {
        var buf = await res.arrayBuffer(); if (token !== s.token) return;
        if (buf.byteLength > 25 * 1024 * 1024) { message('This file is too large to preview. Please download it.', true); return; }
        stage.className = 'nuv-body';
        if (kind === 'docx') await renderDocx(buf, stage, s.urls); else await renderXlsx(buf, stage);
      } else {
        var text = await res.text(); if (token !== s.token) return;
        var cut = text.length > MAX_TEXT; if (cut) text = text.slice(0, MAX_TEXT);
        stage.className = 'nuv-body';
        if (kind === 'csv') renderCsv(text, stage, ext(meta.name) === 'tsv');
        else {
          if (ext(meta.name) === 'json') { try { text = JSON.stringify(JSON.parse(text), null, 2); } catch (e) {} }
          stage.appendChild(el('pre', 'nuv-pre', text));
        }
        if (cut) stage.appendChild(el('p', 'nuv-muted', 'Preview shortened. Download the file to read it all.'));
      }
      if (token !== s.token) return;
      ui.stage.replaceChildren(stage); applyImg(); ui.stage.focus({ preventScroll: true });
    } catch (e) {
      if (token === s.token) message(e && e.message ? e.message : 'The file could not be opened.', !!(spec.meta));
    }
  }

  async function download() {
    if (!state) return;
    var spec = state.items[state.index], name = (spec.meta && spec.meta.name) || spec.name || 'download';
    try {
      var res = await fetch(q(spec, ['dl=1']), { credentials: 'same-origin' });
      if (!res.ok) throw new Error('Download failed (' + res.status + ').');
      var b = await res.blob(), u = URL.createObjectURL(b), a = document.createElement('a');
      a.href = u; a.download = name; a.rel = 'noopener'; document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(u); }, 4000);
    } catch (e) { if (window.NuApp && NuApp.toast) NuApp.toast(e.message, 'error'); else alert(e.message); }
  }

  function openList(items, index) {
    items = (items || []).filter(function (i) { return i && (i.id || i.name); });
    if (!items.length) return;
    if (state) { state.token++; revokeAll(); state.ui.dlg.remove(); state = null; }
    var ui = build(); state = { ui: ui, items: items, index: Math.max(0, Math.min(index || 0, items.length - 1)), token: 0, urls: [], zoom: 1, rot: 0, kind: 'other' };
    ui.dlg.showModal(); load();
  }
  function open(spec) { openList([spec], 0); }

  /* ── wiring ───────────────────────────────────────────────────────────────── */
  function storedName(href) {
    if (!href) return null;
    var m = /^(?:\.\/)?uploads\/([^?#]+)/.exec(href); if (m) return decodeURIComponent(m[1]).split('/').pop();
    m = /api\/file\.php\?(?:[^#]*&)?name=([^&#]+)/.exec(href); if (m && !/[?&]dl=1/.test(href)) return decodeURIComponent(m[1]);
    return null;
  }
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button) return;
    var t = e.target.closest && e.target.closest('[data-nu-view-id],[data-nu-view-name],a[href]'); if (!t) return;
    if (t.hasAttribute('data-nu-view-id') || t.hasAttribute('data-nu-view-name')) {
      e.preventDefault(); e.stopPropagation();
      var group = t.getAttribute('data-nu-view-group'), items = [], idx = 0;
      var all = group ? document.querySelectorAll('[data-nu-view-group="' + group.replace(/"/g, '') + '"]') : [t];
      Array.prototype.forEach.call(all, function (n) { if (n === t) idx = items.length; items.push(n.hasAttribute('data-nu-view-id') ? { id: n.getAttribute('data-nu-view-id') } : { name: n.getAttribute('data-nu-view-name') }); });
      openList(items, idx); return;
    }
    // older links that point straight at the uploads folder open in the viewer instead
    if (t.tagName === 'A') {
      var n = storedName(t.getAttribute('href'));
      if (n) { e.preventDefault(); e.stopPropagation(); open({ name: n }); }
    }
  }, true);

  var origLightbox = window._showImageLightbox;
  window._showImageLightbox = function (src) {
    var n = typeof src === 'string' && !/^(blob:|data:|https?:)/i.test(src) ? decodeURIComponent(src.replace(/^(?:\.\/)?uploads\//, '')).split('/').pop() : null;
    if (n) open({ name: n }); else if (origLightbox) origLightbox(src);
  };

  window.NuViewer = { open: open, openList: openList, close: close };
})();
