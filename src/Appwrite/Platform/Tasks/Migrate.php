<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Migration\Migration;
use Redis;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Registry\Registry;
use Utopia\System\System;
use Utopia\Validator\Text;

class Migrate extends Action
{
    protected Redis $redis;

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
            ->inject('dbForPlatform')
            ->inject('getProjectDB')
            ->inject('register')
            ->callback(function ($version, $dbForPlatform, $getProjectDB, Registry $register) {
                \Co\run(function () use ($version, $dbForPlatform, $getProjectDB, $register) {
                    $this->action($version, $dbForPlatform, $getProjectDB, $register);
                });
            });
    }

    private function clearProjectsCache(Document $project)
    {
        try {
            $iterator = null;
            do {
                $pattern = "default-cache-_{$project->getInternalId()}:*";
                $keys = $this->redis->scan($iterator, $pattern, 1000);
                if ($keys !== false) {
                    foreach ($keys as $key) {
                        $this->redis->del($key);
                    }
                }
            } while ($iterator > 0);
        } catch (\Throwable $th) {
            Console::error('Failed to clear project ("' . $project->getId() . '") cache with error: ' . $th->getMessage());
        }
    }

    public function action(string $version, Database $dbForPlatform, callable $getProjectDB, Registry $register)
    {
        Authorization::disable();
        if (!array_key_exists($version, Migration::$versions)) {
            Console::error("Version {$version} not found.");
            Console::exit(1);

            return;
        }

        $this->redis = new Redis();
        $this->redis->connect(
            System::getEnv('_APP_REDIS_HOST', null),
            System::getEnv('_APP_REDIS_PORT', 6379),
            3,
            null,
            10
        );

        $app = new App('UTC');

        Console::success('Starting Data Migration to version ' . $version);

        $console = $app->getResource('console');

        $limit = 30;
        $sum = 30;
        $offset = 0;
        /**
         * @var \Utopia\Database\Document[] $projects
         */
        $projects = [$console];
        $count = 0;

        try {
            $totalProjects = $dbForPlatform->count('projects') + 1;
        } catch (\Throwable $th) {
            $dbForPlatform->setNamespace('_console');
            $totalProjects = $dbForPlatform->count('projects') + 1;
        }

        $class = 'Appwrite\\Migration\\Version\\' . Migration::$versions[$version];
        /** @var Migration $migration */
        $migration = new $class();

        while (!empty($projects)) {
            foreach ($projects as $project) {
                /**
                 * Skip user projects with id 'console'
                 */
                if ($project->getId() === 'console' && $project->getInternalId() !== 'console') {
                    continue;
                }

                $this->clearProjectsCache($project);

                try {
                    // TODO: Iterate through all project DBs
                    /** @var Database $projectDB */
                    $projectDB = $getProjectDB($project);
                    $projectDB->disableValidation();
                    $migration
                        ->setProject($project, $projectDB, $dbForPlatform)
                        ->setPDO($register->get('db', true))
                        ->execute();
                } catch (\Throwable $th) {
                    Console::error('Failed to update project ("' . $project->getId() . '") version with error: ' . $th->getMessage());
                    throw $th;
                }

                $this->clearProjectsCache($project);
            }

            $sum = \count($projects);
            $projects = $dbForPlatform->find('projects', [Query::limit($limit), Query::offset($offset)]);

            $offset = $offset + $limit;
            $count = $count + $sum;

            Console::log('Migrated ' . $count . '/' . $totalProjects . ' projects...');
        }

        Console::success('Data Migration Completed');
    }
}
