<?php
/**
 * views/header.php - Header & Top Actions UI partial
 */
declare(strict_types=1);
?>
<header class="nu-header">
    <button class="nu-menu-btn" id="menuBtn" title="Toggle sidebar"
            onclick="(function(){
                var app = document.getElementById('nuApp');
                var isMobile = window.innerWidth <= 768;
                if (isMobile) {
                    document.getElementById('sidebar').classList.toggle('open');
                    document.getElementById('overlay').classList.toggle('open');
                } else {
                    app.classList.toggle('sidebar-collapsed');
                    try { localStorage.setItem('nu-sidebar-collapsed', app.classList.contains('sidebar-collapsed') ? '1' : '0'); } catch(e){}
                }
            })()">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="3" y1="12" x2="21" y2="12"/>
            <line x1="3" y1="6" x2="21" y2="6"/>
            <line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
    </button>
    <?php
    require_once NU_ROOT . '/core/AppVersion.php';
    $activeProjectId = ProjectContext::getId();
    $activeProjRow = NuDatabase::getInstance()->fetchOne("SELECT project_settings FROM nu_projects WHERE project_id = ?", [$activeProjectId]);
    $projSettings = json_decode((string)($activeProjRow['project_settings'] ?? '{}'), true) ?: [];
    $projTheme = $projSettings['theme'] ?? null;
    ?>
    <?php if (!empty($projTheme) && is_array($projTheme)): ?>
    <style id="nuProjectThemeStyle">
    :root {
      <?php if (!empty($projTheme['primary_color'])): ?>
        --primary: <?= h($projTheme['primary_color']) ?> !important;
        --color-primary: <?= h($projTheme['primary_color']) ?> !important;
      <?php endif; ?>
      <?php if (!empty($projTheme['primary_hover'])): ?>
        --primary-hover: <?= h($projTheme['primary_hover']) ?> !important;
      <?php endif; ?>
      <?php if (!empty($projTheme['sidebar_bg'])): ?>
        --bg-sidebar: <?= h($projTheme['sidebar_bg']) ?> !important;
      <?php endif; ?>
      <?php if (!empty($projTheme['sidebar_text'])): ?>
        --text-sidebar: <?= h($projTheme['sidebar_text']) ?> !important;
      <?php endif; ?>
      <?php if (!empty($projTheme['accent_color'])): ?>
        --accent: <?= h($projTheme['accent_color']) ?> !important;
      <?php endif; ?>
    }
    </style>
    <?php endif; ?>
    <div style="display: flex; align-items: center; gap: 8px;">
        <h2 class="nu-page-title" id="pageTitle" style="margin:0;">Dashboard</h2>
        <span class="version-badge" title="Released: <?= h(AppVersion::get('released_at')) ?>" style="font-size: 10px; font-weight: 700; color: var(--text-muted); background: var(--bg-subtle, #f8fafc); border: 1px solid var(--border-color, #e2e8f0); padding: 2px 8px; border-radius: 9999px;">
            <?= h(AppVersion::get('full')) ?>
        </span>
    </div>
    <div class="nu-header-actions">
        <?php if ($isLoggedIn): ?>
        <?php
            $activeProjectId = ProjectContext::getId();
            $activeProjectCode = $_SESSION['nu_project_code'] ?? 'default';
            $accessibleProjects = ProjectContext::getAccessibleProjects();
        ?>
        <div class="nu-project-switcher" style="position: relative; display: inline-flex; align-items: center; gap: 6px; margin-right: 8px;">
            <span class="nu-project-badge" style="font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 12px; background: rgba(37,99,235,0.12); color: var(--primary); border: 1px solid rgba(37,99,235,0.3);" title="Active Project Slug">
                ⚡ <?= h($activeProjectCode) ?>
            </span>
            <?php if (!empty($_SESSION['nu_project_share_active']) || ($_SESSION['nu_user_id'] ?? '') === 'guest_tester'): ?>
                <span class="nu-badge nu-badge-warning" style="font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 10px; background: #fff3cd; color: #856404; border: 1px solid #ffeeba;" title="Online Testing Session Active">
                    🧪 Test Mode
                </span>
            <?php endif; ?>
            <select id="nuProjectSelect" onchange="nuSwitchProject(this.value)" class="nu-input" style="padding: 5px 10px; font-weight: 600; font-size: 13px; border-radius: 6px; cursor: pointer; background: var(--bg-card); color: var(--text-primary); border: 1px solid var(--border-color);" title="Switch Active Project">
                <?php foreach ($accessibleProjects as $proj): ?>
                    <option value="<?= (int)$proj['project_id'] ?>" <?= (int)$proj['project_id'] === $activeProjectId ? 'selected' : '' ?>>
                        📁 <?= h($proj['project_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($_role === 'globeadmin'): ?>
            <button class="nu-btn nu-btn-primary nu-btn-sm" style="padding: 4px 10px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; border-radius: 6px;" onclick="nuOpenQuickNewProjectModal()" title="Create New Project">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <span>New Project</span>
            </button>
            <?php endif; ?>
        </div>
        <script>
        function nuSwitchProject(pid) {
            if (!pid) return;
            fetch('api/projects.php?action=switch', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ project_id: parseInt(pid) })
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success) {
                    window.location.reload();
                } else {
                    alert(res.error || 'Failed to switch project');
                }
            })
            .catch(function(err) { alert('Network error switching project'); });
        }

        function nuOpenQuickNewProjectModal() {
            var modal = document.getElementById('nuQuickNewProjectModal');
            if (modal) {
                modal.style.display = 'flex';
                var codeInput = document.getElementById('nuQuickProjCode');
                if (codeInput) codeInput.focus();
            }
        }

        function nuCloseQuickNewProjectModal() {
            var modal = document.getElementById('nuQuickNewProjectModal');
            if (modal) modal.style.display = 'none';
        }

        function nuQuickCreateProjectSubmit(e) {
            e.preventDefault();
            var code = document.getElementById('nuQuickProjCode').value.trim();
            var name = document.getElementById('nuQuickProjName').value.trim();
            var desc = document.getElementById('nuQuickProjDesc').value.trim();

            if (!code || !name) {
                alert('Project Code and Name are required.');
                return;
            }

            var btn = document.getElementById('nuQuickProjSubmitBtn');
            if (btn) { btn.disabled = true; btn.textContent = 'Creating...'; }

            fetch('api/projects.php?action=create', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    project_code: code,
                    project_name: name,
                    project_description: desc
                })
            })
            .then(function(r) {
                return r.text().then(function(text) {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        throw new Error(text || ('HTTP ' + r.status + ' ' + r.statusText));
                    }
                });
            })
            .then(function(res) {
                if (res.success) {
                    window.location.reload();
                } else {
                    alert(res.error || 'Failed to create project');
                    if (btn) { btn.disabled = false; btn.textContent = 'Create Project'; }
                }
            })
            .catch(function(err) {
                alert('Error submitting project creation: ' + (err.message || 'Server error or permission denied'));
                if (btn) { btn.disabled = false; btn.textContent = 'Create Project'; }
            });
        }
        </script>
        <?php endif; ?>

        <button type="button" class="nu-cmdk-trigger" data-nu-cmdk title="Search pages and actions" aria-label="Open command palette">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            <span>Search…</span><kbd>Ctrl K</kbd>
        </button>

        <button class="nu-btn nu-btn-ghost" id="nuThemeToggleBtn" title="Theme: Auto (System)"
                onclick="(function(){
                    var btn=document.getElementById('nuThemeToggleBtn');
                    var t=document.documentElement.getAttribute('data-theme')||'auto';
                    var n=t==='light'?'dark':t==='dark'?'auto':'light';
                    document.documentElement.setAttribute('data-theme',n);
                    if(btn) btn.title = 'Theme: ' + (n==='light'?'Light':n==='dark'?'Dark':'Auto (System)');
                    try{localStorage.setItem('nu-theme',n);}catch(e){}
                })()">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="5"/>
                <line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/>
                <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                <line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/>
                <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
            </svg>
        </button>

        <!-- User Profile Dropdown -->
        <div class="nu-user-dropdown" id="nuUserDropdown">
            <button class="nu-user-dropdown-toggle" id="nuUserDropdownToggle" type="button" aria-haspopup="true" aria-expanded="false" title="User Menu">
                <svg class="nu-user-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display: none; flex-shrink: 0; color: var(--text-secondary);">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                </svg>
                <span class="nu-user-dropdown-text"><?= h($userHeaderDisplay) ?></span>
                <svg class="nu-user-dropdown-caret" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="nu-user-dropdown-menu" id="nuUserDropdownMenu">
                <div class="nu-user-dropdown-header">
                    <div class="nu-dropdown-user-name"><?= h(($currentUser['usr_name'] ?? '') ?: ($currentUser['usr_username'] ?? 'User')) ?></div>
                    <div class="nu-dropdown-user-role"><?= h($currentUser['usr_role'] ?? '') ?></div>
                </div>
                <div class="nu-user-dropdown-divider"></div>
                <a href="#password" class="nu-user-dropdown-item" onclick="NuApp.loadModule('password'); return false;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        <circle cx="12" cy="16" r="1" fill="currentColor"/>
                    </svg>
                    <span>Change Password</span>
                </a>
                <div class="nu-user-dropdown-divider"></div>
                <form method="post" action="index.php" style="margin: 0; padding: 0;">
                    <input type="hidden" name="nu_csrf" value="<?= h($csrfToken) ?>">
                    <button type="submit" name="logout" value="1" class="nu-user-dropdown-item nu-user-dropdown-logout" style="width: 100%;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                            <polyline points="16 17 21 12 16 7"/>
                            <line x1="21" y1="12" x2="9" y2="12"/>
                        </svg>
                        <span>Logout</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>
