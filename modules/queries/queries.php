<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

$db      = NuDatabase::getInstance();
$pid     = class_exists('ProjectContext') ? ProjectContext::getId() : 1;
$queries = $db->fetchAll("SELECT * FROM nu_queries WHERE query_active = 1 AND project_id = ? ORDER BY query_updated_at DESC", [$pid]);
$canBuild = $auth->hasPermission('queries', 'build');

$h = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$ico = static function (string $d, int $s = 14): string {
    return '<svg width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
};
$I = [
    'plus'   => '<path d="M12 5v14M5 12h14"/>',
    'play'   => '<polygon points="6 3 20 12 6 21 6 3" fill="currentColor"/>',
    'back'   => '<path d="m15 18-6-6 6-6"/>',
    'search' => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
    'db'     => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/>',
    'copy'   => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
    'edit'   => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
    'trash'  => '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>',
    'wand'   => '<path d="m15 4 5 5L8 21l-5 0 0-5Z"/><path d="m13 6 5 5"/>',
    'panel'  => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18"/>',
];
?>

<div class="nu-queries-module nu-qb">

  <!-- ══ LIST VIEW ═════════════════════════════════════════════════════ -->
  <div id="qbListPanel">
    <div class="qb-head">
      <div>
        <h2 class="qb-title">Query Builder</h2>
        <p class="qb-sub">Build, test and save reusable SQL queries <span class="nu-sx-chip" id="qbCount"><?= count($queries) ?></span></p>
      </div>
      <div class="qb-head-actions">
        <?php if (!empty($queries)): ?>
        <label class="qb-search">
          <?= $ico($I['search']) ?>
          <input type="search" id="qbSearch" placeholder="Search queries…" aria-label="Search queries" oninput="QB.filterList(this.value)">
        </label>
        <?php endif; ?>
        <?php if ($canBuild): ?>
        <button class="nu-btn nu-btn-primary" onclick="QB.openNew()"><?= $ico($I['plus']) ?><span>New query</span></button>
        <?php endif; ?>
      </div>
    </div>

    <div class="qb-cards" id="qbCards">
      <?php if (empty($queries)): ?>
      <div class="qb-empty">
        <?= $ico($I['db'], 40) ?>
        <strong>No queries yet</strong>
        <span>Write a SELECT, test it against live data, and save it to reuse it in reports and dashboards.</span>
        <?php if ($canBuild): ?><button class="nu-btn nu-btn-primary" onclick="QB.openNew()"><?= $ico($I['plus']) ?><span>Create your first query</span></button><?php endif; ?>
      </div>
      <?php else: foreach ($queries as $q):
          $params = json_decode((string)($q['query_parameters'] ?? '{}'), true);
          $pcount = is_array($params) ? count($params) : 0;
          $sqlOne = preg_replace('/\s+/', ' ', trim((string)$q['query_sql']));
          $updated = !empty($q['query_updated_at']) ? date('M j, Y', strtotime((string)$q['query_updated_at'])) : '';
      ?>
      <article class="qb-card" data-id="<?= (int)$q['query_id'] ?>" data-name="<?= $h($q['query_name']) ?>"
               data-search="<?= $h(strtolower($q['query_name'] . ' ' . $q['query_code'] . ' ' . ($q['query_description'] ?? '') . ' ' . $sqlOne)) ?>">
        <header class="qb-card-head">
          <div class="qb-card-title">
            <h3 title="<?= $h($q['query_name']) ?>"><?= $h($q['query_name']) ?></h3>
            <code><?= $h($q['query_code']) ?></code>
          </div>
          <?php if ($pcount): ?><span class="nu-sx-chip is-info" title="Runtime parameters"><?= $pcount ?> param<?= $pcount === 1 ? '' : 's' ?></span><?php endif; ?>
        </header>
        <?php if (!empty($q['query_description'])): ?><p class="qb-card-desc"><?= $h($q['query_description']) ?></p><?php endif; ?>
        <pre class="qb-card-sql" title="<?= $h(substr($sqlOne, 0, 400)) ?>"><?= $h(substr($sqlOne, 0, 160)) . (strlen($sqlOne) > 160 ? '…' : '') ?></pre>
        <footer class="qb-card-foot">
          <span class="qb-card-date"><?= $h($updated) ?></span>
          <div class="qb-card-actions">
            <button class="nu-btn nu-btn-primary nu-btn-sm" onclick="QB.run(<?= (int)$q['query_id'] ?>)"><?= $ico($I['play'], 11) ?><span>Run</span></button>
            <?php if ($canBuild): ?>
            <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="QB.edit(<?= (int)$q['query_id'] ?>)" title="Edit"><?= $ico($I['edit'], 13) ?><span>Edit</span></button>
            <button class="nu-btn nu-btn-ghost nu-btn-sm qb-icon-only" onclick="QB.duplicate(<?= (int)$q['query_id'] ?>)" title="Duplicate" aria-label="Duplicate"><?= $ico($I['copy'], 13) ?></button>
            <button class="nu-btn nu-btn-ghost nu-btn-sm qb-icon-only qb-danger" onclick="QB.delete(<?= (int)$q['query_id'] ?>)" title="Delete" aria-label="Delete"><?= $ico($I['trash'], 13) ?></button>
            <?php endif; ?>
          </div>
        </footer>
      </article>
      <?php endforeach; endif; ?>
    </div>
    <div class="qb-empty qb-nomatch" id="qbNoMatch" hidden><strong>No queries match your search</strong></div>

    <!-- Run results (saved queries) -->
    <section id="qbResultsPanel" class="qb-panel" hidden>
      <header class="qb-panel-head">
        <div>
          <h3 class="qb-panel-title" id="qbResultsTitle">Results</h3>
          <span class="qb-panel-meta" id="qbResultsMeta"></span>
        </div>
        <div class="qb-panel-actions">
          <button class="nu-btn nu-btn-ghost nu-btn-sm" id="qbExportBtn" onclick="QB.exportCsv()" title="Download the full result (not capped) as CSV">Export CSV</button>
          <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="QB.closeResults()">Close</button>
        </div>
      </header>
      <div id="qbRuntimeParams" class="qb-runtime" hidden></div>
      <div id="qbResultsContent"></div>
    </section>
  </div>

  <!-- ══ WORKSPACE (builder) ══════════════════════════════════════════ -->
  <div id="qbBuilderPanel" class="qb-workspace" hidden>
    <input type="hidden" id="qbEditId">

    <div class="qb-wbar">
      <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="QB.close()"><?= $ico($I['back']) ?><span>Queries</span></button>
      <h3 class="qb-wtitle" id="qbBuilderTitle">New query</h3>
      <span class="nu-sx-chip is-warn" id="qbDirty" hidden>Unsaved changes</span>
      <div class="qb-wbar-actions">
        <button class="nu-btn nu-btn-ghost nu-btn-sm qb-schema-toggle" id="qbSchemaToggle" onclick="QB.toggleSchema()" title="Show / hide schema"><?= $ico($I['panel']) ?><span>Schema</span></button>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" id="qbRunBtn" onclick="QB.runDraft()" title="Run (Ctrl/Cmd + Enter)"><?= $ico($I['play'], 12) ?><span>Run</span><kbd class="nu-sx-kbd" style="background:var(--bg-subtle);color:var(--text-tertiary);">⌘↵</kbd></button>
        <button class="nu-btn nu-btn-primary nu-btn-sm" id="qbSaveBtn" onclick="QB.save()" title="Save (Ctrl/Cmd + S)"><span>Save query</span></button>
      </div>
    </div>

    <div class="qb-wgrid" id="qbWGrid">
      <aside class="qb-schema" id="qbSchema" aria-label="Database schema"></aside>

      <div class="qb-wmain">
        <!-- Meta -->
        <div class="qb-meta">
          <div class="nu-field"><label for="qbName">Name <span class="qb-req">*</span></label>
            <input type="text" class="nu-input" id="qbName" placeholder="Monthly sales" oninput="QB.autoCode(); QB.markDirty()"></div>
          <div class="nu-field"><label for="qbCode">Code</label>
            <input type="text" class="nu-input" id="qbCode" placeholder="monthly_sales" oninput="QB.markDirty()"></div>
          <label class="qb-active"><input type="checkbox" id="qbActive" checked onchange="QB.markDirty()"><span>Active</span></label>
          <div class="nu-field qb-meta-wide"><label for="qbDescription">Description</label>
            <input type="text" class="nu-input" id="qbDescription" placeholder="What does this query answer?" oninput="QB.markDirty()"></div>
        </div>

        <!-- Mode tabs -->
        <div class="qb-modebar">
          <div class="nu-sx-seg" role="tablist" aria-label="Editor mode">
            <button type="button" role="tab" id="qbTabSql" class="is-active" onclick="QB.setMode('raw')">SQL</button>
            <button type="button" role="tab" id="qbTabVisual" onclick="QB.setMode('visual')">Visual</button>
          </div>
          <div class="qb-modebar-right">
            <button class="nu-btn nu-btn-ghost nu-btn-sm" id="qbFormatBtn" onclick="QB.formatSql()" title="Format SQL (Ctrl/Cmd + Shift + F)"><?= $ico($I['wand'], 13) ?><span>Format</span></button>
            <label class="qb-limit">Rows <select id="qbRowCap" class="nu-input"><option>100</option><option selected>500</option><option>1000</option><option>2000</option></select></label>
          </div>
        </div>

        <!-- SQL editor -->
        <div id="qbRawSection">
          <div id="qbEditor"></div>
          <p class="qb-hint">SELECT only. Use <code>:name</code> placeholders for runtime parameters. <kbd>Ctrl/⌘ + Enter</kbd> runs, <kbd>Ctrl/⌘ + S</kbd> saves.</p>
          <div id="qbParamHint" class="qb-banner" hidden></div>
        </div>

        <!-- Visual builder -->
        <div id="qbVisualSection" hidden>
          <div class="qb-vgrid">
            <div class="nu-field"><label for="qbTable">Table <span class="qb-req">*</span></label>
              <select class="nu-input" id="qbTable" onchange="QB.loadColumns()"><option value="">— select table —</option></select></div>
            <div class="nu-field"><label>Columns</label>
              <div class="qb-colchips" id="qbColumns"><span class="qb-muted">Select a table first</span></div></div>
          </div>
          <div class="qb-vblock">
            <div class="qb-vhead"><label>Where</label><button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="QB.addCondition()"><?= $ico($I['plus'], 12) ?><span>Add condition</span></button></div>
            <div id="qbConditions"></div>
          </div>
          <div class="qb-vgrid qb-vgrid-3">
            <div class="nu-field"><label for="qbOrderField">Order by</label><select class="nu-input" id="qbOrderField" onchange="QB.buildSql()"><option value="">— none —</option></select></div>
            <div class="nu-field"><label for="qbOrderDir">Direction</label><select class="nu-input" id="qbOrderDir" onchange="QB.buildSql()"><option value="ASC">Ascending</option><option value="DESC">Descending</option></select></div>
            <div class="nu-field"><label for="qbLimit">Limit</label><input type="number" class="nu-input" id="qbLimit" placeholder="none" min="1" max="10000" oninput="QB.buildSql()"></div>
          </div>
          <div class="qb-vblock">
            <div class="qb-vhead"><label>Generated SQL</label><button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="QB.useInEditor()">Edit as SQL →</button></div>
            <pre id="qbSqlPreview" class="qb-preview"></pre>
          </div>
        </div>

        <!-- Parameters -->
        <section class="qb-params">
          <div class="qb-vhead">
            <label>Parameters <span class="qb-muted">(runtime inputs for <code>:placeholders</code>)</span></label>
            <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="QB.addParam()"><?= $ico($I['plus'], 12) ?><span>Add parameter</span></button>
          </div>
          <div class="qb-prow qb-prow-head" id="qbParamsHead" hidden><span>Key</span><span>Label</span><span>Type</span><span>Default</span><span>Test value</span><span></span></div>
          <div id="qbParams"></div>
          <p class="qb-hint" id="qbParamsEmpty">No parameters. Blank values are passed as <code>NULL</code>, so optional filters can be written as <code>(:status IS NULL OR status = :status)</code>.</p>
        </section>

        <!-- Draft results -->
        <section id="qbDraftResults" class="qb-panel" hidden>
          <header class="qb-panel-head">
            <div><h3 class="qb-panel-title">Result</h3><span class="qb-panel-meta" id="qbDraftMeta"></span></div>
          </header>
          <div id="qbDraftBody"></div>
        </section>
      </div>
    </div>
  </div>

