<?php
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';

$auth = new NuAuth();
if (!$auth->checkAuth()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Legacy endpoint (not used by the current UI): it has no project or permission checks, so it is globeadmin only.
if (strtolower((string)(($auth->getCurrentUser()['usr_role'] ?? ''))) !== 'globeadmin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Use api/form.php.']);
    exit;
}

$action = $_GET['action'] ?? '';
$formCode = $_GET['code'] ?? '';
$db = NuDatabase::getInstance();

switch ($action) {
    case 'render':
        handleRender($db, $formCode, $_GET['id'] ?? null);
        break;
    case 'fields':
        handleFields($db, $formCode);
        break;
    case 'events':
        handleEvents($db, $formCode, $_GET['event'] ?? '');
        break;
    case 'save':
        handleSave($db, $formCode);
        break;
    case 'list':
        handleBrowse($db, $formCode);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}

function handleRender($db, $formCode, $recordId = null) {
    $form = $db->fetchOne("SELECT * FROM nu_forms WHERE form_code = ?", [$formCode]);
    if (!$form) {
        echo json_encode(['success' => false, 'error' => 'Form not found']);
        exit;
    }
    
    $fields = $db->fetchAll("SELECT * FROM nu_form_fields WHERE form_id = ? AND field_active = 1 ORDER BY field_order", [$form['form_id']]);
    
    if (empty($fields) && !empty($form['form_layout'])) {
        $fields = json_decode($form['form_layout'], true) ?: [];
    }
    
    $events = $db->fetchAll("SELECT * FROM nu_form_events WHERE form_id = ? AND event_active = 1 ORDER BY event_order", [$form['form_id']]);
    $eventMap = [];
    foreach ($events as $e) {
        $eventMap[$e['event_type']] = $e['event_code'];
    }
    
    $record = [];
    $isNew = true;
    
    if ($recordId) {
        $record = $db->fetchOne("SELECT * FROM {$form['form_table']} WHERE id = ?", [$recordId]);
        $isNew = false;
    }
    
    $html = renderFormHTML($form, $fields, $eventMap, $record, $isNew, $db);
    echo json_encode(['success' => true, 'html' => $html, 'events' => $eventMap]);
}

function renderFormHTML($form, $fields, $events, $record, $isNew, $db) {
    ob_start();
    ?>
    <form class="nu-form" data-form-code="<?php echo htmlspecialchars($form['form_code']); ?>" 
          data-table="<?php echo htmlspecialchars($form['form_table']); ?>"
          data-record-id="<?php echo $record['id'] ?? ''; ?>"
          data-is-new="<?php echo $isNew ? '1' : '0'; ?>"
          onsubmit="return false;">
        
        <?php if (!empty($form['form_custom_css'])): ?>
        <style><?php echo $form['form_custom_css']; ?></style>
        <?php endif; ?>
        
        <div class="nu-form-fields" style="display:flex;flex-wrap:wrap;gap:12px;">
            <?php foreach ($fields as $field): ?>
                <?php renderField($field, $record, $isNew, $db); ?>
            <?php endforeach; ?>
        </div>
        
        <div class="nu-form-actions" style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
            <button type="button" class="nu-btn nu-btn-ghost" onclick="var o=this.closest('.nu-modal-overlay'); if(o) o.remove(); else history.back();">Cancel</button>
            <button type="button" class="nu-btn nu-btn-primary" onclick="submitNuForm(this.closest('form'))"><?php echo $isNew ? 'Save' : 'Update'; ?></button>
        </div>
        
        <script>
        (function() {
            var nu = window.nuForm;
            nu.init('<?php echo $form['form_code']; ?>', <?php echo json_encode($record); ?>, <?php echo $isNew ? 'true' : 'false'; ?>);
            <?php if (!empty($events['js_onload'])): ?>
            try {
                <?php echo $events['js_onload']; ?>
            } catch(e) { console.error('onLoad error:', e); }
            <?php endif; ?>
        })();
        </script>
        
        <?php if (!empty($form['form_custom_js'])): ?>
        <script>
        try {
            <?php echo $form['form_custom_js']; ?>
        } catch(e) { console.error('Custom JS error:', e); }
        </script>
        <?php endif; ?>
    </form>
    <?php
    return ob_get_clean();
}

