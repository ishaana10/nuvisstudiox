<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

$currentUser = $auth->getCurrentUser();
if (!$currentUser || strtolower((string)$currentUser['usr_role']) !== 'globeadmin') {
    echo '<div style="padding:20px;color:red;">Access Denied</div>';
    exit;
}
?>
<div class="nu-card nu-sso-module" style="padding:24px;">
    <div class="nu-card-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <div>
            <h3 class="nu-card-title" style="font-size:20px; font-weight:700;">Single Sign-On (SSO) Providers</h3>
            <p style="color:var(--text-muted); font-size:13px; margin-top:4px;">Configure SAML 2.0 and OpenID Connect (OIDC) enterprise identity providers (Azure AD, Google Workspace, Okta, Keycloak).</p>
        </div>
        <button type="button" class="nu-btn nu-btn-primary" onclick="SsoAdmin.openModal()">➕ Add SSO Provider</button>
    </div>

    <div style="overflow-x:auto;">
        <table class="nu-table" style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="border-bottom:2px solid var(--border-color); background:var(--table-head-bg);">
                    <th style="padding:10px; text-align:left;">Provider Name</th>
                    <th style="padding:10px; text-align:left;">Protocol Type</th>
                    <th style="padding:10px; text-align:center;">Status</th>
                    <th style="padding:10px; text-align:center;">Default?</th>
                    <th style="padding:10px; text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody id="ssoProvidersTable">
                <tr><td colspan="5" style="padding:20px; text-align:center; color:var(--text-muted);">Loading providers...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal for Provider Config -->
<div id="ssoModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:99999; align-items:center; justify-content:center;">
    <div style="background:var(--bg-card, #1e293b); border:1px solid var(--border-color); border-radius:12px; width:100%; max-width:550px; padding:24px;">
        <h3 id="ssoModalTitle" style="font-size:18px; font-weight:700; margin-bottom:16px;">Configure SSO Provider</h3>
        <form onsubmit="SsoAdmin.saveProvider(event)">
            <input type="hidden" id="ssoId" value="0">
            <div style="margin-bottom:12px;">
                <label class="nu-label">Provider Name *</label>
                <input id="ssoName" type="text" class="nu-input" placeholder="e.g. Azure AD / Google Workspace" required>
            </div>
            <div style="margin-bottom:12px;">
                <label class="nu-label">Protocol Type *</label>
                <select id="ssoType" class="nu-input">
                    <option value="oidc">OpenID Connect (OIDC / OAuth 2.0)</option>
                    <option value="saml">SAML 2.0</option>
                </select>
            </div>
            <div style="margin-bottom:12px;">
                <label class="nu-label">Configuration JSON *</label>
                <textarea id="ssoConfigJson" class="nu-input" rows="6" style="font-family:monospace; font-size:12px;" placeholder='{"client_id": "...", "client_secret": "...", "authorize_url": "...", "token_url": "...", "userinfo_url": "..."}'></textarea>
            </div>
            <div style="display:flex; gap:16px; margin-bottom:20px;">
                <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
                    <input type="checkbox" id="ssoEnabled" checked> Enabled
                </label>
                <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
                    <input type="checkbox" id="ssoDefault"> Default Provider
                </label>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="nu-btn nu-btn-ghost" onclick="document.getElementById('ssoModal').style.display='none'">Cancel</button>
                <button type="submit" class="nu-btn nu-btn-primary">Save Provider</button>
            </div>
        </form>
    </div>
</div>

