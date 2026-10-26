<?php
declare(strict_types=1);
/**
 * modules/dashboard/dashboard_user.php
 * USER DASHBOARD — all non-admin roles.
 *
 * NOT an entry point. Included by index.php or dashboard.php.
 * Bootstrap has already run. Do NOT add require_once bootstrap here.
 */
if (!defined('NU_BOOTSTRAP_DONE')) {
    require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
}

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/_dash_helpers.php';

$fullName = (string)($_SESSION['nu_name'] ?? $_SESSION['nu_username'] ?? 'there');
$username = trim(explode(' ', $fullName)[0]) ?: 'there';
$role     = ucfirst(strtolower((string)($_SESSION['nu_role'] ?? '')));
?>

<div class="nu-dashboard nu-dash" data-nu-dash>
    <?= nu_dash_hero($username, $role) ?>

    <!-- Widget builder -->
    <?php require __DIR__ . '/../widgets/widgets.php'; ?>
</div>
