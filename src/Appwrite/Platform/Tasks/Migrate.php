<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Platform\Action;
use Utopia\CLI\Console;
use Appwrite\Migration\Migration;
use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Registry\Registry;
use Utopia\Validator\Text;

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
            ->inject('register')
            ->callback(fn ($version, $register) => $this->action($version, $register));
    }

    private function clearProjectsCache(Redis $redis, Document $project)
    {
        try {
            $redis->del($redis->keys("cache-_{$project->getInternalId()}:*"));
        } catch (\Throwable $th) {
            Console::error('Failed to clear project ("' . $project->getId() . '") cache with error: ' . $th->getMessage());
        }
    }

    public function action(string $version, Registry $register)
    {
        Authorization::disable();
        if (!array_key_exists($version, Migration::$versions)) {
            Console::error("Version {$version} not found.");
            Console::exit(1);
            return;
        }

        $app = new App('UTC');

        Console::success('Starting Data Migration to version ' . $version);

        $dbPool = $register->get('dbPool', true);
        $redis = $register->get('cache', true);

        $cache = new Cache(new RedisCache($redis));

        $dbForConsole = $dbPool->getDB('console', $cache);
        $dbForConsole->setNamespace('_project_console');

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
            $totalProjects = $dbForConsole->count('projects') + 1;
        } catch (\Throwable $th) {
            $dbForConsole->setNamespace('_console');
            $totalProjects = $dbForConsole->count('projects') + 1;
        }

        $class = 'Appwrite\\Migration\\Version\\' . Migration::$versions[$version];
        $migration = new $class();

        while (!empty($projects)) {
            foreach ($projects as $project) {
                /**
                 * Skip user projects with id 'console'
                 */
                if ($project->getId() === 'console' && $project->getInternalId() !== 'console') {
                    continue;
                }

                $this->clearProjectsCache($redis, $project);

                try {
                    // TODO: Iterate through all project DBs
                    $projectDB = $dbPool->getDB($project->getId(), $cache);
                    $migration
                        ->setProject($project, $projectDB, $consoleDB)
                        ->setPDO($register->get('db'))
                        ->execute();
                } catch (\Throwable $th) {
                    Console::error('Failed to update project ("' . $project->getId() . '") version with error: ' . $th->getMessage());
                    throw $th;
                }

                $this->clearProjectsCache($redis, $project);
            }

            $sum = \count($projects);
            $projects = $dbForConsole->find('projects', limit: $limit, offset: $offset);

            $offset = $offset + $limit;
            $count = $count + $sum;

            Console::log('Migrated ' . $count . '/' . $totalProjects . ' projects...');
        }

        Swoole\Event::wait(); // Wait for Coroutines to finish
        Console::success('Data Migration Completed');
    }
}
