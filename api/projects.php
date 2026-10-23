<?php
declare(strict_types=1);

/**
 * API Endpoint for Project Management and Context Switching
 */

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Auth.php';
require_once dirname(__DIR__) . '/core/ProjectContext.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = NuAuth::getInstance();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$user = $auth->getCurrentUser();
if (is_array($user)) {
    $userId = (int)($user['usr_id'] ?? $user['id'] ?? 0);
    $userRole = $user['usr_role'] ?? $user['role'] ?? 'user';
} elseif (is_object($user)) {
    $userId = (int)($user->usr_id ?? $user->id ?? 0);
    $userRole = $user->usr_role ?? $user->role ?? 'user';
} else {
    $userId = 0;
    $userRole = 'user';
}
$isGlobeAdmin = ($userRole === 'globeadmin');

$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');
$db = NuDatabase::getInstance();

function json_response(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

function get_json_input(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

$input = get_json_input();

switch ($action) {
    case 'list':
        $projects = ProjectContext::getAccessibleProjects($userId, $userRole);
        $currentPid = ProjectContext::getId();
        json_response([
            'success' => true,
            'current_project_id' => $currentPid,
            'projects' => $projects
        ]);
        break;

    case 'current':
        $currentPid = ProjectContext::getId();
        $proj = $db->fetchOne("SELECT * FROM nu_projects WHERE project_id = ?", [$currentPid]);
        json_response([
            'success' => true,
            'project_id' => $currentPid,
            'project' => $proj
        ]);
        break;

    case 'switch':
        $projectId = (int)($_GET['project_id'] ?? ($input['project_id'] ?? ($_POST['project_id'] ?? 0)));
        if ($projectId <= 0) {
            json_response(['success' => false, 'error' => 'Invalid project ID'], 400);
        }

        $ok = ProjectContext::setId($projectId);
        if ($ok) {
            json_response(['success' => true, 'project_id' => $projectId, 'message' => 'Project switched successfully']);
        } else {
            json_response(['success' => false, 'error' => 'Failed to switch project or access denied'], 403);
        }
        break;

    case 'get':
        $projectId = (int)($_GET['project_id'] ?? ($input['project_id'] ?? 0));
        if ($projectId <= 0) {
            json_response(['success' => false, 'error' => 'Invalid project ID'], 400);
        }

        if (!$isGlobeAdmin && !ProjectContext::validateAccess($userId, $projectId, $userRole)) {
            json_response(['success' => false, 'error' => 'Access denied'], 403);
        }

        $proj = $db->fetchOne("SELECT * FROM nu_projects WHERE project_id = ?", [$projectId]);
        if (!$proj) {
            json_response(['success' => false, 'error' => 'Project not found'], 404);
        }

        json_response(['success' => true, 'project' => $proj]);
        break;

    case 'create':
        if (!$isGlobeAdmin) {
            json_response(['success' => false, 'error' => 'Only Globe Admin can create projects'], 403);
        }

        $code = trim($input['project_code'] ?? ($_POST['project_code'] ?? ''));
        $name = trim($input['project_name'] ?? ($_POST['project_name'] ?? ''));
        $desc = trim($input['project_description'] ?? ($_POST['project_description'] ?? ''));
        $settings = $input['project_settings'] ?? ($_POST['project_settings'] ?? null);

        if ($code === '' || $name === '') {
            json_response(['success' => false, 'error' => 'Project Code and Name are required'], 400);
        }

        // Sanitize code
        $code = strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', $code));

        $existing = $db->fetchOne("SELECT project_id FROM nu_projects WHERE project_code = ?", [$code]);
        if ($existing) {
            json_response(['success' => false, 'error' => "Project code '{$code}' already exists"], 400);
        }

        $newId = $db->insert('nu_projects', [
            'project_code' => $code,
            'project_name' => $name,
            'project_description' => $desc,
            'project_settings' => is_array($settings) ? json_encode($settings) : $settings,
            'project_active' => 1,
            'project_is_default' => 0,
            'project_owner_id' => $userId
        ]);

        // Automatically assign creator to nu_project_members as 'owner'
        if ($userId > 0) {
            $db->insert('nu_project_members', [
                'pm_project_id' => $newId,
                'pm_user_id'    => $userId,
                'pm_role'       => 'owner'
            ]);
        }

        // Auto-switch to newly created project
        ProjectContext::setId($newId);

        json_response([
            'success' => true,
            'project_id' => $newId,
            'message' => 'Project created successfully'
        ]);
        break;

    case 'update':
        if (!$isGlobeAdmin) {
            json_response(['success' => false, 'error' => 'Only Globe Admin can update projects'], 403);
        }

        $projectId = (int)($input['project_id'] ?? ($_POST['project_id'] ?? 0));
        if ($projectId <= 0) {
            json_response(['success' => false, 'error' => 'Invalid project ID'], 400);
        }

        $proj = $db->fetchOne("SELECT * FROM nu_projects WHERE project_id = ?", [$projectId]);
        if (!$proj) {
            json_response(['success' => false, 'error' => 'Project not found'], 404);
        }

        $name = trim($input['project_name'] ?? ($_POST['project_name'] ?? $proj['project_name']));
        $desc = trim($input['project_description'] ?? ($_POST['project_description'] ?? $proj['project_description']));
        $settings = $input['project_settings'] ?? $proj['project_settings'];

        $db->update('nu_projects', [
            'project_name' => $name,
            'project_description' => $desc,
            'project_settings' => is_array($settings) ? json_encode($settings) : $settings,
            'project_updated_at' => date('Y-m-d H:i:s')
        ], 'project_id = ?', [$projectId]);

        json_response(['success' => true, 'message' => 'Project updated successfully']);
        break;

    case 'delete':
    case 'soft_delete':
        if (!$isGlobeAdmin) {
            json_response(['success' => false, 'error' => 'Only Globe Admin can delete projects'], 403);
        }

        $projectId = (int)($input['project_id'] ?? ($_POST['project_id'] ?? ($_GET['project_id'] ?? 0)));
        if ($projectId <= 0) {
            json_response(['success' => false, 'error' => 'Invalid project ID'], 400);
        }

        $proj = $db->fetchOne("SELECT * FROM nu_projects WHERE project_id = ?", [$projectId]);
        if (!$proj) {
            json_response(['success' => false, 'error' => 'Project not found'], 404);
        }

        if ((int)$proj['project_is_default'] === 1) {
            json_response(['success' => false, 'error' => 'Cannot delete the default project'], 400);
        }

        // Perform soft delete
        $db->update('nu_projects', ['project_active' => 0], 'project_id = ?', [$projectId]);

        // If active project was deleted, switch back to default project
        if (ProjectContext::getId() === $projectId) {
            $defaultProj = $db->fetchOne("SELECT project_id FROM nu_projects WHERE project_is_default = 1 LIMIT 1");
            if ($defaultProj) {
                ProjectContext::setId((int)$defaultProj['project_id']);
            }
        }

        json_response(['success' => true, 'message' => 'Project soft deleted successfully']);
        break;

    case 'members_list':
        if (!$isGlobeAdmin) {
            json_response(['success' => false, 'error' => 'Access denied'], 403);
        }

        $projectId = (int)($_GET['project_id'] ?? ($input['project_id'] ?? 0));
        if ($projectId <= 0) {
            json_response(['success' => false, 'error' => 'Invalid project ID'], 400);
        }

        $members = $db->fetchAll(
            "SELECT pm.*, u.usr_username, u.usr_email FROM nu_project_members pm
             INNER JOIN nu_users u ON pm.pm_user_id = u.usr_id
             WHERE pm.pm_project_id = ? ORDER BY u.usr_username ASC",
            [$projectId]
        );

        $allUsers = $db->fetchAll("SELECT usr_id, usr_username, usr_email FROM nu_users WHERE usr_active = 1 ORDER BY usr_username ASC");

        json_response([
            'success' => true,
            'members' => $members,
            'all_users' => $allUsers
        ]);
        break;

    case 'member_add':
        if (!$isGlobeAdmin) {
            json_response(['success' => false, 'error' => 'Access denied'], 403);
        }

        $projectId = (int)($input['project_id'] ?? ($_POST['project_id'] ?? 0));
        $targetUserId = (int)($input['user_id'] ?? ($_POST['user_id'] ?? 0));
        $role = trim($input['role'] ?? ($_POST['role'] ?? 'member'));

        if ($projectId <= 0 || $targetUserId <= 0) {
            json_response(['success' => false, 'error' => 'Project ID and User ID are required'], 400);
        }

        $existing = $db->fetchOne("SELECT pm_id FROM nu_project_members WHERE pm_project_id = ? AND pm_user_id = ?", [$projectId, $targetUserId]);
        if ($existing) {
            $db->update('nu_project_members', ['pm_role' => $role], 'pm_id = ?', [$existing['pm_id']]);
        } else {
            $db->insert('nu_project_members', [
                'pm_project_id' => $projectId,
                'pm_user_id' => $targetUserId,
                'pm_role' => $role
            ]);
        }

        json_response(['success' => true, 'message' => 'Member assigned successfully']);
        break;

    case 'member_remove':
        if (!$isGlobeAdmin) {
            json_response(['success' => false, 'error' => 'Access denied'], 403);
        }

        $projectId = (int)($input['project_id'] ?? ($_POST['project_id'] ?? 0));
        $targetUserId = (int)($input['user_id'] ?? ($_POST['user_id'] ?? 0));

        if ($projectId <= 0 || $targetUserId <= 0) {
            json_response(['success' => false, 'error' => 'Project ID and User ID are required'], 400);
        }

        $db->delete('nu_project_members', 'pm_project_id = ? AND pm_user_id = ?', [$projectId, $targetUserId]);

        json_response(['success' => true, 'message' => 'Member removed successfully']);
        break;

    case 'get_share_settings':
        if (!$isGlobeAdmin) {
            json_response(['success' => false, 'error' => 'Access denied'], 403);
        }

        $projectId = (int)($_GET['project_id'] ?? ($input['project_id'] ?? 0));
        if ($projectId <= 0) {
            json_response(['success' => false, 'error' => 'Invalid project ID'], 400);
        }

        $proj = $db->fetchOne("SELECT project_id, project_name, project_code, project_is_public_test, project_share_token, project_share_mode FROM nu_projects WHERE project_id = ?", [$projectId]);
        if (!$proj) {
            json_response(['success' => false, 'error' => 'Project not found'], 404);
        }

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\') . '/../index.php';
        $baseUrl = $protocol . $host . preg_replace('#/api/\.\./#', '/', $path);

        $shareToken = $proj['project_share_token'] ?? '';
        $shareUrl = $shareToken ? $baseUrl . '?project_share=' . $shareToken : '';

        json_response([
            'success' => true,
            'project_id' => $projectId,
            'is_public_test' => (int)($proj['project_is_public_test'] ?? 0),
            'share_token' => $shareToken,
            'share_mode' => $proj['project_share_mode'] ?? 'guest',
            'share_url' => $shareUrl
        ]);
        break;

    case 'toggle_share':
        if (!$isGlobeAdmin) {
            json_response(['success' => false, 'error' => 'Access denied'], 403);
        }

        $projectId = (int)($input['project_id'] ?? ($_POST['project_id'] ?? 0));
        $enabled   = isset($input['enabled']) ? (int)(bool)$input['enabled'] : (int)(bool)($_POST['enabled'] ?? 0);
        $shareMode = trim($input['share_mode'] ?? ($_POST['share_mode'] ?? 'guest'));
        if (!in_array($shareMode, ['guest', 'login'], true)) {
            $shareMode = 'guest';
        }

        if ($projectId <= 0) {
            json_response(['success' => false, 'error' => 'Invalid project ID'], 400);
        }

        $proj = $db->fetchOne("SELECT * FROM nu_projects WHERE project_id = ?", [$projectId]);
        if (!$proj) {
            json_response(['success' => false, 'error' => 'Project not found'], 404);
        }

        $token = $proj['project_share_token'] ?? '';
        if ($enabled && empty($token)) {
            $token = bin2hex(random_bytes(16));
        }

        $db->update('nu_projects', [
            'project_is_public_test' => $enabled,
            'project_share_token'   => $token,
            'project_share_mode'    => $shareMode,
            'project_updated_at'    => date('Y-m-d H:i:s')
        ], 'project_id = ?', [$projectId]);

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\') . '/../index.php';
        $baseUrl = $protocol . $host . preg_replace('#/api/\.\./#', '/', $path);
        $shareUrl = ($enabled && $token) ? $baseUrl . '?project_share=' . $token : '';

        json_response([
            'success' => true,
            'is_public_test' => $enabled,
            'share_token' => $token,
            'share_mode' => $shareMode,
            'share_url' => $shareUrl,
            'message' => $enabled ? 'Online project testing link activated' : 'Online project testing link disabled'
        ]);
        break;

    case 'regenerate_share_token':
        if (!$isGlobeAdmin) {
            json_response(['success' => false, 'error' => 'Access denied'], 403);
        }

        $projectId = (int)($input['project_id'] ?? ($_POST['project_id'] ?? 0));
        if ($projectId <= 0) {
            json_response(['success' => false, 'error' => 'Invalid project ID'], 400);
        }

        $newToken = bin2hex(random_bytes(16));
        $db->update('nu_projects', [
            'project_share_token' => $newToken,
            'project_updated_at'  => date('Y-m-d H:i:s')
        ], 'project_id = ?', [$projectId]);

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\') . '/../index.php';
        $baseUrl = $protocol . $host . preg_replace('#/api/\.\./#', '/', $path);

        json_response([
            'success' => true,
            'share_token' => $newToken,
            'share_url' => $baseUrl . '?project_share=' . $newToken,
            'message' => 'New project testing link generated'
        ]);
        break;

    case 'get_theme':
        $projectId = (int)($_GET['project_id'] ?? ($input['project_id'] ?? 0));
        if ($projectId <= 0) {
            $projectId = ProjectContext::getId();
        }

        if (!$isGlobeAdmin && !ProjectContext::validateAccess($userId, $projectId, $userRole)) {
            json_response(['success' => false, 'error' => 'Access denied'], 403);
        }

        $proj = $db->fetchOne("SELECT project_id, project_name, project_settings FROM nu_projects WHERE project_id = ?", [$projectId]);
        if (!$proj) {
            json_response(['success' => false, 'error' => 'Project not found'], 404);
        }

        $settings = json_decode((string)($proj['project_settings'] ?? '{}'), true) ?: [];
        $theme = $settings['theme'] ?? [
            'primary_color' => '#2563eb',
            'primary_hover' => '#1d4ed8',
            'sidebar_bg'    => '#0f172a',
            'sidebar_text'  => '#e2e8f0',
            'accent_color'  => '#2563eb'
        ];

        json_response([
            'success' => true,
            'project_id' => $projectId,
            'project_name' => $proj['project_name'],
            'theme' => $theme
        ]);
        break;

    case 'save_theme':
        if (!$isGlobeAdmin) {
            json_response(['success' => false, 'error' => 'Access denied: Only Globe Admin can customize project themes'], 403);
        }

        $projectId = (int)($input['project_id'] ?? ($_POST['project_id'] ?? 0));
        if ($projectId <= 0) {
            json_response(['success' => false, 'error' => 'Invalid project ID'], 400);
        }

        $proj = $db->fetchOne("SELECT * FROM nu_projects WHERE project_id = ?", [$projectId]);
        if (!$proj) {
            json_response(['success' => false, 'error' => 'Project not found'], 404);
        }

        $themeInput = $input['theme'] ?? ($_POST['theme'] ?? []);
        if (!is_array($themeInput)) {
            $themeInput = [];
        }

        $cleanTheme = [
            'primary_color' => preg_match('/^#[0-9a-fA-F]{3,8}$/', $themeInput['primary_color'] ?? '') ? $themeInput['primary_color'] : '#2563eb',
            'primary_hover' => preg_match('/^#[0-9a-fA-F]{3,8}$/', $themeInput['primary_hover'] ?? '') ? $themeInput['primary_hover'] : '#1d4ed8',
            'sidebar_bg'    => preg_match('/^#[0-9a-fA-F]{3,8}$/', $themeInput['sidebar_bg'] ?? '') ? $themeInput['sidebar_bg'] : '#0f172a',
            'sidebar_text'  => preg_match('/^#[0-9a-fA-F]{3,8}$/', $themeInput['sidebar_text'] ?? '') ? $themeInput['sidebar_text'] : '#e2e8f0',
            'accent_color'  => preg_match('/^#[0-9a-fA-F]{3,8}$/', $themeInput['accent_color'] ?? '') ? $themeInput['accent_color'] : '#2563eb'
        ];

        $settings = json_decode((string)($proj['project_settings'] ?? '{}'), true) ?: [];
        $settings['theme'] = $cleanTheme;

        $db->update('nu_projects', [
            'project_settings'   => json_encode($settings),
            'project_updated_at' => date('Y-m-d H:i:s')
        ], 'project_id = ?', [$projectId]);

        ProjectContext::resetCache();

        json_response([
            'success' => true,
            'project_id' => $projectId,
            'theme' => $cleanTheme,
            'message' => 'Project theme settings saved successfully'
        ]);
        break;

    case 'export':
    case 'export_project':
        if (!$isGlobeAdmin) {
            json_response(['success' => false, 'error' => 'Only Globe Admin can export projects'], 403);
        }

        $projectId = (int)($_GET['project_id'] ?? ($input['project_id'] ?? 0));
        if ($projectId <= 0) {
            $projectId = ProjectContext::getId();
        }

        require_once dirname(__DIR__) . '/core/AppCloner.php';
        $cloner = new AppCloner();

        $schemaOnly = !empty($_GET['schema_only']) || !empty($input['schema_only']);
        $includeUserData = !isset($_GET['include_data']) || $_GET['include_data'] == '1' || !isset($input['include_data']) || !empty($input['include_data']);

        $sql = $cloner->exportProject($projectId, [
            'schemaOnly' => $schemaOnly,
            'includeUserData' => $includeUserData
        ]);

        $proj = $db->fetchOne("SELECT project_code FROM nu_projects WHERE project_id = ?", [$projectId]);
        $filename = ($proj['project_code'] ?? 'project_' . $projectId) . '_export.sql';

        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $sql;
        exit;

    case 'export_zip':
    case 'export_full_project':
        if (!$isGlobeAdmin) {
            json_response(['success' => false, 'error' => 'Only Globe Admin can export projects'], 403);
        }

        $projectId = (int)($_GET['project_id'] ?? ($input['project_id'] ?? 0));
        if ($projectId <= 0) {
            $projectId = ProjectContext::getId();
        }

        if (!class_exists('ZipArchive')) {
            json_response(['success' => false, 'error' => 'ZipArchive PHP extension is required for full project export'], 500);
        }

        require_once dirname(__DIR__) . '/core/AppCloner.php';
        $cloner = new AppCloner();

        $sql = $cloner->exportProject($projectId, [
            'schemaOnly' => false,
            'includeUserData' => true
        ]);

        $proj = $db->fetchOne("SELECT project_code FROM nu_projects WHERE project_id = ?", [$projectId]);
        $projCode = $proj['project_code'] ?? 'project_' . $projectId;

        $tmpZip = tempnam(sys_get_temp_dir(), 'nu_export_') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            json_response(['success' => false, 'error' => 'Failed to create zip archive'], 500);
        }

        // Base directory
        $baseDir = dirname(__DIR__);

        // Prepare combined SQL (base system install.sql + project application SQL)
        $installSql = '';
        $installSqlFile = $baseDir . '/install.sql';
        if (file_exists($installSqlFile)) {
            $installSql = (string)file_get_contents($installSqlFile) . "\n\n";
        }

        $combinedSql = $installSql . $sql;

        // Add SQL export dumps into the ZIP archive
        $zip->addFromString('database.sql', $combinedSql);
        $zip->addFromString('project.sql', $sql);

        // Include application code files
        $dirsToInclude = ['core', 'api', 'views', 'modules', 'assets', 'lib', 'plugins'];
        $filesToInclude = ['config.php', 'index.php', 'manifest.json', 'sw.js', '.htaccess', 'install.sql'];

        foreach ($filesToInclude as $file) {
            $filePath = $baseDir . '/' . $file;
            if (file_exists($filePath) && is_file($filePath)) {
                $zip->addFile($filePath, $file);
            }
        }

        foreach ($dirsToInclude as $dir) {
            $dirPath = $baseDir . '/' . $dir;
            if (is_dir($dirPath)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dirPath, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ($iterator as $item) {
                    $relativePath = substr($item->getPathname(), strlen($baseDir) + 1);
                    if ($item->isDir()) {
                        $zip->addEmptyDir($relativePath);
                    } elseif ($item->isFile()) {
                        $zip->addFile($item->getPathname(), $relativePath);
                    }
                }
            }
        }

        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $projCode . '_standalone_package.zip"');
        header('Content-Length: ' . filesize($tmpZip));
        readfile($tmpZip);
        @unlink($tmpZip);
        exit;

    case 'list_server_packages':
        if (!$isGlobeAdmin) {
            json_response(['success' => false, 'error' => 'Only Globe Admin can view server packages'], 403);
        }

        $baseDir = dirname(__DIR__);
        $scanDirs = [$baseDir, $baseDir . '/uploads'];
        $foundFiles = [];

        foreach ($scanDirs as $dir) {
            if (!is_dir($dir)) continue;
            $items = @scandir($dir);
            if (!is_array($items)) continue;

            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $fullPath = $dir . '/' . $item;
                if (!is_file($fullPath)) continue;

                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if (in_array($ext, ['sql', 'zip'], true)) {
                    $relPath = substr($fullPath, strlen($baseDir) + 1);
                    $size = @filesize($fullPath) ?: 0;
                    $sizeFormatted = $size >= 1048576
                        ? number_format($size / 1048576, 1) . ' MB'
                        : number_format($size / 1024, 1) . ' KB';

                    $foundFiles[] = [
                        'name' => $item,
                        'path' => $relPath,
                        'size' => $size,
                        'size_formatted' => $sizeFormatted,
                        'mtime' => date('Y-m-d H:i:s', filemtime($fullPath) ?: time())
                    ];
                }
            }
        }

        // Sort by modification time descending
        usort($foundFiles, function($a, $b) {
            return strcmp($b['mtime'], $a['mtime']);
        });

        json_response(['success' => true, 'files' => $foundFiles]);
        break;

    case 'import_project':
        if (!$isGlobeAdmin) {
            json_response(['success' => false, 'error' => 'Only Globe Admin can import projects'], 403);
        }

        $serverFilePath = trim($input['server_file_path'] ?? ($_POST['server_file_path'] ?? ''));
        $fileTmp = '';
        $fileName = '';

        if ($serverFilePath !== '') {
            $baseDir = dirname(__DIR__);
            $targetPath = realpath($baseDir . '/' . ltrim($serverFilePath, '/\\'));

            if (!$targetPath || !is_file($targetPath)) {
                $targetPath = realpath($serverFilePath);
            }

            if (!$targetPath || !is_file($targetPath)) {
                json_response(['success' => false, 'error' => 'Server file not found: ' . htmlspecialchars($serverFilePath)], 404);
            }

            $fileTmp = $targetPath;
            $fileName = basename($targetPath);
        } elseif (!empty($_FILES['package_file']['tmp_name']) && is_uploaded_file($_FILES['package_file']['tmp_name'])) {
            $fileTmp = $_FILES['package_file']['tmp_name'];
            $fileName = $_FILES['package_file']['name'];
        } else {
            json_response(['success' => false, 'error' => 'Please upload a package file or select a valid server file'], 400);
        }

        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $sqlContent = '';

        if ($ext === 'zip') {
            if (!class_exists('ZipArchive')) {
                json_response(['success' => false, 'error' => 'ZipArchive PHP extension is required to import .zip project packages'], 500);
            }

            $zip = new ZipArchive();
            if ($zip->open($fileTmp) !== true) {
                json_response(['success' => false, 'error' => 'Failed to open uploaded .zip package archive'], 400);
            }

            // Look for project.sql, database.sql, or any .sql inside the archive
            $sqlCandidates = ['project.sql', 'database.sql', 'install.sql'];
            $foundSqlName = null;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!$stat || empty($stat['name'])) continue;
                $entryName = strtolower(basename($stat['name']));
                if (in_array($entryName, $sqlCandidates, true)) {
                    $foundSqlName = $stat['name'];
                    break;
                }
            }

            if (!$foundSqlName) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    if ($stat && !empty($stat['name']) && strtolower(pathinfo($stat['name'], PATHINFO_EXTENSION)) === 'sql') {
                        $foundSqlName = $stat['name'];
                        break;
                    }
                }
            }

            if (!$foundSqlName) {
                $zip->close();
                json_response(['success' => false, 'error' => 'No SQL dump file (project.sql or database.sql) found inside .zip archive'], 400);
            }

            $sqlContent = (string)$zip->getFromName($foundSqlName);
            $zip->close();
        } elseif ($ext === 'sql') {
            $sqlContent = (string)file_get_contents($fileTmp);
        } else {
            json_response(['success' => false, 'error' => 'Invalid file extension. Please select a .sql or .zip package file'], 400);
        }

        if (trim($sqlContent) === '') {
            json_response(['success' => false, 'error' => 'Import package SQL file is empty'], 400);
        }

        $pdo = $db->getPdo();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        try {
            if ($driver !== 'sqlite') {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            }

            // Parse and execute SQL statements
            $statements = [];
            $length = strlen($sqlContent);
            $current = '';
            $inSingleQuote = false;
            $inDoubleQuote = false;
            $escaped = false;

            for ($i = 0; $i < $length; $i++) {
                $char = $sqlContent[$i];

                if ($escaped) {
                    $current .= $char;
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $current .= $char;
                    $escaped = true;
                    continue;
                }

                if ($char === "'" && !$inDoubleQuote) {
                    $inSingleQuote = !$inSingleQuote;
                } elseif ($char === '"' && !$inSingleQuote) {
                    $inDoubleQuote = !$inDoubleQuote;
                }

                if ($char === ';' && !$inSingleQuote && !$inDoubleQuote) {
                    $statements[] = $current;
                    $current = '';
                } else {
                    $current .= $char;
                }
            }

            if (trim($current) !== '') {
                $statements[] = $current;
            }

            $executedCount = 0;
            $importedProjectId = null;

            foreach ($statements as $stmt) {
                $stmtTrim = trim($stmt);
                if ($stmtTrim !== '') {
                    try {
                        $pdo->exec($stmtTrim);
                        $executedCount++;

                        // Attempt to extract imported project ID if nu_projects row was inserted/replaced
                        if (preg_match('/INTO\s+`?nu_projects`?\s*\(.*?\)\s*VALUES\s*\(\s*(\d+)/i', $stmtTrim, $m)) {
                            $importedProjectId = (int)$m[1];
                        }
                    } catch (PDOException $e) {
                        $msg = $e->getMessage();
                        if (strpos($msg, 'already exists') === false && strpos($msg, 'Duplicate entry') === false) {
                            throw $e;
                        }
                    }
                }
            }

            // If project ID wasn't explicitly captured from SQL, find the latest created/updated project
            if (!$importedProjectId) {
                $latestProj = $db->fetchOne("SELECT project_id FROM nu_projects ORDER BY project_id DESC LIMIT 1");
                if ($latestProj) {
                    $importedProjectId = (int)$latestProj['project_id'];
                }
            }

            // Ensure current user is assigned as owner/member of the imported project
            if ($importedProjectId && $userId > 0) {
                $existingMember = $db->fetchOne("SELECT pm_id FROM nu_project_members WHERE pm_project_id = ? AND pm_user_id = ?", [$importedProjectId, $userId]);
                if (!$existingMember) {
                    $db->insert('nu_project_members', [
                        'pm_project_id' => $importedProjectId,
                        'pm_user_id'    => $userId,
                        'pm_role'       => 'owner'
                    ]);
                }
                // Switch active project context to the newly imported project
                ProjectContext::setId($importedProjectId);
            }

            if ($driver !== 'sqlite') {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }

            json_response([
                'success' => true,
                'project_id' => $importedProjectId,
                'executed_statements' => $executedCount,
                'message' => 'Project package successfully imported!'
            ]);

        } catch (\Throwable $e) {
            if ($driver !== 'sqlite') {
                try { $pdo->exec('SET FOREIGN_KEY_CHECKS = 1'); } catch (Throwable $ignored) {}
            }
            json_response(['success' => false, 'error' => 'Import failed: ' . $e->getMessage()], 500);
        }
        break;

    case 'version_create':
        if (!$isGlobeAdmin) {
            json_response(['success' => false, 'error' => 'Only Globe Admin can create project versions'], 403);
        }

        $projectId = (int)($input['project_id'] ?? ($_POST['project_id'] ?? 0));
        if ($projectId <= 0) {
            $projectId = ProjectContext::getId();
        }

        $tag = trim($input['version_tag'] ?? ($_POST['version_tag'] ?? ''));
        $desc = trim($input['description'] ?? ($_POST['description'] ?? ''));

        if ($tag === '') {
            $tag = 'v' . date('Ymd.His');
        }

        // Snapshot metadata
        $snapshot = [
            'nu_forms' => $db->fetchAll("SELECT * FROM nu_forms WHERE project_id = ?", [$projectId]),
            'nu_reports' => $db->fetchAll("SELECT * FROM nu_reports WHERE project_id = ?", [$projectId]),
            'nu_queries' => $db->fetchAll("SELECT * FROM nu_queries WHERE project_id = ?", [$projectId]),
            'nu_procedures' => $db->fetchAll("SELECT * FROM nu_procedures WHERE project_id = ?", [$projectId]),
            'nu_menus' => $db->fetchAll("SELECT * FROM nu_menus WHERE project_id = ?", [$projectId]),
            'nu_workflows' => $db->fetchAll("SELECT * FROM nu_workflows WHERE project_id = ?", [$projectId]),
            'nu_workflow_stages' => $db->fetchAll("SELECT * FROM nu_workflow_stages WHERE project_id = ?", [$projectId]),
            'nu_workflow_transitions' => $db->fetchAll("SELECT * FROM nu_workflow_transitions WHERE project_id = ?", [$projectId]),
        ];

        $versionId = $db->insert('nu_project_versions', [
            'pv_project_id' => $projectId,
            'pv_version_tag' => $tag,
            'pv_description' => $desc,
            'pv_snapshot_data' => json_encode($snapshot),
            'pv_created_by' => $userId,
            'pv_created_at' => date('Y-m-d H:i:s')
        ]);

        json_response([
            'success' => true,
            'version_id' => $versionId,
            'version_tag' => $tag,
            'message' => 'Project version snapshot created successfully'
        ]);
        break;

    case 'version_list':
        $projectId = (int)($_GET['project_id'] ?? ($input['project_id'] ?? 0));
        if ($projectId <= 0) {
            $projectId = ProjectContext::getId();
        }

        if (!$isGlobeAdmin && !ProjectContext::validateAccess($userId, $projectId, $userRole)) {
            json_response(['success' => false, 'error' => 'Access denied'], 403);
        }

        try {
            $versions = $db->fetchAll(
                "SELECT pv.pv_id, pv.pv_project_id, pv.pv_version_tag, pv.pv_description, pv.pv_created_by, pv.pv_created_at, u.usr_username
                 FROM nu_project_versions pv
                 LEFT JOIN nu_users u ON CAST(pv.pv_created_by AS CHAR) = CAST(u.usr_id AS CHAR)
                 WHERE pv.pv_project_id = ? ORDER BY pv.pv_id DESC",
                [$projectId]
            );
        } catch (\Throwable $t) {
            $versions = $db->fetchAll(
                "SELECT pv_id, pv_project_id, pv_version_tag, pv_description, pv_created_by, pv_created_at
                 FROM nu_project_versions
                 WHERE pv_project_id = ? ORDER BY pv_id DESC",
                [$projectId]
            );
        }

        json_response(['success' => true, 'versions' => $versions]);
        break;

    case 'version_get':
        $versionId = (int)($_GET['version_id'] ?? ($input['version_id'] ?? 0));
        if ($versionId <= 0) {
            json_response(['success' => false, 'error' => 'Invalid version ID'], 400);
        }

        $ver = $db->fetchOne("SELECT * FROM nu_project_versions WHERE pv_id = ?", [$versionId]);
        if (!$ver) {
            json_response(['success' => false, 'error' => 'Version not found'], 404);
        }

        if (!$isGlobeAdmin && !ProjectContext::validateAccess($userId, (int)$ver['pv_project_id'], $userRole)) {
            json_response(['success' => false, 'error' => 'Access denied'], 403);
        }

        $ver['snapshot_summary'] = array_map(function($v) {
            return is_array($v) ? count($v) : 0;
        }, json_decode((string)($ver['pv_snapshot_data'] ?? '{}'), true) ?: []);

        unset($ver['pv_snapshot_data']);

        json_response(['success' => true, 'version' => $ver]);
        break;

    case 'version_restore':
        if (!$isGlobeAdmin) {
            json_response(['success' => false, 'error' => 'Only Globe Admin can restore project versions'], 403);
        }

        $versionId = (int)($input['version_id'] ?? ($_POST['version_id'] ?? 0));
        if ($versionId <= 0) {
            json_response(['success' => false, 'error' => 'Invalid version ID'], 400);
        }

        $ver = $db->fetchOne("SELECT * FROM nu_project_versions WHERE pv_id = ?", [$versionId]);
        if (!$ver) {
            json_response(['success' => false, 'error' => 'Version not found'], 404);
        }

        $projectId = (int)$ver['pv_project_id'];
        $snapshot = json_decode((string)($ver['pv_snapshot_data'] ?? '{}'), true);
        if (!is_array($snapshot)) {
            json_response(['success' => false, 'error' => 'Corrupted snapshot data'], 500);
        }

        $db->beginTransaction();
        try {
            $tables = ['nu_workflow_transitions', 'nu_workflow_stages', 'nu_workflows', 'nu_menus', 'nu_procedures', 'nu_queries', 'nu_reports', 'nu_forms'];
            foreach ($tables as $tbl) {
                $db->delete($tbl, 'project_id = ?', [$projectId]);
            }

            $pkMap = [
                'nu_forms' => 'form_id',
                'nu_reports' => 'report_id',
                'nu_queries' => 'query_id',
                'nu_procedures' => 'procedure_id',
                'nu_menus' => 'menu_id',
                'nu_workflows' => 'wf_id',
                'nu_workflow_stages' => 'wfs_id',
                'nu_workflow_transitions' => 'wft_id',
            ];

            $idMappings = [];
            $insertOrder = ['nu_forms', 'nu_reports', 'nu_queries', 'nu_procedures', 'nu_menus', 'nu_workflows', 'nu_workflow_stages', 'nu_workflow_transitions'];

            foreach ($insertOrder as $tbl) {
                $rows = $snapshot[$tbl] ?? [];
                if (!is_array($rows)) continue;
                $pkCol = $pkMap[$tbl] ?? null;
                $idMappings[$tbl] = [];

                foreach ($rows as $row) {
                    if (!is_array($row)) continue;
                    $oldId = $pkCol && isset($row[$pkCol]) ? $row[$pkCol] : null;
                    if ($pkCol) {
                        unset($row[$pkCol]);
                    }
                    $row['project_id'] = $projectId;

                    if ($tbl === 'nu_menus' && !empty($row['menu_parent_id']) && isset($idMappings['nu_menus'][$row['menu_parent_id']])) {
                        $row['menu_parent_id'] = $idMappings['nu_menus'][$row['menu_parent_id']];
                    }
                    if ($tbl === 'nu_workflow_stages' && !empty($row['wfs_wf_id']) && isset($idMappings['nu_workflows'][$row['wfs_wf_id']])) {
                        $row['wfs_wf_id'] = $idMappings['nu_workflows'][$row['wfs_wf_id']];
                    }
                    if ($tbl === 'nu_workflow_transitions') {
                        if (!empty($row['wft_wf_id']) && isset($idMappings['nu_workflows'][$row['wft_wf_id']])) {
                            $row['wft_wf_id'] = $idMappings['nu_workflows'][$row['wft_wf_id']];
                        }
                        if (!empty($row['wft_from_id']) && isset($idMappings['nu_workflow_stages'][$row['wft_from_id']])) {
                            $row['wft_from_id'] = $idMappings['nu_workflow_stages'][$row['wft_from_id']];
                        }
                        if (!empty($row['wft_to_id']) && isset($idMappings['nu_workflow_stages'][$row['wft_to_id']])) {
                            $row['wft_to_id'] = $idMappings['nu_workflow_stages'][$row['wft_to_id']];
                        }
                    }

                    $newId = $db->insert($tbl, $row);
                    if ($oldId !== null) {
                        $idMappings[$tbl][$oldId] = $newId;
                    }
                }
            }

            $db->commit();
            json_response([
                'success' => true,
                'message' => "Project state successfully restored to version '{$ver['pv_version_tag']}'"
            ]);
        } catch (\Throwable $e) {
            $db->rollback();
            json_response(['success' => false, 'error' => 'Failed to restore snapshot: ' . $e->getMessage()], 500);
        }
        break;

    default:
        json_response(['success' => false, 'error' => 'Invalid action'], 400);
}
