/**
 * nuSubform — FK-aware runtime subform engine
 * Supports three views: grid | form | inline
 */
(function (window) {
  'use strict';

  /* ── pending queue keyed by container element ─────────────────────── */
  var _pendingRows = new WeakMap();

  function getPending(container) {
    if (!_pendingRows.has(container)) _pendingRows.set(container, []);
    return _pendingRows.get(container);
  }

  /* ── tiny helpers ─────────────────────────────────────────────────── */
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
      .replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }
  function apiJson(url, opts) {
    return fetch(url, opts || {}).then(function (r) { return r.json(); });
  }
  function toast(msg, type) {
    if (window.NuApp && window.NuApp.toast) { window.NuApp.toast(msg, type); return; }
    alert(msg);
  }

  /* ── determine if a field is FK / server-readonly ─────────────────── */
  function isFkField(f) {
    return !!(f.is_fk || f.isfk);
  }
  function isServerReadonly(f) {
    return !!(f.server_readonly || f.serverreadonly);
  }
  function shouldStripFromPost(f) {
    return isFkField(f) || isServerReadonly(f);
  }

  /* ── strip FK / server-readonly keys from a data object ──────────── */
  function stripProtectedFields(data, allFields) {
    if (!allFields || !allFields.length) return data;
    var out = Object.assign({}, data);
    allFields.forEach(function (f) {
      if (shouldStripFromPost(f)) {
        var fname = f.name || f.fieldname || '';
        if (fname) delete out[fname];
      }
    });
    return out;
  }

  /* ── get container meta ───────────────────────────────────────────── */
  function meta(container) {
    return {
      code:               container.dataset.subformCode || '',
      fk:                 container.dataset.subformFk   || '',
      view:               container.dataset.subformView  || 'grid',
      parentId:           container.dataset.parentId    || '',
      parentFormCode:     container.dataset.parentFormCode || '',
      searchable:         container.dataset.subformSearchable === '1',
      selectedFieldsOnly: container.dataset.subformSelectedFieldsOnly === '1',
      viewRoles:          container.dataset.subformViewRoles || '',
      editRoles:          container.dataset.subformEditRoles || '',
      q:                  container.dataset.subformQ || '',
      page:               parseInt(container.dataset.subformPage || '1', 10),
      pageSize:           parseInt(container.dataset.subformPageSize || '10', 10)
    };
  }

  /* ── check user view & edit permissions ────────────────────────────── */
  function checkPermission(container, action) {
    var m = meta(container);
    var userRole = (window.nuUserRole || '').toLowerCase();
    var isAd = userRole === 'admin' || userRole === 'globeadmin';

    if (action === 'view') {
      if (!m.viewRoles) return true; // Empty view roles defaults to allowed
      var allowed = m.viewRoles.split(',').map(function(r) { return r.trim().toLowerCase(); });
      if (allowed.indexOf('*') !== -1) return true;
      if (isAd) return true;
      return allowed.indexOf(userRole) !== -1;
    }

    if (action === 'edit') {
      if (window.NuPerms && typeof window.NuPerms.canEdit === 'function') {
        if (!window.NuPerms.canEdit() && !window.NuPerms.canAdd()) return false;
      }
      if (!m.editRoles) return true; // Empty edit roles defaults to allowed
      var allowed = m.editRoles.split(',').map(function(r) { return r.trim().toLowerCase(); });
      if (allowed.indexOf('*') !== -1) return true;
      if (isAd) return true;
      return allowed.indexOf(userRole) !== -1;
    }
    return true;
  }

  /* ── ensure toolbar ────────────────────────────────────────────────── */
  function ensureToolbar(container) {
    var tb = container.querySelector('.nu-subform-toolbar');
    if (!tb) {
      tb = document.createElement('div');
      tb.className = 'nu-subform-toolbar';
      tb.style.cssText = 'display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:var(--bg-elevated,#f8f9fa);border-bottom:1px solid #ddd;';
      container.insertBefore(tb, container.firstChild);
    }
    updateToolbar(container);
  }

  function updateToolbar(container) {
    var tb = container.querySelector('.nu-subform-toolbar');
    if (!tb) return;

    var m = meta(container);
    var labelText = container.getAttribute('data-subform-label') || m.code;

    tb.innerHTML = '';

    var lblSpan = document.createElement('span');
    lblSpan.style.cssText = 'font-weight:600;font-size:13px;';
    lblSpan.textContent = labelText;
    tb.appendChild(lblSpan);

    var rightSection = document.createElement('div');
    rightSection.style.cssText = 'display:flex;align-items:center;gap:8px;';

    // Search Box
    if (m.searchable && m.parentId) {
      var searchGroup = document.createElement('div');
      searchGroup.style.cssText = 'display:flex;align-items:center;gap:4px;';

      var searchInp = document.createElement('input');
      searchInp.type = 'text';
      searchInp.className = 'nu-input nu-subform-search-input';
      searchInp.placeholder = 'Search...';
      searchInp.value = m.q || '';
      searchInp.style.cssText = 'width:130px;padding:3px 8px;font-size:12px;display:inline-block;margin:0;height:28px;';

      var triggerSearch = function () {
        container.dataset.subformQ = searchInp.value;
        container.dataset.subformPage = '1';
        load(container);
      };

      searchInp.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          triggerSearch();
        }
      });

      var searchBtn = document.createElement('button');
      searchBtn.type = 'button';
      searchBtn.className = 'nu-btn nu-btn-ghost nu-btn-sm';
      searchBtn.style.cssText = 'height:28px;padding:0 8px;display:flex;align-items:center;justify-content:center;';
      searchBtn.innerHTML = '🔍';
      searchBtn.onclick = triggerSearch;

      searchGroup.appendChild(searchInp);
      searchGroup.appendChild(searchBtn);
      rightSection.appendChild(searchGroup);
    }

    // Add Row
    if (checkPermission(container, 'edit')) {
      var addBtn = document.createElement('button');
      addBtn.type = 'button';
      addBtn.className = 'nu-btn nu-btn-primary nu-btn-sm';
      addBtn.textContent = '+ Add Row';
      addBtn.onclick = function () {
        nuSubform.addRow(addBtn);
      };
      rightSection.appendChild(addBtn);
    }

    tb.appendChild(rightSection);
  }

  /* ── ensure pagination footer ───────────────────────────────────────── */
  function ensureFooter(container, totalRecords) {
    var footer = container.querySelector('.nu-subform-footer');
    if (!footer) {
      footer = document.createElement('div');
      footer.className = 'nu-subform-footer';
      footer.style.cssText = 'display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:var(--bg-elevated,#f8f9fa);border-top:1px solid #ddd;font-size:12px;';
      container.appendChild(footer);
    }
    updateFooter(container, totalRecords);
  }

  function updateFooter(container, totalRecords) {
    var footer = container.querySelector('.nu-subform-footer');
    if (!footer) return;

    var m = meta(container);
    if (!m.parentId) {
      footer.style.display = 'none';
      return;
    }
    footer.style.display = 'flex';

    var page = m.page || 1;
    var pageSize = m.pageSize || 10;
    var totalPages = Math.max(1, Math.ceil(totalRecords / pageSize));

    footer.innerHTML = '';

    var infoSpan = document.createElement('span');
    infoSpan.style.color = '#666';
    infoSpan.textContent = 'Showing page ' + page + ' of ' + totalPages + ' (Total ' + totalRecords + ' rows)';
    footer.appendChild(infoSpan);

    var navDiv = document.createElement('div');
    navDiv.style.cssText = 'display:flex;gap:4px;';

    var prevBtn = document.createElement('button');
    prevBtn.type = 'button';
    prevBtn.className = 'nu-btn nu-btn-ghost nu-btn-sm';
    prevBtn.textContent = '← Prev';
    prevBtn.disabled = page <= 1;
    prevBtn.onclick = function () {
      if (page > 1) {
        container.dataset.subformPage = String(page - 1);
        load(container);
      }
    };
    navDiv.appendChild(prevBtn);

    var nextBtn = document.createElement('button');
    nextBtn.type = 'button';
    nextBtn.className = 'nu-btn nu-btn-ghost nu-btn-sm';
    nextBtn.textContent = 'Next →';
    nextBtn.disabled = page >= totalPages;
    nextBtn.onclick = function () {
      if (page < totalPages) {
        container.dataset.subformPage = String(page + 1);
        load(container);
      }
    };
    navDiv.appendChild(nextBtn);

    footer.appendChild(navDiv);
  }

  /* ── load & render rows ───────────────────────────────────────────── */
  function load(container) {
    if (!checkPermission(container, 'view')) {
      container.style.display = 'none';
      return;
    } else {
      container.style.display = '';
    }

    ensureToolbar(container);

    var m    = meta(container);
    var body = container.querySelector('.nu-subform-body');
    if (!body) return;

    /* No parent_id yet (new unsaved parent) — show pending rows only */
    if (!m.parentId) {
      console.warn("[nuSubform] Loading subform with empty parentId. Code: '" + m.code + "', FK: '" + m.fk + "' — only pending queue will be shown.");
      var footer = container.querySelector('.nu-subform-footer');
      if (footer) footer.style.display = 'none';

      var fieldsUrl = 'api/form.php?action=subform_fields&code=' + encodeURIComponent(m.code);
      apiJson(fieldsUrl)
        .then(function (json) {
          console.log("[nuSubform] Fields response for code '" + m.code + "':", json);
          if (!json.success) {
            body.innerHTML = '<div style="padding:12px;color:red;">' + esc(json.error) + '</div>';
            return;
          }
          var data      = json.data || {};
          var allFields  = data.all_fields  || data.layout || [];
          var gridFields = data.layout      || [];
          var pk         = data.pk || 'id';
          container._sfAllFields  = allFields;
          container._sfGridFields = gridFields;
          renderWithPending(container, gridFields, allFields, [], pk);
        })
        .catch(function (e) {
          console.error("[nuSubform] Error loading subform fields:", e);
          body.innerHTML = '<div style="padding:12px;color:red;">' + esc(e.message) + '</div>';
        });
      return;
    }

    /* Has parentId — fetch real rows */
    console.log("[nuSubform] Loading subform. Code: '" + m.code + "', FK: '" + m.fk + "', parentId: '" + m.parentId + "', parentFormCode: '" + m.parentFormCode + "'");
    body.innerHTML = '<div style="padding:20px;text-align:center;color:#666;font-size:13px;">Loading...</div>';

    var listUrl = 'api/form.php?action=subform_list&code=' + encodeURIComponent(m.code)
      + '&fk='        + encodeURIComponent(m.fk)
      + '&parent_id=' + encodeURIComponent(m.parentId)
      + '&parent_form_code=' + encodeURIComponent(m.parentFormCode)
      + '&q='         + encodeURIComponent(m.q || '')
      + '&page='      + encodeURIComponent(m.page || 1)
      + '&page_size=' + encodeURIComponent(m.pageSize || 10);

    console.log("[nuSubform] Fetching subform records URL: " + listUrl);

    apiJson(listUrl)
    .then(function (json) {
      console.log("[nuSubform] Subform records response for '" + m.code + "':", json);
      if (!json.success) {
        console.error("[nuSubform] Error response returned from server:", json.error);
        body.innerHTML = '<div style="padding:12px;color:red;">' + esc(json.error) + '</div>';
        return;
      }
      var data       = json.data || {};
      var gridFields = data.layout     || [];
      var allFields  = data.all_fields || gridFields;
      var records    = data.records    || [];
      var pk         = data.pk || 'id';
      var total      = data.total || 0;

      container._sfAllFields  = allFields;
      container._sfGridFields = gridFields;

      renderWithPending(container, gridFields, allFields, records, pk);
      ensureFooter(container, total);
    })
    .catch(function (e) {
      console.error("[nuSubform] Catch error loading subform records:", e);
      body.innerHTML = '<div style="padding:12px;color:red;">' + esc(e.message) + '</div>';
    });
  }

  /* ── merge saved records + pending rows before rendering ─────────── */
  function renderWithPending(container, gridFields, allFields, records, pk) {
    var pending = getPending(container);
    var pendingRecords = pending.map(function (item, idx) {
      var r = Object.assign({}, item.data);
      r[pk] = '__pending__' + idx;
      r._pending = true;
      return r;
    });
    var allRecords = records.concat(pendingRecords);
    container._sfAllRecords = allRecords;
    container._sfPk         = pk;
    render(container, gridFields, allFields, allRecords, pk);
    if (typeof window.nuCalculateFields === 'function') {
      window.nuCalculateFields(document);
    }
  }

  function render(container, gridFields, allFields, records, pk) {
    var m    = meta(container);
    var body = container.querySelector('.nu-subform-body');
    if (!body) return;

    /* Grid columns: strip UI-only types AND FK fields */
    var userRole = (window.nuUserRole || '').toLowerCase();
    var isAdmin = userRole === 'admin' || userRole === 'globeadmin';
    var displayCols = gridFields.filter(function (f) {
      var t = f.type || f.fieldtype || 'text';
      if (['html','heading','divider','fieldset','subform','button'].indexOf(t) !== -1) return false;
      if (isFkField(f)) return false;

      var fname = (f.name || f.fieldname || '').toLowerCase();
      if (fname === (m.fk || '').toLowerCase()) return false;

      var hidden = f.hidden === true || f.hidden === '1' || f.hidden === 1 || f.hidden === 'true';
      var hideInGrid = f.hide_in_grid === true || f.hide_in_grid === '1' || f.hide_in_grid === 1 || f.hide_in_grid === 'true' || f.hideingrid === true || f.hideingrid === '1' || f.hideingrid === 1 || f.hideingrid === 'true';
      var hiddenForNormal = f.hidden_for_normal_users === true || f.hidden_for_normal_users === '1' || f.hidden_for_normal_users === 1 || f.hidden_for_normal_users === 'true' || f.hiddenfornormalusers === true || f.hiddenfornormalusers === '1' || f.hiddenfornormalusers === 1 || f.hiddenfornormalusers === 'true';

      if (hidden) return false;
      if (hideInGrid) return false;
      if (hiddenForNormal && !isAdmin) return false;

      return true;
    });

    var hasEditPermission = checkPermission(container, 'edit');

    if (m.view === 'grid')        body.innerHTML = renderGrid(displayCols, records, pk, m, hasEditPermission);
    else if (m.view === 'inline') {
      body.innerHTML = renderInline(displayCols, records, pk, m, hasEditPermission);
      if (typeof window.nuInitSelect2 === 'function') {
        window.nuInitSelect2(body);
      }
      if (typeof window.nuCalculateFields === 'function') {
        window.nuCalculateFields(body);
      }
      if (typeof window.nuInitUploadButtons === 'function') {
        window.nuInitUploadButtons(body);
      }
    }
    else                          body.innerHTML = renderFormList(displayCols, records, pk, m, hasEditPermission);

    /* ── bind delete buttons ── */
    body.querySelectorAll('[data-sf-delete]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = btn.dataset.sfDelete;
        if (String(id).indexOf('__pending__') === 0) {
          var idx = parseInt(id.replace('__pending__', ''), 10);
          getPending(container).splice(idx, 1);
          load(container);
        } else {
          deleteRow(container, id, pk);
        }
      });
    });

    /* ── bind edit buttons ── */
    body.querySelectorAll('[data-sf-edit]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id         = btn.dataset.sfEdit;
        var useAllFlds = container._sfAllFields || allFields;
        if (String(id).indexOf('__pending__') === 0) {
          var idx  = parseInt(id.replace('__pending__', ''), 10);
          var item = getPending(container)[idx];
          if (item) openModal(container, item.allFields || useAllFlds, item.pk || pk, null, [], item.data, idx);
        } else {
          openModal(container, useAllFlds, pk, id, records);
        }
      });
    });

    /* ── bind inline save buttons ── */
    body.querySelectorAll('[data-sf-inline-save]').forEach(function (btn) {
      btn.addEventListener('click', function () { saveInlineRow(container, btn, pk, allFields); });
    });
  }

  /* ── grid view ────────────────────────────────────────────────────── */
  function renderGrid(displayCols, records, pk, m, hasEditPermission) {
    var html = '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
    html += '<thead><tr style="background:var(--bg-elevated,#f8f9fa);">';
    displayCols.forEach(function (f) {
      html += '<th style="padding:8px 10px;text-align:left;font-weight:600;border-bottom:1px solid #ddd;">'
        + esc(f.label || f.fieldlabel || f.name || f.fieldname || '') + '</th>';
    });
    if (hasEditPermission) {
      html += '<th style="padding:8px 10px;width:100px;">Actions</th>';
    }
    html += '</tr></thead><tbody>';

    if (!records.length) {
      html += '<tr><td colspan="' + (displayCols.length + (hasEditPermission ? 1 : 0))
        + '" style="padding:20px;text-align:center;color:#999;">No rows yet</td></tr>';
    } else {
      records.forEach(function (row) {
        var id      = row[pk];
        var pending = !!row._pending;
        var rowStyle = pending
          ? 'border-bottom:1px solid #eee;opacity:0.7;background:#fffbe6;'
          : 'border-bottom:1px solid #eee;';
        html += '<tr style="' + rowStyle + '">';
        displayCols.forEach(function (f) {
          var fname = f.name || f.fieldname || '';
          var type  = f.type || f.fieldtype || 'text';
          var val   = row[fname + '_display'] !== undefined
            ? row[fname + '_display']
            : (row[fname] !== undefined ? row[fname] : '');
          html += '<td style="padding:8px 10px;">' + cellDisplay(type, val, f)
            + (pending ? ' <em style="color:#999;font-size:10px;">(pending)</em>' : '') + '</td>';
        });
        if (hasEditPermission) {
          html += '<td style="padding:8px 10px;white-space:nowrap;">';
          html += '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" data-sf-edit="' + esc(id) + '" style="margin-right:4px;">Edit</button>';
          html += '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" data-sf-delete="' + esc(id) + '" style="color:#c00;">Del</button>';
          html += '</td>';
        }
        html += '</tr>';
      });
    }
    html += '</tbody></table>';
    return html;
  }

  /* ── form-card list view ──────────────────────────────────────────── */
  function renderFormList(displayCols, records, pk, m, hasEditPermission) {
    if (!records.length)
      return '<div style="padding:20px;text-align:center;color:#999;font-size:13px;">No rows yet</div>';
    var html = '<div style="display:grid;gap:8px;padding:8px;">';
    records.forEach(function (row) {
      var id = row[pk];
      html += '<div style="border:1px solid #ddd;border-radius:8px;padding:12px;">';
      displayCols.slice(0, 3).forEach(function (f) {
        var fname = f.name || f.fieldname || '';
        var val   = row[fname + '_display'] !== undefined
          ? row[fname + '_display']
          : (row[fname] || '');
        html += '<div style="font-size:12px;"><strong>' + esc(f.label || f.fieldlabel || fname) + ':</strong> ' + esc(val) + '</div>';
      });
      if (hasEditPermission) {
        html += '<div style="display:flex;gap:8px;margin-top:8px;">';
        html += '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" data-sf-edit="' + esc(id) + '">Edit</button>';
        html += '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" data-sf-delete="' + esc(id) + '" style="color:#c00;">Delete</button>';
        html += '</div>';
      }
      html += '</div>';
    });
    html += '</div>';
    return html;
  }

  /* ── inline editable view ────────────────────────────────────────── */
  function renderInline(displayCols, records, pk, m, hasEditPermission) {
    var html = '<div style="padding:8px;">';
    if (!records.length) {
      html += '<div style="padding:12px;text-align:center;color:#999;font-size:13px;">No rows yet</div>';
    }
    records.forEach(function (row) {
      var id = row[pk];
      html += '<div class="nu-sf-inline-row" data-sf-row-id="' + esc(id)
        + '" style="border:1px solid #ddd;border-radius:8px;padding:12px;margin-bottom:8px;display:grid;gap:8px;">';
      html += '<div style="display:flex;flex-wrap:wrap;gap:8px;">';
      displayCols.forEach(function (f) {
        var fname = f.name || f.fieldname || '';
        var val   = row[fname] !== undefined ? row[fname] : '';
        var dispVal = row[fname + '_display'] !== undefined ? row[fname + '_display'] : '';
        var type  = f.type || f.fieldtype || 'text';
        html += '<div style="flex:1;min-width:120px;">';
        html += '<label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">'
          + esc(f.label || f.fieldlabel || fname) + '</label>';
        html += buildInlineInput(type, fname, val, f, !hasEditPermission, dispVal);
        html += '</div>';
      });
      html += '</div>';
      if (hasEditPermission) {
        html += '<div style="display:flex;gap:6px;margin-top:4px;">';
        html += '<button type="button" class="nu-btn nu-btn-primary nu-btn-sm" data-sf-inline-save="' + esc(id) + '">Save</button>';
        html += '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" data-sf-delete="' + esc(id) + '" style="color:#c00;">Delete</button>';
        html += '</div>';
      }
      html += '</div>';
    });
    html += '</div>';
    return html;
  }

  function toValuesArray(val) {
    if (Array.isArray(val)) return val.map(String);
    if (val == null || val === '') return [];
    return String(val).split(',').map(function(s) { return s.trim(); });
  }

  function buildInlineInput(type, name, value, field, disabled, displayVal) {
    var disAttr = disabled ? ' disabled' : '';
    var base = 'class="nu-input" name="' + esc(name) + '" style="width:100%;"' + disAttr;
    if (type === 'textarea')      return '<textarea ' + base + ' rows="2">' + esc(value) + '</textarea>';
    if (type === 'calculated') {
      var expr = field.formula || (field.calculated || (field.calc_formula || ''));
      return '<input type="text" class="nu-input" name="' + esc(name) + '" data-calculated="true" data-expression="' + esc(expr) + '" value="' + esc(value) + '" readonly style="width:100%;background:var(--bg-offset,#f5f5f5);color:#888;">';
    }
    if (type === 'uploadbutton') {
      var buttonText = field.button_text || field.buttontext || 'Upload';
      var uploadTarget = field.upload_target || field.uploadtarget || 'default';
      var onedriveClientId = field.onedrive_client_id || field.onedriveclientid || '';
      var allowedExts = field.allowed_extensions || field.allowedextensions || '';
      var maxFileSize = field.max_file_size_mb || field.maxfilesizemb || '';
      var isMultiple = !!(field.multiple === true || field.multiple === 'true' || field.multiple === 1);
      var maxFiles = field.max_files || field.maxfiles || '';
      var preview = !!(field.preview === true || field.preview === 'true' || field.preview === 1 || field.preview === '1');

      var wrapHtml = '<div class="nu-sf-upload-wrap nuu-wrap"';
      wrapHtml += ' data-field-name="' + esc(name) + '"';
      wrapHtml += ' data-button-text="' + esc(buttonText) + '"';
      wrapHtml += ' data-upload-target="' + esc(uploadTarget) + '"';
      wrapHtml += ' data-onedrive-client-id="' + esc(onedriveClientId) + '"';
      wrapHtml += ' data-allowed-extensions="' + esc(allowedExts) + '"';
      wrapHtml += ' data-max-file-size-mb="' + esc(maxFileSize) + '"';
      wrapHtml += ' data-multiple="' + (isMultiple ? '1' : '0') + '"';
      wrapHtml += ' data-max-files="' + esc(maxFiles) + '"';
      wrapHtml += ' data-preview="' + (preview ? '1' : '0') + '"';
      wrapHtml += '>';

      wrapHtml += '<input type="hidden" class="nu-sf-upload-input" name="' + esc(name) + '" value="' + esc(value) + '">';
      if (!disabled) {
        wrapHtml += '<div class="nuu-top"><button type="button" class="nu-btn nu-btn-ghost nu-btn-sm nu-sf-upload-btn" style="margin:0;">' + esc(buttonText) + '</button>';
        var rl = window.NuUploadField ? window.NuUploadField.rules({ allowed: allowedExts, maxMb: maxFileSize, multiple: isMultiple, maxFiles: maxFiles }) : '';
        if (rl) wrapHtml += '<span class="nuu-rules">' + esc(rl) + '</span>';
        wrapHtml += '</div>';
      }
      wrapHtml += '<div class="nu-sf-upload-preview nuu-list nuu-empty"></div>';
      wrapHtml += '</div>';
      return wrapHtml;
    }
    if (type === 'lookup') {
      var lookup = field.lookup || {};
      var lTable = lookup.table || '';
      var lIdCol = lookup.id_column || lookup.idCol || 'id';
      var lDisplayCol = lookup.display_column || lookup.displayCol || 'name';
      var lFilter = lookup.filter || '';
      var lExtra = lookup.extra || '';
      var lAdditional = lookup.additional_fields || '';

      var dispVal = displayVal || '';
      if (value && !dispVal) {
        dispVal = value;
      }

      var escJsArg = function(s) {
        return String(s || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '&quot;');
      };

      if (disabled) {
        return '<div style="display:flex;gap:6px;align-items:center;width:100%;">'
          + '<input type="hidden" name="' + esc(name) + '" value="' + esc(value) + '">'
          + '<input type="text" class="nu-input" value="' + esc(dispVal) + '" readonly style="flex:1;background:var(--bg-offset,#f5f5f5);color:#888;cursor:default;">'
          + '</div>';
      } else {
        return '<div style="display:flex;gap:6px;align-items:center;width:100%;">'
          + '<input type="hidden" name="' + esc(name) + '" value="' + esc(value) + '">'
          + '<input type="text" class="nu-input" name="' + esc(name) + '_display" value="' + esc(dispVal) + '" style="flex:1;"'
          + ' data-lookup-name="' + esc(name) + '"'
          + ' data-lookup-table="' + esc(lTable) + '"'
          + ' data-lookup-id="' + esc(lIdCol) + '"'
          + ' data-lookup-display="' + esc(lDisplayCol) + '"'
          + ' data-lookup-filter="' + esc(lFilter) + '"'
          + ' data-lookup-extra="' + esc(lExtra) + '"'
          + ' data-lookup-additional-fields="' + esc(lAdditional) + '"'
          + ' data-last-val="' + esc(dispVal) + '"'
          + '>'
          + '<button type="button" class="nu-btn nu-btn-ghost" style="white-space:nowrap;padding:4px 8px;" onclick="openLookupModal('
          + '\'' + escJsArg(name) + '\',\'' + escJsArg(lTable) + '\',\'' + escJsArg(lIdCol) + '\',\'' + escJsArg(lDisplayCol) + '\',\'' + escJsArg(lFilter) + '\',\'' + escJsArg(lExtra) + '\',\'' + escJsArg(lAdditional) + '\''
          + ')">&#x1F50D;</button>'
          + '<button type="button" class="nu-btn nu-btn-ghost" style="padding:4px 8px;" onclick="clearLookup(\'' + escJsArg(name) + '\')">&#x2715;</button>'
          + '</div>';
      }
    }
    if (type === 'select' || type === 'select2') {
      var isMulti = !!(field.multiple === true || field.multiple === 'true' || field.multiple === 1 || field.select_type === 'multiselect');
      var s2Class = '';
      var s2Attrs = '';
      if (type === 'select2') {
        s2Class = ' nu-select2';
        s2Attrs = ' data-select-type="select2" data-select-mode="' + (isMulti ? 'multiple' : 'single') + '"'
                + ' data-placeholder="' + esc(field.placeholder || 'Select\u2026') + '"'
                + ' data-allow-clear="' + (field.allow_clear === false ? 'false' : 'true') + '"';
      }
      var selectBase = 'class="nu-input' + s2Class + '" name="' + esc(name) + '" style="width:100%;"' + disAttr + s2Attrs + (isMulti ? ' multiple' : '');
      var selectedValues = toValuesArray(value);
      var opts = '';
      if (!isMulti) {
        opts += '<option value="">—</option>';
      }
      (field.options || []).forEach(function (o) {
        var sel = selectedValues.indexOf(String(o.value)) !== -1 ? ' selected' : '';
        opts += '<option value="' + esc(o.value) + '"' + sel + '>' + esc(o.label || o.value) + '</option>';
      });
      return '<select ' + selectBase + '>' + opts + '</select>';
    }
    if (type === 'checkbox')  return '<input type="checkbox" name="' + esc(name) + '" value="1"' + (value ? ' checked' : '') + disAttr + '>';
    if (type === 'date')      return '<input type="date" '            + base + ' value="' + esc(value) + '">';
    if (type === 'time')      return '<input type="time" '            + base + ' value="' + esc(value) + '">';
    if (type === 'datetime') {
      var v = value ? String(value).replace(' ', 'T').substring(0, 16) : '';
      return '<input type="datetime-local" ' + base + ' value="' + esc(v) + '">';
    }
    if (type === 'number')    return '<input type="number" '          + base + ' value="' + esc(value) + '">';
    return '<input type="text" ' + base + ' value="' + esc(value) + '">';
  }

  function formatSubformCell(col, val, type) {
    if (type === 'checkbox') return val ? '&#10003;' : '&mdash;';
    if (val == null || val === '') return '';

    if (type === 'uploadbutton') {
      var files = String(val).split(',').map(function (f) { return f.trim(); });
      var cellHtml = '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">';
      files.forEach(function (fName) {
        if (!fName) return;
        if (fName.indexOf('http://') === 0 || fName.indexOf('https://') === 0) {
          var ext = fName.split('.').pop().toLowerCase();
          if (['jpg','jpeg','png','gif','webp','svg'].indexOf(ext) !== -1) {
            cellHtml += '<img src="' + esc(fName) + '" style="max-height:30px;border-radius:4px;border:1px solid #ddd;cursor:pointer;" onclick="if(window._showImageLightbox)window._showImageLightbox(' + esc(JSON.stringify(fName)) + ')">';
          } else {
            cellHtml += '<a href="' + esc(fName) + '" target="_blank" style="color:var(--color-primary,#1d4ed8);text-decoration:underline;font-size:11px;">Cloud Link</a>';
          }
        } else {
          var ext = fName.split('.').pop().toLowerCase();
          if (['jpg','jpeg','png','gif','webp','svg'].indexOf(ext) !== -1) {
            cellHtml += '<img src="api/file.php?name=' + encodeURIComponent(fName) + '" style="max-height:30px;border-radius:4px;border:1px solid #ddd;cursor:pointer;" onclick="if(window._showImageLightbox)window._showImageLightbox(' + esc(JSON.stringify('uploads/' + fName)) + ')" title="' + esc(fName) + '">';
          } else {
            cellHtml += '<a href="#" data-nu-view-name="' + esc(fName) + '" style="color:var(--color-primary,#1d4ed8);text-decoration:underline;font-size:11px;" title="' + esc(fName) + '">' + esc(fName) + '</a>';
          }
        }
      });
      cellHtml += '</div>';
      return cellHtml;
    }

    if (type === 'select' || type === 'select2') {
      var options = col ? (col.options || []) : [];
      if (options.length > 0) {
        var values = toValuesArray(val);
        var labels = values.map(function(v) {
          var opt = options.find(function(o) { return String(o.value) === String(v); });
          return opt ? (opt.label || opt.value) : v;
        });
        val = labels.join(', ');
      }
    }

    if (!col) {
      var str = String(val).toLowerCase().trim();
      var badgeColors = {
        'active': 'background:#dcfce7;color:#15803d;border:1px solid #15803d33;',
        'inactive': 'background:#fee2e2;color:#b91c1c;border:1px solid #b91c1c33;',
        'pending': 'background:#fef3c7;color:#b45309;border:1px solid #b4530933;',
        'approved': 'background:#dbeafe;color:#1d4ed8;border:1px solid #1d4ed833;',
        'rejected': 'background:#f3f4f6;color:#374151;border:1px solid #37415133;'
      };
      if (badgeColors[str]) {
        return '<span class="nu-badge" style="padding:2px 8px;border-radius:9999px;font-size:11px;font-weight:600;display:inline-block;' + badgeColors[str] + '">' + esc(val) + '</span>';
      }
      return esc(val);
    }

    var rules = col.rules || [];
    if (rules.length === 0 && col.cond_op) {
      rules = [{ op: col.cond_op, val: col.cond_val, fg: col.cond_fg, bg: col.cond_bg }];
    }

    if (rules.length > 0) {
      var vStr = String(val).toLowerCase().trim();
      var vNum = parseFloat(val);

      for (var i = 0; i < rules.length; i++) {
        var rule = rules[i];
        var matched = false;
        var rStr = String(rule.val).toLowerCase().trim();
        var rNum = parseFloat(rule.val);

        switch (rule.op) {
          case '=':
            matched = (vStr === rStr);
            break;
          case '!=':
            matched = (vStr !== rStr);
            break;
          case '>':
            if (!isNaN(vNum) && !isNaN(rNum)) matched = (vNum > rNum);
            break;
          case '<':
            if (!isNaN(vNum) && !isNaN(rNum)) matched = (vNum < rNum);
            break;
          case 'contains':
            matched = (vStr.indexOf(rStr) !== -1);
            break;
        }

        if (matched) {
          var fg = rule.fg || '#15803d';
          var bg = rule.bg || '#dcfce7';
          return '<span class="nu-badge-pill" style="padding:4px 10px;border-radius:12px;font-size:11.5px;font-weight:600;display:inline-block;color:' + fg + ';background:' + bg + ';border:1px solid ' + fg + '33;">' + esc(val) + '</span>';
        }
      }
    }

    if (col.formatter === 'badge') {
      var badgeColors = {
        'active': 'background:#dcfce7;color:#15803d;border:1px solid #15803d33;',
        'inactive': 'background:#fee2e2;color:#b91c1c;border:1px solid #b91c1c33;',
        'pending': 'background:#fef3c7;color:#b45309;border:1px solid #b4530933;',
        'approved': 'background:#dbeafe;color:#1d4ed8;border:1px solid #1d4ed833;',
        'rejected': 'background:#f3f4f6;color:#374151;border:1px solid #37415133;'
      };
      var key = String(val).toLowerCase().trim();
      var colorStyle = badgeColors[key] || 'background:var(--bg-offset,#f3f4f6);color:var(--text-secondary,#374151);';
      return '<span class="nu-badge" style="padding:2px 8px;border-radius:9999px;font-size:11px;font-weight:600;display:inline-block;' + colorStyle + '">' + esc(val) + '</span>';
    }

    return esc(val);
  }

  function cellDisplay(type, val, col) {
    return formatSubformCell(col, val, type);
  }

  /* ── modal for add/edit ───────────────────────────────────────────── */
  function openModal(container, allFields, pk, rowId, records, prefillData, pendingIdx) {
    var row = prefillData || {};
    if (rowId && !prefillData) {
      (records || []).forEach(function (r) {
        if (String(r[pk]) === String(rowId)) row = r;
      });
    }

    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:20000;display:flex;align-items:center;justify-content:center;';
    overlay.setAttribute('data-sf-overlay', '1');

    var box = document.createElement('div');
    box.style.cssText = 'background:var(--card-bg,#fff);border-radius:12px;padding:24px;max-width:640px;width:94%;max-height:90vh;overflow-y:auto;';
    box._sfAllFields = allFields;

    var isPending = pendingIdx !== undefined && pendingIdx !== null;
    var title     = (rowId || isPending) ? 'Edit Row' : 'Add Row';

    var headerEl = document.createElement('div');
    headerEl.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;';
    headerEl.innerHTML = '<h3 style="margin:0;">' + title + '</h3>'
      + '<button type="button" style="background:none;border:none;font-size:22px;cursor:pointer;line-height:1;" onclick="this.closest(\'\[data-sf-overlay]\').remove()">&times;</button>';
    box.appendChild(headerEl);

    var fieldsEl = document.createElement('div');
    fieldsEl.style.cssText = 'display:flex;flex-wrap:wrap;gap:12px;';

    (allFields || []).forEach(function (f) {
      var fname = f.name || f.fieldname || '';
      var ftype = f.type || f.fieldtype || 'text';
      var skip  = ['html','heading','divider','fieldset','subform','button'];
      if (skip.indexOf(ftype) !== -1 || !fname) return;

      var val = row[fname] !== undefined ? row[fname] : (f.default_value || f.defaultvalue || '');

      var m = meta(container);
      var isParentFk = isFkField(f) || (fname.toLowerCase() === (m.fk || '').toLowerCase());
      var isReadonly = f.readonly === true || f.readonly === '1' || f.readonly === 1 || f.readonly === 'true' || f.is_readonly === true || f.is_readonly === '1' || f.is_readonly === 1 || f.is_readonly === 'true';
      var isHidden = f.hidden === true || f.hidden === '1' || f.hidden === 1 || f.hidden === 'true' || f.is_hidden === true || f.is_hidden === '1' || f.is_hidden === 1 || f.is_hidden === 'true';

      if (isParentFk || isHidden) {
        var hiddenEl = document.createElement('input');
        hiddenEl.type  = 'hidden';
        hiddenEl.name  = fname;
        hiddenEl.value = val;
        fieldsEl.appendChild(hiddenEl);
        return;
      }

      var width   = f.width || '100%';
      var fieldWr = document.createElement('div');
      fieldWr.style.cssText = 'flex:1;min-width:calc(' + width + ' - 12px);';

      var labelEl = document.createElement('label');
      labelEl.style.cssText = 'font-size:12px;font-weight:600;display:block;margin-bottom:4px;';
      labelEl.innerHTML = esc(f.label || f.fieldlabel || fname)
        + (f.required ? ' <span style="color:red">*</span>' : '');
      fieldWr.appendChild(labelEl);

      var hasEditPermission = checkPermission(container, 'edit');
      var isDisabled = !hasEditPermission || isReadonly;
      var dispVal = row[fname + '_display'] !== undefined ? row[fname + '_display'] : '';
      fieldWr.insertAdjacentHTML('beforeend', buildInlineInput(ftype, fname, val, f, isDisabled, dispVal));

      if (f.help_text || f.helptext) {
        var helpEl = document.createElement('div');
        helpEl.style.cssText = 'font-size:11px;color:#888;margin-top:3px;';
        helpEl.textContent = f.help_text || f.helptext;
        fieldWr.appendChild(helpEl);
      }
      fieldsEl.appendChild(fieldWr);
    });
    box.appendChild(fieldsEl);

    /* ── footer buttons ── */
    var footer = document.createElement('div');
    footer.style.cssText = 'display:flex;gap:8px;justify-content:flex-end;margin-top:20px;';

    var cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'nu-btn nu-btn-ghost';
    cancelBtn.textContent = 'Cancel';
    cancelBtn.onclick = function () { overlay.remove(); };

    var saveBtn = document.createElement('button');
    saveBtn.type = 'button';
    saveBtn.className = 'nu-btn nu-btn-primary';
    saveBtn.textContent = 'Save';
    saveBtn.onclick = function () {
      var data = {};
      (allFields || []).forEach(function (f) {
        var fname = f.name || f.fieldname || '';
        var ftype = f.type || f.fieldtype || 'text';
        var skip  = ['html','heading','divider','fieldset','subform','button'];
        if (skip.indexOf(ftype) !== -1 || !fname) return;
        var el = box.querySelector('[name="' + CSS.escape(fname) + '"]');
        if (!el) return;
        if (ftype === 'checkbox') {
          data[fname] = el.checked ? 1 : 0;
        } else if (el.tagName === 'SELECT' && el.multiple) {
          var selected = [];
          for (var i = 0; i < el.options.length; i++) {
            if (el.options[i].selected) {
              selected.push(el.options[i].value);
            }
          }
          data[fname] = selected;
        } else {
          data[fname] = el.value;
        }
      });

      var m = meta(container);

      if (!m.parentId) {
        var queue    = getPending(container);
        var safeData = stripProtectedFields(data, allFields);
        if (isPending) {
          queue[pendingIdx] = { allFields: allFields, pk: pk, data: safeData };
        } else {
          queue.push({ allFields: allFields, pk: pk, data: safeData });
        }
        overlay.remove();
        load(container);
        toast(isPending ? 'Row updated (will save when parent saves)' : 'Row queued — will save when parent is saved');
        return;
      }

      var postData = stripProtectedFields(data, allFields);
      var url = 'api/form.php?action=subform_save'
        + '&code='      + encodeURIComponent(m.code)
        + '&fk='        + encodeURIComponent(m.fk)
        + '&parent_id=' + encodeURIComponent(m.parentId)
        + '&parent_form_code=' + encodeURIComponent(m.parentFormCode)
        + (rowId ? '&id=' + encodeURIComponent(rowId) : '');

      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving...';

      apiJson(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(postData)
      })
      .then(function (json) {
        if (!json.success) {
          toast(json.error || 'Save failed', 'error');
          saveBtn.disabled = false;
          saveBtn.textContent = 'Save';
          return;
        }
        overlay.remove();
        load(container);
        toast(rowId ? 'Row updated' : 'Row added');
      })
      .catch(function (e) {
        toast('Error: ' + e.message, 'error');
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save';
      });
    };

    footer.appendChild(cancelBtn);
    footer.appendChild(saveBtn);
    box.appendChild(footer);
    overlay.appendChild(box);
    document.body.appendChild(overlay);

    if (typeof window.nuInitSelect2 === 'function') {
      window.nuInitSelect2(overlay);
    }
    if (typeof window.nuCalculateFields === 'function') {
      window.nuCalculateFields(overlay);
    }
    if (typeof window.nuInitUploadButtons === 'function') {
      window.nuInitUploadButtons(overlay);
    }
  }

  /* ── delete row ───────────────────────────────────────────────────── */
  function deleteRow(container, rowId, pk) {
    if (!confirm('Delete this row?')) return;
    var m = meta(container);
    apiJson(
      'api/form.php?action=subform_delete&code=' + encodeURIComponent(m.code)
      + '&fk=' + encodeURIComponent(m.fk)
      + '&parent_form_code=' + encodeURIComponent(m.parentFormCode)
      + '&id=' + encodeURIComponent(rowId),
      { method: 'DELETE' }
    )
    .then(function (json) {
      if (!json.success) { toast(json.error || 'Delete failed', 'error'); return; }
      load(container);
      toast('Row deleted');
    })
    .catch(function (e) { toast('Error: ' + e.message, 'error'); });
  }

  /* ── save inline row ─────────────────────────────────────────────── */
  function saveInlineRow(container, btn, pk, allFields) {
    var rowEl = btn.closest('.nu-sf-inline-row');
    var rowId = rowEl ? rowEl.dataset.sfRowId : '';
    var raw   = {};
    if (rowEl) {
      rowEl.querySelectorAll('[name]').forEach(function (el) {
        if (el.type === 'checkbox') {
          raw[el.name] = el.checked ? 1 : 0;
        } else if (el.tagName === 'SELECT' && el.multiple) {
          var selected = [];
          for (var i = 0; i < el.options.length; i++) {
            if (el.options[i].selected) {
              selected.push(el.options[i].value);
            }
          }
          raw[el.name] = selected;
        } else {
          raw[el.name] = el.value;
        }
      });
    }
    var data = stripProtectedFields(raw, allFields || container._sfAllFields || []);
    btn.disabled = true;
    var m = meta(container);
    apiJson(
      'api/form.php?action=subform_save'
      + '&code='      + encodeURIComponent(m.code)
      + '&fk='        + encodeURIComponent(m.fk)
      + '&parent_id=' + encodeURIComponent(m.parentId)
      + '&parent_form_code=' + encodeURIComponent(m.parentFormCode)
      + (rowId ? '&id=' + encodeURIComponent(rowId) : ''),
      { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) }
    )
    .then(function (json) {
      btn.disabled = false;
      if (!json.success) { toast(json.error || 'Save failed', 'error'); return; }
      load(container);
      toast('Saved');
    })
    .catch(function (e) { btn.disabled = false; toast('Error: ' + e.message, 'error'); });
  }

  /* ── flush pending queue to DB after parent saves ─────────────────── */
  function flushPending(container, parentId) {
    var queue = getPending(container);
    if (!queue.length) return Promise.resolve();
    var m = meta(container);

    return queue.reduce(function (chain, item) {
      return chain.then(function () {
        var allFlds  = item.allFields || container._sfAllFields || [];
        var postData = stripProtectedFields(item.data, allFlds);
        var url = 'api/form.php?action=subform_save'
          + '&code='      + encodeURIComponent(m.code)
          + '&fk='        + encodeURIComponent(m.fk)
          + '&parent_form_code=' + encodeURIComponent(m.parentFormCode)
          + '&parent_id=' + encodeURIComponent(parentId);
        return apiJson(url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(postData)
        }).then(function (json) {
          if (!json.success) throw new Error(json.error || 'Subform row save failed');
        });
      });
    }, Promise.resolve())
    .then(function () { _pendingRows.set(container, []); });
  }

  /* ── public API ───────────────────────────────────────────────────── */
  var nuSubform = {

    initAll: function (scope) {
      scope = scope || document;
      var containers = scope.querySelectorAll('.nu-subform-container');
      containers.forEach(function (el) {
        var parentId = el.dataset.parentId || '';
        if (parentId) {
          delete el.dataset.sfInit;
          load(el);
        } else {
          if (!el.dataset.sfInit) {
            el.dataset.sfInit = '1';
            load(el);
          }
        }
      });
    },

    load: load,

    addRow: function (btn) {
      var container = btn.closest('.nu-subform-container');
      if (!container) return;
      var m = meta(container);
      if (!m.code) { toast('Subform not configured (missing form code)', 'error'); return; }

      if (container._sfAllFields && container._sfAllFields.length) {
        openModal(container, container._sfAllFields, container._sfPk || 'id', null, []);
        return;
      }

      apiJson('api/form.php?action=subform_fields&code=' + encodeURIComponent(m.code))
        .then(function (json) {
          if (!json.success) { toast(json.error || 'Failed to load subform', 'error'); return; }
          var data      = json.data || {};
          var allFields = data.all_fields || data.layout || [];
          var pk        = data.pk || 'id';
          container._sfAllFields = allFields;
          container._sfPk        = pk;
          openModal(container, allFields, pk, null, []);
        })
        .catch(function (e) { toast('Error: ' + e.message, 'error'); });
    },

    setView: function (container, view) {
      if (['grid','form','inline'].indexOf(view) === -1) return;
      container.dataset.subformView = view;
      load(container);
    },

    onParentSaved: function (newId, scope) {
      scope = scope || document;
      var id = String(newId || '');
      if (!id) return;

      var containers = Array.prototype.slice.call(
        scope.querySelectorAll('.nu-subform-container')
      );

      containers.forEach(function (el, i) {
        el.dataset.parentId = id;
        delete el.dataset.sfInit;

        flushPending(el, id)
          .then(function () { load(el); })
          .catch(function (e) {
            toast('Error saving queued subform rows: ' + e.message, 'error');
            load(el);
          });
      });
    }
  };

  window.nuSubform = nuSubform;

  /* ── auto-init after parent form opens ───────────────────────────── */
  document.addEventListener('nu:form:opened', function (e) {
    var scope = e.detail && e.detail.scope ? e.detail.scope : document;
    nuSubform.initAll(scope);
  });

  /* ── listen for parent save event ────────────────────────────────── */
  document.addEventListener('nu:parent:saved', function (e) {
    var detail = e.detail || {};
    nuSubform.onParentSaved(detail.id, detail.scope || document);
  });

  /* ── Intercept NuApp.apiJson to dispatch nu:parent:saved automatically ── */
  var PARENT_SAVE_RE = /[?&]action=save(&|$)/;

  function installParentSavePatch() {
    var app = window.NuApp;
    if (!app || typeof app.apiJson !== 'function') return;
    if (app._nuSubformPatchApplied) return;
    app._nuSubformPatchApplied = true;

    var _origApiJson = app.apiJson.bind(app);
    app.apiJson = function (url, options) {
      return _origApiJson(url, options).then(function (json) {
        if (
          typeof url === 'string' &&
          PARENT_SAVE_RE.test(url) &&
          json && json.success
        ) {
          var savedId = String(
            (json.data && (json.data.id || json.data.record_id))
              || json.id
              || json.record_id
              || ''
          );
          if (savedId) {
            var box = null;
            document.querySelectorAll('.nu-form-overlay').forEach(function (ov) {
              if (ov.querySelector('.nu-subform-container')) box = ov;
            });
            var scope = box || document;
            scope.querySelectorAll('.nu-subform-container').forEach(function (el) {
              el.dataset.parentId = savedId;
            });
            document.dispatchEvent(new CustomEvent('nu:parent:saved', {
              detail: { id: savedId, scope: scope }
            }));
          }
        }
        return json;
      });
    };
  }

  function paintSfUpload(wrap) {
    var input = wrap.querySelector('.nu-sf-upload-input');
    var box = wrap.querySelector('.nu-sf-upload-preview');
    if (!input || !box || !window.NuUploadField) return;
    var readonly = !wrap.querySelector('.nu-sf-upload-btn');
    window.NuUploadField.render(box, input.value, {
      readonly: readonly,
      thumbs: wrap.dataset.preview !== '0',
      onRemove: function (v) { updateSfUploadPreviewAndActions(wrap, v); }
    });
  }

  function updateSfUploadPreviewAndActions(wrap, newValue) {
    var input = wrap.querySelector('.nu-sf-upload-input');
    if (input) {
      input.value = newValue;
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }
    paintSfUpload(wrap);
  }

  window.nuInitUploadButtons = function (scope) {
    scope = scope || document;
    var wraps = scope.querySelectorAll('.nu-sf-upload-wrap');
    wraps.forEach(function (wrap) {
      if (wrap._uppyBound) return;
      wrap._uppyBound = true;
      paintSfUpload(wrap);

      var btn = wrap.querySelector('.nu-sf-upload-btn');
      var input = wrap.querySelector('.nu-sf-upload-input');
      var clearBtn = wrap.querySelector('.nu-sf-upload-clear');

      if (clearBtn) {
        clearBtn.addEventListener('click', function () {
          updateSfUploadPreviewAndActions(wrap, '');
        });
      }

      if (!btn || !input) return;

      var name = wrap.dataset.fieldName || '';
      var buttonText = wrap.dataset.buttonText || 'Upload';
      var uploadTarget = wrap.dataset.uploadTarget || 'default';
      var onedriveClientId = wrap.dataset.onedriveClientId || '';
      var allowedExtensions = wrap.dataset.allowedExtensions || '';
      var maxFileSizeMb = parseFloat(wrap.dataset.maxFileSizeMb) || 0;
      var isMultiple = wrap.dataset.multiple === '1';
      var maxFilesAttr = wrap.dataset.maxFiles;

      var allowedTypes = null;
      if (allowedExtensions) {
        allowedTypes = allowedExtensions.split(',').map(function (ext) {
          ext = ext.trim();
          return ext.indexOf('.') === 0 ? ext : '.' + ext;
        });
      }
      var maxFileSize = maxFileSizeMb ? (maxFileSizeMb * 1024 * 1024) : (10 * 1024 * 1024);
      var maxNumFiles = isMultiple ? (parseInt(maxFilesAttr, 10) || null) : 1;
      if (maxNumFiles !== null && maxNumFiles <= 0) {
        maxNumFiles = null;
      }

      function launchOneDrivePicker() {
        if (!onedriveClientId) {
          toast('Error: OneDrive Client ID is not configured in properties panel!', 'error');
          return;
        }
        var odOptions = {
          clientId: onedriveClientId,
          action: 'share',
          multiSelect: isMultiple,
          advanced: {},
          success: function (response) {
            if (response && response.value && response.value.length > 0) {
              var links = response.value.map(function (item) { return item.permissions[0].link.webUrl; });
              var newVal = input.value;
              if (newVal) {
                newVal = newVal + ', ' + links.join(', ');
              } else {
                newVal = links.join(', ');
              }
              updateSfUploadPreviewAndActions(wrap, newVal);
              toast('OneDrive link acquired!', 'success');
            }
          },
          cancel: function () {
            toast('OneDrive selection canceled.', 'info');
          },
          error: function (err) {
            toast('OneDrive Error: ' + (err.message || String(err)), 'error');
            console.error('OneDrive error', err);
          }
        };
        OneDrive.open(odOptions);
      }

      btn.addEventListener('click', function () {
        if (uploadTarget === 'onedrive') {
          if (typeof OneDrive === 'undefined') {
            toast('OneDrive Picker SDK is loading...', 'info');
            var script = document.createElement('script');
            script.src = 'https://js.live.net/v7.2/OneDrive.js';
            script.onload = launchOneDrivePicker;
            document.head.appendChild(script);
          } else {
            launchOneDrivePicker();
          }
          return;
        }

        // Local Server upload mode using Uppy
        if (typeof Uppy === 'undefined') {
          toast('Error: Uppy library is not loaded!', 'error');
          return;
        }

        var uniqueId = 'uppy_sf_' + name + '_' + Math.random().toString(36).substr(2, 9);
        var modalContainer = document.createElement('div');
        modalContainer.id = uniqueId;
        modalContainer.style.cssText = 'position: relative; z-index: 30000;';
        document.body.appendChild(modalContainer);

        var styleEl = document.createElement('style');
        styleEl.innerHTML = '#' + uniqueId + ' .uppy-Dashboard--modal, .uppy-Dashboard--modal { z-index: 30000 !important; }';
        document.head.appendChild(styleEl);

        var uppy = new Uppy.Uppy({
          autoProceed: false,
          restrictions: {
            maxFileSize: maxFileSize,
            allowedFileTypes: allowedTypes,
            maxNumberOfFiles: maxNumFiles
          }
        })
        .use(Uppy.Dashboard, {
          target: modalContainer,
          inline: false,
          trigger: btn,
          closeModalOnClickOutside: true,
          showProgressDetails: true,
          proudlyDisplayPoweredByUppy: false,
          metaFields: []
        })
        .use(Uppy.XHRUpload, {
          endpoint: 'api/upload.php' + (uploadTarget === 'local_only' ? '?target=local_only' : ''),
          fieldName: 'file',
          formData: true,
          headers: { 'X-CSRF-Token': window.nuCsrfToken || '' },
          getResponseError: function (text, xhr) { try { var j = JSON.parse(text); if (j && j.error) return new Error(j.error); } catch (e) {} return new Error(xhr && xhr.status === 413 ? 'File is too large for the server' : xhr && xhr.status === 403 ? 'Session expired or invalid security token - reload the page' : 'Upload failed (HTTP ' + (xhr ? xhr.status : '?') + ')'); },
          validateStatus: function (status, text) { if (status < 200 || status >= 300) return false; try { var j = JSON.parse(text); return !(j && j.success === false); } catch (e) { return false; } },
        });

        uppy.getPlugin('Dashboard').openModal();

        uppy.on('complete', function (result) {
          if (result.successful.length > 0) {
            var names = result.successful.map(function (file) { return file.response.body.name; });
            var newVal = input.value;
            if (newVal) {
              newVal = newVal + ', ' + names.join(', ');
            } else {
              newVal = names.join(', ');
            }
            updateSfUploadPreviewAndActions(wrap, newVal);
            var warn = result.successful.map(function (file) { return file.response.body.warning; }).filter(Boolean)[0];
            toast(warn ? 'Saved on this server, but the cloud copy failed: ' + warn : 'Upload successful!', warn ? 'error' : 'success');
            uppy.getPlugin('Dashboard').closeModal();
            uppy.close();
            modalContainer.remove();
            styleEl.remove();
          }
        });

        uppy.on('dashboard:modal-closed', function () {
          uppy.close();
          modalContainer.remove();
          styleEl.remove();
        });
      });
    });
  };

  if (window.NuApp && window.NuApp.apiJson) {
    installParentSavePatch();
  } else {
    document.addEventListener('DOMContentLoaded', installParentSavePatch);
  }

}(window));
