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
use Utopia\Validator\Boolean;
use Utopia\Validator\Hostname;

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
            ->param('commit', false, new boolean(true), 'Commit  project deletion', true)
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

        /* Initialise new Utopia app */
        $app = new App('UTC');
        $console = $app->getResource('console');
        $projects = [$console];

        /** Database connections */
        $totalProjects = $dbForConsole->count('projects');
        Console::success("Found a total of: {$totalProjects} projects");

        $orphans = 1;
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
                    if ($collectionsCreated === 0) {
                        if ($commit === true) {
                            Console::info('(' . $orphans . ') deleting project (' . $project->getId() . ')');
                            $this->deleteProject($dbForConsole, $project->getId());
                        } else {
                            Console::log('(' . $orphans . ') project (' . $project->getId() . ')');
                        }
                        $orphans++;
                    }
                } catch (\Throwable $th) {
                    if ($commit === true) {
                        Console::info('(' . $orphans . ') deleting project (' . $project->getId() . ')');
                        $this->deleteProject($dbForConsole, $project->getId());
                    } else {
                        Console::log('(' . $orphans . ') project (' . $project->getId() . ')');
                    }
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

        Console::log('Iterated through ' . $count - 1 . '/' . $totalProjects . ' projects found ' . $orphans - 1 . ' orphans');
    }

    private function deleteProject(Database $dbForConsole, $projectId): void
    {
        try {
            $dbForConsole->deleteDocument('projects', $projectId);
        } catch (\Throwable $th) {
            Console::error('Error when trying to delete project (' . $projectId . ') ' . $th->getMessage());
        }
    }
}
