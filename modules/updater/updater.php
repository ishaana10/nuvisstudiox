<?php
/**
 * modules/updater/updater.php
 * Admin UI: Git updates and configuration manager.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../core/module_bootstrap.php';

global $nuConfig;
$auth = NuAuth::getInstance();
$user = $auth->getCurrentUser();
$role = strtolower((string)($user['usr_role'] ?? ''));

if ($role !== 'globeadmin') {
    echo '<div style="padding:24px;color:#e11d48;font-weight:600;">Access Denied: The System Updater module is restricted to globeadmin users only.</div>';
    return;
}

if (empty($nuConfig['enableGitUpdater'])) {
    echo '<div style="padding:24px;color:#d97706;font-weight:600;">System Updater Disabled: This module is currently disabled in system configuration ($nuConfig[\'enableGitUpdater\'] = false).</div>';
    return;
}
?>

<style>
.nu-upd-wrap { display:flex; flex-direction:column; gap:20px; }
.nu-upd-tabs { display:flex; gap:6px; border-bottom:1px solid var(--border-color,#e2e8f0); padding-bottom:0; }
.nu-upd-tab { padding:10px 20px; border:none; background:none; cursor:pointer; font-size:14px; font-weight:600; color:var(--text-muted,#64748b); border-bottom:3px solid transparent; transition:.2s; border-radius:4px 4px 0 0; }
.nu-upd-tab:hover { background:rgba(0,0,0,.04); color:var(--text-main); }
.nu-upd-tab.active { color:var(--primary,#0ea5e9); border-bottom-color:var(--primary,#0ea5e9); background:rgba(14,165,233,.06); }

.nu-upd-panel { display:none; }
.nu-upd-panel.active { display:block; }

.nu-upd-stat-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:20px; }
.nu-upd-stat-card { background:var(--bg-elevated,#fff); border:1px solid var(--border-color,#e2e8f0); border-radius:10px; padding:16px; }
.nu-upd-stat-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted,#64748b); margin-bottom:4px; }
.nu-upd-stat-value { font-size:18px; font-weight:700; color:var(--text-main); }

.nu-upd-console { background:#1e1e2e; color:#c9d1d9; font-family:monospace; font-size:13px; padding:16px; border-radius:8px; overflow:auto; max-height:400px; white-space:pre-wrap; border:1px solid #313244; }
</style>

<div class="nu-upd-wrap">
    <div class="nu-module-header">
        <h2 class="nu-module-title">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:8px">
                <path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/>
            </svg>
            System Updater & Config
        </h2>
        <p style="color:var(--text-muted);font-size:13px;margin-top:4px">Manage application updates from Git and local configuration.</p>
    </div>

    <div class="nu-upd-tabs">
        <button class="nu-upd-tab active" onclick="nuUpdTab(this,'upd-dash')">Dashboard</button>
        <button class="nu-upd-tab" onclick="nuUpdTab(this,'upd-updates')">Updates</button>
        <button class="nu-upd-tab" onclick="nuUpdTab(this,'upd-history')">History</button>
        <button class="nu-upd-tab" onclick="nuUpdTab(this,'upd-config')">Configuration</button>
    </div>

    <!-- Dashboard -->
    <div class="nu-upd-panel active" id="upd-dash">
        <div class="nu-upd-stat-grid">
            <div class="nu-upd-stat-card">
                <div class="nu-upd-stat-label">Version</div>
                <div class="nu-upd-stat-value" id="upd-stat-version">...</div>
            </div>
            <div class="nu-upd-stat-card">
                <div class="nu-upd-stat-label">Branch</div>
                <div class="nu-upd-stat-value" id="upd-stat-branch">...</div>
            </div>
            <div class="nu-upd-stat-card">
                <div class="nu-upd-stat-label">Git Status</div>
                <div class="nu-upd-stat-value" id="upd-stat-git" style="font-size:14px">Checking...</div>
            </div>
        </div>

        <div class="nu-card">
            <div class="nu-card-header"><h3 class="nu-card-title">System Status</h3></div>
            <div class="nu-card-body">
                <div class="nu-upd-console" id="upd-status-console">Loading status...</div>
                <button class="nu-btn nu-btn-ghost nu-btn-sm" style="margin-top:10px" onclick="nuUpdGetStatus()">&#x21BB; Refresh Status</button>
            </div>
        </div>
    </div>

    <!-- Updates -->
    <div class="nu-upd-panel" id="upd-updates">
        <div class="nu-card">
            <div class="nu-card-header"><h3 class="nu-card-title">Git Update</h3></div>
            <div class="nu-card-body">
                <p style="margin-bottom:16px;font-size:14px">Fetch changes from the remote repository and pull them into the selected branch.</p>

                <div style="display: flex; gap: 20px; align-items: flex-end; margin-bottom: 20px;">
                    <div class="nu-field" style="width: 250px;">
                        <label style="font-size: 13px; font-weight: 500; display: block; margin-bottom: 4px;">Update Branch</label>
                        <select id="updaterBranchSelect" class="nu-input">
                            <option value="main">Loading branches...</option>
                        </select>
                    </div>
                    <button class="nu-btn nu-btn-ghost" onclick="nuUpdSaveBranchPref()">Save Preference</button>
                    <button class="nu-btn nu-btn-primary" onclick="nuUpdGitFetch()">Fetch origin</button>
                    <button class="nu-btn nu-btn-success" onclick="nuUpdGitPull()">1-Click Pull Update</button>
                </div>

                <div class="nu-upd-console" id="upd-git-console">Console output will appear here...</div>
            </div>
        </div>

        <div class="nu-card" style="margin-top: 20px;">
            <div class="nu-card-header"><h3 class="nu-card-title">Git Connection Configuration</h3></div>
            <div class="nu-card-body">
                <p style="margin-bottom:16px;font-size:14px;color:var(--text-muted)">Configure custom paths and repository locations for Git commands. This ensures updates run correctly on any server directory structure.</p>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="nu-field">
                        <label style="font-size: 13px; font-weight: 700; display: block; margin-bottom: 4px;">Git Executable Path</label>
                        <input type="text" id="git_path" class="nu-input" placeholder="e.g. git" style="font-family: monospace;">
                        <span style="font-size: 12px; color: var(--text-muted); display: block; margin-top: 6px; line-height: 1.4;">
                            <strong>Note:</strong> Enter only the command name <code>git</code> or the absolute binary path (e.g., <code>/usr/bin/git</code>). <br>
                            <span style="color: #e11d48; font-weight: 600;">DO NOT paste <code>git clone</code> or <code>gh repo clone</code> commands here.</span>
                        </span>
                    </div>
                    <div class="nu-field">
                        <label style="font-size: 13px; font-weight: 700; display: block; margin-bottom: 4px;">Git Repository Root Directory</label>
                        <input type="text" id="git_repo_dir" class="nu-input" placeholder="e.g. /home/ictfjcom/public_html/nuvis81" style="font-family: monospace;">
                        <span style="font-size: 12px; color: var(--text-muted); display: block; margin-top: 6px; line-height: 1.4;">
                            <strong>Note:</strong> Specify the full server path where the application is installed and where the <code>.git</code> folder lives.<br>
                            For example: <code>/home/ictfjcom/public_html/nuvis81</code>
                        </span>
                    </div>
                </div>

                <div style="display: flex; gap: 12px;">
                    <button class="nu-btn nu-btn-ghost" onclick="nuUpdTestGitSettings()">⚡ Test Git Connection</button>
                    <button class="nu-btn nu-btn-primary" onclick="nuUpdSaveGitSettings()">Save Connection Settings</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Initialize Repository -->
    <div class="nu-card nu-upd-panel" id="upd-init" style="margin-top: 20px; border: 2px dashed var(--primary,#0ea5e9); background: rgba(14,165,233,0.02);">
        <div class="nu-card-header">
            <h3 class="nu-card-title" style="color: var(--primary,#0ea5e9);">Initialize & Link Git Repository</h3>
        </div>
        <div class="nu-card-body">
            <p style="margin-bottom:16px;font-size:14px;line-height:1.5;">
                This directory was manually uploaded and is not currently tracked by Git. <br>
                You can initialize and link it to your remote repository in-place using this tool.
                This will download the Git configuration, link the directory, and ensure updates can be performed seamlessly going forward.
            </p>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="nu-field">
                    <label style="font-size: 13px; font-weight: 700; display: block; margin-bottom: 4px;">Git Remote Repository URL</label>
                    <input type="text" id="init_repo_url" class="nu-input" value="https://github.com/ishaana10/websitebuilder.git" placeholder="https://github.com/ishaana10/websitebuilder.git" style="font-family: monospace;">
                    <span style="font-size: 12px; color: var(--text-muted); display: block; margin-top: 6px;">
                        Specify the Git URL (SSH or HTTPS) of your remote repository (e.g. <code>https://github.com/ishaana10/websitebuilder.git</code>).
                    </span>
                </div>
                <div class="nu-field">
                    <label style="font-size: 13px; font-weight: 700; display: block; margin-bottom: 4px;">Target Update Branch</label>
                    <input type="text" id="init_branch" class="nu-input" value="main" style="font-family: monospace;">
                    <span style="font-size: 12px; color: var(--text-muted); display: block; margin-top: 6px;">
                        The default branch to sync and pull updates from (e.g., <code>main</code> or <code>master</code>).
                    </span>
                </div>
            </div>

            <div style="display: flex; gap: 12px;">
                <button class="nu-btn nu-btn-primary" onclick="nuUpdInitializeGit()">🚀 Initialize & Sync Repository</button>
            </div>
        </div>
    </div>

    <!-- History -->
    <div class="nu-upd-panel" id="upd-history">
        <div class="nu-card">
            <div class="nu-card-header"><h3 class="nu-card-title">Commit History</h3></div>
            <div class="nu-card-body">
                <div id="upd-history-list">Loading commits...</div>
            </div>
        </div>
    </div>

    <!-- Configuration -->
    <div class="nu-upd-panel" id="upd-config">
        <div class="nu-card">
            <div class="nu-card-header" style="display:flex;justify-content:space-between;align-items:center">
                <h3 class="nu-card-title">config.local.php</h3>
                <button class="nu-btn nu-btn-primary nu-btn-sm" id="upd-config-save" onclick="nuUpdConfigSave()">Save Changes</button>
            </div>
            <div class="nu-card-body">
                <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">Edit your local environment settings. A backup is created automatically before each save.</p>
                <div id="upd-config-editor" style="height:400px;border:1px solid var(--border-color,#e2e8f0);border-radius:8px"></div>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    var _api = 'api/updater.php';
    var _editor = null;

    window.nuUpdTab = function(btn, panelId) {
        document.querySelectorAll('.nu-upd-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.nu-upd-panel').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById(panelId).classList.add('active');

        if (panelId === 'upd-history') nuUpdGetHistory();
        if (panelId === 'upd-config')  nuUpdConfigRead();
    };

    window.nuUpdGetStatus = function() {
        var btn = document.querySelector('#upd-dash button[onclick="nuUpdGetStatus()"]');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '&#x21BB; Refreshing...';
        }

        fetch(_api + '?action=git_status')
            .then(r => r.json())
            .then(d => {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '&#x21BB; Refresh Status';
                }
                if (!d.success) {
                    if (document.getElementById('upd-status-console')) {
                        document.getElementById('upd-status-console').textContent = 'Error fetching status: ' + (d.error || 'Unknown error');
                    }
                    return;
                }
                if (document.getElementById('upd-stat-version')) {
                    document.getElementById('upd-stat-version').textContent = d.version || '...';
                }
                if (document.getElementById('upd-stat-branch')) {
                    document.getElementById('upd-stat-branch').textContent = d.branch || '...';
                }
                if (document.getElementById('upd-stat-git')) {
                    document.getElementById('upd-stat-git').textContent = (d.status && d.status.includes('nothing to commit')) ? 'Up to date' : 'Modified / Untracked';
                }
                if (document.getElementById('upd-status-console')) {
                    document.getElementById('upd-status-console').textContent = d.status || 'No status output returned.';
                }

                // Load connection settings values
                if (document.getElementById('git_path')) {
                    document.getElementById('git_path').value = d.git_path || 'git';
                }
                if (document.getElementById('git_repo_dir')) {
                    document.getElementById('git_repo_dir').value = d.git_repo_dir || '';
                }
                if (document.getElementById('init_repo_url') && d.git_remote_url) {
                    document.getElementById('init_repo_url').value = d.git_remote_url;
                }

                const sel = document.getElementById('updaterBranchSelect');
                if (d.remote_branches && sel) {
                    sel.innerHTML = '';
                    d.remote_branches.forEach(b => {
                        let opt = document.createElement('option');
                        opt.value = b;
                        opt.textContent = b;
                        if (b === d.selected_branch) opt.selected = true;
                        sel.appendChild(opt);
                    });
                }
            })
            .catch(err => {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '&#x21BB; Refresh Status';
                }
                if (document.getElementById('upd-status-console')) {
                    document.getElementById('upd-status-console').textContent = 'Error connecting to updater API: ' + err.message;
                }
            });
    };

    window.nuUpdSaveBranchPref = function() {
        const branch = document.getElementById('updaterBranchSelect').value;
        if (!branch) return;
        fetch(_api + '?action=save_branch', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ branch: branch })
        }).then(r => r.json()).then(res => {
            if (res.success) {
                alert('Branch preference saved.');
            } else {
                alert('Error saving branch: ' + res.error);
            }
        });
    };

    window.nuUpdSaveGitSettings = function() {
        const gitPath = document.getElementById('git_path').value;
        const gitRepoDir = document.getElementById('git_repo_dir').value;
        const branch = document.getElementById('updaterBranchSelect').value || 'main';

        fetch(_api + '?action=save_git_settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                git_path: gitPath,
                git_repo_dir: gitRepoDir,
                update_branch: branch
            })
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                alert('Git Connection Settings saved successfully.');
                nuUpdGetStatus();
            } else {
                alert('Error saving settings: ' + res.error);
            }
        });
    };

    window.nuUpdTestGitSettings = function() {
        const gitPath = document.getElementById('git_path').value;
        const gitRepoDir = document.getElementById('git_repo_dir').value;

        var consoleOutput = document.getElementById('upd-git-console');
        consoleOutput.textContent = 'Testing connection settings...\n';

        fetch(_api + '?action=test_git_settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                git_path: gitPath,
                git_repo_dir: gitRepoDir
            })
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                alert('Success!\n\n' + res.message);
                consoleOutput.textContent += 'SUCCESS:\n' + res.message;
                document.getElementById('upd-init').classList.remove('active');
                nuUpdGetStatus(); // refresh branch listings
            } else {
                if (res.git_missing) {
                    alert('Connection Test Failed:\n\n' + res.error + '\n\nWe have revealed the "Initialize & Link Git Repository" section below to help you set it up.');
                    document.getElementById('upd-init').classList.add('active');
                    document.getElementById('upd-init').scrollIntoView({ behavior: 'smooth' });
                } else {
                    alert('Connection Test Failed:\n\n' + res.error);
                }
                consoleOutput.textContent += 'FAILED:\n' + res.error;
            }
        })
        .catch(err => {
            alert('Error during test: ' + err.message);
            consoleOutput.textContent += 'ERROR:\n' + err.message;
        });
    };

    window.nuUpdInitializeGit = function() {
        const gitPath = document.getElementById('git_path').value;
        const gitRepoDir = document.getElementById('git_repo_dir').value;
        const repoUrl = document.getElementById('init_repo_url').value;
        const branch = document.getElementById('init_branch').value;

        if (!repoUrl) {
            alert('Please enter your Git Remote Repository URL.');
            return;
        }

        // Ensure remote repository URL is sanitized (remove command prefixes like sh: git@... or git clone ...)
        let cleanRepoUrl = repoUrl.trim();
        if (cleanRepoUrl.startsWith('sh:')) {
            cleanRepoUrl = cleanRepoUrl.substring(3).trim();
        }
        if (cleanRepoUrl.includes('git@github.com') && cleanRepoUrl.includes('sh:')) {
            cleanRepoUrl = cleanRepoUrl.replace('sh:', '').trim();
        }
        // If they accidentally left a branch checkout or other command in there
        cleanRepoUrl = cleanRepoUrl.split(' ')[0];

        if (!confirm('This will initialize a Git repository in ' + gitRepoDir + ', set the origin remote, and sync with ' + cleanRepoUrl + '. Any untracked local files of same names will be aligned with the remote. Do you want to proceed?')) {
            return;
        }

        var consoleOutput = document.getElementById('upd-git-console');
        consoleOutput.textContent = 'Initializing Git Repository...\n';

        fetch(_api + '?action=git_init', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                git_path: gitPath,
                git_repo_dir: gitRepoDir,
                repo_url: cleanRepoUrl,
                branch: branch
            })
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                alert('Git Repository initialized and synchronized successfully!');
                consoleOutput.textContent += 'SUCCESS:\n' + res.output;
                document.getElementById('upd-init').classList.remove('active');
                nuUpdGetStatus();
            } else {
                alert('Git Initialization Failed:\n\n' + res.error);
                consoleOutput.textContent += 'FAILED:\n' + res.error;
            }
        })
        .catch(err => {
            alert('Error during initialization: ' + err.message);
            consoleOutput.textContent += 'ERROR:\n' + err.message;
        });
    };

    window.nuUpdGitFetch = function() {
        var console = document.getElementById('upd-git-console');
        console.textContent = 'Fetching changes from origin...';
        fetch(_api + '?action=git_fetch')
            .then(r => r.json())
            .then(d => {
                console.textContent = d.output || 'No output from fetch.';
                nuUpdGetStatus();
            });
    };

    window.nuUpdGitPull = function() {
        if (!confirm('This will pull the latest changes from the selected branch. Continue?')) return;
        var console = document.getElementById('upd-git-console');
        console.textContent = 'Pulling from selected branch...';
        fetch(_api + '?action=git_pull')
            .then(r => r.json())
            .then(d => {
                console.textContent = d.output || 'No output from pull.';
                if (d.pulled_branch) {
                    console.textContent += '\n\nUpdate finished successfully on branch: ' + d.pulled_branch;
                }
                nuUpdGetStatus();
            });
    };

    window.nuUpdGetHistory = function() {
        var list = document.getElementById('upd-history-list');
        list.textContent = 'Loading...';
        fetch(_api + '?action=git_log')
            .then(r => r.json())
            .then(d => {
                if (!d.success) { list.textContent = d.error; return; }
                var html = '<table style="width:100%;border-collapse:collapse;font-size:13px"><thead><tr style="text-align:left;border-bottom:2px solid var(--border-color,#e2e8f0)">';
                html += '<th style="padding:10px">Hash</th><th style="padding:10px">Author</th><th style="padding:10px">Date</th><th style="padding:10px">Message</th></tr></thead><tbody>';
                d.commits.forEach(c => {
                    html += '<tr style="border-bottom:1px solid var(--border-color,#e2e8f0)">';
                    html += '<td style="padding:10px"><code style="background:var(--bg-subtle,#f8fafc);padding:2px 6px;border-radius:4px">'+c.hash+'</code></td>';
                    html += '<td style="padding:10px">'+c.author+'</td>';
                    html += '<td style="padding:10px;white-space:nowrap">'+c.date+'</td>';
                    html += '<td style="padding:10px">'+c.msg+'</td></tr>';
                });
                html += '</tbody></table>';
                list.innerHTML = html;
            });
    };

    window.nuUpdConfigRead = function() {
        fetch(_api + '?action=config_read')
            .then(r => r.json())
            .then(d => {
                if (!d.success) return;
                if (!_editor && window.ace) {
                    _editor = ace.edit('upd-config-editor');
                    _editor.setTheme('ace/theme/one_dark');
                    _editor.session.setMode('ace/mode/php');
                    _editor.setOptions({ fontSize: '13px' });
                }
                if (_editor) {
                    _editor.setValue(d.content, -1);
                } else {
                    document.getElementById('upd-config-editor').innerHTML = '<textarea id="upd-config-ta" style="width:100%;height:100%;border:none;padding:12px;font-family:monospace">'+d.content+'</textarea>';
                }
            });
    };

    window.nuUpdConfigSave = function() {
        var content = _editor ? _editor.getValue() : document.getElementById('upd-config-ta').value;
        if (!confirm('Save changes to config.local.php?')) return;

        var btn = document.getElementById('upd-config-save');
        btn.disabled = true;
        btn.textContent = 'Saving...';

        fetch(_api + '?action=config_write', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ content: content })
        })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            btn.textContent = 'Save Changes';
            if (d.success) {
                alert('Configuration saved successfully.');
            } else {
                alert('Error: ' + d.error);
            }
        });
    };

    nuUpdGetStatus();
})();
</script>
