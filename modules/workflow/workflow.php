<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

$db        = NuDatabase::getInstance();
$pid       = ProjectContext::getId();
$workflows = $db->fetchAll(
    'SELECT w.*,
            (SELECT COUNT(*) FROM nu_workflow_stages s WHERE s.wfs_wf_id = w.wf_id) AS stage_count,
            (SELECT COUNT(*) FROM nu_workflow_instances i WHERE i.wfi_wf_id = w.wf_id AND i.wfi_status = "active") AS active_instances,
            (SELECT COUNT(*) FROM nu_workflow_instances i WHERE i.wfi_wf_id = w.wf_id) AS total_instances
       FROM nu_workflows w
      WHERE w.project_id = ?
      ORDER BY w.wf_active DESC, w.wf_updated_at DESC',
    [$pid]
);
?>

<div class="nu-workflow-module">

<!-- ══ TOP HEADER ══════════════════════════════════════════════════════════════ -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px;">
  <div>
    <h2 style="font-size:20px;font-weight:600;margin:0 0 2px;">Workflow Builder</h2>
    <p style="color:var(--text-secondary);font-size:13px;margin:0;">Design approval flows, stage pipelines and automated transitions</p>
  </div>
  <div style="display:flex;gap:8px;align-items:center;">
    <div id="wfSearch" style="position:relative;">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--text-tertiary);"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" class="nu-input" id="wfSearchInput" placeholder="Search workflows…" oninput="WF.search(this.value)" style="padding-left:30px;width:200px;font-size:13px;">
    </div>
    <?php if ($auth->hasPermission('workflow', 'create')): ?>
    <input type="file" id="wfImportFile" accept="application/json,.json" style="display:none;" onchange="WF.importFile(this)">
    <button class="nu-btn nu-btn-ghost" onclick="document.getElementById('wfImportFile').click()" title="Import a workflow exported from another project">Import</button>
    <button class="nu-btn nu-btn-primary" onclick="WF.openNew()">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      New Workflow
    </button>
    <?php endif; ?>
  </div>
</div>

<!-- ══ STATS ROW ═══════════════════════════════════════════════════════════════ -->
<?php
$totalWf      = count($workflows);
$activeWf     = count(array_filter($workflows, fn($w) => $w['wf_active']));
$totalActive  = array_sum(array_column($workflows, 'active_instances'));
$totalRun     = array_sum(array_column($workflows, 'total_instances'));
?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:24px;">
  <?php foreach ([
    ['Workflows',         $totalWf,     '#6366f1', 'M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5'],
    ['Active',            $activeWf,    '#22c55e', 'M22 11.08V12a10 10 0 1 1-5.93-9.14 M22 4L12 14.01l-3-3'],
    ['Running Instances', $totalActive, '#f59e0b', 'M13 10V3L4 14h7v7l9-11h-7z'],
    ['Total Runs',        $totalRun,    '#06b6d4', 'M12 20h9 M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z'],
  ] as [$label, $val, $color, $icon]): ?>
  <div class="nu-card" style="padding:16px;">
    <div style="display:flex;align-items:center;gap:10px;">
      <div style="width:36px;height:36px;border-radius:8px;background:<?= $color ?>18;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="<?= $color ?>" stroke-width="2"><path d="<?= $icon ?>"/></svg>
      </div>
      <div>
        <div style="font-size:22px;font-weight:700;line-height:1;"><?= $val ?></div>
        <div style="font-size:11px;color:var(--text-tertiary);margin-top:2px;"><?= $label ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ══ WORKFLOW CARDS ══════════════════════════════════════════════════════════ -->
<div id="wfCards" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;">
  <?php if (empty($workflows)): ?>
  <div class="nu-card" style="grid-column:1/-1;text-align:center;padding:64px 24px;">
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" style="color:var(--text-tertiary);margin:0 auto 16px;"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
    <p style="font-size:15px;font-weight:500;margin-bottom:4px;">No workflows yet</p>
    <p style="color:var(--text-tertiary);font-size:13px;margin-bottom:20px;">Create your first workflow to start building approval flows and pipelines.</p>
    <button class="nu-btn nu-btn-primary" onclick="WF.openNew()">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Create First Workflow
    </button>
  </div>
  <?php else: ?>
  <?php foreach ($workflows as $wf): ?>
  <div class="nu-card wf-card" data-id="<?= $wf['wf_id'] ?>" data-name="<?= htmlspecialchars(strtolower($wf['wf_name'])) ?>" style="cursor:pointer;transition:box-shadow .15s,transform .15s;" onclick="WF.openDetail(<?= $wf['wf_id'] ?>)" onmouseenter="this.style.boxShadow='0 4px 20px rgba(0,0,0,.15)';this.style.transform='translateY(-1px)'" onmouseleave="this.style.boxShadow='';this.style.transform=''">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;">
      <div style="flex:1;min-width:0;">
        <h4 style="font-size:15px;font-weight:600;margin:0 0 3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($wf['wf_name']) ?></h4>
        <span class="nu-badge nu-badge-secondary" style="font-size:11px;"><?= htmlspecialchars($wf['wf_code']) ?></span>
      </div>
      <span class="nu-badge <?= $wf['wf_active'] ? 'nu-badge-success' : 'nu-badge-muted' ?>" style="margin-left:8px;flex-shrink:0;"><?= $wf['wf_active'] ? 'Active' : 'Inactive' ?></span>
    </div>
    <?php if ($wf['wf_description']): ?>
    <p style="font-size:12px;color:var(--text-secondary);margin-bottom:12px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?= htmlspecialchars($wf['wf_description']) ?></p>
    <?php endif; ?>
    <div style="display:flex;gap:16px;font-size:12px;color:var(--text-tertiary);margin-bottom:14px;">
      <span><b style="color:var(--text-primary);font-weight:600;"><?= $wf['stage_count'] ?></b> stages</span>
      <span><b style="color:var(--color-warning,#f59e0b);font-weight:600;"><?= $wf['active_instances'] ?></b> running</span>
      <span><b style="color:var(--text-secondary);font-weight:600;"><?= $wf['total_instances'] ?></b> total</span>
      <?php if ($wf['wf_form_code']): ?>
      <span>📋 <?= htmlspecialchars($wf['wf_form_code']) ?></span>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:6px;" onclick="event.stopPropagation();">
      <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="WF.openDetail(<?= $wf['wf_id'] ?>)">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        Edit
      </button>
      <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="WF.openInstances(<?= $wf['wf_id'] ?>, '<?= htmlspecialchars($wf['wf_name'], ENT_QUOTES) ?>')">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
        Instances
      </button>
      <?php if ($auth->hasPermission('workflow', 'create')): ?>
      <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="WF.duplicate(<?= $wf['wf_id'] ?>)" title="Duplicate this workflow">Copy</button>
      <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="WF.exportWf(<?= $wf['wf_id'] ?>)" title="Download as a JSON file">Export</button>
      <?php endif; ?>
      <?php if ($auth->hasPermission('workflow', 'delete')): ?>
      <button class="nu-btn nu-btn-danger nu-btn-sm" onclick="WF.delete(<?= $wf['wf_id'] ?>, '<?= htmlspecialchars($wf['wf_name'], ENT_QUOTES) ?>')">
        Delete
      </button>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════════
     DETAIL DRAWER  (slide in from right)
═══════════════════════════════════════════════════════════════════════════════ -->
<div id="wfDrawerOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:800;" onclick="WF.closeDrawer()"></div>

