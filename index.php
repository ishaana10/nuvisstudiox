<?php
declare(strict_types=1);

// ─── Bootstrap ────────────────────────────────────────────────────────────────
$bootError  = '';
$loginError = '';
$isLoggedIn = false;
$currentUser = null;
$auth = null;

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

try {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/core/Database.php';
    require_once __DIR__ . '/core/Auth.php';
    require_once __DIR__ . '/core/Container.php';
    require_once __DIR__ . '/core/MenuRenderer.php';
    require_once __DIR__ . '/core/ProjectContext.php';
    // ErrorLogger registered AFTER Database + Auth are loaded
    require_once __DIR__ . '/core/ErrorLogger.php';
    NuErrorLogger::register();
    $auth = NuAuth::getInstance();
} catch (Throwable $e) {
    $realMsg = get_class($e) . ': ' . $e->getMessage()
             . ' in ' . $e->getFile() . ' on line ' . $e->getLine();
    error_log('[index.php boot] ' . $realMsg);
    $bootError = 'Application failed to start: ' . $realMsg;
}

// ─── Logout ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if ($auth) $auth->logout();
    else { $_SESSION = []; session_destroy(); }
    header('Location: index.php');
    exit;
}

// ─── Login ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($username === '' || $password === '') {
        $loginError = 'Username and password are required.';
    } elseif ($auth === null) {
        $loginError = 'Authentication service unavailable. Check server configuration.';
    } else {
        try {
            $result = $auth->login($username, $password);
            if (!empty($result['success'])) {
                header('Location: index.php');
                exit;
            }
            $loginError = $result['message'] ?? 'Login failed.';
        } catch (Throwable $e) {
            error_log('[index.php login] ' . $e->getMessage());
            $loginError = 'Login error. Please try again.';
        }
    }
}

// ─── Auth Check ───────────────────────────────────────────────────────────────
if ($auth) {
    try {
        $isLoggedIn  = $auth->checkAuth();
        $currentUser = $isLoggedIn ? $auth->getCurrentUser() : null;
    } catch (Throwable $e) {
        error_log('[index.php auth check] ' . $e->getMessage());
        $isLoggedIn = false;
    }
}

$csrfToken   = $auth ? $auth->getCsrfToken() : '';
$userDisplay = 'User';
if (is_array($currentUser)) {
    $userDisplay = $currentUser['usr_name'] ?? $currentUser['usr_username'] ?? 'User';
}

// ── Resolve User Header Format (userName|location customizable) ──────────────
$userHeaderDisplay = '';
if ($isLoggedIn && is_array($currentUser)) {
    $dbFormat = '';
    try {
        $db = NuDatabase::getInstance();
        $userHeaderFormatRow = $db->fetchOne("SELECT setting_value FROM nu_system_settings WHERE setting_key = 'user_header_format'");
        if ($userHeaderFormatRow && trim((string)$userHeaderFormatRow['setting_value']) !== '') {
            $dbFormat = trim((string)$userHeaderFormatRow['setting_value']);
        }
    } catch (Throwable $e) {}

    $format = $dbFormat ?: ($nuConfig['userHeaderFormat'] ?? '{name} | {location}');
    $meta   = $_SESSION['nu_user_meta'] ?? [];

    $name = $currentUser['usr_name'] ?? '';
    if (trim((string)$name) === '') {
        $name = $currentUser['usr_username'] ?? 'User';
    }

    $customFields = [];
    if (!empty($currentUser['usr_custom_fields'])) {
        $customFields = json_decode($currentUser['usr_custom_fields'], true) ?: [];
    }
    $location = $meta['location'] ?? $customFields['location'] ?? '';
    if (is_array($location)) {
        $location = '';
    }

    $replacements = [
        '{name}'     => $name,
        '{username}' => $currentUser['usr_username'] ?? '',
        '{role}'     => $currentUser['usr_role'] ?? '',
        '{location}' => $location,
    ];

    foreach ($meta as $k => $v) {
        if (is_string($v) || is_numeric($v)) {
            $replacements['{' . $k . '}'] = (string)$v;
        }
    }

    $userHeaderDisplay = strtr($format, $replacements);
    $userHeaderDisplay = trim($userHeaderDisplay);
    $userHeaderDisplay = preg_replace('/^\s*\|\s*|\s*\|\s*$/', '', $userHeaderDisplay);
    $userHeaderDisplay = preg_replace('/\s*\|\s*\|\s*/', ' | ', $userHeaderDisplay);
    if ($userHeaderDisplay === '') {
        $userHeaderDisplay = $name;
    }
}

$_role   = strtolower((string)($currentUser['usr_role'] ?? ''));
$isAdmin = ($_role === 'globeadmin' || $_role === 'admin');

$_nuUserPerms = ['canAdd' => false, 'canEdit' => false, 'canDelete' => false];
if ($isLoggedIn && $auth) {
    if ($isAdmin) {
        $_nuUserPerms = ['canAdd' => true, 'canEdit' => true, 'canDelete' => true];
    } else {
        try {
            $wildcardPerms = $auth->formPerms('*');
            $_nuUserPerms = [
                'canAdd'    => !empty($wildcardPerms['add']),
                'canEdit'   => !empty($wildcardPerms['edit']),
                'canDelete' => !empty($wildcardPerms['delete']),
            ];
        } catch (Throwable $e) {
            error_log('[index.php perms] ' . $e->getMessage());
        }
    }
}

