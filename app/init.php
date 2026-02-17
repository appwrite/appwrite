<?php

/**
 * Init
 *
 * Initializes both Appwrite API entry point, queue workers, and CLI tasks.
 * Set configuration, framework resources & app constants
 *
 */

use Utopia\System\System;

if (\file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

\ini_set('memory_limit', '512M');
\ini_set('display_errors', 1);
\ini_set('display_startup_errors', 1);
\ini_set('default_socket_timeout', -1);
\error_reporting(E_ALL);

$_memProfile = [];
$_memProfile['pre_init'] = memory_get_usage();
require_once __DIR__ . '/init/constants.php';
$_memProfile['after_constants'] = memory_get_usage();
require_once __DIR__ . '/init/configs.php';
$_memProfile['after_configs'] = memory_get_usage();
require_once __DIR__ . '/init/database/filters.php';
$_memProfile['after_db_filters'] = memory_get_usage();
require_once __DIR__ . '/init/database/formats.php';
$_memProfile['after_db_formats'] = memory_get_usage();
require_once __DIR__ . '/init/locales.php';
$_memProfile['after_locales'] = memory_get_usage();
require_once __DIR__ . '/init/registers.php';
$_memProfile['after_registers'] = memory_get_usage();
require_once __DIR__ . '/init/models.php';
$_memProfile['after_models'] = memory_get_usage();
require_once __DIR__ . '/init/resources.php';
$_memProfile['after_resources'] = memory_get_usage();

// Print init memory profile
$prev = null;
foreach ($_memProfile as $label => $mem) {
    $cost = $prev !== null ? ($mem - $prev) : 0;
    $costKB = round($cost / 1024);
    $totalMB = round($mem / 1024 / 1024, 1);
    error_log("MEMPROFILE init: {$label} = {$totalMB}MB (+" . $costKB . "KB)");
    $prev = $mem;
}
unset($_memProfile, $prev);

\stream_context_set_default([ // Set global user agent and http settings
    'http' => [
        'method' => 'GET',
        'user_agent' => \sprintf(
            APP_USERAGENT,
            System::getEnv('_APP_VERSION', 'UNKNOWN'),
            System::getEnv('_APP_EMAIL_SECURITY', System::getEnv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS', APP_EMAIL_SECURITY))
        ),
        'timeout' => 2,
    ],
]);
