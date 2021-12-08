<?php

global $cli, $register, $projectDB, $console;

use Utopia\Config\Config;
use Utopia\CLI\Console;
use Appwrite\Database\Database;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Database\Adapter\MySQL as MySQLAdapter;
use Appwrite\Database\Adapter\Redis as RedisAdapter;
use Appwrite\Migration\Migration;
use Utopia\Validator\Text;

Config::load('collections.old', __DIR__.'/../config/collections.old.php');

$cli
    ->task('migrate')
    ->param('version', APP_VERSION_STABLE, new Text(8), 'Version to migrate to.', true)
    ->action(function ($version) use ($register) {
        Authorization::disable();
        if (!array_key_exists($version, Migration::$versions)) {
            Console::error("Version {$version} not found.");
            Console::exit(1);
            return;
        }

        if (str_starts_with($version, '0.12.')) {
            Console::error('WARNING');
            Console::warning('Migrating to Version 0.12.x introduces a major breaking change within the Database Service!');
            Console::warning('Before migrating, please read about the breaking changes here:');
            Console::info('https://appwrite.io/guide-to-db-migration');
            $confirm = Console::confirm("If you want to proceed, type 'yes':");
            if($confirm != 'yes') {
                Console::exit(1);
                return;
            }
        }

        Config::load('collectionsold' , __DIR__.'/../config/collections.old.php');

        Console::success('Starting Data Migration to version '.$version);

        $db = $register->get('db', true);
        $cache = $register->get('cache', true);
        $cache->flushAll();

        $consoleDB = new Database();
        $consoleDB
            ->setAdapter(new RedisAdapter(new MySQLAdapter($db, $cache), $cache))
            ->setNamespace('app_console') // Main DB
            ->setMocks(Config::getParam('collectionsold', []));

        $projectDB = new Database();
        $projectDB
            ->setAdapter(new RedisAdapter(new MySQLAdapter($db, $cache), $cache))
            ->setMocks(Config::getParam('collectionsold', []));

        $console = $consoleDB->getDocument('console');

        $limit = 30;
        $sum = 30;
        $offset = 0;
        $projects = [$console];
        $count = 0;

        $class = 'Appwrite\\Migration\\Version\\'.Migration::$versions[$version];
        $migration = new $class($register->get('db'), $register->get('cache'));

        while ($sum > 0) {
            foreach ($projects as $project) {
                try {
                    $migration
                        ->setProject($project, $projectDB, $consoleDB)
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
        $cache->flushAll();

        Console::success('Data Migration Completed');
    });
