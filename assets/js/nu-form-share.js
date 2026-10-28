/* Nuvis StudioX — "Share form" dialog: a public link so customers can submit a request without signing in. */
(function () {
  'use strict';
  var api = 'api/form_share.php';
  var S = { code: '', label: '', data: null, busy: false };

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
    S.code = code; S.label = label || code; S.data = null;
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

  function render() {
    var d = S.data, s = d.share, on = s ? !!s.enabled : false;
    var st = s ? (STATE_TXT[s.state] || STATE_TXT.ok) : STATE_TXT.disabled;
    var h = '';
    h += '<div class="fs-row fs-toggle"><div><b>Public link</b><div class="fs-hint">Anyone with the link can fill in this form and send a new request. They cannot see, change or delete any existing records.</div></div>'
      + '<label class="fs-switch"><input type="checkbox" id="fsEnabled"' + (on ? ' checked' : '') + '><span></span></label></div>';

    h += '<div id="fsConfig"' + (on || s ? '' : ' class="fs-dim"') + '>';
    if (s) {
      h += '<div class="fs-linkbox"><span class="fs-pill ' + st[1] + '">' + st[0] + '</span><input type="text" id="fsUrl" readonly value="' + esc(s.url) + '">'
        + '<button type="button" class="nu-btn nu-btn-primary nu-btn-sm" data-fs="copy">Copy</button>'
        + '<a class="nu-btn nu-btn-ghost nu-btn-sm" href="' + esc(s.url) + '" target="_blank" rel="noopener">Open</a></div>'
        + '<div class="fs-meta">' + s.submit_count + ' submission' + (s.submit_count === 1 ? '' : 's') + ' so far · '
        + '<button type="button" class="fs-link" data-fs="regen">Make a new link</button> <span class="fs-hint">(the old link stops working)</span></div>';
    } else {
      h += '<div class="fs-note">Turn the switch on and save to create the link.</div>';
    }

    h += '<div class="fs-sec">What the customer sees</div><div class="fs-grid">'
      + fld('Page title', '<input type="text" id="fsTitleIn" maxlength="200" placeholder="' + esc(d.form_name) + '" value="' + esc(s && s.title) + '">')
      + fld('Button text', '<input type="text" id="fsLabelIn" maxlength="60" placeholder="Submit request" value="' + esc(s && s.submit_label) + '">')
      + fld('Introduction (optional)', '<textarea id="fsIntro" rows="3" maxlength="5000" placeholder="Tell customers what this form is for…">' + esc(s && s.intro) + '</textarea>', true)
      + fld('Thank-you message', '<textarea id="fsOk" rows="2" maxlength="2000" placeholder="Thank you! Your request has been submitted.">' + esc(s && s.success_message) + '</textarea>', true)
      + fld('After submit, go to (optional web address)', '<input type="url" id="fsRedir" placeholder="https://example.com/thanks" value="' + esc(s && s.redirect_url) + '">', true)
      + '</div>';

    h += '<div class="fs-sec">Fields to show <span class="fs-hint">Only simple fields can be public.</span></div><div class="fs-fields">';
    var any = false;
    d.fields.forEach(function (f) {
      if (f.public) {
        any = true;
        h += '<label class="fs-field"><input type="checkbox" class="fsF" value="' + esc(f.name) + '"' + (f.selected ? ' checked' : '') + '><span><b>' + esc(f.label) + '</b>'
          + (f.required ? ' <i class="fs-req">required</i>' : '') + '<small>' + esc(f.type) + '</small></span></label>';
      } else {
        h += '<div class="fs-field fs-skip"><span class="fs-x2">–</span><span><b>' + esc(f.label) + '</b><small>' + esc(f.reason) + '</small></span></div>';
      }
    });
    if (!any) h += '<div class="fs-note fs-bad">This form has no fields that can be shown publicly.</div>';
    h += '</div>';

    h += '<div class="fs-sec">Limits &amp; notifications</div><div class="fs-grid">'
      + fld('Stop accepting after (date &amp; time)', '<input type="datetime-local" id="fsExp" value="' + esc(s && s.expires_at) + '">')
      + fld('Maximum submissions', '<input type="number" id="fsMax" min="1" step="1" placeholder="No limit" value="' + esc(s && s.max_submissions) + '">')
      + fld('Email me when a request arrives (comma separated)', '<input type="text" id="fsMail" placeholder="support@example.com" value="' + esc(s && s.notify_email) + '">', true)
      + '<label class="fs-check fs-span"><input type="checkbox" id="fsConfirm"' + (s && s.confirm_email ? ' checked' : '') + '><span>Email the customer a confirmation (uses the first email field on the form)</span></label>'
      + '</div>';
    h += '<div class="fs-hint fs-safe">Protection: spam trap, per-visitor rate limit (5 per 10 minutes) and server-side validation. A linked workflow starts automatically for each new request.</div>';
    h += '</div>';
    document.getElementById('fsBody').innerHTML = h;

    document.getElementById('fsFoot').innerHTML =
      (s ? '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm fs-del" data-fs="del">Remove link</button>' : '<span></span>')
      + '<div class="fs-actions"><button type="button" class="nu-btn nu-btn-ghost" data-fs="close">Close</button><button type="button" class="nu-btn nu-btn-primary" data-fs="save" id="fsSave">Save</button></div>';

    var en = document.getElementById('fsEnabled');
    en.addEventListener('change', function () { document.getElementById('fsConfig').classList.toggle('fs-dim', !en.checked && !S.data.share); });
  }

  function fld(label, ctl, span) { return '<div class="fs-f' + (span ? ' fs-span' : '') + '"><label>' + label + '</label>' + ctl + '</div>'; }
  function v(id) { var e = document.getElementById(id); return e ? e.value.trim() : ''; }

  function save() {
    if (S.busy) return;
    var fields = [].map.call(document.querySelectorAll('#fsModal .fsF:checked'), function (e) { return e.value; });
    var body = {
      enabled: document.getElementById('fsEnabled').checked ? 1 : 0,
      title: v('fsTitleIn'), submit_label: v('fsLabelIn'), intro: v('fsIntro'), success_message: v('fsOk'),
      redirect_url: v('fsRedir'), notify_email: v('fsMail'),
      confirm_email: document.getElementById('fsConfirm').checked ? 1 : 0,
      expires_at: v('fsExp'), max_submissions: v('fsMax'), fields: fields
    };
    S.busy = true; var btn = document.getElementById('fsSave'); btn.disabled = true; btn.textContent = 'Saving…';
    call('save', body).then(function (d) {
      S.busy = false;
      if (!d.success) { toast(d.error || 'Could not save.', 'error'); btn.disabled = false; btn.textContent = 'Save'; return; }
      S.data = d; render(); toast(d.share && d.share.enabled ? 'Link is live. Copy it to share.' : 'Saved. The link is switched off.', 'success');
    }).catch(function () { S.busy = false; btn.disabled = false; btn.textContent = 'Save'; toast('Network error.', 'error'); });
  }

  function onClick(e) {
    var t = e.target.closest('[data-fs]'); if (!t) return;
    var a = t.getAttribute('data-fs');
    if (a === 'close') close();
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
