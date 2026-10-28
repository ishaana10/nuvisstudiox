/* Nuvis StudioX — "Share form" dialog: a public link so customers can submit a request without signing in. */
(function () {
  'use strict';
  var api = 'api/form_share.php';
  var S = { code: '', label: '', data: null, busy: false, tab: 'link' };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(msg, type) { if (window.NuApp && NuApp.toast) NuApp.toast(msg, type || 'info'); }

  function call(action, body) {
    var opts = { credentials: 'same-origin' };
    if (body) { opts.method = 'POST'; opts.headers = { 'Content-Type': 'application/json' }; opts.body = JSON.stringify(body); }
    return fetch(api + '?action=' + action + '&code=' + encodeURIComponent(S.code), opts)
      .then(function (r) { return r.json().catch(function () { return { success: false, error: 'Unexpected response from the server.' }; }); });
  }

  function root() {
    var el = document.getElementById('fsModal');
    if (el) return el;
    el = document.createElement('div');
    el.id = 'fsModal'; el.className = 'fs-overlay';
    el.innerHTML = '<div class="fs-dialog" role="dialog" aria-modal="true" aria-labelledby="fsTitle"><div class="fs-head"><div><h3 id="fsTitle">Share form</h3><div class="fs-sub" id="fsSub"></div></div><button type="button" class="fs-x" data-fs="close" aria-label="Close">✕</button></div><div class="fs-body" id="fsBody"></div><div class="fs-foot" id="fsFoot"></div></div>';
    document.body.appendChild(el);
    el.addEventListener('mousedown', function (e) { if (e.target === el) close(); });
    el.addEventListener('click', onClick);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && el.classList.contains('open')) close(); });
    return el;
  }

  function close() { var el = document.getElementById('fsModal'); if (el) el.classList.remove('open'); }

  function open(code, label) {
    S.code = code; S.label = label || code; S.data = null; S.tab = 'link';
    var el = root();
    el.classList.add('open');
    document.getElementById('fsSub').textContent = S.label;
    document.getElementById('fsBody').innerHTML = '<div class="fs-loading">Loading…</div>';
    document.getElementById('fsFoot').innerHTML = '';
    call('get').then(function (d) {
      if (!d.success) { document.getElementById('fsBody').innerHTML = '<div class="fs-note fs-bad">' + esc(d.error || 'Could not load.') + '</div>'; return; }
      S.data = d; render();
    }).catch(function () { document.getElementById('fsBody').innerHTML = '<div class="fs-note fs-bad">Network error.</div>'; });
  }

  var STATE_TXT = { ok: ['Live', 'fs-live'], disabled: ['Off', 'fs-off'], expired: ['Expired', 'fs-warn'], full: ['Limit reached', 'fs-warn'], inactive: ['Form inactive', 'fs-warn'] };
  var TABS = [['link', 'Link'], ['content', 'Content'], ['fields', 'Fields'], ['look', 'Look & feel'], ['limits', 'Limits']];
  var COLS = [[0, 'As designed'], [12, 'Full width'], [9, '¾'], [8, '⅔'], [6, 'Half'], [4, '⅓'], [3, '¼']];

  function sel(id, val, opts) {
    return '<select id="' + id + '">' + opts.map(function (o) { return '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(val) ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') + '</select>';
  }
  function chk(id, on, text) { return '<label class="fs-check fs-span"><input type="checkbox" id="' + id + '"' + (on ? ' checked' : '') + '><span>' + text + '</span></label>'; }
  function color(id, val, ph) {
    return '<div class="fs-color"><input type="color" id="' + id + 'P" value="' + esc(/^#[0-9a-f]{6}$/i.test(val || '') ? val : ph) + '"><input type="text" id="' + id + '" maxlength="7" placeholder="Default" value="' + esc(val) + '"><button type="button" class="fs-link" data-fs="clr" data-t="' + id + '">reset</button></div>';
  }

  function render() {
    var d = S.data, s = d.share, on = s ? !!s.enabled : false, th = (s && s.theme) || {};
    var st = s ? (STATE_TXT[s.state] || STATE_TXT.ok) : STATE_TXT.disabled;
    var tab = S.tab || 'link';
    var h = '<div class="fs-tabs" role="tablist">' + TABS.map(function (t) {
      return '<button type="button" role="tab" class="fs-tab' + (t[0] === tab ? ' on' : '') + '" data-fs="tab" data-t="' + t[0] + '">' + t[1] + '</button>';
    }).join('') + '</div>';

    // ── Link
    h += '<div class="fs-pane" data-p="link"' + (tab === 'link' ? '' : ' hidden') + '>';
    h += '<div class="fs-row fs-toggle"><div><b>Public link</b><div class="fs-hint">Anyone with the link can fill in this form and send a new request. They cannot see, change or delete any existing records.</div></div>'
      + '<label class="fs-switch"><input type="checkbox" id="fsEnabled"' + (on ? ' checked' : '') + '><span></span></label></div>';
    if (s) {
      h += '<div class="fs-linkbox"><span class="fs-pill ' + st[1] + '">' + st[0] + '</span><input type="text" id="fsUrl" readonly value="' + esc(s.url) + '">'
        + '<button type="button" class="nu-btn nu-btn-primary nu-btn-sm" data-fs="copy">Copy</button>'
        + '<a class="nu-btn nu-btn-ghost nu-btn-sm" href="' + esc(s.url) + '" target="_blank" rel="noopener">Open</a></div>'
        + '<div class="fs-meta">' + s.submit_count + ' submission' + (s.submit_count === 1 ? '' : 's') + ' so far · '
        + '<button type="button" class="fs-link" data-fs="regen">Make a new link</button> <span class="fs-hint">(the old link stops working)</span></div>'
        + '<div class="fs-hint">Tip: add <code>&amp;field_name=value</code> to the link to pre-fill a field.</div>';
    } else {
      h += '<div class="fs-note">Turn the switch on and save to create the link.</div>';
    }
    h += '<div class="fs-hint fs-safe">Protection: spam trap, per-visitor rate limit (5 per 10 minutes) and server-side validation. A workflow linked to this form starts automatically for each new request.</div></div>';

    // ── Content
    h += '<div class="fs-pane" data-p="content"' + (tab === 'content' ? '' : ' hidden') + '><div class="fs-grid">'
      + fld('Page title', '<input type="text" id="fsTitleIn" maxlength="200" placeholder="' + esc(d.form_name) + '" value="' + esc(s && s.title) + '">')
      + fld('Button text', '<input type="text" id="fsLabelIn" maxlength="60" placeholder="Submit request" value="' + esc(s && s.submit_label) + '">')
      + fld('Introduction (optional)', '<textarea id="fsIntro" rows="3" maxlength="5000" placeholder="Tell customers what this form is for…">' + esc(s && s.intro) + '</textarea>', true)
      + fld('Thank-you message', '<textarea id="fsOk" rows="2" maxlength="2000" placeholder="Thank you! Your request has been submitted.">' + esc(s && s.success_message) + '</textarea>', true)
      + fld('After submit, go to (optional web address)', '<input type="url" id="fsRedir" placeholder="https://example.com/thanks" value="' + esc(s && s.redirect_url) + '">', true)
      + fld('Footer text', '<input type="text" id="thFoot" maxlength="300" placeholder="Your answers are sent securely to the form owner." value="' + esc(th.footer_text) + '">', true)
      + chk('thShowTitle', th.show_title !== 0, 'Show the page title')
      + chk('thHideFoot', !!th.hide_footer, 'Hide the footer')
      + '</div></div>';

    // ── Fields
    h += '<div class="fs-pane" data-p="fields"' + (tab === 'fields' ? '' : ' hidden') + '>'
      + '<div class="fs-hint fs-mb">Tick the fields to show. The form’s tabs, sections and columns are kept. Use ⚙ to change a label, placeholder, width, default value or whether it is required — on this link only.</div><div class="fs-flist">';
    var any = false;
    d.fields.forEach(function (f) {
      if (f.public) {
        any = true; var c = f.cfg || {};
        h += '<div class="fs-fitem" data-name="' + esc(f.name) + '"><label class="fs-field"><input type="checkbox" class="fsF" value="' + esc(f.name) + '"' + (f.selected ? ' checked' : '') + '><span><b>' + esc(f.label) + '</b>'
          + (f.required ? ' <i class="fs-req">required</i>' : '') + '<small>' + esc(f.type) + '</small></span></label>'
          + '<button type="button" class="fs-gear" data-fs="gear" title="Customise this field" aria-label="Customise ' + esc(f.label) + '">⚙</button>'
          + '<div class="fs-fcfg" hidden><div class="fs-grid">'
          + fld('Label', '<input type="text" class="fc" data-k="label" placeholder="' + esc(f.label) + '" value="' + esc(c.label) + '">')
          + fld('Placeholder', '<input type="text" class="fc" data-k="placeholder" value="' + esc(c.placeholder) + '">')
          + fld('Help text', '<input type="text" class="fc" data-k="help" value="' + esc(c.help) + '">', true)
          + fld('Default value', '<input type="text" class="fc" data-k="default" value="' + esc(c.default) + '">')
          + fld('Width', '<select class="fc" data-k="col">' + COLS.map(function (o) { return '<option value="' + o[0] + '"' + (+(c.col || 0) === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select>')
          + fld('Required', '<select class="fc" data-k="required">' + [['', 'As designed'], ['1', 'Required'], ['0', 'Optional']].map(function (o) {
              var cur = c.required === true ? '1' : (c.required === false ? '0' : '');
              return '<option value="' + o[0] + '"' + (cur === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select>')
          + '</div></div></div>';
      } else {
        h += '<div class="fs-field fs-skip"><span class="fs-x2">–</span><span><b>' + esc(f.label) + '</b><small>' + esc(f.reason) + '</small></span></div>';
      }
    });
    if (!any) h += '<div class="fs-note fs-bad">This form has no fields that can be shown publicly.</div>';
    h += '</div></div>';

    // ── Look & feel
    h += '<div class="fs-pane" data-p="look"' + (tab === 'look' ? '' : ' hidden') + '><div class="fs-grid">'
      + fld('Page width', sel('thWidth', th.width || 'medium', [['narrow', 'Narrow (520 px)'], ['medium', 'Medium (680 px)'], ['wide', 'Wide (920 px)'], ['full', 'Extra wide (1280 px)']]))
      + fld('Colour mode', sel('thMode', th.mode || 'auto', [['auto', 'Follow the visitor’s device'], ['light', 'Always light'], ['dark', 'Always dark']]))
      + fld('Accent colour (buttons, tabs, focus)', color('thAccent', th.accent, '#2563eb'))
      + fld('Page background', color('thBg', th.bg, '#f4f6fb'))
      + fld('Form background', color('thCard', th.card_bg, '#ffffff'))
      + fld('Text colour', color('thText', th.text, '#14213d'))
      + fld('Corners', sel('thRadius', th.radius || 'md', [['none', 'Square'], ['sm', 'Slightly rounded'], ['md', 'Rounded'], ['lg', 'Very rounded']]))
      + fld('Font', sel('thFont', th.font || 'system', [['system', 'Modern sans-serif'], ['serif', 'Serif'], ['rounded', 'Rounded'], ['mono', 'Monospace']]))
      + fld('Spacing', sel('thDensity', th.density || 'comfortable', [['comfortable', 'Comfortable'], ['compact', 'Compact']]))
      + fld('Title alignment', sel('thAlign', th.title_align || 'left', [['left', 'Left'], ['center', 'Centre']]))
      + fld('Sections &amp; groups', sel('thSection', th.section_style || 'boxed', [['boxed', 'Boxed cards'], ['flat', 'Underlined heading'], ['plain', 'Minimal']]))
      + fld('Tabs', sel('thTab', th.tab_style || 'underline', [['underline', 'Underline'], ['pills', 'Pills']]))
      + fld('Submit button position', sel('thBtnAlign', th.button_align || 'left', [['left', 'Left'], ['center', 'Centre'], ['right', 'Right']]))
      + chk('thBtnFull', !!th.button_full, 'Make the submit button full width')
      + fld('Logo (https:// image address)', '<input type="url" id="thLogo" placeholder="https://example.com/logo.png" value="' + esc(th.logo_url) + '">', true)
      + fld('Logo height (px)', '<input type="number" id="thLogoH" min="16" max="160" value="' + esc(th.logo_height || 48) + '">')
      + fld('Custom CSS (advanced)', '<textarea id="thCss" rows="4" spellcheck="false" placeholder=".card { box-shadow: none; }">' + esc(th.custom_css) + '</textarea>', true)
      + '</div></div>';

    // ── Limits
    h += '<div class="fs-pane" data-p="limits"' + (tab === 'limits' ? '' : ' hidden') + '><div class="fs-grid">'
      + fld('Stop accepting after (date &amp; time)', '<input type="datetime-local" id="fsExp" value="' + esc(s && s.expires_at) + '">')
      + fld('Maximum submissions', '<input type="number" id="fsMax" min="1" step="1" placeholder="No limit" value="' + esc(s && s.max_submissions) + '">')
      + fld('Email me when a request arrives (comma separated)', '<input type="text" id="fsMail" placeholder="support@example.com" value="' + esc(s && s.notify_email) + '">', true)
      + chk('fsConfirm', s && s.confirm_email, 'Email the customer a confirmation (uses the first email field on the form)')
      + '</div></div>';

    document.getElementById('fsBody').innerHTML = h;
    document.getElementById('fsFoot').innerHTML =
      (s ? '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm fs-del" data-fs="del">Remove link</button>' : '<span></span>')
      + '<div class="fs-actions"><button type="button" class="nu-btn nu-btn-ghost" data-fs="close">Close</button><button type="button" class="nu-btn nu-btn-primary" data-fs="save" id="fsSave">Save</button></div>';
    // colour picker <-> text sync
    ['thAccent', 'thBg', 'thCard', 'thText'].forEach(function (id) {
      var p = document.getElementById(id + 'P'), t = document.getElementById(id);
      if (p && t) p.addEventListener('input', function () { t.value = p.value; });
    });
  }

  function fld(label, ctl, span) { return '<div class="fs-f' + (span ? ' fs-span' : '') + '"><label>' + label + '</label>' + ctl + '</div>'; }
  function v(id) { var e = document.getElementById(id); return e ? e.value.trim() : ''; }
  function c(id) { var e = document.getElementById(id); return e && e.checked ? 1 : 0; }

  /* The panes are all in the DOM, so values from every tab are saved no matter which tab is showing. */
  function save() {
    if (S.busy) return;
    var fields = [].map.call(document.querySelectorAll('#fsModal .fsF:checked'), function (e) { return e.value; });
    var cfg = {};
    [].forEach.call(document.querySelectorAll('#fsModal .fs-fitem'), function (it) {
      var o = {};
      [].forEach.call(it.querySelectorAll('.fc'), function (el) {
        var k = el.getAttribute('data-k'), val = el.value.trim();
        if (k === 'col') { if (+val) o.col = +val; } else if (k === 'required') { if (val !== '') o.required = val === '1'; } else if (val !== '') o[k] = val;
      });
      if (Object.keys(o).length) cfg[it.getAttribute('data-name')] = o;
    });
    var body = {
      enabled: c('fsEnabled'),
      title: v('fsTitleIn'), submit_label: v('fsLabelIn'), intro: v('fsIntro'), success_message: v('fsOk'),
      redirect_url: v('fsRedir'), notify_email: v('fsMail'), confirm_email: c('fsConfirm'),
      expires_at: v('fsExp'), max_submissions: v('fsMax'), fields: fields, field_cfg: cfg,
      theme: {
        width: v('thWidth'), mode: v('thMode'), accent: v('thAccent'), bg: v('thBg'), card_bg: v('thCard'), text: v('thText'),
        radius: v('thRadius'), font: v('thFont'), density: v('thDensity'), title_align: v('thAlign'),
        section_style: v('thSection'), tab_style: v('thTab'), button_align: v('thBtnAlign'), button_full: c('thBtnFull'),
        show_title: c('thShowTitle'), hide_footer: c('thHideFoot'), footer_text: v('thFoot'),
        logo_url: v('thLogo'), logo_height: v('thLogoH'), custom_css: document.getElementById('thCss').value
      }
    };
    S.busy = true; var btn = document.getElementById('fsSave'); btn.disabled = true; btn.textContent = 'Saving…';
    call('save', body).then(function (d) {
      S.busy = false;
      if (!d.success) { toast(d.error || 'Could not save.', 'error'); btn.disabled = false; btn.textContent = 'Save'; return; }
      S.data = d; render(); toast(d.share && d.share.enabled ? 'Saved. The link is live.' : 'Saved. The link is switched off.', 'success');
    }).catch(function () { S.busy = false; btn.disabled = false; btn.textContent = 'Save'; toast('Network error.', 'error'); });
  }

  function onClick(e) {
    var t = e.target.closest('[data-fs]'); if (!t) return;
    var a = t.getAttribute('data-fs');
    if (a === 'close') close();
    else if (a === 'tab') {
      S.tab = t.getAttribute('data-t');
      [].forEach.call(document.querySelectorAll('#fsModal .fs-tab'), function (b) { b.classList.toggle('on', b === t); });
      [].forEach.call(document.querySelectorAll('#fsModal .fs-pane'), function (p) { p.hidden = p.getAttribute('data-p') !== S.tab; });
    }
    else if (a === 'gear') { var box = t.parentNode.querySelector('.fs-fcfg'); box.hidden = !box.hidden; t.classList.toggle('on', !box.hidden); }
    else if (a === 'clr') { var tid = t.getAttribute('data-t'); document.getElementById(tid).value = ''; }
    else if (a === 'save') save();
    else if (a === 'copy') {
      var inp = document.getElementById('fsUrl'); inp.select();
      var done = function () { toast('Link copied', 'success'); };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(inp.value).then(done, function () { document.execCommand('copy'); done(); });
      else { document.execCommand('copy'); done(); }
    } else if (a === 'regen') {
      if (!confirm('Make a new link? The current link will stop working immediately.')) return;
      call('regenerate', {}).then(function (d) { if (d.success) { S.data = d; render(); toast('New link created', 'success'); } else toast(d.error || 'Failed', 'error'); });
    } else if (a === 'del') {
      if (!confirm('Remove the public link for this form? Existing records are not affected.')) return;
      call('delete', {}).then(function (d) { if (d.success) { S.data = d; render(); toast('Link removed', 'success'); } else toast(d.error || 'Failed', 'error'); });
    }
  }

  window.FormShare = { open: open, close: close };
})();
