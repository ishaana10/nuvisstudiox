<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
require_once dirname(__DIR__, 2) . '/core/ProjectContext.php';

$db = NuDatabase::getInstance();
$auth = NuAuth::getInstance();
$currentUser = $auth->getCurrentUser();
if (is_array($currentUser)) {
    $userRole = $currentUser['usr_role'] ?? 'user';
} elseif (is_object($currentUser)) {
    $userRole = $currentUser->usr_role ?? 'user';
} else {
    $userRole = 'user';
}
$isGlobeAdmin = ($userRole === 'globeadmin');

$currentPid = ProjectContext::getId();
$projects = ProjectContext::getAccessibleProjects();
?>

<div class="nu-projects-module">
  <!-- ── HEADER ── -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
    <div>
      <h2 style="font-size:20px;font-weight:600;margin:0;">Projects Management</h2>
      <p style="color:var(--text-secondary);font-size:13px;margin:2px 0 0;">Manage application projects, team member access, and project exports.</p>
    </div>
    <?php if ($isGlobeAdmin): ?>
    <div style="display:flex;gap:8px;">
      <button class="nu-btn nu-btn-secondary" onclick="ProjectsModule.openImportModal()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        📥 Import Project
      </button>
      <button class="nu-btn nu-btn-primary" onclick="ProjectsModule.openNew()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        New Project
      </button>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── SEARCH BAR ── -->
  <div style="margin-bottom: 16px;">
    <input type="text" id="projectSearchInput" placeholder="Search projects..." oninput="ProjectsModule.filterList(this.value)" class="nu-input" style="max-width: 320px; font-size: 13px; padding: 6px 12px;">
  </div>

  <!-- ── PROJECTS GRID ── -->
  <div class="nu-grid" id="projectCards" style="grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;">
    <?php foreach ($projects as $p): ?>
    <?php $isCurrent = ((int)$p['project_id'] === $currentPid); ?>
    <div class="nu-card proj-card" data-id="<?= $p['project_id'] ?>" data-name="<?= h($p['project_name']) ?>" data-code="<?= h($p['project_code']) ?>" style="<?= $isCurrent ? 'border: 2px solid var(--color-primary); background: rgba(99,102,241,0.03);' : '' ?>">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;">
        <div style="flex:1;min-width:0;">
          <div style="display:flex;align-items:center;gap:6px;">
            <h4 style="font-weight:600;font-size:15px;margin:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= h($p['project_name']) ?></h4>
            <?php if ($isCurrent): ?>
              <span class="nu-badge nu-badge-primary" style="font-size:10px;">Active</span>
            <?php endif; ?>
          </div>
          <span class="nu-badge nu-badge-secondary" style="font-size:11px;margin-top:4px;display:inline-block;font-family:monospace;"><?= h($p['project_code']) ?></span>
        </div>
        <?php if (!empty($p['project_is_default'])): ?>
          <span class="nu-badge nu-badge-success" style="margin-left:8px;flex-shrink:0;">Default</span>
        <?php endif; ?>
      </div>

      <p style="color:var(--text-secondary);font-size:12px;margin-bottom:16px;min-height:32px;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">
        <?= h($p['project_description'] ?: 'No description provided.') ?>
      </p>

      <div style="display:flex;flex-wrap:wrap;gap:6px;">
        <?php if (!$isCurrent): ?>
          <button class="nu-btn nu-btn-secondary nu-btn-sm" onclick="ProjectsModule.switchProject(<?= $p['project_id'] ?>)">Switch To</button>
        <?php endif; ?>

        <?php if ($isGlobeAdmin): ?>
          <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="ProjectsModule.openShare(<?= $p['project_id'] ?>, '<?= h($p['project_name'], ENT_QUOTES) ?>')">🔗 Share</button>
          <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="ProjectsModule.openExport(<?= $p['project_id'] ?>, '<?= h($p['project_name'], ENT_QUOTES) ?>')">📥 Export SQL</button>
          <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="ProjectsModule.openExportZip(<?= $p['project_id'] ?>, '<?= h($p['project_name'], ENT_QUOTES) ?>')">📦 Export ZIP</button>
          <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="ProjectsModule.openVersions(<?= $p['project_id'] ?>, '<?= h($p['project_name'], ENT_QUOTES) ?>')">🏷️ Versions</button>
          <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="ProjectsModule.openMembers(<?= $p['project_id'] ?>, '<?= h($p['project_name'], ENT_QUOTES) ?>')">👥 Members</button>
          <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="ProjectsModule.edit(<?= $p['project_id'] ?>)">✏️ Edit</button>
          <?php if (empty($p['project_is_default'])): ?>
            <button class="nu-btn nu-btn-danger nu-btn-sm" onclick="ProjectsModule.delete(<?= $p['project_id'] ?>, '<?= h($p['project_name'], ENT_QUOTES) ?>')">Delete</button>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ── NEW / EDIT PROJECT MODAL ── -->
