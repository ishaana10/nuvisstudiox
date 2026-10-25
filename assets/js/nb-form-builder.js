/**
 * nb-form-builder.js  — PATCHED v8.1
 *
 * v7 Fixes:
 *   FIX-J  Added nbFormBuilder.edit(formId) method (was missing — caused "not a function" error)
 *   FIX-K  _applyNbAcePatches guarded against undefined editor instance
 * (all v3-v6 fixes retained)
 *   FIX-H  Fields freely resizable by dragging right edge
 *   FIX-I  Click field → properties open in RIGHT SIDE PANEL
 *   FIX-F  Row/container headers built with DOM (no innerHTML onclick leaking)
 *   FIX-G  Tab panels have both + Row and + Group buttons
 *   FIX-E  Canvas dragover/drop handles group/tab directly
 *   FIX-A  Tab/Group added directly to canvas
 *   FIX-B  Field body always opens on first create
 *   FIX-C  saveForm passes create_table flag
 *   FIX-D  Label in header updates live as user types
 */
(function (window) {
  'use strict';

  /* ══════════════════════════════════════════════════════════════════
     CSS injection
  ══════════════════════════════════════════════════════════════════ */
  (function _injectCSS() {
    if (document.getElementById('nb-container-css')) return;
    var s = document.createElement('style');
    s.id = 'nb-container-css';
    s.textContent = [
      '.nb-container{border:2px solid var(--color-primary,#4f6bed);border-radius:10px;margin:8px 0;background:var(--bg-card,#fff);overflow:hidden;}',
      '.nb-container-header{display:flex;align-items:center;gap:8px;padding:7px 10px;background:var(--color-primary,#4f6bed);color:#fff;cursor:default;}',
      '.nb-container-header .nb-row-drag{font-size:16px;cursor:grab;opacity:.8;}',
      '.nb-container-header .nb-container-label-input{flex:1;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.4);border-radius:5px;color:#fff;font-size:12px;padding:2px 7px;}',
      '.nb-container-header .nb-container-label-input::placeholder{color:rgba(255,255,255,.7);}',
      '.nb-container-type-badge{font-size:10px;font-weight:700;letter-spacing:.06em;background:rgba(255,255,255,.22);padding:2px 7px;border-radius:4px;}',
      '.nb-container-type-badge-tab{background:rgba(255,200,0,.35);}',
      '.nb-container-group-body{padding:8px 10px;display:flex;flex-direction:column;gap:6px;min-height:48px;}',
      '.nb-cfield-tab-nav{display:flex;flex-wrap:wrap;align-items:center;gap:0;border-bottom:2px solid var(--color-primary,#4f6bed);padding:0 8px;}',
      '.nb-cfield-tab-nav-item{display:flex;align-items:center;gap:2px;padding:5px 12px;cursor:pointer;font-size:13px;border-radius:6px 6px 0 0;border:1px solid transparent;margin-bottom:-2px;color:var(--text-secondary,#555);}',
      '.nb-cfield-tab-nav-item.active{background:#fff;border-color:var(--color-primary,#4f6bed);border-bottom-color:#fff;font-weight:600;color:var(--color-primary,#4f6bed);}',
      '.nb-cfield-tab-add-btn{margin-left:4px;padding:3px 10px;font-size:11px;border:1px dashed var(--color-primary,#4f6bed);border-radius:5px;background:none;color:var(--color-primary,#4f6bed);cursor:pointer;}',
      '.nb-container-tab-panels{padding:0;}',
      '.nb-cfield-tab-panel{display:none;flex-direction:column;}',
      '.nb-cfield-tab-panel.active{display:flex;}',
      '.nb-tab-panel-rows{padding:8px 10px;display:flex;flex-direction:column;gap:6px;min-height:52px;}',
      '.nb-row{transition:border-color .15s, box-shadow .15s; border:1px solid var(--border,#e0e4ef); border-radius:8px; margin-bottom:8px; background:var(--bg-card,#fff); cursor:pointer;}',
      '.nb-row.nb-row-selected{border-color:var(--color-primary,#4f6bed) !important; box-shadow:0 0 0 2px rgba(79,107,237,.25) !important;}',
      '.nb-inner-row{border:1px solid var(--border,#e0e4ef);border-radius:7px;background:var(--bg-offset,#f8faff);margin:2px 0;}',
      '.nb-inner-row .nb-row-header{background:var(--bg-offset2,#edf0fc);border-radius:6px 6px 0 0;padding:4px 8px;}',
      '.nb-row.drag-row-over,.nb-container.drag-row-over{outline:2px dashed var(--color-primary,#4f6bed);outline-offset:2px;}',
      '.nb-row.drag-row-source,.nb-container.drag-row-source{opacity:.45;}',
      '.nb-row-drop-hint{color:var(--text-muted,#aaa);font-size:12px;text-align:center;padding:10px 0;width:100%;}',
      '.nb-row-body{display:grid;grid-template-columns:repeat(12,1fr);gap:6px;padding:8px;min-height:52px;position:relative;max-width:100%;}',
      '.nb-cfield{position:relative;display:flex;flex-direction:column;min-width:80px;max-width:100%;box-sizing:border-box;background:var(--bg-card,#fff);border:1.5px solid var(--border,#dde1f0);border-radius:7px;overflow:hidden;cursor:pointer;transition:border-color .15s;}',
      '.nb-cfield:hover{border-color:var(--color-primary,#4f6bed);}',
      '.nb-cfield.nb-cfield-selected{border-color:var(--color-primary,#4f6bed);box-shadow:0 0 0 2px rgba(79,107,237,.18);}',
      '.nb-cfield-resize{position:absolute;top:0;right:-4px;width:8px;height:100%;cursor:col-resize;z-index:10;display:flex;align-items:center;justify-content:center;}',
      '.nb-cfield-resize::after{content:"";width:3px;height:60%;background:var(--color-primary,#4f6bed);border-radius:2px;opacity:0;transition:opacity .15s;}',
      '.nb-cfield:hover .nb-cfield-resize::after,.nb-cfield.nb-cfield-selected .nb-cfield-resize::after{opacity:.5;}',
      '.nb-cfield-header{display:flex;align-items:center;gap:6px;padding:6px 8px;user-select:none;}',
      '.nb-cfield-drag{font-size:14px;cursor:grab;color:var(--text-muted,#aaa);}',
      '.nb-cfield-type-badge{font-size:9px;font-weight:700;letter-spacing:.06em;background:var(--color-primary,#4f6bed);color:#fff;padding:1px 5px;border-radius:3px;white-space:nowrap;}',
      '.nb-cfield-label{flex:1;font-size:12px;font-weight:600;color:var(--text-primary,#333);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
      '.nb-cfield-actions button{background:none;border:none;cursor:pointer;color:var(--text-muted,#999);font-size:12px;padding:2px 4px;border-radius:3px;}',
      '.nb-cfield-actions button:hover{background:var(--bg-danger-soft,#fee2e2);color:#dc2626;}',
      '.nb-cfield-actions .move-tab-btn{display:inline-block;margin-right:4px;}',
      '.nb-cfield-actions .move-tab-btn:hover{background:var(--bg-offset,#f5f7ff) !important;color:var(--color-primary,#4f6bed) !important;}',
      '#formCanvas.nb-canvas-tool-over{outline:2px dashed var(--color-primary,#4f6bed);outline-offset:3px;}',
      '#formCanvas.nb-viewport-desktop{max-width:100%;margin:0 auto;transition:max-width .3s ease;}',
      '#formCanvas.nb-viewport-tablet{max-width:768px;margin:0 auto;box-shadow:0 10px 30px rgba(0,0,0,0.15);transition:max-width .3s ease;}',
      '#formCanvas.nb-viewport-tablet .nb-row-body{flex-wrap:wrap !important;}',
      '#formCanvas.nb-viewport-tablet .nb-cfield{flex:1 1 calc(50% - 8px) !important;min-width:calc(50% - 8px) !important;max-width:50% !important;}',
      '#formCanvas.nb-viewport-mobile{max-width:375px;margin:0 auto;box-shadow:0 10px 30px rgba(0,0,0,0.15);transition:max-width .3s ease;}',
      '#formCanvas.nb-viewport-mobile .nb-row-body{flex-wrap:wrap !important;}',
      '#formCanvas.nb-viewport-mobile .nb-cfield{flex:1 1 100% !important;min-width:100% !important;max-width:100% !important;width:100% !important;}',
      '.nb-canvas-zoom-wrapper{transform-origin:top center;transition:transform .2s ease;}',
      /* RIGHT SIDE PANEL */
      '#nb-props-panel{position:fixed;top:0;right:0;width:320px;height:100vh;background:var(--bg-card,#fff);border-left:2px solid var(--color-primary,#4f6bed);box-shadow:-4px 0 24px rgba(0,0,0,.12);z-index:9999;display:flex;flex-direction:column;transform:translateX(100%);transition:transform .22s cubic-bezier(.4,0,.2,1);}',
      '#nb-props-panel.open{transform:translateX(0);}',
      '#nb-props-panel-header{display:flex;align-items:center;gap:8px;padding:10px 14px;background:var(--color-primary,#4f6bed);color:#fff;flex-shrink:0;}',
      '#nb-props-panel-header .nb-props-type-badge{font-size:10px;font-weight:700;background:rgba(255,255,255,.22);padding:2px 7px;border-radius:4px;}',
      '#nb-props-panel-header .nb-props-title{flex:1;font-size:13px;font-weight:600;}',
      '#nb-props-panel-header .nb-props-close{background:none;border:none;color:#fff;font-size:18px;cursor:pointer;line-height:1;padding:0 2px;}',
      '#nb-props-panel-body{flex:1;overflow-y:auto;padding:14px;}',
      '#nb-props-panel-body .nb-fp-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}',
      '#nb-props-panel-body .nb-fp{display:flex;flex-direction:column;gap:3px;}',
      '#nb-props-panel-body .nb-fp.nb-fp-full{grid-column:1/-1;}',
      '#nb-props-panel-body .nb-fp label{font-size:11px;font-weight:600;color:var(--text-muted,#666);}',
      '#nb-props-panel-body .nu-input{border:1px solid var(--border,#dde1f0);border-radius:5px;padding:5px 8px;font-size:12px;width:100%;box-sizing:border-box;}',
      '#nb-props-panel-body .nu-input:focus{outline:none;border-color:var(--color-primary,#4f6bed);}',
      '#nb-props-panel-body .nb-span-bar{display:flex;align-items:center;gap:4px;flex-wrap:wrap;grid-column:1/-1;padding:6px 0;}',
      '#nb-props-panel-body .nb-span-bar-label{font-size:11px;font-weight:600;color:var(--text-muted,#666);}',
      '#nb-props-panel-body .nb-span-btn{padding:3px 8px;font-size:11px;border:1px solid var(--border,#dde1f0);background:#fff;border-radius:4px;cursor:pointer;}',
      '#nb-props-panel-body .nb-span-btn.active{background:var(--color-primary,#4f6bed);color:#fff;border-color:var(--color-primary,#4f6bed);}',
      '#nb-props-panel-body .nb-vis-flags{display:flex;flex-wrap:wrap;gap:8px 14px;padding:8px;background:var(--bg-offset,#f5f7ff);border:1px solid var(--border,#e0e4ef);border-radius:7px;grid-column:1/-1;}',
      '#nb-props-panel-body .nb-vis-flags label{font-size:12px;cursor:pointer;display:flex;align-items:center;gap:5px;}',
      '.nb-builder-fullscreen{position:fixed !important;top:0 !important;left:0 !important;width:100vw !important;height:100vh !important;z-index:99999 !important;margin:0 !important;border-radius:0 !important;overflow-y:auto !important;background:var(--bg-card,#fff) !important;padding:24px !important;}',
      '.nb-builder-fullscreen .nb-builder-wrap{height:calc(100vh - 140px) !important;min-height:unset !important;}',
    ].join('');
    (document.head || document.documentElement).appendChild(s);
  }());


  /* ════════════════════════════════════════════════════════════════════
     FIX-K: Safe Ace patch helper
  ═══════════════════════════════════════════════════════════════════ */
  function _applyNbAcePatches(editor) {
    if (!editor) return;
    if (editor._nbAcePatchV2) return;
    editor._nbAcePatchV2 = true;
    try {
      if (editor.setOptions) {
        editor.setOptions({
          enableBasicAutocompletion: true,
          enableLiveAutocompletion: false,
          showPrintMargin: false,
          fontSize: '13px'
        });
      }
    } catch (e) { /* Ace not fully ready, ignore */ }
  }

  function _safeAcePatch(nodeList) {
    if (!nodeList) return;
    nodeList.forEach(function (el) {
      if (!el) return;
      try {
        var inst = el.env && el.env.editor
          ? el.env.editor
          : (el._nbAceInstance || null);
        if (inst) _applyNbAcePatches(inst);
      } catch (e) { /* silently skip */ }
    });
  }

  /* expose so nubuilder-next can call it safely */
  window._nbSafeAcePatch = _safeAcePatch;


  /* ════════════════════════════════════════════════════════════════════
     FIX-I: Side panel singleton
  ═══════════════════════════════════════════════════════════════════ */
  var _activeCard = null;

  function _ensurePropsPanel() {
    var p = document.getElementById('nb-props-panel');
    if (p) return p;
    p = document.createElement('div');
    p.id = 'nb-props-panel';
    p.innerHTML =
      '<div id="nb-props-panel-header">'
        + '<span class="nb-props-type-badge" id="nb-props-type-badge">field</span>'
        + '<span class="nb-props-title" id="nb-props-title">Properties</span>'
        + '<button class="nb-props-close" id="nb-props-close" title="Close">×</button>'
      + '</div>'
      + '<div id="nb-props-panel-body"></div>';
    document.body.appendChild(p);
    document.getElementById('nb-props-close').addEventListener('click', _closePropsPanel);
    return p;
  }



 function _closePropsPanel() {
  var panel = document.getElementById('nb-props-panel');
  if (panel) panel.classList.remove('open');
  if (_activeCard) _activeCard.classList.remove('nb-cfield-selected');
  _activeCard = null;
  var canvas = document.getElementById('formCanvas');
  if (canvas) canvas.style.marginRight = '';
}

function _renderSubformPanel(card, grid) {
  var origFormSel  = card.querySelector('.nb-cfield-body .nb-sf-form-code');
  var origFkSel    = card.querySelector('.nb-cfield-body .nb-sf-fk-field');
  var origViewSel  = card.querySelector('.nb-cfield-body .nb-sf-view');
  var origIsFk     = card.querySelector('.nb-cfield-body .nb-sf-is-fk');
  var origHideGrid = card.querySelector('.nb-cfield-body .nb-sf-hide-in-grid');
  var origSrvRo    = card.querySelector('.nb-cfield-body .nb-sf-server-readonly');
  var origSearchable = card.querySelector('.nb-cfield-body .nb-sf-searchable');
  var origSelectedOnly = card.querySelector('.nb-cfield-body .nb-sf-selected-fields-only');
  var origViewRoles = card.querySelector('.nb-cfield-body .nb-sf-view-roles');
  var origEditRoles = card.querySelector('.nb-cfield-body .nb-sf-edit-roles');

  /* ── FIX: getAttribute first, then .value, then dataset ── */
  function _readSel(el, dsKey) {
    if (!el) return card.dataset[dsKey] || '';
    return el.getAttribute('value') || el.value || card.dataset[dsKey] || '';
  }

  var liveFormCode = _readSel(origFormSel, 'sfFormCode');
  var liveFkField  = _readSel(origFkSel,   'sfFkField');
  var liveView     = _readSel(origViewSel, 'sfSubformView') || 'grid';
  var liveIsFk     = origIsFk     ? origIsFk.checked     : (card.dataset.sfIsFk === '1');
  var liveHideGrid = origHideGrid ? origHideGrid.checked : (card.dataset.sfHideInGrid === '1');
  var liveSrvRo    = origSrvRo    ? origSrvRo.checked    : (card.dataset.sfServerReadonly === '1');
  var liveSearchable = origSearchable ? origSearchable.checked : (card.dataset.sfSearchable !== '0');
  var liveSelectedOnly = origSelectedOnly ? origSelectedOnly.checked : (card.dataset.sfSelectedFieldsOnly === '1');
  var liveViewRoles  = origViewRoles ? origViewRoles.value : (card.dataset.sfViewRoles || '');
  var liveEditRoles  = origEditRoles ? origEditRoles.value : (card.dataset.sfEditRoles || '');
  
  console.group('[nb-sfpanel] _renderSubformPanel card.id=' + card.id);
  console.log('  liveFormCode :', liveFormCode);
  console.log('  liveFkField  :', liveFkField);
  console.log('  liveView     :', liveView);
  console.groupEnd();

  if (liveFormCode) card.dataset.sfFormCode    = liveFormCode;
  if (liveFkField)  card.dataset.sfFkField     = liveFkField;
  card.dataset.sfSubformView    = liveView;
  card.dataset.sfIsFk           = liveIsFk     ? '1' : '0';
  card.dataset.sfHideInGrid     = liveHideGrid ? '1' : '0';
  card.dataset.sfServerReadonly = liveSrvRo    ? '1' : '0';
  card.dataset.sfSearchable     = liveSearchable ? '1' : '0';
  card.dataset.sfSelectedFieldsOnly = liveSelectedOnly ? '1' : '0';
  card.dataset.sfViewRoles      = liveViewRoles;
  card.dataset.sfEditRoles      = liveEditRoles;

  var sfWrap = document.createElement('div');
  sfWrap.style.cssText = 'grid-column:1/-1;';
  sfWrap.innerHTML = _subformPanelHTML({
    form_code: liveFormCode, fk_field: liveFkField, subform_view: liveView,
    help_text: card.dataset.sfHelpText || '',
    is_fk: liveIsFk, hide_in_grid: liveHideGrid, server_readonly: liveSrvRo,
    searchable: liveSearchable, selected_fields_only: liveSelectedOnly,
    view_roles: liveViewRoles, edit_roles: liveEditRoles
  });
  var livePanel = sfWrap.querySelector('.nb-sf-fk-panel') || sfWrap;

  var mirrorFormSel  = livePanel.querySelector('.nb-sf-form-code');
  var mirrorView     = livePanel.querySelector('.nb-sf-view');
  var mirrorIsFk     = livePanel.querySelector('.nb-sf-is-fk');
  var mirrorHideGrid = livePanel.querySelector('.nb-sf-hide-in-grid');
  var mirrorSrvRo    = livePanel.querySelector('.nb-sf-server-readonly');
  var mirrorSearchable = livePanel.querySelector('.nb-sf-searchable');
  var mirrorSelectedOnly = livePanel.querySelector('.nb-sf-selected-fields-only');

  if (mirrorIsFk)     mirrorIsFk.checked     = liveIsFk;
  if (mirrorHideGrid) mirrorHideGrid.checked = liveHideGrid;
  if (mirrorSrvRo)    mirrorSrvRo.checked    = liveSrvRo;
  if (mirrorSearchable) mirrorSearchable.checked = liveSearchable;
  if (mirrorSelectedOnly) mirrorSelectedOnly.checked = liveSelectedOnly;
  if (mirrorView && liveView) mirrorView.value = liveView;

  if (origFormSel && liveFormCode) {
    origFormSel.value = liveFormCode;
    origFormSel.setAttribute('value', liveFormCode);
  }
  if (origFkSel && liveFkField) {
    origFkSel.setAttribute('value', liveFkField);
  }
  if (origViewSel && liveView)    { origViewSel.setAttribute('value', liveView); }
  if (origIsFk)     origIsFk.checked     = liveIsFk;
  if (origHideGrid) origHideGrid.checked = liveHideGrid;
  if (origSrvRo)    origSrvRo.checked    = liveSrvRo;
  if (origSearchable) origSearchable.checked = liveSearchable;
  if (origSelectedOnly) origSelectedOnly.checked = liveSelectedOnly;

  // Must be in the live DOM before fetch callbacks fire
  grid.appendChild(sfWrap);

  _populateFormDropdown(livePanel, liveFormCode, function () {
    console.log('[nb-sfpanel] formDropdown populated. liveFormCode=', liveFormCode,
                '| mirrorFormSel.value=', mirrorFormSel ? mirrorFormSel.value : 'N/A');
    if (origFormSel) origFormSel.value = liveFormCode;

    if (liveFormCode) {
      _populateFkDropdown(livePanel, liveFormCode, liveFkField, function () {
        console.log('[nb-sfpanel] fkDropdown populated.',
                    '| livePanel sel.value=', livePanel.querySelector('.nb-sf-fk-field') ? livePanel.querySelector('.nb-sf-fk-field').value : 'N/A',
                    '| liveFkField=', liveFkField);
        // Sync the live panel's FK value back to the hidden body select
        if (origFkSel) {
          var liveFkSel = livePanel.querySelector('.nb-sf-fk-field');
          if (liveFkSel) {
            origFkSel.value = liveFkSel.value;
            origFkSel.setAttribute('value', liveFkSel.value);
            console.log('[nb-sfpanel] origFkSel.value set to:', origFkSel.value);
          }
        }
        card.dataset.sfFkField = liveFkField;
      });
    }
  });

  if (mirrorFormSel) {
    mirrorFormSel.addEventListener('change', function () {
      card.dataset.sfFormCode = mirrorFormSel.value;
      if (origFormSel) { origFormSel.value = mirrorFormSel.value; origFormSel.setAttribute('value', mirrorFormSel.value); }
      if (origFkSel)   { origFkSel.innerHTML = '<option value="">— select FK field —</option>'; origFkSel.value = ''; }
      _populateFkDropdown(livePanel, mirrorFormSel.value, '', function () {
        var liveFkSel = livePanel.querySelector('.nb-sf-fk-field');
        console.log('[nb-sfpanel] formChange FK repopulated. liveFkSel.value=',
                    liveFkSel ? liveFkSel.value : 'N/A');
        if (origFkSel && liveFkSel) {
          origFkSel.innerHTML = liveFkSel.innerHTML; // sync options
          origFkSel.value = liveFkSel.value;
        }
      });
    });
  }

  livePanel.addEventListener('change', function (e) {
    var tgt = e.target;

    // Check if roles checklist changed
    if (tgt.classList.contains('nb-sf-view-role-chk') || tgt.classList.contains('nb-sf-edit-role-chk')) {
      var viewRoles = [];
      livePanel.querySelectorAll('.nb-sf-view-role-chk:checked').forEach(function (chk) { viewRoles.push(chk.value); });
      var editRoles = [];
      livePanel.querySelectorAll('.nb-sf-edit-role-chk:checked').forEach(function (chk) { editRoles.push(chk.value); });

      card.dataset.sfViewRoles = viewRoles.join(',');
      card.dataset.sfEditRoles = editRoles.join(',');

      if (!origViewRoles) {
        origViewRoles = document.createElement('input');
        origViewRoles.type = 'hidden';
        origViewRoles.className = 'nb-sf-view-roles';
        card.querySelector('.nb-cfield-body').appendChild(origViewRoles);
      }
      origViewRoles.value = viewRoles.join(',');
      origViewRoles.setAttribute('value', viewRoles.join(','));

      if (!origEditRoles) {
        origEditRoles = document.createElement('input');
        origEditRoles.type = 'hidden';
        origEditRoles.className = 'nb-sf-edit-roles';
        card.querySelector('.nb-cfield-body').appendChild(origEditRoles);
      }
      origEditRoles.value = editRoles.join(',');
      origEditRoles.setAttribute('value', editRoles.join(','));

      window.nbUpdateLivePreview();
      return;
    }

    var m = (tgt.className || '').match(/\bnb-sf-[\w-]+\b/);
    if (!m) return;

    var origEl = card.querySelector('.nb-cfield-body .' + m[0]);
    if (!origEl) {
      if (tgt.type === 'checkbox') {
        origEl = document.createElement('input');
        origEl.type = 'checkbox';
        origEl.className = m[0];
        card.querySelector('.nb-cfield-body').appendChild(origEl);
      } else {
        origEl = document.createElement('input');
        origEl.type = 'hidden';
        origEl.className = m[0];
        card.querySelector('.nb-cfield-body').appendChild(origEl);
      }
    }

    console.log('[nb-sfpanel] livePanel change', m[0], 'origEl=', !!origEl, 'tgt.value=', tgt.value, 'tgt.checked=', tgt.checked);
    if (tgt.type === 'checkbox') {
      origEl.checked = tgt.checked;
      if (m[0] === 'nb-sf-is-fk')               card.dataset.sfIsFk               = tgt.checked ? '1' : '0';
      if (m[0] === 'nb-sf-hide-in-grid')         card.dataset.sfHideInGrid         = tgt.checked ? '1' : '0';
      if (m[0] === 'nb-sf-server-readonly')      card.dataset.sfServerReadonly      = tgt.checked ? '1' : '0';
      if (m[0] === 'nb-sf-searchable')           card.dataset.sfSearchable           = tgt.checked ? '1' : '0';
      if (m[0] === 'nb-sf-selected-fields-only') card.dataset.sfSelectedFieldsOnly = tgt.checked ? '1' : '0';
    } else {
      origEl.value = tgt.value;
      origEl.setAttribute('value', tgt.value);
      if (m[0] === 'nb-sf-fk-field') card.dataset.sfFkField     = tgt.value;
      if (m[0] === 'nb-sf-view')     card.dataset.sfSubformView = tgt.value;
    }
    window.nbUpdateLivePreview();
  });

  var createBtn = livePanel.querySelector('.nb-sf-create-fk');
  if (createBtn) createBtn.addEventListener('click', function () { _createFkField(livePanel); });
}
/* ─────────────────────────────────────────────────────────────────────
   _renderPropsInPanel
───────────────────────────────────────────────────────────────────── */
function _renderPropsInPanel(card, body) {
  var type = card.dataset.type || 'text';
  var col  = parseInt(card.dataset.col, 10) || 6;

  // Render premium tabs at the top of Properties Panel body
  var tabsContainer = document.createElement('div');
  tabsContainer.className = 'flex border-b border-slate-200 dark:border-slate-800 mb-4';

  var tabNames = ['General', 'Validation', 'Advanced', 'Style'];
  var activeTab = 'General';

  tabNames.forEach(function (tabName) {
    var tabBtn = document.createElement('button');
    tabBtn.type = 'button';
    tabBtn.className = 'flex-grow py-2 text-center text-[11px] font-bold tracking-wider uppercase border-b-2 focus:outline-none transition-colors ' +
      (tabName === activeTab ? 'border-primary text-primary' : 'border-transparent text-slate-500 hover:text-slate-800');
    tabBtn.dataset.tabName = tabName;
    tabBtn.textContent = tabName;
    tabBtn.addEventListener('click', function () {
      activeTab = tabName;
      tabsContainer.querySelectorAll('button').forEach(function (b) {
        b.className = 'flex-grow py-2 text-center text-[11px] font-bold tracking-wider uppercase border-b-2 focus:outline-none transition-colors ' +
          (b.dataset.tabName === activeTab ? 'border-primary text-primary' : 'border-transparent text-slate-500 hover:text-slate-800');
      });
      body.querySelectorAll('.nb-props-tab-pane').forEach(function (p) {
        p.classList.toggle('hidden', p.dataset.tabName !== activeTab);
      });
    });
    tabsContainer.appendChild(tabBtn);
  });
  body.appendChild(tabsContainer);

  // Property Filter Input
  var filterInp = document.createElement('input');
  filterInp.type = 'text';
  filterInp.placeholder = 'Filter properties...';
  filterInp.className = 'w-full px-2.5 py-1.5 text-xs bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary mb-4 transition-all';
  filterInp.addEventListener('input', function () {
    var query = filterInp.value.toLowerCase().trim();
    body.querySelectorAll('.nb-fp').forEach(function (el) {
      var text = (el.querySelector('label')?.textContent || '').toLowerCase();
      el.style.display = text.includes(query) ? '' : 'none';
    });
  });
  body.appendChild(filterInp);

  // Panes
  var panes = {};
  tabNames.forEach(function (tabName) {
    var pane = document.createElement('div');
    pane.className = 'nb-props-tab-pane flex flex-col gap-3' + (tabName === activeTab ? '' : ' hidden');
    pane.dataset.tabName = tabName;
    body.appendChild(pane);
    panes[tabName] = pane;
  });

  // Helpers
  function _fp(labelText, inputEl, full) {
    var wrap = document.createElement('div');
    wrap.className = 'nb-fp flex flex-col gap-1 w-full';
    var lbl = document.createElement('label');
    lbl.className = 'text-[10px] font-bold text-slate-500 uppercase tracking-wider';
    lbl.textContent = labelText;
    wrap.appendChild(lbl);
    wrap.appendChild(inputEl);
    return wrap;
  }

  function _inp(val, ph) {
    var i = document.createElement('input');
    i.type = 'text';
    i.className = 'nu-input w-full px-3 py-1.5 text-xs bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary transition-all';
    i.placeholder = ph || '';
    i.setAttribute('value', val || '');
    i.value = val || '';
    return i;
  }

  function _fromCard(dsKey, bodySelector) {
    var dsVal  = card.dataset[dsKey];
    var bodyEl = card.querySelector('.nb-cfield-body ' + bodySelector);
    var attrVal = bodyEl ? bodyEl.getAttribute('value') : null;
    var propVal = bodyEl ? bodyEl.value : null;
    if (dsVal !== undefined && dsVal !== null && dsVal !== '') return dsVal;
    if (attrVal)  { card.dataset[dsKey] = attrVal; return attrVal; }
    if (propVal)  { card.dataset[dsKey] = propVal; return propVal; }
    return '';
  }

  // --- Style Tab Content ---
  var spanBar = document.createElement('div');
  spanBar.className = 'nb-span-bar flex items-center gap-1 flex-wrap pb-2 border-b border-slate-100 dark:border-slate-800 mb-2';
  var spanLabel = document.createElement('span');
  spanLabel.className = 'text-[11px] font-semibold text-slate-500 uppercase tracking-wider mr-2';
  spanLabel.textContent = 'Width (cols)';
  spanBar.appendChild(spanLabel);
  [3, 4, 6, 8, 12].forEach(function (n) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'nb-span-btn px-2 py-1 text-xs border border-slate-200 dark:border-slate-800 rounded ' + (n === col ? 'bg-primary text-white font-bold' : 'bg-transparent text-slate-500');
    btn.dataset.span = n;
    btn.textContent = n;
    btn.addEventListener('click', function () {
      window.nbFormBuilder._applyColSpan(card, n);
      spanBar.querySelectorAll('.nb-span-btn').forEach(function (b) {
        b.className = 'nb-span-btn px-2 py-1 text-xs border border-slate-200 dark:border-slate-800 rounded ' + (parseInt(b.dataset.span, 10) === n ? 'bg-primary text-white font-bold' : 'bg-transparent text-slate-500');
      });
      window.nbUpdateLivePreview();
    });
    spanBar.appendChild(btn);
  });
  panes['Style'].appendChild(spanBar);

  // --- General Tab Content ---
  if (type !== 'group' && type !== 'tab') {
    var supportedTypes = [
      { value: 'text', label: 'Text' },
      { value: 'textarea', label: 'Textarea' },
      { value: 'number', label: 'Number' },
      { value: 'email', label: 'Email' },
      { value: 'password', label: 'Password' },
      { value: 'date', label: 'Date' },
      { value: 'datetime', label: 'DateTime' },
      { value: 'time', label: 'Time' },
      { value: 'checkbox', label: 'Checkbox' },
      { value: 'file', label: 'File' },
      { value: 'select', label: 'Select' },
      { value: 'select2', label: 'Select2' },
      { value: 'radio', label: 'Radio' },
      { value: 'checkbox_group', label: 'Checkbox Group' },
      { value: 'calculated', label: 'Calculated' },
      { value: 'lookup', label: 'Lookup' },
      { value: 'subform', label: 'Subform' },
      { value: 'color', label: 'Color' },
      { value: 'range', label: 'Range' },
      { value: 'customnumber', label: 'Custom Number' },
      { value: 'autonumber', label: 'Auto-Number' },
      { value: 'uploadbutton', label: 'Upload Button' },
      { value: 'signaturepad', label: 'Signature Pad' }
    ];

    var typeSelect = document.createElement('select');
    typeSelect.className = 'nu-input nu-field-type-select w-full px-3 py-1.5 text-xs bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary mb-3 transition-all';
    typeSelect.style.fontSize = '12px';

    supportedTypes.forEach(function (t) {
      var opt = document.createElement('option');
      opt.value = t.value;
      opt.textContent = t.label;
      if (t.value === type) opt.selected = true;
      typeSelect.appendChild(opt);
    });

    typeSelect.addEventListener('change', function () {
      var newType = typeSelect.value;
      if (newType === type) return;

      var currentData = _readFieldCard(card);
      currentData.type = newType;

      var newCard = window.nbFormBuilder._makeFieldCard(newType, currentData.label, currentData.name, !!currentData.required, currentData);

      card.parentNode.replaceChild(newCard, card);

      window.nbFormBuilder._isDirty = true;
      window.nbFormBuilder._updateEmptyState();

      _openPropsPanel(newCard);

      if (window.nbUpdateLivePreview) window.nbUpdateLivePreview();
      if (window.nbUpdateTreeOutline) window.nbUpdateTreeOutline();
    });

    panes['General'].appendChild(_fp('Type', typeSelect));
  }

  var labelInput = _inp(_fromCard('fieldLabel', '.nu-field-label'), 'Field label');
  labelInput.addEventListener('input', function () {
    card.dataset.fieldLabel = labelInput.value;
    var orig = card.querySelector('.nb-cfield-body .nu-field-label');
    if (orig) { orig.value = labelInput.value; orig.setAttribute('value', labelInput.value); }
    var hdr = card.querySelector('.nb-cfield-label');
    if (hdr) hdr.textContent = labelInput.value || '(no label)';
    var titleEl = document.getElementById('nb-props-title');
    if (titleEl) titleEl.textContent = labelInput.value || 'Properties';
    window.nbUpdateLivePreview();
  });

  var nameInput = _inp(_fromCard('fieldName', '.nu-field-name'), 'field_name');
  nameInput.addEventListener('input', function () {
    card.dataset.fieldName = nameInput.value;
    var orig = card.querySelector('.nb-cfield-body .nu-field-name');
    if (orig) { orig.value = nameInput.value; orig.setAttribute('value', nameInput.value); }
    window.nbUpdateLivePreview();
  });

  panes['General'].appendChild(_fp('Label', labelInput));
  panes['General'].appendChild(_fp('Field Name', nameInput));

  if (type !== 'subform') {
    var phInput = _inp(_fromCard('fieldPh', '.nu-field-placeholder'), 'Placeholder text');
    phInput.addEventListener('input', function () {
      card.dataset.fieldPh = phInput.value;
      var orig = card.querySelector('.nb-cfield-body .nu-field-placeholder');
      if (orig) { orig.value = phInput.value; orig.setAttribute('value', phInput.value); }
      window.nbUpdateLivePreview();
    });

    var defInput = _inp(_fromCard('fieldDefault', '.nu-field-default'), 'Default value');
    defInput.addEventListener('input', function () {
      card.dataset.fieldDefault = defInput.value;
      var orig = card.querySelector('.nb-cfield-body .nu-field-default');
      if (orig) { orig.value = defInput.value; orig.setAttribute('value', defInput.value); }
      window.nbUpdateLivePreview();
    });

    panes['General'].appendChild(_fp('Placeholder', phInput));
    panes['General'].appendChild(_fp('Default', defInput));
  }

  var helpInput = _inp(_fromCard('fieldHelp', '.nu-field-help'), 'Help text shown to user');
  helpInput.addEventListener('input', function () {
    card.dataset.fieldHelp = helpInput.value;
    var orig = card.querySelector('.nb-cfield-body .nu-field-help');
    if (orig) { orig.value = helpInput.value; orig.setAttribute('value', helpInput.value); }
    window.nbUpdateLivePreview();
  });
  panes['General'].appendChild(_fp('Help Text', helpInput, true));

  // --- Validation Tab Content ---
  var visWrap = document.createElement('div');
  visWrap.className = 'nb-vis-flags flex flex-col gap-2 p-3 bg-slate-50 dark:bg-slate-900/60 rounded-xl border border-slate-200 dark:border-slate-800';
  [
    { cls: 'nu-field-required',      label: 'Required' },
    { cls: 'nu-field-no-duplicate',  label: 'No Duplicate' },
    { cls: 'nu-field-readonly',      label: 'Readonly' },
    { cls: 'nu-field-hidden',        label: 'Hidden' },
    { cls: 'nu-field-hidden-normal', label: 'Hidden for normal users' },
    { cls: 'nu-field-searchable',    label: 'Searchable (Subform)' },
    { cls: 'nu-field-hide-in-grid',  label: 'Hide in Grid (Subform)' }
  ].forEach(function (flag) {
    var origChk = card.querySelector('.nb-cfield-body .' + flag.cls);
    var lbl = document.createElement('label');
    lbl.className = 'flex items-center gap-2 text-xs text-slate-700 dark:text-slate-300 cursor-pointer';
    var chk = document.createElement('input');
    chk.type = 'checkbox';
    chk.className = 'rounded text-primary focus:ring-primary border-slate-300 dark:border-slate-700';
    if (flag.cls === 'nu-field-searchable') {
      chk.checked = origChk ? origChk.checked : true;
    } else {
      chk.checked = !!(origChk && origChk.checked);
    }
    chk.addEventListener('change', function () {
      if (origChk) origChk.checked = chk.checked;
      window.nbUpdateLivePreview();
    });
    lbl.appendChild(chk);
    lbl.appendChild(document.createTextNode(' ' + flag.label));
    visWrap.appendChild(lbl);
  });
  panes['Validation'].appendChild(visWrap);

  // Style flags
  var styleFlagsWrap = document.createElement('div');
  styleFlagsWrap.className = 'flex flex-col gap-2 p-3 bg-slate-50 dark:bg-slate-900/60 rounded-xl border border-slate-200 dark:border-slate-800';
  [
    { cls: 'nu-field-label-on-top',  label: 'Label on Top' },
    { cls: 'nu-field-chk-label-left', label: 'Checkbox Label on Left' },
    { cls: 'nu-field-collapsible-group', label: 'Collapsible Group/Section' }
  ].forEach(function (flag) {
    var origChk = card.querySelector('.nb-cfield-body .' + flag.cls);
    var lbl = document.createElement('label');
    lbl.className = 'flex items-center gap-2 text-xs text-slate-700 dark:text-slate-300 cursor-pointer';
    var chk = document.createElement('input');
    chk.type = 'checkbox';
    chk.className = 'rounded text-primary focus:ring-primary border-slate-300 dark:border-slate-700';
    chk.checked = !!(origChk && origChk.checked);
    chk.addEventListener('change', function () {
      if (origChk) origChk.checked = chk.checked;
      window.nbUpdateLivePreview();
    });
    lbl.appendChild(chk);
    lbl.appendChild(document.createTextNode(' ' + flag.label));
    styleFlagsWrap.appendChild(lbl);
  });
  panes['Style'].appendChild(styleFlagsWrap);

  // --- Advanced Tab Content ---
  var cardBody = card.querySelector('.nb-cfield-body');
  if (cardBody) {
    var bodyGrid = cardBody.querySelector('.nb-fp-grid');
    if (bodyGrid) {
      Array.prototype.forEach.call(bodyGrid.children, function (child) {
        // Skip fields already mirrored above
        if (
          child.querySelector('.nu-field-label') ||
          child.querySelector('.nu-field-name') ||
          child.querySelector('.nu-field-placeholder') ||
          child.querySelector('.nu-field-default') ||
          child.querySelector('.nu-field-help') ||
          child.classList.contains('nb-vis-flags')
        ) return;

        // Subform panel: build live, never clone
        if (child.classList.contains('nb-sf-fk-panel')) {
          _renderSubformPanel(card, panes['Advanced']);
          return;
        }

        // Collapsible Advanced section
        var clone = child.cloneNode(true);
        if (clone.classList.contains('nb-select-manual') || clone.classList.contains('nb-select-from-table')) {
          // Keep flat but synced
        } else {
          var innerBody = document.createElement('div');
          innerBody.className = 'p-3 flex flex-col gap-2';
          while (clone.firstChild) {
            innerBody.appendChild(clone.firstChild);
          }

          clone.className = 'nb-prop-section border border-slate-200 dark:border-slate-800 rounded-lg overflow-hidden mb-2';
          var origHeader = document.createElement('div');
          origHeader.className = 'bg-slate-100 dark:bg-slate-800/40 p-2 text-xs font-semibold cursor-pointer flex justify-between items-center';
          origHeader.innerHTML = '<span>Configure element settings</span><span>▼</span>';
          origHeader.addEventListener('click', function () {
            innerBody.classList.toggle('hidden');
            var isHidden = innerBody.classList.contains('hidden');
            origHeader.querySelector('span:last-child').textContent = isHidden ? '▶' : '▼';
          });
          clone.appendChild(origHeader);
          clone.appendChild(innerBody);
        }

        child.querySelectorAll('input, select, textarea').forEach(function (origEl, idx) {
          var cloneEl = clone.querySelectorAll('input, select, textarea')[idx];
          if (!cloneEl) return;
          if (origEl.type === 'checkbox' || origEl.type === 'radio') {
            cloneEl.checked = origEl.checked;
            if (origEl.type === 'radio') {
              cloneEl.name = 'props-clone-' + origEl.name;
            }
          } else {
            var val = (origEl.value !== undefined && origEl.value !== '') ? origEl.value : (origEl.getAttribute('value') || '');
            cloneEl.setAttribute('value', val);
            cloneEl.value = val;
          }
          (function (o, c) {
            c.addEventListener('input', function () {
              if (o.type !== 'checkbox' && o.type !== 'radio') {
                o.value = c.value;
                o.setAttribute('value', c.value);
                window.nbUpdateLivePreview();
              }
            });
            c.addEventListener('change', function () {
              if (o.type === 'checkbox' || o.type === 'radio') {
                if (o.type === 'radio') {
                  var originalGroup = card.querySelectorAll('input[type="radio"][name="' + o.name + '"]');
                  originalGroup.forEach(function (origRadio) {
                    origRadio.checked = false;
                  });
                }
                o.checked = c.checked;
                var evt = document.createEvent('HTMLEvents');
                evt.initEvent('change', true, false);
                o.dispatchEvent(evt);

                if (c.classList.contains('nu-field-opt-src')) {
                  var isTable = c.value === 'table' && c.checked;
                  var clManual = panes['Advanced'].querySelector('.nb-select-manual');
                  var clTable = panes['Advanced'].querySelector('.nb-select-from-table');
                  if (clManual) clManual.style.display = isTable ? 'none' : '';
                  if (clTable) clTable.style.display = isTable ? '' : 'none';
                }
              } else {
                o.value = c.value;
                o.setAttribute('value', c.value);
              }
              window.nbUpdateLivePreview();
            });
          }(origEl, cloneEl));
        });
        panes['Advanced'].appendChild(clone);
      });
    }
  }

  // Custom Attributes in Advanced Tab
  var attrWrap = document.createElement('div');
  attrWrap.className = 'nb-fp flex flex-col gap-1 mt-2';
  var attrLabel = document.createElement('label');
  attrLabel.className = 'text-[11px] font-semibold text-slate-500 uppercase tracking-wider';
  attrLabel.textContent = 'Custom Attributes (JSON)';
  attrWrap.appendChild(attrLabel);
  var attrArea = document.createElement('textarea');
  attrArea.className = 'nu-input w-full px-3 py-1.5 text-xs bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary transition-all';
  attrArea.rows = 2;
  attrArea.placeholder = 'data-custom="value"';
  var liveAttr = _fromCard('fieldCustomAttrs', '.nu-field-custom-attrs');
  attrArea.value = liveAttr;
  attrArea.addEventListener('input', function () {
    card.dataset.fieldCustomAttrs = attrArea.value;
    var orig = card.querySelector('.nb-cfield-body .nu-field-custom-attrs');
    if (orig) { orig.value = attrArea.value; orig.setAttribute('value', attrArea.value); }
    window.nbUpdateLivePreview();
  });
  attrWrap.appendChild(attrArea);
  panes['Advanced'].appendChild(attrWrap);

  // Ensure correct initial option source panel visibility inside Advanced pane
  var initSrcTable = panes['Advanced'].querySelector('.nu-field-opt-src[value="table"]');
  var initIsTable = initSrcTable ? initSrcTable.checked : false;
  var clManual = panes['Advanced'].querySelector('.nb-select-manual');
  var clTable = panes['Advanced'].querySelector('.nb-select-from-table');
  if (clManual) clManual.style.display = initIsTable ? 'none' : '';
  if (clTable) clTable.style.display = initIsTable ? '' : 'none';
}

function _openPropsPanel(card) {
  var panel = _ensurePropsPanel();

  if (_activeCard && _activeCard !== card) {
    _activeCard.classList.remove('nb-cfield-selected');
  }

  _activeCard = card;
  card.classList.add('nb-cfield-selected');

  var type = card.dataset.type || 'field';
  document.getElementById('nb-props-type-badge').textContent = type.toUpperCase();

  var labelEl = card.querySelector('.nb-cfield-label');
  document.getElementById('nb-props-title').textContent =
    labelEl ? (labelEl.textContent || 'Properties') : 'Properties';

  var body = document.getElementById('nb-props-panel-body');
  body.innerHTML = '';

  _renderPropsInPanel(card, body);

  panel.classList.add('open');

  var canvas = document.getElementById('formCanvas');
  if (canvas) canvas.style.marginRight = '320px';
}
  /* ════════════════════════════════════════════════════════════════════
     _nbSfData
  ═══════════════════════════════════════════════════════════════════ */
  var _nbSfData = (function () {
    function _sfRead(card) {
      var fc = card.dataset.sfFormCode || '';
      if (fc) {
        return {
          form_code: fc,
          fk_field: card.dataset.sfFkField || '',
          subform_view: card.dataset.sfSubformView || 'grid',
          help_text: card.dataset.sfHelpText || '',
          is_fk: card.dataset.sfIsFk === '1',
          hide_in_grid: card.dataset.sfHideInGrid === '1',
          server_readonly: card.dataset.sfServerReadonly === '1',
          searchable: card.dataset.sfSearchable === '1',
          selected_fields_only: card.dataset.sfSelectedFieldsOnly === '1',
          view_roles: card.dataset.sfViewRoles || '',
          edit_roles: card.dataset.sfEditRoles || ''
        };
      }
      var raw = card.dataset.fieldJson || card.dataset.fieldData || '';
      if (raw) {
        try {
          var obj = JSON.parse(raw); var sf = (obj.subform && typeof obj.subform === 'object') ? obj.subform : {}; var fc2 = sf.form_code || sf.formcode || '';
          if (fc2) {
            _sfWrite(card, {
              form_code: fc2,
              fk_field: sf.fk_field || sf.fkfield || '',
              subform_view: obj.subform_view || sf.subform_view || 'grid',
              help_text: obj.help_text || obj.field_help_text || '',
              is_fk: !!sf.is_fk,
              hide_in_grid: !!sf.hide_in_grid,
              server_readonly: !!sf.server_readonly,
              searchable: sf.searchable !== undefined ? !!sf.searchable : (obj.searchable !== undefined ? !!obj.searchable : false),
              selected_fields_only: sf.selected_fields_only !== undefined ? !!sf.selected_fields_only : (obj.selected_fields_only !== undefined ? !!obj.selected_fields_only : false),
              view_roles: sf.view_roles || obj.view_roles || '',
              edit_roles: sf.edit_roles || obj.edit_roles || ''
            });
            return _sfRead(card);
          }
        } catch (e) {}
      }
      return { form_code: card.dataset.subformFormCode || card.dataset.formCode || '', fk_field: card.dataset.subformFkField || card.dataset.fkField || '', subform_view: 'grid', help_text: '', is_fk: false, hide_in_grid: false, server_readonly: false, searchable: false, selected_fields_only: false, view_roles: '', edit_roles: '' };
    }
    function _sfWrite(card, obj) {
      if (!obj) return;
      if (obj.form_code)    card.dataset.sfFormCode       = obj.form_code;
      if (obj.fk_field)     card.dataset.sfFkField        = obj.fk_field;
      if (obj.subform_view) card.dataset.sfSubformView    = obj.subform_view;
      if (obj.help_text !== undefined) card.dataset.sfHelpText = obj.help_text;
      card.dataset.sfIsFk           = obj.is_fk           ? '1' : '0';
      card.dataset.sfHideInGrid     = obj.hide_in_grid    ? '1' : '0';
      card.dataset.sfServerReadonly = obj.server_readonly ? '1' : '0';
      card.dataset.sfSearchable     = obj.searchable      ? '1' : '0';
      card.dataset.sfSelectedFieldsOnly = obj.selected_fields_only ? '1' : '0';
      card.dataset.sfViewRoles      = obj.view_roles      || '';
      card.dataset.sfEditRoles      = obj.edit_roles      || '';
    }
    function _sfClear(card) {
      ['sfFormCode','sfFkField','sfSubformView','sfHelpText','sfIsFk','sfHideInGrid','sfServerReadonly','sfSearchable','sfSelectedFieldsOnly','sfViewRoles','sfEditRoles','fieldJson','fieldData'].forEach(function (k) { delete card.dataset[k]; });
    }
    return { read: _sfRead, write: _sfWrite, clear: _sfClear };
  }());
  window._nbSfData = _nbSfData;


  /* ════════════════════════════════════════════════════════════════════
     Core helpers
  ═══════════════════════════════════════════════════════════════════ */
  var _fieldCounter = 0;

  function _esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function _visibilityFlagsHTML(extra) {
    extra = extra || {};
    var searchableChecked = (extra.searchable !== false && extra.searchable !== 'false' && extra.searchable !== 0 && extra.searchable !== '0') ? ' checked' : '';
    return '<div class="nb-fp nb-fp-full nb-vis-flags" style="grid-column:1/-1;display:flex;flex-wrap:wrap;gap:10px 18px;padding:8px 10px;background:var(--bg-offset,#f5f7ff);border:1px solid var(--border,#e0e4ef);border-radius:7px;margin-top:4px;">'
      + '<label style="font-size:11px;font-weight:700;color:var(--text-muted,#888);text-transform:uppercase;letter-spacing:.5px;flex-basis:100%;margin-bottom:2px;">Field Options</label>'
      + '<label class="nb-fp-check" style="font-size:12px;cursor:pointer;display:flex;align-items:center;gap:5px;"><input type="checkbox" class="nu-field-required"'      + (extra.required              ? ' checked' : '') + '> Required</label>'
      + '<label class="nb-fp-check" style="font-size:12px;cursor:pointer;display:flex;align-items:center;gap:5px;"><input type="checkbox" class="nu-field-no-duplicate"'  + (extra.no_duplicate          ? ' checked' : '') + '> No Duplicate</label>'
      + '<label class="nb-fp-check" style="font-size:12px;cursor:pointer;display:flex;align-items:center;gap:5px;"><input type="checkbox" class="nu-field-readonly"'      + (extra.readonly              ? ' checked' : '') + '> Readonly</label>'
      + '<label class="nb-fp-check" style="font-size:12px;cursor:pointer;display:flex;align-items:center;gap:5px;"><input type="checkbox" class="nu-field-hidden"'        + (extra.hidden                ? ' checked' : '') + '> Hidden</label>'
      + '<label class="nb-fp-check" style="font-size:12px;cursor:pointer;display:flex;align-items:center;gap:5px;"><input type="checkbox" class="nu-field-hidden-normal"' + (extra.hidden_for_normal_users ? ' checked' : '') + '> Hidden for normal users</label>'
      + '<label class="nb-fp-check" style="font-size:12px;cursor:pointer;display:flex;align-items:center;gap:5px;"><input type="checkbox" class="nu-field-searchable"'    + searchableChecked + '> Searchable</label>'
      + '<label class="nb-fp-check" style="font-size:12px;cursor:pointer;display:flex;align-items:center;gap:5px;"><input type="checkbox" class="nu-field-hide-in-grid"'  + (extra.hide_in_grid          ? ' checked' : '') + '> Hide in Grid</label>'
      + '</div>';
  }

  /* ── Row drag ── */
  function _wireRowDrag(rowEl) {
    var handle = rowEl.querySelector(':scope > .nb-row-header > .nb-row-drag, :scope > .nb-container-header > .nb-row-drag');
    if (!handle || rowEl._nbRowDragWired) return;
    rowEl._nbRowDragWired = true;

    rowEl.setAttribute('draggable', 'false');

    handle.addEventListener('mousedown', function () {
      rowEl.setAttribute('draggable', 'true');
    });

    handle.addEventListener('mouseup', function () {
      rowEl.setAttribute('draggable', 'false');
    });

    handle.addEventListener('mouseleave', function () {
      rowEl.setAttribute('draggable', 'false');
    });

    rowEl.addEventListener('dragstart', function (e) {
      if (e.target !== rowEl || rowEl.getAttribute('draggable') !== 'true') {
        return;
      }
      e.stopPropagation();
      var rId = rowEl.id || (rowEl.id = 'nb-row-' + Date.now() + '-' + Math.random().toString(36).slice(2,5));
      e.dataTransfer.setData('text/nb-row-id', rId);
      e.dataTransfer.effectAllowed = 'move';
      rowEl.classList.add('drag-row-source');
      window._nbDraggedRowOrContainer = rowEl;
    });

    rowEl.addEventListener('dragend', function () {
      rowEl.classList.remove('drag-row-source');
      rowEl.setAttribute('draggable', 'false');
      document.querySelectorAll('.drag-row-over').forEach(function (el) { el.classList.remove('drag-row-over'); });
      window._nbDraggedRowOrContainer = null;
    });
  }

  /* ── FIX-H: resize handle ── */
  function _attachResizeHandle(card) {
    if (card._nbResizeWired) return;
    card._nbResizeWired = true;
    var handle = document.createElement('div');
    handle.className = 'nb-cfield-resize';
    card.appendChild(handle);
    var startX, startW;
    handle.addEventListener('mousedown', function (e) {
      e.preventDefault(); e.stopPropagation();
      startX = e.clientX; startW = card.offsetWidth;
      function onMove(ev) {
        var newW = Math.max(80, startW + (ev.clientX - startX));
        card.style.width = newW + 'px'; card.style.flex = '0 0 ' + newW + 'px';
        var parent = card.parentNode;
        if (parent) {
          var parentW = parent.offsetWidth || 1;
          var col = Math.min(12, Math.max(1, Math.round((newW / parentW) * 12)));
          card.dataset.col = String(col);
          var badge = card.querySelector('.nb-cfield-span-badge'); if (badge) badge.textContent = col + '/12';
          var panel = document.getElementById('nb-props-panel');
          if (panel && panel.classList.contains('open') && _activeCard === card) {
            panel.querySelectorAll('.nb-span-btn').forEach(function (btn) { btn.classList.toggle('active', parseInt(btn.dataset.span, 10) === col); });
          }
        }
      }
      function onUp() {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        if (card.dataset.col) {
          window.nbFormBuilder._applyColSpan(card, card.dataset.col);
        }
        window.nbFormBuilder._isDirty = true;
      }
      document.addEventListener('mousemove', onMove); document.addEventListener('mouseup', onUp);
    });
  }

  /* ── Unified Row/Container Drag-and-Drop ── */
  function _attachRowContainerDragAndDrop(container) {
    if (!container) return;
    if (container._nbRowDropWired) return;
    container._nbRowDropWired = true;

    container.addEventListener('dragover', function (e) {
      var dragged = window._nbDraggedRowOrContainer;
      if (!dragged) return;

      // Safety check: Avoid hierarchy DOM exception if trying to drag a container into its own descendant
      if (dragged.contains(container)) {
        return;
      }

      // Group/Tab containers cannot be nested inside Group container bodies
      if (dragged.classList.contains('nb-container') && container.classList.contains('nb-container-group-body')) {
        return;
      }

      e.preventDefault();
      e.stopPropagation();

      var target = e.target;
      while (target && target.parentNode !== container) {
        target = target.parentNode;
      }

      document.querySelectorAll('.drag-row-over').forEach(function (el) {
        if (el !== target) el.classList.remove('drag-row-over');
      });

      if (target && target !== dragged) {
        target.classList.add('drag-row-over');
      } else {
        container.classList.add('drag-row-over');
      }
    });

    container.addEventListener('dragleave', function (e) {
      if (!container.contains(e.relatedTarget)) {
        container.classList.remove('drag-row-over');
        document.querySelectorAll('.drag-row-over').forEach(function (el) {
          el.classList.remove('drag-row-over');
        });
      }
    });

    container.addEventListener('drop', function (e) {
      var dragged = window._nbDraggedRowOrContainer;
      if (!dragged) return;

      // Safety check: Avoid hierarchy DOM exception if trying to drag a container into its own descendant
      if (dragged.contains(container)) {
        return;
      }

      if (dragged.classList.contains('nb-container') && container.classList.contains('nb-container-group-body')) {
        return;
      }

      e.preventDefault();
      e.stopPropagation();

      container.classList.remove('drag-row-over');
      document.querySelectorAll('.drag-row-over').forEach(function (el) {
        el.classList.remove('drag-row-over');
      });

      var target = e.target;
      while (target && target.parentNode !== container) {
        target = target.parentNode;
      }

      // Keep track of the original parent to handle hints
      var oldParent = dragged.parentNode;

      // Update inner-row class based on whether destination is main canvas or container body
      if (dragged.classList.contains('nb-row')) {
        if (container.id === 'formCanvas') {
          dragged.classList.remove('nb-inner-row');
        } else {
          dragged.classList.add('nb-inner-row');
        }
      }

      // Remove hint from target container
      var hint = container.querySelector(':scope > .nb-row-drop-hint');
      if (hint) hint.remove();

      if (target && target !== dragged) {
        var rect = target.getBoundingClientRect();
        if (e.clientY < rect.top + rect.height / 2) {
          container.insertBefore(dragged, target);
        } else {
          container.insertBefore(dragged, target.nextSibling);
        }
      } else {
        container.appendChild(dragged);
      }

      // Restore hint to old parent if now empty
      if (oldParent && oldParent !== container) {
        if (oldParent.classList.contains('nb-container-group-body')) {
          if (!oldParent.querySelector('.nb-row') && !oldParent.querySelector('.nb-inner-row')) {
            if (!oldParent.querySelector('.nb-row-drop-hint')) {
              var h = document.createElement('div');
              h.className = 'nb-row-drop-hint';
              h.textContent = 'Click "+ Row" to add a row, then drop fields in';
              oldParent.appendChild(h);
            }
          }
        } else if (oldParent.classList.contains('nb-tab-panel-rows')) {
          if (!oldParent.querySelector('.nb-row') && !oldParent.querySelector('.nb-inner-row') && !oldParent.querySelector('.nb-container-group')) {
            if (!oldParent.querySelector('.nb-row-drop-hint')) {
              var h = document.createElement('div');
              h.className = 'nb-row-drop-hint';
              h.textContent = 'Click "+ Row" or "+ Group" to add content';
              oldParent.appendChild(h);
            }
          }
        }
      }

      window.nbFormBuilder._updateEmptyState();
      window.nbFormBuilder._isDirty = true;
    });
  }

  /* ── Canvas drop ── */
  function _attachCanvasRowDrop(canvas) {
    if (canvas._nbCanvasRowDropWired) return;
    canvas._nbCanvasRowDropWired = true;
    canvas.addEventListener('dragover', function (e) {
      var types = e.dataTransfer.types; if (!types) return;
      var hasRowId  = Array.prototype.indexOf.call(types, 'text/nb-row-id')  !== -1;
      var hasNbType = Array.prototype.indexOf.call(types, 'text/nb-type')    !== -1;
      var hasPlain  = Array.prototype.indexOf.call(types, 'text/plain')      !== -1;
      if (hasNbType || hasPlain) { e.preventDefault(); e.stopPropagation(); canvas.classList.add('nb-canvas-tool-over'); return; }
      if (hasRowId) return; // Let unified row drag-and-drop handle it
    });
    canvas.addEventListener('dragleave', function (e) {
      if (!canvas.contains(e.relatedTarget)) {
        canvas.classList.remove('nb-canvas-tool-over');
      }
    });
    canvas.addEventListener('drop', function (e) {
      var hasRowId = e.dataTransfer.types && Array.prototype.indexOf.call(e.dataTransfer.types, 'text/nb-row-id') !== -1;
      if (hasRowId) return; // Let unified row drag-and-drop handle it
      canvas.classList.remove('nb-canvas-tool-over');
      var dtype = e.dataTransfer.getData('text/nb-type') || e.dataTransfer.getData('text/plain') || '';
      if (dtype) {
        if (dtype === 'group' || dtype === 'tab') { e.preventDefault(); e.stopPropagation(); window.nbFormBuilder.addField(dtype, {}); return; }
        e.preventDefault(); e.stopPropagation();
        var row = window.nbFormBuilder.addRow();
        var rb  = row ? row.querySelector('.nb-row-body') : null;
        if (rb) {
          var nc = window.nbFormBuilder._makeFieldCard(dtype, dtype + ' field', dtype + '_' + Date.now(), false, { col: 6 });
          if (nc) { _prepCard(nc); var hint = rb.querySelector('.nb-row-drop-hint'); if (hint) hint.remove(); rb.appendChild(nc); window.nbFormBuilder._applyColSpan(nc, 6); }
        }
        window.nbFormBuilder._updateEmptyState(); return;
      }
    });
  }


  /* ════════════════════════════════════════════════════════════════════
     GROUP container
  ═══════════════════════════════════════════════════════════════════ */
  function _applyGroupStyles(wrap) {
    var body = wrap.querySelector('.nb-container-group-body');
    var header = wrap.querySelector('.nb-container-header');

    if (wrap.dataset.bgColor) {
      if (body) body.style.backgroundColor = wrap.dataset.bgColor;
      wrap.style.backgroundColor = wrap.dataset.bgColor;
    } else {
      if (body) body.style.backgroundColor = '';
      wrap.style.backgroundColor = '';
    }

    if (wrap.dataset.headerBgColor) {
      if (header) header.style.backgroundColor = wrap.dataset.headerBgColor;
    } else {
      if (header) header.style.backgroundColor = '';
    }

    if (wrap.dataset.textColor) {
      if (header) {
        header.style.color = wrap.dataset.textColor;
        var li = header.querySelector('.nb-container-label-input');
        if (li) li.style.color = wrap.dataset.textColor;
      }
    } else {
      if (header) {
        header.style.color = '';
        var li = header.querySelector('.nb-container-label-input');
        if (li) li.style.color = '';
      }
    }

    var bStyle = wrap.dataset.borderStyle || 'solid';
    var bColor = wrap.dataset.borderColor || 'var(--border,#e0e4ef)';
    var bWidth = wrap.dataset.borderWidth || '1px';

    if (wrap.dataset.borderColor || wrap.dataset.borderStyle || wrap.dataset.borderWidth) {
      wrap.style.border = bWidth + ' ' + bStyle + ' ' + bColor;
    } else {
      wrap.style.border = '';
    }
  }

  function _openGroupSettingsModal(wrap) {
    var modal = document.createElement('div');
    modal.className = 'nu-modal-overlay';
    modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.4);z-index:99999;display:flex;align-items:center;justify-content:center;font-family:sans-serif;';

    var content = document.createElement('div');
    content.style.cssText = 'background:#fff;padding:20px;border-radius:10px;width:380px;box-shadow:0 10px 25px rgba(0,0,0,0.15);display:flex;flex-direction:column;gap:12px;color:#333;';

    var title = document.createElement('h4');
    title.style.cssText = 'margin:0 0 8px;font-size:16px;border-bottom:1px solid #eee;padding-bottom:8px;font-weight:600;display:flex;justify-content:space-between;align-items:center;';
    title.innerHTML = '<span>Group Settings</span>';
    content.appendChild(title);

    // Collapsible Checkbox
    var isCollapsible = wrap.dataset.collapsible === 'true';
    var collapsibleLabel = document.createElement('label');
    collapsibleLabel.style.cssText = 'display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;';
    var collapsibleChk = document.createElement('input');
    collapsibleChk.type = 'checkbox';
    collapsibleChk.checked = isCollapsible;
    collapsibleLabel.appendChild(collapsibleChk);
    collapsibleLabel.appendChild(document.createTextNode('Enable Collapsible (Accordion)'));
    content.appendChild(collapsibleLabel);

    // Collapsed by Default Checkbox
    var isCollapsedByDefault = wrap.dataset.collapsedByDefault === 'true';
    var collapsedLabel = document.createElement('label');
    collapsedLabel.style.cssText = 'display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;margin-bottom:8px;';
    var collapsedChk = document.createElement('input');
    collapsedChk.type = 'checkbox';
    collapsedChk.checked = isCollapsedByDefault;
    collapsedLabel.appendChild(collapsedChk);
    collapsedLabel.appendChild(document.createTextNode('Collapsed by Default'));
    content.appendChild(collapsedLabel);

    function createFieldRow(labelText, inputEl) {
      var row = document.createElement('div');
      row.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:13px;';
      var lbl = document.createElement('span');
      lbl.textContent = labelText;
      lbl.style.fontWeight = '500';
      row.appendChild(lbl);
      row.appendChild(inputEl);
      return row;
    }

    // Color Pickers
    var bgInput = document.createElement('input');
    bgInput.type = 'color';
    bgInput.value = wrap.dataset.bgColor && wrap.dataset.bgColor.indexOf('#') === 0 ? wrap.dataset.bgColor : '#ffffff';
    bgInput.style.cssText = 'cursor:pointer;border:1px solid #ddd;border-radius:4px;width:60px;height:28px;padding:0;';
    var bgRow = createFieldRow('Background Color', bgInput);
    content.appendChild(bgRow);

    var headerBgInput = document.createElement('input');
    headerBgInput.type = 'color';
    headerBgInput.value = wrap.dataset.headerBgColor && wrap.dataset.headerBgColor.indexOf('#') === 0 ? wrap.dataset.headerBgColor : '#f8f9fa';
    headerBgInput.style.cssText = 'cursor:pointer;border:1px solid #ddd;border-radius:4px;width:60px;height:28px;padding:0;';
    var headerBgRow = createFieldRow('Header Background Color', headerBgInput);
    content.appendChild(headerBgRow);

    var borderInput = document.createElement('input');
    borderInput.type = 'color';
    borderInput.value = wrap.dataset.borderColor && wrap.dataset.borderColor.indexOf('#') === 0 ? wrap.dataset.borderColor : '#4f6bed';
    borderInput.style.cssText = 'cursor:pointer;border:1px solid #ddd;border-radius:4px;width:60px;height:28px;padding:0;';
    var borderColorRow = createFieldRow('Border/Outline Color', borderInput);
    content.appendChild(borderColorRow);

    var textColInput = document.createElement('input');
    textColInput.type = 'color';
    textColInput.value = wrap.dataset.textColor && wrap.dataset.textColor.indexOf('#') === 0 ? wrap.dataset.textColor : '#333333';
    textColInput.style.cssText = 'cursor:pointer;border:1px solid #ddd;border-radius:4px;width:60px;height:28px;padding:0;';
    var textColorRow = createFieldRow('Text/Header Color', textColInput);
    content.appendChild(textColorRow);

    // Border Style Dropdown
    var borderStyleSel = document.createElement('select');
    borderStyleSel.className = 'nu-input';
    borderStyleSel.style.cssText = 'width:120px;padding:4px;font-size:13px;';
    ['solid', 'dashed', 'dotted', 'double', 'none'].forEach(function (style) {
      var opt = document.createElement('option');
      opt.value = style;
      opt.textContent = style;
      if (wrap.dataset.borderStyle === style) opt.selected = true;
      borderStyleSel.appendChild(opt);
    });
    var borderStyleRow = createFieldRow('Border Style', borderStyleSel);
    content.appendChild(borderStyleRow);

    // Border Width Dropdown
    var borderWidthSel = document.createElement('select');
    borderWidthSel.className = 'nu-input';
    borderWidthSel.style.cssText = 'width:120px;padding:4px;font-size:13px;';
    ['1px', '2px', '3px', '4px', '5px'].forEach(function (w) {
      var opt = document.createElement('option');
      opt.value = w;
      opt.textContent = w;
      if (wrap.dataset.borderWidth === w) opt.selected = true;
      borderWidthSel.appendChild(opt);
    });
    var borderWidthRow = createFieldRow('Border Width', borderWidthSel);
    content.appendChild(borderWidthRow);

    var actions = document.createElement('div');
    actions.style.cssText = 'display:flex;justify-content:flex-end;gap:8px;margin-top:10px;border-top:1px solid #eee;padding-top:12px;';

    var cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'nu-btn nu-btn-ghost nu-btn-sm';
    cancelBtn.textContent = 'Cancel';
    cancelBtn.addEventListener('click', function () { modal.remove(); });

    var saveBtn = document.createElement('button');
    saveBtn.type = 'button';
    saveBtn.className = 'nu-btn nu-btn-primary nu-btn-sm';
    saveBtn.textContent = 'Save';
    saveBtn.addEventListener('click', function () {
      wrap.dataset.collapsible = collapsibleChk.checked ? 'true' : 'false';
      wrap.dataset.collapsedByDefault = collapsedChk.checked ? 'true' : 'false';
      wrap.dataset.bgColor = bgInput.value;
      wrap.dataset.headerBgColor = headerBgInput.value;
      wrap.dataset.borderColor = borderInput.value;
      wrap.dataset.textColor = textColInput.value;
      wrap.dataset.borderStyle = borderStyleSel.value;
      wrap.dataset.borderWidth = borderWidthSel.value;

      _applyGroupStyles(wrap);
      window.nbFormBuilder._isDirty = true;
      modal.remove();
    });

    actions.appendChild(cancelBtn);
    actions.appendChild(saveBtn);
    content.appendChild(actions);

    modal.appendChild(content);
    document.body.appendChild(modal);
  }

  function _makeGroupContainer(extra) {
    extra = extra || {};
    var label = extra.label || 'Group';
    var id = 'nb-group-' + Date.now() + '-' + Math.random().toString(36).slice(2,5);
    var wrap = document.createElement('div');
    wrap.className = 'nb-container nb-container-group'; wrap.id = id; wrap.dataset.containerType = 'group';

    wrap.dataset.groupName = extra.name || 'group_' + Date.now();
    wrap.dataset.collapsible = (extra.collapsible === true || extra.collapsible === 'true') ? 'true' : 'false';
    wrap.dataset.collapsedByDefault = (extra.collapsed === true || extra.collapsed === 'true' || extra.collapsed_by_default === true || extra.collapsed_by_default === 'true') ? 'true' : 'false';
    wrap.dataset.bgColor = extra.bg_color || '';
    wrap.dataset.headerBgColor = extra.header_bg_color || '';
    wrap.dataset.borderColor = extra.border_color || '';
    wrap.dataset.textColor = extra.text_color || '';
    wrap.dataset.borderStyle = extra.border_style || '';
    wrap.dataset.borderWidth = extra.border_width || '';

    var header = document.createElement('div'); header.className = 'nb-container-header';
    var dh = document.createElement('span'); dh.className = 'nb-row-drag'; dh.title = 'Drag group'; dh.textContent = '⠇';
    var badge = document.createElement('span'); badge.className = 'nb-container-type-badge'; badge.textContent = 'GROUP';
    var li = document.createElement('input'); li.type = 'text'; li.className = 'nb-container-label-input nu-input'; li.value = label; li.placeholder = 'Group label';
    var addRowBtn = document.createElement('button'); addRowBtn.type = 'button'; addRowBtn.className = 'nb-row-btn'; addRowBtn.textContent = '+ Row';
    addRowBtn.addEventListener('click', function () { _addRowToContainer(wrap, [], false); });

    var settingsBtn = document.createElement('button'); settingsBtn.type = 'button'; settingsBtn.className = 'nb-row-btn'; settingsBtn.textContent = '⚙'; settingsBtn.title = 'Group Settings';
    settingsBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      _openGroupSettingsModal(wrap);
    });

    var delBtn = document.createElement('button'); delBtn.type = 'button'; delBtn.className = 'nb-row-btn del'; delBtn.textContent = '✕';
    delBtn.addEventListener('click', function () {
      if (!confirm("Are you sure you want to delete this group and all its rows?")) return;
      wrap.remove();
      window.nbFormBuilder._updateEmptyState();
      window.nbFormBuilder._isDirty = true;
    });
    header.appendChild(dh); header.appendChild(badge); header.appendChild(li); header.appendChild(addRowBtn); header.appendChild(settingsBtn); header.appendChild(delBtn);
    var body = document.createElement('div'); body.className = 'nb-container-body nb-container-group-body';
    _attachRowContainerDragAndDrop(body);
    var hint = document.createElement('div'); hint.className = 'nb-row-drop-hint'; hint.textContent = 'Click "+ Row" to add a row, then drop fields in';
    body.appendChild(hint); wrap.appendChild(header); wrap.appendChild(body);
    if (extra.rows && extra.rows.length) {
      var eh = body.querySelector('.nb-row-drop-hint'); if (eh) eh.remove();
      extra.rows.forEach(function (rowDef) { _addRowToContainer(body, rowDef.fields || [], true); });
    }
    _wireRowDrag(wrap);
    _applyGroupStyles(wrap);
    return wrap;
  }


  /* ════════════════════════════════════════════════════════════════════
     TAB container
  ═══════════════════════════════════════════════════════════════════ */
  function _makeTabContainer(extra) {
    extra = extra || {};
    var tabs = (extra.tabs && extra.tabs.length) ? extra.tabs : [{ name: 'Tab 1' }];
    var id = 'nb-tab-' + Date.now() + '-' + Math.random().toString(36).slice(2,5);
    var wrap = document.createElement('div');
    wrap.className = 'nb-container nb-container-tab'; wrap.id = id; wrap.dataset.containerType = 'tab';
    var header = document.createElement('div'); header.className = 'nb-container-header';
    var dh = document.createElement('span'); dh.className = 'nb-row-drag'; dh.title = 'Drag tab'; dh.textContent = '⠇';
    var badge = document.createElement('span'); badge.className = 'nb-container-type-badge nb-container-type-badge-tab'; badge.textContent = 'TAB';
    var title = document.createElement('span'); title.style.cssText = 'font-size:11px;color:rgba(255,255,255,.8);flex:1;'; title.textContent = 'Tab Container';
    var del = document.createElement('button'); del.type = 'button'; del.className = 'nb-row-btn del'; del.textContent = '✕';
    del.addEventListener('click', function () {
      if (!confirm("Are you sure you want to delete this tab container and all its tabs?")) return;
      wrap.remove();
      window.nbFormBuilder._updateEmptyState();
      window.nbFormBuilder._isDirty = true;
    });
    header.appendChild(dh); header.appendChild(badge); header.appendChild(title); header.appendChild(del);
    var nav    = document.createElement('div'); nav.className = 'nb-cfield-tab-nav'; nav.id = id + '-nav';
    var panels = document.createElement('div'); panels.className = 'nb-container-tab-panels'; panels.id = id + '-panels';
    wrap.appendChild(header); wrap.appendChild(nav); wrap.appendChild(panels);
    document.body.appendChild(wrap);
    tabs.forEach(function (tab, i) { _addTabPanel(wrap, nav, panels, tab.name || ('Tab ' + (i+1)), i === 0, tab.rows || []); });
    var addTabBtn = document.createElement('button'); addTabBtn.type = 'button'; addTabBtn.className = 'nb-cfield-tab-add-btn'; addTabBtn.textContent = '+ Tab';
    addTabBtn.addEventListener('click', function () {
      var idx = nav.querySelectorAll('.nb-cfield-tab-nav-item').length;
      nav.querySelectorAll('.nb-cfield-tab-nav-item').forEach(function (n) { n.classList.remove('active'); });
      panels.querySelectorAll('.nb-cfield-tab-panel').forEach(function (p) { p.classList.remove('active'); });
      _addTabPanel(wrap, nav, panels, 'Tab ' + (idx + 1), true, []);
      window.nbFormBuilder._isDirty = true;
    });
    nav.appendChild(addTabBtn);
    document.body.removeChild(wrap);
    _wireRowDrag(wrap);
    return wrap;
  }

  function _addTabPanel(container, nav, panels, tabName, isActive, rows) {
    var panelId = 'nb-tabpanel-' + Date.now() + '-' + Math.random().toString(36).slice(2,5);
    var navItem = document.createElement('div');
    navItem.className = 'nb-cfield-tab-nav-item' + (isActive ? ' active' : ''); navItem.dataset.panelTarget = panelId;
    var ni = document.createElement('input'); ni.type = 'text'; ni.className = 'nb-tab-name-input'; ni.value = tabName;
    ni.style.cssText = 'background:none;border:none;outline:none;font:inherit;cursor:pointer;width:' + Math.max(50, tabName.length * 8) + 'px;min-width:40px;';
    ni.addEventListener('input', function () {
      ni.style.width = Math.max(50, ni.value.length * 8) + 'px';
      window.nbFormBuilder._isDirty = true;
    });
    var ds = document.createElement('span'); ds.className = 'nb-tab-nav-del'; ds.style.cssText = 'font-size:10px;cursor:pointer;color:rgba(0,0,0,.4);margin-left:2px;'; ds.title = 'Remove tab'; ds.textContent = '×';
    navItem.appendChild(ni); navItem.appendChild(ds);
    navItem.addEventListener('click', function (e) {
      if (e.target === ds) {
        if (!confirm("Are you sure you want to delete this tab and all its rows?")) return;
        var p = document.getElementById(panelId); if (p) p.remove(); navItem.remove();
        var fn = nav.querySelector('.nb-cfield-tab-nav-item');
        if (fn) { fn.classList.add('active'); var fp = document.getElementById(fn.dataset.panelTarget); if (fp) fp.classList.add('active'); }
        window.nbFormBuilder._isDirty = true;
        return;
      }
      nav.querySelectorAll('.nb-cfield-tab-nav-item').forEach(function (n) { n.classList.remove('active'); });
      panels.querySelectorAll('.nb-cfield-tab-panel').forEach(function (p) { p.classList.remove('active'); });
      navItem.classList.add('active'); var tp = document.getElementById(panelId); if (tp) tp.classList.add('active');
    });
    var addBtn = nav.querySelector('.nb-cfield-tab-add-btn');
    if (addBtn) nav.insertBefore(navItem, addBtn); else nav.appendChild(navItem);
    var panel = document.createElement('div');
    panel.className = 'nb-cfield-tab-panel' + (isActive ? ' active' : ''); panel.id = panelId;
    var toolbar = document.createElement('div');
    toolbar.style.cssText = 'display:flex;align-items:center;gap:6px;justify-content:flex-end;padding:4px 8px 2px;border-bottom:1px solid var(--border,#e0e4ef);';
    var addRowBtn = document.createElement('button'); addRowBtn.type = 'button'; addRowBtn.className = 'nb-row-btn'; addRowBtn.textContent = '+ Row';
    var addGrpBtn = document.createElement('button'); addGrpBtn.type = 'button'; addGrpBtn.className = 'nb-row-btn'; addGrpBtn.textContent = '+ Group';
    toolbar.appendChild(addRowBtn); toolbar.appendChild(addGrpBtn);
    var rowsBody = document.createElement('div'); rowsBody.className = 'nb-tab-panel-rows';
    _attachRowContainerDragAndDrop(rowsBody);
    panel.appendChild(toolbar); panel.appendChild(rowsBody); panels.appendChild(panel);
    addRowBtn.addEventListener('click', function () { _addRowToContainer(rowsBody, [], false); });
    addGrpBtn.addEventListener('click', function () {
      var grp = _makeGroupContainer({});
      var h = rowsBody.querySelector(':scope > .nb-row-drop-hint'); if (h) h.remove();
      rowsBody.appendChild(grp);
      window.nbFormBuilder._isDirty = true;
    });
    if (rows && rows.length) {
      rows.forEach(function (rowDef) {
        if (rowDef.type === 'group') { var h = rowsBody.querySelector(':scope > .nb-row-drop-hint'); if (h) h.remove(); rowsBody.appendChild(_makeGroupContainer(rowDef)); }
        else { _addRowToContainer(rowsBody, rowDef.fields || [], true); }
      });
    } else {
      var eh = document.createElement('div'); eh.className = 'nb-row-drop-hint'; eh.textContent = 'Click "+ Row" or "+ Group" to add content'; rowsBody.appendChild(eh);
    }
    return panel;
  }


  /* ════════════════════════════════════════════════════════════════════
     _addRowToContainer
  ═══════════════════════════════════════════════════════════════════ */
  function _addRowToContainer(target, fields, isRestore) {
    var rowsWrap = target;
    if (target) {
      if (target.classList.contains('nb-cfield-tab-panel')) rowsWrap = target.querySelector('.nb-tab-panel-rows');
      else if (target.classList.contains('nb-container'))   rowsWrap = target.querySelector('.nb-container-group-body');
    }
    if (!rowsWrap) return null;
    var hint = rowsWrap.querySelector(':scope > .nb-row-drop-hint'); if (hint) hint.remove();
    var rowId = 'nb-row-' + Date.now() + '-' + Math.random().toString(36).slice(2,5);
    var row = document.createElement('div'); row.className = 'nb-row nb-inner-row'; row.id = rowId;
    var rh = document.createElement('div'); rh.className = 'nb-row-header';
    var rd = document.createElement('span'); rd.className = 'nb-row-drag'; rd.title = 'Drag row'; rd.textContent = '⠇';
    var rl = document.createElement('span'); rl.className = 'nb-row-label'; rl.textContent = 'Row';
    var ra = document.createElement('span'); ra.className = 'nb-row-actions';
    var colBtns = document.createElement('span');
    colBtns.className = 'nb-row-cols-bar';
    colBtns.style.cssText = 'display:inline-flex;gap:3px;margin-right:6px;';
    [1, 2, 3, 4].forEach(function(n) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'nb-row-btn nb-row-cols-btn';
      b.dataset.cols = String(n);
      b.textContent = n === 1 ? '1 Col' : n + ' Cols';
      b.title = 'Set fields in this row to ' + n + ' column' + (n > 1 ? 's' : '');
      b.addEventListener('click', function(e) {
        e.stopPropagation();
        window.nbFormBuilder.setRowColumns(row, n);
      });
      colBtns.appendChild(b);
    });
    ra.appendChild(colBtns);

    var alignBtns = document.createElement('span');
    alignBtns.className = 'nb-row-align-bar';
    alignBtns.style.cssText = 'display:inline-flex;gap:3px;margin-right:6px;border-left:1px solid var(--border-color);padding-left:6px;';
    [
      { code: 'left', label: '⇠ Left', title: 'Align elements to left' },
      { code: 'center', label: '↔ Center', title: 'Align elements to center' },
      { code: 'right', label: '⇢ Right', title: 'Align elements to right' }
    ].forEach(function(a) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'nb-row-btn nb-row-align-btn';
      b.dataset.align = a.code;
      b.textContent = a.label;
      b.title = a.title;
      b.addEventListener('click', function(e) {
        e.stopPropagation();
        window.nbFormBuilder.setRowAlignment(row, a.code);
      });
      alignBtns.appendChild(b);
    });
    ra.appendChild(alignBtns);

    var db = document.createElement('button'); db.className = 'nb-row-btn del'; db.type = 'button'; db.textContent = '✕';
    db.addEventListener('click', function () {
      if (!confirm("Are you sure you want to delete this row and all its fields?")) return;
      var parent = row.parentNode; row.remove();
      if (parent && !parent.querySelector('.nb-row')) { var h = document.createElement('div'); h.className = 'nb-row-drop-hint'; h.textContent = 'Click "+ Row" to add a row, then drop fields in'; parent.appendChild(h); }
      window.nbFormBuilder._updateEmptyState();
      window.nbFormBuilder._isDirty = true;
    });
    ra.appendChild(db); rh.appendChild(rd); rh.appendChild(rl); rh.appendChild(ra);
    var rb = document.createElement('div'); rb.className = 'nb-row-body';
    var dh = document.createElement('div'); dh.className = 'nb-row-drop-hint'; dh.textContent = 'Drop fields here'; rb.appendChild(dh);
    row.appendChild(rh); row.appendChild(rb); rowsWrap.appendChild(row);
    _wireRowDrag(row); _attachRowBodyDrop(rb);
    if (!isRestore) {
      window.nbFormBuilder._isDirty = true;
    }
    if (fields && fields.length) {
fields.forEach(function (f) {
  var fType  = f.type  || 'text';
  var fLabel = f.label || f.fieldlabel || f.field_label || f.title || f.name || '';
  var fName  = f.name  || f.fieldname  || f.field_name  || f.column_name || '';
  var fPh    = f.placeholder || f.field_placeholder || f.fieldplaceholder || '';
  var fDef   = f.default_value || f.defaultvalue || f.default || '';
  var fHelp  = f.help_text || f.field_help_text || f.helptext || '';
  var fReq   = !!f.required;

  var card = window.nbFormBuilder._makeFieldCard(fType, fLabel, fName, fReq, f);
  if (!card) return;

  /* Safety: force dataset after make in case _makeFieldCard received stale/empty values */
  if (fLabel) card.dataset.fieldLabel   = fLabel;
  if (fName)  card.dataset.fieldName    = fName;
  if (fPh)    card.dataset.fieldPh      = fPh;
  if (fDef)   card.dataset.fieldDefault = fDef;
  if (fHelp)  card.dataset.fieldHelp    = fHelp;

  /* Also backfill the hidden body inputs so getAttribute() returns the right value */
  var bodyLabel = card.querySelector('.nu-field-label');
  var bodyName  = card.querySelector('.nu-field-name');
  var bodyPh    = card.querySelector('.nu-field-placeholder');
  var bodyDef   = card.querySelector('.nu-field-default');
  var bodyHelp  = card.querySelector('.nu-field-help');
  if (bodyLabel) { bodyLabel.value = fLabel; bodyLabel.setAttribute('value', fLabel); }
  if (bodyName)  { bodyName.value  = fName;  bodyName.setAttribute('value', fName); }
  if (bodyPh)    { bodyPh.value    = fPh;    bodyPh.setAttribute('value', fPh); }
  if (bodyDef)   { bodyDef.value   = fDef;   bodyDef.setAttribute('value', fDef); }
  if (bodyHelp)  { bodyHelp.value  = fHelp;  bodyHelp.setAttribute('value', fHelp); }

  /* Update visible header label */
  var hdr = card.querySelector('.nb-cfield-label');
  if (hdr && fLabel) hdr.textContent = fLabel;

  var d = rb.querySelector('.nb-row-drop-hint'); if (d) d.remove();
  _prepCard(card); rb.appendChild(card);
  window.nbFormBuilder._applyColSpan(card, parseInt(f.col, 10) || 6);
  _restoreFieldState(card, f);
  if ((f.type === 'subform' || card.dataset.type === 'subform') && f.subform) {
    var sfObj = (f.subform && typeof f.subform === 'object') ? f.subform : {};
    var sfInst = {
      form_code:            sfObj.form_code            || f.sf_form_code || '',
      fk_field:             sfObj.fk_field             || f.sf_fk_field  || '',
      subform_view:         f.subform_view             || sfObj.subform_view || 'grid',
      help_text:            f.help_text                || f.field_help_text || '',
      is_fk:                sfObj.is_fk !== undefined ? !!sfObj.is_fk : !!f.is_fk,
      hide_in_grid:         sfObj.hide_in_grid !== undefined ? !!sfObj.hide_in_grid : !!f.hide_in_grid,
      server_readonly:      sfObj.server_readonly !== undefined ? !!sfObj.server_readonly : !!f.server_readonly,
      searchable:           sfObj.searchable !== undefined ? !!sfObj.searchable : (f.searchable !== undefined ? !!f.searchable : true),
      selected_fields_only: sfObj.selected_fields_only !== undefined ? !!sfObj.selected_fields_only : !!f.selected_fields_only,
      view_roles:           sfObj.view_roles || f.view_roles || '',
      edit_roles:           sfObj.edit_roles || f.edit_roles || ''
    };
    _nbSfData.write(card, sfInst);
    _attachSubformPanelEvents(card, sfInst);
  }
});
    }
    return row;
  }

  /* ── _prepCard ── */
  function _prepCard(card) {
    if (card._nbCardPrepped) return;
    card._nbCardPrepped = true;
    if (!card.id) card.id = 'nb-card-' + Date.now() + '-' + Math.random().toString(36).slice(2,5);
    card.setAttribute('draggable', 'true');
    card.addEventListener('dragstart', function (ev) {
      if (ev.target.classList.contains('nb-cfield-resize')) { ev.preventDefault(); return; }
      ev.stopPropagation();
      ev.dataTransfer.setData('text/nb-card-id', card.id); card.classList.add('drag-source');
    });
    card.addEventListener('dragend', function () { card.classList.remove('drag-source'); });
    card.addEventListener('click', function (e) {
      if (e.target.closest('.nb-cfield-actions')) return;
      if (e.target.classList.contains('nb-cfield-resize')) return;

      if (e.shiftKey || e.ctrlKey || e.metaKey) {
        e.preventDefault();
        var idx = window.nbSelectedCards.indexOf(card);
        if (idx !== -1) {
          window.nbSelectedCards.splice(idx, 1);
          card.classList.remove('nb-cfield-selected-multi');
        } else {
          window.nbSelectedCards.push(card);
          card.classList.add('nb-cfield-selected-multi');
        }
        if (window.nbUpdateMultiSelectBar) window.nbUpdateMultiSelectBar();
      } else {
        if (window.nbClearMultiSelect) window.nbClearMultiSelect();
        _openPropsPanel(card);
      }
    });
    _attachResizeHandle(card);
  }

  function _restoreFieldState(card, f) {
  if (!card || !f) return;

  var fd = (f && typeof f.fields === 'object' && f.fields) ? f.fields : f;
  var type = card.dataset.type || '';

  if (type === 'select' || type === 'select2') {
    var sm = card.querySelector('.nu-field-select-mode');
    if (sm) {
      var isM = fd.multiple === true || fd.multiple === 'true' || fd.multiple === 1 || fd.select_type === 'multiselect';
      sm.value = isM ? 'multi' : 'single';
    }
  }

  var map = {
    '.nu-field-required': 'required',
    '.nu-field-no-duplicate': 'no_duplicate',
    '.nu-field-readonly': 'readonly',
    '.nu-field-hidden': 'hidden',
    '.nu-field-hidden-normal': 'hidden_for_normal_users',
    '.nu-field-searchable': 'searchable',
    '.nu-field-hide-in-grid': 'hide_in_grid'
  };

  Object.keys(map).forEach(function (sel) {
    var el = card.querySelector(sel);
    if (el) {
      if (sel === '.nu-field-searchable') {
        el.checked = fd.searchable !== undefined ? !!fd.searchable : true;
      } else {
        el.checked = !!fd[map[sel]];
      }
    }
  });

  var customAttrs = card.querySelector('.nu-field-custom-attrs');
  if (customAttrs) {
    var caVal = fd.custom_attributes || '';
    customAttrs.value = caVal;
    customAttrs.setAttribute('value', caVal);
    card.dataset.fieldCustomAttrs = caVal;
  }

  if (type === 'upload') {
    var exts = card.querySelector('.nu-upload-exts');
    if (exts) { exts.value = fd.allowed_extensions || ''; exts.setAttribute('value', exts.value); }
    var size = card.querySelector('.nu-upload-maxsize');
    if (size) { size.value = fd.max_size_mb || ''; size.setAttribute('value', size.value); }
  }

  if (type === 'photo_canvas') {
    var w = card.querySelector('.nu-canvas-width');
    if (w) { w.value = fd.canvas_width || ''; w.setAttribute('value', w.value); }
    var h = card.querySelector('.nu-canvas-height');
    if (h) { h.value = fd.canvas_height || ''; h.setAttribute('value', h.value); }
  }

  if (type === 'lookup') {
    var lk = (fd.lookup && typeof fd.lookup === 'object') ? fd.lookup : {};
    var tbl = card.querySelector('.nu-lookup-table');
    if (tbl) { tbl.value = lk.table || fd.lookup_form || ''; tbl.setAttribute('value', tbl.value); }
    var dsp = card.querySelector('.nu-lookup-display');
    if (dsp) { dsp.value = lk.display_column || fd.lookup_display || ''; dsp.setAttribute('value', dsp.value); }
    var store = card.querySelector('.nu-lookup-store');
    if (store) { store.value = lk.id_column || fd.lookup_store || ''; store.setAttribute('value', store.value); }
    var addf = card.querySelector('.nu-lookup-additional-fields');
    if (addf) { addf.value = lk.additional_fields || ''; addf.setAttribute('value', addf.value); }
    var flt = card.querySelector('.nu-lookup-filter');
    if (flt) { flt.value = lk.filter || fd.lookup_filter || ''; flt.setAttribute('value', flt.value); }
    var ext = card.querySelector('.nu-lookup-extra');
    if (ext) { ext.value = lk.extra || fd.lookup_extra || ''; ext.setAttribute('value', ext.value); }
  }

  if (type === 'autonumber') {
    var seq = card.querySelector('.nu-input[data-prop="sequence_code"]');
    if (seq) { seq.value = fd.sequence_code || ''; seq.setAttribute('value', seq.value); }
    var pref = card.querySelector('.nu-input[data-prop="prefix_pattern"]');
    if (pref) { pref.value = fd.prefix_pattern || ''; pref.setAttribute('value', pref.value); }
    var suff = card.querySelector('.nu-input[data-prop="suffix_pattern"]');
    if (suff) { suff.value = fd.suffix_pattern || ''; suff.setAttribute('value', suff.value); }
    var pad = card.querySelector('.nu-input[data-prop="padding_length"]');
    if (pad) { pad.value = fd.padding_length != null ? fd.padding_length : 6; pad.setAttribute('value', pad.value); }
    var mapText = card.querySelector('textarea[data-prop="prefix_map"]');
    if (mapText) { mapText.value = fd.prefix_map || ''; mapText.textContent = mapText.value; }
  }
}
    /* ════════════════════════════════════════════════════════════════════
     nbFormBuilder public API
  ═══════════════════════════════════════════════════════════════════ */
  window.nbFormBuilder = {
    _isDirty: false,
    _currentZoom: 1.0,
    _currentDevice: 'desktop',
    _currentViewMode: 'grid',

    setViewportDevice: function (device) {
      var canvas = document.getElementById('formCanvas');
      if (!canvas) return;
      this._currentDevice = device || 'desktop';
      canvas.classList.remove('nb-viewport-desktop', 'nb-viewport-tablet', 'nb-viewport-mobile');
      canvas.classList.add('nb-viewport-' + this._currentDevice);

      var btnD = document.getElementById('nbDeviceDesktop');
      var btnT = document.getElementById('nbDeviceTablet');
      var btnM = document.getElementById('nbDeviceMobile');

      var activeClass = ['bg-white', 'dark:bg-slate-700', 'shadow-sm', 'text-slate-800', 'dark:text-slate-100'];
      var inactiveClass = ['text-slate-500', 'hover:text-slate-800', 'dark:hover:text-slate-200'];

      [btnD, btnT, btnM].forEach(function (btn) {
        if (!btn) return;
        btn.classList.remove('bg-white', 'dark:bg-slate-700', 'shadow-sm', 'text-slate-800', 'dark:text-slate-100', 'text-slate-500', 'hover:text-slate-800', 'dark:hover:text-slate-200');
      });

      if (btnD) btnD.classList.add.apply(btnD.classList, device === 'desktop' ? activeClass : inactiveClass);
      if (btnT) btnT.classList.add.apply(btnT.classList, device === 'tablet' ? activeClass : inactiveClass);
      if (btnM) btnM.classList.add.apply(btnM.classList, device === 'mobile' ? activeClass : inactiveClass);

      try { localStorage.setItem('nu_form_builder_device', this._currentDevice); } catch (e) {}
    },

    setCanvasZoom: function (zoomLevel) {
      var canvas = document.getElementById('formCanvas');
      if (!canvas) return;
      var z = Math.min(Math.max(parseFloat(zoomLevel) || 1.0, 0.4), 1.6);
      this._currentZoom = Math.round(z * 10) / 10;
      canvas.style.transform = 'scale(' + this._currentZoom + ')';
      canvas.style.transformOrigin = 'top center';
      var label = document.getElementById('nbZoomLabel');
      if (label) label.textContent = Math.round(this._currentZoom * 100) + '%';
    },

    zoomCanvas: function (delta) {
      this.setCanvasZoom(this._currentZoom + delta);
    },

    setLayoutViewMode: function (mode) {
      this._currentViewMode = mode || 'grid';
      var canvas = document.getElementById('formCanvas');
      var jsonContainer = document.getElementById('nbJsonViewContainer');
      var jsonEditor = document.getElementById('nbJsonSchemaEditor');
      var btnG = document.getElementById('nbViewModeGrid');
      var btnP = document.getElementById('nbViewModePreview');
      var btnJ = document.getElementById('nbViewModeJson');

      var activeClass = ['bg-white', 'dark:bg-slate-700', 'shadow-sm', 'text-slate-800', 'dark:text-slate-100'];
      var inactiveClass = ['text-slate-500', 'hover:text-slate-800', 'dark:hover:text-slate-200'];

      [btnG, btnP, btnJ].forEach(function (btn) {
        if (!btn) return;
        btn.classList.remove('bg-white', 'dark:bg-slate-700', 'shadow-sm', 'text-slate-800', 'dark:text-slate-100', 'text-slate-500', 'hover:text-slate-800', 'dark:hover:text-slate-200');
      });

      if (btnG) btnG.classList.add.apply(btnG.classList, mode === 'grid' ? activeClass : inactiveClass);
      if (btnP) btnP.classList.add.apply(btnP.classList, mode === 'preview' ? activeClass : inactiveClass);
      if (btnJ) btnJ.classList.add.apply(btnJ.classList, mode === 'json' ? activeClass : inactiveClass);

      if (mode === 'json') {
        if (canvas) canvas.classList.add('hidden');
        if (jsonContainer) jsonContainer.classList.remove('hidden');
        if (jsonEditor) {
          try {
            var layout = this.getLayout();
            jsonEditor.value = JSON.stringify(layout, null, 2);
          } catch (e) {
            jsonEditor.value = '[]';
          }
        }
      } else if (mode === 'preview') {
        if (jsonContainer) jsonContainer.classList.add('hidden');
        if (canvas) canvas.classList.remove('hidden');
        if (window.nbToggleLivePreviewSplit) {
          var pane = document.getElementById('nbLivePreviewPane');
          if (pane && pane.classList.contains('hidden')) {
            window.nbToggleLivePreviewSplit();
          }
        }
      } else {
        // Default Grid Canvas view
        if (jsonContainer) jsonContainer.classList.add('hidden');
        if (canvas) canvas.classList.remove('hidden');
      }
    },

    formatJsonSchema: function () {
      var jsonEditor = document.getElementById('nbJsonSchemaEditor');
      if (!jsonEditor) return;
      try {
        var parsed = JSON.parse(jsonEditor.value);
        jsonEditor.value = JSON.stringify(parsed, null, 2);
        if (typeof NuApp !== 'undefined' && NuApp.toast) NuApp.toast('JSON schema formatted', 'success');
      } catch (err) {
        if (typeof NuApp !== 'undefined' && NuApp.toast) NuApp.toast('Invalid JSON schema: ' + err.message, 'error');
      }
    },

    applyJsonSchema: function () {
      var jsonEditor = document.getElementById('nbJsonSchemaEditor');
      if (!jsonEditor) return;
      try {
        var parsed = JSON.parse(jsonEditor.value);
        if (!Array.isArray(parsed)) {
          throw new Error('Layout JSON schema must be an array of field objects');
        }
        if (window.nbHistory) window.nbHistory.saveState();
        if (window.nbHistory && window.nbHistory._applyState) {
          window.nbHistory._applyState(JSON.stringify(parsed));
        }
        if (typeof NuApp !== 'undefined' && NuApp.toast) NuApp.toast('JSON layout applied to canvas', 'success');
        this.setLayoutViewMode('grid');
      } catch (err) {
        if (typeof NuApp !== 'undefined' && NuApp.toast) NuApp.toast('Cannot apply JSON: ' + err.message, 'error');
      }
    },

    selectRow: function (rowEl) {
      document.querySelectorAll('#formCanvas .nb-row').forEach(function (r) {
        r.classList.remove('nb-row-selected');
      });
      if (rowEl) {
        rowEl.classList.add('nb-row-selected');
      }
    },

    setRowAlignment: function (rowEl, align) {
      if (!rowEl) return;
      align = align || 'left';
      if (['left', 'center', 'right'].indexOf(align) === -1) align = 'left';

      this.selectRow(rowEl);
      rowEl.dataset.align = align;
      var rb = rowEl.querySelector('.nb-row-body');
      if (rb) {
        rb.dataset.align = align;
      }

      var cards = rowEl.querySelectorAll('.nb-cfield');
      cards.forEach(function (card) {
        card.dataset.rowAlign = align;
        var span = parseInt(card.dataset.col, 10) || 6;
        if (align === 'right') {
          card.style.gridColumnStart = String(12 - span + 1);
        } else if (align === 'center') {
          card.style.gridColumnStart = String(Math.floor((12 - span) / 2) + 1);
        } else {
          card.style.gridColumnStart = '';
        }
      });

      var alignBtns = rowEl.querySelectorAll('.nb-row-align-btn');
      alignBtns.forEach(function (btn) {
        if (btn.dataset.align === align) {
          btn.classList.add('active');
          btn.style.color = 'var(--color-primary,#4f6bed)';
          btn.style.fontWeight = '700';
          btn.style.borderColor = 'var(--color-primary,#4f6bed)';
        } else {
          btn.classList.remove('active');
          btn.style.color = '';
          btn.style.fontWeight = '';
          btn.style.borderColor = '';
        }
      });

      this._isDirty = true;
      if (window.nbHistory) window.nbHistory.saveState();
      if (window.nbUpdateLivePreview) window.nbUpdateLivePreview();
      if (typeof NuApp !== 'undefined' && NuApp.toast) NuApp.toast('Row element alignment set to ' + align, 'success');
    },

    setRowColumns: function (rowEl, cols) {
      if (!rowEl) return;
      cols = parseInt(cols, 10) || 2;
      cols = Math.min(Math.max(cols, 1), 4);
      var span = Math.floor(12 / cols);

      this.selectRow(rowEl);
      var rb = rowEl.querySelector('.nb-row-body');
      if (!rb) return;

      if (window.nbHistory) window.nbHistory.saveState();

      var me = this;
      var cards = rb.querySelectorAll(':scope > .nb-cfield');
      cards.forEach(function (card) {
        if (me._applyColSpan) {
          me._applyColSpan(card, span);
        } else if (window.nbFormBuilder && window.nbFormBuilder._applyColSpan) {
          window.nbFormBuilder._applyColSpan(card, span);
        }
      });

      var colBtns = rowEl.querySelectorAll('.nb-row-cols-btn');
      colBtns.forEach(function (btn) {
        if (parseInt(btn.dataset.cols, 10) === cols) {
          btn.classList.add('active');
          btn.style.color = 'var(--color-primary,#4f6bed)';
          btn.style.fontWeight = '700';
          btn.style.borderColor = 'var(--color-primary,#4f6bed)';
        } else {
          btn.classList.remove('active');
          btn.style.color = '';
          btn.style.fontWeight = '';
          btn.style.borderColor = '';
        }
      });

      this._isDirty = true;
      if (window.nbUpdateLivePreview) window.nbUpdateLivePreview();
      if (typeof NuApp !== 'undefined' && NuApp.toast) NuApp.toast('Set row to ' + cols + ' column' + (cols > 1 ? 's' : ''), 'success');
    },

    autoArrange: function (cols) {
      cols = parseInt(cols, 10) || 2;
      cols = Math.min(Math.max(cols, 1), 4);
      var colSpan = Math.floor(12 / cols);

      var currentLayout = this.getLayout();
      if (!Array.isArray(currentLayout) || currentLayout.length === 0) {
        if (typeof NuApp !== 'undefined' && NuApp.toast) NuApp.toast('No fields to arrange', 'info');
        return;
      }

      if (window.nbHistory) window.nbHistory.saveState();

      var styleNodes = [];
      var fieldsToArrange = [];

      currentLayout.forEach(function (node) {
        if (node && node.type === '_form_style') {
          styleNodes.push(node);
        } else {
          fieldsToArrange.push(node);
        }
      });

      var newLayout = [];
      var currentRowIndex = 0;
      var currentColSum = 0;

      fieldsToArrange.forEach(function (field) {
        if (field.type === 'group' || field.type === 'tab' || field.type === 'divider') {
          // Containers and dividers occupy full row
          field.col = 12;
          if (currentColSum > 0) {
            currentRowIndex++;
            currentColSum = 0;
          }
          field.row_index = currentRowIndex;
          currentRowIndex++;
        } else {
          field.col = colSpan;
          if (currentColSum + colSpan > 12) {
            currentRowIndex++;
            currentColSum = 0;
          }
          field.row_index = currentRowIndex;
          currentColSum += colSpan;
        }
        newLayout.push(field);
      });

      styleNodes.forEach(function (s) { newLayout.push(s); });

      if (window.nbHistory && window.nbHistory._applyState) {
        window.nbHistory._applyState(JSON.stringify(newLayout));
      }

      this._isDirty = true;
      if (typeof NuApp !== 'undefined' && NuApp.toast) NuApp.toast('Auto-arranged layout to ' + cols + ' column grid', 'success');
    },

    /* ── FIX-J: edit method (was missing) ── */
    edit: function (formId) {
      var me = this;
      var card = document.getElementById('formBuilderCard');
      var list = document.getElementById('formsListSection');
      if (card) card.style.display = 'block';
      if (list) list.style.display = 'none';
      _closePropsPanel();
      me._clearForm();
      NuApp.apiJson('api/forms.php?action=get&id=' + encodeURIComponent(formId), { credentials: 'same-origin' })
        .then(function (res) {
          if (!res || !res.success || !res.form) { NuApp.toast((res && res.error) || 'Could not load form', 'error'); return; }
          me.loadForm(res.form);
          me._isDirty = false;
        })
        .catch(function (err) { NuApp.toast('Load error: ' + err.message, 'error'); });
    },

    _currentStyle: null,

    openStyleModal: function () {
      var me = this;
      var existing = document.getElementById('nbFormStyleModal');
      if (existing) existing.remove();

      var st = me._currentStyle || { density: 'comfortable', card_style: 'default', label_align: 'top', header_bg: '', accent_color: '', border_radius: '' };

      var modal = document.createElement('div');
      modal.id = 'nbFormStyleModal';
      modal.style.cssText = 'position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;padding:16px;';
      modal.innerHTML = '<div style="background:var(--bg-card,#fff);border-radius:12px;max-width:500px;width:100%;padding:24px;box-shadow:0 10px 25px rgba(0,0,0,0.2);">' +
        '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">' +
          '<h3 style="margin:0;font-size:16px;font-weight:700;">🎨 Form Style & Customization</h3>' +
          '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" onclick="document.getElementById(\'nbFormStyleModal\').remove()">&times;</button>' +
        '</div>' +
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">' +
          '<div>' +
            '<label class="nu-label" style="font-size:12px;">Density</label>' +
            '<select id="nbStDensity" class="nu-input nu-input-sm">' +
              '<option value="compact"' + (st.density === 'compact' ? ' selected' : '') + '>Compact</option>' +
              '<option value="comfortable"' + (st.density === 'comfortable' ? ' selected' : '') + '>Comfortable</option>' +
              '<option value="relaxed"' + (st.density === 'relaxed' ? ' selected' : '') + '>Relaxed</option>' +
            '</select>' +
          '</div>' +
          '<div>' +
            '<label class="nu-label" style="font-size:12px;">Card Style</label>' +
            '<select id="nbStCardStyle" class="nu-input nu-input-sm">' +
              '<option value="default"' + (st.card_style === 'default' ? ' selected' : '') + '>Default Card</option>' +
              '<option value="elevated"' + (st.card_style === 'elevated' ? ' selected' : '') + '>Elevated Shadow</option>' +
              '<option value="outlined"' + (st.card_style === 'outlined' ? ' selected' : '') + '>Outlined Border</option>' +
              '<option value="flush"' + (st.card_style === 'flush' ? ' selected' : '') + '>Flush (No Card)</option>' +
            '</select>' +
          '</div>' +
        '</div>' +
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">' +
          '<div>' +
            '<label class="nu-label" style="font-size:12px;">Label Alignment</label>' +
            '<select id="nbStLabelAlign" class="nu-input nu-input-sm">' +
              '<option value="top"' + (st.label_align === 'top' ? ' selected' : '') + '>Top Stacked</option>' +
              '<option value="left"' + (st.label_align === 'left' ? ' selected' : '') + '>Left Aligned</option>' +
              '<option value="inline"' + (st.label_align === 'inline' ? ' selected' : '') + '>Inline Floating</option>' +
            '</select>' +
          '</div>' +
          '<div>' +
            '<label class="nu-label" style="font-size:12px;">Corner Radius</label>' +
            '<input type="text" id="nbStBorderRadius" class="nu-input nu-input-sm" value="' + (st.border_radius || '') + '" placeholder="e.g. 8px, 0.5rem">' +
          '</div>' +
        '</div>' +
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">' +
          '<div>' +
            '<label class="nu-label" style="font-size:12px;">Header Background</label>' +
            '<input type="color" id="nbStHeaderBg" class="nu-input nu-input-sm" style="height:34px;padding:2px;" value="' + (st.header_bg || '#f8fafc') + '">' +
          '</div>' +
          '<div>' +
            '<label class="nu-label" style="font-size:12px;">Accent Color</label>' +
            '<input type="color" id="nbStAccentColor" class="nu-input nu-input-sm" style="height:34px;padding:2px;" value="' + (st.accent_color || '#0ea5e9') + '">' +
          '</div>' +
        '</div>' +
        '<div style="display:flex;justify-content:flex-end;gap:8px;">' +
          '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" onclick="document.getElementById(\'nbFormStyleModal\').remove()">Cancel</button>' +
          '<button type="button" class="nu-btn nu-btn-primary nu-btn-sm" onclick="nbFormBuilder.saveStyleFromModal()">Apply Style</button>' +
        '</div>' +
      '</div>';
      document.body.appendChild(modal);
    },

    saveStyleFromModal: function () {
      var d = document.getElementById('nbStDensity');
      var c = document.getElementById('nbStCardStyle');
      var l = document.getElementById('nbStLabelAlign');
      var h = document.getElementById('nbStHeaderBg');
      var a = document.getElementById('nbStAccentColor');
      var r = document.getElementById('nbStBorderRadius');

      this._currentStyle = {
        density: d ? d.value : 'comfortable',
        card_style: c ? c.value : 'default',
        label_align: l ? l.value : 'top',
        header_bg: h ? h.value : '',
        accent_color: a ? a.value : '',
        border_radius: r ? r.value.trim() : ''
      };

      var modal = document.getElementById('nbFormStyleModal');
      if (modal) modal.remove();
      if (window.NuApp) NuApp.toast('Form style updated! Save form layout to persist changes.');
    },

    toggleFullscreen: function () {
      var card = document.getElementById('formBuilderCard');
      var btn = document.getElementById('btnBuilderFullscreen');
      if (!card) return;
      var isFs = card.classList.toggle('nb-builder-fullscreen');
      if (btn) {
        btn.textContent = isFs ? '❐ Exit Fullscreen' : '⛶ Fullscreen';
      }
      if (isFs) {
        document.body.style.overflow = 'hidden';
      } else {
        document.body.style.overflow = '';
      }
    },

    toggleGridLines: function (forceState) {
      var canvas = document.getElementById('formCanvas');
      var btn = document.getElementById('nbToggleGridLines');
      var show = forceState !== undefined ? forceState : !document.body.classList.contains('nb-show-gridlines');
      if (show) {
        document.body.classList.add('nb-show-gridlines');
        if (canvas) canvas.classList.add('nb-show-gridlines');
        if (btn) {
          btn.classList.add('nu-btn-primary');
          btn.classList.remove('nu-btn-ghost');
        }
        localStorage.setItem('nu_form_builder_gridlines', 'true');
      } else {
        document.body.classList.remove('nb-show-gridlines');
        if (canvas) canvas.classList.remove('nb-show-gridlines');
        if (btn) {
          btn.classList.remove('nu-btn-primary');
          btn.classList.add('nu-btn-ghost');
        }
        localStorage.setItem('nu_form_builder_gridlines', 'false');
      }
    },

    initGridLines: function () {
      var saved = localStorage.getItem('nu_form_builder_gridlines');
      var enabled = saved === 'true';
      this.toggleGridLines(enabled);
    },

    showCustomJoinsHelp: function () {
      var overlay = document.createElement('div');
      overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(15,23,42,0.65);z-index:999999;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(4px);';

      var modal = document.createElement('div');
      modal.className = 'bg-white rounded-xl shadow-2xl p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto flex flex-col gap-4 text-slate-800';
      modal.style.fontFamily = 'system-ui, -apple-system, sans-serif';

      var header = document.createElement('div');
      header.className = 'flex justify-between items-center border-b pb-3 border-slate-100';
      header.innerHTML = '<h3 class="text-lg font-bold text-slate-900 flex items-center gap-2">💡 Custom Joins Quick Reference</h3>' +
        '<button type="button" class="text-slate-400 hover:text-slate-600 text-2xl font-semibold leading-none">&times;</button>';

      header.querySelector('button').onclick = function () { overlay.remove(); };

      var body = document.createElement('div');
      body.className = 'flex flex-col gap-5 text-sm leading-relaxed';

      body.innerHTML =
        '<div>' +
          '<p class="mb-3 text-slate-600">To display descriptive child values instead of raw foreign key IDs in your browse grid, you can use either of the following two methods:</p>' +
        '</div>' +

        '<div class="border-l-4 border-amber-500 bg-amber-50 p-3 rounded-r-md">' +
          '<h4 class="font-bold text-amber-900 mb-1">Your SQL Query:</h4>' +
          '<pre class="text-xs font-mono overflow-x-auto text-amber-800 bg-amber-100/50 p-2 rounded mt-1">' +
            'SELECT * FROM pbrder_bapp\n' +
            'LEFT JOIN agent ON agent.ag_id = pbrder_bapp.p_agent\n' +
            'LEFT JOIN station ON station.station_id = pbrder_bapp.p_location\n' +
            'LEFT JOIN zzzzsys_user ON zzzzsys_user.zzzzsys_user_id = pbrder_bapp.p_officer\n' +
            'LEFT JOIN service_type ON service_type.sev_id = pbrder_bapp.p_servicetype' +
          '</pre>' +
        '</div>' +

        '<div class="flex flex-col gap-2">' +
          '<h4 class="font-bold text-slate-900 text-base">Method A: Using "Browse PHP" (Global SQL Override)</h4>' +
          '<p class="text-slate-600">Paste the following code into your <strong>Browse PHP</strong> editor. The system automatically detects any alias ending with <code>_display</code> and uses it in the grid column instead of the ID:</p>' +
          '<pre class="text-xs font-mono bg-slate-900 text-slate-100 p-3 rounded-lg overflow-x-auto leading-normal">' +
            '<' + '?php\n' +
            '$nuSql = "\n' +
            '    SELECT \n' +
            '        pbrder_bapp.*,\n' +
            '        agent.ag_name                  AS p_agent_display,\n' +
            '        station.station_name           AS p_location_display,\n' +
            '        zzzzsys_user.username          AS p_officer_display,\n' +
            '        service_type.sev_name          AS p_servicetype_display\n' +
            '    FROM pbrder_bapp\n' +
            '    LEFT JOIN agent ON agent.ag_id = pbrder_bapp.p_agent\n' +
            '    LEFT JOIN station ON station.station_id = pbrder_bapp.p_location\n' +
            '    LEFT JOIN zzzzsys_user ON zzzzsys_user.zzzzsys_user_id = pbrder_bapp.p_officer\n' +
            '    LEFT JOIN service_type ON service_type.sev_id = pbrder_bapp.p_servicetype\n' +
            '";' +
          '</pre>' +
        '</div>' +

        '<div class="flex flex-col gap-2">' +
          '<h4 class="font-bold text-slate-900 text-base">Method B: Field-Level Join Settings (No Code)</h4>' +
          '<p class="text-slate-600">You can also configure individual fields. Select each field on your builder canvas and set its properties on the right panel:</p>' +
          '<div class="overflow-x-auto border border-slate-100 rounded-lg">' +
            '<table class="min-w-full text-xs text-left text-slate-500">' +
              '<thead class="text-slate-700 bg-slate-50 uppercase font-semibold">' +
                '<tr>' +
                  '<th class="px-3 py-2">Field ID</th>' +
                  '<th class="px-3 py-2">Join SQL</th>' +
                  '<th class="px-3 py-2">Join Display Field</th>' +
                '</tr>' +
              '</thead>' +
              '<tbody class="divide-y divide-slate-100">' +
                '<tr>' +
                  '<td class="px-3 py-2 font-mono text-slate-900">p_agent</td>' +
                  '<td class="px-3 py-2 font-mono">LEFT JOIN agent ON agent.ag_id = pbrder_bapp.p_agent</td>' +
                  '<td class="px-3 py-2 font-mono text-indigo-600">agent.ag_name</td>' +
                '</tr>' +
                '<tr>' +
                  '<td class="px-3 py-2 font-mono text-slate-900">p_location</td>' +
                  '<td class="px-3 py-2 font-mono">LEFT JOIN station ON station.station_id = pbrder_bapp.p_location</td>' +
                  '<td class="px-3 py-2 font-mono text-indigo-600">station.station_name</td>' +
                '</tr>' +
                '<tr>' +
                  '<td class="px-3 py-2 font-mono text-slate-900">p_officer</td>' +
                  '<td class="px-3 py-2 font-mono">LEFT JOIN zzzzsys_user ON zzzzsys_user.zzzzsys_user_id = pbrder_bapp.p_officer</td>' +
                  '<td class="px-3 py-2 font-mono text-indigo-600">zzzzsys_user.username</td>' +
                '</tr>' +
                '<tr>' +
                  '<td class="px-3 py-2 font-mono text-slate-900">p_servicetype</td>' +
                  '<td class="px-3 py-2 font-mono">LEFT JOIN service_type ON service_type.sev_id = pbrder_bapp.p_servicetype</td>' +
                  '<td class="px-3 py-2 font-mono text-indigo-600">service_type.sev_name</td>' +
                '</tr>' +
              '</tbody>' +
            '</table>' +
          '</div>' +
        '</div>';

      var footer = document.createElement('div');
      footer.className = 'flex justify-end pt-3 border-t border-slate-100';
      var closeBtn = document.createElement('button');
      closeBtn.type = 'button';
      closeBtn.className = 'nu-btn nu-btn-secondary px-5 py-2 rounded-lg text-xs font-semibold';
      closeBtn.textContent = 'Close';
      closeBtn.onclick = function () { overlay.remove(); };
      footer.appendChild(closeBtn);

      modal.appendChild(header);
      modal.appendChild(body);
      modal.appendChild(footer);
      overlay.appendChild(modal);

      overlay.onclick = function (e) {
        if (e.target === overlay) overlay.remove();
      };

      document.body.appendChild(overlay);
    },

    showUpdateTableHelp: function () {
      var overlay = document.createElement('div');
      overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(15,23,42,0.65);z-index:999999;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(4px);';

      var modal = document.createElement('div');
      modal.className = 'bg-white rounded-xl shadow-2xl p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto flex flex-col gap-4 text-slate-800';
      modal.style.fontFamily = 'system-ui, -apple-system, sans-serif';

      var header = document.createElement('div');
      header.className = 'flex justify-between items-center border-b pb-3 border-slate-100';
      header.innerHTML = '<h3 class="text-lg font-bold text-slate-900 flex items-center gap-2">⚙️ Automating Table Updates</h3>' +
        '<button type="button" class="text-slate-400 hover:text-slate-600 text-2xl font-semibold leading-none">&times;</button>';

      header.querySelector('button').onclick = function () { overlay.remove(); };

      var body = document.createElement('div');
      body.className = 'flex flex-col gap-5 text-sm leading-relaxed';

      body.innerHTML =
        '<div>' +
          '<p class="mb-3 text-slate-600">To automatically update another table (e.g., marking a booking/order as <strong>completed</strong> when a certificate with a reference number is saved), you can use either <strong>PHP After Save</strong> or a <strong>Workflow Transition Hook</strong>.</p>' +
        '</div>' +

        '<div class="flex flex-col gap-2">' +
          '<h4 class="font-bold text-slate-900 text-base">Method A: Using "PHP After Save" (Form DB Hook)</h4>' +
          '<p class="text-slate-600">Paste the following code into your <strong>PHP After Save</strong> editor under the PHP / CSS tab. It executes immediately after the record is saved, using the saved reference to update your target table:</p>' +
          '<pre class="text-xs font-mono bg-slate-900 text-slate-100 p-3 rounded-lg overflow-x-auto leading-normal">' +
            '<' + '?php\n' +
            '// 1. Get the reference number of the saved certificate record\n' +
            '$reference = trim($cert_reference ?? \'#cert_reference#\');\n\n' +
            'if (!empty($reference)) {\n' +
            '    try {\n' +
            '        $db = nu_db(); // Get current PDO connection\n' +
            '        \n' +
            '        // 2. Execute SQL statement to update another table\n' +
            '        $sql = "UPDATE `orders` SET `status` = \'completed\', `updated_at` = NOW() WHERE `order_reference` = ?";\n' +
            '        $stmt = $db->prepare($sql);\n' +
            '        $stmt->execute([$reference]);\n' +
            '        \n' +
            '        nu_log("Automatically completed order for reference: " . $reference, "after_save_automation");\n' +
            '    } catch (Throwable $e) {\n' +
            '        error_log("[Automation Error] " . $e->getMessage());\n' +
            '    }\n' +
            '}' +
          '</pre>' +
        '</div>' +

        '<div class="flex flex-col gap-2">' +
          '<h4 class="font-bold text-slate-900 text-base">Method B: Using "Workflow Transition Hooks"</h4>' +
          '<p class="text-slate-600">If using a workflow, define a stage transition (e.g. from <code>"Pending Certificate"</code> to <code>"Completed"</code>) and bind a transition hook:</p>' +
          '<ul class="list-disc pl-5 text-xs text-slate-600 flex flex-col gap-1">' +
            '<li>Set the <strong>Transition Hook</strong> to <code>update_record</code> to automatically update the bound table status to match the destination stage code.</li>' +
            '<li>Or set the <strong>Transition Hook</strong> to <code>call_webhook</code> to send a payload to your custom listener/API which handles custom multi-table SQL queries programmatically.</li>' +
          '</ul>' +
        '</div>';

      var footer = document.createElement('div');
      footer.className = 'flex justify-end pt-3 border-t border-slate-100';
      var closeBtn = document.createElement('button');
      closeBtn.type = 'button';
      closeBtn.className = 'nu-btn nu-btn-secondary px-5 py-2 rounded-lg text-xs font-semibold';
      closeBtn.textContent = 'Close';
      closeBtn.onclick = function () { overlay.remove(); };
      footer.appendChild(closeBtn);

      modal.appendChild(header);
      modal.appendChild(body);
      modal.appendChild(footer);
      overlay.appendChild(modal);

      overlay.onclick = function (e) {
        if (e.target === overlay) overlay.remove();
      };

      document.body.appendChild(overlay);
    },

    open: function () {
      var card = document.getElementById('formBuilderCard'); var list = document.getElementById('formsListSection');
      if (card) card.style.display = 'block'; if (list) list.style.display = 'none';
      var editId = document.getElementById('editFormId'); if (editId) editId.value = '';
      var title = document.getElementById('builderTitle'); if (title) title.textContent = 'New Form';
      _closePropsPanel(); this._clearForm();
      this._isDirty = false;
    },

    close: function () {
      if (this._isDirty) {
        if (!confirm("You have unsaved changes. Are you sure you want to close without saving?")) {
          return;
        }
      }
      this._isDirty = false;
      _closePropsPanel();
      var card = document.getElementById('formBuilderCard'); var list = document.getElementById('formsListSection');
      if (card) card.style.display = 'none'; if (list) list.style.display = '';
    },

    _clearForm: function () {
      ['builderFormName','builderFormCode','builderFormTable','formBrowseSql','formBrowseColumns','formBrowseDefaultSort',
       'formBrowseSearchPlaceholder','formBrowseSearchFields','formCustomJs','formJsBeforeSave','formJsAfterSave','formCustomPhp','formCustomPhpAfter','formCustomCss','formBrowsePhp']
        .forEach(function (id) { var el = document.getElementById(id); if (el) el.value = ''; });
      if (window.nbAce) {
        ['aceCustomJs', 'aceJsBeforeSave', 'aceJsAfterSave', 'aceCustomPhp', 'aceCustomPhpAfter', 'aceCustomCss', 'aceBrowsePhp'].forEach(function (id) {
          nbAce.setValue(id, '');
        });
      }
      var ps = document.getElementById('formBrowsePageSize'); if (ps) ps.value = '20';
      var srch = document.getElementById('formBrowseSearchEnabled'); if (srch) srch.checked = false;
      var cList = document.getElementById('browseConditionsList'); if (cList) cList.innerHTML = '';
      var canvas = document.getElementById('formCanvas');
      if (canvas) canvas.querySelectorAll('.nb-row,.nb-container').forEach(function (r) { r.remove(); });
      this._updateEmptyState();
      var _sel = function(n,v){ var e=document.querySelector('input[name="'+n+'"][value="'+v+'"]'); return e?e.closest('[class*="-card"]'):null; };
      this.selectFormType('main',        _sel('formType','main'));
      this.selectTableMode('new',        _sel('formTableMode','new'));
      this.selectPkType('autoincrement', _sel('formPkType','autoincrement'));
      this.selectDisplayMode('inline');
    },

    switchTab: function (btn) {
      if (!btn) return;
      document.querySelectorAll('.nb-tab').forEach(function (t) { t.classList.remove('active'); });
      document.querySelectorAll('.nb-tab-panel').forEach(function (p) { p.classList.remove('active'); });
      btn.classList.add('active');
      var panel = document.getElementById(btn.dataset.panel); if (panel) panel.classList.add('active');
    },

    selectFormType: function (type, card) {
      document.querySelectorAll('.nb-ftype-card').forEach(function (c) { c.classList.remove('selected'); });
      if (card) card.classList.add('selected');
      var radio = document.querySelector('input[name="formType"][value="' + type + '"]'); if (radio) radio.checked = true;
      var browseTabEl  = document.getElementById('browseTab');
      var browseNotice = document.getElementById('browseNotApplicable');
      var isBrowseable = (type === 'main' || type === 'popup');
      if (browseTabEl)  browseTabEl.style.opacity = isBrowseable ? '1' : '0.4';
      if (browseNotice) browseNotice.style.display = isBrowseable ? 'none' : 'block';
    },

    selectTableMode: function (mode, card) {
      document.querySelectorAll('.nb-tmode-card').forEach(function (c) { c.classList.remove('selected'); });
      if (card) card.classList.add('selected');
      var radio = document.querySelector('input[name="formTableMode"][value="' + mode + '"]'); if (radio) radio.checked = true;
      var nw = document.getElementById('newTableWrap'); var ex = document.getElementById('existingTableWrap');
      if (nw) nw.style.display = (mode === 'new') ? '' : 'none'; if (ex) ex.style.display = (mode === 'existing') ? '' : 'none';
    },

    selectPkType: function (type, card) {
      document.querySelectorAll('.nb-pk-card').forEach(function (c) { c.classList.remove('selected'); });
      if (card) card.classList.add('selected');
      var radio = document.querySelector('input[name="formPkType"][value="' + type + '"]'); if (radio) radio.checked = true;
    },

    selectDisplayMode: function (mode) {
      var val = mode || 'inline';
      var radio = document.querySelector('input[name="browseDisplayMode"][value="' + val + '"]');
      if (radio) radio.checked = true;
    },

    _updateEmptyState: function () {
      var canvas = document.getElementById('formCanvas'); var empty = document.getElementById('canvasEmpty');
      if (!canvas || !empty) return;
      empty.style.display = (canvas.querySelector('.nb-cfield') || canvas.querySelector('.nb-container')) ? 'none' : 'block';
    },

    addRow: function () {
      var canvas = document.getElementById('formCanvas'); if (!canvas) return null;
      var empty = document.getElementById('canvasEmpty'); if (empty) empty.style.display = 'none';
      _attachCanvasRowDrop(canvas);
      var rowId = 'nb-row-' + Date.now() + '-' + Math.random().toString(36).slice(2,5);
      var row = document.createElement('div'); row.className = 'nb-row'; row.id = rowId;
      var rh = document.createElement('div'); rh.className = 'nb-row-header';
      var rd = document.createElement('span'); rd.className = 'nb-row-drag'; rd.title = 'Drag row'; rd.textContent = '⠇';
      var rl = document.createElement('span'); rl.className = 'nb-row-label'; rl.textContent = 'Row';
      var ra = document.createElement('span'); ra.className = 'nb-row-actions';
      var colBtns = document.createElement('span');
      colBtns.className = 'nb-row-cols-bar';
      colBtns.style.cssText = 'display:inline-flex;gap:3px;margin-right:6px;';
      [1, 2, 3, 4].forEach(function(n) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'nb-row-btn nb-row-cols-btn';
        b.dataset.cols = String(n);
        b.textContent = n === 1 ? '1 Col' : n + ' Cols';
        b.title = 'Set fields in this row to ' + n + ' column' + (n > 1 ? 's' : '');
        b.addEventListener('click', function(e) {
          e.stopPropagation();
          window.nbFormBuilder.setRowColumns(row, n);
        });
        colBtns.appendChild(b);
      });
      ra.appendChild(colBtns);

      var alignBtns = document.createElement('span');
      alignBtns.className = 'nb-row-align-bar';
      alignBtns.style.cssText = 'display:inline-flex;gap:3px;margin-right:6px;border-left:1px solid var(--border-color);padding-left:6px;';
      [
        { code: 'left', label: '⇠ Left', title: 'Align elements to left' },
        { code: 'center', label: '↔ Center', title: 'Align elements to center' },
        { code: 'right', label: '⇢ Right', title: 'Align elements to right' }
      ].forEach(function(a) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'nb-row-btn nb-row-align-btn';
        b.dataset.align = a.code;
        b.textContent = a.label;
        b.title = a.title;
        b.addEventListener('click', function(e) {
          e.stopPropagation();
          window.nbFormBuilder.setRowAlignment(row, a.code);
        });
        alignBtns.appendChild(b);
      });
      ra.appendChild(alignBtns);

      var db = document.createElement('button'); db.className = 'nb-row-btn del'; db.type = 'button'; db.textContent = '✕';
      db.addEventListener('click', function () {
        if (!confirm("Are you sure you want to delete this row and all its fields?")) return;
        row.remove();
        window.nbFormBuilder._updateEmptyState();
        window.nbFormBuilder._isDirty = true;
      });
      ra.appendChild(db); rh.appendChild(rd); rh.appendChild(rl); rh.appendChild(ra);
      var rb = document.createElement('div'); rb.className = 'nb-row-body';
      var dh = document.createElement('div'); dh.className = 'nb-row-drop-hint'; dh.textContent = 'Drop fields here'; rb.appendChild(dh);
      row.appendChild(rh); row.appendChild(rb); canvas.appendChild(row);
      _wireRowDrag(row); _attachRowBodyDrop(rb);
      this._isDirty = true;
      return row;
    },

    _addRowToContainer: function (target) { return _addRowToContainer(target, [], false); },

    addField: function (type, extraData) {
      var canvas = document.getElementById('formCanvas'); if (!canvas) return null;
      var extra = extraData || {};
      _attachCanvasRowDrop(canvas);
      if (type === 'group') { var grp = _makeGroupContainer(extra); canvas.appendChild(grp); var eg = document.getElementById('canvasEmpty'); if (eg) eg.style.display = 'none'; this._isDirty = true; return grp; }
      if (type === 'tab')   { var tab = _makeTabContainer(extra);   canvas.appendChild(tab);  var et = document.getElementById('canvasEmpty'); if (et) et.style.display = 'none'; this._isDirty = true; return tab; }
      var label = extra.label || extra.fieldlabel || (type.charAt(0).toUpperCase() + type.slice(1) + ' Field');
      var name  = extra.name  || extra.fieldname  || (type + '_' + (++_fieldCounter));
      var col   = parseInt(extra.col || extra.colspan, 10) || 6;
      var card  = this._makeFieldCard(type, label, name, !!extra.required, extra);
      if (!card) return null;
      var canvasRows = canvas.querySelectorAll(':scope > .nb-row');
      var targetBody = canvasRows.length ? canvasRows[canvasRows.length - 1].querySelector('.nb-row-body') : null;
      if (!targetBody) { var newRow = this.addRow(); targetBody = newRow ? newRow.querySelector('.nb-row-body') : null; }
      if (!targetBody) { canvas.appendChild(card); this._isDirty = true; return card; }
      var hint = targetBody.querySelector('.nb-row-drop-hint'); if (hint) hint.remove();
      _prepCard(card); targetBody.appendChild(card); this._applyColSpan(card, col);
      this._isDirty = true;
      return card;
    },

    _makeFieldCard: function (type, label, name, required, extra) {
  extra = extra || {};
  extra = Object.assign({}, extra, { required: required || !!extra.required });
  var col = parseInt(extra.col || extra.colspan, 10) || 6;
  var canvasType = type;
  if (type === 'multiselect') { canvasType = 'select2'; if (!extra.multiple) extra = Object.assign({}, extra, { multiple: true }); }
  if (type === 'select' && (extra.select2 === true || extra.select2 === 'true' || extra.select2 === 1)) canvasType = 'select2';
  if (type === 'group' || type === 'tab') return null;

  var extraBody = '';
  if (canvasType === 'select') {
    var selIsMulti = extra.multiple === true || extra.multiple === 'true' || extra.multiple === 1 || extra.select_type === 'multiselect';
    var opts = (extra.options || []).map(function (o) { return typeof o === 'object' ? (o.value + '|' + o.label) : o; }).join('\n');
    extraBody += '<div class="nb-fp"><label style="font-size:11px;font-weight:600;">Select Mode</label><select class="nu-input nu-field-select-mode" style="font-size:12px;"><option value="single"' + (!selIsMulti ? ' selected' : '') + '>Single</option><option value="multi"' + (selIsMulti ? ' selected' : '') + '>Multi-Select</option></select></div>' + _optionsSourceHTML(name, extra.options_source === 'table', opts, extra);
  }
  if (canvasType === 'select2') {
    var s2Multi = extra.multiple === true || extra.multiple === 'true' || extra.multiple === 1 || extra.select_type === 'multiselect';
    var allowClr = (extra.allow_clear === false || extra.allow_clear === 'false') ? '' : 'checked';
    var s2Opts = (extra.options || []).map(function (o) { return typeof o === 'object' ? (o.value + '|' + o.label) : o; }).join('\n');
    extraBody += '<div style="background:var(--bg-offset,#eef2ff);border:1.5px solid var(--color-primary,#4f6bed);border-radius:8px;padding:12px 14px;grid-column:1/-1;margin-bottom:4px;"><div style="font-size:11px;font-weight:700;letter-spacing:.06em;color:var(--color-primary,#4f6bed);margin-bottom:10px;">🔍 SELECT2 CONFIG</div><div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;"><div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Mode</label><select class="nu-input nu-field-select-mode" style="font-size:12px;"><option value="single"' + (!s2Multi ? ' selected' : '') + '>Single</option><option value="multi"' + (s2Multi ? ' selected' : '') + '>Multi-Select</option></select></div><div style="display:flex;align-items:flex-end;padding-bottom:4px;"><label class="nb-fp-check" style="font-size:11px;"><input type="checkbox" class="nu-field-allow-clear"' + (allowClr ? ' checked' : '') + '> Allow Clear</label></div></div></div>' + _optionsSourceHTML(name, extra.options_source === 'table', s2Opts, extra);
  }
  if (canvasType === 'radio' || canvasType === 'checkbox_group') {
    var rcOpts = (extra.options || []).map(function (o) { return typeof o === 'object' ? (o.value + '|' + o.label) : o; }).join('\n');
    extraBody += _optionsSourceHTML(name, extra.options_source === 'table', rcOpts, extra);
  }
  if (canvasType === 'calculated') extraBody += '<div class="nb-fp nb-fp-full"><label>Formula</label><textarea class="nu-input nu-field-formula" rows="2" placeholder="{qty} * {price}">' + _esc(extra.formula || extra.calc_formula || '') + '</textarea></div>';
  if (canvasType === 'lookup') {
    var lk = (extra.lookup && typeof extra.lookup === 'object') ? extra.lookup : {};
    extraBody += '<div style="background:var(--bg-offset,#f0f4ff);border:1.5px solid var(--color-primary,#4f6bed);border-radius:8px;padding:12px 14px;margin-top:6px;grid-column:1/-1;"><div style="font-size:11px;font-weight:700;letter-spacing:.06em;color:var(--color-primary,#4f6bed);margin-bottom:10px;">🔗 LOOKUP CONFIG</div><div style="margin-bottom:8px;"><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Lookup Table</label><input type="text" class="nu-input nu-lookup-table" value="' + _esc(lk.table || extra.lookup_form || '') + '" placeholder="e.g. customers" style="font-size:12px;width:100%;box-sizing:border-box;"></div><div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;"><div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Display Col</label><input type="text" class="nu-input nu-lookup-display" value="' + _esc(lk.display_column || extra.lookup_display || '') + '" placeholder="full_name" style="font-size:12px;"></div><div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Store Col</label><input type="text" class="nu-input nu-lookup-store" value="' + _esc(lk.id_column || extra.lookup_store || '') + '" placeholder="id" style="font-size:12px;"></div></div><div style="margin-bottom:8px;"><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Additional Display Fields (comma-separated)</label><input type="text" class="nu-input nu-lookup-additional-fields" value="' + _esc(lk.additional_fields || '') + '" placeholder="e.g. code, email" style="font-size:12px;width:100%;box-sizing:border-box;"></div><div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;"><div style="grid-column:1/-1;"><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Filter SQL</label><input type="text" class="nu-input nu-lookup-filter" value="' + _esc(lk.filter || extra.lookup_filter || '') + '" placeholder="active=1" style="font-size:12px;width:100%;box-sizing:border-box;"></div><div style="grid-column:1/-1;margin-top:4px;"><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Extra Mapping</label><textarea class="nu-input nu-lookup-extra" rows="2" placeholder="e.g. f_fee:in_unitprice, in_qty:1" style="font-size:12px;width:100%;box-sizing:border-box;">' + _esc(lk.extra || extra.lookup_extra || '') + '</textarea></div></div></div>';
  }
  if (canvasType === 'upload') {
    extraBody += '<div style="background:var(--bg-offset,#f0f4ff);border:1.5px solid var(--color-primary,#4f6bed);border-radius:8px;padding:12px 14px;margin-top:6px;grid-column:1/-1;"><div style="font-size:11px;font-weight:700;letter-spacing:.06em;color:var(--color-primary,#4f6bed);margin-bottom:10px;">📤 UPLOAD CONFIG</div><div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;"><div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Allowed Exts</label><input type="text" class="nu-input nu-upload-exts" value="' + _esc(extra.allowed_extensions || '') + '" placeholder="jpg,png,pdf" style="font-size:12px;"></div><div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Max Size (MB)</label><input type="number" class="nu-input nu-upload-maxsize" value="' + _esc(extra.max_size_mb || '') + '" placeholder="10" style="font-size:12px;"></div></div></div>';
  }
  if (canvasType === 'photo_canvas') {
    extraBody += '<div style="background:var(--bg-offset,#f0f4ff);border:1.5px solid var(--color-primary,#4f6bed);border-radius:8px;padding:12px 14px;margin-top:6px;grid-column:1/-1;"><div style="font-size:11px;font-weight:700;letter-spacing:.06em;color:var(--color-primary,#4f6bed);margin-bottom:10px;">🎨 PHOTO CANVAS CONFIG</div><div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;"><div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Canvas Width</label><input type="number" class="nu-input nu-canvas-width" value="' + _esc(extra.canvas_width || '') + '" placeholder="800" style="font-size:12px;"></div><div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Canvas Height</label><input type="number" class="nu-input nu-canvas-height" value="' + _esc(extra.canvas_height || '') + '" placeholder="600" style="font-size:12px;"></div></div></div>';
  }
  var sfData;
  if (canvasType === 'subform') {
  var sf = (extra.subform && typeof extra.subform === 'object') ? extra.subform : {};
  sfData = {
    form_code:            sf.form_code            || extra.sf_form_code || '',
    fk_field:             sf.fk_field             || extra.sf_fk_field  || '',
    subform_view:         sf.subform_view         || extra.subform_view  || 'grid',
    help_text:            extra.help_text         || extra.field_help_text || '',
    is_fk:                sf.is_fk !== undefined ? !!sf.is_fk : !!extra.is_fk,
    hide_in_grid:         sf.hide_in_grid !== undefined ? !!sf.hide_in_grid : !!extra.hide_in_grid,
    server_readonly:      sf.server_readonly !== undefined ? !!sf.server_readonly : !!extra.server_readonly,
    searchable:           sf.searchable !== undefined ? !!sf.searchable : (extra.searchable !== undefined ? !!extra.searchable : true),
    selected_fields_only: sf.selected_fields_only !== undefined ? !!sf.selected_fields_only : !!extra.selected_fields_only,
    view_roles:           sf.view_roles || extra.view_roles || '',
    edit_roles:           sf.edit_roles || extra.edit_roles || ''
  };
  extraBody += _subformPanelHTML(sfData);
}

  var card = document.createElement('div');
  card.className = 'nb-cfield'; card.dataset.type = canvasType; card.dataset.runtimeType = type;
  card.style.flex = '0 0 auto'; card.dataset.col = String(col);

  /* ── FIX-L: set dataset BEFORE innerHTML so they are never wiped ── */
 card.dataset.fieldLabel   = label || '';
card.dataset.fieldName    = name  || '';
card.dataset.fieldPh      = extra.placeholder   || '';
card.dataset.fieldDefault = extra.default_value || extra.defaultvalue || '';
card.dataset.fieldHelp    = extra.help_text || extra.field_help_text || '';

if (canvasType === 'subform' && sfData) {
  card.dataset.sfFormCode       = sfData.form_code    || '';
  card.dataset.sfFkField        = sfData.fk_field     || '';
  card.dataset.sfSubformView    = sfData.subform_view || 'grid';
  card.dataset.sfHelpText       = sfData.help_text    || '';
  card.dataset.sfIsFk           = sfData.is_fk           ? '1' : '0';
  card.dataset.sfHideInGrid     = sfData.hide_in_grid    ? '1' : '0';
  card.dataset.sfServerReadonly = sfData.server_readonly ? '1' : '0';
  card.dataset.sfSearchable     = sfData.searchable      ? '1' : '0';
  card.dataset.sfSelectedFieldsOnly = sfData.selected_fields_only ? '1' : '0';
  card.dataset.sfViewRoles      = sfData.view_roles      || '';
  card.dataset.sfEditRoles      = sfData.edit_roles      || '';
}
/* ── END ADD ── */

  card.innerHTML =
    '<div class="nb-cfield-header">'
      + '<span class="nb-cfield-drag">⠇</span>'
      + '<span class="nb-cfield-type-badge">' + _esc(canvasType) + '</span>'
      + '<span class="nb-cfield-label">' + _esc(label) + '</span>'
      + '<span class="nb-cfield-span-badge" style="font-size:10px;color:var(--text-muted,#aaa);margin-left:auto;">' + col + '/12</span>'
      + '<span class="nb-cfield-actions">'
        + '<button class="nb-cfield-btn move-tab-btn" type="button" title="Move to Tab">⇆</button>'
        + '<button class="nb-cfield-btn del" type="button" title="Remove">✕</button>'
      + '</span>'
    + '</div>'
    + '<div class="nb-cfield-body" style="display:none;">'
      + '<div class="nb-fp-grid">'
        + '<div class="nb-fp"><label>Label</label><input type="text" class="nu-input nu-field-label" value="' + _esc(label) + '"></div>'
        + '<div class="nb-fp"><label>Field Name</label><input type="text" class="nu-input nu-field-name" value="' + _esc(name) + '"></div>'
        + (canvasType !== 'subform' ? '<div class="nb-fp"><label>Placeholder</label><input type="text" class="nu-input nu-field-placeholder" value="' + _esc(extra.placeholder || '') + '"></div>' : '')
        + (canvasType !== 'subform' ? '<div class="nb-fp"><label>Default Value</label><input type="text" class="nu-input nu-field-default" value="' + _esc(extra.default_value || extra.defaultvalue || '') + '"></div>' : '')
        + '<div class="nb-fp nb-fp-full"><label>Help Text</label><input type="text" class="nu-input nu-field-help" value="' + _esc(extra.help_text || extra.field_help_text || '') + '"></div>'
        + extraBody
        + _visibilityFlagsHTML(extra)
        + '<div class="nb-fp nb-fp-full" style="grid-column:1/-1;"><label>Custom Attributes</label><textarea class="nu-input nu-field-custom-attrs" rows="2">' + _esc(extra.custom_attributes || '') + '</textarea></div>'
      + '</div>'
    + '</div>';

  var labelInput = card.querySelector('.nu-field-label');
  if (labelInput) {
    labelInput.addEventListener('input', function () { var hdr = card.querySelector('.nb-cfield-label'); if (hdr) hdr.textContent = labelInput.value || '(no label)'; });
  }
  var delBtn = card.querySelector('.nb-cfield-btn.del');
  if (delBtn) {
    delBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      if (!confirm("Are you sure you want to delete this field?")) return;
      if (_activeCard === card) _closePropsPanel();
      card.remove(); window.nbFormBuilder._updateEmptyState();
      window.nbFormBuilder._isDirty = true;
    });
  }
  var moveBtn = card.querySelector('.nb-cfield-btn.move-tab-btn');
  if (moveBtn) {
    moveBtn.addEventListener('click', function (e) {
      e.stopPropagation();

      var tabContainers = document.querySelectorAll('.nb-container-tab');
      var isCurrentlyInTab = !!card.closest('.nb-container-tab');

      if (tabContainers.length === 0) {
        alert("No Tab Containers found. Add a Tab Container to the canvas first.");
        return;
      }

      var existingDropdown = document.getElementById('nb-move-dropdown');
      if (existingDropdown) existingDropdown.remove();

      var dropdown = document.createElement('div');
      dropdown.id = 'nb-move-dropdown';
      dropdown.style.cssText = 'position:fixed;background:var(--bg-elevated,#fff);border:1px solid var(--border,#ccc);border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,0.15);z-index:99999;padding:6px 0;min-width:160px;';

      var rect = moveBtn.getBoundingClientRect();
      dropdown.style.top = (rect.bottom + window.scrollY) + 'px';
      dropdown.style.left = (rect.left + window.scrollX) + 'px';

      var currentPanel = card.closest('.nb-cfield-tab-panel');

      if (isCurrentlyInTab) {
        var mainCanvasOpt = document.createElement('div');
        mainCanvasOpt.style.cssText = 'padding:6px 12px;font-size:12px;cursor:pointer;color:var(--color-primary,#4f6bed);font-weight:600;border-bottom:1px solid var(--border,#eee);transition:background 0.2s;';
        mainCanvasOpt.textContent = "Move to Main Canvas";
        mainCanvasOpt.addEventListener('mouseover', function () { mainCanvasOpt.style.background = 'var(--bg-offset,#f5f7ff)'; });
        mainCanvasOpt.addEventListener('mouseout', function () { mainCanvasOpt.style.background = 'none'; });

        mainCanvasOpt.addEventListener('click', function (ev) {
          ev.stopPropagation();
          dropdown.remove();

          var canvas = document.getElementById('formCanvas');
          if (!canvas) return;

          var targetRowBody = null;
          var canvasRows = canvas.querySelectorAll(':scope > .nb-row');
          if (canvasRows.length > 0) {
            targetRowBody = canvasRows[canvasRows.length - 1].querySelector('.nb-row-body');
          } else {
            var newRow = window.nbFormBuilder.addRow();
            if (newRow) {
              targetRowBody = newRow.querySelector('.nb-row-body');
            }
          }

          if (targetRowBody) {
            var oldRow = card.closest('.nb-row');
            var hint = targetRowBody.querySelector('.nb-row-drop-hint');
            if (hint) hint.remove();

            targetRowBody.appendChild(card);
            window.nbFormBuilder._applyColSpan(card, card.dataset.col || 6);

            if (oldRow && !oldRow.querySelector('.nb-cfield')) {
              oldRow.remove();
            }

            window.nbFormBuilder._updateEmptyState();
            window.nbFormBuilder._isDirty = true;
            NuApp.toast('Element moved to Main Canvas successfully!', 'success');
          }
        });
        dropdown.appendChild(mainCanvasOpt);
      }

      tabContainers.forEach(function (container, cIdx) {
        var containerPrefix = tabContainers.length > 1 ? "Container " + (cIdx + 1) + " - " : "";
        var nav = container.querySelector('.nb-cfield-tab-nav');
        if (!nav) return;
        var navItems = nav.querySelectorAll('.nb-cfield-tab-nav-item');
        var panels = container.querySelector('.nb-container-tab-panels');

        navItems.forEach(function (item) {
          var inputEl = item.querySelector('.nb-tab-name-input');
          var tabName = inputEl ? inputEl.value : 'Tab';
          var panelId = item.dataset.panelTarget;

          if (currentPanel && currentPanel.id === panelId) {
            return;
          }

          var option = document.createElement('div');
          option.style.cssText = 'padding:6px 12px;font-size:12px;cursor:pointer;color:var(--text-main,#333);transition:background 0.2s;';
          option.textContent = containerPrefix + tabName;
          option.addEventListener('mouseover', function () { option.style.background = 'var(--bg-offset,#f5f7ff)'; });
          option.addEventListener('mouseout', function () { option.style.background = 'none'; });

          option.addEventListener('click', function (ev) {
            ev.stopPropagation();
            dropdown.remove();

            var targetPanel = document.getElementById(panelId);
            if (!targetPanel) return;
            var rowsBody = targetPanel.querySelector('.nb-tab-panel-rows');
            if (!rowsBody) return;

            var targetRowBody = null;
            var lastRow = rowsBody.querySelector(':scope > .nb-row');
            if (lastRow) {
              targetRowBody = lastRow.querySelector('.nb-row-body');
            } else {
              var newRow = _addRowToContainer(rowsBody, [], false);
              if (newRow) {
                targetRowBody = newRow.querySelector('.nb-row-body');
              }
            }

            if (targetRowBody) {
              var oldRow = card.closest('.nb-row');
              var hint = targetRowBody.querySelector('.nb-row-drop-hint');
              if (hint) hint.remove();

              targetRowBody.appendChild(card);
              window.nbFormBuilder._applyColSpan(card, card.dataset.col || 6);

              if (oldRow && !oldRow.querySelector('.nb-cfield')) {
                oldRow.remove();
              }

              window.nbFormBuilder._updateEmptyState();
              window.nbFormBuilder._isDirty = true;

              navItems.forEach(function (n) { n.classList.remove('active'); });
              if (panels) {
                panels.querySelectorAll('.nb-cfield-tab-panel').forEach(function (p) { p.classList.remove('active'); });
              }
              item.classList.add('active');
              targetPanel.classList.add('active');

              NuApp.toast('Element moved successfully!', 'success');
            }
          });
          dropdown.appendChild(option);
        });
      });

      document.body.appendChild(dropdown);

      var closeDropdown = function () {
        dropdown.remove();
        document.removeEventListener('click', closeDropdown);
      };
      setTimeout(function () {
        document.addEventListener('click', closeDropdown);
      }, 0);
    });
  }
  if (['select','select2','radio','checkbox_group'].indexOf(canvasType) !== -1) _attachSelectOptionsToggle(card);
  if (canvasType === 'subform') _attachSubformPanelEvents(card, sfData);
  return card;
},
    _applyColSpan: function (card, col) {
      if (!card) return;
      var c = Math.min(12, Math.max(1, parseInt(col, 10) || 6));
      card.dataset.col = String(c);
      card.style.gridColumn = 'span ' + c;
      card.style.width = '100%';
      card.style.maxWidth = '100%';

      var align = card.dataset.rowAlign || (card.closest('.nb-row') ? card.closest('.nb-row').dataset.align : '');
      if (align === 'right') {
        card.style.gridColumnStart = String(12 - c + 1);
      } else if (align === 'center') {
        card.style.gridColumnStart = String(Math.floor((12 - c) / 2) + 1);
      } else {
        card.style.gridColumnStart = '';
      }

      var badge = card.querySelector('.nb-cfield-span-badge');
      if (badge) badge.textContent = c + '/12';
    },

    _collectBrowseConditions: function() {
      var list = document.getElementById('browseConditionsList');
      if (!list) return [];
      var conditions = [];
      list.querySelectorAll('.nb-browse-cond-row').forEach(function(row) {
        var role = row.querySelector('.nb-cond-role').value;
        var where = row.querySelector('.nb-cond-where').value.trim();
        var cols = row.querySelector('.nb-cond-cols').value.trim();
        if (role || where || cols) {
          conditions.push({ role: role, where: where, columns: cols });
        }
      });
      return conditions;
    },

    addBrowseCondition: function(data) {
      var d = data || { role: '', where: '', columns: '' };
      var list = document.getElementById('browseConditionsList');
      if (!list) return;

      var row = document.createElement('div');
      row.className = 'nb-browse-cond-row';
      row.style.cssText = 'display:flex;gap:8px;align-items:flex-end;background:var(--bg-offset);padding:10px;border-radius:6px;border:1px solid var(--border-color);';

      var rolesOpts = '<option value="*">All Roles (*)</option>';
      if (window.nuRolesList) {
        window.nuRolesList.forEach(function(r) {
          var sel = (d.role === r.role_code) ? ' selected' : '';
          rolesOpts += '<option value="' + r.role_code + '"' + sel + '>' + r.role_name + '</option>';
        });
      } else {
        if (d.role && d.role !== '*') {
          rolesOpts += '<option value="' + d.role + '" selected>' + d.role + '</option>';
        }
      }

      row.innerHTML = `
        <div style="flex: 1;">
          <label style="font-size:11px;display:block;margin-bottom:4px;color:var(--text-secondary);">Role</label>
          <select class="nu-input nb-cond-role">${rolesOpts}</select>
        </div>
        <div style="flex: 2;">
          <label style="font-size:11px;display:block;margin-bottom:4px;color:var(--text-secondary);">WHERE Clause (e.g., station = '##location##')</label>
          <input type="text" class="nu-input nb-cond-where" value="${(d.where || '').replace(/"/g, '&quot;')}">
        </div>
        <div style="flex: 1;">
          <label style="font-size:11px;display:block;margin-bottom:4px;color:var(--text-secondary);">Columns (comma-sep)</label>
          <input type="text" class="nu-input nb-cond-cols" value="${(d.columns || '').replace(/"/g, '&quot;')}">
        </div>
        <button type="button" class="nu-btn nu-btn-danger nu-btn-sm" style="padding:4px 8px;margin-bottom:2px;" onclick="this.closest('.nb-browse-cond-row').remove()">✕</button>
      `;
      list.appendChild(row);
    },

    getLayout: function () {
      var canvas = document.getElementById('formCanvas'); if (!canvas) return [];
      var layout = [];
      Array.prototype.forEach.call(canvas.children, function (el) {
        if (el.classList.contains('nb-row')) {
          var ri = Array.prototype.indexOf.call(canvas.querySelectorAll(':scope > .nb-row'), el);
          _collectRowFields(el, ri).forEach(function (f) { layout.push(f); });
        } else if (el.classList.contains('nb-container')) {
          var ctype = el.dataset.containerType;
          if (ctype === 'group') {
            var li = el.querySelector('.nb-container-label-input');
            layout.push({
              type: 'group',
              label: li ? li.value : '',
              name: el.dataset.groupName || ('group_' + Date.now()),
              rows: _collectContainerRows(el.querySelector('.nb-container-group-body')),
              col: 12,
              row_index: -1,
              collapsible: el.dataset.collapsible === 'true',
              collapsed: el.dataset.collapsedByDefault === 'true',
              bg_color: el.dataset.bgColor || '',
              header_bg_color: el.dataset.headerBgColor || '',
              border_color: el.dataset.borderColor || '',
              text_color: el.dataset.textColor || '',
              border_style: el.dataset.borderStyle || '',
              border_width: el.dataset.borderWidth || ''
            });
          } else if (ctype === 'tab') {
            var tabsData = [];
            var tn = el.querySelector('[id$="-nav"]'); var tp = el.querySelector('[id$="-panels"]');
            if (tn && tp) {
              tn.querySelectorAll('.nb-cfield-tab-nav-item').forEach(function (navItem) {
                var ni = navItem.querySelector('.nb-tab-name-input');
                var panelEl = document.getElementById(navItem.dataset.panelTarget);
                tabsData.push({ name: ni ? ni.value : 'Tab', rows: panelEl ? _collectTabPanelContent(panelEl.querySelector('.nb-tab-panel-rows')) : [] });
              });
            }
            layout.push({ type:'tab', name:'tab_'+Date.now(), tabs:tabsData, col:12, row_index:-1 });
          }
        }
      });
      if (this._currentStyle) {
        layout.push(Object.assign({ type: '_form_style' }, this._currentStyle));
      }
      if (typeof window._nbSfAugmentLayout === 'function') layout = window._nbSfAugmentLayout(layout);
      return layout;
    },

    saveForm: async function () {
      var nameEl = document.getElementById('builderFormName'); var codeEl = document.getElementById('builderFormCode');
      var tableEl = document.getElementById('builderFormTable'); var editEl = document.getElementById('editFormId');
      var name = nameEl ? nameEl.value.trim() : ''; var editId = editEl ? editEl.value.trim() : '';
      if (!name) { NuApp.toast('Form name is required', 'error'); return; }
      var _r = function (n) { var e = document.querySelector('input[name="' + n + '"]:checked'); return e ? e.value : ''; };
      var _v = function (id) { var e = document.getElementById(id); return e ? e.value : ''; };
      var _c = function (id) { var e = document.getElementById(id); return !!(e && e.checked); };

      // Auto-calculate legacy browse_columns text
      var colNames = [];
      document.querySelectorAll('.nb-browse-column-row').forEach(function (row) {
        var fld = row.querySelector('.nb-col-field').value;
        if (fld) colNames.push(fld);
      });
      var browseColumnsValue = colNames.join(',');

      var tableMode = _r('formTableMode') || 'new';
      var payload = {
        form_name: name, form_code: codeEl ? codeEl.value.trim() : '', form_table: tableEl ? tableEl.value.trim() : '',
        form_type: _r('formType') || 'main', form_table_mode: tableMode, form_pk_type: _r('formPkType') || 'autoincrement',
        form_layout: JSON.stringify(this.getLayout()),
        create_table: (tableMode === 'new' && !editId) ? 1 : 0,
        browse_display_mode: _r('browseDisplayMode'), browse_sql: _v('formBrowseSql'), browse_columns: browseColumnsValue,
        browse_layout: this._collectBrowseLayout(),
        browse_page_size: _v('formBrowsePageSize'), browse_default_sort: _v('formBrowseDefaultSort'),
        browse_search_enabled: _c('formBrowseSearchEnabled') ? 1 : 0, browse_search_placeholder: _v('formBrowseSearchPlaceholder'),
        browse_search_fields: _v('formBrowseSearchFields'), browse_conditions: this._collectBrowseConditions(),
        browse_delete_enabled: _c('formBrowseDeleteEnabled') ? 1 : 0,
        browse_php: _v('formBrowsePhp'),
        form_custom_js: _v('formCustomJs'),
        form_js_before_save: _v('formJsBeforeSave'), form_js_after_save: _v('formJsAfterSave'),
        form_custom_php: _v('formCustomPhp'), form_custom_php_after: _v('formCustomPhpAfter'), form_custom_css: _v('formCustomCss')
      };
      if (editId) payload.form_id = editId;
      console.log('[saveForm] payload:', JSON.stringify(payload, null, 2));
      try {
        var res = await NuApp.apiJson('api/forms.php?action=save', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload) });
        if (res && res.success) {
          NuApp.toast(editId ? 'Form updated!' : 'Form created!', 'success');
          this._isDirty = false;
          this.close();
          NuApp.loadModule('forms');
        }
        else { NuApp.toast((res && res.error) || 'Save failed', 'error'); }
      } catch (err) { NuApp.toast('Save error: ' + err.message, 'error'); }
    },

    loadForm: function (formData) {
      var me = this;
      _closePropsPanel(); me._clearForm();
      var _set = function (id, val) { var e = document.getElementById(id); if (e) e.value = val || ''; };
      var _chk = function (id, val) { var e = document.getElementById(id); if (e) e.checked = (val !== undefined && val !== null && val !== 0 && val !== '0' && !!val); };
      _set('builderFormName', formData.form_name || formData.name || '');
      _set('builderFormCode', formData.form_code || formData.code || '');
      _set('builderFormTable', formData.form_table || formData.table_name || '');
      _set('editFormId', formData.form_id || formData.id || '');
      _set('formBrowseSql', formData.browse_sql || ''); _set('formBrowseColumns', formData.browse_columns || '');
      _set('formBrowsePageSize', formData.browse_page_size || '20'); _set('formBrowseDefaultSort', formData.browse_default_sort || '');
      _set('formBrowseSearchPlaceholder', formData.browse_search_placeholder || ''); _set('formBrowseSearchFields', formData.browse_search_fields || '');
      _set('formBrowsePhp', formData.browse_php || '');
      _set('formCustomJs', formData.form_custom_js || ''); _set('formJsBeforeSave', formData.form_js_before_save || '');
      _set('formJsAfterSave', formData.form_js_after_save || ''); _set('formCustomPhp', formData.form_custom_php || '');
      _set('formCustomPhpAfter', formData.form_custom_php_after || '');
      _set('formCustomCss', formData.form_custom_css || ''); _chk('formBrowseSearchEnabled', formData.browse_search_enabled);
      _chk('formBrowseDeleteEnabled', formData.browse_delete_enabled !== undefined ? formData.browse_delete_enabled : 1);
      me.selectDisplayMode(formData.browse_display_mode || 'inline');
      var _sel = function (n, v) { var e = document.querySelector('input[name="'+n+'"][value="'+v+'"]'); return e ? e.closest('[class*="-card"]') : null; };
      me.selectFormType(formData.form_type || 'main', _sel('formType', formData.form_type || 'main'));
      me.selectTableMode(formData.form_table_mode || 'existing', _sel('formTableMode', formData.form_table_mode || 'existing'));
      me.selectPkType(formData.form_pk_type || 'autoincrement', _sel('formPkType', formData.form_pk_type || 'autoincrement'));
      var titleEl = document.getElementById('builderTitle'); if (titleEl) titleEl.textContent = 'Edit: ' + (formData.form_name || formData.name || '');

      // Load Version History Revision list
      me.loadFormVersions(formData.form_id || formData.id);

      // Clear browse designer columns list & make reorderable
      var browseColsList = document.getElementById('browseColumnsList');
      if (browseColsList) {
        browseColsList.innerHTML = '';
        _makeBrowseColumnsReorderable();
      }

      var bLayout = [];
      if (formData.browse_layout) {
        try { bLayout = JSON.parse(formData.browse_layout); } catch (e) { bLayout = []; }
      }
      if (Array.isArray(bLayout) && bLayout.length > 0) {
        bLayout.forEach(function (col) {
          me.addBrowseColumnDesignerRow(col);
        });
      } else if (formData.browse_columns) {
        // Fallback: populate from comma-separated list of column names
        var cols = formData.browse_columns.split(',');
        cols.forEach(function (colName) {
          colName = colName.trim();
          if (colName) {
            me.addBrowseColumnDesignerRow({
              fieldname: colName,
              fieldlabel: colName.charAt(0).toUpperCase() + colName.slice(1).replace('_', ' '),
              width: '',
              align: 'left',
              formatter: 'text',
              sortable: true,
              frozen: false
            });
          }
        });
      }

      var conds = formData.browse_conditions;
      if (conds && typeof conds === 'string') {
        try { conds = JSON.parse(conds); } catch (e) { conds = []; }
      }
      if (conds && Array.isArray(conds)) {
        conds.forEach(function(cond) { me.addBrowseCondition(cond); });
      }

      var layout = [];
      try { layout = JSON.parse(formData.form_layout || '[]'); } catch (e) { layout = []; }
      if (!Array.isArray(layout)) layout = [];
      var canvas = document.getElementById('formCanvas'); if (!canvas) return;
      _attachCanvasRowDrop(canvas);
      var rowMap = {}, topOrder = [], seenRows = {};
      layout.forEach(function (item) {
        if (item.type === 'group') { topOrder.push({ kind:'container', ctype:'group', data:item }); }
        else if (item.type === 'tab') { topOrder.push({ kind:'container', ctype:'tab', data:item }); }
        else {
          var ri = (item.row_index !== undefined && item.row_index !== null) ? parseInt(item.row_index, 10) : -1;
          if (isNaN(ri)) ri = -1;
          if (!rowMap[ri]) { rowMap[ri] = []; topOrder.push({ kind:'row', row_index:ri, fields:rowMap[ri] }); }
          rowMap[ri].push(item);
        }
      });
      topOrder.filter(function (e) {
        if (e.kind !== 'row') return true;
        if (seenRows[e.row_index]) return false;
        seenRows[e.row_index] = true; return true;
      }).forEach(function (entry) {
        if (entry.kind === 'container') {
          var cEl = entry.ctype === 'tab' ? _makeTabContainer(entry.data) : _makeGroupContainer(entry.data);
          canvas.appendChild(cEl); _wireRowDrag(cEl);
        } else {
          var row = me.addRow(); if (!row) return;
          var rb = row.querySelector('.nb-row-body'); if (!rb) return;
          var hint = rb.querySelector('.nb-row-drop-hint'); if (hint) hint.remove();
entry.fields.forEach(function (f) {
  /* The API stores field data flat at top level.
     Never unwrap f.fields — that path is for container rows only. */
  var fType  = f.type  || 'text';
  var fLabel = f.label || f.fieldlabel || f.field_label || f.title || f.name || '';
  var fName  = f.name  || f.fieldname  || f.field_name  || f.column_name || '';
  var fReq   = !!f.required;

  var card = window.nbFormBuilder._makeFieldCard(fType, fLabel, fName, fReq, f);
            if (!card) return;
            _prepCard(card); rb.appendChild(card);
            me._applyColSpan(card, parseInt(f.col, 10) || 6);
            _restoreFieldState(card, f);
            if ((f.type === 'subform' || card.dataset.type === 'subform') && f.subform) {
              var sfObj = (f.subform && typeof f.subform === 'object') ? f.subform : {};
              var sfInst = {
                form_code:            sfObj.form_code            || f.sf_form_code || '',
                fk_field:             sfObj.fk_field             || f.sf_fk_field  || '',
                subform_view:         f.subform_view             || sfObj.subform_view || 'grid',
                help_text:            f.help_text                || f.field_help_text || '',
                is_fk:                sfObj.is_fk !== undefined ? !!sfObj.is_fk : !!f.is_fk,
                hide_in_grid:         sfObj.hide_in_grid !== undefined ? !!sfObj.hide_in_grid : !!f.hide_in_grid,
                server_readonly:      sfObj.server_readonly !== undefined ? !!sfObj.server_readonly : !!f.server_readonly,
                searchable:           sfObj.searchable !== undefined ? !!sfObj.searchable : (f.searchable !== undefined ? !!f.searchable : true),
                selected_fields_only: sfObj.selected_fields_only !== undefined ? !!sfObj.selected_fields_only : !!f.selected_fields_only,
                view_roles:           sfObj.view_roles || f.view_roles || '',
                edit_roles:           sfObj.edit_roles || f.edit_roles || ''
              };
              _nbSfData.write(card, sfInst); _attachSubformPanelEvents(card, sfInst);
            }
          });
        }
      });
      me._updateEmptyState(); me._initAfterLoad();
    },

    _initAfterLoad: function () {
      _attachAllRowDrops();
      var canvas = document.getElementById('formCanvas');
      if (canvas) {
        _attachCanvasRowDrop(canvas);
        _attachRowContainerDragAndDrop(canvas);
      }
    }
  };

  window.saveForm = function () { return window.nbFormBuilder.saveForm(); };


  /* ════════════════════════════════════════════════════════════════════
     Layout read helpers
  ═══════════════════════════════════════════════════════════════════ */
  function _collectRowFields(rowEl, ri) {
    var fields = [];
    var body = rowEl.querySelector('.nb-row-body');
    if (body) {
      Array.prototype.forEach.call(body.children, function (card) {
        if (card.classList.contains('nb-cfield')) {
          var f = _readFieldCard(card, ri);
          if (f) fields.push(f);
        }
      });
    }
    return fields;
  }
  function _collectContainerRows(bodyEl) {
    if (!bodyEl) return [];
    var rows = [];
    Array.prototype.forEach.call(bodyEl.children, function (rowEl, ri) {
      if (rowEl.classList.contains('nb-inner-row') || rowEl.classList.contains('nb-row')) {
        var rowFields = [];
        var body = rowEl.querySelector('.nb-row-body');
        if (body) {
          Array.prototype.forEach.call(body.children, function (card) {
            if (card.classList.contains('nb-cfield')) {
              var f = _readFieldCard(card, ri);
              if (f) rowFields.push(f);
            }
          });
        }
        rows.push({ fields: rowFields });
      }
    });
    return rows;
  }
  function _collectTabPanelContent(bodyEl) {
    if (!bodyEl) return [];
    var result = [];
    Array.prototype.forEach.call(bodyEl.children, function (child, ri) {
      if (child.classList.contains('nb-inner-row') || child.classList.contains('nb-row')) {
        var rf = [];
        var body = child.querySelector('.nb-row-body');
        if (body) {
          Array.prototype.forEach.call(body.children, function (card) {
            if (card.classList.contains('nb-cfield')) {
              var f = _readFieldCard(card, ri);
              if (f) rf.push(f);
            }
          });
        }
        result.push({ fields: rf });
      } else if (child.classList.contains('nb-container-group') || (child.classList.contains('nb-container') && child.dataset.containerType === 'group')) {
        var li = child.querySelector('.nb-container-label-input');
        var groupBody = child.querySelector('.nb-container-group-body');
        result.push({
          type: 'group',
          label: li ? li.value : '',
          name: child.dataset.groupName || ('group_' + Date.now()),
          rows: _collectContainerRows(groupBody),
          col: 12,
          collapsible: child.dataset.collapsible === 'true',
          collapsed: child.dataset.collapsedByDefault === 'true',
          bg_color: child.dataset.bgColor || '',
          header_bg_color: child.dataset.headerBgColor || '',
          border_color: child.dataset.borderColor || '',
          text_color: child.dataset.textColor || '',
          border_style: child.dataset.borderStyle || '',
          border_width: child.dataset.borderWidth || ''
        });
      }
    });
    return result;
  }
  function _readFieldCard(card, rowIndex) {
  var t = card.dataset.type || 'text';

  var _val = function (sel) {
    var dsMap = {
      '.nu-field-label':       'fieldLabel',
      '.nu-field-name':        'fieldName',
      '.nu-field-placeholder': 'fieldPh',
      '.nu-field-default':     'fieldDefault',
      '.nu-field-help':        'fieldHelp',
      '.nu-field-custom-attrs':'fieldCustomAttrs'
    };
    var dsKey = dsMap[sel];

    if (dsKey && card.dataset[dsKey] !== undefined && card.dataset[dsKey] !== '') {
      return card.dataset[dsKey];
    }

    /* ── FIX: subform cards have no placeholder/default inputs — skip silently ── */
    if (t === 'subform' && (sel === '.nu-field-placeholder' || sel === '.nu-field-default')) {
      return '';
    }
    /* ── END FIX ── */

    var e = card.querySelector(sel);
    if (!e) {
      console.error('[nb-read] _val: no element for', sel, 'card.id=', card.id);
      return '';
    }
    var propVal = e.value;
    var attrVal = e.getAttribute('value');
    return (propVal !== undefined && propVal !== null && propVal !== '') ? propVal : (attrVal || '');
  };

  var _chk = function (sel) {
    var e = card.querySelector(sel);
    return !!(e && e.checked);
  };

  var sm   = card.querySelector('.nu-field-select-mode');
  var isMs = (t === 'select' || t === 'select2') && sm && sm.value === 'multi';

  var parentRow = card.closest('.nb-row');
  var rowAlign = (parentRow && parentRow.dataset.align) ? parentRow.dataset.align : 'left';

  var field = {
    type:                  t,
    label:                 _val('.nu-field-label'),
    name:                  _val('.nu-field-name'),
    required:              _chk('.nu-field-required'),
    no_duplicate:          _chk('.nu-field-no-duplicate'),
    readonly:              _chk('.nu-field-readonly'),
    hidden:                _chk('.nu-field-hidden'),
    hidden_for_normal_users: _chk('.nu-field-hidden-normal'),
    searchable:            card.querySelector('.nu-field-searchable') ? _chk('.nu-field-searchable') : true,
    hide_in_grid:          _chk('.nu-field-hide-in-grid'),
    placeholder:           _val('.nu-field-placeholder'),
    default_value:         _val('.nu-field-default'),
    help_text:             _val('.nu-field-help'),
    col:                   parseInt(card.dataset.col, 10) || 6,
    row_index:             (rowIndex !== undefined && rowIndex !== null) ? rowIndex : -1,
    row_align:             rowAlign,
    custom_attributes:     _val('.nu-field-custom-attrs')
  };

  if (t === 'select') {
    field.multiple    = isMs;
    field.select2     = false;
    field.select_type = isMs ? 'multiselect' : 'select';
  }
  if (t === 'select2') {
    field.select2     = true;
    field.multiple    = isMs;
    field.select_type = 'select2';
    field.allow_clear = _chk('.nu-field-allow-clear');
  }

  if (['select', 'select2', 'radio', 'checkbox_group'].indexOf(t) !== -1) {
    var osr = card.querySelector('.nu-field-opt-src:checked');
    var os  = osr ? osr.value : 'manual';
    field.options_source = os;
    if (os === 'table') {
      field.options_table      = _val('.nu-field-opt-table');
      field.options_value_col  = _val('.nu-field-opt-val-col');
      field.options_label_col  = _val('.nu-field-opt-label-col');
      field.options_filter     = _val('.nu-field-opt-filter');
      field.options            = [];
    } else {
      var oe = card.querySelector('.nu-field-options');
      field.options = oe
        ? oe.value.split('\n').map(function (l) {
            l = l.trim();
            if (!l) return null;
            var p = l.split('|');
            return p.length >= 2
              ? { value: p[0].trim(), label: p[1].trim() }
              : { value: l, label: l };
          }).filter(Boolean)
        : [];
    }
  }

  var fe = card.querySelector('.nu-field-formula');
  if (fe) field.formula = fe.value;

  if (t === 'lookup') {
    field.lookup = {
      table:             _val('.nu-lookup-table'),
      display_column:    _val('.nu-lookup-display') || 'name',
      id_column:         _val('.nu-lookup-store')   || 'id',
      additional_fields: _val('.nu-lookup-additional-fields') || '',
      filter:            _val('.nu-lookup-filter'),
      extra:             _val('.nu-lookup-extra')
    };
  }

  if (t === 'upload') {
    field.allowed_extensions = _val('.nu-upload-exts');
    field.max_size_mb         = _val('.nu-upload-maxsize');
  }

  if (t === 'photo_canvas') {
    field.canvas_width  = _val('.nu-canvas-width');
    field.canvas_height = _val('.nu-canvas-height');
  }

  if (t === 'subform') {
    field.subform = _readCardConfig(card);
  }

  if (t === 'group') {
    var childFields = [];
    var groupBody = card.querySelector('.nb-cfield-group-body');
    if (groupBody) {
      groupBody.querySelectorAll(':scope > .nb-cfield').forEach(function(subCard) {
        var subField = _readFieldCard(subCard, rowIndex);
        if (subField) childFields.push(subField);
      });
    }
    field.children = childFields;
  }

  if (t === 'tab') {
    var tabsData = [];
    var panes = card.querySelectorAll('.nb-cfield-tab-panes > .nb-cfield-tab-panel');
    var navs = card.querySelectorAll('.nb-cfield-tab-nav > .nb-cfield-tab-nav-item');
    panes.forEach(function(panel, idx) {
      var navBtn = navs[idx];
      var tabLabel = navBtn ? navBtn.textContent.trim() : ('Tab ' + (idx + 1));
      var tabFields = [];
      panel.querySelectorAll(':scope > .nb-cfield').forEach(function(subCard) {
        var subField = _readFieldCard(subCard, rowIndex);
        if (subField) tabFields.push(subField);
      });
      tabsData.push({
        name: tabLabel,
        label: tabLabel,
        rows: [{ fields: tabFields }]
      });
    });
    field.tabs = tabsData;
  }

  // Read any custom advanced properties dynamically from the DOM
  card.querySelectorAll('.nb-adv-prop').forEach(function (el) {
    var prop = el.getAttribute('data-prop');
    if (!prop) return;
    var val;
    if (el.type === 'checkbox') {
      val = !!el.checked;
    } else if (el.type === 'number') {
      val = el.value === '' ? '' : Number(el.value);
    } else {
      val = el.value;
    }
    field[prop] = val;
  });

  return field;
}

  /* ════════════════════════════════════════════════════════════════════
     Options source HTML
  ═══════════════════════════════════════════════════════════════════ */
  function _optionsSourceHTML(name, isFromTable, opts, extra) {
    extra = extra || {};
    return '<div class="nb-fp nb-fp-full" style="grid-column:1/-1;"><label style="font-size:11px;font-weight:600;">Options Source</label><div style="display:flex;gap:8px;margin-top:4px;"><label style="display:flex;align-items:center;gap:4px;cursor:pointer;font-size:12px;"><input type="radio" name="opt-src-' + _esc(name) + '" class="nu-field-opt-src" value="manual"' + (isFromTable ? '' : ' checked') + '> Manual</label><label style="display:flex;align-items:center;gap:4px;cursor:pointer;font-size:12px;"><input type="radio" name="opt-src-' + _esc(name) + '" class="nu-field-opt-src" value="table"' + (isFromTable ? ' checked' : '') + '> From table</label></div></div>'
      + '<div class="nb-select-manual" style="' + (isFromTable ? 'display:none;' : '') + 'grid-column:1/-1;"><label style="font-size:11px;font-weight:600;">Options (value|label per line)</label><textarea class="nu-input nu-field-options" rows="3" style="width:100%;box-sizing:border-box;">' + _esc(opts) + '</textarea></div>'
      + '<div class="nb-select-from-table" style="' + (isFromTable ? '' : 'display:none;') + 'grid-column:1/-1;"><div style="background:var(--bg-offset,#f0f4ff);border:1.5px solid var(--color-primary,#4f6bed);border-radius:8px;padding:12px 14px;"><div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;"><div style="grid-column:1/-1;"><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Table Name</label><input type="text" class="nu-input nu-field-opt-table" value="' + _esc(extra.options_table || '') + '" placeholder="e.g. categories" style="font-size:12px;width:100%;box-sizing:border-box;"></div><div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Value Col</label><input type="text" class="nu-input nu-field-opt-val-col" value="' + _esc(extra.options_value_col || '') + '" placeholder="e.g. id" style="font-size:12px;"></div><div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Label Col</label><input type="text" class="nu-input nu-field-opt-label-col" value="' + _esc(extra.options_label_col || '') + '" placeholder="e.g. name" style="font-size:12px;"></div><div style="grid-column:1/-1;"><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Filter SQL</label><input type="text" class="nu-input nu-field-opt-filter" value="' + _esc(extra.options_filter || '') + '" placeholder="e.g. active=1" style="font-size:12px;width:100%;box-sizing:border-box;"></div></div></div></div>';
  }


  /* ════════════════════════════════════════════════════════════════════
     Drop wiring
  ═══════════════════════════════════════════════════════════════════ */
  if (!window.nuToggleContainer) {
    window.nuToggleContainer = function (btn) {
      if (!btn) return;
      var body = document.getElementById(btn.getAttribute('data-target')); if (!body) return;
      var hidden = body.style.display === 'none' || body.style.display === '';
      body.style.display = hidden ? 'block' : 'none';
      if (btn.classList.contains('nu-group-toggle')) {
        btn.innerHTML = hidden ? '−' : '+';
      } else {
        btn.innerHTML = hidden ? '&#9660;' : '&#9654;';
      }
    };
  }

  function _attachSelectOptionsToggle(card) {
    var radios = card.querySelectorAll('.nu-field-opt-src');
    var mp = card.querySelector('.nb-select-manual'); var tp = card.querySelector('.nb-select-from-table');
    if (!radios.length || !mp || !tp) return;
    radios.forEach(function (r) {
      r.addEventListener('change', function () { mp.style.display = (r.value === 'table' && r.checked) ? 'none' : ''; tp.style.display = (r.value === 'table' && r.checked) ? '' : 'none'; });
    });
    var checked = card.querySelector('.nu-field-opt-src:checked');
    if (checked) { mp.style.display = checked.value === 'table' ? 'none' : ''; tp.style.display = checked.value === 'table' ? '' : 'none'; }
  }

  function _attachRowBodyDrop(rowBody) {
    if (rowBody._nuDropPatched) return;
    rowBody._nuDropPatched = true;
    rowBody.addEventListener('dragover', function (e) {
      if (e.dataTransfer.types && Array.prototype.indexOf.call(e.dataTransfer.types, 'text/nb-row-id') !== -1) return;
      e.preventDefault(); e.stopPropagation(); rowBody.classList.add('drag-col-over');
    });
    rowBody.addEventListener('dragleave', function (e) { if (!rowBody.contains(e.relatedTarget)) rowBody.classList.remove('drag-col-over'); });
    rowBody.addEventListener('drop', function (e) {
      if (e.dataTransfer.types && Array.prototype.indexOf.call(e.dataTransfer.types, 'text/nb-row-id') !== -1) return;
      e.preventDefault(); e.stopPropagation(); rowBody.classList.remove('drag-col-over');
      var cardId = e.dataTransfer.getData('text/nb-card-id');
      if (cardId) {
        var existing = document.getElementById(cardId);
        if (existing) { var oldRow = existing.closest('.nb-row'); rowBody.appendChild(existing); window.nbFormBuilder._applyColSpan(existing, existing.dataset.col || 6); if (oldRow && !oldRow.querySelector('.nb-cfield')) oldRow.remove(); window.nbFormBuilder._updateEmptyState(); return; }
      }
      var dtype = e.dataTransfer.getData('text/nb-type') || e.dataTransfer.getData('text/plain');
       if (dtype && dtype !== 'group' && dtype !== 'tab') {
        var nc = window.nbFormBuilder._makeFieldCard(dtype, dtype + ' field', dtype + '_' + Date.now(), false, { col: 6 });
        if (nc) { _prepCard(nc); var hint = rowBody.querySelector('.nb-row-drop-hint'); if (hint) hint.remove(); rowBody.appendChild(nc); window.nbFormBuilder._applyColSpan(nc, 6); }
        window.nbFormBuilder._updateEmptyState();
      }
    });
  }

  function _attachAllRowDrops() {
    document.querySelectorAll('.nb-row-body').forEach(_attachRowBodyDrop);
  }

  document.addEventListener('dragstart', function (e) {
    var tool = e.target.closest ? e.target.closest('.nb-tool[data-type]') : null;
    if (tool) e.dataTransfer.setData('text/nb-type', tool.dataset.type);
    var card = e.target.closest ? e.target.closest('.nb-cfield[id]') : null;
    if (card) { if (!card.id) card.id = 'nb-card-' + Date.now(); e.dataTransfer.setData('text/nb-card-id', card.id); }
  }, true);


  /* ════════════════════════════════════════════════════════════════════
     Subform FK panel
  ═══════════════════════════════════════════════════════════════════ */
  function _subformPanelHTML(d) {
    var isFk=d.is_fk?'checked':'';
    var hg=d.hide_in_grid?'checked':'';
    var sr=d.server_readonly?'checked':'';
    var searchable=(d.searchable !== false && d.searchable !== 'false' && d.searchable !== 0 && d.searchable !== '0') ? 'checked' : '';
    var selectedOnly=d.selected_fields_only?'checked':'';
    var vg=(!d.subform_view||d.subform_view==='grid')?'selected':'';
    var vf=(d.subform_view==='form')?'selected':'';
    var vi=(d.subform_view==='inline')?'selected':'';

    function _tr(cls,dk,ca,lbl,hint){
      return '<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:12px;"><input type="checkbox" class="'+cls+'" data-fk-flag="'+dk+'" '+ca+'><span><strong>'+lbl+'</strong>'+(hint?' <span style="color:var(--text-muted,#999);font-size:11px;">— '+hint+'</span>':'')+'</span></label>';
    }

    var viewRolesHtml = '';
    var editRolesHtml = '';
    var viewRolesArr = (d.view_roles || '').split(',').map(function(s){return s.trim();}).filter(Boolean);
    var editRolesArr = (d.edit_roles || '').split(',').map(function(s){return s.trim();}).filter(Boolean);

    if (window.nuRolesList && window.nuRolesList.length) {
      window.nuRolesList.forEach(function (r) {
        var vSel = viewRolesArr.indexOf(r.role_code) !== -1 ? 'checked' : '';
        var eSel = editRolesArr.indexOf(r.role_code) !== -1 ? 'checked' : '';
        viewRolesHtml += '<label style="display:inline-flex;align-items:center;gap:4px;margin-right:10px;font-size:11px;cursor:pointer;"><input type="checkbox" class="nb-sf-view-role-chk" value="'+r.role_code+'" '+vSel+'> '+r.role_name+'</label>';
        editRolesHtml += '<label style="display:inline-flex;align-items:center;gap:4px;margin-right:10px;font-size:11px;cursor:pointer;"><input type="checkbox" class="nb-sf-edit-role-chk" value="'+r.role_code+'" '+eSel+'> '+r.role_name+'</label>';
      });
    } else {
      viewRolesHtml = '<span style="font-size:11px;color:#999;">No roles available</span>';
      editRolesHtml = '<span style="font-size:11px;color:#999;">No roles available</span>';
    }

    return [
      '<div class="nb-sf-fk-panel" style="display:flex;flex-direction:column;gap:8px;padding:10px 0;grid-column:1/-1;">',
      '<div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Child Form</label><select class="nu-input nb-sf-form-code" style="width:100%;"><option value="">— select form —</option></select></div>',
      '<div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">FK Field</label><div style="display:flex;gap:6px;"><select class="nu-input nb-sf-fk-field" style="flex:1;"><option value="">— select FK field —</option></select><button type="button" class="nu-btn nu-btn-ghost nu-btn-sm nb-sf-create-fk">＋ Create FK Field</button></div></div>',
      '<div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Display Mode</label><select class="nu-input nb-sf-view" style="width:100%;"><option value="grid" '+vg+'>Grid (table)</option><option value="form" '+vf+'>Form (stacked)</option><option value="inline" '+vi+'>Inline Edit</option></select></div>',

      '<div style="display:flex;flex-direction:column;gap:4px;padding:6px 8px;background:var(--bg-elevated,#f8f9fa);border-radius:6px;border:1px solid var(--border,#e0e0e0);"><label style="font-size:11px;font-weight:700;color:var(--text-muted,#888);text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;">FK Field Flags</label>',
      _tr('nb-sf-is-fk','is_fk',isFk,'FK field','Force hidden; builder locks this field'),
      _tr('nb-sf-hide-in-grid','hide_in_grid',hg,'Hide in grid','Excludes column from subform table'),
      _tr('nb-sf-server-readonly','server_readonly',sr,'Server readonly','PHP ignores POST value; always writes parent ID'),
      '</div>',

      '<div style="display:flex;flex-direction:column;gap:4px;padding:6px 8px;background:var(--bg-elevated,#f8f9fa);border-radius:6px;border:1px solid var(--border,#e0e0e0);"><label style="font-size:11px;font-weight:700;color:var(--text-muted,#888);text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;">Subform Features</label>',
      _tr('nb-sf-searchable','searchable',searchable,'Searchable','Show search box in subform toolbar'),
      _tr('nb-sf-selected-fields-only','selected_fields_only',selectedOnly,'Show Selected Fields Only','Only show fields with hide_in_grid=false inside the subform layout'),
      '</div>',

      '<div style="display:flex;flex-direction:column;gap:6px;padding:6px 8px;background:var(--bg-elevated,#f8f9fa);border-radius:6px;border:1px solid var(--border,#e0e0e0);">',
      '<label style="font-size:11px;font-weight:700;color:var(--text-muted,#888);text-transform:uppercase;letter-spacing:.5px;">Subform View Access Roles</label>',
      '<div style="display:flex;flex-wrap:wrap;gap:2px 6px;">' + viewRolesHtml + '</div>',
      '</div>',

      '<div style="display:flex;flex-direction:column;gap:6px;padding:6px 8px;background:var(--bg-elevated,#f8f9fa);border-radius:6px;border:1px solid var(--border,#e0e0e0);">',
      '<label style="font-size:11px;font-weight:700;color:var(--text-muted,#888);text-transform:uppercase;letter-spacing:.5px;">Subform Edit Access Roles</label>',
      '<div style="display:flex;flex-wrap:wrap;gap:2px 6px;">' + editRolesHtml + '</div>',
      '</div>',

      '</div>'
    ].join('');
  }
  function _attachSubformPanelEvents(card, initialData) {
    var panel = card.querySelector('.nb-sf-fk-panel'); if (!panel) return;
    var d = initialData || {};
    _populateFormDropdown(panel, d.form_code || '', function () { if (d.form_code) _populateFkDropdown(panel, d.form_code, d.fk_field || ''); });
    var viewSel = panel.querySelector('.nb-sf-view'); if (viewSel && d.subform_view) viewSel.value = d.subform_view;
    var formSel = panel.querySelector('.nb-sf-form-code'); if (formSel) formSel.addEventListener('change', function () { _populateFkDropdown(panel, formSel.value, ''); });
    var createBtn = panel.querySelector('.nb-sf-create-fk'); if (createBtn) createBtn.addEventListener('click', function () { _createFkField(panel); });
  }
  function _populateFormDropdown(panel, selectedCode, cb) {
    var sel = panel.querySelector('.nb-sf-form-code'); if (!sel) { if (cb) cb(); return; }
    fetch('api/forms.php?action=list', { credentials:'same-origin' })
    
          .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.forms) { if (cb) cb(); return; }
        sel.innerHTML = '<option value="">— select form —</option>';
        res.forms.forEach(function (f) {
          var opt = document.createElement('option');
          opt.value = f.form_code || f.code || '';
          opt.textContent = (f.form_name || f.name || '') + ' (' + opt.value + ')';
          if (opt.value === selectedCode) opt.selected = true;
          sel.appendChild(opt);
        });
        if (cb) cb();
      })
      .catch(function () { if (cb) cb(); });
  }

 function _populateFkDropdown(panel, formCode, selectedField, cb) {
  var sel = panel.querySelector('.nb-sf-fk-field');
  console.group('[nb-fk] _populateFkDropdown');
  console.log('  panel         :', panel);
  console.log('  formCode      :', formCode);
  console.log('  selectedField :', selectedField);
  console.log('  sel found     :', !!sel);
  console.groupEnd();

  if (!sel) { if (cb) cb(); return; }
  sel.innerHTML = '<option value="">— select FK field —</option>';
  if (!formCode) { if (cb) cb(); return; }

  fetch('api/forms.php?action=get_fields&code=' + encodeURIComponent(formCode), { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (res) {
      console.group('[nb-fk] _populateFkDropdown fetch response  formCode=' + formCode);
      console.log('  raw res       :', res);
      console.log('  res.fields    :', res && res.fields);
      console.groupEnd();

      if (!res || !res.fields) {
        console.warn('[nb-fk] No fields returned for formCode=', formCode);
        if (cb) cb();
        return;
      }

      res.fields.forEach(function (f) {
        var opt = document.createElement('option');
        opt.value = f.name || f.fieldname || '';
        opt.textContent = (f.label || f.fieldlabel || opt.value) + ' (' + opt.value + ')';
        if (opt.value === selectedField) {
          opt.selected = true;
          console.log('[nb-fk] matched selectedField=', selectedField, 'opt.value=', opt.value);
        }
        sel.appendChild(opt);
      });

      console.log('[nb-fk] after populate: sel.value=', sel.value,
                  '| sel.options.length=', sel.options.length,
                  '| wanted=', selectedField);

      if (selectedField && sel.value !== selectedField) {
        console.warn('[nb-fk] selectedField NOT in options — option was never appended or value mismatch.',
                     'selectedField=', selectedField,
                     'options=', Array.prototype.map.call(sel.options, function(o){ return o.value; }));
      }

      if (cb) cb(); // ← FIXED: moved OUTSIDE forEach, called once after all options added
    })
    .catch(function (err) {
      console.error('[nb-fk] fetch error for formCode=', formCode, err);
      if (cb) cb();
    });
}
  function _createFkField(panel) {
    var formSel = panel.querySelector('.nb-sf-form-code');
    var fkSel   = panel.querySelector('.nb-sf-fk-field');
    if (!formSel || !formSel.value) { NuApp.toast('Select a child form first', 'error'); return; }
    var parentCode = (document.getElementById('builderFormCode') || {}).value || '';
    if (!parentCode) { NuApp.toast('Save the parent form first so it has a form code', 'error'); return; }
    NuApp.apiJson('api/forms.php?action=create_fk_field', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ child_form_code: formSel.value, fk_to_form_code: parentCode })
    }).then(function (res) {
      if (!res || !res.success) { NuApp.toast((res && res.error) || 'Could not create FK field', 'error'); return; }
      NuApp.toast('FK field created: ' + (res.field_name || ''), 'success');
      _populateFkDropdown(panel, formSel.value, res.field_name || '');
    }).catch(function (err) { NuApp.toast('Error: ' + err.message, 'error'); });
  }

  function _readCardConfig(card) {
  /* Prefer dataset (written by _nbSfData.write + _renderSubformPanel sync) */
  var dsCode = card.dataset.sfFormCode    || '';
  var dsFk   = card.dataset.sfFkField     || '';
  var dsView = card.dataset.sfSubformView || 'grid';
  var dsHelp = card.dataset.sfHelpText    || '';
  var dsIsFk = card.dataset.sfIsFk        === '1';
  var dsHide = card.dataset.sfHideInGrid  === '1';
  var dsSrv  = card.dataset.sfServerReadonly === '1';
  var dsSearchable = card.dataset.sfSearchable !== '0';
  var dsSelectedOnly = card.dataset.sfSelectedFieldsOnly === '1';
  var dsViewRoles = card.dataset.sfViewRoles || '';
  var dsEditRoles = card.dataset.sfEditRoles || '';

  /* If dataset populated, use it — avoids reading unpopulated hidden selects */
  if (dsCode) {
    return {
      form_code: dsCode,
      fk_field: dsFk,
      subform_view: dsView,
      help_text: dsHelp,
      is_fk: dsIsFk,
      hide_in_grid: dsHide,
      server_readonly: dsSrv,
      searchable: dsSearchable,
      selected_fields_only: dsSelectedOnly,
      view_roles: dsViewRoles,
      edit_roles: dsEditRoles
    };
  }

  /* Fallback: read from hidden body panel selects */
  var panel = card.querySelector('.nb-sf-fk-panel');
  if (!panel) return { form_code:'', fk_field:'', subform_view:'grid', help_text:'', is_fk:false, hide_in_grid:false, server_readonly:false, searchable:true, selected_fields_only:false, view_roles:'', edit_roles:'' };
  var fc   = panel.querySelector('.nb-sf-form-code');
  var fk   = panel.querySelector('.nb-sf-fk-field');
  var view = panel.querySelector('.nb-sf-view');
  var ht   = card.querySelector('.nu-field-help');

  var viewRoles = [];
  panel.querySelectorAll('.nb-sf-view-role-chk:checked').forEach(function (chk) { viewRoles.push(chk.value); });
  var editRoles = [];
  panel.querySelectorAll('.nb-sf-edit-role-chk:checked').forEach(function (chk) { editRoles.push(chk.value); });

  return {
    form_code:       fc   ? fc.value   : '',
    fk_field:        fk   ? fk.value   : '',
    subform_view:    view ? view.value : 'grid',
    help_text:       ht   ? ht.value   : '',
    is_fk:           !!(panel.querySelector('.nb-sf-is-fk')          && panel.querySelector('.nb-sf-is-fk').checked),
    hide_in_grid:    !!(panel.querySelector('.nb-sf-hide-in-grid')    && panel.querySelector('.nb-sf-hide-in-grid').checked),
    server_readonly: !!(panel.querySelector('.nb-sf-server-readonly') && panel.querySelector('.nb-sf-server-readonly').checked),
    searchable:      !(panel.querySelector('.nb-sf-searchable')      && !panel.querySelector('.nb-sf-searchable').checked),
    selected_fields_only: !!(panel.querySelector('.nb-sf-selected-fields-only') && panel.querySelector('.nb-sf-selected-fields-only').checked),
    view_roles:      viewRoles.join(','),
    edit_roles:      editRoles.join(',')
  };
}

  /* ════════════════════════════════════════════════════════════════════
     Keyboard + click-outside close panel
  ═══════════════════════════════════════════════════════════════════ */
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') _closePropsPanel();
  });

  document.addEventListener('mousedown', function (e) {
    var panel = document.getElementById('nb-props-panel');
    if (!panel || !panel.classList.contains('open')) return;
    if (panel.contains(e.target)) return;
    if (e.target.closest && e.target.closest('.nb-cfield')) return;
    _closePropsPanel();
  });


  /* ════════════════════════════════════════════════════════════════════
     Reusable Component Blocks
  ═══════════════════════════════════════════════════════════════════ */
  function _insertReusableBlock(blockType, targetRowBody) {
    var fields = [];
    if (blockType === 'block_address') {
      fields = [
        { type: 'text', label: 'Street Address', name: 'street_address', col: 12 },
        { type: 'text', label: 'City', name: 'city', col: 6 },
        { type: 'text', label: 'State / Province', name: 'state_province', col: 3 },
        { type: 'text', label: 'ZIP / Postal Code', name: 'zip_code', col: 3 },
        { type: 'text', label: 'Country', name: 'country', col: 12 }
      ];
    } else if (blockType === 'block_contact') {
      fields = [
        { type: 'text', label: 'Primary Phone', name: 'primary_phone', col: 6 },
        { type: 'text', label: 'Secondary Phone', name: 'secondary_phone', col: 6 },
        { type: 'email', label: 'Work Email', name: 'work_email', col: 6 },
        { type: 'select', label: 'Preferred Contact Method', name: 'contact_method', col: 6, select_options: 'Email\nPhone\nSMS' }
      ];
    } else if (blockType === 'block_billing') {
      fields = [
        { type: 'text', label: 'Billing Street Address', name: 'billing_address', col: 12 },
        { type: 'text', label: 'Billing ZIP Code', name: 'billing_zip', col: 4 },
        { type: 'text', label: 'Cardholder Name', name: 'cardholder_name', col: 8 }
      ];
    }
    if (!fields.length) return;

    var row = targetRowBody ? targetRowBody.closest('.nb-row') : window.nbFormBuilder.addRow();
    var rb = row ? row.querySelector('.nb-row-body') : null;
    if (!rb) return;

    var hint = rb.querySelector('.nb-row-drop-hint'); if (hint) hint.remove();

    fields.forEach(function (f) {
      var nc = window.nbFormBuilder._makeFieldCard(f.type, f.label, f.name + '_' + Math.random().toString(36).slice(2, 6), false, { col: f.col });
      if (nc) {
        _prepCard(nc);
        rb.appendChild(nc);
        window.nbFormBuilder._applyColSpan(nc, f.col);
        if (f.select_options) {
          var optsArea = nc.querySelector('.nb-cfield-select-options');
          if (optsArea) optsArea.value = f.select_options;
        }
      }
    });
    window.nbFormBuilder._updateEmptyState();
    window.nbFormBuilder._isDirty = true;
    NuApp.toast('Reusable block added!', 'success');
  }

  /* ════════════════════════════════════════════════════════════════════
     Visual Browse Designer Helper Behaviors
  ═══════════════════════════════════════════════════════════════════ */
  function _makeBrowseColumnsReorderable() {
    var list = document.getElementById('browseColumnsList');
    if (!list) return;

    list.addEventListener('dragstart', function (e) {
      var row = e.target.closest('.nb-browse-column-row');
      if (row) {
        window._draggedBrowseColumnRow = row;
        e.dataTransfer.effectAllowed = 'move';
      }
    });

    list.addEventListener('dragover', function (e) {
      e.preventDefault();
      var row = e.target.closest('.nb-browse-column-row');
      if (row && row !== window._draggedBrowseColumnRow) {
        var rect = row.getBoundingClientRect();
        var next = (e.clientY - rect.top) > (rect.height / 2);
        list.insertBefore(window._draggedBrowseColumnRow, next ? row.nextSibling : row);
      }
    });

    list.addEventListener('dragend', function (e) {
      window._draggedBrowseColumnRow = null;
      window.nbFormBuilder._isDirty = true;
    });
  }

  function _getAvailableFieldsList() {
    var canvas = document.getElementById('formCanvas');
    if (!canvas) return [];
    var list = [];
    canvas.querySelectorAll('.nb-cfield').forEach(function (card) {
      var type = card.dataset.type;
      if (type === 'group' || type === 'tab') return;
      var nameInput = card.querySelector('[data-prop="name"]') || card.querySelector('input[name]');
      var name = nameInput ? nameInput.value : '';
      if (!name) {
        name = card.dataset.name || card.id;
      }
      var labelInput = card.querySelector('[data-prop="label"]') || card.querySelector('label');
      var label = labelInput ? (labelInput.value || labelInput.textContent) : name;
      if (name) {
        list.push({ name: name, label: label });
      }
    });
    ['id', 'created_at', 'updated_at', 'user_id', 'location'].forEach(function (sf) {
      if (!list.some(function (f) { return f.name === sf; })) {
        list.push({ name: sf, label: sf.charAt(0).toUpperCase() + sf.slice(1).replace('_', ' ') });
      }
    });

    // Dynamically populate colFieldDatalist so the editable inputs have autocomplete options!
    var dl = document.getElementById('colFieldDatalist');
    if (dl) {
      dl.innerHTML = '';
      list.forEach(function (f) {
        var opt = document.createElement('option');
        opt.value = f.name;
        opt.textContent = f.label;
        dl.appendChild(opt);
      });
    }
    return list;
  }

  // Extend nbFormBuilder object with the new designer and revisions methods
  window.nbFormBuilder.addBrowseColumnDesignerRow = function (data) {
    var d = data || { fieldname: '', fieldlabel: '', width: '', align: 'left', formatter: 'text', sortable: true, frozen: false, aggregate: 'none', rules: [] };
    var list = document.getElementById('browseColumnsList');
    if (!list) return;

    // Convert old single cond block to multi rules list
    if (!d.rules && d.cond_op) {
      d.rules = [{ op: d.cond_op, val: d.cond_val, fg: d.cond_fg, bg: d.cond_bg }];
    }
    if (!d.rules) d.rules = [];

    var row = document.createElement('div');
    row.className = 'nb-browse-column-row';
    row.setAttribute('draggable', 'true');
    row.style.cssText = 'display:grid;grid-template-columns:1.5fr 1.5fr 1fr 1fr 1fr 1.2fr auto;gap:8px;align-items:center;background:var(--bg-surface);padding:10px;border-radius:6px;border:1px solid var(--border-color);margin-bottom:6px;';

    var alignLeft = d.align === 'left' ? 'selected' : '';
    var alignCenter = d.align === 'center' ? 'selected' : '';
    var alignRight = d.align === 'right' ? 'selected' : '';

    var aggNone = (d.aggregate === 'none' || !d.aggregate) ? 'selected' : '';
    var aggSum = d.aggregate === 'sum' ? 'selected' : '';
    var aggAvg = d.aggregate === 'avg' ? 'selected' : '';
    var aggCount = d.aggregate === 'count' ? 'selected' : '';

    var formatters = ['text', 'currency', 'badge', 'progress_bar', 'date', 'checkbox_toggle', 'image', 'html', 'custom_button'];
    var formatOpts = '';
    formatters.forEach(function (fmt) {
      var sel = (d.formatter === fmt) ? ' selected' : '';
      var lbl = fmt.replace('_', ' ').toUpperCase();
      formatOpts += '<option value="' + fmt + '"' + sel + '>' + lbl + '</option>';
    });

    var isSortable = d.sortable ? 'checked' : '';
    var isFrozen = d.frozen ? 'checked' : '';

    row.innerHTML = `
      <div style="display:flex;align-items:center;gap:4px;">
        <span class="nb-column-drag-handle" style="cursor:grab;color:var(--text-tertiary);font-size:16px;">☰</span>
        <input type="text" class="nu-input nb-col-field" list="colFieldDatalist" style="width:100%;font-size:12px;padding:4px 6px;" placeholder="FieldName / JoinedColumn" value="${(d.fieldname || '').replace(/"/g, '&quot;')}">
      </div>
      <div>
        <input type="text" class="nu-input nb-col-label" placeholder="Display Label" value="${(d.fieldlabel || '').replace(/"/g, '&quot;')}" style="font-size:12px;padding:4px 6px;">
      </div>
      <div>
        <input type="text" class="nu-input nb-col-width" placeholder="Width (e.g. 150px)" value="${(d.width || '').replace(/"/g, '&quot;')}" style="font-size:12px;padding:4px 6px;">
      </div>
      <div>
        <select class="nu-input nb-col-align" style="font-size:12px;padding:4px 6px;">
          <option value="left" ${alignLeft}>LEFT</option>
          <option value="center" ${alignCenter}>CENTER</option>
          <option value="right" ${alignRight}>RIGHT</option>
        </select>
      </div>
      <div>
        <select class="nu-input nb-col-format" style="font-size:12px;padding:4px 6px;">${formatOpts}</select>
      </div>
      <div>
        <select class="nu-input nb-col-aggregate" style="font-size:12px;padding:4px 6px;" title="Aggregation Function">
          <option value="none" ${aggNone}>NONE</option>
          <option value="sum" ${aggSum}>SUM</option>
          <option value="avg" ${aggAvg}>AVG</option>
          <option value="count" ${aggCount}>COUNT</option>
        </select>
      </div>
      <div style="display:flex;gap:10px;align-items:center;">
        <label style="font-size:11px;display:flex;align-items:center;gap:2px;cursor:pointer;" title="Make column sortable">
          <input type="checkbox" class="nb-col-sortable" ${isSortable}> Sort
        </label>
        <label style="font-size:11px;display:flex;align-items:center;gap:2px;cursor:pointer;" title="Pin column to left side">
          <input type="checkbox" class="nb-col-frozen" ${isFrozen}> Pin
        </label>
        <button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" style="padding:2px 4px;font-size:11px;" onclick="window.nbFormBuilder.toggleColumnConditionalRule(this)" title="Conditional Formatting">🎨 Rule</button>
        <button type="button" class="nu-btn nu-btn-danger nu-btn-sm" style="padding:2px 6px;" onclick="this.closest('.nb-browse-column-row').remove()">✕</button>
      </div>

      <div class="nb-col-cond-section" style="display:${d.rules.length > 0 ? 'block' : 'none'};grid-column:1/-1;background:var(--bg-offset);padding:10px;border-radius:6px;border:1px solid var(--border-color);margin-top:6px;">
        <div style="font-size:11px;font-weight:600;margin-bottom:6px;color:var(--text-secondary);">Conditional formatting rules:</div>
        <div class="nb-col-rules-list"></div>
        <button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" style="font-size:10px;padding:2px 6px;margin-top:4px;" onclick="window.nbFormBuilder.addColumnConditionalRuleRow(this)">+ Add Rule</button>
      </div>

      <div class="nb-col-btn-section" style="display:${d.formatter === 'custom_button' ? 'grid' : 'none'};grid-column:1/-1;grid-template-columns:1fr 1fr 2fr;gap:8px;background:var(--bg-offset);padding:10px;border-radius:6px;border:1px solid var(--border-color);margin-top:6px;">
        <div>
          <label style="font-size:10px;color:var(--text-secondary);display:block;margin-bottom:2px;">Button Label</label>
          <input type="text" class="nu-input nb-col-btn-label" value="${(d.btn_label || 'Assign').replace(/"/g, '&quot;')}" style="font-size:11px;padding:2px 4px;">
        </div>
        <div>
          <label style="font-size:10px;color:var(--text-secondary);display:block;margin-bottom:2px;">CSS Class</label>
          <input type="text" class="nu-input nb-col-btn-class" value="${(d.btn_class || 'nu-btn nu-btn-primary nu-btn-sm').replace(/"/g, '&quot;')}" style="font-size:11px;padding:2px 4px;">
        </div>
        <div>
          <label style="font-size:10px;color:var(--text-secondary);display:block;margin-bottom:2px;">OnClick JS (has access to <code>row</code>, <code>selection</code>, <code>nu</code>)</label>
          <textarea class="nu-input nb-col-btn-js" style="font-size:11px;padding:4px;height:70px;font-family:monospace;width:100%;" placeholder="e.g. nu.toast('ID: ' + row.id)">${d.btn_js || ""}</textarea>
        </div>
        <div style="grid-column:1/-1;display:flex;align-items:center;gap:6px;margin-top:4px;">
          <label style="font-size:11px;font-weight:600;display:flex;align-items:center;gap:4px;cursor:pointer;">
            <input type="checkbox" class="nb-col-btn-has-lookup" ${d.btn_has_lookup ? 'checked' : ''}> Open Selection Lookup Modal first (e.g. choose a user to assign)
          </label>
        </div>
        <div class="nb-col-btn-lookup-config" style="display:${d.btn_has_lookup ? 'grid' : 'none'};grid-column:1/-1;grid-template-columns:1fr 1fr 1fr 2fr;gap:6px;margin-top:4px;border-top:1px dashed var(--border-color);padding-top:8px;">
          <div>
            <label style="font-size:10px;color:var(--text-secondary);display:block;margin-bottom:2px;">Lookup Table</label>
            <input type="text" class="nu-input nb-col-btn-table" value="${(d.btn_table || 'nu_users').replace(/"/g, '&quot;')}" style="font-size:11px;padding:2px 4px;">
          </div>
          <div>
            <label style="font-size:10px;color:var(--text-secondary);display:block;margin-bottom:2px;">ID Column</label>
            <input type="text" class="nu-input nb-col-btn-id" value="${(d.btn_id || 'usr_id').replace(/"/g, '&quot;')}" style="font-size:11px;padding:2px 4px;">
          </div>
          <div>
            <label style="font-size:10px;color:var(--text-secondary);display:block;margin-bottom:2px;">Display Column</label>
            <input type="text" class="nu-input nb-col-btn-disp" value="${(d.btn_disp || 'usr_name').replace(/"/g, '&quot;')}" style="font-size:11px;padding:2px 4px;">
          </div>
          <div>
            <label style="font-size:10px;color:var(--text-secondary);display:block;margin-bottom:2px;">Where Filter (e.g. <code>location = '##location##'</code>)</label>
            <input type="text" class="nu-input nb-col-btn-where" value="${(d.btn_where || "location = '##location##'").replace(/"/g, '&quot;')}" style="font-size:11px;padding:2px 4px;">
          </div>
        </div>
      </div>
    `;
    list.appendChild(row);

    // Toggle custom button config panel
    row.querySelector('.nb-col-format').addEventListener('change', function (e) {
      var btnSec = row.querySelector('.nb-col-btn-section');
      if (btnSec) btnSec.style.display = (e.target.value === 'custom_button') ? 'grid' : 'none';
    });

    // Toggle custom button lookup config panel
    row.querySelector('.nb-col-btn-has-lookup').addEventListener('change', function (e) {
      var configSec = row.querySelector('.nb-col-btn-lookup-config');
      if (configSec) configSec.style.display = e.target.checked ? 'grid' : 'none';

      // Auto-populate default template if OnClick JS is empty
      var jsText = row.querySelector('.nb-col-btn-js');
      if (jsText && !jsText.value.trim()) {
        jsText.value = [
          "// 1. Assign selected user to this record",
          "nu.apiJson('api/crud.php?table=' + encodeURIComponent(row._form_table || 'tasks') + '&id=' + row.id, {",
          "  method: 'POST',",
          "  headers: { 'Content-Type': 'application/json' },",
          "  body: JSON.stringify({ assigned_user_id: selection.id })",
          "}).then(res => {",
          "  if (res.success) {",
          "    nu.toast('Successfully assigned to ' + selection.display + '!', 'success');",
          "    ",
          "    // 2. Send email notification via SMTP Templates",
          "    nu.apiJson('api/email.php?action=send_template', {",
          "      method: 'POST',",
          "      headers: { 'Content-Type': 'application/json' },",
          "      body: JSON.stringify({",
          "        template: 'task_assigned',",
          "        to: selection.usr_email || 'user@example.com',",
          "        data: { task_id: row.id, user_name: selection.display }",
          "      })",
          "    });",
          "    ",
          "    // 3. Refresh browse list",
          "    nu.browseForm(row._form_code, 1, '', row._form_label, 'inline');",
          "  }",
          "});"
        ].join('\\n');
      }
    });

    // Render rules list
    var condSection = row.querySelector('.nb-col-cond-section');
    d.rules.forEach(function (r) {
      window.nbFormBuilder.addColumnConditionalRuleRow(condSection, r.op, r.val, r.fg, r.bg);
    });

    // Fire datalist populate check
    _getAvailableFieldsList();

    var select = row.querySelector('.nb-col-field');
    if (select && !d.fieldname) select.focus();
  };

  window.nbFormBuilder.toggleColumnConditionalRule = function (btn) {
    var row = btn.closest('.nb-browse-column-row');
    var section = row.querySelector('.nb-col-cond-section');
    if (section) {
      if (section.style.display === 'none') {
        section.style.display = 'block';
        window.nbFormBuilder.addColumnConditionalRuleRow(section);
      } else {
        section.style.display = 'none';
        section.querySelector('.nb-col-rules-list').innerHTML = '';
      }
    }
  };

  window.nbFormBuilder.addColumnConditionalRuleRow = function (btn, op, val, fg, bg) {
    var section = btn.closest ? btn.closest('.nb-col-cond-section') : btn;
    var list = section.querySelector('.nb-col-rules-list');
    if (!list) return;

    var o = op || '';
    var v = val || '';
    var f = fg || '#15803d'; // Default beautiful green
    var b = bg || '#dcfce7'; // Default beautiful light green

    var row = document.createElement('div');
    row.className = 'nb-col-rule-row';
    row.style.cssText = 'display:grid;grid-template-columns:1fr 1fr 1fr 1fr auto;gap:6px;align-items:center;background:var(--bg-card);padding:6px;border-radius:4px;border:1px solid var(--border-color);margin-bottom:4px;';

    row.innerHTML = `
      <div>
        <select class="nu-input nb-col-cond-op" style="font-size:11px;padding:2px 4px;">
          <option value="">— select op —</option>
          <option value="=" ${o === '=' ? 'selected' : ''}>Equals</option>
          <option value="!=" ${o === '!=' ? 'selected' : ''}>Not Equals</option>
          <option value=">" ${o === '>' ? 'selected' : ''}>Greater Than</option>
          <option value="<" ${o === '<' ? 'selected' : ''}>Less Than</option>
          <option value="contains" ${o === 'contains' ? 'selected' : ''}>Contains</option>
        </select>
      </div>
      <div>
        <input type="text" class="nu-input nb-col-cond-val" value="${v.replace(/"/g, '&quot;')}" placeholder="Value" style="font-size:11px;padding:2px 4px;">
      </div>
      <div>
        <input type="color" class="nu-input nb-col-cond-fg" value="${f}" style="font-size:11px;padding:0 2px;height:22px;cursor:pointer;" title="Text Color">
      </div>
      <div>
        <input type="color" class="nu-input nb-col-cond-bg" value="${b}" style="font-size:11px;padding:0 2px;height:22px;cursor:pointer;" title="Bg Color">
      </div>
      <button type="button" class="nu-btn nu-btn-danger nu-btn-sm" style="padding:2px 6px;" onclick="this.closest('.nb-col-rule-row').remove()" title="Delete Rule">✕</button>
    `;
    list.appendChild(row);
  };

  window.nbFormBuilder._collectBrowseLayout = function () {
    var rows = [];
    document.querySelectorAll('.nb-browse-column-row').forEach(function (row) {
      var fld = row.querySelector('.nb-col-field').value.trim();
      if (!fld) return;
      var label = row.querySelector('.nb-col-label').value.trim();
      var width = row.querySelector('.nb-col-width').value.trim();
      var align = row.querySelector('.nb-col-align').value;
      var formatter = row.querySelector('.nb-col-format').value;
      var sortable = row.querySelector('.nb-col-sortable').checked;
      var frozen = row.querySelector('.nb-col-frozen').checked;
      var aggregate = row.querySelector('.nb-col-aggregate') ? row.querySelector('.nb-col-aggregate').value : 'none';

      var rules = [];
      row.querySelectorAll('.nb-col-rule-row').forEach(function (rRow) {
        var op = rRow.querySelector('.nb-col-cond-op').value;
        var val = rRow.querySelector('.nb-col-cond-val').value.trim();
        var fg = rRow.querySelector('.nb-col-cond-fg').value;
        var bg = rRow.querySelector('.nb-col-cond-bg').value;
        if (op) {
          rules.push({ op: op, val: val, fg: fg, bg: bg });
        }
      });

      var btnLabel     = row.querySelector('.nb-col-btn-label') ? row.querySelector('.nb-col-btn-label').value.trim() : '';
      var btnClass     = row.querySelector('.nb-col-btn-class') ? row.querySelector('.nb-col-btn-class').value.trim() : '';
      var btnJs        = row.querySelector('.nb-col-btn-js') ? row.querySelector('.nb-col-btn-js').value.trim() : '';
      var btnHasLookup = row.querySelector('.nb-col-btn-has-lookup') ? row.querySelector('.nb-col-btn-has-lookup').checked : false;
      var btnTable     = row.querySelector('.nb-col-btn-table') ? row.querySelector('.nb-col-btn-table').value.trim() : '';
      var btnId        = row.querySelector('.nb-col-btn-id') ? row.querySelector('.nb-col-btn-id').value.trim() : '';
      var btnDisp      = row.querySelector('.nb-col-btn-disp') ? row.querySelector('.nb-col-btn-disp').value.trim() : '';
      var btnWhere     = row.querySelector('.nb-col-btn-where') ? row.querySelector('.nb-col-btn-where').value.trim() : '';

      rows.push({
        fieldname: fld,
        fieldlabel: label,
        width: width,
        align: align,
        formatter: formatter,
        sortable: sortable,
        frozen: frozen,
        aggregate: aggregate,
        rules: rules,
        btn_label: btnLabel,
        btn_class: btnClass,
        btn_js: btnJs,
        btn_has_lookup: btnHasLookup,
        btn_table: btnTable,
        btn_id: btnId,
        btn_disp: btnDisp,
        btn_where: btnWhere
      });
    });
    return JSON.stringify(rows);
  };

  window.nbFormBuilder.loadFormVersions = function (formId) {
    var tbody = document.getElementById('formVersionsTableBody');
    if (!tbody) return;
    if (!formId) {
      tbody.innerHTML = '<tr><td colspan="4" style="padding:20px;text-align:center;color:var(--text-tertiary);">No revisions logged yet. Save changes to create a version snapshot.</td></tr>';
      return;
    }
    NuApp.apiJson('api/forms.php?action=get_versions&form_id=' + encodeURIComponent(formId), { credentials: 'same-origin' })
      .then(function (res) {
        if (!res || !res.success || !res.versions || res.versions.length === 0) {
          tbody.innerHTML = '<tr><td colspan="4" style="padding:20px;text-align:center;color:var(--text-tertiary);">No revisions logged yet. Save changes to create a version snapshot.</td></tr>';
          return;
        }
        tbody.innerHTML = '';
        res.versions.forEach(function (v) {
          var tr = document.createElement('tr');
          tr.style.cssText = 'border-bottom:1px solid var(--border-color);';

          var tdId = document.createElement('td');
          tdId.style.cssText = 'padding:10px;font-weight:600;';
          tdId.textContent = '#' + v.ver_id;

          var tdUser = document.createElement('td');
          tdUser.style.cssText = 'padding:10px;';
          tdUser.textContent = v.ver_created_by || 'Unknown';

          var tdTime = document.createElement('td');
          tdTime.style.cssText = 'padding:10px;';
          tdTime.textContent = v.ver_created_at;

          var tdActions = document.createElement('td');
          tdActions.style.cssText = 'padding:10px;';

          var btnRestore = document.createElement('button');
          btnRestore.type = 'button';
          btnRestore.className = 'nu-btn nu-btn-ghost nu-btn-sm';
          btnRestore.style.cssText = 'color:var(--color-primary);cursor:pointer;';
          btnRestore.textContent = '↩ Restore';
          btnRestore.addEventListener('click', function () {
            if (confirm('Are you sure you want to restore Version #' + v.ver_id + '? This will overwrite your current draft.')) {
              NuApp.apiJson('api/forms.php?action=rollback&ver_id=' + encodeURIComponent(v.ver_id), { credentials: 'same-origin' })
                .then(function (rollbackRes) {
                  if (rollbackRes && rollbackRes.success && rollbackRes.form) {
                    NuApp.toast('Overwritten draft with Version #' + v.ver_id, 'success');
                    window.nbFormBuilder.loadForm(rollbackRes.form);
                    window.nbFormBuilder._isDirty = true;
                  } else {
                    NuApp.toast((rollbackRes && rollbackRes.error) || 'Rollback failed', 'error');
                  }
                });
            }
          });

          tdActions.appendChild(btnRestore);
          tr.appendChild(tdId);
          tr.appendChild(tdUser);
          tr.appendChild(tdTime);
          tr.appendChild(tdActions);
          tbody.appendChild(tr);
        });
      })
      .catch(function (err) {
        tbody.innerHTML = '<tr><td colspan="4" style="padding:20px;text-align:center;color:red;">Failed to load versions: ' + err.message + '</td></tr>';
      });
  };

  /* ════════════════════════════════════════════════════════════════════
     Boot — wire up existing rows after page load
  ═══════════════════════════════════════════════════════════════════ */
  document.addEventListener('DOMContentLoaded', function () {
    var canvas = document.getElementById('formCanvas');
    if (canvas) {
      _attachCanvasRowDrop(canvas);
      _attachRowContainerDragAndDrop(canvas);
      canvas.querySelectorAll('.nb-row-body').forEach(_attachRowBodyDrop);
      canvas.querySelectorAll('.nb-row,.nb-container').forEach(_wireRowDrag);
      canvas.querySelectorAll('.nb-cfield').forEach(function (card) { _prepCard(card); });
    }

    document.addEventListener('keyup', function (e) {
      if (e.key === 'Escape') {
        var card = document.getElementById('formBuilderCard');
        if (card && card.classList.contains('nb-builder-fullscreen')) {
          window.nbFormBuilder.toggleFullscreen();
        }
      }
    });
    _ensurePropsPanel();
    window.nbFormBuilder.initGridLines();

    var builderCard = document.getElementById('formBuilderCard');
    if (builderCard) {
      ['input', 'change'].forEach(function (evtType) {
        builderCard.addEventListener(evtType, function () {
          window.nbFormBuilder._isDirty = true;
        });
      });
    }

    // Single click handler to auto-add field types or reusable blocks on click
    document.addEventListener('click', function (e) {
      var tool = e.target.closest ? e.target.closest('.nb-tool[data-type]') : null;
      if (tool) {
        var dtype = tool.dataset.type;
        if (dtype === 'group' || dtype === 'tab') {
          window.nbFormBuilder.addField(dtype, {});
        } else if (dtype.indexOf('block_') === 0) {
          _insertReusableBlock(dtype, null);
        } else {
          var row = window.nbFormBuilder.addRow();
          var rb = row ? row.querySelector('.nb-row-body') : null;
          if (rb) {
            var nc = window.nbFormBuilder._makeFieldCard(dtype, dtype + ' field', dtype + '_' + Date.now(), false, { col: 6 });
            if (nc) {
              _prepCard(nc);
              var hint = rb.querySelector('.nb-row-drop-hint'); if (hint) hint.remove();
              rb.appendChild(nc);
              window.nbFormBuilder._applyColSpan(nc, 6);
            }
          }
          window.nbFormBuilder._updateEmptyState();
        }
      }
    });
  });

  // Expose internal helper functions to global scope to prevent ReferenceErrors from external modules
  window._prepCard = _prepCard;
  window._restoreFieldState = _restoreFieldState;
  window._makeTabContainer = _makeTabContainer;
  window._makeGroupContainer = _makeGroupContainer;
  window._readFieldCard = _readFieldCard;
  window._wireRowDrag = _wireRowDrag;


}(window));
