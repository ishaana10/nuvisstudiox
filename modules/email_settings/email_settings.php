<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

// Only administrators can manage email settings
$currentUser = $auth->getCurrentUser();
$userRole = strtolower($currentUser['usr_role'] ?? '');
$isAdmin = ($userRole === 'globeadmin' || $userRole === 'admin');

if (!$isAdmin) {
    echo '<div style="padding:24px;border:2px solid var(--color-error);background:var(--color-error-highlight);border-radius:8px;"><h3>Access Denied</h3><p>Only administrators can manage email settings.</p></div>';
    exit;
}
?>

<div class="nu-email-settings-module">

  <!-- ── PAGE HEADER ────────────────────────────────────────────────── -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
    <div>
      <h2 style="font-size:20px;font-weight:600;margin:0;">Email Settings</h2>
      <p style="color:var(--text-secondary);font-size:13px;margin:2px 0 0;">Configure SMTP connections, manage email templates, and inspect the send log.</p>
    </div>
  </div>

  <div id="esAlertArea"></div>

  <!-- ── TABS BAR ───────────────────────────────────────────────────── -->
  <div class="es-tab-bar">
    <button class="es-tab-btn active" onclick="esSwitchTab('smtp', this)">SMTP Configuration</button>
    <button class="es-tab-btn" onclick="esSwitchTab('templates', this)">Email Templates</button>
    <button class="es-tab-btn" onclick="esSwitchTab('logs', this)">SMTP Send Log</button>
  </div>

  <!-- ── TAB 1: SMTP CONFIG ─────────────────────────────────────────── -->
  <div class="es-tab-panel active" id="es-tab-smtp">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;">

      <!-- LEFT: Setup Form -->
      <div class="nu-card" style="padding:20px;">
        <h3 style="font-size:15px;font-weight:600;margin:0 0 16px;border-bottom:1px solid var(--color-divider);padding-bottom:10px;">SMTP Connection Properties</h3>

        <div class="nu-field" style="margin-bottom:12px;">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
            <input type="checkbox" id="esUseSmtp" onchange="esToggleSmtpFields()" style="width:16px;height:16px;">
            Enable SMTP Email Driver
          </label>
          <span style="font-size:12px;color:var(--color-text-faint);display:block;margin-top:2px;">When disabled, the system will fall back to using default PHP mail().</span>
        </div>

        <div id="esSmtpFieldsContainer">
          <div class="nu-field" style="margin-bottom:12px;">
            <label>Provider preset</label>
            <select class="nu-input" id="esPreset" onchange="esApplyPreset()">
              <option value="">Choose a preset…</option>
              <option value="a2">A2 Hosting (mail.yourdomain.com, SSL 465)</option>
              <option value="a2tls">A2 Hosting (mail.yourdomain.com, STARTTLS 587)</option>
              <option value="gmail">Gmail / Google Workspace (app password)</option>
              <option value="o365">Microsoft 365</option>
              <option value="sendgrid">SendGrid</option>
              <option value="mailgun">Mailgun</option>
            </select>
            <span style="font-size:12px;color:var(--color-text-faint);display:block;margin-top:2px;">On A2 Hosting create a mailbox in cPanel &gt; Email Accounts, then use its full address and password here.</span>
          </div>
          <div class="nu-field" style="margin-bottom:12px;">
            <label>SMTP Hostname</label>
            <input type="text" class="nu-input" id="esSmtpHost" placeholder="e.g. mail.yourdomain.com">
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
            <div class="nu-field" style="margin:0;">
              <label>SMTP Port</label>
              <input type="number" class="nu-input" id="esSmtpPort" placeholder="587">
            </div>
            <div class="nu-field" style="margin:0;">
              <label>Encryption Mode</label>
              <select class="nu-input" id="esSmtpSecure">
                <option value="tls">TLS (STARTTLS)</option>
                <option value="ssl">SSL</option>
                <option value="">None / Plain</option>
              </select>
            </div>
          </div>

          <div class="nu-field" style="margin-bottom:12px;">
            <label>SMTP Username</label>
            <input type="text" class="nu-input" id="esSmtpUsername" placeholder="user@yourdomain.com">
          </div>

          <div class="nu-field" style="margin-bottom:16px;">
            <label>SMTP Password</label>
            <input type="password" class="nu-input" id="esSmtpPassword" placeholder="••••••••" autocomplete="new-password">
            <span id="esPwHint" style="font-size:12px;color:var(--color-text-faint);display:block;margin-top:2px;"></span>
          </div>
          <details style="margin-bottom:16px;">
            <summary style="cursor:pointer;font-size:13px;font-weight:600;">Advanced</summary>
            <div class="nu-field" style="margin:10px 0;"><label>Reply-To address (optional)</label><input type="email" class="nu-input" id="esReplyTo"></div>
            <div class="nu-field" style="margin:10px 0;"><label>EHLO name (optional)</label><input type="text" class="nu-input" id="esEhlo" placeholder="defaults to your From domain"></div>
            <label style="display:flex;gap:8px;align-items:center;font-size:13px;"><input type="checkbox" id="esVerifyPeer" checked> Verify the server's TLS certificate (turn off only if the host name does not match the certificate)</label>
          </details>
        </div>

        <button class="nu-btn nu-btn-primary" onclick="esSaveSmtpSettings()" style="width:100%;">
          Save Email Configuration
        </button>
      </div>

      <!-- RIGHT: Test & Identity Form -->
      <div style="display:flex;flex-direction:column;gap:20px;">
        <div class="nu-card" style="padding:20px;">
          <h3 style="font-size:15px;font-weight:600;margin:0 0 16px;border-bottom:1px solid var(--color-divider);padding-bottom:10px;">Default Sender Identity</h3>

          <div class="nu-field" style="margin-bottom:12px;">
            <label>Default From Name</label>
            <input type="text" class="nu-input" id="esFromName" placeholder="nuvis Notification">
          </div>

          <div class="nu-field" style="margin-bottom:16px;">
            <label>Default From Email Address</label>
            <input type="email" class="nu-input" id="esFromEmail" placeholder="noreply@yourdomain.com">
          </div>
        </div>

        <div class="nu-card" style="padding:20px;background:var(--color-surface-offset);">
          <h3 style="font-size:15px;font-weight:600;margin:0 0 10px;">Verify Configuration</h3>
          <p style="font-size:13px;color:var(--color-text-muted);margin:0 0 14px;line-height:1.5;">Send a test email to any recipient address to verify that your SMTP or PHP mail() settings connect and transmit successfully.</p>

          <div class="nu-field" style="margin-bottom:12px;">
            <label>Recipient Email Address</label>
            <input type="email" class="nu-input" id="esTestEmailTo" placeholder="you@example.com" style="background:#fff;">
          </div>

          <button class="nu-btn nu-btn-ghost" id="esTestBtn" onclick="esSendTestEmail()" style="width:100%;">
            Send Test Message
          </button>
          <pre id="esTestResult" style="display:none;margin-top:10px;font-size:12px;white-space:pre-wrap;word-break:break-word;background:var(--color-surface);padding:10px;border-radius:8px;max-height:200px;overflow:auto;"></pre>
        </div>

        <div class="nu-card" style="padding:20px;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
            <h3 style="font-size:15px;font-weight:600;margin:0;">Deliverability check</h3>
            <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="esCheckDeliverability()">Run check</button>
          </div>
          <p style="font-size:12px;color:var(--color-text-muted);margin:0 0 10px;line-height:1.5;">Gmail and Yahoo reject mail without SPF/DKIM that matches the From domain. This checks your sending domain's DNS.</p>
          <div id="esDeliv" style="font-size:13px;"></div>
        </div>
      </div>

    </div>
  </div>

  <!-- ── TAB 2: TEMPLATES ───────────────────────────────────────────── -->
  <div class="es-tab-panel" id="es-tab-templates" style="display:none;">
    <div class="nu-card" style="padding:20px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;border-bottom:1px solid var(--color-divider);padding-bottom:10px;">
        <h3 style="font-size:15px;font-weight:600;margin:0;">Configured Email Templates</h3>
        <button class="nu-btn nu-btn-primary nu-btn-sm" onclick="esOpenTemplateModal(null)">+ Create Template</button>
      </div>

      <div style="overflow-x:auto;">
        <table class="es-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Slug</th>
              <th>Subject</th>
              <th>Status</th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody id="esTemplatesBody">
            <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--color-text-faint);">Loading templates…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ── TAB 3: SEND LOG ────────────────────────────────────────────── -->
  <div class="es-tab-panel" id="es-tab-logs" style="display:none;">
    <div class="nu-card" style="padding:20px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;border-bottom:1px solid var(--color-divider);padding-bottom:10px;">
        <h3 style="font-size:15px;font-weight:600;margin:0;">Email Sent &amp; Error Log</h3>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
          <input type="search" class="nu-input" id="esLogQ" placeholder="Search recipient, subject, error…" style="width:220px;" onkeydown="if(event.key==='Enter')esLoadLogs()">
          <select class="nu-input" id="esLogStatusFilter" onchange="esLoadLogs()" style="width:130px;"><option value="">All</option><option value="SENT">Sent</option><option value="FAIL">Failed</option><option value="SKIPPED">Skipped</option></select>
          <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="esLoadLogs()">Refresh</button>
          <a class="nu-btn nu-btn-ghost nu-btn-sm" id="esLogCsv" href="#" onclick="this.href=esLogCsvUrl()">CSV</a>
          <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="esClearLogs()">Clear</button>
        </div>
      </div>
      <div id="esLogStats" style="display:flex;gap:12px;margin-bottom:12px;font-size:13px;"></div>

      <div style="overflow-x:auto;">
        <table class="es-table">
          <thead>
            <tr>
              <th>Time</th>
              <th>Source</th>
              <th>Recipient</th>
              <th>Subject</th>
              <th>Status</th>
              <th>Details / Error</th>
            </tr>
          </thead>
          <tbody id="esLogsBody">
            <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--color-text-faint);">Loading logs…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ── TEMPLATE EDIT MODAL ────────────────────────────────────────── -->
  <div id="esTemplateModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:24px;width:95%;max-width:600px;box-shadow:var(--shadow-lg);">
      <h3 id="esModalTemplateTitle" style="margin:0 0 16px;font-size:16px;font-weight:600;border-bottom:1px solid var(--color-divider);padding-bottom:10px;">Create Template</h3>
      <input type="hidden" id="esTplId">

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
        <div class="nu-field" style="margin:0;">
          <label>Template Name</label>
          <input type="text" class="nu-input" id="esTplName" placeholder="Welcome Email">
        </div>
        <div class="nu-field" style="margin:0;">
          <label>Unique Key / Slug</label>
          <input type="text" class="nu-input" id="esTplSlug" placeholder="welcome_email">
        </div>
      </div>

      <div class="nu-field" style="margin-bottom:12px;">
        <label>Subject Line <span style="font-weight:normal;color:var(--color-text-faint);">(supports {{placeholders}})</span></label>
        <input type="text" class="nu-input" id="esTplSubject" placeholder="Hello {{user_name}}!">
      </div>

      <div class="nu-field" style="margin-bottom:12px;">
        <label>Internal Description</label>
        <input type="text" class="nu-input" id="esTplDesc" placeholder="Optional description of use-case">
      </div>

      <div class="nu-field" style="margin-bottom:16px;">
        <label>HTML / Plain Body <span style="font-weight:normal;color:var(--color-text-faint);">(supports {{placeholders}})</span></label>
        <textarea class="nu-input" id="esTplBody" rows="8" style="font-family:monospace;font-size:13px;resize:vertical;" placeholder="<p>Hi {{user_name}}!</p>"></textarea>
      </div>

      <div class="nu-field" style="margin-bottom:20px;">
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:normal;">
          <input type="checkbox" id="esTplActive" checked style="width:16px;height:16px;">
          Active and available for sending
        </label>
      </div>

      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button class="nu-btn nu-btn-ghost" onclick="esCloseTemplateModal()">Cancel</button>
        <button class="nu-btn nu-btn-primary" onclick="esSaveTemplate()">Save Template</button>
      </div>
    </div>
  </div>

  <!-- ── LOG DETAILS MODAL ────────────────────────────────────────── -->
  <div id="esLogModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:24px;width:95%;max-width:600px;box-shadow:var(--shadow-lg);display:flex;flex-direction:column;gap:16px;">
      <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--color-divider);padding-bottom:10px;">
        <h3 style="margin:0;font-size:16px;font-weight:600;">Email Log Details</h3>
        <button class="modal-close" onclick="esCloseLogModal()" style="font-size:20px;cursor:pointer;background:none;border:none;color:var(--color-text-muted);">&times;</button>
      </div>

      <div style="display:grid;grid-template-columns:120px 1fr;gap:12px;font-size:14px;line-height:1.5;">
        <div style="font-weight:600;color:var(--color-text-muted);">Sent At:</div>
        <div id="esLogSentAt"></div>

        <div style="font-weight:600;color:var(--color-text-muted);">Recipient:</div>
        <div id="esLogRecipient" style="word-break:break-all;"></div>

        <div style="font-weight:600;color:var(--color-text-muted);">Subject:</div>
        <div id="esLogSubject" style="font-weight:600;"></div>

        <div style="font-weight:600;color:var(--color-text-muted);">Status:</div>
        <div id="esLogStatus"></div>

        <div style="font-weight:600;color:var(--color-text-muted);">Source:</div>
        <div id="esLogSource"></div>

        <div style="font-weight:600;color:var(--color-text-muted);">Driver:</div>
        <div id="esLogDriver"></div>

        <div style="font-weight:600;color:var(--color-text-muted);">Template:</div>
        <div id="esLogTemplate"></div>

        <div style="font-weight:600;color:var(--color-text-muted);">Server log:</div>
        <pre id="esLogDetail" style="margin:0;font-size:12px;white-space:pre-wrap;word-break:break-word;max-height:180px;overflow:auto;"></pre>

        <div style="font-weight:600;color:var(--color-text-muted);">Error Message:</div>
        <div id="esLogErrorMessage" style="color:var(--color-error);word-break:break-word;"></div>
      </div>

      <div style="display:flex;justify-content:flex-end;margin-top:10px;">
        <button class="nu-btn nu-btn-ghost" onclick="esCloseLogModal()">Close</button>
      </div>
    </div>
  </div>

