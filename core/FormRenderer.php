<?php
// Nuvis StudioX — Runtime Form Renderer
// Supports layout field types:
//   group  — collapsible section wrapping child fields
//   tab    — tab container; its 'tabs' array holds tab panels
//   All other types are rendered as individual fields.
//
// Builder JSON shape for a tab container:
// {
//   "type": "tab",
//   "tabs": [
//     {
//       "name": "Tab 1",          // builder saves 'name' (NOT 'label')
//       "rows": [                 // builder saves 'rows' (NOT 'fields')
//         { "fields": [ ... ] }  // each row has a 'fields' array
//       ]
//     }
//   ]
// }

class NuFormRenderer {
    private $db;
    private $auth;
    private $audit;

    public function __construct() {
        $this->db    = NuDatabase::getInstance();
        $this->auth  = new NuAuth();
        $this->audit = new NuAudit();
    }

    // =========================================================================
    // PUBLIC: render()
    // =========================================================================
    public function render($formCode, $recordId = null) {
        $form = $this->db->fetchOne(
            "SELECT * FROM nu_forms WHERE form_code = :code AND form_active = 1",
            [':code' => $formCode]
        );
        if (!$form) return '<div class="nu-toast error">Form not found</div>';

        if (!$this->auth->canForm($formCode, 'view')) {
            return '<div class="nu-alert nu-alert-danger">You do not have permission to view this form.</div>';
        }

        $perms  = $this->auth->formPerms($formCode);
        $layout = json_decode($form['form_layout'], true);
        if (!is_array($layout)) $layout = [];

        $record = null;
        if ($recordId && $form['form_table']) {
            $record = $this->db->fetchOne(
                "SELECT * FROM {$form['form_table']} WHERE id = :id",
                [':id' => $recordId]
            );
        }

        $isReadonly = $recordId ? !$perms['edit'] : !$perms['add'];

        // Unique ID for this form instance (supports multiple forms on one page)
        $formId = 'runtimeForm_' . $formCode;

        $html  = '<form class="nu-generated-form nu-runtime-form" id="' . $formId . '" ';
        $html .= 'data-form-code="'   . htmlspecialchars($formCode) . '" ';
        $html .= 'data-table="'       . htmlspecialchars($form['form_table'] ?? '') . '" ';
        $html .= 'data-record-id="'   . ($recordId ?? '') . '" ';
        $html .= 'data-can-view="'    . ($perms['view']   ? '1' : '0') . '" ';
        $html .= 'data-can-add="'     . ($perms['add']    ? '1' : '0') . '" ';
        $html .= 'data-can-edit="'    . ($perms['edit']   ? '1' : '0') . '" ';
        $html .= 'data-can-delete="'  . ($perms['delete'] ? '1' : '0') . '" ';
        $html .= 'data-can-export="'  . ($perms['export'] ? '1' : '0') . '"';
        $html .= ($isReadonly ? ' data-readonly="1"' : '') . '>';

        $html .= '<div class="nu-form-body">';
        $html .= $this->renderLayout($layout, $record, $isReadonly, $formCode);
        $html .= '</div>';

        $html .= '<div class="nu-form-actions">';
        if (!$isReadonly) {
            $html .= '<button type="submit" class="nu-btn nu-btn-primary">Save</button>';
        }
        $html .= '<button type="button" class="nu-btn nu-btn-ghost" onclick="history.back()">Cancel</button>';
        if ($recordId && $perms['delete']) {
            $html .= '<button type="button" class="nu-btn nu-btn-danger" onclick="deleteRecord(this)">Delete</button>';
        }
        if ($recordId) {
            $html .= '<button type="button" class="nu-btn nu-btn-ghost" onclick="NuApp.openRecordCertificatesModal(\'' . htmlspecialchars($formCode) . '\', \'' . htmlspecialchars((string)$recordId) . '\')" style="color:var(--color-primary);"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline;vertical-align:middle;margin-right:4px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg> Certificates</button>';
        }
        $html .= '</div>';
        $html .= '</form>';

        return $html;
    }

    // =========================================================================
    // PRIVATE: renderLayout() — top-level dispatcher
    // =========================================================================
    private function renderLayout(array $layout, $record, bool $readonly, string $formCode): string
    {
        $html = '';
        foreach ($layout as $item) {
            $type = $item['type'] ?? 'text';

            if ($type === 'tab') {
                $html .= $this->renderTabContainer($item, $record, $readonly, $formCode);
            } elseif ($type === 'group') {
                $html .= $this->renderGroup($item, $record, $readonly);
            } else {
                // Plain field — wrap in the standard grid
                $html .= '<div class="nu-form-grid">';
                $html .= $this->renderField($item, $record, $readonly);
                $html .= '</div>';
            }
        }
        return $html;
    }

