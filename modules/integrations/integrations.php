<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

$db       = NuDatabase::getInstance();

// ── Run schema updates if missing ──
try {
    $whCols = $db->fetchAll("SHOW COLUMNS FROM `nu_webhooks`");
    $whNames = array_column($whCols, 'Field');
    if (!in_array('webhook_method', $whNames, true)) {
        $db->exec("ALTER TABLE `nu_webhooks` ADD COLUMN `webhook_method` VARCHAR(10) NOT NULL DEFAULT 'POST' AFTER `webhook_url`");
    }
    if (!in_array('webhook_headers', $whNames, true)) {
        $db->exec("ALTER TABLE `nu_webhooks` ADD COLUMN `webhook_headers` TEXT DEFAULT NULL AFTER `webhook_method`");
    }
    if (!in_array('webhook_payload_template', $whNames, true)) {
        $db->exec("ALTER TABLE `nu_webhooks` ADD COLUMN `webhook_payload_template` TEXT DEFAULT NULL AFTER `webhook_headers`");
    }
} catch (Throwable $e) {}

// Fetch Webhooks & Keys
$webhooks = $db->fetchAll("SELECT * FROM nu_webhooks ORDER BY webhook_created_at DESC");
$apiKeys  = $db->fetchAll("SELECT * FROM nu_api_tokens ORDER BY token_created_at DESC LIMIT 50");
?>

