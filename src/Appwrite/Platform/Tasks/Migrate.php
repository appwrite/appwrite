<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Platform\Action;
use Utopia\CLI\Console;
use Appwrite\Migration\Migration;
use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Redis as RedisCache;
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
            ->param('version', APP_VERSION_STABLE, new Text(8), 'Version to migrate to.', true)
            ->inject('register')
            ->callback($this->action);
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
        $redis->flushAll();
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

                try {
                    // TODO: Iterate through all project DBs
                    $projectDB = $dbPool->getDB($project->getId(), $cache);
                    $migration
                        ->setProject($project, $projectDB, $dbForConsole)
                        ->execute();
                } catch (\Throwable $th) {
                    throw $th;
                    Console::error('Failed to update project ("' . $project->getId() . '") version with error: ' . $th->getMessage());
                }
            }

            $sum = \count($projects);
            $projects = $dbForConsole->find('projects', limit: $limit, offset: $offset);

            $offset = $offset + $limit;
            $count = $count + $sum;

            Console::log('Migrated ' . $count . '/' . $totalProjects . ' projects...');
        }

        Swoole\Event::wait(); // Wait for Coroutines to finish
        $redis->flushAll();
        Console::success('Data Migration Completed');
    }
}
