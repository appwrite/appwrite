<?php

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/controllers/general.php';

use Appwrite\CLI\Tasks;
use Utopia\CLI\CLI;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Service;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;

Authorization::disable();

CLI::setResource('register', fn()=>$register);

$attempts = 0;
$max = 10;
$sleep = 1;

do {
    try {
        $attempts++;
        $db = $register->get('db');
        $redis = $register->get('cache');
        break; // leave the do-while if successful
    } catch (\Exception $e) {
        Console::warning("Database not ready. Retrying connection ({$attempts})...");
        if ($attempts >= $max) {
            throw new \Exception('Failed to connect to database: ' . $e->getMessage());
        }
        sleep($sleep);
    }
} while ($attempts < $max);

CLI::setResource('db', fn () => $db);
CLI::setResource('cache', fn () => $redis);

CLI::setResource('dbForConsole', function ($db, $cache) {
    $cache = new Cache(new RedisCache($cache));

    $database = new Database(new MariaDB($db), $cache);
    $database->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));
    $database->setNamespace('_console');

    return $database;
}, ['db', 'cache']);

$cliPlatform = new Tasks();
$cliPlatform->init(Service::TYPE_CLI);

$cli = $cliPlatform->getCli();
$cli->run();