<div id="projModal" class="nu-modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
  <div class="nu-card" style="width:100%; max-width:480px; padding:24px; background:var(--bg-card); border-radius:8px;">
    <h3 id="projModalTitle" style="font-size:16px; font-weight:600; margin:0 0 16px 0;">New Project</h3>
    <input type="hidden" id="projEditId">

    <div class="nu-field" style="margin-bottom:12px;">
      <label>Project Name <span style="color:var(--color-danger)">*</span></label>
      <input type="text" class="nu-input" id="projName" placeholder="e.g. E-Commerce Portal" oninput="ProjectsModule.autoCode()">
    </div>

    <div class="nu-field" style="margin-bottom:12px;">
      <label>Project Code (Slug) <span style="color:var(--color-danger)">*</span></label>
      <input type="text" class="nu-input" id="projCode" placeholder="e.g. ecommerce">
    </div>

    <div class="nu-field" style="margin-bottom:20px;">
      <label>Description</label>
      <textarea class="nu-input" id="projDescription" rows="3" placeholder="Briefly describe the project purpose"></textarea>
    </div>

    <div style="display:flex; justify-content:flex-end; gap:8px;">
      <button class="nu-btn nu-btn-ghost" onclick="ProjectsModule.closeModal()">Cancel</button>
      <button class="nu-btn nu-btn-primary" onclick="ProjectsModule.saveProject()">Save Project</button>
    </div>
  </div>
</div>

<!-- ── VERSION CONTROL SNAPSHOTS MODAL ── -->
<div id="projVersionsModal" class="nu-modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
  <div class="nu-card" style="width:100%; max-width:680px; padding:24px; background:var(--bg-card); border-radius:8px;">
    <h3 id="projVersionsModalTitle" style="font-size:16px; font-weight:600; margin:0 0 16px 0;">Project Versions & Snapshots</h3>
    <input type="hidden" id="projVersionsProjId">

    <!-- Create Version Form -->
    <div style="display:grid; grid-template-columns:1fr 1.5fr auto; gap:8px; margin-bottom:16px; padding:12px; background:var(--bg-subtle); border-radius:6px; align-items:end;">
      <div>
        <label style="font-size:11px; font-weight:600; margin-bottom:4px; display:block;">Version Tag</label>
        <input type="text" id="projVerTag" class="nu-input" placeholder="e.g. v1.0.0">
      </div>
      <div>
        <label style="font-size:11px; font-weight:600; margin-bottom:4px; display:block;">Description / Release Notes</label>
        <input type="text" id="projVerDesc" class="nu-input" placeholder="e.g. Initial production release">
      </div>
      <button class="nu-btn nu-btn-primary" onclick="ProjectsModule.createVersion()">🏷️ Create Snapshot</button>
    </div>

    <!-- Versions History Table -->
    <div style="max-height:300px; overflow-y:auto; border:1px solid var(--border-color); border-radius:6px;">
      <table style="width:100%; border-collapse:collapse; font-size:13px;">
        <thead>
          <tr style="background:var(--bg-subtle); text-align:left;">
            <th style="padding:8px 12px;">Tag</th>
            <th style="padding:8px 12px;">Description</th>
            <th style="padding:8px 12px;">Created By</th>
            <th style="padding:8px 12px;">Date</th>
            <th style="padding:8px 12px; text-align:right;">Action</th>
          </tr>
        </thead>
        <tbody id="projVersionsTableBody">
          <!-- Populated dynamically -->
        </tbody>
      </table>
    </div>

    <div style="display:flex; justify-content:flex-end; margin-top:16px;">
      <button class="nu-btn nu-btn-secondary" onclick="ProjectsModule.closeVersionsModal()">Close</button>
    </div>
  </div>
</div>

