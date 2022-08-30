<?php

global $cli, $register;

use Appwrite\Auth\Auth;
use Appwrite\Extend\PDO;
use Utopia\Cache\Adapter\None;
use Utopia\CLI\Console;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Permission;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Validator\WhiteList;

$cli
    ->task('process')
    ->param('name', null, new WhiteList(['MariaDB'], true), 'Adapter List', false)
    ->action(function ($name) use ($register) {
        $consoleDB = null;

        $timeInSecondToTerminate = 10;

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
        foreach ($processes as $proc) {
            if ((int)$proc['Time'] > $timeInSecondToTerminate) {

                $document = Authorization::skip(fn() => $consoleDB->createDocument('processes', new Document([
                    'thread' => $proc['Id'],
                    'seconds' => $proc['Time'],
                    'user' => $proc['User'],
                    'host' => $proc['Host'],
                    'db' => $proc['Command'],
                    'state' => $proc['State'],
                    'info' => $proc['Info'],
                    'progress' => $proc['Progress'],
                    'json' => [],
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::update(Role::any()),
                        Permission::delete(Role::any()),
                    ],
                ])));

                var_dump($proc);
            }
        }
        //Swoole\Event::wait(); // Wait for Coroutines to finish
        Console::success('Finish ! 2');
    });
