<?php

/**
 * Init
 * 
 * Inializes both Appwrite API entry point, queue workers, and CLI tasks.
 * Set configuration, framework resources, app constants
 * 
 */

// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

if (file_exists(__DIR__.'/../vendor/autoload.php')) {
    require_once __DIR__.'/../vendor/autoload.php';
}

require_once 'init.php';

require_once __DIR__ . '/config/collections.php';
require_once __DIR__ . '/config/currencies.php';
require_once __DIR__ . '/config/eu.php';
require_once __DIR__ . '/config/locales.php';
require_once __DIR__ . '/config/phones.php';
require_once __DIR__ . '/config/platforms.php';
require_once __DIR__ . '/config/providers.php';
require_once __DIR__ . '/config/roles.php';
require_once __DIR__ . '/config/scopes.php';
require_once __DIR__ . '/config/services.php';

require_once __DIR__ . '/controllers/web/console.php';
require_once __DIR__ . '/controllers/web/home.php';
require_once __DIR__ . '/controllers/api/account.php';
require_once __DIR__ . '/controllers/api/avatars.php';
require_once __DIR__ . '/controllers/api/database.php';
require_once __DIR__ . '/controllers/api/graphql.php';
require_once __DIR__ . '/controllers/api/health.php';
require_once __DIR__ . '/controllers/api/locale.php';
require_once __DIR__ . '/controllers/api/projects.php';
require_once __DIR__ . '/controllers/api/storage.php';
require_once __DIR__ . '/controllers/api/teams.php';
require_once __DIR__ . '/controllers/api/users.php';

// use Appwrite\Preloader\Preloader;
// (new Preloader())
//     ->paths(realpath(__DIR__ . '/../vendor'))
//     //->paths(realpath(__DIR__ . '/config'))
//     // ->ignore(
//     //     \Illuminate\Filesystem\Cache::class,
//     //     \Illuminate\Log\LogManager::class,
//     //     \Illuminate\Http\Testing\File::class,
//     //     \Illuminate\Http\UploadedFile::class,
//     //     \Illuminate\Support\Carbon::class,
//     // )
//     ->load();