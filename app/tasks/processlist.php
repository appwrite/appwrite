<?php

global $cli, $register;

use Appwrite\Auth\Auth;
use Appwrite\Extend\PDO;
use Utopia\App;
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

        Authorization::disable();

        $consoleDB = null;
        // add comments =>  SELECT sleep(100) /* {"user":2} */;
        switch ($name) {
            case 'Mongo':
                break;
            case 'MariaDB':
                // php app/cli.php process
                // $db = $register->get('db', true);
                $dbHost = '127.0.0.1';
                $dbPort = '3306';
                $dbUser = 'root';
                $dbPass = 'rootsecretpassword';
                $dbScheme = App::getEnv('_APP_DB_SCHEMA', 'appwrite');

                $db = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbScheme};charset=utf8mb4", $dbUser, $dbPass, MariaDB::getPdoAttributes());

                $cache = new Cache(new None());
                $consoleDB = new Database(new MariaDB($db), $cache);
                $consoleDB->setDefaultDatabase($dbScheme);
                $consoleDB->setNamespace('_console');
                break;
        }

        Console::loop(function () use ($consoleDB) {
            $processes = $consoleDB->getProcessList();
            foreach ($processes as $proc) {
                $proc['Time'] = (int)$proc['Time'];
                $proc['Id'] = (int)$proc['Id'];

                $kill = false;

                if (in_array($proc['User'], ['root_', 'root_'])) {
                    continue; // We Do not want to kill internal users
                } elseif ($proc['command'] === 'sleep' && $proc['Time'] >= 28800) { // wait_timeout variable
                    continue; // let's not kill sleep at this point //PDO::ATTR_PERSISTENT = true
                } elseif ($proc['Time'] >= 0) {
                    $kill = true;
                }

                if ($kill) {
                    //$proc['Info'] = 'SELECT sleep(100) LIMIT 0, 1000 /* {"user":"xxx"} */'; // mock external comment
                    $comment = '';
                    $tmp = explode('/*', $proc['Info']);
                    if (count($tmp) > 1) {
                        $tmp = explode('*/', $tmp[count($tmp) - 1]);
                        if (count($tmp) > 1) {
                            $comment = trim($tmp[0]);
                        }
                    }

                    $json =  null;
                    $comments = json_decode($comment, true);
                    if (!empty($comments)) {
                        $json['_comment'] = $comments;
                    }

                    $doc = [
                        'thread' => $proc['Id'],
                        'seconds' => $proc['Time'],
                        'user' => $proc['User'],
                        'host' => $proc['Host'],
                        'db' => $proc['db'],
                        'command' => $proc['Command'],
                        'state' => $proc['State'],
                        'info' => $proc['Info'],
                        'progress' => $proc['Progress'],
                        'json' => $json
                    ];

                    $document = Authorization::skip(fn() => $consoleDB->createDocument('processes', new Document($doc)));
                    var_dump($document);
                }
            }
        }, 1);

        //Swoole\Event::wait(); // Wait for Coroutines to finish
        Console::success('Finish ! 2');
    });
