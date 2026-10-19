<?php
declare(strict_types=1);

if (!defined('NU_ROOT')) {
    define('NU_ROOT', dirname(__DIR__));
}

require_once NU_ROOT . '/config.php';

// Configure SQLite for automated unit tests
$GLOBALS['nuConfig']['dbDriver'] = 'sqlite';
$GLOBALS['nuConfig']['dbSqlitePath'] = NU_ROOT . '/tests/test.sqlite';

require_once NU_ROOT . '/core/Cache.php';
require_once NU_ROOT . '/core/RedisSessionHandler.php';
require_once NU_ROOT . '/core/Database.php';
require_once NU_ROOT . '/core/Auth.php';
require_once NU_ROOT . '/core/Api.php';
require_once NU_ROOT . '/core/Container.php';
require_once NU_ROOT . '/core/ProjectContext.php';
