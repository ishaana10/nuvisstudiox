<?php
declare(strict_types=1);
/**
 * Dashboard widget shell. Renders the card frames only; each card's content is a
 * fragment loaded by assets/js/nu-widgets.js from widget_api.php?action=render.
 * Widget types live in modules/widgets/types/ (see docs/widgets.md).
 */
if (!defined('NU_BOOTSTRAP_DONE')) {
    require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
}
require_once dirname(__DIR__, 2) . '/core/WidgetRegistry.php';
require_once dirname(__DIR__, 2) . '/core/WidgetPrefs.php';
require_once dirname(__DIR__, 2) . '/core/WidgetStore.php';

$nuWgUserId = NuWidgetStore::normalizeUserId($_SESSION['nu_user_id'] ?? null);
$nuWgRole   = strtolower((string)($_SESSION['nu_role'] ?? ''));
if ($nuWgUserId === null) return;

try {
    $nuWgDb       = NuDatabase::getInstance();
    $nuWgWidgets  = NuWidgetStore::visibleWidgets($nuWgDb, $nuWgUserId, $nuWgRole);
    $nuWgResolved = NuWidgetPrefs::resolve($nuWgWidgets, NuWidgetStore::loadPrefs($nuWgDb, $nuWgUserId));
    $nuWgSettings = NuWidgetStore::loadSettings($nuWgDb, $nuWgUserId);
} catch (Throwable $e) {
    error_log('[widgets] shell load error: ' . $e->getMessage());
    $nuWgResolved = ['visible' => [], 'hidden' => []];
    $nuWgSettings = NuWidgetPrefs::normalizeSettings([]);
}

$nuWgEsc  = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$nuWgCard = static function (array $w, bool $hidden) use ($nuWgEsc): string {
    $def    = NuWidgetRegistry::get((string)$w['widget_type']);
    $group  = strtolower((string)($def['group'] ?? 'data'));
    $title  = trim((string)$w['widget_title']) !== '' ? (string)$w['widget_title'] : (string)($def['label'] ?? 'Widget');
    $custom = trim((string)($w['widget_icon'] ?? ''));
    $ico    = $custom !== '' ? NuWidgetRegistry::iconHtml($custom) : '';
    if ($ico === '') $ico = NuWidgetRegistry::svgIcon((string)($def['icon'] ?? ''));
    $accent = (string)($w['accent'] ?? '');
    $isHex  = $accent !== '' && $accent[0] === '#';
    $locked = (int)($w['widget_locked'] ?? 0) === 1;
    $attrs  = ' data-widget-id="' . (int)$w['widget_id'] . '"'
            . ' data-type="' . $nuWgEsc((string)$w['widget_type']) . '"'
            . ' data-group="' . $nuWgEsc($group) . '"'
            . ' data-w="' . (int)$w['widget_width'] . '" data-h="' . (int)$w['widget_height'] . '"'
            . ' data-refresh="' . (int)($w['widget_refresh'] ?? 0) . '"'
            . ' data-locked="' . ($locked ? '1' : '0') . '"'
            . ($accent !== '' ? ($isHex ? ' data-accent-hex="' . $nuWgEsc($accent) . '"' : ' data-accent="' . $nuWgEsc($accent) . '"') : '')
            . ($hidden ? ' hidden data-hidden="1"' : '');
    return '<article class="nu-wg-card"' . $attrs . ' aria-label="' . $nuWgEsc($title) . '">'
         . '<header class="nu-wg-head"><span class="nu-wg-ico">' . $ico . '</span>'
         . '<h3 class="nu-wg-title">' . $nuWgEsc($title) . '</h3>'
         . ($locked ? '<span class="nu-wg-lock" title="Set by your administrator" aria-label="Locked by administrator">'
             . '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></span>' : '')
         . '<span class="nu-wg-tools"></span></header>'
         . '<div class="nu-wg-body"></div></article>';
};
?>
<?php $nuWgCanDesign = ($nuWgRole === 'globeadmin'); ?>
<section class="nu-wg" id="nuWidgets" aria-label="Dashboard widgets"<?php if ($nuWgCanDesign): ?>
         data-can-design="1" data-designer-src="assets/js/nu-widget-designer.js?v=<?= (int)@filemtime(dirname(__DIR__, 2) . '/assets/js/nu-widget-designer.js') ?>"<?php endif; ?>>
    <div class="nu-wg-bar">
        <h2>My dashboard</h2>
        <span class="nu-wg-status" id="nuWgStatus" role="status" aria-live="polite"></span>
        <span class="nu-wg-bar-actions" id="nuWgBarActions"></span>
    </div>
<?php if (empty($nuWgResolved['visible']) && empty($nuWgResolved['hidden'])): ?>
    <div class="nu-wg-blank">
        <strong>No widgets yet</strong>
<?php if ($nuWgCanDesign): ?>
        Use <em>Design role layout</em> to build the dashboard each role receives, or pick the developer role to set up your own.
<?php else: ?>
        Your administrator hasn't set up a dashboard for your role.
<?php endif; ?>
    </div>
<?php else: ?>
    <div class="nu-wg-grid" id="nuWidgetGrid"
         data-density="<?= $nuWgEsc((string)$nuWgSettings['density']) ?>"
         data-card-style="<?= $nuWgEsc((string)$nuWgSettings['card_style']) ?>"
         data-accent="<?= $nuWgEsc((string)($nuWgSettings['accent'] ?? '')) ?>">
<?php foreach ($nuWgResolved['visible'] as $w) echo $nuWgCard($w, false), "\n"; ?>
<?php foreach ($nuWgResolved['hidden'] as $w) echo $nuWgCard($w, true), "\n"; ?>
    </div>
<?php endif; ?>
</section>
<script>
(function () { if (window.NuWidgets && window.NuWidgets.init) window.NuWidgets.init(document.getElementById('nuWidgets') || document); })();
</script>
