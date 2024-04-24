<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Utopia\Queue\Connections;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Http\Adapter\FPM\Server;
use Utopia\Http\Http;
use Utopia\Http\Validator\Boolean;
use Utopia\Platform\Action;
use Utopia\Pools\Group;
use Utopia\Registry\Registry;

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
            ->inject('auth')
            ->inject('connections')
            ->callback(function (bool $commit, Group $pools, Cache $cache, Database $dbForConsole, Registry $register, Authorization $auth, Connections $connections) {
                $this->action($commit, $pools, $cache, $dbForConsole, $register, $auth, $connections);
            });
    }


    public function action(bool $commit, Group $pools, Cache $cache, Database $dbForConsole, Registry $register, Authorization $auth, Connections $connections): void
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
        $http = new Http(new Server(), 'UTC');
        $console = $http->getResource('console');
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
                    $connection = $pools->get($db)->pop();
                    $connections->add($connection);
                    $adapter = $connection->getResource();

                    $dbForProject = new Database($adapter, $cache);
                    $dbForProject->setAuthorization($auth);
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

                        if ($dbForProject->exists($dbForProject->getDatabase(), Database::METADATA)) {
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