    // =========================================================================
    // PRIVATE: renderTabContainer()
    //
    // Builder saves tabs as:
    //   { "type": "tab", "tabs": [ { "name": "Tab 1", "rows": [ { "fields": [...] } ] } ] }
    //
    // Keys supported (with fallbacks for both old and new builder output):
    //   tab label  → tab['name']  ?? tab['label'] ?? 'Tab N'
    //   tab id     → tab['id']    ?? 'tab_N'
    //   tab fields → collected from tab['rows'][*]['fields']  OR  tab['fields'] (legacy flat)
    // =========================================================================
    private function renderTabContainer(array $item, $record, bool $readonly, string $formCode): string
    {
        $tabs = $item['tabs'] ?? [];
        if (empty($tabs)) return '';

        $containerId = 'nu-tabs-' . $formCode . '-' . substr(md5(serialize($item)), 0, 6);

        // ── Tab bar ──────────────────────────────────────────────────────────
        $html  = '<div class="nu-tabs" id="' . $containerId . '">';
        $html .= '<div class="nu-tab-bar" role="tablist">';

        foreach ($tabs as $i => $tab) {
            // Builder uses 'name'; legacy renderer used 'label' — support both
            $tabLabel = htmlspecialchars($tab['name'] ?? $tab['label'] ?? 'Tab ' . ($i + 1));
            $tabId    = htmlspecialchars($tab['id']   ?? ('tab_' . $i));
            $active   = $i === 0 ? ' nu-tab-active' : '';

            $html .= '<button type="button"';
            $html .= ' class="nu-tab-btn' . $active . '"';
            $html .= ' role="tab"';
            $html .= ' data-tab="' . $tabId . '"';
            $html .= ' data-tabs-container="' . $containerId . '"';
            $html .= ' aria-selected="' . ($i === 0 ? 'true' : 'false') . '"';
            $html .= ' aria-controls="' . $containerId . '-panel-' . $tabId . '"';
            $html .= ' onclick="nuTabSwitch(this)"';
            $html .= '>' . $tabLabel . '</button>';
        }
        $html .= '</div>'; // .nu-tab-bar

        // ── Tab panels ───────────────────────────────────────────────────────
        foreach ($tabs as $i => $tab) {
            $tabId  = htmlspecialchars($tab['id'] ?? ('tab_' . $i));
            $hidden = $i === 0 ? '' : ' nu-tab-panel-hidden';

            $html .= '<div';
            $html .= ' id="' . $containerId . '-panel-' . $tabId . '"';
            $html .= ' class="nu-tab-panel' . $hidden . '"';
            $html .= ' role="tabpanel"';
            $html .= ' data-tab="' . $tabId . '"';
            $html .= '>';

            // Collect fields: builder stores them in rows[*].fields; fall back
            // to a flat 'fields' array for backwards compatibility.
            $panelItems = $this->collectTabFields($tab);

            foreach ($panelItems as $panelItem) {
                $fieldType = $panelItem['type'] ?? 'text';
                if ($fieldType === 'group') {
                    $html .= $this->renderGroup($panelItem, $record, $readonly);
                } else {
                    $html .= '<div class="nu-form-grid">';
                    $html .= $this->renderField($panelItem, $record, $readonly);
                    $html .= '</div>';
                }
            }

            $html .= '</div>'; // .nu-tab-panel
        }

        $html .= '</div>'; // .nu-tabs
        return $html;
    }

    /**
     * Flatten fields out of a tab definition regardless of whether the builder
     * stored them under 'rows[*].fields' (current builder) or 'fields' (legacy).
     */
    private function collectTabFields(array $tab): array
    {
        // Current builder shape: tab.rows = [ { fields: [...] }, ... ]
        if (!empty($tab['rows']) && is_array($tab['rows'])) {
            $fields = [];
            foreach ($tab['rows'] as $row) {
                if (!empty($row['fields']) && is_array($row['fields'])) {
                    foreach ($row['fields'] as $f) {
                        $fields[] = $f;
                    }
                }
            }
            return $fields;
        }

        // Legacy / hand-crafted shape: tab.fields = [ ... ]
        if (!empty($tab['fields']) && is_array($tab['fields'])) {
            return $tab['fields'];
        }

        return [];
    }