<div class="nu-integrations-container" style="padding: 12px 0;">

    <!-- Tab Navigation -->
    <div class="nu-tabs" style="display: flex; gap: 12px; margin-bottom: 20px; border-bottom: 2px solid var(--border-color, #e2e8f0); padding-bottom: 8px;">
        <button class="nu-tab-btn active" onclick="switchIntegrationsTab('webhooksTab', this)" style="background: none; border: none; font-size: 15px; font-weight: 600; padding: 6px 16px; cursor: pointer; color: var(--accent, #4f6bed); border-bottom: 2px solid var(--accent, #4f6bed); margin-bottom: -10px; transition: all 0.2s;">
            Outgoing Webhooks
        </button>
        <button class="nu-tab-btn" onclick="switchIntegrationsTab('apiKeysTab', this)" style="background: none; border: none; font-size: 15px; font-weight: 500; padding: 6px 16px; cursor: pointer; color: var(--text-muted, #718096); transition: all 0.2s;">
            API Authorization Keys
        </button>
        <button class="nu-tab-btn" onclick="switchIntegrationsTab('demoConsoleTab', this); fetchDemoLogs();" style="background: none; border: none; font-size: 15px; font-weight: 500; padding: 6px 16px; cursor: pointer; color: var(--text-muted, #718096); transition: all 0.2s;">
            Live Webhook Inspector (Demo)
        </button>
    </div>

    <!-- 1. Webhooks Tab -->
    <div id="webhooksTab" class="nu-integrations-tab">
        <div class="nu-card">
            <div class="nu-card-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h3 class="nu-card-title" style="margin: 0; font-size: 18px; font-weight: 600;">Configured Webhooks</h3>
                <button class="nu-btn nu-btn-primary" onclick="openWebhookModal()">+ New Webhook</button>
            </div>
            <div class="nu-table-wrap" style="overflow-x: auto;">
                <table class="nu-table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border-color); text-align: left;">
                            <th style="padding: 10px 12px;">Webhook Name</th>
                            <th style="padding: 10px 12px;">Destination Details</th>
                            <th style="padding: 10px 12px;">Active Events</th>
                            <th style="padding: 10px 12px;">Status</th>
                            <th style="padding: 10px 12px;">Last Triggered</th>
                            <th style="padding: 10px 12px; text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($webhooks as $wh): ?>
                        <tr style="border-bottom: 1px solid var(--border-color, #edf2f7); transition: background 0.15s;" onmouseenter="this.style.background='var(--bg-secondary, #f7fafc)'" onmouseleave="this.style.background='none'">
                            <td style="padding: 12px;">
                                <strong><?php echo htmlspecialchars($wh['webhook_name']); ?></strong>
                            </td>
                            <td style="padding: 12px; max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <span class="nu-badge" style="background: var(--bg-secondary, #edf2f7); border-radius: 4px; padding: 2px 6px; font-size: 11px; font-weight: 700; color: var(--accent); margin-right: 6px;"><?php echo htmlspecialchars($wh['webhook_method'] ?: 'POST'); ?></span>
                                <code><?php echo htmlspecialchars($wh['webhook_url']); ?></code>
                            </td>
                            <td style="padding: 12px;">
                                <?php
                                $evs = array_filter(explode(',', $wh['webhook_events'] ?? ''));
                                if (empty($evs)) {
                                    echo '<span style="color:var(--text-muted);">None</span>';
                                } else {
                                    foreach ($evs as $e) {
                                        echo '<span class="nu-badge" style="background: #e0f2fe; color: #0369a1; border-radius: 12px; padding: 2px 8px; font-size: 11px; margin-right: 4px; display: inline-block; margin-bottom: 2px;">' . htmlspecialchars(trim($e)) . '</span>';
                                    }
                                }
                                ?>
                            </td>
                            <td style="padding: 12px;">
                                <span class="nu-status <?php echo $wh['webhook_active'] ? 'nu-status-active' : 'nu-status-inactive'; ?>" style="border-radius: 12px; padding: 4px 10px; font-size: 12px; font-weight: 600; display: inline-block; background: <?php echo $wh['webhook_active'] ? '#dcfce7; color: #166534;' : '#fee2e2; color: #991b1b;'; ?>">
                                    <?php echo $wh['webhook_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td style="padding: 12px; color: var(--text-secondary, #4a5568); font-size: 13px;">
                                <?php echo $wh['webhook_last_triggered'] ? date('M j, Y H:i:s', strtotime($wh['webhook_last_triggered'])) : '<span style="color:var(--text-muted)">Never</span>'; ?>
                            </td>
                            <td style="padding: 12px; text-align: right; white-space: nowrap;">
                                <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="openWebhookModal(<?php echo htmlspecialchars(json_encode($wh), ENT_QUOTES, 'UTF-8'); ?>)" style="margin-right: 4px;">Edit</button>
                                <button class="nu-btn nu-btn-secondary nu-btn-sm" onclick="testWebhook(<?php echo $wh['webhook_id']; ?>)" style="margin-right: 4px; background: #f0fdf4; color: #16a34a; border-color: #bbf7d0;">Test</button>
                                <button class="nu-btn nu-btn-danger nu-btn-sm" onclick="deleteWebhook(<?php echo $wh['webhook_id']; ?>)">Delete</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($webhooks)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted, #a0aec0); font-style: italic;">No webhooks configured. Create a new webhook to trigger automated events.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 2. API Keys Tab -->
    <div id="apiKeysTab" class="nu-integrations-tab" style="display: none;">
        <div class="nu-card">
            <div class="nu-card-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h3 class="nu-card-title" style="margin: 0; font-size: 18px; font-weight: 600;">Authorized API Tokens</h3>
                <button class="nu-btn nu-btn-primary" onclick="openApiKeyModal()">+ Generate New Key</button>
            </div>
            <div class="nu-table-wrap" style="overflow-x: auto;">
                <table class="nu-table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border-color); text-align: left;">
                            <th style="padding: 10px 12px;">Token Name</th>
                            <th style="padding: 10px 12px;">Bearer Token Key</th>
                            <th style="padding: 10px 12px;">Mapped User Scope</th>
                            <th style="padding: 10px 12px;">Created Date</th>
                            <th style="padding: 10px 12px;">Expiration</th>
                            <th style="padding: 10px 12px; text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($apiKeys as $key): ?>
                        <tr style="border-bottom: 1px solid var(--border-color, #edf2f7); transition: background 0.15s;" onmouseenter="this.style.background='var(--bg-secondary, #f7fafc)'" onmouseleave="this.style.background='none'">
                            <td style="padding: 12px;">
                                <strong><?php echo htmlspecialchars($key['token_name'] ?? 'REST Key'); ?></strong>
                            </td>
                            <td style="padding: 12px;">
                                <code style="background: var(--bg-secondary, #edf2f7); padding: 4px 8px; border-radius: 4px; font-size: 13px;">
                                    <?php echo substr(htmlspecialchars($key['token_key']), 0, 16); ?>...
                                </code>
                            </td>
                            <td style="padding: 12px;">
                                <span class="nu-badge" style="background: #f0fdfa; color: #0d9488; font-weight: 600; padding: 2px 8px; border-radius: 4px; font-size: 12px;">
                                    User ID: <?php echo htmlspecialchars((string)($key['token_user_id'] ?? '-')); ?>
                                </span>
                            </td>
                            <td style="padding: 12px; color: var(--text-secondary); font-size: 13px;">
                                <?php echo date('M j, Y H:i', strtotime($key['token_created_at'])); ?>
                            </td>
                            <td style="padding: 12px; color: var(--text-secondary); font-size: 13px;">
                                <?php
                                if (empty($key['token_expires_at'])) {
                                    echo '<span style="color:#059669; font-weight:600;">Never Expires</span>';
                                } else {
                                    $expired = strtotime($key['token_expires_at']) < time();
                                    $col = $expired ? '#dc2626' : 'var(--text)';
                                    $suff = $expired ? ' (Expired)' : '';
                                    echo '<span style="color:' . $col . '">' . date('M j, Y', strtotime($key['token_expires_at'])) . $suff . '</span>';
                                }
                                ?>
                            </td>
                            <td style="padding: 12px; text-align: right;">
                                <button class="nu-btn nu-btn-danger nu-btn-sm" onclick="revokeApiKey(<?php echo $key['token_id']; ?>)">Revoke</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($apiKeys)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted, #a0aec0); font-style: italic;">No active REST API tokens generated. Create a key to access Nuvis StudioX REST endpoints.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 3. Live Webhook Inspector / Demo Tab -->
    <div id="demoConsoleTab" class="nu-integrations-tab" style="display: none;">
        <?php if (!empty($GLOBALS['nuConfig']['enableWebhookDemo'])): ?>
        <div class="nu-card" style="background: linear-gradient(135deg, #eff6ff, #dbeafe); border: 1px solid #bfdbfe; border-radius: 12px; padding: 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div style="flex: 1; min-width: 280px;">
                <h4 style="margin: 0 0 6px 0; color: #1e40af; font-size: 16px; font-weight: 700;">🚀 Launch Interactive Webhook Playground</h4>
                <p style="margin: 0; color: #1e3a8a; font-size: 13px; line-height: 1.5;">
                    Step through our new developer demo playground to provision test routes, customize Slack/Discord payload templates, fire simulated event streams, and see real-time cryptographic HMAC signature verifications.
                </p>
            </div>
            <a href="webhook_demo.php" class="nu-btn nu-btn-primary" style="background: #2563eb; color: #fff; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; font-weight: 600; padding: 10px 18px; border-radius: 8px;">
                Open Webhook Playground &rarr;
            </a>
        </div>
        <?php endif; ?>

        <div style="display: flex; gap: 20px; flex-wrap: wrap;">

            <div class="nu-card" style="flex: 1; min-width: 300px;">
                <div class="nu-card-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <h3 class="nu-card-title" style="margin: 0; font-size: 16px; font-weight: 600;">How to Test Webhooks</h3>
                    <button class="nu-btn nu-btn-secondary nu-btn-sm" onclick="fetchDemoLogs()" style="background: var(--bg-secondary);">Refresh Logs</button>
                </div>
                <div style="font-size: 14px; line-height: 1.6; color: var(--text-secondary);">
                    <p>Webhooks are triggered automatically when subscribed events occur in nuvis. Here is how you can perform a complete end-to-end integration test:</p>
                    <ol style="padding-left: 20px; margin-bottom: 16px;">
                        <li>Click <strong>+ New Webhook</strong> on the first tab.</li>
                        <li>Set Name as "Local Test Demo".</li>
                        <li>Set URL as: <code id="localListenerUrl"></code> (This points to our mock receiver).</li>
                        <li>Select the events you want to listen to (e.g. <code>form_insert</code>, <code>user_login</code>, <code>test_webhook</code>).</li>
                        <li>Create or update any Form record, or click the green <strong>Test</strong> button on your webhook.</li>
                        <li>Watch the live webhook deliveries arrive instantly in the panel on the right!</li>
                    </ol>
                    <div style="background: #eff6ff; border-left: 4px solid #3b82f6; padding: 12px; border-radius: 0 8px 8px 0; font-size: 13px; color: #1e3a8a;">
                        <strong>Tip:</strong> You can write custom payload formats using template variables like <code>{{event_type}}</code>, <code>{{actor}}</code>, and <code>{{data.my_field}}</code> to fit any API requirements (Slack, Discord, custom backends).
                    </div>
                </div>
            </div>

            <div class="nu-card" style="flex: 1.5; min-width: 400px; display: flex; flex-direction: column;">
                <div class="nu-card-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <h3 class="nu-card-title" style="margin: 0; font-size: 16px; font-weight: 600;">Captured Payload Stream</h3>
                    <button class="nu-btn nu-btn-danger nu-btn-sm" onclick="clearDemoLogs()" style="background: #fef2f2; color: #dc2626; border-color: #fca5a5;">Clear Logs</button>
                </div>
                <div id="demoLogsContainer" style="flex: 1; min-height: 350px; max-height: 500px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 8px; padding: 12px; background: #0f172a; color: #38bdf8; font-family: monospace; font-size: 13px;">
                    <div style="color: #64748b; font-style: italic; text-align: center; margin-top: 100px;">Listening for webhooks... Try clicking "Test" on a webhook or saving a form to capture a live payload.</div>
                </div>
            </div>

        </div>
    </div>

</div>

<!-- ════════════════════════ MODAL: WEBHOOK CONFIG ════════════════════════ -->
<div class="nu-modal-overlay" id="webhookModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1050; align-items: center; justify-content: center; padding: 20px; overflow-y: auto;">
    <div class="nu-modal" style="background: var(--card-bg, #fff); border-radius: 12px; width: 100%; max-width: 680px; padding: 24px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); max-height: 90vh; overflow-y: auto;">

        <div class="nu-modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 12px;">
            <h3 class="nu-modal-title" id="webhookModalTitle" style="margin: 0; font-size: 18px; font-weight: 600;">Add Outgoing Webhook</h3>
            <button class="nu-modal-close" onclick="closeWebhookModal()" style="background: none; border: none; cursor: pointer; color: var(--text-muted); padding: 4px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>

        <div class="nu-modal-body" style="display: flex; flex-direction: column; gap: 16px;">
            <input type="hidden" id="whId">

            <div style="display: flex; gap: 12px; width: 100%;">
                <div style="flex: 2;">
                    <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Integration Name <span style="color:red">*</span></label>
                    <input type="text" class="nu-input" id="whName" placeholder="e.g. Slack ERP Channel" style="width: 100%;">
                </div>
                <div style="flex: 1;">
                    <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">HTTP Method</label>
                    <select class="nu-input" id="whMethod" style="width: 100%;">
                        <option value="POST">POST</option>
                        <option value="PUT">PUT</option>
                        <option value="PATCH">PATCH</option>
                        <option value="GET">GET</option>
                    </select>
                </div>
            </div>

            <div>
                <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Endpoint URL <span style="color:red">*</span></label>
                <input type="url" class="nu-input" id="whUrl" placeholder="https://api.yourchannel.com/v1/webhook" style="width: 100%;">
            </div>

            <div>
                <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">HMAC Verification Secret (Optional)</label>
                <input type="text" class="nu-input" id="whSecret" placeholder="Calculates payload signature for X-Nuvis-Signature header" style="width: 100%;">
            </div>

            <!-- Subscribed Events Checkboxes -->
            <div>
                <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px;">Subscribed Events</label>
                <div style="display: flex; flex-wrap: wrap; gap: 12px; background: var(--bg-secondary, #edf2f7); padding: 12px; border-radius: 8px;">
                    <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer;">
                        <input type="checkbox" class="wh-event-cb" value="form_insert"> Record Created (insert)
                    </label>
                    <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer;">
                        <input type="checkbox" class="wh-event-cb" value="form_update"> Record Updated (update)
                    </label>
                    <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer;">
                        <input type="checkbox" class="wh-event-cb" value="form_delete"> Record Deleted (delete)
                    </label>
                    <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer;">
                        <input type="checkbox" class="wh-event-cb" value="workflow_advance"> Workflow Advanced
                    </label>
                    <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer;">
                        <input type="checkbox" class="wh-event-cb" value="user_login"> User Logged In
                    </label>
                    <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer;">
                        <input type="checkbox" class="wh-event-cb" value="test_webhook"> Manual Test Trigger
                    </label>
                </div>
            </div>

            <!-- Customizable HTTP Headers -->
            <div>
                <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Custom HTTP Headers</label>
                <div id="headersContainer" style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 8px;">
                    <!-- Rows added dynamically -->
                </div>
                <button class="nu-btn nu-btn-secondary nu-btn-sm" onclick="addHeaderRow()" style="background: var(--bg-secondary); border-color: var(--border-color); font-size: 12px;">+ Add Custom Header</button>
            </div>

            <!-- Customizable JSON Payload Template -->
            <div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label style="font-size: 13px; font-weight: 600; margin: 0;">Custom JSON Payload Format (Optional)</label>
                    <span style="font-size: 11px; color: var(--text-muted);">Leave empty for standard payload format</span>
                </div>
                <textarea class="nu-input" id="whPayloadTemplate" rows="4" placeholder='{ "text": "Record #{{record_id}} in {{table}} was updated by {{actor}}" }' style="width: 100%; font-family: monospace; font-size: 12px;"></textarea>

                <!-- Variables Guide -->
                <div style="margin-top: 6px; font-size: 11px; color: var(--text-secondary); background: #f0fdf4; padding: 8px; border-radius: 6px;">
                    <strong>Available Variable Placeholders:</strong>
                    <span style="display: inline-block; background: #fff; border: 1px solid #cbd5e1; padding: 1px 4px; border-radius: 3px; margin: 2px;">{{event_type}}</span>
                    <span style="display: inline-block; background: #fff; border: 1px solid #cbd5e1; padding: 1px 4px; border-radius: 3px; margin: 2px;">{{record_id}}</span>
                    <span style="display: inline-block; background: #fff; border: 1px solid #cbd5e1; padding: 1px 4px; border-radius: 3px; margin: 2px;">{{table}}</span>
                    <span style="display: inline-block; background: #fff; border: 1px solid #cbd5e1; padding: 1px 4px; border-radius: 3px; margin: 2px;">{{actor}}</span>
                    <span style="display: inline-block; background: #fff; border: 1px solid #cbd5e1; padding: 1px 4px; border-radius: 3px; margin: 2px;">{{timestamp}}</span>
                    <span style="display: inline-block; background: #fff; border: 1px solid #cbd5e1; padding: 1px 4px; border-radius: 3px; margin: 2px;">{{data}}</span>
                    <span style="display: inline-block; background: #fff; border: 1px solid #cbd5e1; padding: 1px 4px; border-radius: 3px; margin: 2px;">{{data.fieldname}}</span>
                </div>
            </div>

            <div style="display: flex; align-items: center; gap: 8px;">
                <input type="checkbox" id="whActive" checked style="cursor: pointer;">
                <label for="whActive" style="font-size: 13px; font-weight: 500; cursor: pointer;">Enable this webhook integration route</label>
            </div>

        </div>

        <div class="nu-modal-footer" style="display: flex; gap: 10px; justify-content: flex-end; border-top: 1px solid var(--border-color); padding-top: 16px; margin-top: 16px;">
            <button class="nu-btn nu-btn-secondary" onclick="closeWebhookModal()" style="background: none; border-color: var(--border-color);">Cancel</button>
            <button class="nu-btn nu-btn-primary" onclick="saveWebhook()">Save Integration</button>
        </div>
    </div>
</div>

<!-- ════════════════════════ MODAL: GENERATE API TOKEN ════════════════════════ -->
<div class="nu-modal-overlay" id="apiKeyModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1050; align-items: center; justify-content: center; padding: 20px;">
    <div class="nu-modal" style="background: var(--card-bg, #fff); border-radius: 12px; width: 100%; max-width: 480px; padding: 24px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);">

        <div class="nu-modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 12px;">
            <h3 class="nu-modal-title" style="margin: 0; font-size: 16px; font-weight: 600;">Generate API Authorization Key</h3>
            <button class="nu-modal-close" onclick="closeApiKeyModal()" style="background: none; border: none; cursor: pointer; color: var(--text-muted); padding: 4px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>

        <div class="nu-modal-body" style="display: flex; flex-direction: column; gap: 14px;">

            <div>
                <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Key Name / Label <span style="color:red">*</span></label>
                <input type="text" class="nu-input" id="keyName" placeholder="e.g. ERP Synchronizer Client" style="width: 100%;">
            </div>

            <div>
                <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Authorized User Scope <span style="color:red">*</span></label>
                <select class="nu-input" id="keyUser" style="width: 100%;">
                    <option value="">-- Loading users... --</option>
                </select>
                <small style="color: var(--text-muted); font-size: 12px; display: block; margin-top: 4px;">Enforces database permissions mapping to this user's role scope.</small>
            </div>

            <div>
                <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Expiration Date (Optional)</label>
                <input type="date" class="nu-input" id="keyExpiry" style="width: 100%;">
            </div>

            <!-- Success Box: Shown only when key has been generated successfully -->
            <div id="keySuccessContainer" style="display: none; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 14px; margin-top: 10px;">
                <strong style="color: #15803d; font-size: 13px; display: block; margin-bottom: 8px;">✓ API Token Generated Successfully!</strong>
                <p style="font-size: 12px; color: #166534; margin: 0 0 8px;">Copy this secret bearer token now. For security, it cannot be displayed again:</p>
                <div style="display: flex; gap: 6px; align-items: center;">
                    <input type="text" id="rawTokenKey" readonly style="flex: 1; font-family: monospace; font-size: 12px; padding: 8px; border: 1px solid #86efac; border-radius: 6px; background: #fff; color: #111827;">
                    <button class="nu-btn nu-btn-sm" onclick="copyGeneratedToken()" style="background: #16a34a; color: #fff; border: none; font-weight: 600;">Copy</button>
                </div>
            </div>

        </div>

        <div class="nu-modal-footer" id="apiKeyModalFooter" style="display: flex; gap: 10px; justify-content: flex-end; border-top: 1px solid var(--border-color); padding-top: 16px; margin-top: 16px;">
            <button class="nu-btn nu-btn-secondary" onclick="closeApiKeyModal()" style="background: none; border-color: var(--border-color);">Cancel</button>
            <button class="nu-btn nu-btn-primary" onclick="saveApiKey()">Generate Key</button>
        </div>
    </div>
</div>

<!-- ════════════════════════ SCRIPTS FOR MODULE ════════════════════════ -->
<script>
// Switch active module tabs
function switchIntegrationsTab(tabId, btn) {
    document.querySelectorAll('.nu-integrations-tab').forEach(t => t.style.display = 'none');
    document.getElementById(tabId).style.display = 'block';

    document.querySelectorAll('.nu-tab-btn').forEach(b => {
        b.classList.remove('active');
        b.style.color = 'var(--text-muted, #718096)';
        b.style.borderBottom = 'none';
        b.style.fontWeight = '500';
    });

    btn.classList.add('active');
    btn.style.color = 'var(--accent, #4f6bed)';
    btn.style.borderBottom = '2px solid var(--accent, #4f6bed)';
    btn.style.fontWeight = '600';
}

// ── Outgoing Webhook logic ───────────────────────────────────────────────────

function openWebhookModal(data = null) {
    // Clear elements
    document.getElementById('whId').value = '';
    document.getElementById('whName').value = '';
    document.getElementById('whUrl').value = '';
    document.getElementById('whMethod').value = 'POST';
    document.getElementById('whSecret').value = '';
    document.getElementById('whPayloadTemplate').value = '';
    document.getElementById('whActive').checked = true;
    document.getElementById('headersContainer').innerHTML = '';

    document.querySelectorAll('.wh-event-cb').forEach(cb => cb.checked = false);

    if (data) {
        document.getElementById('webhookModalTitle').textContent = 'Edit Webhook Integration';
        document.getElementById('whId').value = data.webhook_id;
        document.getElementById('whName').value = data.webhook_name;
        document.getElementById('whUrl').value = data.webhook_url;
        document.getElementById('whMethod').value = data.webhook_method || 'POST';
        document.getElementById('whSecret').value = data.webhook_secret || '';
        document.getElementById('whPayloadTemplate').value = data.webhook_payload_template || '';
        document.getElementById('whActive').checked = parseInt(data.webhook_active) === 1;

        // Checkboxes check
        const activeEvs = (data.webhook_events || '').split(',').map(s => s.trim());
        document.querySelectorAll('.wh-event-cb').forEach(cb => {
            if (activeEvs.includes(cb.value)) cb.checked = true;
        });

        // Headers parse
        try {
            const parsed = JSON.parse(data.webhook_headers || '{}');
            Object.keys(parsed).forEach(k => {
                addHeaderRow(k, parsed[k]);
            });
        } catch (e) {
            console.error('Error parsing headers JSON', e);
        }
    } else {
        document.getElementById('webhookModalTitle').textContent = 'Add Outgoing Webhook';
        // Add a default content-type header to start
        addHeaderRow('Content-Type', 'application/json');
    }

    document.getElementById('webhookModal').style.display = 'flex';
}

function closeWebhookModal() {
    document.getElementById('webhookModal').style.display = 'none';
}

function addHeaderRow(key = '', val = '') {
    const container = document.getElementById('headersContainer');
    const div = document.createElement('div');
    div.className = 'header-row';
    div.style.cssText = 'display: flex; gap: 8px; align-items: center;';

    div.innerHTML = `
        <input type="text" class="nu-input h-key" placeholder="Authorization" value="${key}" style="flex:1; font-size:12px; padding:6px 10px;">
        <input type="text" class="nu-input h-value" placeholder="Bearer your-secret-token" value="${val}" style="flex:1.5; font-size:12px; padding:6px 10px;">
        <button type="button" class="nu-btn nu-btn-danger" onclick="this.parentNode.remove()" style="background:#fef2f2; color:#ef4444; border-color:#fca5a5; padding:6px 10px; font-size:11px;">Remove</button>
    `;
    container.appendChild(div);
}

async function saveWebhook() {
    const id = document.getElementById('whId').value;
    const name = document.getElementById('whName').value.trim();
    const url = document.getElementById('whUrl').value.trim();
    const method = document.getElementById('whMethod').value;
    const secret = document.getElementById('whSecret').value.trim();
    const payloadTemplate = document.getElementById('whPayloadTemplate').value;
    const active = document.getElementById('whActive').checked ? 1 : 0;

    if (name === '' || url === '') {
        NuApp.toast('Name and URL are required!', 'error');
        return;
    }

    // Build comma separated events list
    const evs = [];
    document.querySelectorAll('.wh-event-cb:checked').forEach(cb => evs.push(cb.value));
    const eventsStr = evs.join(',');

    // Build headers JSON object
    const headers = {};
    document.querySelectorAll('.header-row').forEach(row => {
        const k = row.querySelector('.h-key').value.trim();
        const v = row.querySelector('.h-value').value.trim();
        if (k !== '') headers[k] = v;
    });

    const payload = {
        webhook_id: id ? parseInt(id) : null,
        webhook_name: name,
        webhook_url: url,
        webhook_method: method,
        webhook_headers: JSON.stringify(headers),
        webhook_payload_template: payloadTemplate,
        webhook_events: eventsStr,
        webhook_secret: secret,
        webhook_active: active
    };

    try {
        const res = await NuApp.apiJson('api/webhook.php?action=save', {
            method: 'POST',
            body: JSON.stringify(payload),
            credentials: 'same-origin'
        });

        if (res.success) {
            NuApp.toast(res.message || 'Webhook integration saved!');
            closeWebhookModal();
            NuApp.loadModule('integrations');
        } else {
            NuApp.toast(res.error || 'Failed to save webhook', 'error');
        }
    } catch (e) {
        NuApp.toast('An error occurred: ' + e.message, 'error');
    }
}

async function testWebhook(id) {
    NuApp.toast('Executing test dispatch...');
    try {
        const res = await NuApp.apiJson('api/webhook.php?action=test&id=' + id, { credentials: 'same-origin' });

        // Show test result in a styled alert modal
        const success = !!res.success;
        const alertHtml = `
            <div style="text-align: left; font-size: 14px; line-height: 1.5;">
                <div style="background: ${success ? '#dcfce7' : '#fee2e2'}; color: ${success ? '#15803d' : '#991b1b'}; padding: 12px; border-radius: 8px; font-weight: 600; margin-bottom: 12px;">
                    Test Trigger Result: ${success ? 'SUCCESS' : 'FAILED'} (HTTP ${res.http_code || 'Unknown'})
                </div>
                <div style="margin-bottom: 8px;"><strong>Endpoint Target:</strong> <code>${res.webhook_url || 'N/A'}</code></div>
                <div style="margin-bottom: 8px;"><strong>Sent Custom Headers:</strong> <pre style="background:#f1f5f9; padding:8px; border-radius:6px; font-size:11px; max-height:80px; overflow-y:auto; margin:4px 0;">${JSON.stringify(res.headers || [], null, 2)}</pre></div>
                <div style="margin-bottom: 8px;"><strong>Sent Payload Body:</strong> <pre style="background:#1e293b; color:#38bdf8; padding:8px; border-radius:6px; font-size:11px; max-height:120px; overflow-y:auto; margin:4px 0;">${htmlspecialchars(res.payload || '{}')}</pre></div>
                <div><strong>Server Response:</strong> <pre style="background:#f1f5f9; padding:8px; border-radius:6px; font-size:11px; max-height:100px; overflow-y:auto; margin:4px 0;">${htmlspecialchars(res.response || '(empty)')}</pre></div>
            </div>
        `;

        // Build alert overlay dynamically
        const overlay = document.createElement('div');
        overlay.className = 'nu-webhook-test-overlay';
        overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:2000;display:flex;align-items:center;justify-content:center;padding:20px;';

        const box = document.createElement('div');
        box.style.cssText = 'background:var(--card-bg,#fff);border-radius:12px;padding:24px;width:92%;max-width:540px;box-shadow:0 10px 25px rgba(0,0,0,0.15);';

        box.innerHTML = `
            <h4 style="margin:0 0 16px; font-size:16px; font-weight:600;">Webhook Dispatch Test details</h4>
            <div>${alertHtml}</div>
            <div style="text-align:right; margin-top:20px;">
                <button class="nu-btn nu-btn-primary" onclick="this.closest('.nu-webhook-test-overlay').remove()">Done</button>
            </div>
        `;

        overlay.appendChild(box);
        document.body.appendChild(overlay);

    } catch (e) {
        NuApp.toast('Webhook test run failed: ' + e.message, 'error');
    }
}

async function deleteWebhook(id) {
    if (!confirm('Are you completely sure you want to delete this webhook integration route?')) return;
    try {
        const res = await NuApp.apiJson('api/webhook.php?action=delete&id=' + id, { credentials: 'same-origin' });
        if (res.success) {
            NuApp.toast(res.message || 'Deleted');
            NuApp.loadModule('integrations');
        } else {
            NuApp.toast(res.error || 'Delete failed', 'error');
        }
    } catch (e) {
        NuApp.toast(e.message, 'error');
    }
}

// Helper to escape HTML tags in strings
function htmlspecialchars(str) {
    if (typeof str !== 'string') return String(str);
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}


// ── REST API Key logic ────────────────────────────────────────────────────────

async function openApiKeyModal() {
    document.getElementById('keyName').value = '';
    document.getElementById('keyExpiry').value = '';
    document.getElementById('keySuccessContainer').style.display = 'none';
    document.getElementById('rawTokenKey').value = '';

    // Restore footer button visibility
    document.getElementById('apiKeyModalFooter').style.display = 'flex';

    // Populate user list scope
    const select = document.getElementById('keyUser');
    select.innerHTML = '<option value="">-- Loading users... --</option>';

    try {
        const res = await NuApp.apiJson('api/webhook.php?action=list_users', { credentials: 'same-origin' });
        if (res.success && res.users) {
            select.innerHTML = '<option value="">-- Choose System User --</option>';
            res.users.forEach(u => {
                const opt = document.createElement('option');
                opt.value = u.usr_id;
                opt.textContent = `${u.usr_username} (${u.usr_name || 'No Name'}) - Role: ${u.usr_role}`;
                select.appendChild(opt);
            });
        } else {
            select.innerHTML = '<option value="">Failed to load active system users</option>';
        }
    } catch (e) {
        select.innerHTML = '<option value="">Connection error loading users</option>';
    }

    document.getElementById('apiKeyModal').style.display = 'flex';
}

function closeApiKeyModal() {
    document.getElementById('apiKeyModal').style.display = 'none';
    // If a key has been generated, refresh view to show in list
    if (document.getElementById('rawTokenKey').value !== '') {
        NuApp.loadModule('integrations');
    }
}

async function saveApiKey() {
    const name = document.getElementById('keyName').value.trim();
    const userId = document.getElementById('keyUser').value;
    const expiry = document.getElementById('keyExpiry').value;

    if (name === '' || userId === '') {
        NuApp.toast('Name and Authorized User Scope are required!', 'error');
        return;
    }

    try {
        const res = await NuApp.apiJson('api/webhook.php?action=generate_token', {
            method: 'POST',
            body: JSON.stringify({
                token_name: name,
                token_user_id: userId,
                token_expires_at: expiry
            }),
            credentials: 'same-origin'
        });

        if (res.success && res.token_key) {
            document.getElementById('rawTokenKey').value = res.token_key;
            document.getElementById('keySuccessContainer').style.display = 'block';
            document.getElementById('apiKeyModalFooter').style.display = 'none'; // hide generate buttons once generated
            NuApp.toast('Secret API Key generated successfully!');
        } else {
            NuApp.toast(res.error || 'Failed to generate key', 'error');
        }
    } catch (e) {
        NuApp.toast('API Key Generation Failed: ' + e.message, 'error');
    }
}

function copyGeneratedToken() {
    const input = document.getElementById('rawTokenKey');
    input.select();
    input.setSelectionRange(0, 99999); // mobile support
    try {
        document.execCommand('copy');
        NuApp.toast('Token copied to clipboard!');
    } catch (e) {
        NuApp.toast('Failed to auto-copy. Please highlight and copy manually.', 'warning');
    }
}

async function revokeApiKey(id) {
    if (!confirm('Are you completely sure you want to revoke this REST API Token key? External systems using it will be locked out immediately.')) return;
    try {
        const res = await NuApp.apiJson('api/webhook.php?action=revoke_token&id=' + id, { credentials: 'same-origin' });
        if (res.success) {
            NuApp.toast(res.message || 'Token revoked successfully');
            NuApp.loadModule('integrations');
        } else {
            NuApp.toast(res.error || 'Revoke failed', 'error');
        }
    } catch (e) {
        NuApp.toast(e.message, 'error');
    }
}


// ── Webhook live receiver/demo inspector logic ────────────────────────────────

function updateListenerUrlInfo() {
    const origin = window.location.origin;
    // Extract path to api
    const path = window.location.pathname.replace('index.php', '').replace(/\/$/, '');
    const url = `${origin}${path}/api/webhook_demo_listener.php`;
    const label = document.getElementById('localListenerUrl');
    if (label) {
        label.textContent = url;
    }
}

async function fetchDemoLogs() {
    updateListenerUrlInfo();
    const container = document.getElementById('demoLogsContainer');
    if (!container) return;

    try {
        const res = await fetch('api/webhook_demo_listener.php?action=list_logs', { credentials: 'same-origin' });
        const data = await res.json();

        if (data.success && data.logs && data.logs.length > 0) {
            container.innerHTML = '';
            data.logs.forEach(log => {
                const item = document.createElement('div');
                item.style.cssText = 'border-bottom: 1px solid #1e293b; padding-bottom: 12px; margin-bottom: 12px;';

                // Color badges
                const methodColor = log.method === 'POST' ? '#34d399' : '#fb7185';

                item.innerHTML = `
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                        <span>
                            <span style="background:${methodColor}; color:#0f172a; font-weight:800; padding:1px 6px; border-radius:3px; font-size:11px; margin-right:6px;">${log.method}</span>
                            <span style="color:#e2e8f0; font-weight:600;">Delivery: ${log.event || 'test_webhook'}</span>
                        </span>
                        <span style="color:#64748b; font-size:12px;">${log.received_at}</span>
                    </div>
                    <div style="color:#a5f3fc; font-size:12px; margin-bottom:4px;"><strong>Headers:</strong></div>
                    <pre style="background:#1e293b; color:#94a3b8; padding:8px; border-radius:4px; font-size:11px; overflow-x:auto; margin:0 0 6px;">${JSON.stringify(log.headers, null, 2)}</pre>
                    <div style="color:#a5f3fc; font-size:12px; margin-bottom:4px;"><strong>Resolved Body Payload:</strong></div>
                    <pre style="background:#020617; color:#38bdf8; padding:8px; border-radius:4px; font-size:11px; overflow-x:auto; margin:0;">${JSON.stringify(log.payload, null, 2)}</pre>
                `;
                container.appendChild(item);
            });
            // scroll container to top
            container.scrollTop = 0;
        } else {
            container.innerHTML = '<div style="color:#64748b; font-style:italic; text-align:center; margin-top:100px;">Listening for webhooks... Try clicking "Test" on a webhook or saving a form to capture a live payload.</div>';
        }
    } catch (e) {
        container.innerHTML = `<div style="color:#ef4444; text-align:center; margin-top:100px;">Error loading logs: ${e.message}</div>`;
    }
}

async function clearDemoLogs() {
    if (!confirm('Clear all captured webhook logs?')) return;
    try {
        const res = await fetch('api/webhook_demo_listener.php?action=clear_logs', { method: 'POST', credentials: 'same-origin' });
        const data = await res.json();
        if (data.success) {
            NuApp.toast('Logs cleared');
            fetchDemoLogs();
        }
    } catch (e) {
        NuApp.toast('Failed to clear logs: ' + e.message, 'error');
    }
}

// Initial triggers setup after rendering
setTimeout(() => {
    updateListenerUrlInfo();
}, 200);
</script>