</div>

<style>
.nu-email-settings-module {
  font-family:var(--font-body,sans-serif);
}
.es-tab-bar {
  display:flex;
  gap:6px;
  background:var(--color-surface-offset);
  padding:4px;
  border-radius:var(--radius-lg);
  width:fit-content;
  margin-bottom:20px;
}
.es-tab-btn {
  padding:6px 16px;
  border-radius:var(--radius-md);
  font-size:13px;
  font-weight:500;
  color:var(--color-text-muted);
  cursor:pointer;
  transition:all 150ms;
}
.es-tab-btn.active {
  background:var(--color-surface);
  color:var(--color-text);
  box-shadow:var(--shadow-sm);
}
.es-tab-btn:hover:not(.active) {
  color:var(--color-text);
}
.es-table {
  width:100%;
  border-collapse:collapse;
  font-size:13px;
}
.es-table th {
  background:var(--color-bg);
  padding:8px 12px;
  text-align:left;
  font-weight:600;
  color:var(--color-text-muted);
  text-transform:uppercase;
  font-size:11px;
  letter-spacing:0.04em;
  border-bottom:1px solid var(--color-border);
}
.es-table td {
  padding:10px 12px;
  border-bottom:1px solid var(--color-divider);
  vertical-align:middle;
}
.es-table tr:last-child td {
  border-bottom:none;
}
.es-table tr:hover td {
  background:var(--color-surface-offset);
}
.es-status-dot {
  width:8px;
  height:8px;
  border-radius:50%;
  display:inline-block;
  margin-right:6px;
}
.es-status-sent { background:#10b981; }
.es-status-fail { background:#ef4444; }
</style>

<script>
(function () {

  // Guard: tear down previous instance before attaching new one
  if (window.ES && typeof window.ES._destroy === 'function') {
    window.ES._destroy();
  }

  // ── toast helper ────────────────────────────────────────────────────
  function toast(msg, type) {
    type = type || 'info';
    if (window.NuApp && typeof NuApp.toast === 'function') { NuApp.toast(msg, type); return; }
    if (typeof nuToast === 'function') { nuToast(msg, type); return; }
    console.log('[ES]', type, msg);
  }

  var API = 'api/email.php';
  var _templates = [];

  // Defer run until SPA layout is ready
  setTimeout(function () {
    esLoadSmtpSettings();
  }, 0);

  // ── Tab switching ───────────────────────────────────────────────────
  window.esSwitchTab = function (tabId, btn) {
    document.querySelectorAll('.es-tab-btn').forEach(function (b) { b.classList.remove('active'); });
    btn.classList.add('active');

    document.querySelectorAll('.es-tab-panel').forEach(function (panel) { panel.style.display = 'none'; });
    document.getElementById('es-tab-' + tabId).style.display = '';

    if (tabId === 'templates') esLoadTemplates();
    if (tabId === 'logs') esLoadLogs();
  };

  // ── SMTP settings controls ──────────────────────────────────────────
  window.esToggleSmtpFields = function () {
    var on = document.getElementById('esUseSmtp').checked;
    var container = document.getElementById('esSmtpFieldsContainer');
    container.style.opacity = on ? '1' : '0.4';
    container.style.pointerEvents = on ? 'auto' : 'none';
  };

  window.esLoadSmtpSettings = async function () {
    try {
      var res  = await fetch(API + '?action=get_settings', { credentials: 'same-origin' });
      var data = await res.json();
      if (!data.success) return;
      var s = data.data;

      document.getElementById('esUseSmtp').checked    = (s.driver === 'smtp');
      document.getElementById('esSmtpHost').value     = s.smtp_host     || '';
      document.getElementById('esSmtpPort').value     = s.smtp_port     || '587';
      document.getElementById('esSmtpSecure').value   = s.smtp_secure   || 'tls';
      document.getElementById('esSmtpUsername').value = s.smtp_username || '';
      document.getElementById('esSmtpPassword').value = '';
      document.getElementById('esPwHint').textContent = s.smtp_password_set === '1' ? 'A password is saved. Leave blank to keep it.' : '';
      document.getElementById('esReplyTo').value      = s.reply_to || '';
      document.getElementById('esEhlo').value         = s.smtp_ehlo || '';
      document.getElementById('esVerifyPeer').checked = (s.smtp_verify_peer !== '0');
      document.getElementById('esFromName').value     = s.from_name     || '';
      document.getElementById('esFromEmail').value    = s.from_email    || '';

      esToggleSmtpFields();
    } catch(e) { console.warn('[ES] settings load failed:', e); }
  };

  window.esSaveSmtpSettings = async function () {
    var driver = document.getElementById('esUseSmtp').checked ? 'smtp' : 'mail';
    var settings = [
      { key: 'driver',        value: driver },
      { key: 'smtp_host',     value: document.getElementById('esSmtpHost').value },
      { key: 'smtp_port',     value: document.getElementById('esSmtpPort').value || '587' },
      { key: 'smtp_secure',   value: document.getElementById('esSmtpSecure').value },
      { key: 'smtp_username', value: document.getElementById('esSmtpUsername').value },
      { key: 'smtp_password', value: document.getElementById('esSmtpPassword').value },
      { key: 'from_name',     value: document.getElementById('esFromName').value },
      { key: 'from_email',    value: document.getElementById('esFromEmail').value },
      { key: 'reply_to',      value: document.getElementById('esReplyTo').value },
      { key: 'smtp_ehlo',     value: document.getElementById('esEhlo').value },
      { key: 'smtp_verify_peer', value: document.getElementById('esVerifyPeer').checked ? '1' : '0' },
    ];

    try {
      var res = await fetch(API, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'save_settings', settings: settings })
      });
      var data = await res.json();
      if (data.success) {
        toast('SMTP Settings saved successfully', 'success'); document.getElementById('esSmtpPassword').value = ''; esLoadSmtpSettings();
      } else {
        toast('Save failed: ' + data.message, 'error');
      }
    } catch(e) {
      toast('Network error: ' + e.message, 'error');
    }
  };

  // ── Send test email ─────────────────────────────────────────────────
  window.esSendTestEmail = async function () {
    var to = document.getElementById('esTestEmailTo').value.trim();
    if (!to) { toast('Please enter a recipient email address', 'warning'); return; }

    var btn = document.getElementById('esTestBtn');
    btn.textContent = 'Sending test…';
    btn.disabled = true;

    try {
      var res = await fetch(API, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'test', to: to })
      });
      var data = await res.json();
      var pre = document.getElementById('esTestResult');
      pre.style.display = 'block';
      pre.textContent = (data.success ? 'OK: ' : 'FAILED: ') + (data.message || '') + (data.detail ? '\n\n' + data.detail : '');
      if (data.success) {
        toast('Test email accepted for ' + to + '. Check the inbox and spam folder.', 'success');
      } else {
        toast('Send failed: ' + data.message, 'error');
      }
    } catch(e) {
      toast('Network error: ' + e.message, 'error');
    }

    btn.textContent = 'Send Test Message';
    btn.disabled = false;
  };

  // ── Manage templates ────────────────────────────────────────────────
  window.esLoadTemplates = async function () {
    try {
      var res = await fetch(API + '?action=templates', { credentials: 'same-origin' });
      var j   = await res.json();
      _templates = j.success ? j.data : [];
      esRenderTemplatesTable();
    } catch(e) {
      document.getElementById('esTemplatesBody').innerHTML = '<tr><td colspan="6" style="color:var(--color-error)">Failed to load: ' + e.message + '</td></tr>';
    }
  };

  function esRenderTemplatesTable() {
    var tbody = document.getElementById('esTemplatesBody');
    if (!_templates.length) {
      tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--color-text-faint);">No email templates found. Click <strong>+ Create Template</strong> to add one.</td></tr>';
      return;
    }

    tbody.innerHTML = _templates.map(function (t) {
      return '<tr>' +
        '<td style="font-weight:600;">' + esc(t.name) + '</td>' +
        '<td><code style="background:var(--color-surface-offset);padding:2px 6px;border-radius:var(--radius-sm);font-family:monospace;font-size:12px;color:var(--color-primary);">' + esc(t.slug) + '</code></td>' +
        '<td style="color:var(--color-text-muted);">' + esc(t.subject) + '</td>' +
        '<td>' + (t.is_active == 1 ? '<span class="nu-status nu-status-active">Active</span>' : '<span class="nu-status">Inactive</span>') + '</td>' +
        '<td style="text-align:right;"><div style="display:inline-flex;gap:4px;">' +
          '<button class="nu-btn nu-btn-ghost nu-btn-sm" onclick=\'esEditTemplate(' + JSON.stringify(t).replace(/'/g, "&apos;") + ')\'>Edit</button>' +
          '<button class="nu-btn nu-btn-ghost nu-btn-sm" style="color:var(--color-error);" onclick="esDeleteTemplate(' + t.id + ')">Delete</button>' +
        '</div></td>' +
      '</tr>';
    }).join('');
  }

  window.esOpenTemplateModal = function (t) {
    if (t) {
      document.getElementById('esModalTemplateTitle').textContent = 'Edit Template';
      document.getElementById('esTplId').value          = t.id;
      document.getElementById('esTplName').value        = t.name;
      document.getElementById('esTplSlug').value        = t.slug;
      document.getElementById('esTplSubject').value     = t.subject;
      document.getElementById('esTplDesc').value        = t.description || '';
      document.getElementById('esTplBody').value        = t.body;
      document.getElementById('esTplActive').checked    = t.is_active == 1;
    } else {
      document.getElementById('esModalTemplateTitle').textContent = 'Create Template';
      document.getElementById('esTplId').value          = '';
      document.getElementById('esTplName').value        = '';
      document.getElementById('esTplSlug').value        = '';
      document.getElementById('esTplSubject').value     = '';
      document.getElementById('esTplDesc').value        = '';
      document.getElementById('esTplBody').value        = '';
      document.getElementById('esTplActive').checked    = true;
    }
    document.getElementById('esTemplateModal').style.display = 'flex';
  };

  window.esEditTemplate = function (t) {
    esOpenTemplateModal(t);
  };

  window.esCloseTemplateModal = function () {
    document.getElementById('esTemplateModal').style.display = 'none';
  };

  window.esSaveTemplate = async function () {
    var id     = document.getElementById('esTplId').value || 0;
    var name   = document.getElementById('esTplName').value.trim();
    var slug   = document.getElementById('esTplSlug').value.trim();
    var sub    = document.getElementById('esTplSubject').value.trim();
    var desc   = document.getElementById('esTplDesc').value.trim();
    var body   = document.getElementById('esTplBody').value.trim();
    var active = document.getElementById('esTplActive').checked ? 1 : 0;

    if (!name || !slug || !sub || !body) {
      toast('Name, Slug, Subject, and Body are all required', 'warning');
      return;
    }

    try {
      var res = await fetch(API, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'save_template',
          id: id, name: name, slug: slug, subject: sub, description: desc, body: body, is_active: active
        })
      });
      var data = await res.json();
      if (data.success) {
        esCloseTemplateModal();
        toast('Template saved successfully', 'success');
        esLoadTemplates();
      } else {
        toast('Save template failed: ' + data.message, 'error');
      }
    } catch (e) {
      toast('Network error: ' + e.message, 'error');
    }
  };

  window.esDeleteTemplate = async function (id) {
    if (!confirm('Are you sure you want to delete this template? This cannot be undone.')) return;
    try {
      var res = await fetch(API, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete_template', id: id })
      });
      var data = await res.json();
      if (data.success) {
        toast('Template deleted', 'success');
        esLoadTemplates();
      } else {
        toast('Delete failed: ' + data.message, 'error');
      }
    } catch(e) {
      toast('Network error: ' + e.message, 'error');
    }
  };

  // ── SMTP send log ───────────────────────────────────────────────────
  function esLogQuery() {
    var q = encodeURIComponent((document.getElementById('esLogQ') || {}).value || '');
    var st = encodeURIComponent((document.getElementById('esLogStatusFilter') || {}).value || '');
    return '&q=' + q + '&status=' + st;
  }
  window.esLogCsvUrl = function () { return API + '?action=logs_csv' + esLogQuery(); };
  window.esClearLogs = async function () {
    if (!confirm('Delete all email log entries?')) return;
    await fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'clear_logs' }) });
    esLoadLogs();
  };
  function esBadge(st) {
    var m = { SENT: ['#d4dfcc', '#437a22', 'Sent'], FAIL: ['#f3d6d6', '#a12c2c', 'Failed'], SKIPPED: ['#f1e6c8', '#8a6a12', 'Skipped'] }[st] || ['#e5e7eb', '#475569', st];
    return '<span class="badge" style="background:' + m[0] + ';color:' + m[1] + ';">' + m[2] + '</span>';
  }
  var esLogRows = [];
  window.esLoadLogs = async function () {
    var tbody = document.getElementById('esLogsBody');
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--color-text-faint);">Loading logs…</td></tr>';
    try {
      var res = await fetch(API + '?action=logs&limit=100' + esLogQuery(), { credentials: 'same-origin' });
      var data = await res.json();
      var stats = {}; ((data && data.stats) || []).forEach(function (x) { stats[x.status] = x.c; });
      document.getElementById('esLogStats').innerHTML = ['SENT', 'FAIL', 'SKIPPED'].map(function (k) { return esBadge(k) + ' ' + (stats[k] || 0); }).join(' &nbsp; ');
      if (!data.success || !data.data.length) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--color-text-faint);">' + esc(data.message || 'No email log entries found.') + '</td></tr>';
        return;
      }
      esLogRows = data.data;
      tbody.innerHTML = data.data.map(function (row, i) {
        return '<tr data-i="' + i + '" style="cursor:pointer;">' +
          '<td style="color:var(--color-text-muted);font-size:11px;white-space:nowrap;">' + esc(row.sent_at) + '</td>' +
          '<td style="font-size:11px;">' + esc(row.source || '') + '</td>' +
          '<td>' + esc(row.recipient) + '</td>' +
          '<td style="font-weight:500;">' + esc(row.subject) + '</td>' +
          '<td>' + esBadge(row.status) + '</td>' +
          '<td style="color:var(--color-error);font-size:11px;">' + esc(row.error_message || '') + '</td>' +
        '</tr>';
      }).join('');
      tbody.querySelectorAll('tr[data-i]').forEach(function (tr) { tr.addEventListener('click', function () { esShowLogDetails(esLogRows[+tr.dataset.i]); }); });
    } catch(e) {
      tbody.innerHTML = '<tr><td colspan="6" style="color:var(--color-error)">Failed to load logs: ' + esc(e.message) + '</td></tr>';
    }
  };

  window.esShowLogDetails = function (row) {
    document.getElementById('esLogSentAt').textContent = row.sent_at || '';
    document.getElementById('esLogRecipient').textContent = row.recipient || '';
    document.getElementById('esLogSubject').textContent = row.subject || '';
    document.getElementById('esLogStatus').innerHTML = esBadge(row.status);
    document.getElementById('esLogSource').textContent = row.source || '—';
    document.getElementById('esLogDriver').textContent = (row.driver || '—') + (row.duration_ms ? ' · ' + row.duration_ms + ' ms' : '');
    document.getElementById('esLogTemplate').textContent = row.template_slug || 'None';
    document.getElementById('esLogDetail').textContent = row.detail || '—';
    document.getElementById('esLogErrorMessage').textContent = row.error_message || 'None';
    document.getElementById('esLogModal').style.display = 'flex';
  };

  // ── presets, deliverability ─────────────────────────────────────────
  window.esApplyPreset = function () {
    var v = document.getElementById('esPreset').value;
    var host = (document.getElementById('esFromEmail').value.split('@')[1] || 'yourdomain.com');
    var P = {
      a2: ['mail.' + host, 465, 'ssl'], a2tls: ['mail.' + host, 587, 'tls'], gmail: ['smtp.gmail.com', 587, 'tls'],
      o365: ['smtp.office365.com', 587, 'tls'], sendgrid: ['smtp.sendgrid.net', 587, 'tls'], mailgun: ['smtp.mailgun.org', 587, 'tls']
    }[v];
    if (!P) return;
    document.getElementById('esUseSmtp').checked = true;
    document.getElementById('esSmtpHost').value = P[0]; document.getElementById('esSmtpPort').value = P[1]; document.getElementById('esSmtpSecure').value = P[2];
    if (v === 'sendgrid') document.getElementById('esSmtpUsername').value = 'apikey';
    esToggleSmtpFields();
  };
  window.esCheckDeliverability = async function () {
    var box = document.getElementById('esDeliv');
    box.textContent = 'Checking…';
    try {
      var d = await (await fetch(API + '?action=deliverability', { credentials: 'same-origin' })).json();
      if (!d.success) { box.textContent = d.message || 'Check failed'; return; }
      var col = { ok: '#437a22', warn: '#8a6a12', error: '#a12c2c' }, ico = { ok: '✓', warn: '!', error: '✕' };
      box.innerHTML = d.checks.map(function (c) {
        return '<div style="margin-bottom:8px;"><b style="color:' + col[c.level] + '">' + ico[c.level] + ' ' + esc(c.title) + '</b><div style="color:var(--color-text-muted);font-size:12px;word-break:break-word;">' + esc(c.msg) + '</div></div>';
      }).join('');
    } catch (e) { box.textContent = 'Check failed: ' + e.message; }
  };

  window.esCloseLogModal = function () {
    document.getElementById('esLogModal').style.display = 'none';
  };

  // Close modal on backdrop click
  setTimeout(function () {
    var modal = document.getElementById('esLogModal');
    if (modal) {
      modal.addEventListener('click', function (e) {
        if (e.target === this) {
          esCloseLogModal();
        }
      });
    }
  }, 100);

  // ── utility escaper ─────────────────────────────────────────────────
  function esc(s) {
    return String(s !== null && s !== undefined ? s : '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  // Teardown / destroy guard
  window.ES = {
    _destroy: function () {
      window.ES = null;
    }
  };

})();
</script>