    private function collectGroupFields(array $group): array
    {
        if (!empty($group['fields']) && is_array($group['fields'])) {
            return $group['fields'];
        }
        if (!empty($group['rows']) && is_array($group['rows'])) {
            $fields = [];
            foreach ($group['rows'] as $row) {
                if (!empty($row['fields']) && is_array($row['fields'])) {
                    foreach ($row['fields'] as $f) {
                        $fields[] = $f;
                    }
                }
            }
            return $fields;
        }
        return [];
    }

    // =========================================================================
    // PRIVATE: renderGroup()
    // Layout JSON shape:
    // {
    //   "type": "group",
    //   "label": "Personal Details",
    //   "collapsible": true,      // optional, default true
    //   "collapsed": false,        // optional, default false
    //   "columns": 2,              // optional grid columns (1-4)
    //   "fields": [ ... ]
    // }
    // =========================================================================
    private function renderGroup(array $item, $record, bool $readonly): string
    {
        $label       = htmlspecialchars($item['label']    ?? 'Section');
        $fields      = $this->collectGroupFields($item);
        $collapsible = $item['collapsible'] ?? true;
        $collapsed   = $item['collapsed']   ?? false;
        $columns     = max(1, min(4, (int)($item['columns'] ?? 2)));

        $groupId     = 'nu-group-' . substr(md5(serialize($item)), 0, 8);
        $expanded    = $collapsed ? 'false' : 'true';
        $hiddenClass = $collapsed ? ' nu-group-body-collapsed' : '';

        $html = '<div class="nu-form-group" id="' . $groupId . '">';

        // ── Group header ─────────────────────────────────────────────────────
        $html .= '<div class="nu-group-header"';
        if ($collapsible) {
            $html .= ' role="button" tabindex="0" aria-expanded="' . $expanded . '"';
            $html .= ' aria-controls="' . $groupId . '-body"';
            $html .= ' onclick="nuGroupToggle(this)"';
            $html .= ' onkeydown="if(event.key===\'Enter\'||event.key===\' \'){nuGroupToggle(this)}"';
        }
        $html .= '>';
        $html .= '<span class="nu-group-label">' . $label . '</span>';
        if ($collapsible) {
            $chevronRotate = $collapsed ? ' nu-chevron-collapsed' : '';
            $html .= '<svg class="nu-group-chevron' . $chevronRotate . '" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>';
        }
        $html .= '</div>'; // .nu-group-header

        // ── Group body ───────────────────────────────────────────────────────
        $html .= '<div id="' . $groupId . '-body" class="nu-group-body' . $hiddenClass . '">';
        $html .= '<div class="nu-form-grid nu-form-grid-cols-' . $columns . '">';
        foreach ($fields as $field) {
            $html .= $this->renderField($field, $record, $readonly);
        }
        $html .= '</div>';
        $html .= '</div>'; // .nu-group-body

        $html .= '</div>'; // .nu-form-group
        return $html;
    }