<!-- ── IMPORT PROJECT MODAL ── -->
<div id="projImportModal" class="nu-modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
  <div class="nu-card" style="width:100%; max-width:560px; padding:24px; background:var(--bg-card); border-radius:8px;">
    <h3 style="font-size:16px; font-weight:600; margin:0 0 8px 0;">Import Application Project Package</h3>
    <p style="font-size:13px; color:var(--text-secondary); margin-bottom:16px; line-height:1.4;">
      Choose whether to upload a package file from your local computer or select a package file from the webserver folder.
    </p>

    <!-- Source Selection Tabs -->
    <div style="display:flex; gap:12px; margin-bottom:16px; border-bottom:1px solid var(--border-color); padding-bottom:12px;">
      <label style="display:flex; align-items:center; gap:6px; font-size:13px; font-weight:600; cursor:pointer;">
        <input type="radio" name="projImportSource" value="upload" checked onchange="ProjectsModule.toggleImportSource('upload')">
        💻 Upload from Local Computer
      </label>
      <label style="display:flex; align-items:center; gap:6px; font-size:13px; font-weight:600; cursor:pointer;">
        <input type="radio" name="projImportSource" value="server" onchange="ProjectsModule.toggleImportSource('server')">
        📁 Select from Webserver Folder
      </label>
    </div>

    <!-- Upload Option -->
    <div id="projImportSourceUpload" class="nu-field" style="margin-bottom:16px;">
      <label style="font-weight:600; display:block; margin-bottom:6px;">Select Local File (.sql or .zip) <span style="color:var(--color-danger)">*</span></label>
      <input type="file" id="projImportFile" accept=".sql,.zip" class="nu-input" style="width:100%;">
    </div>

    <!-- Server Folder Option -->
    <div id="projImportSourceServer" class="nu-field" style="display:none; margin-bottom:16px;">
      <label style="font-weight:600; display:block; margin-bottom:6px;">Detected Packages on Webserver <span style="color:var(--color-danger)">*</span></label>
      <select id="projServerFileSelect" class="nu-input" style="width:100%; margin-bottom:10px;" onchange="ProjectsModule.onServerFileSelect(this.value)">
        <option value="">Scanning server folder...</option>
      </select>

      <label style="font-size:12px; font-weight:600; color:var(--text-secondary); display:block; margin-bottom:4px;">Or specify relative file path from root:</label>
      <input type="text" id="projServerFilePathInput" class="nu-input" placeholder="e.g. install.sql or uploads/allpest.zip" style="width:100%;">
    </div>

    <div id="projImportStatus" style="display:none; margin-bottom:16px; font-size:13px; padding:10px 12px; border-radius:6px;"></div>

    <div style="display:flex; justify-content:flex-end; gap:8px;">
      <button class="nu-btn nu-btn-ghost" onclick="ProjectsModule.closeImportModal()">Cancel</button>
      <button class="nu-btn nu-btn-primary" id="btnRunProjectImport" onclick="ProjectsModule.executeProjectImport()">📥 Start Import</button>
    </div>
  </div>
</div>

<!-- ── SHARE FOR TESTING MODAL ── -->
<div id="projShareModal" class="nu-modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
  <div class="nu-card" style="width:100%; max-width:540px; padding:24px; background:var(--bg-card); border-radius:8px;">
    <h3 id="projShareModalTitle" style="font-size:16px; font-weight:600; margin:0 0 8px 0;">Share Project for Testing</h3>
    <p style="font-size:13px; color:var(--text-secondary); margin-bottom:16px;">
      Allow external testers or clients to access and test this project online while it is under development.
    </p>
    <input type="hidden" id="projShareProjId">

    <!-- Toggle Public Link -->
    <div style="display:flex; align-items:center; justify-content:space-between; padding:12px; background:var(--bg-subtle); border-radius:6px; margin-bottom:16px;">
      <div>
        <strong style="font-size:14px; display:block;">Enable Online Test Link</strong>
        <span style="font-size:12px; color:var(--text-secondary);">Anyone with the share URL can access this project for testing.</span>
      </div>
      <label style="position:relative; display:inline-block; width:44px; height:24px; cursor:pointer;">
        <input type="checkbox" id="projShareToggle" onchange="ProjectsModule.toggleShare()" style="opacity:0; width:0; height:0;">
        <span id="projShareSlider" style="position:absolute; top:0; left:0; right:0; bottom:0; background-color:#ccc; transition:.3s; border-radius:24px;"></span>
      </label>
    </div>

    <!-- Test Mode Configuration -->
    <div id="projShareConfigArea" style="display:none; margin-bottom:16px;">
      <div class="nu-field" style="margin-bottom:12px;">
        <label style="font-weight:600; font-size:13px;">Testing Access Mode</label>
        <select id="projShareMode" class="nu-input" onchange="ProjectsModule.toggleShare()">
          <option value="guest">⚡ 1-Click Guest Test Mode (No login required)</option>
          <option value="login">🔒 Authenticated Test Mode (User logs in &amp; gets switched to project)</option>
        </select>
        <small style="color:var(--text-secondary); display:block; margin-top:4px;">
          Guest Mode lets testers test immediately without needing an existing user account.
        </small>
      </div>

      <div class="nu-field">
        <label style="font-weight:600; font-size:13px;">Shareable Testing URL</label>
        <div style="display:flex; gap:8px;">
          <input type="text" id="projShareUrlInput" class="nu-input" readonly style="font-family:monospace; font-size:12px; background:var(--bg-subtle);">
          <button class="nu-btn nu-btn-primary" onclick="ProjectsModule.copyShareUrl()" id="copyShareBtn">📋 Copy</button>
        </div>
      </div>

      <div style="margin-top:12px; display:flex; justify-content:space-between; align-items:center;">
        <small style="color:var(--text-tertiary);">Revoking the link invalidates previous testing tokens.</small>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="ProjectsModule.regenerateShareToken()">↻ Revoke &amp; New Link</button>
      </div>
    </div>

    <div style="display:flex; justify-content:flex-end; margin-top:16px;">
      <button class="nu-btn nu-btn-secondary" onclick="ProjectsModule.closeShareModal()">Done</button>
    </div>
  </div>
