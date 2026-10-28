/* Shared file-chip UI for Upload Button fields (forms + subforms).
 * Shows a friendly file name (no random storage prefix / no raw value), a thumbnail or type badge,
 * a View button (opens the in-app viewer) and a per-file remove button. DOM-only, no innerHTML. */
(function (w) {
  'use strict';
  var IMG = /\.(jpe?g|png|gif|webp|svg)$/i;

  function isUrl(v) { return /^https?:\/\//i.test(v); }
  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }

  function displayName(v) {
    v = String(v || '').trim();
    if (isUrl(v)) { try { v = decodeURIComponent(new URL(v).pathname.split('/').pop() || ''); } catch (e) { v = ''; } return v || 'Cloud file'; }
    v = v.split('/').pop();
    return v.replace(/^[0-9a-f]{13}_/i, '') || v;
  }
  function typeLabel(v) {
    var m = /\.([a-z0-9]{1,5})$/i.exec(displayName(v)); return m ? m[1].toUpperCase() : 'FILE';
  }
  function split(value) { return String(value || '').split(',').map(function (x) { return x.trim(); }).filter(Boolean); }

  /** Render chips for `value` (comma separated) into `box`. opts: {readonly, onRemove(newValue)} */
  function render(box, value, opts) {
    opts = opts || {};
    while (box.firstChild) box.removeChild(box.firstChild);
    var files = split(value);
    box.classList.toggle('nuu-empty', files.length === 0);
    files.forEach(function (f, i) {
      var chip = el('div', 'nuu-chip');
      var thumb;
      if (opts.thumbs !== false && !isUrl(f) && IMG.test(f)) {
        thumb = el('img', 'nuu-thumb'); thumb.alt = ''; thumb.loading = 'lazy'; thumb.src = 'api/file.php?name=' + encodeURIComponent(f.split('/').pop());
      } else {
        thumb = el('span', 'nuu-badge', typeLabel(f));
      }
      chip.appendChild(thumb);
      var name = el('span', 'nuu-name', displayName(f)); name.title = displayName(f);
      chip.appendChild(name);
      var view = el('button', 'nuu-act', isUrl(f) ? 'Open' : 'View'); view.type = 'button';
      view.addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation();
        if (isUrl(f)) { w.open(f, '_blank', 'noopener,noreferrer'); }
        else if (w.NuViewer) { w.NuViewer.open({ name: f.split('/').pop() }); }
      });
      chip.appendChild(view);
      if (!opts.readonly) {
        var rm = el('button', 'nuu-rm', '×'); rm.type = 'button'; rm.title = 'Remove'; rm.setAttribute('aria-label', 'Remove ' + displayName(f));
        rm.addEventListener('click', function (e) {
          e.preventDefault(); e.stopPropagation();
          var rest = files.slice(); rest.splice(i, 1);
          if (opts.onRemove) opts.onRemove(rest.join(', '));
        });
        chip.appendChild(rm);
      }
      box.appendChild(chip);
    });
  }

  /** One-line rules summary, e.g. "PDF, PNG · max 5 MB · up to 3 files". */
  function rules(o) {
    var p = [];
    var ex = String(o.allowed || '').split(',').map(function (x) { return x.trim().replace(/^\./, '').toUpperCase(); }).filter(Boolean);
    if (ex.length) p.push(ex.slice(0, 6).join(', ') + (ex.length > 6 ? '…' : ''));
    if (o.maxMb) p.push('max ' + o.maxMb + ' MB');
    if (o.multiple && o.maxFiles) p.push('up to ' + o.maxFiles + ' files'); else if (!o.multiple) p.push('1 file');
    return p.join(' · ');
  }

  w.NuUploadField = { render: render, displayName: displayName, rules: rules };
})(window);
