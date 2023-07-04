<?php

/**
 * Init
 *
 * Initializes both Appwrite API entry point, queue workers, and CLI tasks.
 * Set configuration, framework resources & app constants
 *
 */

if (\file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

ini_set('memory_limit', '512M');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('default_socket_timeout', -1);
error_reporting(E_ALL);

require_once __DIR__ . '/init/constants.php';
require_once __DIR__ . '/init/config.php';
require_once __DIR__ . '/init/database.php';
require_once __DIR__ . '/init/redis.php';
require_once __DIR__ . '/init/registry.php';
require_once __DIR__ . '/init/locales.php';
require_once __DIR__ . '/init/app.php';

use Utopia\App;

\stream_context_set_default([ // Set global user agent and http settings
    'http' => [
        'method' => 'GET',
        'user_agent' => \sprintf(
            APP_USERAGENT,
            App::getEnv('_APP_VERSION', 'UNKNOWN'),
            App::getEnv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS', APP_EMAIL_SECURITY)
        ),
        'timeout' => 2,
    ],
]);
