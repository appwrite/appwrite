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
use Utopia\Database\Document;
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
                $seconds = (int)$proc['Time'];
                $info = $proc['Info'];
                $thread = (int)$proc['Id'];
                $kill = false;
                $json =  null;

                // Cases to kill process:
                if (in_array($info, ['show full processlist', 'show full processlist'])) {
                    continue; // skip
                } elseif (in_array($proc['User'], ['root_', 'root_'])) {
                    continue; // We Do not want to kill internal users
                } elseif ($proc['Command'] === 'sleep' && $seconds >= 28800) { // wait_timeout variable
                    $kill = true; // let's not kill sleep at this point //PDO::ATTR_PERSISTENT = true
                } elseif (str_contains(strtolower($info), "select") && $seconds >= 0) {
                    $kill = true; // kill stock select
                    var_dump($info);
                } elseif ($seconds >= 0) {
                    $kill = true;
                    $kill = false;
                }

                if ($kill) {
                    //$info = 'SELECT sleep(100) LIMIT 0, 1000 /* {"user":"xxx"} */'; // mock external comment
                    $comment = '';
                    if (!empty($info)) {
                        $tmp = explode('/*', $info);
                        if (count($tmp) > 1) {
                            $tmp = explode('*/', $tmp[count($tmp) - 1]);
                            if (count($tmp) > 1) {
                                $comment = trim($tmp[0]);
                            }
                        }

                        $comments = json_decode($comment, true);
                        if (!empty($comments)) {
                            $json['_comment'] = $comments;
                        }
                    }

                    $doc = [
                        'thread' => $thread,
                        'seconds' => $seconds,
                        'user' => $proc['User'],
                        'host' => $proc['Host'],
                        'db' => $proc['db'],
                        'command' => $proc['Command'],
                        'state' => $proc['State'],
                        'info' => $info,
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
