<?php

global $cli, $register;

use Utopia\CLI\Console;
use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\None;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;

#docker compose exec appwrite backup --projectId=1

$cli
    ->task('backup')
    ->param('projectId', '', new UID(), 'Project id to backupo', false)
    ->action(function ($projectId) use ($register) {
        Authorization::disable();
        $app = new App('UTC');

        Console::success('Starting Data Backup for project ' . $projectId);

        $db = $register->get('db', true);

        $cache = new Cache(new None());

        // todo: we want the adapter dynamic.
        $projectDB = new Database(new MariaDB($db), $cache);
        $projectDB->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));

        $consoleDB = new Database(new MariaDB($db), $cache);
        $consoleDB->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));
        $consoleDB->setNamespace('_console');

       // $console = $app->getResource('console');
        //$project = $consoleDB->find('projects', [Query::equal('$uid', [$projectId])]);
        $project = $consoleDB->getDocument('projects', $projectId);

        var_dump($project);

        if ($project->getId() === 'console' && $project->getInternalId() !== 'console') {
            // todo: make sure not to backup console?
        }


//        $migration
//            ->setProject($project, $projectDB, $consoleDB)
//            ->setPDO($register->get('db'))
//            ->execute();


        Swoole\Event::wait(); // Wait for Coroutines to finish
        Console::success('Complete');
    });
