<?php
declare(strict_types=1);
/**
 * modules/dashboard/dashboard.php
 * ADMIN DASHBOARD — globeadmin / admin only.
 *
 * NOT an entry point. Always included by index.php (which already
 * called module_bootstrap.php). Do NOT add require_once bootstrap here.
 */
// Safety: if somehow hit directly, bootstrap once.
if (!defined('NU_BOOTSTRAP_DONE')) {
    require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
}

ini_set('display_errors', '0');
error_reporting(E_ALL);

$_dashRole = strtolower((string)($_SESSION['nu_role'] ?? ''));
if ($_dashRole !== 'globeadmin' && $_dashRole !== 'admin') {
    require __DIR__ . '/dashboard_user.php';
    return;
}
$isGlobeAdmin = ($_dashRole === 'globeadmin');

require_once __DIR__ . '/_dash_helpers.php';

$db = NuDatabase::getInstance();

if (!function_exists('nu_safe_count')) {
    function nu_safe_count(NuDatabase $db, string $sql): int {
        try { $r = $db->fetchOne($sql); return (int)($r['total'] ?? 0); }
        catch (Throwable $e) { error_log('[dashboard] ' . $e->getMessage()); return 0; }
    }
}

$currentPid  = class_exists('ProjectContext') ? ProjectContext::getId() : 1;
$userCount   = nu_safe_count($db, "SELECT COUNT(*) as total FROM nu_users");
$formCount   = nu_safe_count($db, "SELECT COUNT(*) as total FROM nu_forms WHERE project_id = " . (int)$currentPid);
$reportCount = nu_safe_count($db, "SELECT COUNT(*) as total FROM nu_reports WHERE project_id = " . (int)$currentPid);
$auditToday  = nu_safe_count($db, "SELECT COUNT(*) as total FROM nu_audit_log WHERE DATE(audit_timestamp) = CURDATE()");
$auditYday   = nu_safe_count($db, "SELECT COUNT(*) as total FROM nu_audit_log WHERE DATE(audit_timestamp) = CURDATE() - INTERVAL 1 DAY");
$activeToday = nu_safe_count($db, "SELECT COUNT(DISTINCT audit_username) as total FROM nu_audit_log WHERE DATE(audit_timestamp) = CURDATE()");

// Last 7 days of audit activity (oldest → newest), zero-filled.
$series = [];
for ($i = 6; $i >= 0; $i--) { $series[date('Y-m-d', strtotime("-$i day"))] = 0; }
try {
    $rows = $db->fetchAll(
        "SELECT DATE(audit_timestamp) AS d, COUNT(*) AS c FROM nu_audit_log
          WHERE audit_timestamp >= CURDATE() - INTERVAL 6 DAY GROUP BY DATE(audit_timestamp)"
    ) ?: [];
    foreach ($rows as $r) { if (isset($series[$r['d']])) $series[$r['d']] = (int)$r['c']; }
} catch (Throwable $e) { error_log('[dashboard] series: ' . $e->getMessage()); }
$seriesVals = array_values($series);
$weekTotal  = array_sum($seriesVals);

try {
    $recentActivity = $db->fetchAll("SELECT * FROM nu_audit_log ORDER BY audit_timestamp DESC LIMIT 12") ?: [];
} catch (Throwable $e) { $recentActivity = []; }

$actDelta = null;
if ($auditYday > 0)      $actDelta = (int)round((($auditToday - $auditYday) / $auditYday) * 100);
elseif ($auditToday > 0) $actDelta = 100;

$adminName = (string)($_SESSION['nu_name'] ?? $_SESSION['nu_username'] ?? 'there');
$adminName = trim(explode(' ', $adminName)[0]) ?: 'there';

