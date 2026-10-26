<?php
/**
 * views/scripts.php - App Scripts & Boot logic UI partial
 */
declare(strict_types=1);
?>
<?php if ($isLoggedIn): ?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="<?= nu_asset('assets/js/select2.min.js') ?>"></script>
<script src="<?= nu_asset('lib/uppy/uppy.min.js') ?>"></script>
<?php
require_once NU_ROOT . '/core/AppVersion.php';
$_commitVersion = urlencode(AppVersion::get('commit'));
?>
<script src="<?= nu_asset('assets/js/nu-browse-table.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nubuilder-next.js') ?>?v=<?= $_commitVersion ?>"></script>
<script src="<?= nu_asset('assets/js/nu-form-shell.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nu-shell.js') ?>"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.43.3/ace.min.js" ></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.43.3/ext-language_tools.min.js" ></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/js-beautify/1.15.1/beautify.min.js" ></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/js-beautify/1.15.1/beautify-css.min.js" ></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/js-beautify/1.15.1/beautify-html.min.js" ></script>
<script src="<?= nu_asset('assets/js/nb-ace-manager.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nb-form-builder.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nusubform.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nu-errorlogger.js') ?>"></script>
<script>
(function () {
    // ── Inject application release version into global JS context ──────────────
    window.NUVIS_VERSION = <?= json_encode(AppVersion::get('full')) ?>;
    // ── Inject server-side user role so NuPerms can evaluate access ──────────
    window.nuUserRole  = <?= json_encode($_role) ?>;
    // ── Inject per-action permission flags derived from role's wildcard row ──
    window.nuUserPerms = <?= json_encode($_nuUserPerms) ?>;
    // ── Inject global CSRF token for secure AJAX requests ────────────────────
    window.nuCsrfToken = <?= json_encode($csrfToken) ?>;
    // ── Inject global user meta and developer-configured system global hashes ──
    window.nuUserMeta  = <?= json_encode($isLoggedIn && $auth ? $auth->getGlobalMeta() : []) ?>;

    // Friendly helper function to retrieve all global hashes/user meta
    window.nuGetGlobalHashes = function() {
        return window.nuUserMeta || {};
    };

    // Log the loaded global hashes to the developer console
    if (window.nuUserMeta && Object.keys(window.nuUserMeta).length > 0) {
        console.log("%c📂 [NuBuilder] Global Hashes & User Meta Loaded:", "color: #4f6bed; font-weight: bold; font-size: 12px;");
        console.table(window.nuUserMeta);
    }

    // ── User Profile Dropdown Logic ──────────────────────────────────────────
    (function() {
        var toggle = document.getElementById('nuUserDropdownToggle');
        var menu   = document.getElementById('nuUserDropdownMenu');
        if (!toggle || !menu) return;

        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            var show = menu.classList.toggle('show');
            toggle.setAttribute('aria-expanded', show ? 'true' : 'false');
        });

        document.addEventListener('click', function (e) {
            if (!toggle.contains(e.target) && !menu.contains(e.target)) {
                menu.classList.remove('show');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });

        // Close dropdown when item is clicked (e.g. Change Password)
        menu.addEventListener('click', function (e) {
            menu.classList.remove('show');
            toggle.setAttribute('aria-expanded', 'false');
        });
    })();

    // Restore theme
    try {
        var t = localStorage.getItem('nu-theme');
        if (t) document.documentElement.setAttribute('data-theme', t);
    } catch (e) {}

    // Restore sidebar collapsed state
    try {
        if (localStorage.getItem('nu-sidebar-collapsed') === '1') {
            var app = document.getElementById('nuApp');
            if (app) app.classList.add('sidebar-collapsed');
        }
    } catch (e) {}

    function _boot() {
        if (!window.NuApp) return;
        <?php if (!empty($_SESSION['nu_must_change_password'])): ?>
            alert('Default credentials detected or password change required. Please update your password to continue.');
            NuApp.loadModule('password');
            return;
        <?php endif; ?>
        var hash = (location.hash || '').replace('#', '');
        NuApp.loadModule(hash || 'dashboard');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', _boot);
    } else {
        _boot();
    }
})();
</script>
<?php endif; ?>