function renderField($field, $record, $isNew, $db) {
    $type = $field['field_type'] ?? $field['type'] ?? 'text';
    $name = htmlspecialchars($field['field_name'] ?? $field['name'] ?? '');
    $label = htmlspecialchars($field['field_label'] ?? $field['label'] ?? $name);
    $required = ($field['field_required'] ?? $field['required'] ?? false) ? 'required' : '';
    $width = htmlspecialchars($field['field_width'] ?? $field['width'] ?? '100%');
    $placeholder = htmlspecialchars($field['field_placeholder'] ?? $field['placeholder'] ?? '');
    $help = !empty($field['field_help_text']) ? '<small style="color:var(--text-secondary);display:block;margin-top:4px;font-size:12px;">' . htmlspecialchars($field['field_help_text']) . '</small>' : '';
    $calcExpr = $field['field_formula'] ?? ($field['formula'] ?? ($field['field_calculated'] ?? ($field['calculated'] ?? '')));
    $calculated = !empty($calcExpr) ? 'data-calculated="true" data-expression="' . htmlspecialchars((string)$calcExpr) . '"' : '';
    $css = !empty($field['field_css']) ? ' style="' . htmlspecialchars($field['field_css']) . '"' : '';
    $defaultValue = $isNew ? ($field['field_default_value'] ?? $field['default_value'] ?? '') : ($record[$name] ?? '');
    
    $events = '';
    if (!empty($field['field_js_onchange'])) $events .= ' onchange="' . htmlspecialchars($field['field_js_onchange']) . '"';
    
    echo '<div class="nu-field-wrapper" data-field="' . $name . '" style="margin-bottom:12px;width:' . $width . ';">';
    echo '<label style="display:block;font-size:13px;font-weight:500;margin-bottom:4px;">' . $label . ($required ? ' <span style="color:red;">*</span>' : '') . '</label>';
    
    switch ($type) {
        case 'text':
        case 'email':
        case 'number':
        case 'url':
        case 'password':
            echo '<input type="' . $type . '" class="nu-input" data-field="' . $name . '" name="' . $name . '" value="' . htmlspecialchars($defaultValue) . '" placeholder="' . $placeholder . '" ' . $required . ' ' . $calculated . $events . $css . '>';
            break;
            
        case 'date':
            echo '<input type="date" class="nu-input" data-field="' . $name . '" name="' . $name . '" value="' . htmlspecialchars($defaultValue) . '" ' . $required . $events . $css . '>';
            break;
            
        case 'color':
            echo '<input type="color" class="nu-input" data-field="' . $name . '" name="' . $name . '" value="' . htmlspecialchars($defaultValue ?: '#000000') . '" ' . $events . $css . '>';
            break;
            
        case 'range':
            echo '<input type="range" class="nu-input" data-field="' . $name . '" name="' . $name . '" value="' . htmlspecialchars($defaultValue ?: '50') . '" ' . $events . $css . '>';
            break;
            
        case 'textarea':
            echo '<textarea class="nu-input" data-field="' . $name . '" name="' . $name . '" placeholder="' . $placeholder . '" rows="4" ' . $required . $events . $css . '>' . htmlspecialchars($defaultValue) . '</textarea>';
            break;
            
        case 'select':
            $options = json_decode($field['field_options'] ?? $field['options'] ?? '[]', true);
            echo '<select class="nu-input" data-field="' . $name . '" name="' . $name . '" ' . $required . $events . $css . '>';
            echo '<option value="">-- Select --</option>';
            foreach ($options as $opt) {
                $selected = ($defaultValue == $opt['value']) ? 'selected' : '';
                echo '<option value="' . htmlspecialchars($opt['value']) . '" ' . $selected . '>' . htmlspecialchars($opt['label']) . '</option>';
            }
            echo '</select>';
            break;
            
        case 'radio':
            $options = json_decode($field['field_options'] ?? $field['options'] ?? '[]', true);
            echo '<div style="display:flex;gap:12px;flex-wrap:wrap;">';
            foreach ($options as $opt) {
                $checked = ($defaultValue == $opt['value']) ? 'checked' : '';
                echo '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;">';
                echo '<input type="radio" name="' . $name . '" value="' . htmlspecialchars($opt['value']) . '" ' . $checked . $events . '>';
                echo htmlspecialchars($opt['label']);
                echo '</label>';
            }
            echo '</div>';
            break;
            
        case 'checkbox':
            $checked = $defaultValue ? 'checked' : '';
            echo '<label style="display:flex;align-items:center;gap:8px;cursor:pointer;">';
            echo '<input type="checkbox" data-field="' . $name . '" name="' . $name . '" value="1" ' . $checked . $events . $css . '>';
            echo '<span>' . $label . '</span>';
            echo '</label>';
            break;
            
        case 'lookup':
            $displayValue = '';
            $lookupTable = $field['field_lookup_table'] ?? '';
            $lookupId = $field['field_lookup_id'] ?? 'id';
            $lookupDisplay = $field['field_lookup_display'] ?? 'name';
            
            if ($defaultValue && $lookupTable) {
                try {
                    $lookup = $db->fetchOne("SELECT " . $lookupDisplay . " FROM " . $lookupTable . " WHERE " . $lookupId . " = ?", [$defaultValue]);
                    $displayValue = $lookup[$lookupDisplay] ?? '';
                } catch (Exception $e) {}
            }
            echo '<div style="display:flex;gap:8px;">';
            echo '<input type="hidden" data-field="' . $name . '" name="' . $name . '" value="' . htmlspecialchars($defaultValue) . '">';
            echo '<input type="text" class="nu-input" readonly placeholder="Click to select..." value="' . htmlspecialchars($displayValue) . '" onclick="openLookupModal(\'' . $name . '\', \'' . $lookupTable . '\', \'' . $lookupId . '\', \'' . $lookupDisplay . '\', \'' . htmlspecialchars($field['field_lookup_filter'] ?? '') . '\')" style="flex:1;cursor:pointer;">';
            echo '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" onclick="clearLookup(\'' . $name . '\')">Clear</button>';
            echo '</div>';
            break;
            
        case 'subform':
            $subformId = $field['field_subform_id'] ?? 0;
            $subformFk = $field['field_subform_fk'] ?? '';
            $subformView = $field['field_subform_view'] ?? 'grid';
            echo '<div class="nu-subform" data-subform-id="' . $subformId . '" data-subform-fk="' . $subformFk . '" data-subform-view="' . $subformView . '" data-parent-id="' . ($record['id'] ?? '0') . '">';
            echo '<div style="padding:20px;text-align:center;color:var(--text-secondary);">Subform: loading...</div>';
            echo '</div>';
            break;
            
        case 'calculated':
            echo '<input type="text" class="nu-input nu-calculated" data-field="' . $name . '" readonly value="' . htmlspecialchars($defaultValue) . '" ' . $calculated . $css . '>';
            break;
            
        case 'html':
            echo '<div data-field="' . $name . '" ' . $css . '>' . ($field['field_default_value'] ?? '') . '</div>';
            break;
            
        case 'file':
            echo '<input type="file" class="nu-input" data-field="' . $name . '" name="' . $name . '" ' . $events . $css . '>';
            if ($defaultValue) echo '<p style="font-size:12px;margin-top:4px;">Current: ' . htmlspecialchars($defaultValue) . '</p>';
            break;
    }
    
    echo $help;
    echo '</div>';
}

