<?php
/**
 * views/sidebar.php - Sidebar Navigation UI partial
 */
declare(strict_types=1);
?>
<aside class="nu-sidebar" id="sidebar">
    <div class="nu-sidebar-header">
        <?php if ($customAppLogo !== ''): ?>
            <img src="<?= h($customAppLogo) ?>" alt="Logo" class="nu-custom-logo" style="max-height: 32px; border-radius: 4px; margin-right: 8px;">
        <?php else: ?>
            <div class="nu-logo">nu</div>
        <?php endif; ?>
        <span class="nu-version" style="flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= h($customAppName) ?>"><?= h($customAppName) ?></span>
    </div>

    <?php if ($dynNav !== ''): ?>
        <?= $dynNav ?>
    <?php else: ?>
    <!-- ══ Static fallback nav (shown until nu_menus is populated) ══ -->
    <nav class="nu-nav">

        <!-- ── Main ── -->
        <div style="margin:12px 8px 4px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted,#888);padding:0 4px;">Main</div>
        <a href="#dashboard" class="nu-nav-item" data-module="dashboard"
           onclick="NuApp.loadModule('dashboard'); return false;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
                <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
            </svg>
            <span>Dashboard</span>
        </a>
        <a href="#forms" class="nu-nav-item" data-module="forms"
           onclick="NuApp.loadModule('forms'); return false;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
                <line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
            </svg>
            <span>Forms</span>
        </a>
        <a href="#report_dashboards" class="nu-nav-item" data-module="report_dashboards"
           onclick="NuApp.loadModule('report_dashboards'); return false;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/>
            </svg>
            <span>Reports Dashboard </span>
        </a>
          <a href="#reports" class="nu-nav-item" data-module="reports"
           onclick="NuApp.loadModule('reports'); return false;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/>
            </svg>
            <span>Reports</span>
        </a>
        <a href="#queries" class="nu-nav-item" data-module="queries"
           onclick="NuApp.loadModule('queries'); return false;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <ellipse cx="12" cy="5" rx="9" ry="3"/>
                <path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/>
                <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>
            </svg>
            <span>Queries</span>
        </a>
        <a href="#calendar" class="nu-nav-item" data-module="calendar"
           onclick="NuApp.loadModule('calendar'); return false;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                <line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/>
                <line x1="3" y1="10" x2="21" y2="10"/>
            </svg>
            <span>Calendar</span>
        </a>
        <a href="#ai" class="nu-nav-item" data-module="ai"
           onclick="NuApp.loadModule('ai'); return false;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 2a10 10 0 1 0 10 10H12V2z"/>
                <path d="M12 2a10 10 0 0 1 10 10"/>
            </svg>
            <span>AI Assistant</span>
        </a>
        <a href="#integrations" class="nu-nav-item" data-module="integrations"
           onclick="NuApp.loadModule('integrations'); return false;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
                <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
            </svg>
            <span>Webhooks</span>
        </a>
        <?php if (!empty($nuConfig['enableWebhookDemo'])): ?>
        <a href="webhook_demo.php" target="_blank" class="nu-nav-item" style="color: var(--accent, #4f6bed);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polygon points="5 3 19 12 5 21 5 3"/>
            </svg>
            <span style="font-weight: 600;">Playground Demo</span>
        </a>
        <?php endif; ?>

        <!-- ── Admin Tools section ── -->
        <?php if ($isAdmin): ?>
        <div style="margin:12px 8px 4px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted,#888);padding:0 4px;">Admin Tools</div>
        <a href="#projects" class="nu-nav-item" data-module="projects"
           onclick="NuApp.loadModule('projects'); return false;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
            </svg>
            <span>Projects</span>
        </a>
        <a href="#menus" class="nu-nav-item" data-module="menus"
           onclick="NuApp.loadModule('menus'); return false;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="3" y1="12" x2="21" y2="12"/>
                <line x1="3" y1="6" x2="21" y2="6"/>
                <line x1="3" y1="18" x2="21" y2="18"/>
                <circle cx="20" cy="6" r="2" fill="currentColor" stroke="none"/>
                <circle cx="20" cy="12" r="2" fill="currentColor" stroke="none"/>
                <circle cx="20" cy="18" r="2" fill="currentColor" stroke="none"/>
            </svg>
            <span>Menus</span>
        </a>
         <a href="#email_settings" class="nu-nav-item" data-module="email_settings"
           onclick="NuApp.loadModule('email_settings'); return false;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="3" y1="12" x2="21" y2="12"/>
                <line x1="3" y1="6" x2="21" y2="6"/>
                <line x1="3" y1="18" x2="21" y2="18"/>
                <circle cx="20" cy="6" r="2" fill="currentColor" stroke="none"/>
                <circle cx="20" cy="12" r="2" fill="currentColor" stroke="none"/>
                <circle cx="20" cy="18" r="2" fill="currentColor" stroke="none"/>
            </svg>
            <span>Email Settings</span>
        </a>
        <a href="#users" class="nu-nav-item" data-module="users"
           onclick="NuApp.loadModule('users'); return false;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
            <span>Users</span>
        </a>
        <a href="#roles" class="nu-nav-item" data-module="roles"
           onclick="NuApp.loadModule('roles'); return false;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <span>Roles</span>
        </a>
        <a href="#audit" class="nu-nav-item" data-module="audit"
           onclick="NuApp.loadModule('audit'); return false;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
                <line x1="16" y1="13" x2="8" y2="13"/>
                <line x1="16" y1="17" x2="8" y2="17"/>
                <circle cx="17" cy="17" r="3"/>
                <line x1="21" y1="21" x2="19.1" y2="19.1"/>
            </svg>
            <span>Audit Trail</span>
        </a>
        <a href="#files" class="nu-nav-item" data-module="files"
           onclick="NuApp.loadModule('files'); return false;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
            </svg>
            <span>Files</span>
        </a>
        <a href="#cloud_storage" class="nu-nav-item" data-module="cloud_storage"
           onclick="NuApp.loadModule('cloud_storage'); return false;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/>
            </svg>
            <span>Cloud Storage</span>
        </a>
        <a href="#workflow" class="nu-nav-item" data-module="workflow"
           onclick="NuApp.loadModule('workflow'); return false;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
            </svg>
            <span>Workflow</span>
        </a>
        <a href="#inspector" class="nu-nav-item" data-module="inspector"
           onclick="NuApp.loadModule('inspector'); return false;"
           style="color:var(--warning,#f59e0b);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <ellipse cx="12" cy="5" rx="9" ry="3"/>
                <path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/>
                <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>
                <line x1="19" y1="19" x2="23" y2="23"/>
                <circle cx="19" cy="19" r="3"/>
            </svg>
            <span>DB &amp; Server Inspector</span>
        </a>
        <a href="#errorlog" class="nu-nav-item" data-module="errorlog"
           onclick="NuApp.loadModule('errorlog'); return false;"
           style="color:var(--warning,#f59e0b);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <span>Error Log</span>
        </a>
        <a href="#password_policy" class="nu-nav-item" data-module="password_policy"
           onclick="NuApp.loadModule('password_policy'); return false;"
           style="color:var(--warning,#f59e0b);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                <polyline points="9 12 11 14 15 10"/>
            </svg>
            <span>Password Policy</span>
        </a>
        <a href="#appcloner" class="nu-nav-item" data-module="appcloner"
           onclick="NuApp.loadModule('appcloner'); return false;"
           style="color:var(--warning,#f59e0b);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
            </svg>
            <span>App Cloner</span>
        </a>
        <a href="#updater" class="nu-nav-item" data-module="updater"
           onclick="NuApp.loadModule('updater'); return false;"
           style="color:var(--warning,#f59e0b);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/>
            </svg>
            <span>System Updater</span>
        </a>
        <?php if ($_role === 'globeadmin'): ?>
        <a href="#developer_settings" class="nu-nav-item" data-module="developer_settings"
           onclick="NuApp.loadModule('developer_settings'); return false;"
           style="color:var(--warning,#f59e0b);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
            </svg>
            <span>Developer Settings</span>
        </a>
        <a href="#system_demo_files" class="nu-nav-item" data-module="system_demo_files"
           onclick="NuApp.loadModule('system_demo_files'); return false;"
           style="color:var(--warning,#f59e0b);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
            </svg>
            <span>System Demo Files</span>
        </a>
        <?php endif; ?>
        <?php endif; ?>

    </nav>
    <?php endif; ?>
</aside>
