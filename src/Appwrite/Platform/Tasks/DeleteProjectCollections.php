<?php

namespace Appwrite\Platform\Tasks;

use Utopia\App;
use Utopia\Platform\Action;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Pools\Group;
use Utopia\Registry\Registry;

class DeleteProjectCollections extends Action
{
    private array $names = [
        'webhooks',
        'webhooks_perms',
        'platforms',
        'schedules',
        'projects',
        'domains',
        'certificates',
        'keys',
        'realtime',
    ];

    public static function getName(): string
    {
        return 'delete-project-collections';
    }

    public function __construct()
    {

        $this
            ->desc('Delete unnecessary project collections')
            ->inject('pools')
            ->inject('cache')
            ->inject('dbForConsole')
            ->inject('register')
            ->callback(function (Group $pools, Cache $cache, Database $dbForConsole, Registry $register) {
                $this->action($pools, $cache, $dbForConsole, $register);
            });
    }

    public function action(Group $pools, Cache $cache, Database $dbForConsole, Registry $register): void
    {
        //docker compose exec -t appwrite delete-project-collections

        Console::title('Cloud Users calculation V1');
        Console::success(APP_NAME . ' cloud Users calculation has started');

        /* Initialise new Utopia app */
        $app = new App('UTC');
        $console = $app->getResource('console');

        /** Database connections */
        $totalProjects = $dbForConsole->count('projects');
        Console::success("Found a total of: {$totalProjects} projects");

        $projects = [$console];
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

                Console::info("Getting stats for {$project->getId()}");

                try {
                    $db = $project->getAttribute('database');
                    $adapter = $pools
                        ->get($db)
                        ->pop()
                        ->getResource();

                    $dbForProject = new Database($adapter, $cache);
                    $dbForProject->setDefaultDatabase('appwrite');
                    $dbForProject->setNamespace('_' . $project->getInternalId());

                    foreach ($this->names as $name) {
                        if ($dbForProject->exists('appwrite', $name)) {
                            if ($dbForProject->deleteCollection($name)) {
                                Console::log('Deleted ' . $name);
                            } else {
                                Console::error('Failed to delete ' . $name);
                            }

                            if ($dbForProject->deleteCachedCollection($name)) {
                                Console::log('Deleted (cached) ' . $name);
                            } else {
                                Console::error('Failed to delete (cached) ' . $name);
                            }
                        }
                    }
                } catch (\Throwable $th) {
                    Console::error('Failed  on project ("' . $project->getId() . '") version with error: ' . $th->getMessage());
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
        Console::log('Iterated through ' . $count - 1 . '/' . $totalProjects . ' projects...');
        $pools
            ->get('console')
            ->reclaim();
    }
}