</div>

<!-- ── MEMBERS ASSIGNMENT MODAL ── -->
<div id="projMembersModal" class="nu-modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
  <div class="nu-card" style="width:100%; max-width:600px; padding:24px; background:var(--bg-card); border-radius:8px;">
    <h3 id="projMembersModalTitle" style="font-size:16px; font-weight:600; margin:0 0 16px 0;">Project Members</h3>
    <input type="hidden" id="projMembersProjId">

    <!-- Add Member Form -->
    <div style="display:grid; grid-template-columns:1fr 140px auto; gap:8px; margin-bottom:16px; padding:12px; background:var(--bg-subtle); border-radius:6px;">
      <select id="projAddUserId" class="nu-input">
        <option value="">Select user...</option>
      </select>
      <select id="projAddRole" class="nu-input">
        <option value="member">Member</option>
        <option value="admin">Admin</option>
        <option value="owner">Owner</option>
      </select>
      <button class="nu-btn nu-btn-primary" onclick="ProjectsModule.addMember()">Add Member</button>
    </div>

    <!-- Members Table -->
    <div style="max-height:280px; overflow-y:auto; border:1px solid var(--border-color); border-radius:6px;">
      <table style="width:100%; border-collapse:collapse; font-size:13px;">
        <thead>
          <tr style="background:var(--bg-subtle); text-align:left;">
            <th style="padding:8px 12px;">User</th>
            <th style="padding:8px 12px;">Role</th>
            <th style="padding:8px 12px; text-align:right;">Action</th>
          </tr>
        </thead>
        <tbody id="projMembersTableBody">
          <!-- Populated dynamically -->
        </tbody>
      </table>
    </div>

    <div style="display:flex; justify-content:flex-end; margin-top:16px;">
      <button class="nu-btn nu-btn-secondary" onclick="ProjectsModule.closeMembersModal()">Close</button>
    </div>
  </div>
</div>

