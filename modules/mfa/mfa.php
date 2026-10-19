<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

$currentUser = $auth->getCurrentUser();
if (!$currentUser) {
    echo '<div style="padding:20px;color:red;">Unauthorized</div>';
    exit;
}
?>
<div class="nu-card nu-mfa-module" style="padding:24px; max-width:650px; margin:20px auto;">
    <div class="nu-card-header" style="margin-bottom:20px;">
        <h3 class="nu-card-title" style="font-size:20px; font-weight:700;">Multi-Factor Authentication (MFA)</h3>
        <p style="color:var(--text-muted); font-size:13px; margin-top:4px;">Secure your account using time-based one-time passwords (TOTP) from Google Authenticator, Authy, or 1Password.</p>
    </div>

    <div id="mfaStatusCard" style="background:var(--bg-elevated, #1e293b); padding:20px; border-radius:10px; border:1px solid var(--border-color); margin-bottom:20px;">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <div>
                <strong style="font-size:15px; display:block;" id="mfaStatusText">Checking MFA status...</strong>
                <span style="font-size:12px; color:var(--text-muted);" id="mfaStatusSub">...</span>
            </div>
            <span id="mfaBadge" class="nu-status pending">Loading</span>
        </div>
    </div>

    <!-- Enrollment Form -->
    <div id="mfaSetupSection" style="display:none; background:var(--bg-elevated); padding:20px; border-radius:10px; border:1px solid var(--border-color);">
        <h4 style="font-size:16px; font-weight:600; margin-bottom:12px;">Step 1: Scan QR Code or Copy Secret Key</h4>
        <div style="display:flex; gap:20px; align-items:center; flex-wrap:wrap; margin-bottom:20px;">
            <div id="mfaQrContainer" style="background:#fff; padding:12px; border-radius:8px; width:160px; height:160px; display:flex; align-items:center; justify-content:center;">
                <!-- QR Image injected here -->
            </div>
            <div style="flex:1; min-width:200px;">
                <label style="font-size:12px; font-weight:600; color:var(--text-muted);">Manual Secret Key</label>
                <div style="display:flex; gap:8px; margin-top:4px;">
                    <input id="mfaSecretInput" type="text" class="nu-input" readonly style="font-family:monospace; letter-spacing:2px; font-weight:700;">
                    <button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('mfaSecretInput').value); if(window.NuApp) NuApp.toast('Key copied!');">Copy</button>
                </div>
            </div>
        </div>

        <h4 style="font-size:16px; font-weight:600; margin-bottom:12px;">Step 2: Enter 6-Digit Code to Confirm</h4>
        <div style="display:flex; gap:12px; align-items:center; max-width:320px;">
            <input id="mfaCodeInput" type="text" class="nu-input" placeholder="000000" maxlength="6" style="font-size:20px; letter-spacing:4px; text-align:center; font-family:monospace;">
            <button type="button" class="nu-btn nu-btn-primary" onclick="MfaModule.confirmSetup()">Enable MFA</button>
        </div>
    </div>

    <!-- Enabled Options -->
    <div id="mfaEnabledSection" style="display:none; margin-top:20px;">
        <div style="display:flex; gap:12px;">
            <button type="button" class="nu-btn nu-btn-secondary" onclick="MfaModule.getBackupCodes()">🔑 View Backup Codes</button>
            <button type="button" class="nu-btn nu-btn-danger" onclick="MfaModule.promptDisable()">Disable MFA</button>
        </div>
    </div>
</div>

<script>
var MfaModule = (function() {
    var secret = '';

    async function loadStatus() {
        try {
            var res = await fetch('api/mfa.php?action=status');
            var json = await res.json();
            if (json.success) {
                var badge = document.getElementById('mfaBadge');
                var text  = document.getElementById('mfaStatusText');
                var sub   = document.getElementById('mfaStatusSub');

                if (json.enabled) {
                    badge.className = 'nu-status active';
                    badge.textContent = 'ENABLED';
                    text.textContent = 'MFA is active on your account';
                    sub.textContent = json.enrolled_at ? 'Enrolled at ' + json.enrolled_at : 'Protection active';
                    document.getElementById('mfaEnabledSection').style.display = 'block';
                    document.getElementById('mfaSetupSection').style.display = 'none';
                } else {
                    badge.className = 'nu-status inactive';
                    badge.textContent = 'DISABLED';
                    text.textContent = 'MFA is not enabled';
                    sub.textContent = json.enforcement !== 'optional' ? 'Enforcement rule: ' + json.enforcement : 'Optional security enhancement';
                    document.getElementById('mfaEnabledSection').style.display = 'none';
                    startSetup();
                }
            }
        } catch (e) {
            console.error('[MFA] load status error', e);
        }
    }

    async function startSetup() {
        try {
            var res = await fetch('api/mfa.php?action=setup');
            var json = await res.json();
            if (json.success) {
                secret = json.secret;
                document.getElementById('mfaSecretInput').value = secret;
                document.getElementById('mfaQrContainer').innerHTML = '<img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' + encodeURIComponent(json.otpauth_url) + '" style="max-width:100%; height:auto;">';
                document.getElementById('mfaSetupSection').style.display = 'block';
            }
        } catch (e) {
            console.error('[MFA] setup error', e);
        }
    }

    async function confirmSetup() {
        var code = document.getElementById('mfaCodeInput').value.trim();
        if (!code || code.length !== 6) {
            if (window.NuApp) NuApp.toast('Please enter a 6-digit MFA code', 'error');
            return;
        }

        try {
            var res = await fetch('api/mfa.php?action=confirm', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ secret: secret, code: code })
            });
            var json = await res.json();
            if (json.success) {
                if (window.NuApp) NuApp.toast('MFA enabled successfully!');
                if (json.backup_codes && json.backup_codes.length) {
                    alert('Save your backup codes in a safe place:\n\n' + json.backup_codes.join('\n'));
                }
                loadStatus();
            } else {
                if (window.NuApp) NuApp.toast(json.message || 'MFA verification failed', 'error');
            }
        } catch (e) {
            console.error('[MFA] confirm error', e);
        }
    }

    async function getBackupCodes() {
        try {
            var res = await fetch('api/mfa.php?action=backup_codes');
            var json = await res.json();
            if (json.success && json.backup_codes) {
                alert('Your new backup codes (store safely):\n\n' + json.backup_codes.join('\n'));
            }
        } catch (e) {
            console.error('[MFA] backup codes error', e);
        }
    }

    async function promptDisable() {
        var pwd = prompt('Enter your current password to disable MFA:');
        if (!pwd) return;
        var code = prompt('Enter a current 6-digit MFA code:');
        if (!code) return;

        try {
            var res = await fetch('api/mfa.php?action=disable', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ current_password: pwd, code: code })
            });
            var json = await res.json();
            if (json.success) {
                if (window.NuApp) NuApp.toast('MFA disabled');
                loadStatus();
            } else {
                if (window.NuApp) NuApp.toast(json.message || 'Failed to disable MFA', 'error');
            }
        } catch (e) {
            console.error('[MFA] disable error', e);
        }
    }

    loadStatus();

    return {
        confirmSetup: confirmSetup,
        getBackupCodes: getBackupCodes,
        promptDisable: promptDisable
    };
})();
</script>
