<?php

namespace Appwrite\Platform\Tasks;

use Utopia\App;
use Utopia\Cache\Adapter\Filesystem;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization;
use Utopia\Exception;
use Utopia\Platform\Action;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Pools\Group;
use Utopia\Registry\Registry;

class PurgeCache extends Action
{
    public static function getName(): string
    {
        return 'purge-cache';
    }

    public function __construct()
    {

        $this
            ->desc('Get stats for projects')
            ->inject('pools')
            ->inject('cache')
            ->inject('dbForConsole')
            ->inject('register')
            ->callback(function (Group $pools, Cache $cache, Database $dbForConsole, Registry $register) {
                $this->action($pools, $cache, $dbForConsole, $register);
            });
    }

    /**
     * @throws Exception
     */
    public function action(Group $pools, Cache $cache, Database $dbForConsole, Registry $register): void
    {
        //docker compose exec -t appwrite purge-cache

        Console::title('Cache purge V1');
        Console::success(APP_NAME . ' cache purge has started');

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

                Console::info("Getting cache files for project {$project->getId()}");

                try {
                    $db = $project->getAttribute('database');
                    $adapter = $pools
                        ->get($db)
                        ->pop()
                        ->getResource();

                    $dbForProject = new Database($adapter, $cache);
                    $dbForProject->setDefaultDatabase('appwrite');
                    $dbForProject->setNamespace('_' . $project->getInternalId());
                    $projectId = $project->getId();

                    $files = new Cache(
                        new Filesystem(APP_STORAGE_CACHE . DIRECTORY_SEPARATOR . 'app-' . $projectId)
                    );

                    $this->deleteByGroup(
                        [],
                        $dbForProject,
                        function (Document $document) use ($files, $projectId) {
                            $path = APP_STORAGE_CACHE . DIRECTORY_SEPARATOR . 'app-' . $projectId . DIRECTORY_SEPARATOR . $document->getId();

                            if ($files->purge($document->getId())) {
                                Console::success('Deleting cache file: ' . $path);
                            } else {
                                Console::error('Failed to delete cache file: ' . $path);
                            }
                        }
                    );
                } catch (\Throwable $th) {
                    Console::error('Failed to fetch project ("' . $project->getId() . '") version with error: ' . $th->getMessage());
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

    /**
     * @param Query[] $queries
     * @param Database $dbForProject
     * @param callable|null $callback
     * @throws Authorization
     */
    private function deleteByGroup(array $queries, Database $dbForProject, callable $callback = null): void
    {
        $count = 0;
        $chunk = 0;
        $limit = 50;
        $results = [];
        $sum = $limit;

        $executionStart = \microtime(true);

        while ($sum === $limit) {
            $chunk++;
            $results = $dbForProject->find('cache', \array_merge([Query::limit($limit)], $queries));
            $sum = count($results);

            Console::info('Deleting chunk #' . $chunk . '. Found ' . $sum . ' documents');

            foreach ($results as $document) {
                if ($dbForProject->deleteDocument($document->getCollection(), $document->getId())) {
                    Console::success('Deleted document "' . $document->getId() . '" successfully');

                    if (is_callable($callback)) {
                        $callback($document);
                    }
                } else {
                    Console::error('Failed to delete document: ' . $document->getId());
                }
                $count++;
            }
        }

        $executionEnd = \microtime(true);
        Console::info("Deleted {$count} document by group in " . ($executionEnd - $executionStart) . " seconds");
    }
}