<?php if (!$isLoggedIn): ?>
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(function () {});
}

// ─── Forgot Password Client-Side View Logic ─────────────────────────────────
function toggleForgotView(showForgot) {
    const loginView = document.getElementById('login-form-view');
    const forgotView = document.getElementById('forgot-form-view');
    if (!loginView || !forgotView) return;

    if (showForgot) {
        loginView.classList.add('hidden');
        forgotView.classList.remove('hidden');
        const input = document.getElementById('reset_identity');
        if (input) input.focus();
    } else {
        forgotView.classList.add('hidden');
        loginView.classList.remove('hidden');
        const input = document.getElementById('nu_username');
        if (input) input.focus();
    }
}

// ─── Handle Forgot Password Request ─────────────────────────────────────────
async function handleForgotSubmit(e) {
    e.preventDefault();
    const identityInput = document.getElementById('reset_identity');
    const alertEl = document.getElementById('forgot-alert');
    const btn = document.getElementById('forgot-submit-btn');
    if (!identityInput || !alertEl || !btn) return;

    const identity = identityInput.value.trim();
    if (identity === '') {
        showCardAlert(alertEl, 'Please enter your username or email address.', 'error');
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Sending...';

    try {
        const res = await fetch('api/forgot_password.php?action=send_reset_link', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ identity: identity })
        });
        const data = await res.json();
        if (data.success) {
            showCardAlert(alertEl, data.message, 'success');
            identityInput.value = '';
        } else {
            showCardAlert(alertEl, data.error || 'An error occurred. Please try again.', 'error');
        }
    } catch (err) {
        showCardAlert(alertEl, 'Network error. Please try again later.', 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Send Reset Link';
    }
}

// ─── Handle Password Reset Execution ────────────────────────────────────────
async function handlePasswordResetSubmit(e) {
    e.preventDefault();
    const token = document.getElementById('reset-token')?.value || '';
    const newPwdInput = document.getElementById('new_password');
    const confirmPwdInput = document.getElementById('confirm_password');
    const alertEl = document.getElementById('reset-alert');
    const btn = document.getElementById('reset-submit-btn');

    if (!newPwdInput || !confirmPwdInput || !alertEl || !btn) return;

    const newPwd = newPwdInput.value.trim();
    const confirmPwd = confirmPwdInput.value.trim();

    if (newPwd === '' || confirmPwd === '') {
        showCardAlert(alertEl, 'Please fill in all password fields.', 'error');
        return;
    }

    if (newPwd !== confirmPwd) {
        showCardAlert(alertEl, 'Passwords do not match.', 'error');
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Resetting...';

    try {
        const res = await fetch('api/forgot_password.php?action=reset_password', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                token: token,
                new_password: newPwd,
                confirm_password: confirmPwd
            })
        });
        const data = await res.json();
        if (data.success) {
            showCardAlert(alertEl, data.message, 'success');
            newPwdInput.value = '';
            confirmPwdInput.value = '';
            setTimeout(() => {
                window.location.href = 'index.php';
            }, 3000);
        } else {
            showCardAlert(alertEl, data.error || 'An error occurred. Please try again.', 'error');
        }
    } catch (err) {
        showCardAlert(alertEl, 'Network error. Please try again later.', 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Update Password';
    }
}

function showCardAlert(el, message, type) {
    el.classList.remove('hidden', 'bg-red-900/40', 'border-red-500', 'text-red-200', 'bg-emerald-900/40', 'border-emerald-500', 'text-emerald-200');
    if (type === 'success') {
        el.classList.add('bg-emerald-900/40', 'border', 'border-emerald-500', 'text-emerald-200');
    } else {
        el.classList.add('bg-red-900/40', 'border', 'border-red-500', 'text-red-200');
    }
    el.textContent = message;
}
</script>
<?php endif; ?>