<div id="wfDrawer" style="
  display:none;position:fixed;top:0;right:0;bottom:0;
  width:min(780px,100vw);background:var(--bg-primary,#fff);
  box-shadow:-4px 0 32px rgba(0,0,0,.2);z-index:801;
  overflow-y:auto;transform:translateX(100%);
  transition:transform .25s cubic-bezier(.16,1,.3,1);
">

  <!-- Drawer header -->
  <div style="position:sticky;top:0;background:var(--bg-primary,#fff);border-bottom:1px solid var(--border-color);z-index:10;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;">
    <div>
      <h3 id="wfDrawerTitle" style="font-size:16px;font-weight:600;margin:0 0 2px;">Workflow</h3>
      <span id="wfDrawerCode" style="font-size:12px;color:var(--text-tertiary);font-family:monospace;"></span>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
      <button class="nu-btn nu-btn-primary nu-btn-sm" onclick="WF.saveAll()">Save</button>
      <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="WF.closeDrawer()">✕</button>
    </div>
  </div>

  <div style="padding:20px;">

    <!-- ── Workflow meta ────────────────────────────────────────────────────── -->
    <div class="nu-card" style="margin-bottom:20px;">
      <h4 style="font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-tertiary);margin:0 0 14px;">Workflow Details</h4>
      <input type="hidden" id="wfEditId">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
        <div class="nu-field" style="margin:0;">
          <label>Name <span style="color:var(--color-danger)">*</span></label>
          <input type="text" class="nu-input" id="wfName" placeholder="Leave Approval" oninput="WF.autoCode()">
        </div>
        <div class="nu-field" style="margin:0;">
          <label>Code</label>
          <input type="text" class="nu-input" id="wfCode" placeholder="leave_approval" style="font-family:monospace;font-size:13px;">
        </div>
      </div>
      <div class="nu-field" style="margin-bottom:12px;">
        <label>Description</label>
        <input type="text" class="nu-input" id="wfDescription" placeholder="Optional description…">
      </div>
      <div style="display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end;">
        <div class="nu-field" style="margin:0;">
          <label>Bound Form Code <span style="color:var(--text-tertiary);font-weight:400;">(optional)</span></label>
          <input type="text" class="nu-input" id="wfFormCode" placeholder="leave_form" style="font-family:monospace;font-size:13px;">
        </div>
        <div class="nu-field" style="margin:0;">
          <label>Active</label>
          <div style="padding-top:8px;"><input type="checkbox" id="wfActive" checked style="width:16px;height:16px;"></div>
        </div>
      </div>
    </div>

    <!-- ── Live Diagram Visual ─────────────────────────────────────────────── -->
    <div class="nu-card" style="margin-bottom:20px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <h4 style="font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-tertiary);margin:0;">Live Flowchart Diagram</h4>
        <span style="font-size:11px;color:var(--text-tertiary);background:var(--bg-secondary);padding:2px 8px;border-radius:99px;">Auto-generated</span>
      </div>
      <div id="wfMermaidContainer" style="background:var(--bg-secondary,#f9fafb); border:1px solid var(--border-color); border-radius:12px; padding:16px; min-height:180px; display:flex; flex-direction:column; align-items:center; justify-content:center; overflow:auto; position:relative;">
        <div id="wfMermaidDiagram" style="width:100%;"></div>
      </div>
    </div>

    <!-- ── Test Run & Simulation ──────────────────────────────────────────────── -->
    <div class="nu-card" style="margin-bottom:20px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
        <h4 style="font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-tertiary);margin:0;">Test Run & Simulation</h4>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" id="btnToggleSim" onclick="WF.toggleSimulation()" style="font-size:11px;padding:2px 8px;">Show Simulator</button>
      </div>

      <div id="wfSimulationPanel" style="display:none;border-top:1px solid var(--border-color);padding-top:12px;">
        <p style="font-size:12px;color:var(--text-secondary);margin-bottom:10px;">
          Simulate how the workflow processes a record step-by-step based on conditions and mock data.
        </p>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
          <div>
            <label style="font-size:11px;font-weight:600;color:var(--text-tertiary);text-transform:uppercase;">Mock Record Data (JSON)</label>
            <textarea class="nu-input" id="wfSimData" rows="4" style="font-family:monospace;font-size:12px;" placeholder='{ "amount": 1500, "dept": "engineering" }'></textarea>
          </div>
          <div>
            <label style="font-size:11px;font-weight:600;color:var(--text-tertiary);text-transform:uppercase;">Simulation Log & Results</label>
            <div id="wfSimResults" style="background:var(--bg-secondary);border:1px solid var(--border-color);border-radius:6px;padding:8px;font-family:monospace;font-size:11px;height:72px;overflow-y:auto;color:var(--text-secondary);line-height:1.4;">
              Ready to simulate. Click "Run Simulation".
            </div>
          </div>
        </div>

        <div style="display:flex;gap:8px;">
          <button class="nu-btn nu-btn-primary nu-btn-sm" onclick="WF.runSimulation()" style="flex:1;">⚡ Run Simulation</button>
          <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="WF.resetSimulation()">Reset</button>
        </div>
      </div>
    </div>

    <!-- ── Stage Pipeline Visual ─────────────────────────────────────────────── -->
    <div class="nu-card" style="margin-bottom:20px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <h4 style="font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-tertiary);margin:0;">Stages</h4>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="WF.addStage()">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add Stage
        </button>
      </div>

      <!-- Pipeline visual -->
      <div id="wfPipeline" style="display:none;align-items:center;gap:0;overflow-x:auto;padding-bottom:8px;margin-bottom:16px;min-height:64px;"></div>

      <!-- Stage list -->
      <div id="wfStageList"></div>
    </div>

    <!-- ── Transitions ───────────────────────────────────────────────────────── -->
    <div class="nu-card" style="margin-bottom:20px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <h4 style="font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-tertiary);margin:0;">Transitions</h4>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="WF.addTransition()">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add Transition
        </button>
      </div>
      <div id="wfTransitionList">
        <p style="font-size:13px;color:var(--text-tertiary);text-align:center;padding:16px;">Save the workflow first, then add transitions.</p>
      </div>
    </div>

  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════════
     INSTANCES PANEL
═══════════════════════════════════════════════════════════════════════════════ -->
<div id="wfInstancesPanel" style="display:none;margin-top:28px;">
  <div class="nu-card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:12px;">
      <div>
        <h3 class="nu-card-title" id="wfInstancesTitle" style="margin:0;">Instances</h3>
        <span id="wfInstancesMeta" style="font-size:12px;color:var(--text-secondary);"></span>
      </div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <div class="rpt-view-tabs" style="display:flex;gap:4px;">
          <button id="wfToggleList" class="rpt-vtab active" onclick="WF.setViewLayout('list')" style="padding: 5px 10px; font-size:12px; cursor:pointer;">📋 List</button>
          <button id="wfToggleKanban" class="rpt-vtab" onclick="WF.setViewLayout('kanban')" style="padding: 5px 10px; font-size:12px; cursor:pointer;">📊 Kanban</button>
        </div>
        <select class="nu-input" id="wfInstancesFilter" onchange="WF.filterInstances()" style="font-size:13px;padding:5px 10px;">
          <option value="">All statuses</option>
          <option value="active">Active</option>
          <option value="completed">Completed</option>
          <option value="rejected">Rejected</option>
          <option value="cancelled">Cancelled</option>
        </select>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="document.getElementById('wfInstancesPanel').style.display='none'">Close</button>
      </div>
    </div>
    <div id="wfInstancesContent"></div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════════
     HOOK CONFIGURATION MODAL
═══════════════════════════════════════════════════════════════════════════════ -->
<div id="wfRuleModal" class="wfb-modal">
  <div class="wfb-modal-card">
    <div class="wfb-modal-head">
      <div>
        <h3 id="wfRuleTitle" style="font-size:16px;font-weight:700;margin:0 0 2px;">Rules &amp; actions</h3>
        <p id="wfRuleSub" style="font-size:12px;color:var(--text-tertiary);margin:0;"></p>
      </div>
      <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="WF.closeRule()" aria-label="Close">✕</button>
    </div>
    <div class="wfb-tabs" id="wfRuleTabs">
      <button type="button" class="is-active" data-tab="when" onclick="WF.ruleTab('when')">1 · When — rules &amp; who</button>
      <button type="button" data-tab="then" onclick="WF.ruleTab('then')">2 · Then — actions</button>
    </div>
    <div class="wfb-modal-body">
      <div class="wfb-pane" id="wfPaneWhen">
        <p style="margin:0 0 8px;font-size:13px;color:var(--text-secondary);">This step can only be taken when the rule below is true. Leave it empty to always allow it.</p>
        <div id="wfCondHost"></div>
        <div class="wfb-testbar">
          <input class="nu-input" id="wfTestRec" placeholder="record id (blank = latest record)" style="width:230px;height:32px;font-size:12.5px;">
          <button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" onclick="WF.testRule()">Test this rule on a real record</button>
          <span id="wfTestRes" class="wfb-test-res"></span>
        </div>
        <div class="wfb-opts">
          <label class="wfb-opt"><input type="checkbox" id="wfOptComment"><span><b>Require a comment</b><small>The person must explain before taking this step.</small></span></label>
          <label class="wfb-opt"><input type="checkbox" id="wfOptAuto"><span><b>Run automatically</b><small>Fires by itself as soon as the rule is true (checked on every step and by the scheduled job).</small></span></label>
          <label class="wfb-opt"><input type="checkbox" id="wfOptSla"><span><b>Use when the stage SLA is exceeded</b><small>Escalate automatically if the instance sits too long (set SLA hours on the stage).</small></span></label>
        </div>
        <div style="margin-top:14px;">
          <div style="font-size:12px;font-weight:700;color:var(--text-secondary);">Who can take this step?</div>
          <div class="wfb-roles" id="wfRoles"></div>
          <p class="wfb-note">Leave all unticked to allow everyone (or the stage’s role). Administrators can always act.</p>
        </div>
        <div style="margin-top:14px;max-width:520px;">
          <label style="font-size:12px;font-weight:700;color:var(--text-secondary);display:block;margin-bottom:4px;">Ask for confirmation first (optional)</label>
          <input class="nu-input" id="wfOptConfirm" placeholder="e.g. This will notify the customer. Continue?" style="height:32px;font-size:12.5px;">
        </div>
      </div>
      <div class="wfb-pane" id="wfPaneThen" hidden>
        <p id="wfThenHint" style="margin:0 0 10px;font-size:13px;color:var(--text-secondary);">These run, in order, right after the step is taken. Use <b>Update a record</b> to change fields in this record or in any other table.</p>
        <div id="wfActHost"></div>
      </div>
    </div>
    <div class="wfb-modal-foot">
      <span id="wfRuleErr" style="font-size:12px;color:var(--color-error);"></span>
      <div style="display:flex;gap:8px;margin-left:auto;">
        <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="WF.closeRule()">Cancel</button>
        <button class="nu-btn nu-btn-primary nu-btn-sm" onclick="WF.applyRule()">Apply</button>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════════
     HISTORY TIMELINE MODAL
