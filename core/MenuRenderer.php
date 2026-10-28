<?php
/**
 * NuMenuRenderer
 * Renders the sidebar <nav> from nu_menus, filtered by the current user's role.
 * PHP 8.1+ compatible.
 */
class NuMenuRenderer
{
    // No typed property declarations — use plain assignments
    private static $icons = array(
        'dashboard'  => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
        'forms'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
        'file-text'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
        'reports'    => '<path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/>',
        'pie-chart'  => '<path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/>',
        'queries'    => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
        'database'   => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
        'menus'      => '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'users'      => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'roles'      => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
        'shield'     => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/>',
        'audit'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><circle cx="17" cy="17" r="3"/><line x1="21" y1="21" x2="19.1" y2="19.1"/>',
        'clipboard'  => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/>',
        'files'      => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
        'paperclip'  => '<path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>',
        'workflow'   => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'calendar'   => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'ai'         => '<path d="M12 2a10 10 0 1 0 10 10H12V2z"/><path d="M12 2a10 10 0 0 1 10 10"/>',
        'link'       => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'password'   => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/><circle cx="12" cy="16" r="1" fill="currentColor"/>',
        'lock'       => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'alert'      => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
        'copy'       => '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'refresh'    => '<path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/>',
        'inspector'  => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
        'layout'     => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/>',
        'divider'    => '',
        'group'      => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
        'barcode'    => '<path d="M3 5v14M6 5v14M8 5v14M11 5v14M14 5v14M17 5v14M19 5v14M21 5v14"/>',
        'default'    => '<circle cx="12" cy="12" r="9"/>',
    );

    // Types that support open-mode
    private static $openModeTypes = array('form', 'report', 'query');