    // =========================================================================
    // PRIVATE: renderField() — individual field types
    // =========================================================================
    private function renderField($field, $record = null, $readonly = false) {
        $type        = $field['type']        ?? 'text';
        $name        = $field['name']        ?? '';
        $label       = $field['label']       ?? $name;
        $value       = $record[$name]        ?? ($field['default'] ?? '');
        $required    = !empty($field['required']) ? 'required' : '';
        $placeholder = $field['placeholder'] ?? '';
        $options     = $field['options']     ?? [];
        $disabledAttr = $readonly ? 'disabled' : '';

        // Span multiple columns inside a group grid
        $colSpan = isset($field['col_span']) ? ' nu-field-span-' . (int)$field['col_span'] : '';

        $html  = '<div class="nu-field nu-field-' . $type . $colSpan . '">';

        // Checkbox gets a different label arrangement
        if ($type !== 'checkbox') {
            $html .= '<label class="nu-field-label">' . htmlspecialchars($label);
            if ($required && !$readonly) $html .= ' <span class="nu-required" aria-hidden="true">*</span>';
            $html .= '</label>';
        }

        switch ($type) {

            case 'textarea':
                $rows = (int)($field['rows'] ?? 4);
                $html .= '<textarea name="' . $name . '" class="nu-input" ' . $required . ' ' . $disabledAttr;
                $html .= ' placeholder="' . htmlspecialchars($placeholder) . '" rows="' . $rows . '">';
                $html .= htmlspecialchars($value);
                $html .= '</textarea>';
                break;

            // ------------------------------------------------------------------
            // Standard <select> — no Select2
            // ------------------------------------------------------------------
            case 'select':
                $isMulti = !empty($field['multiple']);
                $html .= '<select name="' . $name . ($isMulti ? '[]' : '') . '"'
                       . ' class="nu-input"'
                       . ($isMulti ? ' multiple' : '')
                       . ' ' . $required
                       . ' ' . $disabledAttr . '>';
                if (!$isMulti) {
                    $html .= '<option value="">-- Select --</option>';
                }
                $selectedValues = $isMulti
                    ? (is_array($value) ? $value : (strlen((string)$value) ? explode(',', (string)$value) : []))
                    : [$value];
                foreach ($options as $opt) {
                    $sel  = in_array($opt['value'], $selectedValues) ? 'selected' : '';
                    $html .= '<option value="' . htmlspecialchars($opt['value']) . '" ' . $sel . '>';
                    $html .= htmlspecialchars($opt['label']) . '</option>';
                }
                $html .= '</select>';
                break;

            // ------------------------------------------------------------------
            // Select2 — enhanced dropdown / multiselect
            // ------------------------------------------------------------------
            case 'select2':
                $s2Multiple    = !empty($field['multiple']) || ($field['select_type'] ?? '') === 'multiselect';
                $s2Mode        = $s2Multiple ? 'multiple' : 'single';
                $s2Placeholder = htmlspecialchars($field['placeholder'] ?? 'Select\u2026');
                $s2AllowClear  = isset($field['allow_clear']) && $field['allow_clear'] === false ? 'false' : 'true';

                $html .= '<select name="' . $name . ($s2Multiple ? '[]' : '') . '"'
                       . ' class="nu-input nu-select2"'
                       . ' data-select-type="select2"'
                       . ' data-select-mode="' . $s2Mode . '"'
                       . ' data-placeholder="' . $s2Placeholder . '"'
                       . ' data-allow-clear="' . $s2AllowClear  . '"'
                       . ($s2Multiple ? ' multiple' : '')
                       . ' ' . $required
                       . ' ' . $disabledAttr . '>';
                if (!$s2Multiple) $html .= '<option value=""></option>';
                $selectedValues = $s2Multiple
                    ? (is_array($value) ? $value : (strlen((string)$value) ? explode(',', (string)$value) : []))
                    : [$value];
                foreach ($options as $opt) {
                    $sel  = in_array($opt['value'], $selectedValues) ? 'selected' : '';
                    $html .= '<option value="' . htmlspecialchars($opt['value']) . '" ' . $sel . '>';
                    $html .= htmlspecialchars($opt['label']) . '</option>';
                }
                $html .= '</select>';
                break;

            case 'lookup':
                $html .= $this->renderLookup($field, $value, $readonly);
                break;

            case 'file':
                if (!$readonly) {
                    $html .= '<input type="file" name="' . $name . '" class="nu-input" ' . $required . '>';
                }
                if ($value) {
                    $html .= '<div class="nu-file-current"><a href="#" data-nu-view-name="' . htmlspecialchars(basename((string)$value)) . '">View current file</a></div>';
                }
                break;

            case 'date':
                $html .= '<input type="date" name="' . $name . '" class="nu-input" value="' . htmlspecialchars($value) . '" ' . $required . ' ' . $disabledAttr . '>';
                break;

            case 'datetime':
                $html .= '<input type="datetime-local" name="' . $name . '" class="nu-input" value="' . htmlspecialchars($value) . '" ' . $required . ' ' . $disabledAttr . '>';
                break;

            case 'number':
                $min  = isset($field['min'])  ? 'min="'  . $field['min']  . '"' : '';
                $max  = isset($field['max'])  ? 'max="'  . $field['max']  . '"' : '';
                $step = isset($field['step']) ? 'step="' . $field['step'] . '"' : '';
                $html .= '<input type="number" name="' . $name . '" class="nu-input" value="' . htmlspecialchars($value) . '" ' . $required . ' ' . $min . ' ' . $max . ' ' . $step . ' ' . $disabledAttr . ' placeholder="' . htmlspecialchars($placeholder) . '">';
                break;

            case 'checkbox':
                $checked = $value ? 'checked' : '';
                $html .= '<label class="nu-checkbox-label">';
                $html .= '<input type="checkbox" name="' . $name . '" value="1" ' . $checked . ' ' . $required . ' ' . $disabledAttr . '>';
                $html .= '<span>' . htmlspecialchars($label) . '</span>';
                $html .= '</label>';
                break;

            case 'radio':
                $html .= '<div class="nu-radio-group" role="radiogroup">';
                foreach ($options as $opt) {
                    $checked = ($value == $opt['value']) ? 'checked' : '';
                    $html .= '<label class="nu-radio-label">';
                    $html .= '<input type="radio" name="' . $name . '" value="' . htmlspecialchars($opt['value']) . '" ' . $checked . ' ' . $required . ' ' . $disabledAttr . '>';
                    $html .= '<span>' . htmlspecialchars($opt['label']) . '</span>';
                    $html .= '</label>';
                }
                $html .= '</div>';
                break;

            case 'html':
                // Static HTML / rich text display — no input
                $html .= '<div class="nu-html-block">' . ($field['content'] ?? '') . '</div>';
                break;

            case 'divider':
                $html .= '<hr class="nu-field-divider">';
                break;

            case 'subform':
                $html .= $this->renderSubform($field, $record);
                break;

            case 'repeater':
                $html .= $this->renderRepeater($field, $record);
                break;

            default:
                $inputType = in_array($type, ['email','password','url','tel']) ? $type : 'text';
                $html .= '<input type="' . $inputType . '" name="' . $name . '" class="nu-input" value="' . htmlspecialchars($value) . '" ' . $required . ' ' . $disabledAttr . ' placeholder="' . htmlspecialchars($placeholder) . '">';
        }

        if (!empty($field['help'])) {
            $html .= '<div class="nu-field-help">' . htmlspecialchars($field['help']) . '</div>';
        }
        $html .= '</div>'; // .nu-field
        return $html;
    }