function nu_asset(string $path): string {
    $full = __DIR__ . '/' . ltrim($path, '/');
    $v    = is_file($full) ? filemtime($full) : time();
    return h(ltrim($path, '/')) . '?v=' . $v;
}

$dynNav = '';
if ($isLoggedIn && $currentUser) {
    try {
        $dynNav = NuMenuRenderer::render($currentUser);
    } catch (Throwable $e) {
        error_log('[index.php menu] ' . $e->getMessage());
    }
}

$customAppName = $nuConfig['siteTitle'] ?? 'Nuvis StudioX';
$customAppLogo = '';
$forgotPasswordEnabled = true;
try {
    $db = NuDatabase::getInstance();
    $appNameRow = $db->fetchOne("SELECT setting_value FROM nu_system_settings WHERE setting_key = 'app_name'");
    if ($appNameRow && trim((string)$appNameRow['setting_value']) !== '') {
        $customAppName = trim((string)$appNameRow['setting_value']);
    }
    $appLogoRow = $db->fetchOne("SELECT setting_value FROM nu_system_settings WHERE setting_key = 'app_logo'");
    if ($appLogoRow && trim((string)$appLogoRow['setting_value']) !== '') {
        $customAppLogo = trim((string)$appLogoRow['setting_value']);
    }
    $forgotPasswordRow = $db->fetchOne("SELECT setting_value FROM nu_system_settings WHERE setting_key = 'forgot_password_enabled'");
    if ($forgotPasswordRow) {
        $forgotPasswordEnabled = ($forgotPasswordRow['setting_value'] === '1');
    }
} catch (Throwable $e) {
    error_log('[index.php settings load] ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= h($nuConfig['theme'] ?? 'auto') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= h($customAppName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?= nu_asset('assets/css/nubuilder-next.css') ?>">
    <link rel="stylesheet" href="<?= nu_asset('assets/css/select2.min.css') ?>">
    <link rel="stylesheet" href="<?= nu_asset('assets/css/nu-browse.css') ?>">
    <link rel="stylesheet" href="<?= nu_asset('assets/css/nu-shell.css') ?>">
    <link rel="stylesheet" href="<?= nu_asset('assets/css/nu-form-shell.css') ?>">
    <link rel="stylesheet" href="<?= nu_asset('assets/css/nu-dashboard.css') ?>">
    <link rel="stylesheet" href="<?= nu_asset('lib/uppy/uppy.min.css') ?>">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0b1020">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: {
              primary: 'var(--color-primary, #4f6bed)',
            }
          }
        }
      }
    </script>
</head>
<body>
<?php if (!$isLoggedIn): ?>
    <?php require __DIR__ . '/views/login.php'; ?>
<?php else: ?>
<!-- ════════════════════════════════ APP SHELL ════════════════════════════════ -->
<div class="nu-app" id="nuApp">
    <?php require __DIR__ . '/views/sidebar.php'; ?>

    <!-- Main -->
    <main class="nu-main">
        <?php require __DIR__ . '/views/header.php'; ?>

        <div class="nu-content" id="contentArea">
            <div class="nu-spinner" style="margin:40px auto"></div>
        </div>
    </main>

    <div class="nu-overlay" id="overlay"
         onclick="document.getElementById('sidebar').classList.remove('open');this.classList.remove('open')"></div>
</div>

<!-- ════════════════════════ QUICK NEW PROJECT MODAL ════════════════════════ -->
<?php if ($_role === 'globeadmin'): ?>
<div id="nuQuickNewProjectModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 99999; align-items: center; justify-content: center; backdrop-filter: blur(4px);">
    <div style="background: var(--bg-card, #1e293b); border: 1px solid var(--border-color, #334155); border-radius: 12px; width: 100%; max-width: 460px; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="font-size: 18px; font-weight: 700; color: var(--text-primary, #fff); margin: 0; display: flex; align-items: center; gap: 8px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                Create New Project
            </h3>
            <button onclick="nuCloseQuickNewProjectModal()" style="background: transparent; border: none; color: var(--text-muted, #94a3b8); cursor: pointer; padding: 4px;" title="Close">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <form onsubmit="nuQuickCreateProjectSubmit(event)">
            <div style="margin-bottom: 14px;">
                <label for="nuQuickProjCode" style="display: block; font-size: 12px; font-weight: 600; color: var(--text-secondary, #cbd5e1); margin-bottom: 4px;">Project Code (Slug) *</label>
                <input id="nuQuickProjCode" type="text" class="nu-input" placeholder="e.g. sales_app" required style="width: 100%; font-family: monospace;">
            </div>
            <div style="margin-bottom: 14px;">
                <label for="nuQuickProjName" style="display: block; font-size: 12px; font-weight: 600; color: var(--text-secondary, #cbd5e1); margin-bottom: 4px;">Project Name *</label>
                <input id="nuQuickProjName" type="text" class="nu-input" placeholder="e.g. Sales Management Portal" required style="width: 100%;">
            </div>
            <div style="margin-bottom: 20px;">
                <label for="nuQuickProjDesc" style="display: block; font-size: 12px; font-weight: 600; color: var(--text-secondary, #cbd5e1); margin-bottom: 4px;">Description</label>
                <textarea id="nuQuickProjDesc" class="nu-input" rows="3" placeholder="Brief description of this project environment..." style="width: 100%; resize: vertical;"></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" class="nu-btn nu-btn-ghost" onclick="nuCloseQuickNewProjectModal()">Cancel</button>
                <button type="submit" id="nuQuickProjSubmitBtn" class="nu-btn nu-btn-primary">Create Project</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/views/scripts.php'; ?>

</body>
</html>
