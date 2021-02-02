<?php

global $cli, $register, $projectDB, $console;

use Utopia\Config\Config;
use Utopia\CLI\Console;
use Appwrite\Database\Database;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Database\Adapter\MySQL as MySQLAdapter;
use Appwrite\Database\Adapter\Redis as RedisAdapter;
use Appwrite\Migration\Version;

$cli
    ->task('migrate')
    ->action(function () use ($register) {
        Console::success('Starting Data Migration');

        $consoleDB = new Database();
        $consoleDB
            ->setAdapter(new RedisAdapter(new MySQLAdapter($register), $register))
            ->setNamespace('app_console') // Main DB
            ->setMocks(Config::getParam('collections', []));

        $projectDB = new Database();
        $projectDB
            ->setAdapter(new RedisAdapter(new MySQLAdapter($register), $register))
            ->setMocks(Config::getParam('collections', []));

        $console = $consoleDB->getDocument('console');

        Authorization::disable();

        $limit = 30;
        $sum = 30;
        $offset = 0;
        $projects = [$console];
        $count = 0;

        $migration = new Version\V06($register->get('db')); //TODO: remove hardcoded version and move to dynamic migration

        while ($sum > 0) {
            foreach ($projects as $project) {
                try {
                    $migration
                        ->setProject($project, $projectDB)
                        ->execute();
                } catch (\Throwable $th) {
                    throw $th;
                    Console::error('Failed to update project ("'.$project->getId().'") version with error: '.$th->getMessage());
                }
            }

            $projects = $consoleDB->getCollection([
                'limit' => $limit,
                'offset' => $offset,
                'filters' => [
                    '$collection='.Database::SYSTEM_COLLECTION_PROJECTS,
                ],
            ]);

            $sum = \count($projects);
            $offset = $offset + $limit;
            $count = $count + $sum;
            
            if ($sum > 0) {
                Console::log('Fetched '.$count.'/'.$consoleDB->getSum().' projects...');
            }
        }

        Console::success('Data Migration Completed');
    });
