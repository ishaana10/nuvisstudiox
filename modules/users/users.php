<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

if (!$auth->hasPermission('users.view')) {
    http_response_code(403);
    exit('Access denied');
}

$db    = NuDatabase::getInstance();
require_once dirname(__DIR__, 2) . '/core/Tenant.php';
$__role = strtolower((string)(($auth->getCurrentUser()['usr_role'] ?? '')));
$__tenant = NuTenant::current();
$users = $__role === 'globeadmin'
    ? $db->fetchAll("SELECT * FROM nu_users ORDER BY usr_id DESC")
    : $db->fetchAll("SELECT * FROM nu_users WHERE tenant_code = ? AND usr_role <> 'globeadmin' ORDER BY usr_id DESC", [$__tenant]);
$roles = $__role === 'globeadmin'
    ? $db->fetchAll("SELECT role_code, role_name FROM nu_roles ORDER BY role_name")
    : $db->fetchAll("SELECT role_code, role_name FROM nu_roles WHERE role_code <> 'globeadmin' AND (role_is_system = 1 OR tenant_code = ?) ORDER BY role_name", [$__tenant]);

// Get unified user field definitions
$fieldsDef = nu_get_user_fields_def($db);
$systemFields = [];
$customFields = [];
foreach ($fieldsDef as $fd) {
    if (!empty($fd['is_system'])) {
        $systemFields[] = $fd;
    } else {
        $customFields[] = $fd;
    }
}

/**
 * Fetch dynamic select option values from database lookup table/field
 */
function nu_get_lookup_options(NuDatabase $db, string $table, string $field): array {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $field = preg_replace('/[^a-zA-Z0-9_]/', '', $field);
    if (!$table || !$field) return [];

    try {
        $rows = $db->fetchAll("SELECT DISTINCT `{$field}` as opt FROM `{$table}` WHERE `{$field}` IS NOT NULL AND `{$field}` != '' ORDER BY `{$field}` ASC LIMIT 100");
        return array_column($rows, 'opt');
    } catch (Exception $e) {
        return [];
    }
}
?>
<div class="nu-users" id="usersRoot">

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
    <h3 class="nu-card-title" style="margin:0;">Users</h3>
    <div style="display:flex;gap:8px;">
        <?php if ($auth->getCurrentUser()['usr_username'] === 'globeadmin'): ?>
        <button class="nu-btn nu-btn-secondary" style="background:#f1f5f9;color:#0f172a;border:1px solid #cbd5e1;" onclick="Users.openManageFields()">⚙ Manage User Fields</button>
        <?php endif; ?>
        <?php if ($auth->hasPermission('users.create')): ?>
        <button class="nu-btn nu-btn-primary" onclick="Users.openCreate()">+ New User</button>
        <?php endif; ?>
    </div>
</div>

