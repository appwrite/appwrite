<?php

use Appwrite\Extend\PDO;
use Redis;
use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Adapter\MariaDB;

/** @var Utopia\Registry\Registry $register */

require_once __DIR__.'/init.php';

$register->set('db', function () {
    $dbHost = App::getEnv('_APP_DB_HOST', '');
    $dbUser = App::getEnv('_APP_DB_USER', '');
    $dbPass = App::getEnv('_APP_DB_PASS', '');
    $dbScheme = App::getEnv('_APP_DB_SCHEMA', '');

    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbScheme};charset=utf8mb4", $dbUser, $dbPass, array(
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
        PDO::ATTR_TIMEOUT => 3, // Seconds
        PDO::ATTR_PERSISTENT => true,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ));

    return $pdo;
});

$register->set('cache', function () { // Register cache connection
    $redis = new Redis();
    $redis->pconnect(App::getEnv('_APP_REDIS_HOST', ''), App::getEnv('_APP_REDIS_PORT', ''));
    $redis->setOption(Redis::OPT_READ_TIMEOUT, -1);

    return $redis;
});

/**
 * Get internal project database
 * @param string $projectId
 * @return Database
 */
function getInternalDB(string $projectId): Database
{
    global $register;

    $attempts = 0;
    $max = 10;
    $sleep = 2;

    do {
        try {
            $attempts++;
            $cache = new Cache(new RedisCache($register->get('cache')));
            $dbForInternal = new Database(new MariaDB($register->get('db')), $cache);
            $dbForInternal->setNamespace("project_{$projectId}_internal"); // Main DB
            if (!$dbForInternal->exists()) {
                throw new Exception("Table does not exist: {$dbForInternal->getNamespace()}");
            }
            break; // leave loop if successful
        } catch(\Exception $e) {
            Console::warning("Database not ready. Retrying connection ({$attempts})...");
            if ($attempts >= $max) {
                throw new \Exception('Failed to connect to database: '. $e->getMessage());
            }
            sleep($sleep);
        }
    } while ($attempts < $max);

    return $dbForInternal;
}

/**
 * Get external project database
 * @param string $projectId
 * @return Database
 */
function getExternalDB(string $projectId): Database
{
    global $register;

    $attempts = 0;
    $max = 10;
    $sleep = 2;

    do {
        try {
            $attempts++;
            $cache = new Cache(new RedisCache($register->get('cache')));
            $dbForExternal = new Database(new MariaDB($register->get('db')), $cache);
            $dbForExternal->setNamespace("project_{$projectId}_external"); // Main DB
            if (!$dbForExternal->exists()) {
                throw new Exception("Table does not exist: {$dbForExternal->getNamespace()}");
            }
            break; // leave loop if successful
        } catch(\Exception $e) {
            Console::warning("Database not ready. Retrying connection ({$attempts})...");
            if ($attempts >= $max) {
                throw new \Exception('Failed to connect to database: '. $e->getMessage());
            }
            sleep($sleep);
        }
    } while ($attempts < $max);

    return $dbForExternal;
}

/**
 * Get console database
 * @return Database
 */
function getConsoleDB(): Database
{
    global $register;

    $attempts = 0;
    $max = 5;
    $sleep = 5;

    do {
        try {
            $attempts++;
            $cache = new Cache(new RedisCache($register->get('cache')));
            $dbForConsole = new Database(new MariaDB($register->get('db')), $cache);
            $dbForConsole->setNamespace('project_console_internal'); // Main DB
            if (!$dbForConsole->exists()) {
                throw new Exception("Table does not exist: {$dbForConsole->getNamespace()}");
            }
            break; // leave loop if successful
        } catch(\Exception $e) {
            Console::warning("Database not ready. Retrying connection ({$attempts})...");
            if ($attempts >= $max) {
                throw new \Exception('Failed to connect to database: '. $e->getMessage());
            }
            sleep($sleep);
        }
    } while ($attempts < $max);

    return $dbForConsole;
}