<script>
(function() {
  if (window.ProjectsModule && typeof window.ProjectsModule._destroy === 'function') {
    window.ProjectsModule._destroy();
  }

  window.ProjectsModule = (function() {
    var $ = function(id) { return document.getElementById(id); };

    function filterList(query) {
      query = (query || '').toLowerCase().trim();
      var cards = document.querySelectorAll('#projectCards .proj-card');
      cards.forEach(function(card) {
        var name = (card.dataset.name || '').toLowerCase();
        var code = (card.dataset.code || '').toLowerCase();
        if (name.includes(query) || code.includes(query)) {
          card.style.display = '';
        } else {
          card.style.display = 'none';
        }
      });
    }

    function autoCode() {
      if ($('projEditId') && $('projEditId').value) return;
      var name = $('projName') ? $('projName').value : '';
      if ($('projCode')) {
        $('projCode').value = name.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
      }
    }

    function openNew() {
      $('projEditId').value = '';
      $('projName').value = '';
      $('projCode').value = '';
      $('projDescription').value = '';
      $('projCode').readOnly = false;
      $('projModalTitle').textContent = 'New Project';
      $('projModal').style.display = 'flex';
    }

    async function edit(id) {
      try {
        var res = await fetch('api/projects.php?action=get&project_id=' + id);
        var data = await res.json();
        if (data.success && data.project) {
          var p = data.project;
          $('projEditId').value = p.project_id;
          $('projName').value = p.project_name;
          $('projCode').value = p.project_code;
          $('projCode').readOnly = true;
          $('projDescription').value = p.project_description || '';
          $('projModalTitle').textContent = 'Edit Project';
          $('projModal').style.display = 'flex';
        } else {
          alert(data.error || 'Failed to load project details');
        }
      } catch (e) {
        alert('Network error loading project');
      }
    }

    function closeModal() {
      $('projModal').style.display = 'none';
    }

    async function saveProject() {
      var id = $('projEditId').value;
      var name = $('projName').value.trim();
      var code = $('projCode').value.trim();
      var desc = $('projDescription').value.trim();

      if (!name || !code) {
        alert('Project Name and Code are required');
        return;
      }

      var action = id ? 'update' : 'create';
      var payload = {
        project_id: id ? parseInt(id) : null,
        project_name: name,
        project_code: code,
        project_description: desc
      };

      try {
        var res = await fetch('api/projects.php?action=' + action, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        var data = await res.json();
        if (data.success) {
          closeModal();
          window.location.reload();
        } else {
          alert(data.error || 'Failed to save project');
        }
      } catch (e) {
        alert('Network error saving project');
      }
    }

    async function switchProject(id) {
      try {
        var res = await fetch('api/projects.php?action=switch', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ project_id: id })
        });
        var data = await res.json();
        if (data.success) {
          window.location.reload();
        } else {
          alert(data.error || 'Failed to switch project');
        }
      } catch (e) {
        alert('Network error switching project');
      }
    }

    async function delProject(id, name) {
      if (!confirm('Are you sure you want to delete project "' + name + '"?')) return;
      try {
        var res = await fetch('api/projects.php?action=delete', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ project_id: id })
        });
        var data = await res.json();
        if (data.success) {
          window.location.reload();
        } else {
          alert(data.error || 'Failed to delete project');
        }
      } catch (e) {
        alert('Network error deleting project');
      }
    }

    function openExport(id, name) {
      window.location.href = 'api/projects.php?action=export_project&project_id=' + id;
    }

    function openExportZip(id, name) {
      window.location.href = 'api/projects.php?action=export_zip&project_id=' + id;
    }

    async function openVersions(id, name) {
      $('projVersionsProjId').value = id;
      $('projVersionsModalTitle').textContent = 'Project Versions: ' + name;
      $('projVerTag').value = 'v' + new Date().toISOString().replace(/[^0-9]/g, '').slice(0, 14);
      $('projVerDesc').value = '';
      $('projVersionsModal').style.display = 'flex';
      await loadVersions(id);
    }

    async function loadVersions(id) {
      try {
        var res = await fetch('api/projects.php?action=version_list&project_id=' + id);
        var text = await res.text();
        var data;
        try {
          data = JSON.parse(text);
        } catch (parseErr) {
          alert('Server error loading versions: ' + text);
          return;
        }

        var tbody = $('projVersionsTableBody');
        tbody.innerHTML = '';
        if (data.success && data.versions && data.versions.length > 0) {
          data.versions.forEach(function(v) {
            var tr = document.createElement('tr');
            tr.style.borderBottom = '1px solid var(--border-color)';
            tr.innerHTML = '<td style="padding:8px 12px; font-weight:600; font-family:monospace;"><span class="nu-badge nu-badge-primary">' + v.pv_version_tag + '</span></td>' +
                           '<td style="padding:8px 12px; color:var(--text-secondary);">' + (v.pv_description || 'No release notes') + '</td>' +
                           '<td style="padding:8px 12px; font-size:12px;">' + (v.usr_username || 'System') + '</td>' +
                           '<td style="padding:8px 12px; font-size:12px; color:var(--text-tertiary);">' + v.pv_created_at + '</td>' +
                           '<td style="padding:8px 12px; text-align:right;"><button class="nu-btn nu-btn-secondary nu-btn-sm" onclick="ProjectsModule.restoreVersion(' + v.pv_id + ', \'' + v.pv_version_tag + '\')">↺ Restore</button></td>';
            tbody.appendChild(tr);
          });
        } else if (data.success) {
          tbody.innerHTML = '<tr><td colspan="5" style="padding:16px; text-align:center; color:var(--text-tertiary);">No version snapshots created yet.</td></tr>';
        } else {
          alert('Error loading versions: ' + (data.error || 'Unknown error'));
        }
      } catch (e) {
        alert('Network error loading versions: ' + e.message);
      }
    }

    async function createVersion() {
      var projId = $('projVersionsProjId').value;
      var tag    = $('projVerTag').value.trim();
      var desc   = $('projVerDesc').value.trim();

      if (!projId) return;

      try {
        var res = await fetch('api/projects.php?action=version_create', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ project_id: parseInt(projId), version_tag: tag, description: desc })
        });
        var data = await res.json();
        if (data.success) {
          $('projVerDesc').value = '';
          $('projVerTag').value = 'v' + new Date().toISOString().replace(/[^0-9]/g, '').slice(0, 14);
          await loadVersions(projId);
        } else {
          alert(data.error || 'Failed to create snapshot');
        }
      } catch (e) {
        alert('Network error creating snapshot');
      }
    }

    async function restoreVersion(versionId, tag) {
      if (!confirm('Are you sure you want to restore the project metadata to snapshot "' + tag + '"? Current forms, menus, reports, procedures, and workflows will be overwritten.')) return;

      try {
        var res = await fetch('api/projects.php?action=version_restore', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ version_id: versionId })
        });
        var data = await res.json();
        if (data.success) {
          alert(data.message || 'Version snapshot restored successfully!');
          window.location.reload();
        } else {
          alert(data.error || 'Failed to restore snapshot');
        }
      } catch (e) {
        alert('Network error restoring version');
      }
    }

    function closeVersionsModal() {
      $('projVersionsModal').style.display = 'none';
    }

    async function openMembers(id, name) {
      $('projMembersProjId').value = id;
      $('projMembersModalTitle').textContent = 'Project Members: ' + name;
      $('projMembersModal').style.display = 'flex';
      await loadMembers(id);
    }

    async function loadMembers(id) {
      try {
        var res = await fetch('api/projects.php?action=members_list&project_id=' + id);
        var data = await res.json();
        if (data.success) {
          // Populate user select dropdown
          var userSelect = $('projAddUserId');
          userSelect.innerHTML = '<option value="">Select user...</option>';
          (data.all_users || []).forEach(function(u) {
            var opt = document.createElement('option');
            opt.value = u.usr_id;
            opt.textContent = (u.usr_name || u.usr_username) + ' (' + u.usr_username + ')';
            userSelect.appendChild(opt);
          });

          // Populate members table
          var tbody = $('projMembersTableBody');
          tbody.innerHTML = '';
          if (data.members && data.members.length > 0) {
            data.members.forEach(function(m) {
              var tr = document.createElement('tr');
              tr.style.borderBottom = '1px solid var(--border-color)';
              tr.innerHTML = '<td style="padding:8px 12px; font-weight:500;">' + m.usr_username + '</td>' +
                             '<td style="padding:8px 12px;"><span class="nu-badge nu-badge-secondary" style="font-size:11px;">' + m.pm_role + '</span></td>' +
                             '<td style="padding:8px 12px; text-align:right;"><button class="nu-btn nu-btn-danger nu-btn-sm" onclick="ProjectsModule.removeMember(' + m.pm_user_id + ')">Remove</button></td>';
              tbody.appendChild(tr);
            });
          } else {
            tbody.innerHTML = '<tr><td colspan="3" style="padding:16px; text-align:center; color:var(--text-tertiary);">No members explicitly assigned. GlobeAdmin users access all projects.</td></tr>';
          }
        } else {
          alert(data.error || 'Failed to load members');
        }
      } catch (e) {
        alert('Network error loading members');
      }
    }

    async function addMember() {
      var projId = $('projMembersProjId').value;
      var userId = $('projAddUserId').value;
      var role   = $('projAddRole').value;

      if (!projId || !userId) {
        alert('Please select a user');
        return;
      }

      try {
        var res = await fetch('api/projects.php?action=member_add', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ project_id: parseInt(projId), user_id: parseInt(userId), role: role })
        });
        var data = await res.json();
        if (data.success) {
          await loadMembers(projId);
        } else {
          alert(data.error || 'Failed to add member');
        }
      } catch (e) {
        alert('Network error adding member');
      }
    }

    async function removeMember(userId) {
      var projId = $('projMembersProjId').value;
      if (!confirm('Remove this user from the project?')) return;

      try {
        var res = await fetch('api/projects.php?action=member_remove', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ project_id: parseInt(projId), user_id: parseInt(userId) })
        });
        var data = await res.json();
        if (data.success) {
          await loadMembers(projId);
        } else {
          alert(data.error || 'Failed to remove member');
        }
      } catch (e) {
        alert('Network error removing member');
      }
    }

    function toggleImportSource(mode) {
      if (mode === 'server') {
        if ($('projImportSourceUpload')) $('projImportSourceUpload').style.display = 'none';
        if ($('projImportSourceServer')) $('projImportSourceServer').style.display = 'block';
        loadServerPackages();
      } else {
        if ($('projImportSourceUpload')) $('projImportSourceUpload').style.display = 'block';
        if ($('projImportSourceServer')) $('projImportSourceServer').style.display = 'none';
      }
    }

    async function loadServerPackages() {
      var select = $('projServerFileSelect');
      if (!select) return;
      select.innerHTML = '<option value="">Scanning server folder...</option>';

      try {
        var res = await fetch('api/projects.php?action=list_server_packages');
        var data = await res.json();
        select.innerHTML = '';

        if (data.success && data.files && data.files.length > 0) {
          select.innerHTML = '<option value="">-- Choose a package file found on server --</option>';
          data.files.forEach(function(f) {
            var opt = document.createElement('option');
            opt.value = f.path;
            opt.textContent = f.name + ' (' + f.size_formatted + ' • ' + f.path + ')';
            select.appendChild(opt);
          });
        } else {
          select.innerHTML = '<option value="">No .sql or .zip package files found automatically in webserver root</option>';
        }
      } catch (e) {
        select.innerHTML = '<option value="">Failed to scan server directory</option>';
      }
    }

    function onServerFileSelect(val) {
      if ($('projServerFilePathInput')) {
        $('projServerFilePathInput').value = val || '';
      }
    }

    function openImportModal() {
      var statusDiv = $('projImportStatus');
      if (statusDiv) statusDiv.style.display = 'none';
      if ($('projImportFile')) $('projImportFile').value = '';
      if ($('projServerFilePathInput')) $('projServerFilePathInput').value = '';

      var defaultRadio = document.querySelector('input[name="projImportSource"][value="upload"]');
      if (defaultRadio) defaultRadio.checked = true;
      toggleImportSource('upload');

      if ($('projImportModal')) $('projImportModal').style.display = 'flex';
    }

    function closeImportModal() {
      if ($('projImportModal')) $('projImportModal').style.display = 'none';
    }

    async function executeProjectImport() {
      var sourceMode = 'upload';
      var radios = document.getElementsByName('projImportSource');
      for (var i = 0; i < radios.length; i++) {
        if (radios[i].checked) {
          sourceMode = radios[i].value;
          break;
        }
      }

      var statusDiv = $('projImportStatus');
      var btn = $('btnRunProjectImport');
      var formData = new FormData();

      if (sourceMode === 'server') {
        var serverPath = ($('projServerFilePathInput') ? $('projServerFilePathInput').value : '').trim();
        if (!serverPath) {
          alert('Please select or specify a valid relative webserver file path.');
          return;
        }
        formData.append('server_file_path', serverPath);
      } else {
        var fileInput = $('projImportFile');
        if (!fileInput || !fileInput.files.length) {
          alert('Please select a .sql or .zip project package file from your computer to upload.');
          return;
        }
        formData.append('package_file', fileInput.files[0]);
      }

      btn.disabled = true;
      btn.textContent = 'Importing Package...';
      if (statusDiv) {
        statusDiv.style.display = 'block';
        statusDiv.style.background = 'var(--bg-subtle)';
        statusDiv.style.color = 'var(--text-primary)';
        statusDiv.textContent = '⏳ Executing import and setting up project tables...';
      }

      try {
        var res = await fetch('api/projects.php?action=import_project', {
          method: 'POST',
          body: formData
        });
        var data = await res.json();
        btn.disabled = false;
        btn.textContent = '📥 Start Import';

        if (data.success) {
          if (statusDiv) {
            statusDiv.style.background = 'var(--success-light, #d1fae5)';
            statusDiv.style.color = 'var(--success, #065f46)';
            statusDiv.textContent = '✓ ' + (data.message || 'Project imported successfully! Reloading...');
          }
          setTimeout(function() {
            closeImportModal();
            window.location.reload();
          }, 1200);
        } else {
          if (statusDiv) {
            statusDiv.style.background = 'var(--danger-light, #fee2e2)';
            statusDiv.style.color = 'var(--danger, #991b1b)';
            statusDiv.textContent = '✗ ' + (data.error || 'Project import failed.');
          }
        }
      } catch (e) {
        btn.disabled = false;
        btn.textContent = '📥 Start Import';
        if (statusDiv) {
          statusDiv.style.background = 'var(--danger-light, #fee2e2)';
          statusDiv.style.color = 'var(--danger, #991b1b)';
          statusDiv.textContent = '✗ Network or server error during project import.';
        }
      }
    }

    function closeMembersModal() {
      $('projMembersModal').style.display = 'none';
    }

    async function openShare(id, name) {
      $('projShareProjId').value = id;
      $('projShareModalTitle').textContent = 'Share Project for Testing: ' + name;
      $('projShareModal').style.display = 'flex';
      await loadShareSettings(id);
    }

    async function loadShareSettings(id) {
      try {
        var res = await fetch('api/projects.php?action=get_share_settings&project_id=' + id);
        var data = await res.json();
        if (data.success) {
          var isPublic = !!data.is_public_test;
          $('projShareToggle').checked = isPublic;
          $('projShareMode').value = data.share_mode || 'guest';
          $('projShareUrlInput').value = data.share_url || '';
          updateSliderStyle(isPublic);
          $('projShareConfigArea').style.display = isPublic ? 'block' : 'none';
        } else {
          alert(data.error || 'Failed to load share settings');
        }
      } catch (e) {
        alert('Network error loading share settings');
      }
    }

    function updateSliderStyle(checked) {
      var slider = $('projShareSlider');
      if (slider) {
        slider.style.backgroundColor = checked ? '#10b981' : '#ccc';
      }
    }

    async function toggleShare() {
      var projId  = $('projShareProjId').value;
      var enabled = $('projShareToggle').checked;
      var mode    = $('projShareMode').value;

      updateSliderStyle(enabled);

      try {
        var res = await fetch('api/projects.php?action=toggle_share', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ project_id: parseInt(projId), enabled: enabled ? 1 : 0, share_mode: mode })
        });
        var data = await res.json();
        if (data.success) {
          $('projShareUrlInput').value = data.share_url || '';
          $('projShareConfigArea').style.display = enabled ? 'block' : 'none';
        } else {
          alert(data.error || 'Failed to update share settings');
          $('projShareToggle').checked = !enabled;
          updateSliderStyle(!enabled);
        }
      } catch (e) {
        alert('Network error updating share settings');
      }
    }

    async function regenerateShareToken() {
      var projId = $('projShareProjId').value;
      if (!confirm('Regenerate testing link? Any previously shared test links for this project will stop working.')) return;

      try {
        var res = await fetch('api/projects.php?action=regenerate_share_token', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ project_id: parseInt(projId) })
        });
        var data = await res.json();
        if (data.success) {
          $('projShareUrlInput').value = data.share_url || '';
          alert('New share token generated successfully!');
        } else {
          alert(data.error || 'Failed to regenerate token');
        }
      } catch (e) {
        alert('Network error regenerating token');
      }
    }

    function copyShareUrl() {
      var input = $('projShareUrlInput');
      if (!input || !input.value) return;
      input.select();
      navigator.clipboard.writeText(input.value).then(function() {
        var btn = $('copyShareBtn');
        if (btn) {
          var orig = btn.textContent;
          btn.textContent = '✓ Copied!';
          setTimeout(function() { btn.textContent = orig; }, 2000);
        }
      });
    }

    function closeShareModal() {
      $('projShareModal').style.display = 'none';
    }

    function _destroy() {
      window.ProjectsModule = null;
    }

    return {
      openNew: openNew,
      edit: edit,
      closeModal: closeModal,
      saveProject: saveProject,
      switchProject: switchProject,
      delete: delProject,
      openExport: openExport,
      openExportZip: openExportZip,
      openVersions: openVersions,
      createVersion: createVersion,
      restoreVersion: restoreVersion,
      closeVersionsModal: closeVersionsModal,
      openMembers: openMembers,
      addMember: addMember,
      removeMember: removeMember,
      closeMembersModal: closeMembersModal,
      openShare: openShare,
      toggleShare: toggleShare,
      regenerateShareToken: regenerateShareToken,
      copyShareUrl: copyShareUrl,
      closeShareModal: closeShareModal,
      openImportModal: openImportModal,
      closeImportModal: closeImportModal,
      executeProjectImport: executeProjectImport,
      toggleImportSource: toggleImportSource,
      loadServerPackages: loadServerPackages,
      onServerFileSelect: onServerFileSelect,
      autoCode: autoCode,
      filterList: filterList,
      _destroy: _destroy
    };
  })();
})();
</script>