    /**
     * Check whether a menu row is accessible by the given role.
     * PHP 8.1+ safe.
     */
    private static function isAccessible(array $item, $userRole, $isAdmin)
    {
        if ($isAdmin) return true;

        // Standardize on menu_role_access as the single canonical source of truth.
        // If menu_role_access is missing or blank, we fall back to menu_roles.
        $raw = isset($item['menu_role_access']) ? trim((string)$item['menu_role_access']) : '';
        if ($raw === '') {
            $raw = isset($item['menu_roles']) ? trim((string)$item['menu_roles']) : '';
        }

        if ($raw === '' || $raw === '[]' || $raw === 'null') return true;

        // JSON array format  e.g. ["admin","globeadmin"]
        if (isset($raw[0]) && $raw[0] === '[') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $allowed = array_map('strtolower', array_map('trim', $decoded));
                return in_array($userRole, $allowed, true);
            }
        }

        // Legacy comma-separated  e.g. "admin,globeadmin"
        $allowed = array_map('strtolower', array_map('trim', explode(',', $raw)));
        return in_array($userRole, $allowed, true);
    }

    public static function render($currentUser)
    {
        $userRole = strtolower((string)(isset($currentUser['usr_role']) ? $currentUser['usr_role'] : ''));
        $isAdmin  = ($userRole === 'globeadmin' || $userRole === 'admin');

        $rows = array();
        try {
            if (!class_exists('ProjectContext')) {
                require_once __DIR__ . '/ProjectContext.php';
            }
            $db  = NuDatabase::getInstance();
            $pid = ProjectContext::getId();

            // Self-healing: Ensure Import / Export and Developer Settings menu items exist in nu_menus table
            try {
                $exists = $db->fetchOne("SELECT menu_id FROM nu_menus WHERE menu_target = 'import_export' AND project_id = ?", [$pid]);
                if (!$exists) {
                    $adminGroup = $db->fetchOne("SELECT menu_id FROM nu_menus WHERE menu_label = 'Admin Tools' AND menu_type = 'group' AND project_id = ?", [$pid]);
                    $parentId = $adminGroup ? (int)$adminGroup['menu_id'] : 0;
                    $db->insert('nu_menus', [
                        'menu_label'        => 'Import / Export',
                        'menu_type'         => 'form',
                        'menu_target'       => 'import_export',
                        'menu_parent_id'    => $parentId,
                        'menu_order'        => 85,
                        'menu_roles'        => 'globeadmin,admin',
                        'menu_role_access'  => '["globeadmin","admin"]',
                        'menu_active'       => 1,
                        'menu_icon'         => 'clipboard',
                        'menu_open_mode'    => 'inline|browse',
                        'menu_browse_mode'  => 'inline',
                        'menu_preview_mode' => 'inline',
                        'menu_default_view' => 'browse',
                        'project_id'        => $pid
                    ]);
                }

                $existsAi = $db->fetchOne("SELECT menu_id FROM nu_menus WHERE menu_target = 'ai' AND project_id = ?", [$pid]);
                if (!$existsAi) {
                    $adminGroup = $db->fetchOne("SELECT menu_id FROM nu_menus WHERE menu_label = 'Admin Tools' AND menu_type = 'group' AND project_id = ?", [$pid]);
                    $parentId = $adminGroup ? (int)$adminGroup['menu_id'] : null;
                    $db->insert('nu_menus', [
                        'menu_label' => 'AI Agents Studio',
                        'menu_type'  => 'module',
                        'menu_target' => 'ai',
                        'menu_parent_id' => $parentId,
                        'menu_order' => 12,
                        'menu_roles' => 'globeadmin',
                        'menu_role_access' => '["globeadmin"]',
                        'menu_active' => 1,
                        'menu_icon' => 'fas fa-robot',
                        'project_id' => $pid
                    ]);
                }

                $existsCloud = $db->fetchOne("SELECT menu_id FROM nu_menus WHERE menu_target = 'cloud_storage' AND project_id = ?", [$pid]);
                if (!$existsCloud) {
                    $adminGroup = $db->fetchOne("SELECT menu_id FROM nu_menus WHERE menu_label = 'Admin Tools' AND menu_type = 'group' AND project_id = ?", [$pid]);
                    $db->insert('nu_menus', [
                        'menu_label'        => 'Cloud Storage',
                        'menu_type'         => 'module',
                        'menu_target'       => 'cloud_storage',
                        'menu_parent_id'    => $adminGroup ? (int)$adminGroup['menu_id'] : 0,
                        'menu_order'        => 32,
                        'menu_roles'        => 'globeadmin,admin',
                        'menu_role_access'  => '["globeadmin","admin"]',
                        'menu_active'       => 1,
                        'menu_icon'         => 'files',
                        'menu_open_mode'    => 'inline|browse',
                        'menu_browse_mode'  => 'inline',
                        'menu_preview_mode' => 'inline',
                        'menu_default_view' => 'browse',
                        'project_id'        => $pid
                    ]);
                }

                $existsProjects = $db->fetchOne("SELECT menu_id FROM nu_menus WHERE menu_target = 'projects' AND project_id = ?", [$pid]);
                if (!$existsProjects) {
                    $adminGroup = $db->fetchOne("SELECT menu_id FROM nu_menus WHERE menu_label = 'Admin Tools' AND menu_type = 'group' AND project_id = ?", [$pid]);
                    $parentId = $adminGroup ? (int)$adminGroup['menu_id'] : 0;
                    $db->insert('nu_menus', [
                        'menu_label'        => 'Projects',
                        'menu_type'         => 'module',
                        'menu_target'       => 'projects',
                        'menu_parent_id'    => $parentId,
                        'menu_order'        => 5,
                        'menu_roles'        => 'globeadmin,admin',
                        'menu_role_access'  => '["globeadmin","admin"]',
                        'menu_active'       => 1,
                        'menu_icon'         => 'files',
                        'menu_open_mode'    => 'inline|browse',
                        'menu_browse_mode'  => 'inline',
                        'menu_preview_mode' => 'inline',
                        'menu_default_view' => 'browse',
                        'project_id'        => $pid
                    ]);
                } else {
                    // Self-healing: ensure type is module so it loads modules/projects/projects.php rather than searching nu_forms
                    $db->query("UPDATE nu_menus SET menu_type = 'module' WHERE menu_target = 'projects' AND menu_type = 'form'");
                }

                $existsApi = $db->fetchOne("SELECT menu_id FROM nu_menus WHERE menu_target = 'api_manager'");
                if (!$existsApi) {
                    $adminGroup = $db->fetchOne("SELECT menu_id FROM nu_menus WHERE menu_label = 'Admin Tools' AND menu_type = 'group'");
                    $parentId = $adminGroup ? (int)$adminGroup['menu_id'] : 0;
                    $db->insert('nu_menus', [
                        'menu_label'        => 'API Designer',
                        'menu_type'         => 'form',
                        'menu_target'       => 'api_manager',
                        'menu_parent_id'    => $parentId,
                        'menu_order'        => 96,
                        'menu_roles'        => 'globeadmin,admin',
                        'menu_role_access'  => '["globeadmin","admin"]',
                        'menu_active'       => 1,
                        'menu_icon'         => 'link',
                        'menu_open_mode'    => 'inline|browse',
                        'menu_browse_mode'  => 'inline',
                        'menu_preview_mode' => 'inline',
                        'menu_default_view' => 'browse'
                    ]);
                }

                $existsDev = $db->fetchOne("SELECT menu_id FROM nu_menus WHERE menu_target = 'developer_settings'");
                if (!$existsDev) {
                    $adminGroup = $db->fetchOne("SELECT menu_id FROM nu_menus WHERE menu_label = 'Admin Tools' AND menu_type = 'group'");
                    $parentId = $adminGroup ? (int)$adminGroup['menu_id'] : 0;
                    $db->insert('nu_menus', [
                        'menu_label'        => 'Developer Settings',
                        'menu_type'         => 'form',
                        'menu_target'       => 'developer_settings',
                        'menu_parent_id'    => $parentId,
                        'menu_order'        => 95,
                        'menu_roles'        => 'globeadmin',
                        'menu_role_access'  => '["globeadmin"]',
                        'menu_active'       => 1,
                        'menu_icon'         => 'layout',
                        'menu_open_mode'    => 'inline|browse',
                        'menu_browse_mode'  => 'inline',
                        'menu_preview_mode' => 'inline',
                        'menu_default_view' => 'browse'
                    ]);
                }

                $existsWord = $db->fetchOne("SELECT menu_id FROM nu_menus WHERE menu_target = 'word_certificates'");
                if (!$existsWord) {
                    $adminGroup = $db->fetchOne("SELECT menu_id FROM nu_menus WHERE menu_label = 'Admin Tools' AND menu_type = 'group'");
                    $parentId = $adminGroup ? (int)$adminGroup['menu_id'] : 0;
                    $db->insert('nu_menus', [
                        'menu_label'        => 'Word Certificates',
                        'menu_type'         => 'form',
                        'menu_target'       => 'word_certificates',
                        'menu_parent_id'    => $parentId,
                        'menu_order'        => 90,
                        'menu_roles'        => 'globeadmin,admin',
                        'menu_role_access'  => '["globeadmin","admin"]',
                        'menu_active'       => 1,
                        'menu_icon'         => 'clipboard',
                        'menu_open_mode'    => 'inline|browse',
                        'menu_browse_mode'  => 'inline',
                        'menu_preview_mode' => 'inline',
                        'menu_default_view' => 'browse'
                    ]);
                }

                $existsBarcode = $db->fetchOne("SELECT menu_id FROM nu_menus WHERE menu_target = 'barcode'");
                if (!$existsBarcode) {
                    $db->insert('nu_menus', [
                        'menu_label'        => 'Barcode Manager',
                        'menu_type'         => 'form',
                        'menu_target'       => 'barcode',
                        'menu_parent_id'    => 0,
                        'menu_order'        => 35,
                        'menu_roles'        => '',
                        'menu_role_access'  => '[]',
                        'menu_active'       => 1,
                        'menu_icon'         => 'barcode',
                        'menu_open_mode'    => 'inline|browse',
                        'menu_browse_mode'  => 'inline',
                        'menu_preview_mode' => 'inline',
                        'menu_default_view' => 'browse'
                    ]);
                }

                $existsProcedures = $db->fetchOne("SELECT menu_id FROM nu_menus WHERE menu_target = 'procedures'");
                if (!$existsProcedures) {
                    $adminGroup = $db->fetchOne("SELECT menu_id FROM nu_menus WHERE menu_label = 'Admin Tools' AND menu_type = 'group'");
                    $parentId = $adminGroup ? (int)$adminGroup['menu_id'] : 0;
                    $db->insert('nu_menus', [
                        'menu_label'        => 'Custom PHP Functions',
                        'menu_type'         => 'form',
                        'menu_target'       => 'procedures',
                        'menu_parent_id'    => $parentId,
                        'menu_order'        => 92,
                        'menu_roles'        => 'globeadmin,admin',
                        'menu_role_access'  => '["globeadmin","admin"]',
                        'menu_active'       => 1,
                        'menu_icon'         => 'shield',
                        'menu_open_mode'    => 'inline|browse',
                        'menu_browse_mode'  => 'inline',
                        'menu_preview_mode' => 'inline',
                        'menu_default_view' => 'browse'
                    ]);
                }

                // Self-healing: Ensure "System Demo Files" menu items exist in nu_menus table (for globeadmin only)
                $existsDemoGroup = $db->fetchOne("SELECT menu_id FROM nu_menus WHERE menu_label = 'System Demo Files' AND menu_type = 'group'");
                if (!$existsDemoGroup) {
                    $db->insert('nu_menus', [
                        'menu_label'        => 'System Demo Files',
                        'menu_type'         => 'group',
                        'menu_target'       => '',
                        'menu_parent_id'    => 0,
                        'menu_order'        => 120,
                        'menu_roles'        => 'globeadmin',
                        'menu_role_access'  => '["globeadmin"]',
                        'menu_active'       => 1,
                        'menu_icon'         => 'clipboard',
                    ]);
                    $existsDemoGroup = $db->fetchOne("SELECT menu_id FROM nu_menus WHERE menu_label = 'System Demo Files' AND menu_type = 'group'");
                }
                $demoParentId = $existsDemoGroup ? (int)$existsDemoGroup['menu_id'] : 0;

                // 1. Interactive Developer Dashboard Menu Target
                $existsDemoDashboard = $db->fetchOne("SELECT menu_id FROM nu_menus WHERE menu_target = 'system_demo_files'");
                if (!$existsDemoDashboard) {
                    $db->insert('nu_menus', [
                        'menu_label'        => 'Developer Demo',
                        'menu_type'         => 'form',
                        'menu_target'       => 'system_demo_files',
                        'menu_parent_id'    => $demoParentId,
                        'menu_order'        => 121,
                        'menu_roles'        => 'globeadmin',
                        'menu_role_access'  => '["globeadmin"]',
                        'menu_active'       => 1,
                        'menu_icon'         => 'layout',
                        'menu_open_mode'    => 'inline|browse',
                        'menu_browse_mode'  => 'inline',
                        'menu_preview_mode' => 'inline',
                        'menu_default_view' => 'browse'
                    ]);
                }

                // 2. Service Types Forms Menu Target
                $existsDemoTypes = $db->fetchOne("SELECT menu_id FROM nu_menus WHERE menu_target = 'demo_service_types'");
                if (!$existsDemoTypes) {
                    $db->insert('nu_menus', [
                        'menu_label'        => 'Service Types',
                        'menu_type'         => 'form',
                        'menu_target'       => 'demo_service_types',
                        'menu_parent_id'    => $demoParentId,
                        'menu_order'        => 122,
                        'menu_roles'        => 'globeadmin',
                        'menu_role_access'  => '["globeadmin"]',
                        'menu_active'       => 1,
                        'menu_icon'         => 'copy',
                        'menu_open_mode'    => 'inline|browse',
                        'menu_browse_mode'  => 'inline',
                        'menu_preview_mode' => 'inline',
                        'menu_default_view' => 'browse'
                    ]);
                }

                // 3. Customer Requests Forms Menu Target
                $existsDemoRequests = $db->fetchOne("SELECT menu_id FROM nu_menus WHERE menu_target = 'demo_customer_requests'");
                if (!$existsDemoRequests) {
                    $db->insert('nu_menus', [
                        'menu_label'        => 'Customer Requests',
                        'menu_type'         => 'form',
                        'menu_target'       => 'demo_customer_requests',
                        'menu_parent_id'    => $demoParentId,
                        'menu_order'        => 123,
                        'menu_roles'        => 'globeadmin',
                        'menu_role_access'  => '["globeadmin"]',
                        'menu_active'       => 1,
                        'menu_icon'         => 'users',
                        'menu_open_mode'    => 'inline|browse',
                        'menu_browse_mode'  => 'inline',
                        'menu_preview_mode' => 'inline',
                        'menu_default_view' => 'browse'
                    ]);
                }

                // 4. Staff Services Forms Menu Target
                $existsDemoStaff = $db->fetchOne("SELECT menu_id FROM nu_menus WHERE menu_target = 'demo_staff_services'");
                if (!$existsDemoStaff) {
                    $db->insert('nu_menus', [
                        'menu_label'        => 'Staff Services',
                        'menu_type'         => 'form',
                        'menu_target'       => 'demo_staff_services',
                        'menu_parent_id'    => $demoParentId,
                        'menu_order'        => 124,
                        'menu_roles'        => 'globeadmin',
                        'menu_role_access'  => '["globeadmin"]',
                        'menu_active'       => 1,
                        'menu_icon'         => 'clipboard',
                        'menu_open_mode'    => 'inline|browse',
                        'menu_browse_mode'  => 'inline',
                        'menu_preview_mode' => 'inline',
                        'menu_default_view' => 'browse'
                    ]);
                }
            } catch (Exception $e) {
                // Fail silently if nu_menus table doesn't exist yet
            }

            // Self-healing: Fix missing/blank targets for email_settings and report_dashboards menu items
            try {
                $db->query("UPDATE nu_menus SET menu_target = 'email_settings' WHERE menu_label = 'email_settings' AND (menu_target IS NULL OR menu_target = '')");
                $db->query("UPDATE nu_menus SET menu_target = 'report_dashboards' WHERE menu_label = 'report_dashboards' AND (menu_target IS NULL OR menu_target = '')");
            } catch (Exception $ignored) {}

            $pid = ProjectContext::getId();
            $raw = $db->fetchAll(
                "SELECT * FROM nu_menus
                 WHERE  menu_active = 1 AND project_id = ?
                 ORDER  BY menu_parent_id ASC, menu_order ASC, menu_id ASC",
                [$pid]
            );
            $rows = array();
            foreach ($raw as $r) {
                // Ensure all expected keys exist in the row array
                if (!isset($r['menu_target'])) {
                    $r['menu_target'] = '';
                }

                // Align roles columns safely
                if (!isset($r['menu_role_access'])) {
                    $r['menu_role_access'] = isset($r['menu_roles']) ? $r['menu_roles'] : '';
                }
                if (!isset($r['menu_roles'])) {
                    $r['menu_roles'] = isset($r['menu_role_access']) ? $r['menu_role_access'] : '';
                }

                // Align open mode columns safely
                if (!isset($r['menu_browse_mode'])) {
                    $old = isset($r['menu_open_mode']) ? $r['menu_open_mode'] : 'inline|browse';
                    $parts = explode('|', $old, 2);
                    $bm = (isset($parts[0]) && in_array($parts[0], array('inline', 'popup'), true)) ? $parts[0] : 'inline';
                    $dv = (isset($parts[1]) && in_array($parts[1], array('browse', 'preview'), true)) ? $parts[1] : 'browse';

                    $r['menu_browse_mode']  = $bm;
                    $r['menu_preview_mode'] = 'inline';
                    $r['menu_default_view'] = $dv;
                }

                $rows[] = $r;
            }
        } catch (Exception $e) {
            error_log('[MenuRenderer] ' . $e->getMessage());
            return '';
        }

        if (empty($rows)) return '';

        // ── Role filtering (ALL rows — parent AND children) ───────────────────
        $byId = array();
        foreach ($rows as $row) {
            $byId[(int)$row['menu_id']] = $row;
        }

        $visible = array();
        foreach ($rows as $item) {
            if (!self::isAccessible($item, $userRole, $isAdmin)) continue;

            $pid = (int)$item['menu_parent_id'];
            if ($pid > 0 && isset($byId[$pid])) {
                if (!self::isAccessible($byId[$pid], $userRole, $isAdmin)) continue;
            }

            $visible[] = $item;
        }

        // ── Build tree ────────────────────────────────────────────────────────
        $topLevel = array();
        $children = array();
        foreach ($visible as $item) {
            $pid = (int)$item['menu_parent_id'];
            if ($pid === 0) {
                $topLevel[] = $item;
            } else {
                $children[$pid][] = $item;
            }
        }

        if (empty($topLevel)) return '';

        $html = "\n<nav class=\"nu-nav\" id=\"nuDynNav\">\n";
        foreach ($topLevel as $item) {
            $html .= self::renderItem($item, isset($children[$item['menu_id']]) ? $children[$item['menu_id']] : array());
        }
        $html .= "</nav>\n";
        return $html;
    }

    private static function renderItem(array $item, array $kids)
    {
        $type    = $item['menu_type'];
        $label   = htmlspecialchars((string)$item['menu_label'], ENT_QUOTES, 'UTF-8');
        $iconKey = strtolower(trim(isset($item['menu_icon']) ? $item['menu_icon'] : 'default'));
        $svgBody = isset(self::$icons[$iconKey]) ? self::$icons[$iconKey] : self::$icons['default'];

        if ($type === 'divider') {
            return "<hr class=\"nu-nav-divider\">\n";
        }

        $rawTarget = trim(isset($item['menu_target']) ? $item['menu_target'] : '');

        // ── Group: collapsible section ────────────────────────────────────────
        if (!empty($kids)) {
            $groupId = 'nu-group-' . (int)$item['menu_id'];
            $out  = "<div class=\"nu-nav-group\">\n";
            $out .= "  <button class=\"nu-nav-group-label\" type=\"button\"";
            $out .= " aria-expanded=\"true\" aria-controls=\"{$groupId}\">\n";
            $out .= self::svgIcon($svgBody);
            $out .= "  <span>{$label}</span>\n";
            $out .= "  <svg class=\"nu-nav-chevron\" width=\"14\" height=\"14\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" aria-hidden=\"true\"><polyline points=\"6 9 12 15 18 9\"/></svg>\n";
            $out .= "</button>\n";
            $out .= "  <ul class=\"nu-nav-children\" id=\"{$groupId}\">\n";
            foreach ($kids as $child) {
                $out .= "    <li>" . self::renderItem($child, array()) . "</li>\n";
            }
            $out .= "  </ul>\n";
            $out .= "</div>\n";
            return $out;
        }

        // ── URL item ──────────────────────────────────────────────────────────
        if ($type === 'url') {
            $href = htmlspecialchars($rawTarget ? $rawTarget : '#', ENT_QUOTES, 'UTF-8');
            $out  = "<a href=\"{$href}\" class=\"nu-nav-item\" target=\"_blank\" rel=\"noopener noreferrer\">\n";
            $out .= self::svgIcon($svgBody);
            $out .= "  <span>{$label}</span>\n";
            $out .= "</a>\n";
            return $out;
        }

        // ── Standard leaf item ────────────────────────────────────────────────
        $module = $rawTarget;
        if ($module === '') {
            return "<!-- nu_menus id={$item['menu_id']} skipped: no target -->\n";
        }
        $moduleSafe = htmlspecialchars($module, ENT_QUOTES, 'UTF-8');

        $browseMode  = self::sanitiseDisplay(isset($item['menu_browse_mode'])  ? $item['menu_browse_mode']  : 'inline');
        $previewMode = self::sanitiseDisplay(isset($item['menu_preview_mode']) ? $item['menu_preview_mode'] : 'inline');
        $defaultView = self::sanitiseView(isset($item['menu_default_view'])    ? $item['menu_default_view']    : 'browse');

        $openAttrs = '';
        if (in_array($type, self::$openModeTypes, true)) {
            $openAttrs  = " data-default-view=\"{$defaultView}\"";
            $openAttrs .= " data-browse-mode=\"{$browseMode}\"";
            $openAttrs .= " data-preview-mode=\"{$previewMode}\"";
        }

        $jsCall = "NuApp.loadModule('{$moduleSafe}','{$defaultView}','{$browseMode}','{$previewMode}'); return false;";

        $out  = "<a href=\"javascript:void(0)\" class=\"nu-nav-item\" data-module=\"{$moduleSafe}\"{$openAttrs}\n";
        $out .= "   onclick=\"{$jsCall}\">\n";
        $out .= self::svgIcon($svgBody);
        $out .= "  <span>{$label}</span>\n";
        $out .= "</a>\n";
        return $out;
    }

    private static function svgIcon($body)
    {
        if ($body === '') return '';
        return "  <svg width=\"20\" height=\"20\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" aria-hidden=\"true\">\n    {$body}\n  </svg>\n";
    }

    private static function sanitiseDisplay($v)
    {
        $v = strtolower(trim((string)$v));
        return in_array($v, array('inline', 'popup'), true) ? $v : 'inline';
    }

    private static function sanitiseView($v)
    {
        $v = strtolower(trim((string)$v));
        return in_array($v, array('browse', 'preview'), true) ? $v : 'browse';
    }
}
