<?php

global $cli, $register;

use Utopia\CLI\Console;
use Appwrite\Migration\Migration;
use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\None;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;

#docker compose exec appwrite backup --project=1

$cli
    ->task('backup')
    ->param('projectId', '', new UID(), 'Project id to backupo', false)
    ->action(function ($projectId) use ($register) {

        var_dump($projectId);
        Console::exit(1);

        Authorization::disable();
//        if (!array_key_exists($version, Migration::$versions)) {
//            Console::error("Version {$version} not found.");
//            Console::exit(1);
//            return;
//        }

        $app = new App('UTC');

//        Console::success('Starting Data Migration to version ' . $version);

        $db = $register->get('db', true);

        $cache = new Cache(new None());

        $projectDB = new Database(new MariaDB($db), $cache);
        $projectDB->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));

        $consoleDB = new Database(new MariaDB($db), $cache);
        $consoleDB->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));
        $consoleDB->setNamespace('_project_console');

        $console = $app->getResource('console');
        //$project = $consoleDB->find('projects', [Query::equal('$uid', [$projectId])]);
        $project = Authorization::skip(fn() => $consoleDB->getDocument('projects', $projectId));

        var_dump($project);

        if ($project->getId() === 'console' && $project->getInternalId() !== 'console') {
            // todo: how not to choose console.?
        }

//        $migration
//            ->setProject($project, $projectDB, $consoleDB)
//            ->setPDO($register->get('db'))
//            ->execute();

        Swoole\Event::wait(); // Wait for Coroutines to finish
        Console::success('Complete');
    });
