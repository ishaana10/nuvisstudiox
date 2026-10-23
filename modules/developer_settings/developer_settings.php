<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

// Restricted to globeadmin only
$currentUser = $auth->getCurrentUser();
if (!$currentUser || strtolower((string)$currentUser['usr_role']) !== 'globeadmin') {
    echo '<div style="padding:24px;border:2px solid var(--color-error);background:var(--color-error-highlight);border-radius:8px;"><h3>Access Denied</h3><p>Only globeadmin developers can manage developer settings.</p></div>';
    exit;
}
?>

<style>
.nu-dev-settings-module {
    padding: 24px;
    background: var(--bg-card, #ffffff);
    border-radius: var(--radius-lg, 12px);
    border: 1px solid var(--border-color, #e2e8f0);
    box-shadow: var(--shadow-sm, 0 1px 3px rgba(0,0,0,0.08));
}
.nu-dev-tabs {
    display: flex;
    gap: 8px;
    border-bottom: 2px solid var(--border-color, #e2e8f0);
    margin-bottom: 20px;
    padding-bottom: 2px;
}
.nu-dev-tab-btn {
    padding: 8px 16px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-secondary, #475569);
    background: transparent;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 6px;
    border-radius: 6px 6px 0 0;
}
.nu-dev-tab-btn:hover {
    color: var(--primary, #0ea5e9);
    background: var(--bg-hover, rgba(0,0,0,0.04));
}
.nu-dev-tab-btn.active {
    color: var(--primary, #0ea5e9);
    border-bottom-color: var(--primary, #0ea5e9);
    background: var(--bg-subtle, #f8fafc);
}
.nu-dev-panel {
    display: none;
}
.nu-dev-panel.active {
    display: block;
}
.nu-section-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary, #0f172a);
    margin: 0 0 12px 0;
}
.nu-grid-2 {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 16px;
}
.nu-field-desc {
    font-size: 11px;
    color: var(--text-muted, #94a3b8);
    margin-top: 4px;
    display: block;
}

/* Redis Status Styles matching image spec */
.nu-redis-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 12px;
}
.nu-redis-title-group h3 {
    font-size: 14px;
    font-weight: 700;
    color: #1e3a8a; /* Deep blue */
    text-transform: uppercase;
    letter-spacing: 0.03em;
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
}
.nu-redis-title-group p {
    font-size: 12px;
    color: var(--text-muted, #64748b);
    margin: 3px 0 0 0;
}
.nu-redis-btn-group {
    display: flex;
    gap: 10px;
}
.nu-btn-benchmark {
    background: #1d4ed8;
    color: #ffffff;
    font-weight: 600;
    border-radius: 8px;
    padding: 8px 16px;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.1);
}
.nu-btn-benchmark:hover {
    background: #1e40af;
}
.nu-btn-refresh {
    background: #f1f5f9;
    color: #334155;
    font-weight: 600;
    border-radius: 8px;
    padding: 8px 16px;
    border: 1px solid #cbd5e1;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
}
.nu-btn-refresh:hover {
    background: #e2e8f0;
}

.nu-redis-cards-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 20px;
}
@media (max-width: 1024px) {
    .nu-redis-cards-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 640px) {
    .nu-redis-cards-grid { grid-template-columns: 1fr; }
}

.nu-redis-kpi-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}
[data-theme="dark"] .nu-redis-kpi-card {
    background: #1e293b;
    border-color: #334155;
}

.nu-redis-kpi-label {
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-bottom: 6px;
}
.nu-redis-kpi-val {
    font-size: 20px;
    font-weight: 800;
    color: #0f172a;
}
[data-theme="dark"] .nu-redis-kpi-val {
    color: #f8fafc;
}
.nu-redis-kpi-sub {
    font-size: 11px;
    color: #94a3b8;
    font-family: monospace;
    margin-top: 4px;
}

.nu-redis-daemon-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}
[data-theme="dark"] .nu-redis-daemon-card {
    background: #1e293b;
    border-color: #334155;
}

.nu-redis-daemon-info {
    display: flex;
    align-items: center;
    gap: 10px;
}
.nu-redis-daemon-title {
    font-size: 13px;
    font-weight: 700;
    color: #0f172a;
}
[data-theme="dark"] .nu-redis-daemon-title {
    color: #f8fafc;
}
.nu-redis-daemon-path {
    font-size: 11px;
    color: #64748b;
    margin-top: 2px;
}
.nu-redis-daemon-path code {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    padding: 2px 6px;
    border-radius: 4px;
    font-family: monospace;
    color: #0f172a;
}
[data-theme="dark"] .nu-redis-daemon-path code {
    background: #0f172a;
    border-color: #334155;
    color: #f8fafc;
}

.nu-btn-daemon {
    background: #c2410c;
    color: #ffffff;
    font-weight: 700;
    font-size: 12px;
    padding: 8px 16px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.nu-btn-daemon:hover {
    background: #9a3412;
}

.nu-btn-flush {
    background: #dc2626;
    color: #ffffff;
    font-weight: 700;
    font-size: 12px;
    padding: 8px 16px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.nu-btn-flush:hover {
    background: #b91c1c;
}

.nu-redis-table-title {
    font-size: 12px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-bottom: 12px;
}
.nu-redis-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}
.nu-redis-table th {
    background: #f8fafc;
    color: #64748b;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-weight: 700;
    padding: 10px 12px;
    text-align: left;
    border-bottom: 1px solid #e2e8f0;
}
[data-theme="dark"] .nu-redis-table th {
    background: #1e293b;
    border-color: #334155;
    color: #94a3b8;
}
.nu-redis-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #e2e8f0;
    font-family: monospace;
}
[data-theme="dark"] .nu-redis-table td {
    border-color: #334155;
}
</style>

<div class="nu-card nu-dev-settings-module">
    <div class="nu-card-header" style="margin-bottom: 16px;">
        <h3 class="nu-card-title">Developer &amp; System Settings</h3>
        <p style="color:var(--text-muted); font-size:13px; margin:4px 0 0 0;">Manage core branding, dynamic global hashes, and high-performance Redis cache diagnostics.</p>
    </div>

    <!-- Navigation Tabs -->
    <div class="nu-dev-tabs">
        <button type="button" class="nu-dev-tab-btn active" id="tabBtnGeneral" onclick="DevSettings.switchTab('general')">
            ⚙️ General &amp; Branding
        </button>
        <button type="button" class="nu-dev-tab-btn" id="tabBtnRedis" onclick="DevSettings.switchTab('redis')">
            ⚡ Redis Cache &amp; Sessions
        </button>
        <button type="button" class="nu-dev-tab-btn" id="tabBtnFnRef" onclick="DevSettings.switchTab('fnref')">
            📚 Function Reference
        </button>
    </div>

    <!-- Panel 1: General Branding & Global Hashes -->
    <div class="nu-dev-panel active" id="panelGeneral">
        <!-- Application Brand Section -->
        <div class="nu-dev-section">
            <h4 class="nu-section-title">Application Branding</h4>
            <div class="nu-grid-2">
                <div class="nu-field">
                    <label for="devAppName" class="nu-label">Application Name</label>
                    <input id="devAppName" type="text" class="nu-input" placeholder="e.g. My Custom Low-Code App">
                    <span class="nu-field-desc">Used in browser tabs, navigation title, and login cards.</span>
                </div>

                <div class="nu-field">
                    <label for="devUserHeaderFormat" class="nu-label">User Header Format</label>
                    <input id="devUserHeaderFormat" type="text" class="nu-input" placeholder="e.g. {name} | {location}">
                    <span class="nu-field-desc">Custom format for top-right user profile text. Placeholders: {name}, {username}, {role}, {location}.</span>
                </div>

                <div class="nu-field" style="display: flex; align-items: center; margin-top: 10px;">
                    <input id="devForgotPasswordEnabled" type="checkbox" style="width: 18px; height: 18px; margin-right: 8px; cursor: pointer;">
                    <div>
                        <label for="devForgotPasswordEnabled" class="nu-label" style="display: inline-block; margin-bottom: 0; cursor: pointer; font-weight: 500;">Enable Forgot Password module</label>
                        <span class="nu-field-desc" style="margin-top: 2px;">When enabled, a "Forgot Password?" link is visible on the login screen.</span>
                    </div>
                </div>

                <div class="nu-field">
                    <label for="devMfaEnforcement" class="nu-label">MFA Enforcement Policy</label>
                    <select id="devMfaEnforcement" class="nu-input">
                        <option value="optional">Optional (User-driven enrollment)</option>
                        <option value="required_for_admins">Required for Administrators</option>
                        <option value="required">Required for All Users</option>
                    </select>
                    <span class="nu-field-desc">Forces target users to complete TOTP MFA setup on next login.</span>
                </div>

                <div class="nu-field">
                    <label class="nu-label">Application Logo</label>
                    <div class="logo-preview-container" style="display:flex; align-items:center; gap:16px; margin-bottom:8px;">
                        <div id="devLogoPreview" style="width:64px; height:64px; border:1px solid var(--border-color); border-radius:var(--radius-lg); display:flex; align-items:center; justify-content:center; overflow:hidden; background:var(--bg-elevated);">
                            <span style="color:var(--text-muted); font-size:12px;">No Logo</span>
                        </div>
                        <div style="display:flex; flex-direction:column; gap:6px;">
                            <input id="devLogoUrl" type="text" class="nu-input nu-input-sm" style="width:240px;" placeholder="Logo URL or relative path" readonly>
                            <div style="display:flex; gap:8px;">
                                <label class="nu-btn nu-btn-ghost nu-btn-sm" style="cursor:pointer; margin:0;">
                                    ⬆ Upload Logo File
                                    <input type="file" id="devLogoFile" style="display:none;" accept="image/*">
                                </label>
                                <button id="devLogoRemoveBtn" class="nu-btn nu-btn-danger nu-btn-sm" style="display:none;" onclick="DevSettings.removeLogo()">Remove Logo</button>
                            </div>
                        </div>
                    </div>
                    <span class="nu-field-desc">Recommended: Transparent PNG or SVG under 500kb. Replaces default logo icon.</span>
                </div>
            </div>
        </div>

        <!-- Dynamic Settings / Global Hashes Section -->
        <div class="nu-dev-section" style="margin-top: 24px; border-top: 1px solid var(--border-color); padding-top: 24px;">
            <h4 class="nu-section-title">System Settings &amp; Global Hashes</h4>
            <p style="color:var(--text-muted); font-size:12px; margin-bottom:12px; line-height:1.4;">
                Define dynamic key-value properties. Checking the <strong>Global Hash</strong> checkbox makes the key available globally in SQL queries as <code>##fieldname##</code> (similar to user fields configurations).
            </p>

            <div style="overflow-x:auto;">
                <table class="nu-table" style="width:100%; border-collapse:collapse;" id="devHashesTable">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border-color); background:var(--table-head-bg);">
                            <th style="padding:10px; text-align:left; font-size:12px; font-weight:600;">Field Label</th>
                            <th style="padding:10px; text-align:left; font-size:12px; font-weight:600;">Field Key (Unique Name)</th>
                            <th style="padding:10px; text-align:left; font-size:12px; font-weight:600;">Value</th>
                            <th style="padding:10px; text-align:center; font-size:12px; font-weight:600; width:120px;">Global Hash?</th>
                            <th style="padding:10px; text-align:center; font-size:12px; font-weight:600; width:80px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="devHashesBody">
                        <!-- Dynamic rows injected here -->
                    </tbody>
                </table>
            </div>

            <button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" style="margin-top:12px;" onclick="DevSettings.addRow()">
                ➕ Add Custom Field
            </button>
        </div>

        <!-- Save Controls -->
        <div style="margin-top:24px; padding-top:16px; border-top:1px solid var(--border-color); display:flex; justify-content:flex-end; gap:12px;">
            <button type="button" class="nu-btn nu-btn-ghost" onclick="DevSettings.loadSettings()">Discard Changes</button>
            <button type="button" class="nu-btn nu-btn-primary" onclick="DevSettings.saveSettings()">💾 Save Settings</button>
        </div>
    </div>

    <!-- Panel 2: Redis Cache & Session Management Status -->
    <div class="nu-dev-panel" id="panelRedis">
        <div class="nu-redis-header">
            <div class="nu-redis-title-group">
                <h3>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="color:#1d4ed8;">
                        <rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/>
                        <line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/>
                    </svg>
                    Redis High-Performance Cache &amp; Session Management Status
                </h3>
                <p>Live memory diagnostics, latency benchmark, active sessions, and key metrics</p>
            </div>
            <div class="nu-redis-btn-group">
                <button type="button" class="nu-btn-refresh" onclick="DevSettings.fetchRedisDiagnostics()">
                    🔄 Refresh Metrics
                </button>
                <button type="button" class="nu-btn-benchmark" onclick="DevSettings.testRedisPing()">
                    ⚡ Test Latency (Benchmark)
                </button>
            </div>
        </div>

        <!-- Benchmark Result Container -->
        <div id="redisBenchmarkBox" style="display:none; padding:12px 16px; margin-bottom:16px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; font-size:12px; font-family:monospace; color:#1e40af;">
            <span id="redisBenchmarkText">Running latency benchmark...</span>
        </div>

        <!-- 4 KPI Cards Grid -->
        <div class="nu-redis-cards-grid">
            <!-- Card 1 -->
            <div class="nu-redis-kpi-card">
                <div>
                    <div class="nu-redis-kpi-label">Connection Status</div>
                    <div class="nu-redis-kpi-val" id="redisCardStatus" style="display:flex; align-items:center; gap:8px; font-size:15px; color:#15803d;">
                        <span style="width:10px; height:10px; border-radius:50%; background:#22c55e; display:inline-block;"></span>
                        <span>Connected &amp; Operational</span>
                    </div>
                </div>
                <div class="nu-redis-kpi-sub" id="redisCardScheme">Scheme: unix</div>
            </div>

            <!-- Card 2 -->
            <div class="nu-redis-kpi-card">
                <div>
                    <div class="nu-redis-kpi-label">Redis Memory &amp; Engine</div>
                    <div class="nu-redis-kpi-val" id="redisCardMemory">--</div>
                </div>
                <div class="nu-redis-kpi-sub" id="redisCardVersion">Version: --</div>
            </div>

            <!-- Card 3 -->
            <div class="nu-redis-kpi-card">
                <div>
                    <div class="nu-redis-kpi-label">Cached App Objects</div>
                    <div class="nu-redis-kpi-val" id="redisCardKeys" style="color:#1d4ed8;">0</div>
                </div>
                <div class="nu-redis-kpi-sub">Prefix: nuvis:*</div>
            </div>

            <!-- Card 4 -->
            <div class="nu-redis-kpi-card">
                <div>
                    <div class="nu-redis-kpi-label">Redis Active Sessions</div>
                    <div class="nu-redis-kpi-val" id="redisCardSessions" style="color:#15803d;">0</div>
                </div>
                <div class="nu-redis-kpi-sub">Prefix: nuvis:sess:*</div>
            </div>
        </div>

        <!-- A2 Hosting Daemon Card -->
        <div class="nu-redis-daemon-card">
            <div class="nu-redis-daemon-info">
                <span style="font-size:20px;">🏠</span>
                <div>
                    <div class="nu-redis-daemon-title">A2 Hosting Turbo Max Redis Daemon Initialization</div>
                    <div class="nu-redis-daemon-path">
                        Socket Path: <code id="redisSocketPathDisplay">/home/ictfjcom/.redis/redis.sock</code>
                    </div>
                </div>
            </div>
            <div style="display:flex; gap:10px;">
                <button type="button" class="nu-btn-daemon" onclick="DevSettings.triggerStartRedisDaemon()">
                    ⏻ Start A2 Hosting Redis Daemon (~/.redis.on)
                </button>
                <button type="button" class="nu-btn-flush" onclick="DevSettings.flushRedisCache()">
                    🧹 Flush Nuvis Cache
                </button>
            </div>
        </div>

        <!-- Sample Cached Keys Table -->
        <div>
            <div class="nu-redis-table-title">Sample Cached Keys &amp; Time To Live (TTL)</div>
            <div style="overflow-x:auto; border:1px solid var(--border-color); border-radius:10px;">
                <table class="nu-redis-table">
                    <thead>
                        <tr>
                            <th>Cache Key Name</th>
                            <th>Full Redis Key</th>
                            <th style="text-align:right;">TTL (Time to Live)</th>
                        </tr>
                    </thead>
                    <tbody id="redisKeysTableBody">
                        <tr>
                            <td colspan="3" style="text-align:center; padding:20px; color:var(--text-muted); font-style:italic; font-family:sans-serif;">Loading cached keys...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Panel 3: Developer Function Reference -->
    <div class="nu-dev-panel" id="panelFnRef">
        <div style="margin-bottom:16px; display:flex; justify-content:space-between; align-items:center;">
            <div>
                <h4 class="nu-section-title" style="margin:0;">Interactive Function Reference</h4>
                <p style="font-size:12px; color:var(--text-muted); margin:4px 0 0 0;">Searchable directory of backend PHP helpers, client JS APIs, and SQL placeholders for developer scripts.</p>
            </div>
            <input type="search" id="fnRefSearchInput" placeholder="Filter functions..." class="nu-input" style="width:220px;" oninput="DevSettings.filterFnRef(this.value)">
        </div>
        <div id="fnRefContainer">
            <p style="color:var(--text-muted); font-style:italic;">Loading function reference...</p>
        </div>
    </div>
</div>

<script>
var DevSettings = (function() {
    var API = 'api/developer_settings.php';
    var REDIS_API = 'api/redis_actions.php';
    var rows = [];

    var fnRefData = null;

    function switchTab(tab) {
        var btnGeneral = document.getElementById('tabBtnGeneral');
        var btnRedis = document.getElementById('tabBtnRedis');
        var btnFnRef = document.getElementById('tabBtnFnRef');
        var panelGeneral = document.getElementById('panelGeneral');
        var panelRedis = document.getElementById('panelRedis');
        var panelFnRef = document.getElementById('panelFnRef');

        btnGeneral.classList.remove('active');
        btnRedis.classList.remove('active');
        if (btnFnRef) btnFnRef.classList.remove('active');
        panelGeneral.classList.remove('active');
        panelRedis.classList.remove('active');
        if (panelFnRef) panelFnRef.classList.remove('active');

        if (tab === 'redis') {
            btnRedis.classList.add('active');
            panelRedis.classList.add('active');
            fetchRedisDiagnostics();
        } else if (tab === 'fnref') {
            if (btnFnRef) btnFnRef.classList.add('active');
            if (panelFnRef) panelFnRef.classList.add('active');
            fetchFnRef();
        } else {
            btnGeneral.classList.add('active');
            panelGeneral.classList.add('active');
        }
    }

    async function fetchFnRef() {
        if (fnRefData) return;
        try {
            var res = await fetch(API + '?action=get_fn_ref', { credentials: 'same-origin' });
            var json = await res.json();
            if (json.success) {
                fnRefData = json.fn_ref;
                renderFnRef('');
            }
        } catch (e) {
            console.error('[DevSettings] failed to fetch fn ref', e);
        }
    }

    function renderFnRef(filter) {
        var container = document.getElementById('fnRefContainer');
        if (!container || !fnRefData) return;
        var q = (filter || '').toLowerCase();
        var html = '';

        Object.keys(fnRefData).forEach(function(catKey) {
            var cat = fnRefData[catKey];
            var items = cat.items.filter(function(item) {
                return !q || item.name.toLowerCase().includes(q) || item.description.toLowerCase().includes(q) || item.category.toLowerCase().includes(q);
            });

            if (items.length > 0) {
                html += '<div style="margin-bottom:24px;">' +
                    '<h5 style="font-size:14px; font-weight:700; color:var(--primary, #0ea5e9); border-bottom:1px solid var(--border-color); padding-bottom:6px; margin-bottom:12px;">' + escapeHtml(cat.title) + '</h5>' +
                    '<p style="font-size:12px; color:var(--text-muted); margin-bottom:12px;">' + escapeHtml(cat.description) + '</p>' +
                    '<div style="display:grid; grid-template-columns:1fr; gap:12px;">';

                items.forEach(function(it) {
                    html += '<div style="background:var(--bg-subtle, #f8fafc); border:1px solid var(--border-color, #e2e8f0); border-radius:8px; padding:12px;">' +
                        '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">' +
                            '<strong style="font-family:monospace; font-size:13px; color:var(--text-primary);">' + escapeHtml(it.name) + '</strong>' +
                            '<span style="font-size:10px; font-weight:700; padding:2px 8px; border-radius:12px; background:#e0f2fe; color:#0369a1;">' + escapeHtml(it.category) + '</span>' +
                        '</div>' +
                        '<div style="font-size:11px; font-family:monospace; color:#64748b; margin-bottom:6px;">' + escapeHtml(it.signature) + '</div>' +
                        '<div style="font-size:12px; color:var(--text-secondary); margin-bottom:8px;">' + escapeHtml(it.description) + '</div>' +
                        '<pre style="margin:0; padding:8px; background:#0f172a; color:#f8fafc; border-radius:4px; font-size:11px; overflow-x:auto;">' + escapeHtml(it.example) + '</pre>' +
                    '</div>';
                });

                html += '</div></div>';
            }
        });

        container.innerHTML = html || '<p style="color:var(--text-muted); font-style:italic;">No matching functions found.</p>';
    }

    function filterFnRef(val) {
        renderFnRef(val);
    }

    async function loadSettings() {
        try {
            var res = await fetch(API + '?action=get', { credentials: 'same-origin' });
            var json = await res.json();
            if (!json.success) {
                if (window.NuApp) NuApp.toast(json.error || 'Failed to load settings', 'error');
                return;
            }

            var settings = json.settings || {};
            document.getElementById('devAppName').value = settings.app_name || '';
            if (document.getElementById('devUserHeaderFormat')) {
                document.getElementById('devUserHeaderFormat').value = settings.user_header_format || '';
            }
            document.getElementById('devLogoUrl').value = settings.app_logo || '';
            if (document.getElementById('devForgotPasswordEnabled')) {
                document.getElementById('devForgotPasswordEnabled').checked = (settings.forgot_password_enabled === '1');
            }
            if (document.getElementById('devMfaEnforcement')) {
                document.getElementById('devMfaEnforcement').value = settings.mfa_enforcement || 'optional';
            }
            updateLogoPreview(settings.app_logo || '');

            rows = settings.system_fields_def || [];
            renderRows();
        } catch (e) {
            console.error('[DevSettings] failed to load', e);
            if (window.NuApp) NuApp.toast('Error connecting to backend API', 'error');
        }
    }

    function fetchRedisDiagnostics() {
        var statusCard = document.getElementById('redisCardStatus');
        if (!statusCard) return;

        fetch(REDIS_API + '?action=get_redis_status', { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success) {
                var socketDisplay = document.getElementById('redisSocketPathDisplay');
                if (socketDisplay) socketDisplay.textContent = d.socket_path || '/home/ictfjcom/.redis/redis.sock';

                var schemeEl = document.getElementById('redisCardScheme');
                if (schemeEl) schemeEl.textContent = 'Scheme: ' + (d.scheme || 'unix');

                if (d.is_connected) {
                    statusCard.style.color = '#15803d';
                    statusCard.innerHTML = '<span style="width:10px; height:10px; border-radius:50%; background:#22c55e; display:inline-block;"></span><span>Connected &amp; Operational</span>';
                } else if (d.socket_exists) {
                    statusCard.style.color = '#b45309';
                    statusCard.innerHTML = '<span>Socket Present (Config Disabled)</span> <button type="button" onclick="DevSettings.toggleRedisConfig(true)" style="padding:2px 8px; font-size:11px; background:#16a34a; color:#fff; border:none; border-radius:4px; cursor:pointer;">Enable Redis</button>';
                } else {
                    statusCard.style.color = '#c2410c';
                    statusCard.innerHTML = '<span>Socket Offline (~/.redis.on required)</span>';
                }

                document.getElementById('redisCardMemory').textContent = d.used_memory || '--';
                document.getElementById('redisCardVersion').textContent = 'Version: ' + (d.redis_version || '--');
                document.getElementById('redisCardKeys').textContent = d.cached_keys_count || 0;
                document.getElementById('redisCardSessions').textContent = d.active_sessions_count || 0;

                var tbody = document.getElementById('redisKeysTableBody');
                if (tbody) {
                    if (d.keys_sample && d.keys_sample.length > 0) {
                        tbody.innerHTML = d.keys_sample.map(function(k) {
                            return '<tr>' +
                                '<td style="font-weight:700; color:#1d4ed8;">' + escapeHtml(k.key) + '</td>' +
                                '<td style="color:var(--text-muted);">' + escapeHtml(k.full_key) + '</td>' +
                                '<td style="text-align:right; font-weight:700; color:#15803d;">' + escapeHtml(String(k.ttl)) + '</td>' +
                            '</tr>';
                        }).join('');
                    } else {
                        tbody.innerHTML = '<tr><td colspan="3" style="text-align:center; padding:20px; color:var(--text-muted); font-style:italic; font-family:sans-serif;">No active keys currently cached under nuvis:* prefix.</td></tr>';
                    }
                }
            }
        })
        .catch(function(err) {
            statusCard.innerHTML = '<span style="color:#dc2626;">Diagnostics Error</span>';
        });
    }

    function testRedisPing() {
        var box = document.getElementById('redisBenchmarkBox');
        var txt = document.getElementById('redisBenchmarkText');
        if (box) box.style.display = 'block';
        if (txt) txt.textContent = 'Running latency benchmark...';

        fetch(REDIS_API + '?action=test_redis_ping', { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success) {
                txt.style.color = '#15803d';
                txt.textContent = '✔ ' + d.message;
                fetchRedisDiagnostics();
            } else {
                txt.style.color = '#dc2626';
                txt.textContent = '❌ Benchmark Failed: ' + (d.error || 'Connection error');
            }
        })
        .catch(function(err) {
            txt.style.color = '#dc2626';
            txt.textContent = '❌ Error: ' + err;
        });
    }

    function triggerStartRedisDaemon() {
        fetch(REDIS_API + '?action=start_redis_daemon', { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (window.NuApp) NuApp.toast(d.message || 'Daemon startup signaled (~/.redis.on)');
            else alert(d.message || 'Daemon startup signaled');
            fetchRedisDiagnostics();
        });
    }

    function flushRedisCache() {
        if (!confirm('Are you sure you want to flush all Nuvis cached objects in Redis?')) return;

        fetch(REDIS_API + '?action=flush_cache', { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (window.NuApp) NuApp.toast(d.message || 'Cache flushed');
            else alert(d.message || 'Cache flushed');
            fetchRedisDiagnostics();
        });
    }

    function toggleRedisConfig(enable) {
        fetch(REDIS_API + '?action=toggle_redis_config', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ enable: enable })
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (window.NuApp) NuApp.toast(d.message || 'Config updated');
            fetchRedisDiagnostics();
        });
    }

    function updateLogoPreview(path) {
        var preview = document.getElementById('devLogoPreview');
        var removeBtn = document.getElementById('devLogoRemoveBtn');
        if (path && path.trim() !== '') {
            preview.innerHTML = '<img src="' + path + '" style="max-width:100%; max-height:100%; object-fit:contain;">';
            if (removeBtn) removeBtn.style.display = 'block';
        } else {
            preview.innerHTML = '<span style="color:var(--text-muted); font-size:12px;">No Logo</span>';
            if (removeBtn) removeBtn.style.display = 'none';
        }
    }

    function removeLogo() {
        document.getElementById('devLogoUrl').value = '';
        updateLogoPreview('');
        if (window.NuApp) NuApp.toast('Logo reference removed from draft (click save to persist)');
    }

    function addRow(label, key, val, isGlobal) {
        rows.push({
            label: label || '',
            key: key || '',
            value: val || '',
            global: isGlobal !== undefined ? !!isGlobal : true
        });
        renderRows();
    }

    function removeRow(index) {
        rows.splice(index, 1);
        renderRows();
    }

    function updateRowProp(index, prop, value) {
        if (rows[index]) {
            rows[index][prop] = value;
        }
    }

    function renderRows() {
        var tbody = document.getElementById('devHashesBody');
        tbody.innerHTML = '';

        if (rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="padding:20px; text-align:center; color:var(--text-muted); font-style:italic;">No custom dynamic settings or hashes configured yet.</td></tr>';
            return;
        }

        rows.forEach(function(r, idx) {
            var tr = document.createElement('tr');
            tr.style.cssText = 'border-bottom:1px solid var(--border-color);';

            // Label
            var tdLabel = document.createElement('td');
            tdLabel.style.padding = '8px 10px';
            var inputLabel = document.createElement('input');
            inputLabel.type = 'text';
            inputLabel.className = 'nu-input nu-input-sm';
            inputLabel.value = r.label || '';
            inputLabel.placeholder = 'e.g. Standard Tax Rate';
            inputLabel.addEventListener('input', function() {
                updateRowProp(idx, 'label', this.value);
            });
            tdLabel.appendChild(inputLabel);
            tr.appendChild(tdLabel);

            // Key
            var tdKey = document.createElement('td');
            tdKey.style.padding = '8px 10px';
            var inputKey = document.createElement('input');
            inputKey.type = 'text';
            inputKey.className = 'nu-input nu-input-sm';
            inputKey.value = r.key || '';
            inputKey.placeholder = 'e.g. tax_rate';
            inputKey.addEventListener('input', function() {
                var sanitized = this.value.toLowerCase().replace(/[^a-z0-9_]/g, '_');
                this.value = sanitized;
                updateRowProp(idx, 'key', sanitized);
            });
            tdKey.appendChild(inputKey);
            tr.appendChild(tdKey);

            // Value
            var tdValue = document.createElement('td');
            tdValue.style.padding = '8px 10px';
            var inputValue = document.createElement('input');
            inputValue.type = 'text';
            inputValue.className = 'nu-input nu-input-sm';
            inputValue.value = r.value || '';
            inputValue.placeholder = 'e.g. 0.15';
            inputValue.addEventListener('input', function() {
                updateRowProp(idx, 'value', this.value);
            });
            tdValue.appendChild(inputValue);
            tr.appendChild(tdValue);

            // Global Hash checkbox
            var tdGlobal = document.createElement('td');
            tdGlobal.style.cssText = 'padding:8px 10px; text-align:center;';
            var checkGlobal = document.createElement('input');
            checkGlobal.type = 'checkbox';
            checkGlobal.checked = r.global !== false;
            checkGlobal.addEventListener('change', function() {
                updateRowProp(idx, 'global', this.checked);
            });
            tdGlobal.appendChild(checkGlobal);
            tr.appendChild(tdGlobal);

            // Actions
            var tdActions = document.createElement('td');
            tdActions.style.cssText = 'padding:8px 10px; text-align:center;';
            var delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.className = 'nu-btn nu-btn-danger nu-btn-sm';
            delBtn.textContent = '✕';
            delBtn.style.padding = '2px 6px';
            delBtn.addEventListener('click', function() {
                removeRow(idx);
            });
            tdActions.appendChild(delBtn);
            tr.appendChild(tdActions);

            tbody.appendChild(tr);
        });
    }

    async function saveSettings() {
        var keys = {};
        for (var i = 0; i < rows.length; i++) {
            var k = (rows[i].key || '').trim();
            if (k === '') {
                if (window.NuApp) NuApp.toast('All dynamic fields must have a valid Field Key', 'error');
                return;
            }
            if (keys[k]) {
                if (window.NuApp) NuApp.toast('Duplicate Field Key: "' + k + '" is not allowed', 'error');
                return;
            }
            keys[k] = true;
        }

        var payload = {
            app_name: document.getElementById('devAppName').value,
            user_header_format: document.getElementById('devUserHeaderFormat')?.value || '',
            app_logo: document.getElementById('devLogoUrl').value,
            forgot_password_enabled: document.getElementById('devForgotPasswordEnabled')?.checked ? '1' : '0',
            mfa_enforcement: document.getElementById('devMfaEnforcement')?.value || 'optional',
            system_fields_def: rows
        };

        try {
            var res = await fetch(API + '?action=save', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            var json = await res.json();
            if (json.success) {
                if (window.NuApp) {
                    NuApp.toast('Settings saved successfully! Page will reload to apply branding.');
                }
                setTimeout(function() {
                    window.location.reload();
                }, 1000);
            } else {
                if (window.NuApp) NuApp.toast(json.error || 'Failed to save settings', 'error');
            }
        } catch (e) {
            console.error('[DevSettings] failed to save', e);
            if (window.NuApp) NuApp.toast('Error saving settings to server', 'error');
        }
    }

    function bindFileUpload() {
        var fileInput = document.getElementById('devLogoFile');
        if (!fileInput) return;

        fileInput.addEventListener('change', async function() {
            if (!fileInput.files || fileInput.files.length === 0) return;
            var file = fileInput.files[0];

            var formData = new FormData();
            formData.append('logo_file', file);

            if (window.NuApp) NuApp.toast('Uploading logo...');

            try {
                var res = await fetch(API + '?action=upload_logo', {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                });
                var json = await res.json();
                if (json.success) {
                    document.getElementById('devLogoUrl').value = json.logo_url;
                    updateLogoPreview(json.logo_url);
                    if (window.NuApp) NuApp.toast('Logo uploaded! (Draft updated, save settings to make it active)');
                } else {
                    if (window.NuApp) NuApp.toast(json.error || 'Failed to upload logo', 'error');
                }
            } catch (e) {
                console.error('[DevSettings] upload logo failed', e);
                if (window.NuApp) NuApp.toast('Logo upload failed', 'error');
            }

            fileInput.value = '';
        });
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function init() {
        loadSettings();
        bindFileUpload();
    }

    return {
        init: init,
        switchTab: switchTab,
        addRow: addRow,
        removeLogo: removeLogo,
        loadSettings: loadSettings,
        saveSettings: saveSettings,
        fetchRedisDiagnostics: fetchRedisDiagnostics,
        testRedisPing: testRedisPing,
        triggerStartRedisDaemon: triggerStartRedisDaemon,
        flushRedisCache: flushRedisCache,
        toggleRedisConfig: toggleRedisConfig,
        filterFnRef: filterFnRef
    };
})();

DevSettings.init();
</script>