</div><!-- /.nu-queries-module -->

<style>
.nu-qb { max-width: 1500px; margin-inline: auto; }
.nu-qb [hidden] { display: none !important; }
.nu-qb .nu-btn { display: inline-flex; align-items: center; gap: 6px; }
.qb-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
.qb-title { font-size: 1.5rem; font-weight: 800; letter-spacing: -.02em; margin: 0; }
.qb-sub { margin: 4px 0 0; color: var(--text-tertiary); font-size: var(--font-base); display: flex; align-items: center; gap: 8px; }
.qb-head-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.qb-search { position: relative; display: inline-flex; align-items: center; }
.qb-search svg { position: absolute; left: 10px; color: var(--text-tertiary); pointer-events: none; }
.qb-search input { height: 34px; width: 240px; padding: 0 12px 0 32px; border: 1px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-card); color: var(--text-primary); font: inherit; font-size: var(--font-base); }
.qb-search input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--focus-ring); }

.qb-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 14px; }
.qb-card { display: flex; flex-direction: column; gap: 10px; padding: 16px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); transition: border-color .15s, box-shadow .15s, transform .15s; }
.qb-card:hover { border-color: color-mix(in srgb, var(--primary) 50%, var(--border-color)); box-shadow: var(--shadow-md); transform: translateY(-1px); }
.qb-card[hidden] { display: none; }
.qb-card-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
.qb-card-title { min-width: 0; display: flex; flex-direction: column; gap: 4px; }
.qb-card-title h3 { margin: 0; font-size: 15px; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.qb-card-title code { align-self: flex-start; font: 600 11px ui-monospace, Menlo, monospace; padding: 1px 7px; border-radius: 5px; background: var(--bg-subtle); color: var(--text-tertiary); }
.qb-card-desc { margin: 0; font-size: var(--font-sm); color: var(--text-secondary); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.qb-card-sql { margin: 0; padding: 8px 10px; font: 12px/1.5 ui-monospace, Menlo, monospace; color: var(--text-secondary); background: var(--bg-subtle); border: 1px solid var(--border-color); border-radius: var(--radius-sm); overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.qb-card-foot { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: auto; }
.qb-card-date { font-size: var(--font-xs); color: var(--text-tertiary); }
.qb-card-actions { display: flex; gap: 4px; }
.qb-icon-only { padding-inline: 8px !important; }
.qb-danger:hover { color: var(--color-error) !important; }
.qb-empty { grid-column: 1 / -1; display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 56px 20px; text-align: center; color: var(--text-tertiary); background: var(--bg-card); border: 1px dashed var(--border-color); border-radius: var(--radius-lg); }
.qb-empty strong { color: var(--text-primary); font-size: var(--font-lg); }
.qb-empty span { max-width: 420px; font-size: var(--font-base); }

.qb-panel { margin-top: 20px; padding: 14px 16px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); }
.qb-panel-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; }
.qb-panel-title { margin: 0; font-size: var(--font-lg); font-weight: 700; }
.qb-panel-meta { font-size: var(--font-sm); color: var(--text-tertiary); }
.qb-panel-actions { display: flex; gap: 6px; }
.qb-runtime { margin-bottom: 12px; padding: 12px; background: var(--bg-subtle); border-radius: var(--radius-md); }
.qb-runtime form { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; align-items: end; }
.qb-error { padding: 12px 14px; border-radius: var(--radius-md); background: var(--color-error-highlight); color: var(--color-error); font: 12.5px/1.5 ui-monospace, Menlo, monospace; white-space: pre-wrap; overflow-wrap: anywhere; }
.qb-loading { display: flex; align-items: center; gap: 10px; padding: 24px; color: var(--text-tertiary); font-size: var(--font-base); }

/* workspace */
.qb-wbar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; position: sticky; top: -24px; z-index: 5; padding: 10px 0; background: var(--bg-app); }
.qb-wtitle { margin: 0; font-size: var(--font-xl); font-weight: 700; }
.qb-wbar-actions { margin-left: auto; display: flex; gap: 8px; align-items: center; }
.qb-wgrid { display: grid; grid-template-columns: 260px minmax(0, 1fr); gap: 16px; align-items: start; }
.qb-wgrid.no-schema { grid-template-columns: minmax(0, 1fr); }
.qb-wgrid.no-schema .qb-schema { display: none; }
.qb-schema { position: sticky; top: 56px; height: calc(100vh - 190px); min-height: 280px; }
.qb-wmain { display: flex; flex-direction: column; gap: 14px; min-width: 0; }
.qb-meta { display: grid; grid-template-columns: 1.3fr 1fr auto; gap: 12px; align-items: end; padding: 14px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-lg); }
.qb-meta .nu-field { margin: 0; }
.qb-meta-wide { grid-column: 1 / -1; }
.qb-meta label, .qb-vgrid label, .qb-vhead label { font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--text-tertiary); }
.qb-req { color: var(--color-error); }
.qb-active { display: inline-flex; align-items: center; gap: 8px; height: 38px; font-size: var(--font-base); font-weight: 600; cursor: pointer; text-transform: none !important; letter-spacing: 0 !important; color: var(--text-primary) !important; }
.qb-active input { width: 16px; height: 16px; accent-color: var(--primary); }
.qb-modebar { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
.qb-modebar-right { display: flex; align-items: center; gap: 10px; }
.qb-limit { display: inline-flex; align-items: center; gap: 6px; font-size: var(--font-sm); color: var(--text-tertiary); font-weight: 600; }
.qb-limit select { width: 84px; padding: 4px 8px; height: 30px; }
.qb-hint { margin: 6px 0 0; font-size: var(--font-sm); color: var(--text-tertiary); }
.qb-hint code, .qb-muted code { font: 12px ui-monospace, Menlo, monospace; padding: 0 5px; border-radius: 4px; background: var(--bg-subtle); }
.qb-hint kbd { font: 600 10.5px 'Inter', sans-serif; padding: 2px 5px; border-radius: 4px; background: var(--bg-card); border: 1px solid var(--border-color); border-bottom-width: 2px; }
.qb-muted { color: var(--text-tertiary); font-weight: 400; text-transform: none; letter-spacing: 0; font-size: var(--font-sm); }
.qb-banner { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 8px; padding: 8px 12px; border-radius: var(--radius-md); font-size: var(--font-sm); background: var(--color-warning-highlight); color: var(--text-primary); }
.qb-banner code { font: 12px ui-monospace, Menlo, monospace; padding: 0 5px; border-radius: 4px; background: var(--bg-card); }

.qb-vgrid { display: grid; grid-template-columns: 1fr 2fr; gap: 12px; margin-bottom: 12px; }
.qb-vgrid-3 { grid-template-columns: 1fr 1fr 120px; }
.qb-vgrid .nu-field { margin: 0; }
.qb-vblock { margin-bottom: 12px; }
.qb-vhead { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 6px; }
.qb-colchips { display: flex; flex-wrap: wrap; gap: 6px; padding: 8px; max-height: 128px; overflow: auto; border: 1px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-card); min-height: 38px; align-items: center; }
.qb-colchips label { display: inline-flex; align-items: center; gap: 5px; padding: 2px 9px; border-radius: 999px; border: 1px solid var(--border-color); font: 600 12px ui-monospace, Menlo, monospace; text-transform: none; letter-spacing: 0; color: var(--text-secondary); cursor: pointer; user-select: none; }
.qb-colchips label:has(input:checked) { background: var(--color-primary-highlight); border-color: color-mix(in srgb, var(--primary) 50%, transparent); color: var(--primary); }
.qb-colchips input { display: none; }
.qb-cond { display: grid; grid-template-columns: 70px minmax(120px, 1fr) 130px minmax(120px, 1fr) 32px; gap: 6px; margin-bottom: 6px; align-items: center; }
.qb-cond .nu-input, .qb-prow .nu-input { height: 32px; padding: 3px 8px; font-size: 12.5px; }
.qb-preview { margin: 0; padding: 10px 12px; font: 12.5px/1.55 ui-monospace, Menlo, monospace; background: var(--bg-subtle); border: 1px solid var(--border-color); border-radius: var(--radius-md); white-space: pre-wrap; min-height: 44px; color: var(--text-secondary); }
.qb-params { padding: 14px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-lg); }
.qb-prow { display: grid; grid-template-columns: 1fr 1fr 96px 1fr 1fr 32px; gap: 6px; margin-bottom: 6px; align-items: center; }
.qb-prow-head { font-size: 11px; font-weight: 700; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .04em; margin-bottom: 4px; }
.qb-prow.is-unused .qb-pkey { opacity: .55; }
.qb-pkey { font-family: ui-monospace, Menlo, monospace; }
.qb-x { border: 0; background: transparent; color: var(--text-tertiary); cursor: pointer; font-size: 18px; line-height: 1; border-radius: var(--radius-sm); height: 30px; }
.qb-x:hover { color: var(--color-error); background: var(--color-error-highlight); }

