<?php

namespace Appwrite\Platform\Tasks;

use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Pools\Group;
use Utopia\Registry\Registry;
use Utopia\Validator\Boolean;

class DeleteOrphanedProjects extends Action
{
    public static function getName(): string
    {
        return 'delete-orphaned-projects';
    }

    public function __construct()
    {

        $this
            ->desc('Delete orphaned projects')
            ->param('commit', false, new Boolean(true), 'Commit  project deletion', true)
            ->inject('pools')
            ->inject('cache')
            ->inject('dbForConsole')
            ->inject('register')
            ->callback(function (bool $commit, Group $pools, Cache $cache, Database $dbForConsole, Registry $register) {
                $this->action($commit, $pools, $cache, $dbForConsole, $register);
            });
    }


    public function action(bool $commit, Group $pools, Cache $cache, Database $dbForConsole, Registry $register): void
    {

        Console::title('Delete orphaned projects V1');
        Console::success(APP_NAME . ' Delete orphaned projects started');

        /** @var array $collections */
        $collectionsConfig = Config::getParam('collections', [])['projects'] ?? [];

        $collectionsConfig = array_merge([
            'audit' => [
                '$id' => ID::custom('audit'),
                '$collection' => Database::METADATA
            ],
            'abuse' => [
                '$id' => ID::custom('abuse'),
                '$collection' => Database::METADATA
            ]
        ], $collectionsConfig);

        /* Initialise new Utopia app */
        $app = new App('UTC');
        $console = $app->getResource('console');
        $projects = [$console];

        /** Database connections */
        $totalProjects = $dbForConsole->count('projects');
        Console::success("Found a total of: {$totalProjects} projects");

        $orphans = 1;
        $cnt = 0;
        $count = 0;
        $limit = 30;
        $sum = 30;
        $offset = 0;
        while (!empty($projects)) {
            foreach ($projects as $project) {

                /**
                 * Skip user projects with id 'console'
                 */
                if ($project->getId() === 'console') {
                    continue;
                }

                try {
                    $db = $project->getAttribute('database');
                    $adapter = $pools
                        ->get($db)
                        ->pop()
                        ->getResource();

                    $dbForProject = new Database($adapter, $cache);
                    $dbForProject->setDatabase('appwrite');
                    $dbForProject->setNamespace('_' . $project->getInternalId());

                    $collectionsCreated = 0;
                    $cnt++;
                    if ($dbForProject->exists($dbForProject->getDatabase(), Database::METADATA)) {
                        $collectionsCreated = $dbForProject->count(Database::METADATA);
                    }

                    $msg = '(' . $cnt . ') found (' . $collectionsCreated . ') collections on project  (' . $project->getInternalId() . ') , database (' . $project['database'] . ')';

                    if ($collectionsCreated >= count($collectionsConfig)) {
                        Console::log($msg . ' ignoring....');
                        continue;
                    }

                    Console::log($msg);

                    if ($collectionsCreated > 0) {
                        $collections = $dbForProject->find(Database::METADATA, []);
                        foreach ($collections as $collection) {
                            if ($commit) {
                                $dbForProject->deleteCollection($collection->getId());
                                $dbForConsole->purgeCachedCollection($collection->getId());
                            }
                            Console::info('--Deleting collection  (' . $collection->getId() . ') project no (' . $project->getInternalId() . ')');
                        }
                    }

                    if ($commit) {
                        $dbForConsole->deleteDocument('projects', $project->getId());
                        $dbForConsole->purgeCachedDocument('projects', $project->getId());

                        if ($dbForProject->exists($dbForProject->getDefaultDatabase(), Database::METADATA)) {
                            try {
                                $dbForProject->deleteCollection(Database::METADATA);
                                $dbForProject->purgeCachedCollection(Database::METADATA);
                            } catch (\Throwable $th) {
                                Console::warning('Metadata collection does not exist');
                            }
                        }
                    }

                    Console::info('--Deleting project no (' . $project->getInternalId() . ')');

                    $orphans++;
                } catch (\Throwable $th) {
                    Console::error('Error: ' . $th->getMessage() . ' ' . $th->getTraceAsString());
                } finally {
                    $pools
                        ->get($db)
                        ->reclaim();
                }
            }

            $sum = \count($projects);

            $projects = $dbForConsole->find('projects', [
                Query::limit($limit),
                Query::offset($offset),
            ]);

            $offset = $offset + $limit;
            $count = $count + $sum;
        }

        Console::log('Iterated through ' . $count - 1 . '/' . $totalProjects . ' projects found ' . $orphans - 1 . ' orphans');
    }
}
