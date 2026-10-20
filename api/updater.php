<?php
declare(strict_types=1);
/**
 * api/updater.php — Application Update & Configuration Manager
 * Admin-only tool for Git operations and config editing.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/AppVersion.php';

header('Content-Type: application/json');

// ── Auth + globeadmin + config gate ────────────────────────────────────
global $nuConfig;
if (empty($nuConfig['enableGitUpdater'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Git Updater is disabled in configuration ($nuConfig[\'enableGitUpdater\'])']);
    exit;
}

$auth = NuAuth::getInstance();
if (!$auth->checkAuth()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
$user = $auth->getCurrentUser();
$role = strtolower((string)($user['usr_role'] ?? ''));
if ($role !== 'globeadmin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied: Restricted to globeadmin role']);
    exit;
}

function validate_repo_dir(string $dir): string {
    $appRoot = realpath(NU_ROOT);
    $realDir = realpath($dir);
    if (!$realDir || !$appRoot) {
        throw new Exception('Invalid repository directory: path does not exist');
    }
    if (strpos($realDir, $appRoot) !== 0) {
        throw new Exception('Access denied: repository directory must reside within application root');
    }
    return $realDir;
}

function validate_git_path(string $path): string {
    $path = trim($path);
    if ($path === 'git') return 'git';
    if (!preg_match('#^/([a-zA-Z0-9_.-]+/)*git$#', $path)) {
        throw new Exception('Invalid git executable path');
    }
    if (!file_exists($path) || !is_executable($path)) {
        throw new Exception('Git binary path does not exist or is not executable');
    }
    return $path;
}

/**
 * Safely fetches the Git configurations from database settings with fallbacks.
 */