@media (max-width: 1000px) {
  .qb-wgrid { grid-template-columns: 1fr; }
  .qb-schema { position: static; height: 300px; }
  .qb-meta { grid-template-columns: 1fr 1fr; }
  .qb-vgrid, .qb-vgrid-3 { grid-template-columns: 1fr; }
  .qb-cond { grid-template-columns: 60px 1fr 1fr 32px; }
  .qb-cond .qb-cond-val { grid-column: 2 / 4; }
  .qb-prow { grid-template-columns: 1fr 1fr 32px; }
  .qb-prow-head { display: none; }
}
</style>

<script>
/* ============================================================
   QB — Query Builder controller
   ============================================================ */
(function () {

if (window.QB && typeof window.QB._destroy === 'function') { window.QB._destroy(); }

window.QB = (() => {
  const API = 'api/queries.php';
  let _mode = 'raw';
  let _runCode = '';
  let _runParams = {};
  let _condCount = 0, _paramCount = 0;
  let _columns = [];
  let _editor = null;
  let _schema = [];
  let _dirty = false;
  let _running = false;
  let _paramTimer = null;
  let _savedSnapshot = '';

  const $   = (id) => document.getElementById(id);
  const esc = (s) => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');

  function toast(msg, type = 'info') {
    if (window.NuApp && typeof NuApp.toast === 'function') { NuApp.toast(msg, type); return; }
    console.log('[QB]', type, msg);
  }
  async function api(url, opts) {
    const r = await fetch(url, Object.assign({ credentials: 'same-origin' }, opts || {}));
    const text = await r.text();
    try { return JSON.parse(text); }
    catch (e) { throw new Error(r.ok ? 'Unexpected response from server' : 'Server error (' + r.status + ')'); }
  }
  async function confirmBox(message, opts) {
    if (window.NuBrowseTable && typeof NuBrowseTable.confirm === 'function') return NuBrowseTable.confirm(message, opts);
    return window.confirm(message);
  }
  function _reloadModule() {
    window.QB = null;
    if (window.NuApp && typeof NuApp.loadModule === 'function') NuApp.loadModule('queries'); else location.reload();
  }

  /* ── list ─────────────────────────────────────────────── */
  function filterList(q) {
    q = String(q || '').trim().toLowerCase();
    let shown = 0;
    document.querySelectorAll('#qbCards .qb-card').forEach(c => {
      const on = !q || (c.dataset.search || '').includes(q);
      c.hidden = !on; if (on) shown++;
    });
    const nm = $('qbNoMatch'); if (nm) nm.hidden = shown > 0;
  }

  /* ── dirty tracking ───────────────────────────────────── */
  function snapshot() {
    return JSON.stringify([$('qbName')?.value, $('qbCode')?.value, $('qbDescription')?.value, $('qbActive')?.checked, getSql(), collectParams()]);
  }
  function markDirty() {
    const d = snapshot() !== _savedSnapshot;
    if (d !== _dirty) { _dirty = d; if ($('qbDirty')) $('qbDirty').hidden = !d; }
  }
  function markClean() { _savedSnapshot = snapshot(); _dirty = false; if ($('qbDirty')) $('qbDirty').hidden = true; }

  /* ── schema ───────────────────────────────────────────── */
  function mountSchema() {
    if (!window.NuSchema) return;
    NuSchema.mount($('qbSchema'), {
      onLoaded: (t) => { _schema = t; if (_editor) _editor.setSchema(t); fillTableSelect(); },
      onPick: (name, kind, table) => {
        if (_mode !== 'raw') { if (kind === 'table') { $('qbTable').value = name; loadColumns(); } return; }
        _editor.insert(kind === 'table' ? '`' + name + '`' : '`' + name + '`');
      },
      onInsertSelect: (table) => {
        if (_mode !== 'raw') setMode('raw');
        const cur = _editor.getValue().trim();
        const stmt = 'SELECT *\nFROM `' + table + '`\nLIMIT 100';
        _editor.setValue(cur ? cur + '\n\n' + stmt : stmt);
        _editor.focus(); onSqlChange();
      }
    });
  }
  function toggleSchema() { $('qbWGrid').classList.toggle('no-schema'); setTimeout(() => _editor && _editor.resize && _editor.resize(), 50); }

  /* ── editor ───────────────────────────────────────────── */
  function mountEditor(value) {
    if (_editor) { _editor.destroy(); _editor = null; }
    _editor = NuSqlEditor.mount($('qbEditor'), {
      value: value || '', minLines: 9,
      placeholder: 'SELECT * FROM nu_users WHERE usr_active = 1 ORDER BY usr_created_at DESC LIMIT 100',
      onChange: onSqlChange, onRun: runDraft, onSave: save
    });
    if (_schema.length) _editor.setSchema(_schema);
  }
  function onSqlChange() {
    markDirty();
    clearTimeout(_paramTimer);
    _paramTimer = setTimeout(syncParamHints, 350);
  }
  function formatSql() {
    if (_mode !== 'raw') return;
    _editor.format(); onSqlChange();
  }

  /* ── tables / columns (visual mode) ───────────────────── */
  function fillTableSelect(selectValue) {
    const sel = $('qbTable'); if (!sel) return;
    const cur = selectValue !== undefined ? selectValue : sel.value;
    sel.innerHTML = '<option value="">— select table —</option>' + _schema.map(t => `<option value="${esc(t.name)}"${t.name === cur ? ' selected' : ''}>${esc(t.name)}</option>`).join('');
  }
  async function loadTables(selectValue) {
    try { _schema = await NuSchema.load(); } catch (e) { return; }
    fillTableSelect(selectValue);
    if (_editor) _editor.setSchema(_schema);
  }
  function loadColumns() {
    const table = $('qbTable').value;
    const t = _schema.find(x => x.name === table);
    _columns = t ? t.columns.map(c => c.name) : [];
    const box = $('qbColumns');
    if (!t) { box.innerHTML = '<span class="qb-muted">Select a table first</span>'; }
    else {
      box.innerHTML = `<label><input type="checkbox" value="*" checked onchange="QB.colToggle(this)">* all</label>` +
        _columns.map(c => `<label><input type="checkbox" value="${esc(c)}" onchange="QB.colToggle(this)">${esc(c)}</label>`).join('');
    }
    $('qbOrderField').innerHTML = '<option value="">— none —</option>' + _columns.map(c => `<option value="${esc(c)}">${esc(c)}</option>`).join('');
    document.querySelectorAll('.qb-cond-field').forEach(s => {
      const cur = s.value;
      s.innerHTML = '<option value="">— field —</option>' + _columns.map(c => `<option value="${esc(c)}"${c === cur ? ' selected' : ''}>${esc(c)}</option>`).join('');
    });
    buildSql();
  }
  function colToggle(input) {
    const boxes = [...document.querySelectorAll('#qbColumns input')];
    if (input.value === '*' && input.checked) boxes.forEach(b => { if (b.value !== '*') b.checked = false; });
    else if (input.value !== '*' && input.checked) { const all = boxes.find(b => b.value === '*'); if (all) all.checked = false; }
    if (!boxes.some(b => b.checked)) { const all = boxes.find(b => b.value === '*'); if (all) all.checked = true; }
    buildSql();
  }

  function addCondition(field, op, val, logic) {
    const id = ++_condCount;
    const row = document.createElement('div');
    row.id = `qbCond_${id}`; row.className = 'qb-cond';
    const cols = _columns.map(c => `<option value="${esc(c)}" ${c === field ? 'selected' : ''}>${esc(c)}</option>`).join('');
    const ops = [['=','= equals'],['!=','≠ not equals'],['>','> greater'],['>=','≥ greater or equal'],['<','< less'],['<=','≤ less or equal'],['LIKE','LIKE contains'],['IS NULL','IS NULL'],['IS NOT NULL','IS NOT NULL']];
    row.innerHTML = `
      <select class="nu-input qb-cond-logic" onchange="QB.buildSql()"><option value="AND"${(!logic || logic === 'AND') ? ' selected' : ''}>AND</option><option value="OR"${logic === 'OR' ? ' selected' : ''}>OR</option></select>
      <select class="nu-input qb-cond-field" onchange="QB.buildSql()"><option value="">— field —</option>${cols}</select>
      <select class="nu-input qb-cond-op" onchange="QB.buildSql()">${ops.map(([v, l]) => `<option value="${esc(v)}"${op === v ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select>
      <input type="text" class="nu-input qb-cond-val" placeholder="value or :param" value="${esc(val || '')}" oninput="QB.buildSql()">
      <button class="qb-x" onclick="QB.removeCondition(${id})" aria-label="Remove condition">×</button>`;
    $('qbConditions').appendChild(row);
    buildSql();
  }
  function removeCondition(id) { const el = $(`qbCond_${id}`); if (el) el.remove(); buildSql(); }

  function buildSql() {
    const table = $('qbTable') ? $('qbTable').value : '';
    if (!table) { if ($('qbSqlPreview')) $('qbSqlPreview').textContent = ''; return ''; }
    const sel = [...document.querySelectorAll('#qbColumns input:checked')].map(i => i.value);
    const colStr = (!sel.length || sel.includes('*')) ? '*' : sel.map(c => '`' + c + '`').join(', ');
    let sql = `SELECT ${colStr}\nFROM \`${table}\``;
    const wheres = [];
    document.querySelectorAll('#qbConditions .qb-cond').forEach(row => {
      const field = row.querySelector('.qb-cond-field').value;
      const op = row.querySelector('.qb-cond-op').value;
      const val = row.querySelector('.qb-cond-val').value.trim();
      const logic = row.querySelector('.qb-cond-logic').value;
      if (!field) return;
      const prefix = wheres.length ? ` ${logic} ` : '';
      if (op === 'IS NULL' || op === 'IS NOT NULL') wheres.push(prefix + '`' + field + '` ' + op);
      else if (val !== '') {
        const isParam = /^:[A-Za-z_]\w*$/.test(val);
        const isNum = /^-?\d+(\.\d+)?$/.test(val) && op !== 'LIKE';
        const v = isParam || isNum ? val : `'${(op === 'LIKE' && !val.includes('%') ? '%' + val + '%' : val).replace(/'/g, "''")}'`;
        wheres.push(prefix + '`' + field + '` ' + op + ' ' + v);
      }
    });
    if (wheres.length) sql += `\nWHERE ${wheres.join('')}`;
    const of = $('qbOrderField').value;
    if (of) sql += `\nORDER BY \`${of}\` ${$('qbOrderDir').value}`;
    const lim = parseInt($('qbLimit').value, 10);
    if (!isNaN(lim) && lim > 0) sql += `\nLIMIT ${lim}`;
    $('qbSqlPreview').textContent = sql;
    markDirty(); clearTimeout(_paramTimer); _paramTimer = setTimeout(syncParamHints, 350);
    return sql;
  }
  function useInEditor() {
    const sql = buildSql();
    if (!sql) { toast('Choose a table first', 'error'); return; }
    _editor.setValue(sql); setMode('raw'); _editor.focus();
  }

  function getSql() {
    if (_mode === 'raw') return _editor ? _editor.getValue().trim() : '';
    return ($('qbSqlPreview') ? $('qbSqlPreview').textContent : '').trim();
  }

  function setMode(m) {
    if (m === 'visual') { loadTables(); }
    _mode = m;
    $('qbRawSection').hidden = m !== 'raw';
    $('qbVisualSection').hidden = m !== 'visual';
    $('qbTabSql').classList.toggle('is-active', m === 'raw');
    $('qbTabVisual').classList.toggle('is-active', m === 'visual');
    $('qbFormatBtn').hidden = m !== 'raw';
    if (m === 'raw' && _editor) setTimeout(() => _editor.resize && _editor.resize(), 30);
    syncParamHints();
  }

  /* ── parameters ───────────────────────────────────────── */
  function placeholdersIn(sql) {
    let clean = String(sql || '').replace(/\/\*[\s\S]*?\*\//g, ' ').replace(/(--|#)[^\n]*/g, ' ');
    clean = clean.replace(/'(?:[^'\\]|\\.|'')*'|"(?:[^"\\]|\\.|"")*"|`[^`]*`/g, ' ');
    const out = []; const re = /(?<![:\w]):([A-Za-z_][A-Za-z0-9_]*)/g; let m;
    while ((m = re.exec(clean))) if (!out.includes(m[1])) out.push(m[1]);
    return out;
  }
  function paramKeys() { return [...document.querySelectorAll('#qbParams .qb-prow')].map(r => r.querySelector('.qb-param-key').value.trim()).filter(Boolean); }

  function syncParamHints() {
    const used = placeholdersIn(getSql());
    const defined = paramKeys();
    const missing = used.filter(k => !defined.includes(k));
    const hint = $('qbParamHint');
    if (hint) {
      if (missing.length) {
        hint.hidden = false;
        hint.innerHTML = `<span>Placeholders without a parameter: ${missing.map(k => `<code>:${esc(k)}</code>`).join(' ')}</span><button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="QB.addMissingParams()">Add parameter${missing.length > 1 ? 's' : ''}</button>`;
      } else hint.hidden = true;
    }
    document.querySelectorAll('#qbParams .qb-prow').forEach(r => {
      const k = r.querySelector('.qb-param-key').value.trim();
      r.classList.toggle('is-unused', !!k && !used.includes(k));
      r.title = k && !used.includes(k) ? 'Not referenced in the SQL' : '';
    });
    const has = document.querySelectorAll('#qbParams .qb-prow').length > 0;
    $('qbParamsHead').hidden = !has; $('qbParamsEmpty').hidden = has;
  }
  function addMissingParams() {
    const defined = paramKeys();
    placeholdersIn(getSql()).filter(k => !defined.includes(k)).forEach(k => addParam(k, { label: k.replace(/_/g, ' ').replace(/^./, c => c.toUpperCase()), type: /date/i.test(k) ? 'date' : (/(^|_)(id|count|qty|amount|num)$/i.test(k) ? 'number' : 'text') }));
  }
  function addParam(key, cfg) {
    const id = ++_paramCount;
    const row = document.createElement('div');
    row.id = `qbParam_${id}`; row.className = 'qb-prow';
    const typ = cfg?.type || 'text';
    row.innerHTML = `
      <input type="text" class="nu-input qb-pkey qb-param-key" value="${esc(key || '')}" placeholder="param_key" oninput="QB.markDirty();QB.syncParamHints()">
      <input type="text" class="nu-input qb-param-label" value="${esc(cfg?.label || '')}" placeholder="Display label" oninput="QB.markDirty()">
      <select class="nu-input qb-param-type" onchange="QB.markDirty()">${['text','number','date','select'].map(t => `<option value="${t}"${typ === t ? ' selected' : ''}>${t[0].toUpperCase() + t.slice(1)}</option>`).join('')}</select>
      <input type="text" class="nu-input qb-param-default" value="${esc(cfg?.default || '')}" placeholder="default" oninput="QB.markDirty()">
      <input type="text" class="nu-input qb-param-test" value="" placeholder="used for Run" title="Value used when you press Run here (not saved)">
      <button class="qb-x" onclick="QB.removeParam(${id})" aria-label="Remove parameter">×</button>`;
    $('qbParams').appendChild(row);
    syncParamHints(); markDirty();
  }
  function removeParam(id) { const el = $(`qbParam_${id}`); if (el) el.remove(); syncParamHints(); markDirty(); }
  function collectParams() {
    const params = {};
    document.querySelectorAll('#qbParams .qb-prow').forEach(row => {
      const key = row.querySelector('.qb-param-key').value.trim();
      if (!key) return;
      params[key] = { label: row.querySelector('.qb-param-label').value.trim() || key, type: row.querySelector('.qb-param-type').value, default: row.querySelector('.qb-param-default').value.trim() };
    });
    return params;
  }
  function collectTestValues() {
    const v = {};
    document.querySelectorAll('#qbParams .qb-prow').forEach(row => {
      const key = row.querySelector('.qb-param-key').value.trim(); const t = row.querySelector('.qb-param-test').value;
      if (key && t !== '') v[key] = t;
    });
    return v;
  }

  /* ── open / edit / reset ──────────────────────────────── */
  function showBuilder() {
    $('qbListPanel').hidden = true;
    $('qbBuilderPanel').hidden = false;
    window.scrollTo && document.getElementById('contentArea')?.scrollTo?.({ top: 0 });
  }
  function reset() {
    ['qbEditId','qbName','qbCode','qbDescription','qbLimit'].forEach(id => { if ($(id)) $(id).value = ''; });
    $('qbActive').checked = true;
    $('qbConditions').innerHTML = ''; $('qbParams').innerHTML = '';
    $('qbDraftResults').hidden = true; $('qbDraftBody').innerHTML = '';
    $('qbSqlPreview').textContent = '';
    _condCount = 0; _paramCount = 0; _columns = [];
    $('qbColumns').innerHTML = '<span class="qb-muted">Select a table first</span>';
    $('qbOrderField').innerHTML = '<option value="">— none —</option>';
  }
  function openNew() {
    reset(); mountEditor('');
    $('qbBuilderTitle').textContent = 'New query';
    setMode('raw'); showBuilder(); mountSchema();
    syncParamHints(); markClean();
    setTimeout(() => $('qbName').focus(), 50);
  }
  async function edit(id) {
    try {
      const d = await api(`${API}?action=get&id=${id}`);
      if (!d.success) { toast(d.error, 'error'); return; }
      const q = d.query;
      reset();
      $('qbEditId').value = q.query_id; $('qbName').value = q.query_name; $('qbCode').value = q.query_code;
      $('qbDescription').value = q.query_description || ''; $('qbActive').checked = q.query_active == 1;
      $('qbBuilderTitle').textContent = 'Edit query';
      mountEditor(q.query_sql || '');
      let params = {}; try { params = JSON.parse(q.query_parameters || 'null') || {}; } catch (e) { /* ignore */ }
      Object.entries(params).forEach(([k, cfg]) => addParam(k, cfg));
      setMode('raw'); showBuilder(); mountSchema();
      syncParamHints(); markClean();
    } catch (e) { toast('Failed to load query: ' + e.message, 'error'); }
  }
  async function close() {
    if (_dirty && !(await confirmBox('You have unsaved changes. Discard them?', { title: 'Discard changes?', confirmLabel: 'Discard', cancelLabel: 'Keep editing', danger: true }))) return;
    if (_editor) { _editor.destroy(); _editor = null; }
    _dirty = false;
    $('qbBuilderPanel').hidden = true; $('qbListPanel').hidden = false;
  }

  /* ── save ─────────────────────────────────────────────── */
  async function save() {
    const name = $('qbName').value.trim();
    if (!name) { toast('Query name is required', 'error'); $('qbName').focus(); return; }
    const sql = getSql();
    if (!sql) { toast('SQL is required', 'error'); return; }
    const payload = {
      query_id: $('qbEditId').value || null, query_name: name, query_code: $('qbCode').value.trim() || null,
      query_description: $('qbDescription').value.trim(), query_sql: sql,
      query_active: $('qbActive').checked ? 1 : 0, query_parameters: collectParams()
    };
    const btn = $('qbSaveBtn'); btn.disabled = true;
    try {
      const d = await api(`${API}?action=save`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
      if (!d.success) { toast(d.error, 'error'); btn.disabled = false; return; }
      toast('Query saved', 'success');
      markClean();
      if (!payload.query_id && d.query_id) $('qbEditId').value = d.query_id;
      setTimeout(_reloadModule, 350);
    } catch (e) { toast('Save failed: ' + e.message, 'error'); btn.disabled = false; }
  }

  /* ── run (draft, from the workspace) ──────────────────── */
  async function runDraft() {
    if (_running) return;
    const sql = getSql();
    if (!sql) { toast('Write a query first', 'error'); return; }
    _running = true;
    const btn = $('qbRunBtn'); const label = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<span class="nu-sx-spin"></span><span>Running…</span>';
    const panel = $('qbDraftResults'); panel.hidden = false;
    $('qbDraftMeta').textContent = '';
    $('qbDraftBody').innerHTML = '<div class="qb-loading"><span class="nu-sx-spin"></span> Running query…</div>';
    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    try {
      const d = await api(`${API}?action=preview`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ sql, params: collectTestValues(), defs: collectParams(), limit: parseInt($('qbRowCap').value, 10) || 500 })
      });
      if (!d.success) { $('qbDraftBody').innerHTML = `<div class="qb-error">${esc(d.error)}</div>`; return; }
      const r = d.data;
      $('qbDraftMeta').textContent = '';
      NuResultGrid.render($('qbDraftBody'), {
        columns: r.columns, rows: r.records, filename: (($('qbCode').value || 'query') + '_' + new Date().toISOString().slice(0, 10) + '.csv'),
        meta: { elapsed_ms: r.elapsed_ms, truncated: r.truncated, limit: parseInt($('qbRowCap').value, 10) || 500, total_available: r.total_available }
      });
    } catch (e) {
      $('qbDraftBody').innerHTML = `<div class="qb-error">${esc(e.message)}</div>`;
    } finally {
      _running = false; btn.disabled = false; btn.innerHTML = label;
    }
  }

  /* ── run (saved, from the list) ───────────────────────── */
  async function run(id) {
    try {
      const d1 = await api(`${API}?action=get&id=${id}`);
      if (!d1.success) { toast(d1.error, 'error'); return; }
      const q = d1.query;
      _runCode = q.query_code; _runParams = {};
      $('qbResultsTitle').textContent = q.query_name;
      $('qbResultsPanel').hidden = false;
      $('qbResultsMeta').textContent = '';
      let params = {}; try { params = JSON.parse(q.query_parameters || 'null') || {}; } catch (e) { /* ignore */ }
      renderParamForm(params);
      $('qbResultsPanel').scrollIntoView({ behavior: 'smooth', block: 'start' });
      if (Object.keys(params).length) {
        $('qbResultsContent').innerHTML = '<p class="qb-hint" style="padding:8px 0;">Fill in the parameters above (or leave blank for defaults), then press Run.</p>';
        return;
      }
      await executeQuery(q.query_code, {});
    } catch (e) { toast('Failed to run query: ' + e.message, 'error'); }
  }
  function renderParamForm(params) {
    const rp = $('qbRuntimeParams');
    const entries = Object.entries(params);
    if (!entries.length) { rp.hidden = true; rp.innerHTML = ''; return; }
    rp.innerHTML = '<form onsubmit="event.preventDefault();QB.runWithParams()">' + entries.map(([key, cfg]) => {
      const type = cfg.type === 'number' ? 'number' : (cfg.type === 'date' ? 'date' : 'text');
      return `<div class="nu-field" style="margin:0;"><label style="font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--text-tertiary);">${esc(cfg.label || key)}</label>` +
        `<input type="${type}" name="${esc(key)}" class="nu-input" value="${esc(cfg.default || '')}" placeholder="${esc(cfg.label || key)}"></div>`;
    }).join('') + '<div><button type="submit" class="nu-btn nu-btn-primary" style="width:100%;justify-content:center;">Run</button></div></form>';
    rp.hidden = false;
  }
  async function runWithParams() {
    const form = $('qbRuntimeParams').querySelector('form'); const params = {};
    [...form.querySelectorAll('[name]')].forEach(i => { if (i.name && i.value !== '') params[i.name] = i.value; });
    await executeQuery(_runCode, params);
  }
  async function executeQuery(code, params) {
    _runParams = params;
    $('qbResultsContent').innerHTML = '<div class="qb-loading"><span class="nu-sx-spin"></span> Running…</div>';
    try {
      const qs = new URLSearchParams({ action: 'run', code }); Object.entries(params).forEach(([k, v]) => qs.set(k, v));
      const d = await api(`${API}?${qs.toString()}`);
      if (!d.success) { $('qbResultsContent').innerHTML = `<div class="qb-error">${esc(d.error)}</div>`; return; }
      const r = d.data;
      NuResultGrid.render($('qbResultsContent'), { columns: r.columns, rows: r.records, filename: code + '_' + new Date().toISOString().slice(0, 10) + '.csv', meta: { elapsed_ms: r.elapsed_ms } });
    } catch (e) { $('qbResultsContent').innerHTML = `<div class="qb-error">${esc(e.message)}</div>`; }
  }
  function closeResults() { $('qbResultsPanel').hidden = true; }
  function exportCsv() {
    if (!_runCode) return;
    const qs = new URLSearchParams({ action: 'export', code: _runCode }); Object.entries(_runParams).forEach(([k, v]) => qs.set(k, v));
    window.open(`${API}?${qs.toString()}`, '_blank');
  }

  /* ── duplicate / delete ───────────────────────────────── */
  async function duplicate(id) {
    try {
      const d = await api(`${API}?action=duplicate&id=${id}`);
      if (!d.success) { toast(d.error, 'error'); return; }
      toast('Query duplicated', 'success'); setTimeout(_reloadModule, 350);
    } catch (e) { toast('Duplicate failed: ' + e.message, 'error'); }
  }
  async function del(id) {
    const card = document.querySelector(`.qb-card[data-id="${id}"]`);
    const name = card ? card.dataset.name : 'this query';
    if (!(await confirmBox(`Delete “${name}”? This cannot be undone.`, { title: 'Delete query?', confirmLabel: 'Delete', danger: true }))) return;
    try {
      const d = await api(`${API}?action=delete&id=${id}`);
      if (!d.success) { toast(d.error, 'error'); return; }
      toast('Query deleted', 'success'); setTimeout(_reloadModule, 350);
    } catch (e) { toast('Delete failed: ' + e.message, 'error'); }
  }

  function autoCode() {
    if ($('qbEditId') && $('qbEditId').value) return;
    const name = $('qbName') ? $('qbName').value : '';
    if ($('qbCode')) $('qbCode').value = name.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
  }

  function _destroy() { if (_editor) { try { _editor.destroy(); } catch (e) { /* ignore */ } } window.QB = null; }

  return {
    openNew, edit, save, run, runDraft, runWithParams, exportCsv, closeResults, duplicate,
    delete: del, close, loadColumns, colToggle, buildSql, useInEditor,
    addCondition, removeCondition, addParam, removeParam, addMissingParams, syncParamHints,
    autoCode, setMode, formatSql, toggleSchema, filterList, markDirty, _destroy
  };
})();

})();
</script>