<div class="nu-table-wrap">
    <table class="nu-table" style="width:100%;">
        <thead>
            <tr>
                <th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Active</th>
                <?php foreach ($customFields as $cf): ?>
                <th><?php echo htmlspecialchars($cf['label']); ?></th>
                <?php endforeach; ?>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u):
                $customValues = json_decode($u['usr_custom_fields'] ?? '{}', true);
                // Fallback to nu_user_meta for backward compatibility
                foreach ($customFields as $cf) {
                    if (!isset($customValues[$cf['key']])) {
                        $metaVal = $db->fetchOne(
                            "SELECT umeta_value FROM nu_user_meta WHERE umeta_user_id = :id AND umeta_key = :k",
                            [':id' => $u['usr_id'], ':k' => $cf['key']]
                        );
                        if ($metaVal) {
                            $customValues[$cf['key']] = $metaVal['umeta_value'];
                        }
                    }
                }
            ?>
            <tr>
                <td><?php echo $u['usr_id']; ?></td>
                <td><strong><?php echo htmlspecialchars($u['usr_username']); ?></strong></td>
                <td><?php echo htmlspecialchars($u['usr_email'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($u['usr_role']); ?></td>
                <td><?php echo $u['usr_active'] ? '<span class="nu-status nu-status-active">Yes</span>' : '<span class="nu-status nu-status-inactive">No</span>'; ?></td>
                <?php foreach ($customFields as $cf): ?>
                <td><?php echo htmlspecialchars((string)($customValues[$cf['key']] ?? '-')); ?></td>
                <?php endforeach; ?>
                <td style="display:flex;gap:6px;">
                    <?php if ($auth->hasPermission('users.edit')): ?>
                    <button class="nu-btn nu-btn-ghost nu-btn-sm"
                        onclick="Users.openEdit('<?php echo $u['usr_id']; ?>')"
                        data-user='<?php echo htmlspecialchars(json_encode($u), ENT_QUOTES); ?>'
                        data-meta='<?php echo htmlspecialchars(json_encode($customValues), ENT_QUOTES); ?>'>
                        ✎ Edit
                    </button>
                    <?php endif; ?>
                    <?php if ($auth->hasPermission('users.delete') && $u['usr_username'] !== 'globeadmin'): ?>
                    <button class="nu-btn nu-btn-danger nu-btn-sm"
                        onclick="Users.deleteUser('<?php echo $u['usr_id']; ?>', '<?php echo htmlspecialchars($u['usr_username']); ?>')">✕</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ═══ USER CREATE/EDIT MODAL ═══════════════════════════════════════════════ -->
<div id="userModalOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10000;align-items:center;justify-content:center;">
  <div style="background:var(--card-bg,#fff);border-radius:12px;padding:28px;width:92%;max-width:520px;max-height:90vh;overflow-y:auto;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;">
      <h4 id="userModalTitle" style="margin:0;">New User</h4>
      <button onclick="Users.closeModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#666;">✕</button>
    </div>

    <input type="hidden" id="umEditId">

    <div class="nu-field" style="margin-bottom:12px;">
      <label style="font-size:13px;display:block;margin-bottom:4px;">Username *</label>
      <input type="text" class="nu-input" id="umUsername">
    </div>
    <div class="nu-field" style="margin-bottom:12px;">
      <label style="font-size:13px;display:block;margin-bottom:4px;">Email</label>
      <input type="email" class="nu-input" id="umEmail">
    </div>
    <div class="nu-field" style="margin-bottom:12px;">
      <label style="font-size:13px;display:block;margin-bottom:4px;">Role</label>
      <select class="nu-input" id="umRole">
        <?php foreach ($roles as $r): ?>
        <option value="<?php echo htmlspecialchars($r['role_code']); ?>"><?php echo htmlspecialchars($r['role_name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="nu-field" style="margin-bottom:12px;">
      <label style="font-size:13px;display:block;margin-bottom:4px;">Password <small id="umPwdHint" style="color:#999;">(leave blank to keep current)</small></label>
      <input type="password" class="nu-input" id="umPassword" autocomplete="new-password">
    </div>

    <?php foreach ($customFields as $cf): ?>
    <div class="nu-field" style="margin-bottom:12px;">
      <label style="font-size:13px;display:block;margin-bottom:4px;"><?php echo htmlspecialchars($cf['label']); ?><?php echo !empty($cf['global']) ? ' <span style="font-size:11px;color:#1a6fad;">(global)</span>' : ''; ?></label>
      <?php if (($cf['type'] ?? 'text') === 'select'):
          $optsArray = [];
          if (!empty($cf['lookup_table']) && !empty($cf['lookup_field'])) {
              $optsArray = nu_get_lookup_options($db, $cf['lookup_table'], $cf['lookup_field']);
          } elseif (!empty($cf['options'])) {
              $optsArray = array_map('trim', explode(',', $cf['options']));
          }
      ?>
      <select class="nu-input" id="um_meta_<?php echo htmlspecialchars($cf['key']); ?>" data-meta-key="<?php echo htmlspecialchars($cf['key']); ?>">
        <option value="">— select —</option>
        <?php foreach ($optsArray as $opt): ?>
        <option value="<?php echo htmlspecialchars($opt); ?>"><?php echo htmlspecialchars($opt); ?></option>
        <?php endforeach; ?>
      </select>
      <?php else: ?>
      <input type="<?php echo htmlspecialchars($cf['type'] ?? 'text'); ?>" class="nu-input" id="um_meta_<?php echo htmlspecialchars($cf['key']); ?>" data-meta-key="<?php echo htmlspecialchars($cf['key']); ?>">
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div class="nu-field" style="margin-bottom:20px;">
      <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
        <input type="checkbox" id="umActive" checked> Active
      </label>
    </div>

    <div style="display:flex;gap:8px;justify-content:flex-end;">
      <button class="nu-btn nu-btn-ghost" onclick="Users.closeModal()">Cancel</button>
      <button class="nu-btn nu-btn-primary" onclick="Users.saveUser()">Save</button>
    </div>
  </div>
</div>

<!-- ═══ MANAGE FIELDS MODAL ════════════════════════════════════════════════ -->
<div id="manageFieldsModalOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10001;align-items:center;justify-content:center;">
  <div style="background:var(--card-bg,#fff);border-radius:12px;padding:28px;width:95%;max-width:920px;max-height:90vh;overflow-y:auto;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;">
      <h4 style="margin:0;font-size:18px;">Manage User Fields</h4>
      <button onclick="Users.closeManageFieldsModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#666;">✕</button>
    </div>

    <div style="margin-bottom:16px;background:#f8fafc;padding:12px;border-radius:6px;border:1px solid #e2e8f0;">
      <p style="margin:0 0 8px 0;font-size:13px;color:#475569;">System fields cannot be deleted, but they are listed so you can configure them to be available as global hashes (e.g. <code>##usr_role##</code>) in your SQL queries.</p>
    </div>

    <div class="nu-table-wrap" style="margin-bottom:20px;max-height:50vh;overflow-y:auto;">
      <table class="nu-table" style="width:100%;font-size:13px;" id="fieldsTable">
        <thead>
          <tr>
            <th>Field Key</th>
            <th>Label</th>
            <th>Type</th>
            <th>Options / Database Lookup</th>
            <th style="text-align:center;">Global Hash</th>
            <th style="text-align:center;">Actions</th>
          </tr>
        </thead>
        <tbody id="fieldsTableBody">
          <!-- Populated by JS -->
        </tbody>
      </table>
    </div>

    <div style="display:flex;justify-content:space-between;align-items:center;">
      <button class="nu-btn nu-btn-secondary" style="background:#f1f5f9;color:#0f172a;border:1px solid #cbd5e1;" onclick="Users.addFieldRow()">+ Add Custom Field</button>
      <div style="display:flex;gap:8px;">
        <button class="nu-btn nu-btn-ghost" onclick="Users.closeManageFieldsModal()">Cancel</button>
        <button class="nu-btn nu-btn-primary" onclick="Users.saveFieldsDef()">Save Configuration</button>
      </div>
    </div>
  </div>
</div>

<script>
var Users = (() => {
  const $ = id => document.getElementById(id);
  let currentFieldsDef = [];
  let dbTablesList = [];
  let dbColumnsCache = {};

  function toast(msg, type) {
    if (window.NuApp && NuApp.toast) NuApp.toast(msg, type);
    else alert(msg);
  }

  async function api(url, opts) {
    const r = await fetch(url, opts || {});
    const j = await r.json();
    if (!j.success) throw new Error(j.error || 'API error');
    return j;
  }

  function openCreate() {
    $('userModalTitle').textContent = 'New User';
    $('umEditId').value = '';
    $('umUsername').value = '';
    $('umUsername').disabled = false;
    $('umEmail').value = '';
    $('umRole').value = $('umRole').options[0]?.value || '';
    $('umPassword').value = '';
    $('umPwdHint').style.display = 'none';
    $('umActive').checked = true;
    // Clear meta fields
    document.querySelectorAll('[data-meta-key]').forEach(el => el.value = '');
    $('userModalOverlay').style.display = 'flex';
  }

  function openEdit(id) {
    const btn = document.querySelector(`[onclick="Users.openEdit('${id}')"]`);
    const user = JSON.parse(btn.dataset.user);
    const meta = JSON.parse(btn.dataset.meta || '{}');
    $('userModalTitle').textContent = 'Edit User';
    $('umEditId').value = user.usr_id;
    $('umUsername').value = user.usr_username;
    $('umUsername').disabled = true; // username immutable after creation
    $('umEmail').value = user.usr_email || '';
    $('umRole').value = user.usr_role || '';
    $('umPassword').value = '';
    $('umPwdHint').style.display = '';
    $('umActive').checked = user.usr_active == 1;
    // Populate meta fields
    document.querySelectorAll('[data-meta-key]').forEach(el => {
      el.value = meta[el.dataset.metaKey] || '';
    });
    $('userModalOverlay').style.display = 'flex';
  }

  function closeModal() {
    $('userModalOverlay').style.display = 'none';
  }

  async function saveUser() {
    const id       = $('umEditId').value;
    const username = $('umUsername').value.trim();
    const email    = $('umEmail').value.trim();
    const role     = $('umRole').value;
    const password = $('umPassword').value;
    const active   = $('umActive').checked ? 1 : 0;

    if (!username) { toast('Username is required', 'error'); return; }

    // Collect meta fields
    const meta = {};
    document.querySelectorAll('[data-meta-key]').forEach(el => {
      meta[el.dataset.metaKey] = el.value;
    });

    const payload = { username, email, role, active, meta };
    if (password) payload.password = password;
    if (id) payload.id = isNaN(id) ? id : parseInt(id); // Allow UUID strings

    try {
      await api('api/users.php?action=' + (id ? 'update' : 'create'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      toast(id ? 'User updated' : 'User created');
      closeModal();
      setTimeout(() => location.reload(), 600);
    } catch(e) { toast(e.message, 'error'); }
  }

  async function deleteUser(id, username) {
    if (!confirm(`Delete user "${username}"? This cannot be undone.`)) return;
    try {
      await api('api/users.php?action=delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id })
      });
      toast('User deleted');
      setTimeout(() => location.reload(), 600);
    } catch(e) { toast(e.message, 'error'); }
  }

  async function openManageFields() {
    try {
      const data = await api('api/users.php?action=get_tables');
      dbTablesList = data.tables || [];
    } catch (e) {
      console.error('Failed to load table list', e);
    }

    currentFieldsDef = <?php echo json_encode(nu_get_user_fields_def($db)); ?>;
    renderFieldsTable();
    $('manageFieldsModalOverlay').style.display = 'flex';
  }

  function closeManageFieldsModal() {
    $('manageFieldsModalOverlay').style.display = 'none';
  }

  async function loadTableColumns(tableName, selectFieldElement, selectedValue) {
    if (!tableName) {
      selectFieldElement.innerHTML = '<option value="">— select field —</option>';
      return;
    }
    if (dbColumnsCache[tableName]) {
      populateFieldDropdown(selectFieldElement, dbColumnsCache[tableName], selectedValue);
      return;
    }
    try {
      const data = await api(`api/users.php?action=get_columns&table=${tableName}`);
      dbColumnsCache[tableName] = data.columns || [];
      populateFieldDropdown(selectFieldElement, dbColumnsCache[tableName], selectedValue);
    } catch (e) {
      toast('Failed to load table columns', 'error');
    }
  }

  function populateFieldDropdown(el, cols, selectedValue) {
    el.innerHTML = '<option value="">— select field —</option>';
    cols.forEach(col => {
      const opt = document.createElement('option');
      opt.value = col;
      opt.textContent = col;
      if (col === selectedValue) opt.selected = true;
      el.appendChild(opt);
    });
  }

  function renderFieldsTable() {
    const tbody = $('fieldsTableBody');
    tbody.innerHTML = '';
    currentFieldsDef.forEach((f, index) => {
      const tr = document.createElement('tr');

      // Key
      const tdKey = document.createElement('td');
      if (f.is_system) {
        tdKey.innerHTML = `<code>${f.key}</code> <span style="font-size:10px;color:#64748b;background:#e2e8f0;padding:2px 4px;border-radius:3px;">system</span>`;
      } else {
        const inputKey = document.createElement('input');
        inputKey.type = 'text';
        inputKey.className = 'nu-input';
        inputKey.style.padding = '4px 8px';
        inputKey.value = f.key;
        inputKey.oninput = (e) => { f.key = e.target.value.replace(/[^a-zA-Z0-9_]/g, ''); };
        tdKey.appendChild(inputKey);
      }
      tr.appendChild(tdKey);

      // Label
      const tdLabel = document.createElement('td');
      const inputLabel = document.createElement('input');
      inputLabel.type = 'text';
      inputLabel.className = 'nu-input';
      inputLabel.style.padding = '4px 8px';
      inputLabel.value = f.label;
      inputLabel.oninput = (e) => { f.label = e.target.value; };
      tdLabel.appendChild(inputLabel);
      tr.appendChild(tdLabel);

      // Type
      const tdType = document.createElement('td');
      if (f.is_system) {
        tdType.textContent = f.type || 'text';
      } else {
        const selectType = document.createElement('select');
        selectType.className = 'nu-input';
        selectType.style.padding = '4px 8px';
        const types = ['text', 'textarea', 'number', 'email', 'password', 'date', 'datetime-local', 'time', 'checkbox', 'select'];
        types.forEach(t => {
          const opt = document.createElement('option');
          opt.value = t;
          opt.textContent = t;
          if (f.type === t) opt.selected = true;
          selectType.appendChild(opt);
        });
        selectType.onchange = (e) => {
          f.type = e.target.value;
          if (f.type !== 'select') {
            f.options = '';
            f.lookup_table = '';
            f.lookup_field = '';
          }
          renderFieldsTable();
        };
        tdType.appendChild(selectType);
      }
      tr.appendChild(tdType);

      // Options (select)
      const tdOptions = document.createElement('td');
      if (!f.is_system && f.type === 'select') {
        const container = document.createElement('div');
        container.style.display = 'flex';
        container.style.flexDirection = 'column';
        container.style.gap = '4px';

        const srcSelect = document.createElement('select');
        srcSelect.className = 'nu-input';
        srcSelect.style.padding = '2px 4px';
        srcSelect.style.fontSize = '12px';

        const optStatic = document.createElement('option');
        optStatic.value = 'static';
        optStatic.textContent = 'Static List';
        srcSelect.appendChild(optStatic);

        const optDb = document.createElement('option');
        optDb.value = 'db';
        optDb.textContent = 'Database Table';
        srcSelect.appendChild(optDb);

        const staticDiv = document.createElement('div');
        const inputOptions = document.createElement('input');
        inputOptions.type = 'text';
        inputOptions.className = 'nu-input';
        inputOptions.style.padding = '4px 8px';
        inputOptions.placeholder = 'comma,separated,options';
        inputOptions.value = f.options || '';
        inputOptions.oninput = (e) => { f.options = e.target.value; };
        staticDiv.appendChild(inputOptions);

        const dbDiv = document.createElement('div');
        dbDiv.style.display = 'flex';
        dbDiv.style.gap = '4px';

        const tblSelect = document.createElement('select');
        tblSelect.className = 'nu-input';
        tblSelect.style.padding = '2px 4px';
        tblSelect.style.fontSize = '12px';
        tblSelect.innerHTML = '<option value="">— select table —</option>';
        dbTablesList.forEach(t => {
          const opt = document.createElement('option');
          opt.value = t;
          opt.textContent = t;
          if (f.lookup_table === t) opt.selected = true;
          tblSelect.appendChild(opt);
        });

        const fldSelect = document.createElement('select');
        fldSelect.className = 'nu-input';
        fldSelect.style.padding = '2px 4px';
        fldSelect.style.fontSize = '12px';
        fldSelect.innerHTML = '<option value="">— select field —</option>';

        tblSelect.onchange = (e) => {
          f.lookup_table = e.target.value;
          f.lookup_field = '';
          loadTableColumns(f.lookup_table, fldSelect, '');
        };

        fldSelect.onchange = (e) => {
          f.lookup_field = e.target.value;
        };

        dbDiv.appendChild(tblSelect);
        dbDiv.appendChild(fldSelect);

        const isDb = !!f.lookup_table;
        if (isDb) {
          srcSelect.value = 'db';
          staticDiv.style.display = 'none';
          dbDiv.style.display = 'flex';
          loadTableColumns(f.lookup_table, fldSelect, f.lookup_field);
        } else {
          srcSelect.value = 'static';
          staticDiv.style.display = 'block';
          dbDiv.style.display = 'none';
        }

        srcSelect.onchange = (e) => {
          if (e.target.value === 'db') {
            staticDiv.style.display = 'none';
            dbDiv.style.display = 'flex';
            f.options = '';
            loadTableColumns(tblSelect.value, fldSelect, f.lookup_field);
          } else {
            staticDiv.style.display = 'block';
            dbDiv.style.display = 'none';
            f.lookup_table = '';
            f.lookup_field = '';
          }
        };

        container.appendChild(srcSelect);
        container.appendChild(staticDiv);
        container.appendChild(dbDiv);
        tdOptions.appendChild(container);
      } else {
        tdOptions.innerHTML = '<span style="color:#cbd5e1">—</span>';
      }
      tr.appendChild(tdOptions);

      // Global Hash
      const tdGlobal = document.createElement('td');
      tdGlobal.style.textAlign = 'center';
      const chkGlobal = document.createElement('input');
      chkGlobal.type = 'checkbox';
      chkGlobal.checked = !!f.global;
      chkGlobal.onchange = (e) => { f.global = e.target.checked; };
      tdGlobal.appendChild(chkGlobal);
      tr.appendChild(tdGlobal);

      // Actions
      const tdActions = document.createElement('td');
      tdActions.style.textAlign = 'center';
      if (!f.is_system) {
        const btnDel = document.createElement('button');
        btnDel.className = 'nu-btn nu-btn-danger nu-btn-sm';
        btnDel.style.padding = '2px 6px';
        btnDel.textContent = '✕';
        btnDel.onclick = () => {
          currentFieldsDef.splice(index, 1);
          renderFieldsTable();
        };
        tdActions.appendChild(btnDel);
      } else {
        tdActions.innerHTML = '<span style="color:#cbd5e1">—</span>';
      }
      tr.appendChild(tdActions);

      tbody.appendChild(tr);
    });
  }

  function addFieldRow() {
    currentFieldsDef.push({
      key: '',
      label: '',
      type: 'text',
      options: '',
      lookup_table: '',
      lookup_field: '',
      is_system: false,
      global: false
    });
    renderFieldsTable();
  }

  async function saveFieldsDef() {
    for (let i = 0; i < currentFieldsDef.length; i++) {
      const f = currentFieldsDef[i];
      if (!f.key.trim()) {
        toast('All fields must have a valid key', 'error');
        return;
      }
      if (!f.label.trim()) {
        toast(`Field "${f.key}" must have a label`, 'error');
        return;
      }
    }

    try {
      await api('api/users.php?action=save_fields_def', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ fields: currentFieldsDef })
      });
      toast('Fields configuration saved successfully');
      closeManageFieldsModal();
      setTimeout(() => location.reload(), 600);
    } catch (e) {
      toast(e.message, 'error');
    }
  }

  // Close on overlay click
  $('userModalOverlay').addEventListener('click', e => {
    if (e.target === $('userModalOverlay')) closeModal();
  });

  $('manageFieldsModalOverlay').addEventListener('click', e => {
    if (e.target === $('manageFieldsModalOverlay')) closeManageFieldsModal();
  });

  return { openCreate, openEdit, closeModal, saveUser, deleteUser, openManageFields, closeManageFieldsModal, addFieldRow, saveFieldsDef };
})();
</script>
</div>
