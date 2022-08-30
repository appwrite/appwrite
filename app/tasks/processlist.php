<?php

global $cli, $register;

use Appwrite\Extend\PDO;
use Utopia\Cache\Adapter\None;
use Utopia\CLI\Console;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;
use Utopia\Validator\WhiteList;

$cli
    ->task('process')
    ->param('name', null, new WhiteList(['MariaDB'], true), 'Adapter List', false)
    ->action(function ($name) use ($register) {
        $consoleDB = null;
        switch ($name) {
            case 'MariaDB':
                echo "i equals 0";
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
                break;
        }

        $processes = $consoleDB->getProcessList();
        foreach ($processes as $proc){
            var_dump($processes);
        }
        //Swoole\Event::wait(); // Wait for Coroutines to finish
        Console::success('Finish ! 2');
    });
