<?php

global $cli, $register, $projectDB, $console;

use Utopia\Config\Config;
use Utopia\CLI\Console;
use Appwrite\Database\Database;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Database\Adapter\MySQL as MySQLAdapter;
use Appwrite\Database\Adapter\Redis as RedisAdapter;
use Utopia\Validator\Text;

$cli
    ->task('migrate')
    ->param('version', APP_VERSION_STABLE, new Text(8), 'Version to migrate to.', true)
    ->action(function ($version) use ($register) {
        $versions = [
            '0.6.0' => 'V05',
            '0.7.0' => 'V06',
            '0.8.0' => 'V07',
            '0.9.0' => 'V08',
        ];

        if (!array_key_exists($version, $versions)) {
            Console::error("Version {$version} not found.");
            Console::exit(1);
            return;
        }

        Console::success('Starting Data Migration to version '.$version);
        $db = $register->get('db', true);
        $cache = $register->get('cache', true);

        $consoleDB = new Database();
        $consoleDB
            ->setAdapter(new RedisAdapter(new MySQLAdapter($db, $cache), $cache))
            ->setNamespace('app_console') // Main DB
            ->setMocks(Config::getParam('collections', []));

        $projectDB = new Database();
        $projectDB
            ->setAdapter(new RedisAdapter(new MySQLAdapter($db, $cache), $cache))
            ->setMocks(Config::getParam('collections', []));

        $console = $consoleDB->getDocument('console');

        Authorization::disable();

        $limit = 30;
        $sum = 30;
        $offset = 0;
        $projects = [$console];
        $count = 0;

        $class = "Appwrite\\Migration\\Version\\$versions[$version]";
        $migration = new $class($register->get('db'));

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