    // =========================================================================
    // PRIVATE: renderLookup()
    // =========================================================================
    private function renderLookup($field, $value, $readonly = false) {
        $name         = $field['name'];
        $lookup       = $field['lookup']             ?? [];
        $table        = $lookup['table']             ?? '';
        $idCol        = $lookup['id_column']         ?? 'id';
        $displayCol   = $lookup['display_column']    ?? 'name';
        $disabledAttr = $readonly ? 'disabled' : '';
        $useSelect2   = !empty($field['use_select2']);
        $placeholder  = htmlspecialchars($field['placeholder'] ?? '-- Select --');

        $options = [];
        if ($table) {
            $options = $this->db->fetchAll(
                "SELECT {$idCol} as id, {$displayCol} as label FROM {$table} LIMIT 500"
            );
        }

        $cssClass = $useSelect2 ? 'nu-input nu-select2 nu-lookup' : 'nu-input nu-lookup';
        $html = '<select name="' . $name . '" class="' . $cssClass . '"';
        if ($useSelect2) {
            $html .= ' data-select-type="select2"';
            $html .= ' data-select-mode="single"';
            $html .= ' data-placeholder="' . $placeholder . '"';
            $html .= ' data-allow-clear="true"';
        }
        $html .= ' ' . $disabledAttr . '>';
        $html .= '<option value="">' . ($useSelect2 ? '' : '-- Select --') . '</option>';
        foreach ($options as $opt) {
            $selected = $value == $opt['id'] ? 'selected' : '';
            $html .= '<option value="' . htmlspecialchars($opt['id']) . '" ' . $selected . '>';
            $html .= htmlspecialchars($opt['label']) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    // =========================================================================
    // PRIVATE: renderSubform() / renderRepeater()
    // =========================================================================
    private function renderSubform($field, $record) {
        $subformCode = $field['subform_code'] ?? '';
        $parentField = $field['parent_field'] ?? 'parent_id';
        $parentId    = $record['id'] ?? 0;
        $html  = '<div class="nu-subform" data-subform="' . $subformCode . '" data-parent-field="' . $parentField . '" data-parent-id="' . $parentId . '">';
        $html .= '<div class="nu-subform-header">';
        $html .= '<span>Subform: ' . htmlspecialchars($subformCode) . '</span>';
        $html .= '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" onclick="loadSubform(this)">Load</button>';
        $html .= '</div><div class="nu-subform-content"></div></div>';
        return $html;
    }

    private function renderRepeater($field, $record) {
        $name  = $field['name'];
        $html  = '<div class="nu-repeater" data-field="' . $name . '">';
        $html .= '<div class="nu-repeater-rows"></div>';
        $html .= '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" onclick="addRepeaterRow(this)">+ Add Row</button>';
        $html .= '</div>';
        return $html;
    }

    // =========================================================================
    // PUBLIC: save()
    // =========================================================================
    public function save($formCode, $data, $recordId = null) {
        $action = $recordId ? 'edit' : 'add';
        if (!$this->auth->canForm($formCode, $action)) {
            throw new Exception('Permission denied: cannot ' . $action . ' records on ' . $formCode);
        }

        $form = $this->db->fetchOne(
            "SELECT * FROM nu_forms WHERE form_code = :code",
            [':code' => $formCode]
        );
        if (!$form || !$form['form_table']) throw new Exception('Form has no target table');

        $table  = $form['form_table'];
        $layout = json_decode($form['form_layout'], true);

        // Collect all field names from layout (including inside groups and tabs)
        $fieldNames = $this->collectFieldNames($layout);

        $safeData = [];
        foreach ($fieldNames as $name) {
            if (isset($data[$name])) $safeData[$name] = $data[$name];
        }

        if ($recordId) {
            $old = $this->db->fetchOne("SELECT * FROM {$table} WHERE id = :id", [':id' => $recordId]);
            $this->db->update($table, $safeData, "id = :id", [':id' => $recordId]);
            $this->audit->log('update', $table, $recordId, $old, $safeData);
            return $recordId;
        } else {
            $id = $this->db->insert($table, $safeData);
            $this->audit->log('create', $table, $id, null, $safeData);
            return $id;
        }
    }

    /**
     * Recursively collect all field 'name' values from a layout array,
     * including fields nested inside groups and tab panels (rows).
     */
    private function collectFieldNames(array $layout): array {
        $names = [];
        foreach ($layout as $item) {
            $type = $item['type'] ?? 'text';
            if ($type === 'tab') {
                foreach ($item['tabs'] ?? [] as $tab) {
                    // Support both builder shape (rows) and legacy shape (fields)
                    $tabFields = $this->collectTabFields($tab);
                    $names = array_merge($names, $this->collectFieldNames($tabFields));
                }
            } elseif ($type === 'group') {
                $groupFields = $this->collectGroupFields($item);
                $names = array_merge($names, $this->collectFieldNames($groupFields));
            } elseif (!in_array($type, ['divider','html','subform','repeater'], true)) {
                if (!empty($item['name'])) $names[] = $item['name'];
            }
        }
        return $names;
    }

    // =========================================================================
    // PUBLIC: delete()
    // =========================================================================
    public function delete($formCode, $recordId) {
        if (!$this->auth->canForm($formCode, 'delete')) {
            throw new Exception('Permission denied: cannot delete records on ' . $formCode);
        }

        $form = $this->db->fetchOne(
            "SELECT * FROM nu_forms WHERE form_code = :code",
            [':code' => $formCode]
        );
        if (!$form || !$form['form_table']) throw new Exception('Form has no target table');

        $old = $this->db->fetchOne(
            "SELECT * FROM {$form['form_table']} WHERE id = :id",
            [':id' => $recordId]
        );
        $this->db->delete($form['form_table'], "id = :id", [':id' => $recordId]);
        $this->audit->log('delete', $form['form_table'], $recordId, $old, null);
        return true;
    }

    // =========================================================================
    // PUBLIC: browse()
    // =========================================================================
    public function browse($formCode, $page = 1, $perPage = 20, $filters = []) {
        if (!$this->auth->canForm($formCode, 'view')) {
            throw new Exception('Permission denied: cannot view ' . $formCode);
        }

        $form = $this->db->fetchOne(
            "SELECT * FROM nu_forms WHERE form_code = :code",
            [':code' => $formCode]
        );
        if (!$form || !$form['form_table']) throw new Exception('Form has no target table');

        $table  = $form['form_table'];
        $layout = json_decode($form['form_layout'], true);
        $offset = ($page - 1) * $perPage;

        $where  = ['1=1'];
        $params = [];
        foreach ($filters as $key => $val) {
            if ($val !== '') {
                $where[]           = "{$key} LIKE :{$key}";
                $params[":{$key}"] = '%' . $val . '%';
            }
        }

        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT :limit OFFSET :offset";
        $params[':limit']  = (int)$perPage;
        $params[':offset'] = (int)$offset;
        $records = $this->db->fetchAll($sql, $params);

        $countSql = "SELECT COUNT(*) as total FROM {$table} WHERE " . implode(' AND ', $where);
        unset($params[':limit'], $params[':offset']);
        $total = $this->db->fetchOne($countSql, $params)['total'];

        return [
            'records'  => $records,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => ceil($total / $perPage),
            'layout'   => $layout,
            'perms'    => $this->auth->formPerms($formCode),
        ];
    }
}