═══════════════════════════════════════════════════════════════════════════════ -->
<div id="wfHistoryModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:900;align-items:center;justify-content:center;">
  <div style="background:var(--bg-primary,#fff);border-radius:12px;width:min(580px,94vw);max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);">
    <div style="padding:20px 24px;border-bottom:1px solid var(--border-color);display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:var(--bg-primary,#fff);z-index:1;">
      <div>
        <h3 id="wfHistoryTitle" style="font-size:15px;font-weight:600;margin:0 0 2px;">Instance History</h3>
        <span id="wfHistorySub" style="font-size:12px;color:var(--text-tertiary);"></span>
      </div>
      <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="WF.closeHistory()">✕</button>
    </div>
    <div id="wfHistoryStage" style="padding:12px 24px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:8px;"></div>
    <div id="wfHistoryActions" style="padding:12px 24px;border-bottom:1px solid var(--border-color);display:none;"></div>
    <div id="wfHistoryTimeline" style="padding:20px 24px;"></div>
  </div>
</div>

</div><!-- /.nu-workflow-module -->

<!-- Load Mermaid.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.min.js"></script>

<style>
.wf-stage-pill {
  display:inline-flex;align-items:center;gap:6px;
  padding:7px 14px;border-radius:99px;
  font-size:12px;font-weight:600;white-space:nowrap;
  border:2px solid transparent;
  transition:box-shadow .12s;
}
.wf-stage-pill.start { border-style:dashed; }
.wf-stage-pill.end   { border-style:solid;  }
.wf-arrow {
  width:32px;height:2px;background:var(--border-color);flex-shrink:0;position:relative;
}
.wf-arrow::after {
  content:'';position:absolute;right:-1px;top:-4px;
  border:5px solid transparent;border-left-color:var(--border-color);
}
.wf-timeline-item { display:flex;gap:14px;padding-bottom:20px;position:relative; }
.wf-timeline-item::before {
  content:'';position:absolute;left:15px;top:28px;bottom:0;width:2px;
  background:var(--border-color);
}
.wf-timeline-item:last-child::before { display:none; }
.wf-timeline-dot {
  width:32px;height:32px;border-radius:50%;display:flex;align-items:center;
  justify-content:center;flex-shrink:0;font-size:13px;
}
</style>

<script>
/* =============================================================================
   WF — Workflow module controller
   Uses window.WF guard so re-injection by the SPA module loader is safe.
   ============================================================================= */
