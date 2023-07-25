<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Platform\Action;
use Utopia\CLI\Console;
use Appwrite\Migration\Migration;
use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Pools\Group;
use Utopia\Validator\Text;
use Swoole\Event;
use Swoole\Runtime;

use function Swoole\Coroutine\batch;
use function Co\run;

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

class Migrate extends Action
{
    public static function getName(): string
    {
        return 'migrate';
    }

    public function __construct()
    {
        $this
            ->desc('Migrate Appwrite to new version')
            /** @TODO APP_VERSION_STABLE needs to be defined */
            ->param('version', APP_VERSION_STABLE, new Text(8), 'Version to migrate to.', true)
            ->inject('pools')
            ->inject('cache')
            ->inject('getProjectDB')
            ->inject('dbForConsole')
            ->callback(function (string $version, Group $pools, Cache $cache, callable $getProjectDB, Database $dbForConsole) {
                $this->action($version, $pools, $cache, $getProjectDB, $dbForConsole);
            });
    }

    private function clearProjectsCache(Cache $cache, Document $project)
    {
        try {
            $cache->purge("cache-_{$project->getInternalId()}:*");
        } catch (\Throwable $th) {
            Console::error('Failed to clear project ("' . $project->getId() . '") cache with error: ' . $th->getMessage());
        }
    }

    public function action(string $version, Group $pools, Cache $cache, callable $getProjectDB, Database $dbForConsole)
    {
        Authorization::disable();
        if (!array_key_exists($version, Migration::$versions)) {
            Console::error("Version {$version} not found.");
            Console::exit(1);
            return;
        }

        $app = new App('UTC');

        $console = $app->getResource('console');

        Console::success('Starting Data Migration to version ' . $version);

        $limit = 30;
        $offset = 0;
        /**
         * @var \Utopia\Database\Document[] $projects
         */
        $projects = [$console];
        $count = 0;
        $class = 'Appwrite\\Migration\\Version\\' . Migration::$versions[$version];
        $migration = new $class();


        try {
            $totalProjects = $dbForConsole->count('projects') + 1;
        } catch (\Throwable $th) {
            Console::error($th->getMessage());
            $dbForConsole->setNamespace('_console');
            $totalProjects = $dbForConsole->count('projects') + 1;
        }

        // while (!empty($projects)) {
        //     // Filter out projects
        //     $projects = array_filter($projects, function ($project) {
        //         return !($project->getId() === 'console' && $project->getInternalId() !== 'console') && 
        //                 $project->getAttribute('database', '') === 'database_db_fra1_02';
        //     });

        //     $tasks = array_map(function($project) use ($version, $dbForConsole, $pools, $cache) {
        //         return function() use ($project, $version, $dbForConsole, $pools, $cache) {
        //             $class = 'Appwrite\\Migration\\Version\\' . Migration::$versions[$version];
        //             $migration = new $class();

        //             try {
        //                 if ($project->getInternalId() === 'console') {
        //                     $projectDB = $dbForConsole;
        //                 } else {
        //                     $databaseName = $project->getAttribute('database','');
        //                     $dbAdapter = $pools
        //                                     ->get($databaseName)
        //                                     ->pop()
        //                                     ->getResource();

        //                     $projectDB = new Database($dbAdapter, $cache);
        //                     $projectDB->setNamespace('_' . $project->getInternalId());
        //                 }

        //                 Console::info("Migrating Project {$project->getId()}");
        //                 // $migration
        //                 //     ->setProject($project, $projectDB, $dbForConsole)
        //                 //     ->execute();
        
        //                 $this->clearProjectsCache($cache, $project);
        //             } catch (\Throwable $th) {
        //                 Console::error('Failed to update project ("' . $project->getId() . '") version with error: ' . $th->getMessage());
        //             } finally {
        //                 if ($databaseName && $dbAdapter) {
        //                     $pools
        //                         ->get($databaseName)
        //                         ->reclaim($dbAdapter);
        //                 } else {
        //                     $pools->reclaim();
        //                 }
        //             }
        //         };
        //     }, $projects);
        
        //     run(function() use ($tasks) {
        //         $results = batch($tasks);
        //         // You can now handle results if needed
        //     });
        
        //     $offset += $limit;
        //     $count += count($projects);
        //     Console::log('Migrated ' . $count . '/' . $totalProjects . ' projects...');
            
        //     $projects = $dbForConsole->find('projects', queries: [
        //         Query::limit($limit),
        //         Query::offset($offset)
        //     ]);
        // }

        while (!empty($projects)) {
            foreach ($projects as $project) {
                /**
                 * Skip user projects with id 'console'
                 */
                if ($project->getId() === 'console' && $project->getInternalId() !== 'console') {
                    continue;
                }

                // var_dump($project->getAttribute('database', ''));
                if ($project->getAttribute('database', '') !== 'database_db_fra1_02') continue;
                // $this->clearProjectsCache($cache, $project);

                try {
                    // TODO: Iterate through all project DBs
                    if ($project->getInternalId() === 'console') {
                        $projectDB = $dbForConsole;
                    } else {
                        $projectDB = $getProjectDB($project);
                    }
                    // var_dump("in Migrate.php", $projectDB->getNamespace());
                    Console::info("Migrating Project {$project->getId()}");
                    $migration
                        ->setProject($project, $projectDB, $dbForConsole)
                        ->execute();
                } catch (\Throwable $th) {
                    throw $th;
                    Console::error('Failed to update project ("' . $project->getId() . '") version with error: ' . $th->getMessage());
                }

                $this->clearProjectsCache($cache, $project);
            }

            $sum = \count($projects);
            
            $projects = $dbForConsole->find('projects', queries: [
                Query::limit($limit),
                Query::offset($offset)
            ]);

            $offset = $offset + $limit;
            $count = $count + $sum;

            Console::log('Migrated ' . $count . '/' . $totalProjects . ' projects...');
        }

        Event::wait(); // Wait for Coroutines to finish
        Console::success('Data Migration Completed');
    }
}
