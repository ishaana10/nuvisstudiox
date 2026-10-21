<?php
// nuBuilder Next - Setup / Installer
// Run this file once to verify environment and create database

error_reporting(E_ALL);
ini_set('display_errors', '0');

$isLocked = file_exists(__DIR__ . '/installed.lock');
$allowForce = isset($_GET['force']) && $_GET['force'] === '1';

$step = $_GET['step'] ?? ($isLocked && !$allowForce ? 'locked' : 'check');
$errors = [];
$warnings = [];

function checkRequirement($name, $check, $required = true) {
    global $errors, $warnings;
    if ($check) {
        return '<span style="color:var(--success)">✓ ' . $name . '</span>';
    } else {
        if ($required) {
            $errors[] = $name;
            return '<span style="color:var(--danger)">✗ ' . $name . ' (Required)</span>';
        } else {
            $warnings[] = $name;
            return '<span style="color:var(--warning)">⚠ ' . $name . ' (Recommended)</span>';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="auto">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>nuBuilder Next - Setup</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/nubuilder-next.css">
    <style>
        body { background: var(--bg-secondary); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .setup-card { width: 100%; max-width: 600px; background: var(--bg-elevated); border-radius: var(--radius-xl); padding: 40px; box-shadow: var(--shadow-lg); border: 1px solid var(--border-color); }
        .setup-brand { text-align: center; margin-bottom: 32px; }
        .setup-logo { width: 56px; height: 56px; background: var(--accent); color: #fff; border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: 700; margin: 0 auto 16px; }
        .setup-brand h1 { font-size: 22px; font-weight: 700; margin-bottom: 4px; }
        .setup-brand p { color: var(--text-secondary); font-size: 14px; }
        .setup-step { margin-bottom: 24px; }
        .setup-step h3 { font-size: 16px; font-weight: 600; margin-bottom: 12px; }
        .setup-checks { display: flex; flex-direction: column; gap: 8px; font-size: 14px; }
        .setup-actions { display: flex; gap: 10px; margin-top: 24px; }
        .setup-success { background: var(--success-light); color: var(--success); padding: 16px; border-radius: var(--radius-md); margin-bottom: 16px; }
        .setup-error { background: var(--danger-light); color: var(--danger); padding: 16px; border-radius: var(--radius-md); margin-bottom: 16px; }
        .id-type-option { display: flex; align-items: flex-start; gap: 10px; padding: 14px; border: 2px solid var(--border-color); border-radius: var(--radius-md); cursor: pointer; transition: border-color 0.2s; margin-bottom: 10px; }
        .id-type-option:has(input:checked) { border-color: var(--accent); background: var(--accent-light, #f0f4ff); }
        .id-type-option input { margin-top: 3px; accent-color: var(--accent); }
        .id-type-option strong { display: block; margin-bottom: 4px; }
        .id-type-option span { font-size: 13px; color: var(--text-secondary); }
        .id-type-option code { background: var(--bg-secondary); padding: 1px 5px; border-radius: 3px; font-size: 12px; }
    </style>
</head>
<body>
    <div class="setup-card">
        <div class="setup-brand">
            <div class="setup-logo">nu</div>
            <h1>nuBuilder Next Setup</h1>
            <p>Version 5.0.0 - Environment Check</p>
        </div>

        <?php if ($step === 'locked'): ?>
        <div class="setup-step" style="text-align:center;">
            <div style="font-size: 40px; margin-bottom: 12px;">🔒</div>
            <h3>Application Already Installed</h3>
            <p style="color:var(--text-secondary);font-size:14px;margin-bottom:24px;line-height:1.5;">
                Nuvis StudioX is already installed and configured on this server. <br>
                If you wish to re-verify environment requirements or re-initialize the database, click below.
            </p>
            <div class="setup-actions" style="justify-content:center;">
                <a href="index.php" class="nu-btn nu-btn-primary">Launch Application</a>
                <a href="setup.php?force=1" class="nu-btn nu-btn-ghost">Re-run Setup &amp; Checks</a>
            </div>
        </div>

        <?php elseif ($step === 'check'): ?>
        <div class="setup-step">
            <h3>System Requirements</h3>
            <div class="setup-checks">
                <?php echo checkRequirement('PHP 8.1+', version_compare(PHP_VERSION, '8.1.0', '>=')); ?>
                <?php echo checkRequirement('PDO Extension', extension_loaded('pdo')); ?>
                <?php echo checkRequirement('PDO MySQL', extension_loaded('pdo_mysql')); ?>
                <?php echo checkRequirement('JSON Extension', extension_loaded('json')); ?>
                <?php echo checkRequirement('MBString', extension_loaded('mbstring'), false); ?>
                <?php echo checkRequirement('GD/Image', extension_loaded('gd'), false); ?>
            </div>
        </div>

        <div class="setup-step">
            <h3>File Permissions</h3>
            <div class="setup-checks">
                <?php echo checkRequirement('Config file readable', is_readable('config.php')); ?>
                <?php echo checkRequirement('Uploads directory writable', is_writable('uploads') || @mkdir('uploads', 0755)); ?>
                <?php echo checkRequirement('Logs directory writable', is_writable('logs') || @mkdir('logs', 0755)); ?>
            </div>
        </div>

        <div class="setup-step">
            <h3>Database Connection</h3>
            <div class="setup-checks">
                <?php
                $dbOk = false;
                try {
                    require_once 'config.php';
                    $dsn = "mysql:host={$nuConfig['dbHost']};dbname={$nuConfig['dbName']};charset={$nuConfig['dbCharset']}";
                    $pdo = new PDO($dsn, $nuConfig['dbUser'], $nuConfig['dbPassword']);
                    $dbOk = true;
                    echo checkRequirement('Database connection', true);
                    echo checkRequirement('Database "' . $nuConfig['dbName'] . '" exists', true);
                } catch (Exception $e) {
                    echo checkRequirement('Database connection', false);
                    echo '<span style="color:var(--danger);font-size:12px;">Error: ' . htmlspecialchars($e->getMessage()) . '</span>';
                }
                ?>
            </div>
        </div>

        <?php if (empty($errors)): ?>
            <div class="setup-success">
                <strong>All requirements met!</strong> You can proceed with installation.
            </div>
            <div class="setup-actions">
                <a href="setup.php?step=configure" class="nu-btn nu-btn-primary">Configure &amp; Install</a>
                <a href="index.php" class="nu-btn nu-btn-ghost">Go to Application</a>
            </div>
        <?php else: ?>
            <div class="setup-error">
                <strong>Please fix the errors above before continuing.</strong>
            </div>
        <?php endif; ?>

        <?php elseif ($step === 'configure'): ?>
        <div class="setup-step">
            <h3>User ID Type</h3>
            <p style="font-size:14px;color:var(--text-secondary);margin-bottom:16px;">
                Choose how <strong>usr_id</strong> is generated. This setting is written to
                <code>config.local.php</code> and cannot be changed after install without a migration.
            </p>
            <form method="POST" action="setup.php?step=install">
                <label class="id-type-option">
                    <input type="radio" name="user_id_type" value="uuid" checked>
                    <div>
                        <strong>UUID <span style="font-weight:400;font-size:12px;color:var(--text-secondary);">(VARCHAR 36)</span></strong>
                        <span>e.g. <code>550e8400-e29b-41d4-a716-446655440000</code><br>
                        Recommended for nuBuilder Forte data transfer &amp; multi-instance migration.
                        Uses MySQL 8.0 <code>DEFAULT (UUID())</code> — globally unique across installations.</span>
                    </div>
                </label>
                <label class="id-type-option">
                    <input type="radio" name="user_id_type" value="auto_increment">
                    <div>
                        <strong>Auto Increment <span style="font-weight:400;font-size:12px;color:var(--text-secondary);">(INT)</span></strong>
                        <span>e.g. <code>1, 2, 3 ...</code><br>
                        Simpler for standalone installs. Smaller index size and faster joins,
                        but IDs may conflict during data transfer between instances.</span>
                    </div>
                </label>
                <div class="setup-actions">
                    <button type="submit" class="nu-btn nu-btn-primary">Install Database</button>
                    <a href="setup.php?step=check" class="nu-btn nu-btn-ghost">Back</a>
                </div>
            </form>
        </div>

        <?php elseif ($step === 'install'): ?>
            <?php
            // Read and validate chosen ID type from POST
            $userIdType = $_POST['user_id_type'] ?? 'uuid';
            $userIdType = in_array($userIdType, ['uuid', 'auto_increment']) ? $userIdType : 'uuid';

            $sqlFile = 'install.sql';
            if (!file_exists($sqlFile)) {
                echo '<div class="setup-error">install.sql not found</div>';
            } else {
                try {
                    require_once 'config.php';
                    $dsn = "mysql:host={$nuConfig['dbHost']};dbname={$nuConfig['dbName']};charset={$nuConfig['dbCharset']}";
                    $pdo = new PDO($dsn, $nuConfig['dbUser'], $nuConfig['dbPassword'], [
                        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
                    ]);
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                    // Ensure nu_menus columns exist in case they run setup on an existing database
                    try {
                        $tableExists = $pdo->query("SHOW TABLES LIKE 'nu_menus'")->fetch();
                        if ($tableExists) {
                            $columns = [];
                            $stmt = $pdo->query("SHOW COLUMNS FROM `nu_menus`");
                            while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                $columns[] = strtolower($col['Field']);
                            }
                            if (!in_array('menu_role_access', $columns, true)) {
                                $pdo->exec("ALTER TABLE `nu_menus` ADD COLUMN `menu_role_access` VARCHAR(512) DEFAULT NULL");
                            }
                            if (!in_array('menu_open_mode', $columns, true)) {
                                $pdo->exec("ALTER TABLE `nu_menus` ADD COLUMN `menu_open_mode` VARCHAR(30) NOT NULL DEFAULT 'inline|browse'");
                            }
                            if (!in_array('menu_browse_mode', $columns, true)) {
                                $pdo->exec("ALTER TABLE `nu_menus` ADD COLUMN `menu_browse_mode` VARCHAR(10) NOT NULL DEFAULT 'inline'");
                            }
                            if (!in_array('menu_preview_mode', $columns, true)) {
                                $pdo->exec("ALTER TABLE `nu_menus` ADD COLUMN `menu_preview_mode` VARCHAR(10) NOT NULL DEFAULT 'inline'");
                            }
                            if (!in_array('menu_default_view', $columns, true)) {
                                $pdo->exec("ALTER TABLE `nu_menus` ADD COLUMN `menu_default_view` VARCHAR(10) NOT NULL DEFAULT 'browse'");
                            }
                        }
                    } catch (Exception $ignored) {}

                    $sql = file_get_contents($sqlFile);

                    if ($userIdType === 'uuid') {
                        // MySQL 8.0+: replace INT AUTO_INCREMENT with VARCHAR(36) DEFAULT (UUID())
                        // Targets the usr_id PRIMARY KEY line in nu_users
                        $sql = preg_replace(
                            '/(`usr_id`\s+)INT(?:\(\d+\))?\s+AUTO_INCREMENT\s+PRIMARY KEY/i',
                            '$1VARCHAR(36) NOT NULL DEFAULT (UUID()) PRIMARY KEY',
                            $sql
                        );
                        // Also replace all foreign key column types referencing usr_id
                        $fk_cols = [
                            'umeta_user_id', 'token_user_id', 'usage_user_id', 'file_uploaded_by',
                            'form_created_by', 'report_created_by', 'query_created_by', 'doc_created_by',
                            'sig_user_id', 'event_user_id', 'ph_user_id', 'wf_created_by',
                            'wfi_started_by', 'wfh_actor_id', 'errlog_user_id', 'audit_user_id',
                            'widget_user_id'
                        ];
                        foreach ($fk_cols as $col) {
                            $pattern = '/(`' . $col . '`\s+)INT(?:\(\d+\))?(?:\s+UNSIGNED)?/i';
                            $sql = preg_replace($pattern, '$1VARCHAR(36)', $sql);
                        }
                    }
                    // else: leave SQL as-is for auto_increment

                    // Write user_id_type to config.local.php
                    $configLocalPath = 'config.local.php';
                    $existingConfig = file_exists($configLocalPath) ? file_get_contents($configLocalPath) : "<?php\n";
                    if (strpos($existingConfig, 'userIdType') === false) {
                        $existingConfig = rtrim($existingConfig) . "\n\$nuConfig['userIdType'] = '{$userIdType}';\n";
                    } else {
                        $existingConfig = preg_replace(
                            '/\$nuConfig\[\'userIdType\'\]\s*=\s*\'[^\']+\';/',
                            "\$nuConfig['userIdType'] = '{$userIdType}';",
                            $existingConfig
                        );
                    }
                    file_put_contents($configLocalPath, $existingConfig);

                    // Split and execute statements safely by parsing quotes and escapes
                    // This ensures semicolons inside string literals/HTML/JSON do not cause syntax errors
                    $statements = [];
                    $length = strlen($sql);
                    $current = '';
                    $inSingleQuote = false;
                    $inDoubleQuote = false;
                    $escaped = false;

                    for ($i = 0; $i < $length; $i++) {
                        $char = $sql[$i];

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

                    $statements = array_filter(array_map('trim', $statements));

                    foreach ($statements as $stmt) {
                        if (!empty($stmt)) {
                            try {
                                if (preg_match('/^\s*select\s/i', $stmt)) {
                                    $stmt_obj = $pdo->query($stmt);
                                    if ($stmt_obj) {
                                        $stmt_obj->closeCursor();
                                    }
                                } else {
                                    $pdo->exec($stmt);
                                }
                            } catch (PDOException $e) {
                                $msg = $e->getMessage();
                                if (strpos($msg, 'already exists') === false && strpos($msg, 'Duplicate entry') === false) {
                                    throw $e;
                                }
                            }
                        }
                    }

                    // Ensure all new columns on nu_menus exist, in case the table already existed
                    try {
                        $pdo->exec("ALTER TABLE nu_menus ADD COLUMN menu_role_access VARCHAR(512) DEFAULT NULL");
                    } catch (PDOException $ignored) {}
                    try {
                        $pdo->exec("ALTER TABLE nu_menus ADD COLUMN menu_open_mode VARCHAR(30) NOT NULL DEFAULT 'inline|browse'");
                    } catch (PDOException $ignored) {}
                    try {
                        $pdo->exec("ALTER TABLE nu_menus ADD COLUMN menu_browse_mode VARCHAR(10) NOT NULL DEFAULT 'inline'");
                    } catch (PDOException $ignored) {}
                    try {
                        $pdo->exec("ALTER TABLE nu_menus ADD COLUMN menu_preview_mode VARCHAR(10) NOT NULL DEFAULT 'inline'");
                    } catch (PDOException $ignored) {}
                    try {
                        $pdo->exec("ALTER TABLE nu_menus ADD COLUMN menu_default_view VARCHAR(10) NOT NULL DEFAULT 'browse'");
                    } catch (PDOException $ignored) {}

                    // Lock installation
                    @file_put_contents(__DIR__ . '/installed.lock', 'Installed on ' . date('Y-m-d H:i:s'));

                    $idLabel = $userIdType === 'uuid' ? 'UUID — VARCHAR(36) with DEFAULT (UUID())' : 'Auto Increment — INT';
                    echo '<div class="setup-success">Database installed successfully!<br>User ID type: <strong>' . htmlspecialchars($idLabel) . '</strong></div>';
                    echo '<div class="setup-actions">';
                    echo '<a href="index.php" class="nu-btn nu-btn-primary">Launch Application</a>';
                    echo '</div>';
                    echo '<p style="margin-top:16px;font-size:13px;color:var(--text-secondary);">Default login: <strong>globeadmin</strong> / <strong>password</strong> (change immediately)</p>';
                } catch (Exception $e) {
                    echo '<div class="setup-error">Installation failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    echo '<div class="setup-actions"><a href="setup.php?step=configure" class="nu-btn nu-btn-ghost">Back to Configure</a></div>';
                }
            }
            ?>
        <?php endif; ?>
    </div>
</body>
</html>