$kpis = [
    ['label' => 'Total users',    'value' => $userCount,   'icon' => 'users',    'tone' => 'blue',   'note' => $activeToday . ' active today', 'go' => 'users',   'spark' => null],
    ['label' => 'Forms built',    'value' => $formCount,   'icon' => 'form',     'tone' => 'violet', 'note' => 'in this project',               'go' => 'forms',   'spark' => null],
    ['label' => 'Reports',        'value' => $reportCount, 'icon' => 'report',   'tone' => 'green',  'note' => 'in this project',               'go' => 'reports', 'spark' => null],
    ['label' => 'Activity today', 'value' => $auditToday,  'icon' => 'activity', 'tone' => 'amber',  'note' => $weekTotal . ' this week',       'go' => 'audit',   'spark' => $seriesVals, 'delta' => $actDelta],
];

$actionClass = static function (string $a): string {
    $a = strtolower($a);
    if (in_array($a, ['delete', 'remove'], true)) return 'is-delete';
    if (in_array($a, ['create', 'insert', 'add'], true)) return 'is-create';
    if (in_array($a, ['update', 'edit'], true)) return 'is-update';
    if (in_array($a, ['login', 'logout'], true)) return 'is-login';
    return 'is-other';
};
$maxBar = max(1, max($seriesVals));
?>

