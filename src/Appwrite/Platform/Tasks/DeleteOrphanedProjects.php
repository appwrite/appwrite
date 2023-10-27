<?php

namespace Appwrite\Platform\Tasks;

use PHPMailer\PHPMailer\PHPMailer;
use Utopia\App;
use Utopia\Config\Config;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\Database;
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
            ->desc('Get stats for projects')
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

        Console::title('Delete orphaned projects V1');
        Console::success(APP_NAME . ' Delete orphaned projects started');

        /** @var array $collections */
        $collectionsConfig = Config::getParam('collections', [])['projects'] ?? [];

        /* Initialise new Utopia app */
        $app = new App('UTC');
        $console = $app->getResource('console');
        $projects = [$console];

        /** Database connections */
        $totalProjects = $dbForConsole->count('projects');
        Console::success("Found a total of: {$totalProjects} projects");

        $orphans = 0;
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
                    $dbForProject->setDefaultDatabase('appwrite');
                    $dbForProject->setNamespace('_' . $project->getInternalId());
                    $collectionsCreated = $dbForProject->count(Database::METADATA);
                    $message = ' (' . $collectionsCreated . ') collections where found on project (' . $project->getId() . '))';
                    if ($collectionsCreated < ($collectionsConfig + 2)) {
                        Console::error($message);
                        $orphans++;
                    } else {
                        Console::log($message);
                    }
                } catch (\Throwable $th) {
                    //$dbForConsole->deleteDocument('projects', $project->getId());
                    //Console::success('Deleting  project (' . $project->getId() . ')');
                    Console::error(' (0) collections where found for project (' . $project->getId() . ')');
                    $orphans++;
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

        Console::log('Iterated through ' . $count - 1 . '/' . $totalProjects . ' projects found ' . $orphans . ' orphans');
    }
}
