<?php

global $cli, $register;

use Appwrite\Extend\PDO;
use Utopia\Cache\Adapter\None;
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

        //php app/cli.php process
        Authorization::disable();
        $app = new App('UTC');

        Console::error('Hello 1');

       // $db = $register->get('db', true);

        $dbHost = '127.0.0.1';
        $dbPort = '3306';
        $dbUser = 'user';
        $dbPass = 'password';
        $dbScheme = 'appwrite';

        $db = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbScheme};charset=utf8mb4", $dbUser, $dbPass, MariaDB::getPdoAttributes());
        Console::error('Hello 2');

        $cache = new Cache(new None());

//        $projectDB = new Database(new MariaDB($db), $cache);
//        $projectDB->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));

        $consoleDB = new Database(new MariaDB($db), $cache);
        $consoleDB->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));
        $consoleDB->setNamespace('_console');

        $totalProjects = $consoleDB->count('projects') + 1;
        $x = $consoleDB->getAdapter('projects') + 1;

        //Swoole\Event::wait(); // Wait for Coroutines to finish
        Console::success('Finish!');

    });