function get_git_config(NuDatabase $db): array {
    // Ensure table exists
    $db->query("CREATE TABLE IF NOT EXISTS `nu_settings` (
        `setting_key` VARCHAR(100) NOT NULL PRIMARY KEY,
        `setting_value` TEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $settings = [
        'git_path' => 'git',
        'git_repo_dir' => realpath(__DIR__ . '/..') ?: '/app',
        'update_branch' => 'main',
        'git_remote_url' => ''
    ];

    foreach ($settings as $key => $default) {
        $row = $db->fetchOne("SELECT setting_value FROM nu_settings WHERE setting_key = ?", [$key]);
        if ($row !== null) {
            $settings[$key] = trim((string)$row['setting_value']);
        }
    }

    // Double check fallbacks in case DB stored empty strings
    try {
        $settings['git_path'] = validate_git_path($settings['git_path'] ?? 'git');
    } catch (Throwable $t) {
        $settings['git_path'] = 'git';
    }

    try {
        $settings['git_repo_dir'] = validate_repo_dir($settings['git_repo_dir'] ?? NU_ROOT);
    } catch (Throwable $t) {
        $settings['git_repo_dir'] = realpath(NU_ROOT) ?: NU_ROOT;
    }
    if (empty($settings['update_branch'])) {
        $settings['update_branch'] = 'main';
    }

    return $settings;
}

$action = (string)($_GET['action'] ?? '');
try {
    switch ($action) {
        case 'git_status':
            $db = NuDatabase::getInstance();
            $settings = get_git_config($db);

            $git_path = $settings['git_path'];
            $git_repo_dir = $settings['git_repo_dir'];
            $selectedBranch = $settings['update_branch'];

            // Construct git prefix command using -C and safe.directory
            $gitCmdPrefix = escapeshellarg($git_path) . " -C " . escapeshellarg($git_repo_dir) . " -c safe.directory=* ";

            $status = (string)shell_exec($gitCmdPrefix . 'status 2>&1');
            $branch = (string)shell_exec($gitCmdPrefix . 'rev-parse --abbrev-ref HEAD 2>&1');

            // Dynamically query remote/local branches using branch -a
            $branchesOutput = (string)shell_exec($gitCmdPrefix . "branch -a 2>&1");
            $remoteBranches = [];
            if ($branchesOutput && strpos($branchesOutput, 'fatal:') === false && strpos($branchesOutput, 'sh:') === false && strpos($branchesOutput, 'not found') === false) {
                $lines = explode("\n", $branchesOutput);
                foreach ($lines as $line) {
                    $line = trim($line, "* \t\r\n");
                    if (!$line) continue;
                    // Filter HEAD pointer and extract branch name
                    if (strpos($line, 'remotes/origin/HEAD') !== false) continue;
                    if (strpos($line, 'remotes/origin/') === 0) {
                        $b = substr($line, 15);
                    } elseif (strpos($line, 'origin/') === 0) {
                        $b = substr($line, 7);
                    } else {
                        $b = $line;
                    }
                    // Validate branch name doesn't contain spaces, colons or error messages
                    if ($b && !preg_match('/[\s:]/', $b) && !in_array($b, $remoteBranches)) {
                        $remoteBranches[] = $b;
                    }
                }
            }

            if (empty($remoteBranches)) {
                $remoteBranches = [$selectedBranch];
            }

            $remoteUrl = '';
            $remoteUrlCheck = (string)shell_exec($gitCmdPrefix . "config --get remote.origin.url 2>&1");
            if ($remoteUrlCheck && stripos($remoteUrlCheck, 'fatal:') === false && stripos($remoteUrlCheck, 'sh:') === false) {
                $remoteUrl = trim($remoteUrlCheck);
            }
            if (empty($remoteUrl)) {
                $remoteUrl = $settings['git_remote_url'];
            }

            echo json_encode([
                'success' => true,
                'status'  => trim((string)$status),
                'branch'  => trim((string)$branch),
                'version' => class_exists('AppVersion') ? AppVersion::get('full') : NU_VERSION,
                'version_short' => class_exists('AppVersion') ? AppVersion::get('version') : NU_VERSION,
                'selected_branch' => $selectedBranch,
                'remote_branches' => $remoteBranches,
                'git_path' => $git_path,
                'git_repo_dir' => $git_repo_dir,
                'git_remote_url' => $remoteUrl
            ]);
            break;

        case 'save_branch':
            $raw  = file_get_contents('php://input');
            $body = json_decode($raw ?: '{}', true);
            $newBranch = trim((string)($body['branch'] ?? 'main'));
            if (!$newBranch) {
                $newBranch = 'main';
            }

            $db = NuDatabase::getInstance();
            $db->query("CREATE TABLE IF NOT EXISTS `nu_settings` (
                `setting_key` VARCHAR(100) NOT NULL PRIMARY KEY,
                `setting_value` TEXT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            $db->query("INSERT INTO nu_settings (setting_key, setting_value) VALUES ('update_branch', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$newBranch, $newBranch]);
            echo json_encode(['success' => true]);
            break;

        case 'save_git_settings':
            $raw  = file_get_contents('php://input');
            $body = json_decode($raw ?: '{}', true);
            $gitPath = trim((string)($body['git_path'] ?? 'git'));
            $gitRepoDir = trim((string)($body['git_repo_dir'] ?? ''));
            $updateBranch = trim((string)($body['update_branch'] ?? 'main'));

            if (!$gitPath) {
                $gitPath = 'git';
            }

            // Check for spaces/clone commands to prevent misconfiguration errors
            if (preg_match('/\s+/', $gitPath) || stripos($gitPath, 'clone') !== false || stripos($gitPath, 'gh ') !== false || stripos($gitPath, '.git') !== false || stripos($gitPath, '@') !== false || stripos($gitPath, 'http') !== false) {
                echo json_encode([
                    'success' => false,
                    'error' => "Invalid Git Executable Path: It looks like you entered a Git Repository URL or command. Please enter ONLY 'git' or the absolute path to the git binary on your server (e.g. '/usr/bin/git')."
                ]);
                break;
            }

            if (!$gitRepoDir) {
                $gitRepoDir = realpath(__DIR__ . '/..') ?: '/app';
            }
            if (!$updateBranch) {
                $updateBranch = 'main';
            }

            $db = NuDatabase::getInstance();
            $db->query("CREATE TABLE IF NOT EXISTS `nu_settings` (
                `setting_key` VARCHAR(100) NOT NULL PRIMARY KEY,
                `setting_value` TEXT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $db->query("INSERT INTO nu_settings (setting_key, setting_value) VALUES ('git_path', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$gitPath, $gitPath]);
            $db->query("INSERT INTO nu_settings (setting_key, setting_value) VALUES ('git_repo_dir', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$gitRepoDir, $gitRepoDir]);
            $db->query("INSERT INTO nu_settings (setting_key, setting_value) VALUES ('update_branch', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$updateBranch, $updateBranch]);

            echo json_encode(['success' => true]);
            break;

        case 'git_init':
            $raw  = file_get_contents('php://input');
            $body = json_decode($raw ?: '{}', true);
            $gitPath = trim((string)($body['git_path'] ?? 'git'));
            $gitRepoDir = trim((string)($body['git_repo_dir'] ?? ''));
            $repoUrl = trim((string)($body['repo_url'] ?? ''));
            $branch = trim((string)($body['branch'] ?? 'main'));

            if (!$gitPath) {
                $gitPath = 'git';
            }
            if (!$gitRepoDir) {
                $gitRepoDir = realpath(__DIR__ . '/..') ?: '/app';
            }
            if (!$repoUrl) {
                echo json_encode(['success' => false, 'error' => 'Repository URL cannot be empty.']);
                break;
            }

            if (!is_dir($gitRepoDir)) {
                echo json_encode(['success' => false, 'error' => "The directory '{$gitRepoDir}' does not exist or is not accessible."]);
                break;
            }

            // Verify the Git binary works
            $gitEscaped = escapeshellarg($gitPath);
            $versionOutput = (string)shell_exec("{$gitEscaped} --version 2>&1");
            if (!$versionOutput || stripos($versionOutput, 'version') === false) {
                echo json_encode([
                    'success' => false,
                    'error' => "Failed to run Git with path '{$gitPath}'. Error details: " . trim($versionOutput)
                ]);
                break;
            }

            $gitCmdPrefix = $gitEscaped . " -C " . escapeshellarg($gitRepoDir) . " -c safe.directory=* ";

            $output = "Starting Git repository initialization...\n";

            // If .git already exists, we do not need to call init, just remote/fetch
            if (!is_dir(rtrim($gitRepoDir, '/') . '/.git')) {
                $res = (string)shell_exec($gitCmdPrefix . "init 2>&1");
                $output .= "git init:\n" . trim($res) . "\n\n";
            }

            // Save settings immediately so the user doesn't lose them
            $db = NuDatabase::getInstance();
            $db->query("CREATE TABLE IF NOT EXISTS `nu_settings` (
                `setting_key` VARCHAR(100) NOT NULL PRIMARY KEY,
                `setting_value` TEXT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            $db->query("INSERT INTO nu_settings (setting_key, setting_value) VALUES ('git_path', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$gitPath, $gitPath]);
            $db->query("INSERT INTO nu_settings (setting_key, setting_value) VALUES ('git_repo_dir', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$gitRepoDir, $gitRepoDir]);
            $db->query("INSERT INTO nu_settings (setting_key, setting_value) VALUES ('update_branch', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$branch, $branch]);
            $db->query("INSERT INTO nu_settings (setting_key, setting_value) VALUES ('git_remote_url', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$repoUrl, $repoUrl]);

            // Add or set remote origin
            // Check if origin already exists
            $remoteCheck = (string)shell_exec($gitCmdPrefix . "remote 2>&1");
            if (stripos($remoteCheck, 'origin') !== false) {
                $res = (string)shell_exec($gitCmdPrefix . "remote set-url origin " . escapeshellarg($repoUrl) . " 2>&1");
                $output .= "git remote set-url origin:\n" . trim($res) . "\n\n";
            } else {
                $res = (string)shell_exec($gitCmdPrefix . "remote add origin " . escapeshellarg($repoUrl) . " 2>&1");
                $output .= "git remote add origin:\n" . trim($res) . "\n\n";
            }

            // Fetch
            $output .= "Fetching branches from origin...\n";
            $res = (string)shell_exec($gitCmdPrefix . "fetch origin 2>&1");
            $output .= "git fetch:\n" . trim($res) . "\n\n";

            // Track remote branch, checkout (using force -f to bypass overwrite errors for manually uploaded files)
            $branchEscaped = escapeshellarg($branch);
            $output .= "Checking out branch '{$branch}'...\n";
            $res = (string)shell_exec($gitCmdPrefix . "checkout -f -B {$branchEscaped} --track origin/{$branchEscaped} 2>&1");
            if (stripos($res, 'fatal:') !== false) {
                // Try checkout without track if remote tracking branch doesn't exist yet or if already tracked
                $res = (string)shell_exec($gitCmdPrefix . "checkout -f -B {$branchEscaped} origin/{$branchEscaped} 2>&1");
            }
            $output .= "git checkout:\n" . trim($res) . "\n\n";

            // Because the files were uploaded manually, they might be marked as modified or git status might be messy.
            // Let's do a hard reset to match remote exactly to make sure update is clean.
            $output .= "Syncing with remote repository...\n";
            $res = (string)shell_exec($gitCmdPrefix . "reset --hard origin/{$branchEscaped} 2>&1");
            $output .= "git reset --hard:\n" . trim($res) . "\n\n";

            echo json_encode([
                'success' => true,
                'output' => $output
            ]);
            break;

        case 'test_git_settings':
            $raw  = file_get_contents('php://input');
            $body = json_decode($raw ?: '{}', true);
            $gitPath = trim((string)($body['git_path'] ?? ''));
            $gitRepoDir = trim((string)($body['git_repo_dir'] ?? ''));

            if (!$gitPath) {
                echo json_encode(['success' => false, 'error' => 'Git Executable Path cannot be empty.']);
                break;
            }

            // Reject commands containing multiple words or clone arguments to prevent common configuration mistakes
            if (preg_match('/\s+/', $gitPath) || stripos($gitPath, 'clone') !== false || stripos($gitPath, 'gh ') !== false || stripos($gitPath, '.git') !== false || stripos($gitPath, '@') !== false || stripos($gitPath, 'http') !== false) {
                echo json_encode([
                    'success' => false,
                    'error' => "Invalid Git Executable Path: It looks like you entered a Git Repository URL or command. Please enter ONLY 'git' or the absolute path to the git binary on your server (e.g. '/usr/bin/git')."
                ]);
                break;
            }

            if (!$gitRepoDir) {
                echo json_encode(['success' => false, 'error' => 'Git Repository Root Directory cannot be empty.']);
                break;
            }

            if (!is_dir($gitRepoDir)) {
                echo json_encode(['success' => false, 'error' => "The directory '{$gitRepoDir}' does not exist or is not accessible."]);
                break;
            }

            if (!is_dir(rtrim($gitRepoDir, '/') . '/.git')) {
                echo json_encode([
                    'success' => false,
                    'git_missing' => true,
                    'error' => "The directory '{$gitRepoDir}' exists, but it does not appear to be a git repository (no '.git' directory found)."
                ]);
                break;
            }

            // Verify the Git binary is executable and works by running --version
            $gitEscaped = escapeshellarg($gitPath);
            $versionOutput = (string)shell_exec("{$gitEscaped} --version 2>&1");
            if (!$versionOutput || stripos($versionOutput, 'version') === false) {
                echo json_encode([
                    'success' => false,
                    'error' => "Failed to run Git with path '{$gitPath}'. Error details: " . trim($versionOutput)
                ]);
                break;
            }

            // Run a quick status test using the custom prefix
            $gitCmdPrefix = $gitEscaped . " -C " . escapeshellarg($gitRepoDir) . " -c safe.directory=* ";
            $statusOutput = (string)shell_exec($gitCmdPrefix . 'status 2>&1');
            if (stripos($statusOutput, 'fatal:') !== false) {
                echo json_encode([
                    'success' => false,
                    'error' => "Git executable is working, but repository check failed. Git output: " . trim($statusOutput)
                ]);
                break;
            }

            echo json_encode([
                'success' => true,
                'message' => "Connection successful!\nGit version: " . trim((string)$versionOutput) . "\nRepository status: OK"
            ]);
            break;

        case 'git_fetch':
            $db = NuDatabase::getInstance();
            $settings = get_git_config($db);
            $gitCmdPrefix = escapeshellarg($settings['git_path']) . " -C " . escapeshellarg($settings['git_repo_dir']) . " -c safe.directory=* ";

            $output = shell_exec($gitCmdPrefix . 'fetch origin 2>&1');
            echo json_encode(['success' => true, 'output' => trim((string)$output)]);
            break;

        case 'git_pull':
            $db = NuDatabase::getInstance();
            $settings = get_git_config($db);
            $git_path = $settings['git_path'];
            $git_repo_dir = $settings['git_repo_dir'];
            $selectedBranch = $settings['update_branch'];

            $gitCmdPrefix = escapeshellarg($git_path) . " -C " . escapeshellarg($git_repo_dir) . " -c safe.directory=* ";
            $selectedBranchEscaped = escapeshellarg($selectedBranch);

            // Let's first fetch to have accurate remote state
            shell_exec($gitCmdPrefix . "fetch origin {$selectedBranchEscaped} 2>&1");

            // Capture the diff summary before we reset, to show the user what files changed
            $diffOutput = (string)shell_exec($gitCmdPrefix . "diff --name-status HEAD origin/{$selectedBranchEscaped} 2>&1");

            // Perform forced checkout to transition/switch cleanly
            shell_exec($gitCmdPrefix . "checkout -f {$selectedBranchEscaped} 2>&1");

            // Pull with force strategies or do a hard reset to remote to ensure local conflicts are cleanly overwritten
            $pullOutput = (string)shell_exec($gitCmdPrefix . "pull origin {$selectedBranchEscaped} -X theirs --no-rebase 2>&1");
            $resetOutput = (string)shell_exec($gitCmdPrefix . "reset --hard origin/{$selectedBranchEscaped} 2>&1");

            // Construct rich output response
            $output = "Git Pull:\n" . trim($pullOutput) . "\n\nGit Reset Hard:\n" . trim($resetOutput);
            if (!empty($diffOutput) && stripos($diffOutput, 'fatal:') === false) {
                $output .= "\n\nUpdated Files:\n" . trim($diffOutput);
            }

            echo json_encode(['success' => true, 'output' => trim($output), 'pulled_branch' => $selectedBranch]);
            break;

        case 'git_log':
            $db = NuDatabase::getInstance();
            $settings = get_git_config($db);
            $gitCmdPrefix = escapeshellarg($settings['git_path']) . " -C " . escapeshellarg($settings['git_repo_dir']) . " -c safe.directory=* ";

            $limit  = min(50, max(1, (int)($_GET['limit'] ?? 10)));
            $output = shell_exec($gitCmdPrefix . "log -n $limit --pretty=format:'%h|%an|%ar|%s' 2>&1");
            $lines  = explode("\n", trim((string)$output));
            $commits = [];
            foreach ($lines as $line) {
                if (!$line) continue;
                $parts = explode('|', $line, 4);
                $commits[] = [
                    'hash'   => $parts[0] ?? '',
                    'author' => $parts[1] ?? '',
                    'date'   => $parts[2] ?? '',
                    'msg'    => $parts[3] ?? ''
                ];
            }
            echo json_encode(['success' => true, 'commits' => $commits]);
            break;

        case 'config_read':
            $path = __DIR__ . '/../config.local.php';
            $content = file_exists($path) ? file_get_contents($path) : "<?php\n// Local configuration\n";
            echo json_encode(['success' => true, 'content' => $content, 'writable' => is_writable(file_exists($path) ? $path : dirname($path))]);
            break;

        case 'config_write':
            $path = __DIR__ . '/../config.local.php';
            $raw  = file_get_contents('php://input');
            $body = json_decode($raw ?: '{}', true);
            $content = (string)($body['content'] ?? '');
            if (strpos($content, '<?php') !== 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid PHP file: must start with <?php']);
                break;
            }
            // Backup
            if (file_exists($path)) {
                copy($path, $path . '.bak.' . date('YmdHis'));
            }
            $res = file_put_contents($path, $content);
            if ($res === false) {
                echo json_encode(['success' => false, 'error' => 'Failed to write config.local.php. Check permissions.']);
            } else {
                echo json_encode(['success' => true, 'bytes' => $res]);
            }
            break;

        case 'ping':
            echo json_encode(['success' => true, 'message' => 'pong']);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
