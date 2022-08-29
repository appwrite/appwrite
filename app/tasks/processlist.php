<?php

global $cli, $register;

use Appwrite\Extend\PDO;
use Utopia\Cache\Adapter\None;
use Utopia\CLI\Console;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;

$cli
    ->task('process')
    ->action(function () use ($register) {
        // php app/cli.php process
        // $db = $register->get('db', true);
        $dbHost = '127.0.0.1';
        $dbPort = '3306';
        $dbUser = 'root';
        $dbPass = 'rootsecretpassword';
        $dbScheme = 'appwrite';

        $db = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbScheme};charset=utf8mb4", $dbUser, $dbPass, MariaDB::getPdoAttributes());

        $cache = new Cache(new None());
        $consoleDB = new Database(new MariaDB($db), $cache);
        $processes = $consoleDB->getProcessList();
        foreach ($processes as $proc) {
            var_dump($proc);
        }

        //Swoole\Event::wait(); // Wait for Coroutines to finish
        Console::success('Finish ! 2');
    });
