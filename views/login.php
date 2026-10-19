<?php
/**
 * views/login.php - Login & Password Reset UI partial
 */
declare(strict_types=1);
?>
<div class="nu-login">
    <div class="nu-login-card">
        <div class="nu-login-brand">
            <?php if ($customAppLogo !== ''): ?>
                <img src="<?= h($customAppLogo) ?>" alt="Logo" class="nu-custom-logo" style="max-height: 56px; margin: 0 auto 16px; display: block; border-radius: var(--radius-lg);">
            <?php else: ?>
                <div class="nu-logo">nu</div>
            <?php endif; ?>
            <h1><?= h($customAppName) ?></h1>
            <p>Modern Low-Code Platform</p>
        </div>

        <?php if ($bootError !== ''): ?>
            <div class="nu-login-error" style="background:rgba(255,193,7,.12);border-color:rgba(255,193,7,.4);color:#ffe9a8;display:block;word-break:break-word;font-size:0.8rem;">
                ⚠ <?= h($bootError) ?>
            </div>
        <?php endif; ?>

        <?php if ($loginError !== ''): ?>
            <div class="nu-login-error" style="display:block">
                <?= h($loginError) ?>
            </div>
        <?php endif; ?>

        <!-- ── Reset Password View (if token in URL) ── -->
        <?php if (isset($_GET['action']) && $_GET['action'] === 'reset_password' && isset($_GET['token'])): ?>
        <div id="reset-password-view" class="transition-opacity duration-300">
            <h2 class="text-xl font-bold mb-2 text-center" style="color: var(--text-primary, #fff);">Set New Password</h2>
            <p class="text-sm text-center mb-6" style="color: var(--text-secondary, #888);">Secure your account with a strong password conforming to security guidelines.</p>

            <div id="reset-alert" class="hidden mb-4 p-3 rounded text-sm"></div>

            <form id="reset-pwd-form" onsubmit="handlePasswordResetSubmit(event)" novalidate>
                <input type="hidden" id="reset-token" value="<?= h($_GET['token']) ?>">
                <div class="nu-field mb-4">
                    <label for="new_password">New Password</label>
                    <input id="new_password" type="password" class="nu-input" required>
                </div>
                <div class="nu-field mb-4">
                    <label for="confirm_password">Confirm New Password</label>
                    <input id="confirm_password" type="password" class="nu-input" required>
                </div>
                <button type="submit" id="reset-submit-btn" class="nu-btn nu-btn-primary nu-btn-block">
                    Update Password
                </button>
                <div class="text-center mt-4">
                    <a href="index.php" class="text-xs font-semibold hover:underline" style="color: var(--accent, #4f6bed);">Back to Sign In</a>
                </div>
            </form>
        </div>

        <!-- ── Standard Login and Forgot Password togglable container ── -->
        <?php else: ?>
        <div id="login-form-view" class="transition-all duration-300">
            <form method="post" action="index.php" autocomplete="off" novalidate>
                <input type="hidden" name="nu_csrf" value="<?= h($csrfToken) ?>">
                <div class="nu-field">
                    <label for="nu_username">Username</label>
                    <input id="nu_username" name="username" type="text"
                           class="nu-input" autocomplete="username"
                           value="" required autofocus spellcheck="false">
                </div>
                <div class="nu-field">
                    <div class="flex justify-between items-center mb-1">
                        <label for="nu_password" style="margin-bottom: 0;">Password</label>
                        <?php if ($forgotPasswordEnabled): ?>
                            <a href="#" onclick="toggleForgotView(true); return false;" class="text-xs font-semibold hover:underline" style="color: var(--accent, #4f6bed);">Forgot Password?</a>
                        <?php endif; ?>
                    </div>
                    <input id="nu_password" name="password" type="password"
                           class="nu-input" autocomplete="current-password" required>
                </div>
                <button type="submit" name="login_submit" value="1"
                        class="nu-btn nu-btn-primary nu-btn-block">
                    Sign In
                </button>
            </form>

            <?php
            $ssoProviders = [];
            try {
                if (file_exists(__DIR__ . '/../core/Sso/SsoManager.php')) {
                    require_once __DIR__ . '/../core/Sso/SsoManager.php';
                    $ssoProviders = \Nuvis\Sso\SsoManager::getActiveProviders();
                }
            } catch (\Throwable $e) {}
            ?>
            <?php if (!empty($ssoProviders)): ?>
            <div style="margin-top: 16px; border-top: 1px solid var(--border-color, #334155); padding-top: 16px;">
                <p style="font-size: 12px; color: var(--text-muted, #94a3b8); text-align: center; margin-bottom: 10px;">Or sign in with Enterprise SSO:</p>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <?php foreach ($ssoProviders as $sso): ?>
                    <a href="api/sso.php?action=sso_login&provider=<?= (int)$sso['id'] ?>" class="nu-btn nu-btn-ghost nu-btn-block" style="display: flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; border: 1px solid var(--border-color);">
                        🔐 Login with <?= h($sso['name']) ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($forgotPasswordEnabled): ?>
        <div id="forgot-form-view" class="hidden transition-all duration-300">
            <h2 class="text-xl font-bold mb-2 text-center" style="color: var(--text-primary, #fff);">Reset Password</h2>
            <p class="text-sm text-center mb-6" style="color: var(--text-secondary, #888);">Enter your registered username or email to receive a password reset link.</p>

            <div id="forgot-alert" class="hidden mb-4 p-3 rounded text-sm"></div>

            <form id="forgot-submit-form" onsubmit="handleForgotSubmit(event)" novalidate>
                <div class="nu-field mb-4">
                    <label for="reset_identity">Username or Email Address</label>
                    <input id="reset_identity" type="text" class="nu-input" required placeholder="e.g. globeadmin">
                </div>
                <button type="submit" id="forgot-submit-btn" class="nu-btn nu-btn-primary nu-btn-block">
                    Send Reset Link
                </button>
                <div class="text-center mt-4">
                    <a href="#" onclick="toggleForgotView(false); return false;" class="text-xs font-semibold hover:underline" style="color: var(--accent, #4f6bed);">Back to Sign In</a>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>
</div>