<div class="nu-dashboard nu-dash" data-nu-dash>

    <?= nu_dash_hero($adminName, $isGlobeAdmin ? 'Globe Admin' : 'Admin') ?>

    <?php if ($formCount === 0): ?>
    <div class="nu-dash-callout">
        <div>
            <strong>Welcome — let's build your first form.</strong>
            <span>Forms power browse tables, reports and dashboards. It takes a minute.</span>
        </div>
        <button type="button" class="nu-btn nu-btn-primary nu-btn-sm" onclick="NuApp.loadModule('forms')"><?= nu_dash_icon('plus') ?><span>Create a form</span></button>
    </div>
    <?php endif; ?>

    <!-- KPI row -->
    <div class="nu-dash-kpis">
        <?php foreach ($kpis as $k): ?>
        <a href="#<?= nu_dash_h($k['go']) ?>" class="nu-dash-kpi tone-<?= nu_dash_h($k['tone']) ?>"
           onclick="NuApp.loadModule('<?= nu_dash_h($k['go']) ?>'); return false;">
            <span class="nu-dash-kpi-ico"><?= nu_dash_icon($k['icon']) ?></span>
            <span class="nu-dash-kpi-body">
                <span class="nu-dash-kpi-label"><?= nu_dash_h($k['label']) ?></span>
                <span class="nu-dash-kpi-value"><?= number_format((int)$k['value']) ?></span>
                <span class="nu-dash-kpi-note">
                    <?php if (array_key_exists('delta', $k) && $k['delta'] !== null): ?>
                        <span class="nu-dash-delta <?= $k['delta'] >= 0 ? 'up' : 'down' ?>"><?= $k['delta'] >= 0 ? '▲' : '▼' ?> <?= abs((int)$k['delta']) ?>%</span> vs yesterday
                    <?php else: ?>
                        <?= nu_dash_h($k['note']) ?>
                    <?php endif; ?>
                </span>
            </span>
            <?php if (!empty($k['spark'])): ?><?= nu_dash_sparkline($k['spark']) ?><?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Quick actions -->
    <div class="nu-dash-actions" role="list">
        <button type="button" role="listitem" class="nu-dash-action" onclick="NuApp.loadModule('forms')"><span class="nu-dash-action-ico tone-violet"><?= nu_dash_icon('form') ?></span><span>New form</span></button>
        <button type="button" role="listitem" class="nu-dash-action" onclick="NuApp.loadModule('reports')"><span class="nu-dash-action-ico tone-green"><?= nu_dash_icon('report') ?></span><span>New report</span></button>
        <button type="button" role="listitem" class="nu-dash-action" onclick="NuApp.loadModule('queries')"><span class="nu-dash-action-ico tone-blue"><?= nu_dash_icon('query') ?></span><span>New query</span></button>
        <button type="button" role="listitem" class="nu-dash-action" onclick="NuApp.loadModule('users')"><span class="nu-dash-action-ico tone-amber"><?= nu_dash_icon('user-plus') ?></span><span>New user</span></button>
        <?php if ($isGlobeAdmin): ?>
        <button type="button" role="listitem" class="nu-dash-action" onclick="NuApp.loadModule('forms','__editmode__')"><span class="nu-dash-action-ico tone-rose"><?= nu_dash_icon('edit') ?></span><span>Edit form mode</span></button>
        <button type="button" role="listitem" class="nu-dash-action" onclick="NuApp.loadModule('inspector')"><span class="nu-dash-action-ico tone-slate"><?= nu_dash_icon('search') ?></span><span>DB inspector</span></button>
        <?php endif; ?>
    </div>

    <!-- Activity chart + feed -->
    <div class="nu-dash-split">
        <section class="nu-dash-panel">
            <header class="nu-dash-panel-head">
                <h2 class="nu-dash-panel-title">Activity · last 7 days</h2>
                <span class="nu-dash-panel-meta"><?= number_format($weekTotal) ?> events</span>
            </header>
            <div class="nu-dash-bars" role="img" aria-label="Audit events per day for the last 7 days">
                <?php foreach ($series as $day => $count):
                    $pct = (int)round($count / $maxBar * 100);
                    $isToday = $day === date('Y-m-d'); ?>
                <div class="nu-dash-bar<?= $isToday ? ' is-today' : '' ?>" title="<?= nu_dash_h(date('D j M', strtotime($day))) ?>: <?= $count ?>">
                    <span class="nu-dash-bar-val"><?= $count ?></span>
                    <span class="nu-dash-bar-fill" style="height:<?= max(3, $pct) ?>%"></span>
                    <span class="nu-dash-bar-day"><?= nu_dash_h(date('D', strtotime($day))) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="nu-dash-panel">
            <header class="nu-dash-panel-head">
                <h2 class="nu-dash-panel-title">Recent activity</h2>
                <button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" onclick="NuApp.loadModule('audit')"><span>View all</span><?= nu_dash_icon('arrow') ?></button>
            </header>
            <div class="nu-dash-filters" role="group" aria-label="Filter activity" data-nu-dash-filters>
                <button type="button" class="is-active" data-filter="all">All</button>
                <button type="button" data-filter="is-create">Created</button>
                <button type="button" data-filter="is-update">Updated</button>
                <button type="button" data-filter="is-delete">Deleted</button>
                <button type="button" data-filter="is-login">Logins</button>
            </div>
            <ul class="nu-dash-feed" data-nu-dash-feed>
                <?php foreach ($recentActivity as $log):
                    $cls  = $actionClass((string)($log['audit_action'] ?? ''));
                    $user = (string)($log['audit_username'] ?? '');
                    $init = strtoupper(substr($user !== '' ? $user : '?', 0, 1)); ?>
                <li class="nu-dash-feed-item <?= $cls ?>" data-kind="<?= $cls ?>">
                    <span class="nu-dash-avatar" aria-hidden="true"><?= nu_dash_h($init) ?></span>
                    <span class="nu-dash-feed-main">
                        <span class="nu-dash-feed-line"><strong><?= nu_dash_h($user ?: 'System') ?></strong>
                            <span class="nu-dash-pill <?= $cls ?>"><?= nu_dash_h(ucfirst((string)$log['audit_action'])) ?></span>
                            <?php if (!empty($log['audit_table'])): ?><code><?= nu_dash_h((string)$log['audit_table']) ?></code><?php endif; ?></span>
                    </span>
                    <time class="nu-dash-feed-time" datetime="<?= nu_dash_h((string)$log['audit_timestamp']) ?>" title="<?= nu_dash_h(date('M j, Y g:i A', strtotime((string)$log['audit_timestamp']))) ?>"><?= nu_dash_h(nu_dash_ago((string)$log['audit_timestamp'])) ?></time>
                </li>
                <?php endforeach; ?>
            </ul>
            <div class="nu-dash-empty" data-nu-dash-empty<?= empty($recentActivity) ? '' : ' hidden' ?>>
                <?= nu_dash_icon('inbox') ?><span><?= empty($recentActivity) ? 'No activity yet' : 'Nothing matches this filter' ?></span>
            </div>
        </section>
    </div>

    <!-- Customisable widget section -->
    <?php require __DIR__ . '/../widgets/widgets.php'; ?>
</div>