if (!window.WF) {
window.WF = (() => {
  const $ = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

  function toast(msg, type = 'info') {
    if (typeof nuToast === 'function') { nuToast(msg, type); return; }
    if (window.NuApp && typeof NuApp.toast === 'function') { NuApp.toast(msg, type); return; }
    console.log('[WF]', type, msg);
  }

  // Reload just this module inside the SPA content area (no full page reload)
  function _reloadModule() {
    WF._cleanup();
    if (window.NuApp && typeof NuApp.loadModule === 'function') {
      NuApp.loadModule('workflow');
    } else {
      location.reload(); // fallback only
    }
  }

  let _stages      = [];
  let _transitions = [];
  let _stageCount  = 0;
  let _transCount  = 0;
  let _currentWfId = null;
  var _wfCtx = { fields: [], table: '', roles: [], forFor: null };
  let _instancesWfId = null;
  let _loadedInstancesList = [];
  let _viewLayout  = 'list';

  function search(val) {
    const q = val.toLowerCase();
    document.querySelectorAll('.wf-card').forEach(el => {
      el.style.display = (el.dataset.name || '').includes(q) ? '' : 'none';
    });
  }

  function openNew() {
    _resetDrawer();
    $('wfDrawerTitle').textContent = 'New Workflow';
    $('wfDrawerCode').textContent  = '';
    _showDrawer();
  }

  async function openDetail(id) {
    _resetDrawer();
    try {
      const r = await fetch(`api/workflow.php?action=get&id=${id}`);
      const d = await r.json();
      if (!d.success) { toast(d.error, 'error'); return; }
      const wf = d.workflow;
      _currentWfId = parseInt(wf.wf_id);
      $('wfEditId').value       = wf.wf_id;
      $('wfName').value         = wf.wf_name;
      $('wfCode').value         = wf.wf_code;
      $('wfDescription').value  = wf.wf_description || '';
      $('wfFormCode').value     = wf.wf_form_code   || '';
      $('wfActive').checked     = wf.wf_active == 1;
      $('wfDrawerTitle').textContent = wf.wf_name;
      $('wfDrawerCode').textContent  = wf.wf_code;
      _stages      = d.stages      || [];
      _transitions = d.transitions || [];
      _renderStages();
      _renderTransitions();
      _loadContext();
    } catch (e) { toast('Failed to load workflow', 'error'); }
    _showDrawer();
  }

  async function saveAll() {
    const name = $('wfName').value.trim();
    if (!name) { toast('Workflow name is required', 'error'); return; }
    const payload = {
      wf_id:          $('wfEditId').value || null,
      wf_name:        name,
      wf_code:        $('wfCode').value.trim() || null,
      wf_description: $('wfDescription').value.trim(),
      wf_form_code:   $('wfFormCode').value.trim() || null,
      wf_active:      $('wfActive').checked ? 1 : 0,
    };
    try {
      const r = await fetch('api/workflow.php?action=save', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
      });
      const d = await r.json();
      if (!d.success) { toast(d.error, 'error'); return; }
      _currentWfId = d.wf_id;
      $('wfEditId').value = d.wf_id;
      toast('Workflow saved', 'success');

      // Save stages (save any new or existing updated stage rows)
      const errs = [], idMap = {};
      const stageRows = document.querySelectorAll('.wf-stage-row');
      for (const row of stageRows) {
        const res = await _saveStageRow(row, d.wf_id);
        if (res && !res.success) errs.push(res.error);
        else if (res && row.dataset.new === '1') idMap[row.dataset.id] = res.wfs_id;
      }
      // Transitions may point at stages that were only just created: translate their temporary ids.
      _transitions.forEach(t => {
        if (idMap[t.wft_from_id]) t.wft_from_id = idMap[t.wft_from_id];
        if (idMap[t.wft_to_id])   t.wft_to_id   = idMap[t.wft_to_id];
      });

      // Save transitions (save any new or existing updated transition rows)
      const transRows = document.querySelectorAll('.wf-trans-row');
      for (const row of transRows) {
        const res = await _saveTransRow(row, d.wf_id);
        if (res && !res.success) errs.push(res.error);
      }
      if (errs.length) { toast('Saved with problems: ' + errs.join('; '), 'error'); return; }

      // Close drawer then reload workflow module in-place (no full page reload)
      closeDrawer();
      setTimeout(_reloadModule, 300);
    } catch (e) { toast('Save failed: ' + e.message, 'error'); }
  }

  async function del(id, name) {
    if (!confirm(`Delete workflow "${name}"? This will also remove all stages, transitions, and history.`)) return;
    try {
      const r = await fetch(`api/workflow.php?action=delete&id=${id}`);
      const d = await r.json();
      if (!d.success) { toast(d.error, 'error'); return; }
      toast('Workflow deleted', 'success');
      setTimeout(_reloadModule, 400);
    } catch (e) { toast('Delete failed', 'error'); }
  }

  function addStage() {
    const id = 'new_' + (++_stageCount);
    const colors = ['#6366f1','#22c55e','#f59e0b','#06b6d4','#ec4899','#8b5cf6','#14b8a6','#f97316'];
    const color  = colors[(_stageCount - 1) % colors.length];
    _stages.push({ wfs_id: id, wfs_name: '', wfs_code: '', wfs_color: color, wfs_is_start: _stages.length === 0 ? 1 : 0, wfs_is_end: 0, wfs_order: _stages.length, wfs_sla_hours: '', wfs_role: '' });
    _renderStages();
    setTimeout(() => {
      const inputs = document.querySelectorAll('.wf-stage-row input[data-field="name"]');
      if (inputs.length) inputs[inputs.length - 1].focus();
    }, 50);
  }

  function _renderStages() {
    const pipeline = $('wfPipeline');
    if (_stages.length === 0) {
      pipeline.innerHTML = '<span style="font-size:12px;color:var(--text-tertiary);padding:8px;">No stages yet — click "Add Stage" to start.</span>';
    } else {
      pipeline.innerHTML = _stages.map((s, i) => {
        const isStart = s.wfs_is_start == 1, isEnd = s.wfs_is_end == 1;
        const name = s.wfs_name || 'Stage ' + (i + 1);
        const col  = s.wfs_color || '#6366f1';
        const cls  = isStart ? 'start' : isEnd ? 'end' : '';
        const arrow = i < _stages.length - 1 ? '<div class="wf-arrow"></div>' : '';
        return `<div class="wf-stage-pill ${cls}" style="background:${col}18;color:${col};border-color:${col}44;" title="${esc(name)}">${isStart ? '▶ ' : isEnd ? '■ ' : ''}${esc(name)}</div>${arrow}`;
      }).join('');
    }
    const list = $('wfStageList');
    if (_stages.length === 0) { list.innerHTML = ''; _renderMermaidDiagram(); return; }
    list.innerHTML = _stages.map((s, i) => {
      const isNew = String(s.wfs_id).startsWith('new_');
      return `<div class="wf-stage-row" data-idx="${i}" data-new="${isNew?1:0}" data-id="${esc(s.wfs_id)}"
           style="display:grid;grid-template-columns:16px auto 1fr 90px 80px 70px 50px 40px 32px;gap:8px;align-items:center;padding:8px;border-radius:6px;margin-bottom:6px;background:var(--bg-secondary);">
        <span style="color:var(--text-tertiary);cursor:grab;user-select:none;font-size:14px;">⠿</span>
        <input type="color" value="${esc(s.wfs_color)}" data-field="color" onchange="WF._patchStage(${i},'color',this.value)" style="width:28px;height:28px;border:none;border-radius:6px;cursor:pointer;padding:1px;background:none;">
        <input type="text" class="nu-input" value="${esc(s.wfs_name)}" data-field="name" placeholder="Stage name" oninput="WF._patchStage(${i},'name',this.value)" style="font-size:13px;padding:5px 8px;">
        <input type="text" class="nu-input" value="${esc(s.wfs_role||'')}" data-field="role" placeholder="Role" oninput="WF._patchStage(${i},'role',this.value)" style="font-size:12px;padding:5px 8px;">
        <input type="number" class="nu-input" value="${esc(s.wfs_sla_hours||'')}" data-field="sla" placeholder="SLA hrs" oninput="WF._patchStage(${i},'sla',this.value)" style="font-size:12px;padding:5px 8px;">
        <label style="font-size:11px;color:var(--text-tertiary);white-space:nowrap;cursor:pointer;display:flex;align-items:center;gap:3px;">
          <input type="checkbox" ${s.wfs_is_start==1?'checked':''} onchange="WF._patchStage(${i},'is_start',this.checked?1:0)" style="width:13px;height:13px;"> Start
        </label>
        <label style="font-size:11px;color:var(--text-tertiary);white-space:nowrap;cursor:pointer;display:flex;align-items:center;gap:3px;">
          <input type="checkbox" ${s.wfs_is_end==1?'checked':''} onchange="WF._patchStage(${i},'is_end',this.checked?1:0)" style="width:13px;height:13px;"> End
        </label>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="WF.openStageActions(${i})" style="padding:4px 7px;${_stageActCount(s)?'color:var(--primary);':''}" title="Actions to run when a record enters this stage${_stageActCount(s)?' ('+_stageActCount(s)+')':''}">⚡${_stageActCount(s)||''}</button>
        <button class="nu-btn nu-btn-danger nu-btn-sm" onclick="WF._deleteStage(${i})" style="padding:4px 7px;">×</button>
      </div>`;
    }).join('');
    _renderMermaidDiagram();
  }

  function _patchStage(idx, field, val) {
    if (!_stages[idx]) return;
    const map = { color:'wfs_color', name:'wfs_name', role:'wfs_role', sla:'wfs_sla_hours', is_start:'wfs_is_start', is_end:'wfs_is_end' };
    if (map[field]) _stages[idx][map[field]] = val;
    if (field === 'name' && (!_stages[idx].wfs_code || _stages[idx].wfs_code === '')) {
      _stages[idx].wfs_code = val.toLowerCase().replace(/[^a-z0-9]+/g, '_');
    }
    _renderStages();
  }

  async function _deleteStage(idx) {
    const stage = _stages[idx];
    if (!stage) return;
    if (!String(stage.wfs_id).startsWith('new_')) {
      if (!confirm('Delete stage "' + (stage.wfs_name || 'this stage') + '"? Transitions using it will also be removed.')) return;
      try {
        const r = await fetch(`api/workflow.php?action=delete_stage&id=${stage.wfs_id}`);
        const d = await r.json();
        if (!d.success) { toast(d.error, 'error'); return; }
      } catch (e) { toast('Delete failed', 'error'); return; }
    }
    _stages.splice(idx, 1);
    _renderStages();
    if (_currentWfId) _reloadTransitions(_currentWfId);
  }

  async function _saveStageRow(row, wfId) {
    const idx   = parseInt(row.dataset.idx);
    const stage = _stages[idx];
    if (!stage || !stage.wfs_name.trim()) return null;
    const payload = {
      wfs_wf_id:     wfId,
      wfs_id:        String(stage.wfs_id).startsWith('new_') ? null : stage.wfs_id,
      wfs_name:      stage.wfs_name,
      wfs_code:      stage.wfs_code || stage.wfs_name.toLowerCase().replace(/[^a-z0-9]+/g,'_'),
      wfs_color:     stage.wfs_color,
      wfs_is_start:  stage.wfs_is_start,
      wfs_is_end:    stage.wfs_is_end,
      wfs_order:     idx,
      wfs_sla_hours: stage.wfs_sla_hours || null,
      wfs_role:      stage.wfs_role || null,
      wfs_on_enter:  stage.wfs_on_enter || null,
    };
    try {
      const r = await fetch('api/workflow.php?action=save_stage', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload) });
      return await r.json();
    } catch (e) { return { success:false, error:'Network error saving stage' }; }
  }

  function addTransition() {
    if (!_currentWfId) { toast('Save the workflow first before adding transitions.', 'error'); return; }
    if (_stages.length < 2) { toast('You need at least 2 stages to create a transition.', 'error'); return; }
    _transitions.push({ wft_id: 'new_' + (++_transCount), wft_wf_id: _currentWfId, wft_from_id: '', wft_to_id: '', wft_action: 'advance', wft_label: 'Advance' });
    _renderTransitions();
  }

  async function _reloadTransitions(wfId) {
    try {
      const r = await fetch(`api/workflow.php?action=get&id=${wfId}`);
      const d = await r.json();
      if (d.success) { _transitions = d.transitions || []; _renderTransitions(); }
    } catch (e) {}
  }

  function _renderTransitions() {
    const list = $('wfTransitionList');
    if (_stages.length < 2) { list.innerHTML = '<p style="font-size:13px;color:var(--text-tertiary);text-align:center;padding:16px;">Add at least 2 stages first.</p>'; _renderMermaidDiagram(); return; }
    if (_transitions.length === 0) { list.innerHTML = '<p style="font-size:13px;color:var(--text-tertiary);text-align:center;padding:16px;">No transitions yet. Click "Add Transition" to connect stages.</p>'; _renderMermaidDiagram(); return; }
    const stageOptions = _stages.map(s => `<option value="${esc(s.wfs_id)}">${esc(s.wfs_name||'Unnamed')}</option>`).join('');
    list.innerHTML = _transitions.map((t, i) => {
      const isNew = String(t.wft_id).startsWith('new_');
      return `<div class="wf-trans-row-container" style="background:var(--bg-secondary);border:1px solid var(--border-color);border-radius:8px;padding:12px;margin-bottom:12px;">
        <div class="wf-trans-row" data-idx="${i}" data-new="${isNew?1:0}" data-id="${esc(t.wft_id)}"
             style="display:grid;grid-template-columns:1fr 80px 1fr 100px 32px;gap:8px;align-items:center;margin-bottom:8px;">
          <select class="nu-input" onchange="WF._patchTrans(${i},'from',this.value)" style="font-size:12px;padding:5px 8px;">
            <option value="">From stage…</option>${stageOptions.replace(`value="${esc(t.wft_from_id)}"`,`value="${esc(t.wft_from_id)}" selected`)}
          </select>
          <select class="nu-input" onchange="WF._patchTrans(${i},'action',this.value)" style="font-size:12px;padding:5px 8px;">
            <option value="advance" ${t.wft_action==='advance'?'selected':''}>→ Advance</option>
            <option value="reject"  ${t.wft_action==='reject' ?'selected':''}>✕ Reject</option>
            <option value="return"  ${t.wft_action==='return' ?'selected':''}>↩ Return</option>
            <option value="escalate"${t.wft_action==='escalate'?'selected':''}>↑ Escalate</option>
          </select>
          <select class="nu-input" onchange="WF._patchTrans(${i},'to',this.value)" style="font-size:12px;padding:5px 8px;">
            <option value="">To stage…</option>${stageOptions.replace(`value="${esc(t.wft_to_id)}"`,`value="${esc(t.wft_to_id)}" selected`)}
          </select>
          <input type="text" class="nu-input" value="${esc(t.wft_label)}" oninput="WF._patchTrans(${i},'label',this.value)" placeholder="Button label" style="font-size:12px;padding:5px 8px;">
          <button class="nu-btn nu-btn-danger nu-btn-sm" onclick="WF._deleteTrans(${i})" style="padding:4px 7px;">×</button>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;border-top:1px dashed var(--border-color);padding-top:8px;flex-wrap:wrap;">
          <div class="wf-badges">${_badgesHtml(t)}</div>
          <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="WF.openRule(${i})" style="font-size:12px;">⚙ Rules &amp; actions</button>
        </div>
      </div>`;
    }).join('');
    _renderMermaidDiagram();
  }

  function _patchTrans(idx, field, val) {
    if (!_transitions[idx]) return;
    const map = { from:'wft_from_id', to:'wft_to_id', action:'wft_action', label:'wft_label', condition:'wft_condition', hook:'wft_hook' };
    if (map[field]) _transitions[idx][map[field]] = val;
  }

  async function _deleteTrans(idx) {
    const t = _transitions[idx];
    if (!t) return;
    if (!String(t.wft_id).startsWith('new_')) {
      try {
        const r = await fetch(`api/workflow.php?action=delete_transition&id=${t.wft_id}`);
        const d = await r.json();
        if (!d.success) { toast(d.error, 'error'); return; }
      } catch (e) { toast('Delete failed', 'error'); return; }
    }
    _transitions.splice(idx, 1);
    _renderTransitions();
  }

  async function _saveTransRow(row, wfId) {
    const idx = parseInt(row.dataset.idx);
    const t   = _transitions[idx];
    if (!t || !t.wft_from_id || !t.wft_to_id) return null;
    const payload = {
      wft_wf_id:     wfId,
      wft_id:        String(t.wft_id).startsWith('new_') ? null : t.wft_id,
      wft_from_id:   t.wft_from_id,
      wft_to_id:     t.wft_to_id,
      wft_action:    t.wft_action || 'advance',
      wft_label:     t.wft_label || 'Advance',
      wft_condition: t.wft_condition || null,
      wft_hook:      t.wft_hook || null,
      wft_options:   t.wft_options || null,
    };
    try {
      const r = await fetch('api/workflow.php?action=save_transition', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
      });
      return await r.json();
    } catch (e) { return { success:false, error:'Network error saving transition' }; }
  }

  async function openInstances(wfId, wfName) {
    _instancesWfId = wfId;
    $('wfInstancesTitle').textContent = 'Instances — ' + wfName;
    $('wfInstancesPanel').style.display = 'block';
    $('wfInstancesPanel').scrollIntoView({ behavior:'smooth', block:'start' });
    await _loadInstances(wfId, '');
  }

  async function filterInstances() {
    if (!_instancesWfId) return;
    await _loadInstances(_instancesWfId, $('wfInstancesFilter').value);
  }

  async function _loadInstances(wfId, status) {
    $('wfInstancesContent').innerHTML = '<p style="font-size:13px;color:var(--text-tertiary);padding:12px;">Loading…</p>';
    try {
      const r = await fetch(`api/workflow.php?action=instances&wf_id=${wfId}&status=${encodeURIComponent(status)}`);
      const d = await r.json();
      if (!d.success) { $('wfInstancesContent').innerHTML = `<p style="color:var(--color-danger)">${esc(d.error)}</p>`; return; }
      _loadedInstancesList = d.instances || [];
      _renderInstances();
    } catch (e) { $('wfInstancesContent').innerHTML = `<p style="color:var(--color-danger);font-size:13px;">${esc(e.message)}</p>`; }
  }

  function setViewLayout(layout) {
    _viewLayout = layout;
    if (layout === 'kanban') {
      $('wfToggleList').classList.remove('active');
      $('wfToggleKanban').classList.add('active');
    } else {
      $('wfToggleKanban').classList.remove('active');
      $('wfToggleList').classList.add('active');
    }
    _renderInstances();
  }

  function _renderInstances() {
    const rows = _loadedInstancesList;
    $('wfInstancesMeta').textContent = rows.length + ' instance' + (rows.length !== 1 ? 's' : '');
    if (!rows.length) {
      $('wfInstancesContent').innerHTML = '<p style="font-size:13px;color:var(--text-tertiary);padding:12px 0;">No instances found.</p>';
      return;
    }

    if (_viewLayout === 'kanban') {
      _renderKanbanView(rows);
    } else {
      _renderListView(rows);
    }
  }

  function _renderListView(rows) {
    const statusColors = { active:'#f59e0b', completed:'#22c55e', rejected:'#ef4444', cancelled:'#6b7280' };
    let html = '<table class="nu-table" style="font-size:13px;"><thead><tr><th>#</th><th>Stage</th><th>Status</th><th>Record</th><th>Started By</th><th>Started</th><th>Actions</th></tr></thead><tbody>';
    rows.forEach(inst => {
      const col = statusColors[inst.wfi_status] || '#888';
      const sc  = inst.stage_color || '#6366f1';
      html += `<tr>
        <td style="font-weight:600;">#${esc(inst.wfi_id)}</td>
        <td><span style="background:${sc}18;color:${sc};padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600;">${esc(inst.stage_name)}</span></td>
        <td><span style="background:${col}18;color:${col};padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600;text-transform:capitalize;">${esc(inst.wfi_status)}</span></td>
        <td style="font-size:12px;color:var(--text-secondary);">${inst.wfi_record_table ? esc(inst.wfi_record_table)+' #'+esc(inst.wfi_record_id) : '—'}</td>
        <td style="font-size:12px;">${esc(inst.started_by_name||'—')}</td>
        <td style="font-size:11px;color:var(--text-tertiary);white-space:nowrap;">${esc((inst.wfi_started_at||'').substring(0,16))}</td>
        <td><button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="WF.openHistory(${inst.wfi_id})">Timeline</button></td>
      </tr>`;
    });
    $('wfInstancesContent').innerHTML = html + '</tbody></table>';
  }

  function _renderKanbanView(rows) {
    const statuses = [
      { key: 'active', label: 'Active', color: '#f59e0b', bg: '#f59e0b10', icon: '⚡' },
      { key: 'completed', label: 'Completed', color: '#22c55e', bg: '#22c55e10', icon: '✓' },
      { key: 'rejected', label: 'Rejected', color: '#ef4444', bg: '#ef444410', icon: '✕' },
      { key: 'cancelled', label: 'Cancelled', color: '#6b7280', bg: '#6b728010', icon: '○' }
    ];

    let html = `<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;overflow-x:auto;padding-bottom:12px;align-items:start;">`;

    statuses.forEach(col => {
      const colRows = rows.filter(r => r.wfi_status === col.key);
      html += `
      <div style="background:var(--bg-secondary);border:1px solid var(--border-color);border-radius:12px;padding:12px;min-height:300px;display:flex;flex-direction:column;gap:12px;">
        <div style="display:flex;justify-content:space-between;align-items:center;padding-bottom:8px;border-bottom:1px solid var(--border-color);">
          <div style="display:flex;align-items:center;gap:6px;font-weight:600;font-size:14px;color:${col.color};">
            <span>${col.icon}</span> <span>${col.label}</span>
          </div>
          <span style="background:${col.color}22;color:${col.color};padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600;">${colRows.length}</span>
        </div>
        <div style="display:flex;flex-direction:column;gap:10px;overflow-y:auto;max-height:500px;padding:2px;">
      `;

      if (colRows.length === 0) {
        html += `<div style="text-align:center;padding:24px 0;color:var(--text-tertiary);font-size:12px;">No items</div>`;
      } else {
        colRows.forEach(inst => {
          const sc = inst.stage_color || '#6366f1';
          const rec = inst.wfi_record_table ? `${esc(inst.wfi_record_table)} #${esc(inst.wfi_record_id)}` : 'No bound record';
          html += `
          <div class="nu-card" style="padding:12px;border:1px solid var(--border-color);background:var(--bg-primary);border-left:4px solid ${sc};margin:0;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;flex-direction:column;gap:8px;">
            <div style="display:flex;justify-content:space-between;align-items:start;">
              <span style="font-weight:700;font-size:13px;color:var(--text-primary);">#${inst.wfi_id}</span>
              <span style="background:${sc}12;color:${sc};padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600;">${esc(inst.stage_name)}</span>
            </div>
            <div style="font-size:12px;color:var(--text-secondary);font-family:monospace;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${rec}">
              📋 ${rec}
            </div>
            <div style="font-size:11px;color:var(--text-tertiary);display:flex;flex-direction:column;gap:2px;">
              <span>By: <b>${esc(inst.started_by_name||'—')}</b></span>
              <span>Started: ${esc((inst.wfi_started_at||'').substring(0,16))}</span>
            </div>
            <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="WF.openHistory(${inst.wfi_id})" style="padding:4px 0;font-size:11px;width:100%;margin-top:4px;">
              View Timeline
            </button>
          </div>
          `;
        });
      }

      html += `
        </div>
      </div>
      `;
    });

    html += `</div>`;
    $('wfInstancesContent').innerHTML = html;
  }

  async function openHistory(instanceId) {
    $('wfHistoryModal').style.display = 'flex';
    $('wfHistoryTimeline').innerHTML  = '<p style="font-size:13px;color:var(--text-tertiary);">Loading…</p>';
    $('wfHistoryActions').style.display = 'none';
    $('wfHistoryStage').innerHTML = '';
    try {
      const r = await fetch(`api/workflow.php?action=history&instance_id=${instanceId}`);
      const d = await r.json();
      if (!d.success) { $('wfHistoryTimeline').innerHTML = `<p style="color:var(--color-danger);">${esc(d.error)}</p>`; return; }
      const inst = d.instance;
      $('wfHistoryTitle').textContent = `Instance #${inst.wfi_id} — ${inst.wf_name}`;
      $('wfHistorySub').textContent   = inst.wf_code + (inst.wfi_record_table ? `  ·  ${inst.wfi_record_table} #${inst.wfi_record_id}` : '');
      const sc = inst.stage_color || '#6366f1';
      $('wfHistoryStage').innerHTML = `<span style="font-size:12px;color:var(--text-tertiary);margin-right:6px;">Current stage:</span>
        <span style="background:${sc}18;color:${sc};padding:3px 12px;border-radius:99px;font-size:12px;font-weight:600;">${esc(inst.stage_name)}</span>
        <span style="margin-left:8px;font-size:12px;color:var(--text-tertiary);">${esc(inst.wfi_status)}</span>`;
      if (inst.wfi_status === 'active') {
        const manual = (d.transitions || []).filter(t => !t.auto);
        const autos  = (d.transitions || []).filter(t => t.auto);
        const ac = { advance:'nu-btn-primary', reject:'nu-btn-danger', return:'nu-btn-ghost', escalate:'nu-btn-ghost' };
        let ah = `<div style="font-size:12px;color:var(--text-tertiary);margin-bottom:8px;">Available actions:</div><div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px;">`;
        manual.forEach(t => {
          ah += `<button class="nu-btn ${ac[t.wft_action]||'nu-btn-ghost'} nu-btn-sm" ${t.can_run ? '' : 'disabled'} title="${esc(t.can_run ? '' : t.blocked_reason)}"
                   onclick="WF.doTransition(${instanceId},${t.wft_id},'${esc(t.wft_label).replace(/'/g, '&#39;')}',${t.require_comment ? 1 : 0},'${esc(t.confirm).replace(/'/g, '&#39;')}')">${esc(t.wft_label)}${t.require_comment ? ' 💬' : ''}</button>`;
        });
        ah += `<button class="nu-btn nu-btn-danger nu-btn-sm" onclick="WF.doReject(${instanceId})">✕ Reject</button>`;
        if (autos.length) ah += `<button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="WF.recheck(${instanceId})" title="Run any automatic steps whose rule is now true">🤖 Re-check automation</button>`;
        ah += '</div>';
        const blocked = manual.filter(t => !t.can_run);
        if (blocked.length) ah += `<div style="font-size:11.5px;color:var(--text-tertiary);margin-bottom:8px;">${blocked.map(t => `<div>🔒 <b>${esc(t.wft_label)}</b>: ${esc(t.blocked_reason)}</div>`).join('')}</div>`;
        if (autos.length) ah += `<div style="font-size:11.5px;color:var(--text-tertiary);margin-bottom:8px;">${autos.map(t => `<div>🤖 <b>${esc(t.wft_label)}</b> runs automatically${t.can_run ? '' : ' once its rule is true'}</div>`).join('')}</div>`;
        const needC = manual.some(t => t.require_comment);
        ah += `<div style="display:flex;gap:8px;"><input type="text" class="nu-input" id="wfActionComment" placeholder="Comment${needC ? ' (required for steps marked 💬)' : ' (optional)'}" style="font-size:12px;padding:5px 10px;flex:1;"></div>`;
        $('wfHistoryActions').innerHTML = ah;
        $('wfHistoryActions').style.display = 'block';
      }
      const history = d.history || [];
      const ai = { start:'▶', advance:'→', reject:'✕', cancel:'○', return:'↩', escalate:'↑', completed:'✓', sla_breach:'⏱' };
      const ac2 = { start:'#6366f1', advance:'#22c55e', reject:'#ef4444', cancel:'#6b7280', return:'#f59e0b', escalate:'#06b6d4', completed:'#22c55e', sla_breach:'#f59e0b' };
      let tl = history.length ? history.map(h => {
        const col = ac2[h.wfh_action]||'#888', icon = ai[h.wfh_action]||'•';
        const from = h.from_stage ? `<span style="color:var(--text-tertiary);">${esc(h.from_stage)}</span> → ` : '';
        return `<div class="wf-timeline-item">
          <div class="wf-timeline-dot" style="background:${col}18;color:${col};">${icon}</div>
          <div style="flex:1;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
              <span style="font-size:13px;font-weight:600;text-transform:capitalize;">${h.wfh_action === 'sla_breach' ? 'SLA exceeded' : esc(String(h.wfh_action).replace('_',' '))}</span>
              <span style="font-size:12px;color:var(--text-secondary);">${from}${esc(h.to_stage)}</span>
              <span style="font-size:11px;color:var(--text-tertiary);margin-left:auto;">${(h.wfh_acted_at||'').substring(0,16)}</span>
            </div>
            <div style="font-size:12px;color:var(--text-secondary);margin-top:2px;">By <b>${esc(h.actor_name||'System')}</b>${h.wfh_comment?' — <i>'+esc(h.wfh_comment)+'</i>':''}</div>
          </div>
        </div>`;
      }).join('') : '<p style="font-size:13px;color:var(--text-tertiary);">No history yet.</p>';

      const log = d.actions || [];
      if (log.length) {
        const typeName = { update_record:'Updated record', create_record:'Added record', send_email:'Email', notify_user:'Notification', http_request:'Web request', call_webhook:'Webhook', run_procedure:'Procedure', call_agent:'AI agent' };
        tl += `<div style="margin-top:8px;border-top:1px solid var(--border-color);padding-top:14px;"><div style="font-size:12px;font-weight:700;color:var(--text-secondary);margin-bottom:6px;">⚡ Automation log</div><div class="wf-log">` +
          log.map(l => {
            const ch = (l.wal_changes || []).slice(0, 3).map(c => `<div style="font-family:ui-monospace,monospace;font-size:11px;color:var(--text-tertiary);">${esc(c.table)}${c.id != null ? ' #' + esc(c.id) : ''}: ` +
              Object.keys(c.after || {}).map(k => `${esc(k)} ${esc(c.before && c.before[k] != null ? c.before[k] : '∅')} → <b>${esc(c.after[k] == null ? '∅' : c.after[k])}</b>`).join(', ') + '</div>').join('');
            return `<div class="wf-log-item is-${esc(l.wal_status)}"><b>${esc(typeName[l.wal_type] || l.wal_type)}</b>${l.wal_label ? ' — ' + esc(l.wal_label) : ''}
              <span style="color:var(--text-tertiary);">· ${esc(l.wal_status)} · ${(l.wal_at||'').substring(0,16)}</span><div>${esc(l.wal_message || '')}</div>${ch}</div>`;
          }).join('') + '</div></div>';
      }
      $('wfHistoryTimeline').innerHTML = tl;
    } catch (e) { $('wfHistoryTimeline').innerHTML = `<p style="color:var(--color-danger);font-size:13px;">${esc(e.message)}</p>`; }
  }

  async function doTransition(instanceId, transitionId, label, requireComment, confirmMsg) {
    const input = $('wfActionComment');
    const comment = (input?.value||'').trim();
    if (requireComment && !comment) { toast(`“${label}” needs a comment.`, 'error'); input?.focus(); return; }
    if (!confirm(confirmMsg || `Perform "${label}" on instance #${instanceId}?`)) return;
    try {
      const r = await fetch('api/workflow.php?action=advance', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ instance_id:instanceId, transition_id:transitionId, comment }) });
      const d = await r.json();
      if (!d.success) { toast(d.error,'error'); return; }
      toast('Transition applied','success');
      await openHistory(instanceId);
      if (_instancesWfId) await _loadInstances(_instancesWfId, $('wfInstancesFilter')?.value||'');
    } catch(e) { toast('Action failed','error'); }
  }

  async function recheck(instanceId) {
    try {
      const r = await fetch('api/workflow.php?action=evaluate', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ instance_id:instanceId }) });
      const d = await r.json();
      if (!d.success) { toast(d.error,'error'); return; }
      toast(d.steps ? `${d.steps} automatic step(s) ran` : 'Nothing to run yet', d.steps ? 'success' : 'info');
      await openHistory(instanceId);
      if (_instancesWfId) await _loadInstances(_instancesWfId, $('wfInstancesFilter')?.value||'');
    } catch(e) { toast('Re-check failed','error'); }
  }

  async function doReject(instanceId) {
    const comment = ($('wfActionComment')?.value||'').trim();
    if (!confirm(`Reject instance #${instanceId}? This cannot be undone.`)) return;
    try {
      const r = await fetch('api/workflow.php?action=reject', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ instance_id:instanceId, comment }) });
      const d = await r.json();
      if (!d.success) { toast(d.error,'error'); return; }
      toast('Instance rejected','success');
      await openHistory(instanceId);
      if (_instancesWfId) await _loadInstances(_instancesWfId, $('wfInstancesFilter')?.value||'');
    } catch(e) { toast('Action failed','error'); }
  }

  function closeHistory() { $('wfHistoryModal').style.display = 'none'; }

  function _showDrawer() {
    $('wfDrawerOverlay').style.display = 'block';
    $('wfDrawer').style.display = 'block';
    requestAnimationFrame(() => { $('wfDrawer').style.transform = 'translateX(0)'; });
    document.body.style.overflow = 'hidden';
  }

  function closeDrawer() {
    $('wfDrawer').style.transform = 'translateX(100%)';
    setTimeout(() => {
      $('wfDrawer').style.display = 'none';
      $('wfDrawerOverlay').style.display = 'none';
      document.body.style.overflow = '';
    }, 260);
  }

  function _resetDrawer() {
    ['wfEditId','wfName','wfCode','wfDescription','wfFormCode'].forEach(id => { if($(id)) $(id).value=''; });
    if($('wfActive')) $('wfActive').checked = true;
    _stages=[]; _transitions=[]; _stageCount=0; _transCount=0; _currentWfId=null; _wfCtx.forFor=null;
    _renderStages(); _renderTransitions();
  }

  function _renderMermaidDiagram(walkedStages = [], walkedTransitions = []) {
    if (typeof mermaid === 'undefined') {
      const container = $('wfMermaidDiagram');
      if (container) container.innerHTML = '<div style="color:var(--text-tertiary);font-size:13px;">Mermaid.js loading...</div>';
      return;
    }

    if (_stages.length === 0) {
      $('wfMermaidDiagram').innerHTML = '<div style="color:var(--text-tertiary);font-size:13px;text-align:center;padding:24px 0;">No stages added yet. Add stages below to auto-generate the diagram.</div>';
      return;
    }

    try {
      // Setup unique IDs mapping to prevent any weird character issues
      const idMap = {};
      _stages.forEach((s, idx) => {
        idMap[s.wfs_id] = `stage_${idx}`;
      });

      let diagramText = 'graph LR\n';

      // Theme colors & classes setup
      const isDark = document.body.classList.contains('dark-theme') || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);

      // Node definitions with Custom Colors
      _stages.forEach((s, idx) => {
        const id = idMap[s.wfs_id];
        const name = s.wfs_name || `Stage ${idx + 1}`;
        const isStart = s.wfs_is_start == 1;
        const isEnd = s.wfs_is_end == 1;

        let shapeLeft = '[', shapeRight = ']';
        if (isStart) { shapeLeft = '(('; shapeRight = '))'; }
        else if (isEnd) { shapeLeft = '{'; shapeRight = '}'; }

        diagramText += `  ${id}${shapeLeft}"${name}"${shapeRight}\n`;
      });

      // Transitions (Links)
      let linkIndex = 0;
      const linkStyles = [];
      _transitions.forEach((t) => {
        const fromId = idMap[t.wft_from_id];
        const toId = idMap[t.wft_to_id];
        if (fromId && toId) {
          const label = t.wft_label ? `|"${t.wft_label}"|` : '';
          diagramText += `  ${fromId} -->${label} ${toId}\n`;

          const isWalkedLink = walkedTransitions.some(wt => wt.wft_from_id == t.wft_from_id && wt.wft_to_id == t.wft_to_id);

          // Action mapping to link style colors
          let color = '#6366f1'; // advance (blue-ish)
          if (t.wft_action === 'reject') color = '#ef4444'; // red
          else if (t.wft_action === 'return') color = '#f59e0b'; // orange/yellow
          else if (t.wft_action === 'escalate') color = '#06b6d4'; // cyan

          if (walkedTransitions.length > 0) {
            if (isWalkedLink) {
              linkStyles.push(`linkStyle ${linkIndex} stroke:${color},stroke-width:4px;`);
            } else {
              linkStyles.push(`linkStyle ${linkIndex} stroke:${color},stroke-width:1.5px,opacity:0.35;`);
            }
          } else {
            linkStyles.push(`linkStyle ${linkIndex} stroke:${color},stroke-width:2px;`);
          }
          linkIndex++;
        }
      });

      // Style Node fills/borders based on stage colors
      _stages.forEach((s, idx) => {
        const id = idMap[s.wfs_id];
        const color = s.wfs_color || '#6366f1';
        const isWalked = walkedStages.includes(s.wfs_id);
        const borderStyle = isWalked ? 'stroke-width:4px' : 'stroke-width:2px';
        const fillStyle = isWalked ? `fill:${color}33` : `fill:${color}18`;
        diagramText += `  style ${id} ${fillStyle},stroke:${color},${borderStyle}\n`;
      });

      // Append link styles
      linkStyles.forEach(style => {
        diagramText += `  ${style}\n`;
      });

      // Render Mermaid
      const elementId = 'mermaid-temp-' + Date.now();
      $('wfMermaidDiagram').innerHTML = `<div id="${elementId}">${diagramText}</div>`;

      mermaid.initialize({
        startOnLoad: false,
        theme: isDark ? 'dark' : 'default',
        securityLevel: 'loose',
        flowchart: { useMaxWidth: true, htmlLabels: true }
      });

      mermaid.run({
        nodes: [document.getElementById(elementId)]
      });

    } catch (e) {
      console.error('Mermaid render error:', e);
      $('wfMermaidDiagram').innerHTML = '<div style="color:var(--color-danger);font-size:12px;">Visual Diagram generation error. Please check stage connections.</div>';
    }
  }

  function toggleSimulation() {
    const panel = $('wfSimulationPanel');
    const btn = $('btnToggleSim');
    if (panel.style.display === 'none') {
      panel.style.display = 'block';
      btn.textContent = 'Hide Simulator';
    } else {
      panel.style.display = 'none';
      btn.textContent = 'Show Simulator';
      resetSimulation();
    }
  }

  function resetSimulation() {
    $('wfSimResults').innerHTML = 'Ready to simulate. Click "Run Simulation".';
    _renderMermaidDiagram();
  }

  function runSimulation() {
    let mockData = {};
    try {
      const txt = $('wfSimData').value.trim();
      if (txt) mockData = JSON.parse(txt);
    } catch(e) {
      $('wfSimResults').innerHTML = '<span style="color:var(--color-danger)">Error: Invalid JSON mock data.</span>';
      return;
    }

    const startStage = _stages.find(s => s.wfs_is_start == 1);
    if (!startStage) {
      $('wfSimResults').innerHTML = '<span style="color:var(--color-danger)">Error: No start stage defined in this workflow.</span>';
      return;
    }

    let currentStage = startStage;
    const walkedStages = [currentStage.wfs_id];
    const walkedTransitions = [];
    const log = [];
    log.push(`▶ Start: <b>${esc(currentStage.wfs_name)}</b>`);

    let steps = 0;
    const maxSteps = 20;

    while (steps < maxSteps) {
      steps++;
      if (currentStage.wfs_is_end == 1) {
        log.push(`✓ Completed at End Stage: <b>${esc(currentStage.wfs_name)}</b>`);
        break;
      }

      const outs = _transitions.filter(t => t.wft_from_id == currentStage.wfs_id);
      if (outs.length === 0) {
        log.push(`⚠ Stuck: No outgoing transitions from <b>${esc(currentStage.wfs_name)}</b>`);
        break;
      }

      let matchedTrans = null;
      for (const t of outs) {
        const cond = t.wft_condition || '';
        if (evaluateCondition(cond, mockData)) {
          matchedTrans = t;
          break;
        }
      }

      if (!matchedTrans) {
        log.push(`✕ Stopped: No transition conditions met from <b>${esc(currentStage.wfs_name)}</b>`);
        break;
      }

      const nextStage = _stages.find(s => s.wfs_id == matchedTrans.wft_to_id);
      if (!nextStage) {
        log.push(`✕ Error: Target stage not found for transition.`);
        break;
      }

      walkedTransitions.push(matchedTrans);
      walkedStages.push(nextStage.wfs_id);
      log.push(`→ ${matchedTrans.wft_label || 'Advance'} ➔ <b>${esc(nextStage.wfs_name)}</b>`);
      currentStage = nextStage;
    }

    if (steps >= maxSteps) {
      log.push(`⚠ Limit reached: Stopped after ${maxSteps} simulation steps to prevent infinite loop.`);
    }

    $('wfSimResults').innerHTML = log.join('<br>');
    _renderMermaidDiagram(walkedStages, walkedTransitions);
  }

  function evaluateCondition(cond, data) {
    if (window.NuWfBuilder) return NuWfBuilder.evalCondition(cond, data);
    if (!cond || String(cond).trim() === '') return true;
    try {
      const keys = Object.keys(data), vals = Object.values(data);
      return !!(new Function(...keys, `return (${cond});`))(...vals);
    } catch (e) { return false; }
  }

  // ── Rules & actions modal ──────────────────────────────────────────────
  let _rule = null, _condEd = null, _actEd = null, _schemaP = null;

  function _schema() {
    if (!_schemaP) _schemaP = fetch('api/workflow.php?action=schema').then(r => r.json()).then(d => d.success ? d.tables : []).catch(() => []);
    return _schemaP;
  }
  async function _loadContext() {
    if (_wfCtx.forFor === _currentWfId && _currentWfId) return;
    _wfCtx = { fields: [], table: '', roles: [], forFor: _currentWfId };
    try {
      const r = await fetch(`api/workflow.php?action=context&wf_id=${_currentWfId || 0}`);
      const d = await r.json();
      if (d.success) _wfCtx = { fields: (d.fields || []).map(f => f.name), table: d.table || '', roles: d.roles || [], forFor: _currentWfId };
    } catch (e) { /* free-text fields still work */ }
  }
  function _opts(t) {
    const o = t.wft_options;
    if (!o) return {};
    if (typeof o === 'object') return o;
    try { return JSON.parse(o) || {}; } catch (e) { return {}; }
  }
  function _stageActCount(s) { return window.NuWfBuilder ? NuWfBuilder.normalizeActions(s.wfs_on_enter).length : 0; }
  function _badgesHtml(t) {
    const B = window.NuWfBuilder; if (!B) return '';
    const o = _opts(t), out = [];
    const cond = B.describeCondition(t.wft_condition);
    if (cond) out.push(`<span class="wf-badge is-set" title="${esc(cond)}">🔎 ${esc(cond)}</span>`);
    const n = B.normalizeActions(t.wft_hook).length;
    out.push(n ? `<span class="wf-badge is-set">⚡ ${n} action${n > 1 ? 's' : ''}</span>` : `<span class="wf-badge">no actions</span>`);
    if (o.auto) out.push('<span class="wf-badge is-set">🤖 automatic</span>');
    if (o.on_sla) out.push('<span class="wf-badge is-set">⏱ on SLA breach</span>');
    if (o.require_comment) out.push('<span class="wf-badge">💬 comment required</span>');
    if ((o.roles || []).length) out.push(`<span class="wf-badge">🔒 ${esc(o.roles.join(', '))}</span>`);
    if (!cond && !o.auto && !(o.roles || []).length) out.unshift('<span class="wf-badge">always available</span>');
    return out.join('');
  }

  function ruleTab(tab) {
    document.querySelectorAll('#wfRuleTabs button').forEach(b => b.classList.toggle('is-active', b.dataset.tab === tab));
    $('wfPaneWhen').hidden = tab !== 'when';
    $('wfPaneThen').hidden = tab !== 'then';
  }

  function _destroyEditors() {
    if (_condEd) { _condEd.destroy(); _condEd = null; }
    if (_actEd)  { _actEd.destroy();  _actEd = null; }
  }

  async function _preview(actions, recId, toStageId) {
    if (!_currentWfId) throw new Error('Save the workflow first so the preview can use its record.');
    const r = await fetch('api/workflow.php?action=preview_actions', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ wf_id: _currentWfId, actions, record_id: recId || '', to_stage_id: toStageId || 0 }) });
    const d = await r.json();
    if (!d.success) throw new Error(d.error || 'Preview failed');
    return d;
  }

  async function openRule(idx) {
    const t = _transitions[idx]; if (!t) return;
    if (!t.wft_from_id || !t.wft_to_id) { toast('Choose the From and To stages first.', 'error'); return; }
    await _loadContext();
    _destroyEditors();
    _rule = { mode: 'transition', idx };
    const from = _stages.find(s => s.wfs_id == t.wft_from_id), to = _stages.find(s => s.wfs_id == t.wft_to_id);
    $('wfRuleTitle').textContent = `Rules & actions — ${t.wft_label || 'Step'}`;
    $('wfRuleSub').textContent = `${from ? from.wfs_name : '?'}  →  ${to ? to.wfs_name : '?'}`;
    $('wfRuleTabs').style.display = '';
    $('wfThenHint').innerHTML = 'These run, in order, right after the step is taken. Use <b>Update a record</b> to change fields in this record or in <i>any other table</i> — by id, by field match, or per scenario.';
    const o = _opts(t);
    $('wfOptComment').checked = !!o.require_comment; $('wfOptAuto').checked = !!o.auto; $('wfOptSla').checked = !!o.on_sla;
    $('wfOptConfirm').value = o.confirm || '';
    $('wfRoles').innerHTML = _wfCtx.roles.length
      ? _wfCtx.roles.map(r => `<label><input type="checkbox" data-role="${esc(r.role_code)}" ${(o.roles || []).includes(r.role_code) ? 'checked' : ''}>${esc(r.role_name || r.role_code)}</label>`).join('')
      : '<span class="wfb-muted" style="font-size:12px;">No roles found.</span>';
    $('wfRoles').querySelectorAll('input').forEach(i => { i.style.display = 'none'; });
    $('wfTestRes').textContent = ''; $('wfTestRes').className = 'wfb-test-res';
    _condEd = NuWfBuilder.mountConditions($('wfCondHost'), { value: t.wft_condition, fields: _wfCtx.fields });
    _actEd  = NuWfBuilder.mountActions($('wfActHost'), { value: t.wft_hook, fields: _wfCtx.fields, tableName: _wfCtx.table, schema: _schema, preview: (a, rec) => _preview(a, rec, t.wft_to_id) });
    $('wfPaneWhen').style.display = ''; ruleTab('when');
    $('wfRuleErr').textContent = '';
    $('wfRuleModal').classList.add('is-open');
  }

  async function openStageActions(idx) {
    const s = _stages[idx]; if (!s) return;
    if (!_currentWfId) { toast('Save the workflow first.', 'error'); return; }
    await _loadContext();
    _destroyEditors();
    _rule = { mode: 'stage', idx };
    $('wfRuleTitle').textContent = `When a record enters “${s.wfs_name || 'this stage'}”`;
    $('wfRuleSub').textContent = 'Runs when the workflow starts here or any step arrives here';
    $('wfRuleTabs').style.display = 'none';
    $('wfThenHint').innerHTML = 'Actions that run every time an instance <b>enters this stage</b> — handy for setting a status field, notifying someone or creating a follow-up record.';
    $('wfRuleErr').textContent = '';
    _actEd = NuWfBuilder.mountActions($('wfActHost'), { value: s.wfs_on_enter, fields: _wfCtx.fields, tableName: _wfCtx.table, schema: _schema, preview: (a, rec) => _preview(a, rec, s.wfs_id) });
    ruleTab('then'); $('wfPaneWhen').style.display = 'none';
    $('wfRuleModal').classList.add('is-open');
  }

  function closeRule() {
    $('wfRuleModal').classList.remove('is-open');
    _destroyEditors();
    $('wfPaneWhen').style.display = '';
    _rule = null;
  }

  function applyRule() {
    if (!_rule) return;
    const actions = _actEd ? _actEd.getValue() : null;
    if (_rule.mode === 'stage') {
      _stages[_rule.idx].wfs_on_enter = actions;
      closeRule(); _renderStages();
      toast('Stage actions applied — click Save to keep them.', 'success');
      return;
    }
    const t = _transitions[_rule.idx];
    const roles = [...document.querySelectorAll('#wfRoles input:checked')].map(i => i.dataset.role);
    const o = {};
    if ($('wfOptComment').checked) o.require_comment = true;
    if ($('wfOptAuto').checked) o.auto = true;
    if ($('wfOptSla').checked) o.on_sla = true;
    if (roles.length) o.roles = roles;
    if ($('wfOptConfirm').value.trim()) o.confirm = $('wfOptConfirm').value.trim();
    if (o.auto && o.require_comment) { $('wfRuleErr').textContent = 'An automatic step cannot also require a comment.'; return; }
    t.wft_condition = _condEd ? _condEd.getValue() : null;
    t.wft_hook = actions;
    t.wft_options = Object.keys(o).length ? o : null;
    closeRule(); _renderTransitions();
    toast('Rules & actions applied — click Save to keep them.', 'success');
  }

  async function testRule() {
    const res = $('wfTestRes');
    if (!_currentWfId) { res.textContent = 'Save the workflow first.'; res.className = 'wfb-test-res bad'; return; }
    res.textContent = 'Testing…'; res.className = 'wfb-test-res';
    try {
      const r = await fetch('api/workflow.php?action=test_condition', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ wf_id: _currentWfId, condition: _condEd.getValue(), record_id: $('wfTestRec').value.trim() }) });
      const d = await r.json();
      if (!d.success) throw new Error(d.error);
      const using = d.record_id ? ` (${d.table} #${d.record_id})` : ' (no sample record found)';
      res.textContent = (d.pass ? '✓ The rule passes' : '✕ ' + (d.reason || 'The rule does not pass')) + using;
      res.className = 'wfb-test-res ' + (d.pass ? 'ok' : 'bad');
    } catch (e) { res.textContent = e.message; res.className = 'wfb-test-res bad'; }
  }

  // ── duplicate / export / import ─────────────────────────────────────────
  async function duplicate(id) {
    try {
      const r = await fetch(`api/workflow.php?action=duplicate&id=${id}`); const d = await r.json();
      if (!d.success) { toast(d.error, 'error'); return; }
      toast('Workflow duplicated', 'success'); setTimeout(_reloadModule, 350);
    } catch (e) { toast('Duplicate failed', 'error'); }
  }
  async function exportWf(id) {
    try {
      const r = await fetch(`api/workflow.php?action=export&id=${id}`); const d = await r.json();
      if (!d.success) { toast(d.error, 'error'); return; }
      const blob = new Blob([JSON.stringify(d.bundle, null, 2)], { type: 'application/json' });
      const a = document.createElement('a'); a.href = URL.createObjectURL(blob);
      a.download = `workflow_${d.bundle.workflow.wf_code || id}.json`; document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(a.href);
    } catch (e) { toast('Export failed', 'error'); }
  }
  async function importFile(input) {
    const f = input.files && input.files[0]; input.value = '';
    if (!f) return;
    try {
      const bundle = JSON.parse(await f.text());
      const r = await fetch('api/workflow.php?action=import', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ bundle }) });
      const d = await r.json();
      if (!d.success) { toast(d.error, 'error'); return; }
      toast('Workflow imported', 'success'); setTimeout(_reloadModule, 350);
    } catch (e) { toast('Import failed: ' + e.message, 'error'); }
  }

  function autoCode() {
    if ($('wfEditId').value) return;
    const name = $('wfName').value;
    $('wfCode').value = name.toLowerCase().replace(/[^a-z0-9]+/g,'_').replace(/^_|_$/g,'');
    $('wfDrawerTitle').textContent = name || 'New Workflow';
  }

  // Clean up global WF reference so next loadModule() re-initialises it fresh
  function _cleanup() {
    window.WF = null;
  }

  return {
    search, openNew, openDetail, saveAll,
    delete: del, closeDrawer, autoCode,
    addStage, _patchStage, _deleteStage, _saveStageRow,
    addTransition, _patchTrans, _deleteTrans,
    openInstances, filterInstances,
    openHistory, doTransition, doReject, recheck, closeHistory,
    toggleSimulation, resetSimulation, runSimulation,
    _renderStages, _renderTransitions, _renderMermaidDiagram, _cleanup,
    openRule, openStageActions, closeRule, applyRule, ruleTab, testRule,
    duplicate, exportWf, importFile, _stageActCount, _badgesHtml
  };
})();
} // end if (!window.WF)

document.getElementById('wfHistoryModal')?.addEventListener('click', function(e) {
  if (e.target === this) WF.closeHistory();
});
</script>
