<?php

global $cli, $register;

use Appwrite\Extend\PDO;
use Appwrite\Migration\Migration;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\CLI\Console;
use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Validator\Text;

$cli
    ->task('process')
    ->param('version', APP_VERSION_STABLE, new Text(8), 'Version to migrate to.', false)
    ->action(function ($version) use ($register) {
        Authorization::disable();
        $app = new App('UTC');

        Console::error('Hello 1');

       // $db = $register->get('db', true);

        $dbHost = 'mariadb';
        $dbPort = '3306';
        $dbUser = 'user';
        $dbPass = 'password';
        $dbScheme = 'appwrite';

        $db = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbScheme};charset=utf8mb4", $dbUser, $dbPass, array(
            PDO::ATTR_TIMEOUT => 3, // Seconds
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::ATTR_STRINGIFY_FETCHES => true,
        ));
        Console::error('Hello 2');

        $redis = $register->get('cache', true);
        $redis->flushAll();
        $cache = new Cache(new RedisCache($redis));

        $projectDB = new Database(new MariaDB($db), $cache);
        $projectDB->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));

        $consoleDB = new Database(new MariaDB($db), $cache);
        $consoleDB->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));
        $consoleDB->setNamespace('_project_console');
        $console = $app->getResource('console');

        $totalProjects = $consoleDB->count('projects') + 1;
        Swoole\Event::wait(); // Wait for Coroutines to finish
        Console::success('Finish!');

    });