function handleFields($db, $formCode) {
    $form = $db->fetchOne("SELECT form_id FROM nu_forms WHERE form_code = ?", [$formCode]);
    if (!$form) { echo json_encode(['success' => false]); exit; }
    $fields = $db->fetchAll("SELECT * FROM nu_form_fields WHERE form_id = ? AND field_active = 1 ORDER BY field_order", [$form['form_id']]);
    echo json_encode(['success' => true, 'data' => $fields]);
}

function handleEvents($db, $formCode, $eventType) {
    $form = $db->fetchOne("SELECT form_id FROM nu_forms WHERE form_code = ?", [$formCode]);
    if (!$form) { echo json_encode(['success' => false]); exit; }
    $code = $db->fetchOne("SELECT event_code FROM nu_form_events WHERE form_id = ? AND event_type = ? AND event_active = 1", [$form['form_id'], $eventType]);
    echo json_encode(['success' => !!$code, 'code' => $code['event_code'] ?? '']);
}

function handleSave($db, $formCode) {
    $form = $db->fetchOne("SELECT * FROM nu_forms WHERE form_code = ?", [$formCode]);
    if (!$form) { echo json_encode(['success' => false, 'error' => 'Form not found']); exit; }

    $input = json_decode(file_get_contents('php://input'), true);
    $recordId = $_GET['id'] ?? null;
    $isNew = !$recordId;

    $events = $db->fetchAll("SELECT * FROM nu_form_events WHERE form_id = ? AND event_active = 1", [$form['form_id']]);
    $eventMap = [];
    foreach ($events as $e) { $eventMap[$e['event_type']] = $e['event_code']; }

    if (!empty($eventMap['php_beforesave'])) {
        $data = $input;
        $hashCookies = $input['hashCookies'] ?? [];
        eval($eventMap['php_beforesave']);
        $input = $data;
    }

    // ✅ Get current logged-in user ID from session/auth
    $currentUserId = $_SESSION['nu_user_id'] ?? $_SESSION['user_id'] ?? null;

    try {
        $now = date('Y-m-d H:i:s');

        $tableColumns = [];
        foreach ($db->fetchAll("DESCRIBE `{$form['form_table']}`") as $row) {
            $tableColumns[$row['Field']] = true;
        }

        $safeInput = [];
        foreach ($input as $k => $v) {
            if (isset($tableColumns[$k])) {
                $safeInput[$k] = $v;
            }
        }

        // Get location
        $locationVal = $_SESSION['nu_user_meta']['location'] ?? $_SESSION['location'] ?? null;
        if ($locationVal === null && $currentUserId !== null) {
            try {
                $stmtLocation = $db->fetchOne("SELECT usr_custom_fields FROM nu_users WHERE usr_id = ?", [$currentUserId]);
                if ($stmtLocation && !empty($stmtLocation['usr_custom_fields'])) {
                    $customLocation = json_decode($stmtLocation['usr_custom_fields'], true);
                    if (is_array($customLocation) && isset($customLocation['location'])) {
                        $locationVal = $customLocation['location'];
                    }
                }
            } catch (Throwable $e) {}
        }

        if ($isNew) {
            if (isset($tableColumns['created_at'])) $safeInput['created_at'] = $now;
            if (isset($tableColumns['updated_at'])) $safeInput['updated_at'] = $now;
            // ✅ Set created_by, updated_by, and user_id on insert
            if ($currentUserId !== null) {
                if (isset($tableColumns['created_by'])) $safeInput['created_by'] = $currentUserId;
                if (isset($tableColumns['updated_by'])) $safeInput['updated_by'] = $currentUserId;
                if (isset($tableColumns['user_id'])) $safeInput['user_id'] = $currentUserId;
            }
            if ($locationVal !== null && isset($tableColumns['location'])) {
                $safeInput['location'] = $locationVal;
            }
            unset($safeInput['id']);
            $db->insert($form['form_table'], $safeInput);
            $recordId = $db->lastInsertId();

            // Trigger Outgoing Webhooks for form_insert
            try {
                require_once __DIR__ . '/../core/WebhookSender.php';
                NuWebhookSender::trigger('form_insert', [
                    'table'     => $form['form_table'],
                    'record_id' => $recordId,
                    'data'      => array_merge($safeInput, ['id' => $recordId])
                ]);
            } catch (\Throwable $whe) {
                error_log('[Webhook Form Insert Trigger Error] ' . $whe->getMessage());
            }

            // Auto-start workflow if bound to this form code
            try {
                if (!class_exists('WorkflowEngine')) {
                    require_once __DIR__ . '/../core/Workflow.php';
                }
                $dbInst = NuDatabase::getInstance();
                $wfBound = $dbInst->fetchOne('SELECT wf_id FROM nu_workflows WHERE LOWER(wf_form_code) = LOWER(:fcode) AND wf_active = 1 LIMIT 1', [':fcode' => $formCode]);
                if ($wfBound) {
                    $userIdVal = (int)($currentUserId ?? $_SESSION['nu_user_id'] ?? $_SESSION['user_id'] ?? 0);
                    $wfEngine = new WorkflowEngine();
                    $wfEngine->start((int)$wfBound['wf_id'], $userIdVal, $form['form_table'], (string)$recordId);
                }
            } catch (\Throwable $wfe) {
                error_log('[Auto Workflow Start Error] ' . $wfe->getMessage());
            }
        } else {
            unset($safeInput['created_at']);
            unset($safeInput['created_by']); // ✅ Never overwrite original creator
            unset($safeInput['user_id']);    // ✅ Never overwrite original creator
            unset($safeInput['location']);   // ✅ Never overwrite original creator location
            unset($safeInput['id']);
            if (isset($tableColumns['updated_at'])) $safeInput['updated_at'] = $now;
            // ✅ Set updated_by on update
            if ($currentUserId !== null && isset($tableColumns['updated_by'])) {
                $safeInput['updated_by'] = $currentUserId;
            }
            $db->update($form['form_table'], $safeInput, 'id = ?', [$recordId]);

            // Trigger Outgoing Webhooks for form_update
            try {
                require_once __DIR__ . '/../core/WebhookSender.php';
                NuWebhookSender::trigger('form_update', [
                    'table'     => $form['form_table'],
                    'record_id' => $recordId,
                    'data'      => array_merge($safeInput, ['id' => $recordId])
                ]);
            } catch (\Throwable $whe) {
                error_log('[Webhook Form Update Trigger Error] ' . $whe->getMessage());
            }
        }

        // ✅ Missing: after-save event and response
        if (!empty($eventMap['php_aftersave'])) {
            $data = $safeInput;
            $record = array_merge($safeInput, ['id' => $recordId]);
            $record['record_id'] = $recordId;
            foreach ($record as $k => $v) {
                if (preg_match('/^[a-zA-Z0-9_]+$/', $k)) {
                    $$k = $v;
                }
            }
            $codeToEval = preg_replace_callback('/#([a-zA-Z0-9_]+)#/', function($matches) use ($record) {
                $fieldName = $matches[1];
                $val = $record[$fieldName] ?? '';
                return addslashes((string)$val);
            }, $eventMap['php_aftersave']);
            eval($codeToEval);
        }

        echo json_encode(['success' => true, 'id' => $recordId]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} // ✅ Missing closing brace restored

function handleBrowse($db, $formCode) {
    $form = $db->fetchOne("SELECT * FROM nu_forms WHERE form_code = ?", [$formCode]);
    if (!$form) { echo json_encode(['success' => false, 'error' => 'Form not found']); exit; }
    
    $page = intval($_GET['page'] ?? 1);
    $limit = 20;
    $offset = ($page - 1) * $limit;
    
    $fields = $db->fetchAll("SELECT field_name, field_label FROM nu_form_fields WHERE form_id = ? AND field_active = 1 ORDER BY field_order LIMIT 5", [$form['form_id']]);
    
    if (empty($fields)) {
        $layout = json_decode($form['form_layout'] ?? '[]', true);
        $fields = [];
        foreach ($layout as $f) {
            $fields[] = ['field_name' => $f['name'], 'field_label' => $f['label'] ?? $f['name']];
        }
    }
    
    $total = 0;
    $records = [];
    $pages = 0;
    
    $auth = NuAuth::getInstance();
    $currentRole = $auth->getCurrentRole();
    $browseConds = $form['browse_conditions'] ?? [];
    if (is_string($browseConds)) {
        $browseConds = json_decode($browseConds, true) ?: [];
    }

    $nuWhere = '';
    $nuColumns = '*';

    if (!empty($browseConds) && is_array($browseConds)) {
        foreach ($browseConds as $cond) {
            if (isset($cond['role']) && ($cond['role'] === '*' || $cond['role'] === $currentRole)) {
                if (!empty($cond['where'])) {
                    $nuWhere = $auth->resolveHashes($cond['where']);
                }
                if (!empty($cond['columns'])) {
                    $cols = [];
                    foreach(explode(',', trim($cond['columns'])) as $c) {
                        $c = preg_replace('/[^a-zA-Z0-9_]/', '', trim($c));
                        if ($c !== '') $cols[] = "`{$c}`";
                    }
                    if (!empty($cols)) $nuColumns = implode(', ', $cols);
                }
                break;
            }
        }
    }

    $whereSql = $nuWhere !== '' ? "WHERE ({$nuWhere})" : "";

    try {
        $totalRow = $db->fetchOne("SELECT COUNT(*) as c FROM `{$form['form_table']}` {$whereSql}");
        $total = $totalRow['c'] ?? 0;
        $records = $db->fetchAll("SELECT {$nuColumns} FROM `{$form['form_table']}` {$whereSql} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}");
        $pages = ceil($total / $limit);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Table error: ' . $e->getMessage()]);
        exit;
    }
    
    echo json_encode(['success' => true, 'data' => ['records' => $records, 'layout' => $fields, 'page' => $page, 'pages' => $pages, 'total' => $total]]);
}