<script>
var SsoAdmin = (function() {
    var providers = [];

    async function loadProviders() {
        try {
            var res = await fetch('api/sso.php?action=get_providers');
            var json = await res.json();
            if (json.success) {
                providers = json.providers || [];
                renderTable();
            }
        } catch (e) {
            console.error('[SSO Admin] load error', e);
        }
    }

    function renderTable() {
        var tbody = document.getElementById('ssoProvidersTable');
        tbody.innerHTML = '';
        if (providers.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="padding:20px; text-align:center; color:var(--text-muted);">No SSO providers configured yet.</td></tr>';
            return;
        }

        providers.forEach(function(p) {
            var tr = document.createElement('tr');
            tr.style.cssText = 'border-bottom:1px solid var(--border-color);';
            tr.innerHTML = '<td style="padding:10px; font-weight:600;">' + (p.name || '') + '</td>' +
                '<td style="padding:10px;"><span class="nu-status pending">' + (p.type || '').toUpperCase() + '</span></td>' +
                '<td style="padding:10px; text-align:center;"><span class="nu-status ' + (p.enabled == 1 ? 'active' : 'inactive') + '">' + (p.enabled == 1 ? 'Active' : 'Disabled') + '</span></td>' +
                '<td style="padding:10px; text-align:center;">' + (p.is_default == 1 ? '⭐ Yes' : 'No') + '</td>' +
                '<td style="padding:10px; text-align:right;">' +
                '<button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="SsoAdmin.editProvider(' + p.id + ')">Edit</button> ' +
                '<button class="nu-btn nu-btn-danger nu-btn-sm" onclick="SsoAdmin.deleteProvider(' + p.id + ')">Delete</button>' +
                '</td>';
            tbody.appendChild(tr);
        });
    }

    function openModal() {
        document.getElementById('ssoId').value = 0;
        document.getElementById('ssoName').value = '';
        document.getElementById('ssoType').value = 'oidc';
        document.getElementById('ssoConfigJson').value = '{\n  "client_id": "",\n  "client_secret": "",\n  "authorize_url": "",\n  "token_url": "",\n  "userinfo_url": ""\n}';
        document.getElementById('ssoEnabled').checked = true;
        document.getElementById('ssoDefault').checked = false;
        document.getElementById('ssoModalTitle').textContent = 'Add SSO Provider';
        document.getElementById('ssoModal').style.display = 'flex';
    }

    function editProvider(id) {
        var p = providers.find(function(item) { return item.id == id; });
        if (!p) return;
        document.getElementById('ssoId').value = p.id;
        document.getElementById('ssoName').value = p.name;
        document.getElementById('ssoType').value = p.type;
        document.getElementById('ssoConfigJson').value = p.config_json;
        document.getElementById('ssoEnabled').checked = (p.enabled == 1);
        document.getElementById('ssoDefault').checked = (p.is_default == 1);
        document.getElementById('ssoModalTitle').textContent = 'Edit SSO Provider #' + p.id;
        document.getElementById('ssoModal').style.display = 'flex';
    }

    async function saveProvider(e) {
        e.preventDefault();
        var id = document.getElementById('ssoId').value;
        var name = document.getElementById('ssoName').value;
        var type = document.getElementById('ssoType').value;
        var config = document.getElementById('ssoConfigJson').value;
        var enabled = document.getElementById('ssoEnabled').checked;
        var isDefault = document.getElementById('ssoDefault').checked;

        try {
            var res = await fetch('api/sso.php?action=save_provider', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id: id,
                    name: name,
                    type: type,
                    config_json: config,
                    enabled: enabled,
                    is_default: isDefault
                })
            });
            var json = await res.json();
            if (json.success) {
                if (window.NuApp) NuApp.toast('SSO provider saved');
                document.getElementById('ssoModal').style.display = 'none';
                loadProviders();
            } else {
                if (window.NuApp) NuApp.toast(json.error || 'Save failed', 'error');
            }
        } catch (err) {
            console.error('[SSO Admin] save error', err);
        }
    }

    async function deleteProvider(id) {
        if (!confirm('Are you sure you want to delete this SSO provider?')) return;
        try {
            var res = await fetch('api/sso.php?action=delete_provider&id=' + id);
            var json = await res.json();
            if (json.success) {
                if (window.NuApp) NuApp.toast('Provider deleted');
                loadProviders();
            }
        } catch (e) {
            console.error('[SSO Admin] delete error', e);
        }
    }

    loadProviders();

    return {
        openModal: openModal,
        editProvider: editProvider,
        saveProvider: saveProvider,
        deleteProvider: deleteProvider
    };
})();
</script>
