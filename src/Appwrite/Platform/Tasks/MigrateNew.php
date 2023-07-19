<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\MigrationV2\Migration;
use Utopia\Platform\Action;
use Utopia\CLI\Console;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Pools\Group;
use Utopia\Registry\Registry;
use Utopia\Validator\Text;

class MigrateNew extends Action
{
    public static function getName(): string
    {
        return 'migratenew';
    }

    public function __construct()
    {
        $this
            ->desc('Migrate Appwrite to new version')
            ->param('from', APP_VERSION_STABLE, new Text(8), 'Version to migrate from', true)
            ->param('to', APP_VERSION_STABLE, new Text(8), 'Version to migrate to.', true)
            ->inject('register')
            ->inject('dbForConsole')
            ->inject('getProjectDB')
            ->inject('pools')
            ->callback(fn ($from, $to, $register, $dbForConsole, $getProjectDB, $pools) => $this->action($from, $to, $register, $dbForConsole, $getProjectDB, $pools));
    }

    private function clearProjectsCache(Redis $redis, Document $project)
    {
        try {
            $redis->del($redis->keys("cache-_{$project->getInternalId()}:*"));
        } catch (\Throwable $th) {
            Console::error('Failed to clear project ("' . $project->getId() . '") cache with error: ' . $th->getMessage());
        }
    }

    public function action(string $from, string $to, Registry $register, Database $dbForConsole, callable $getProjectDB, Group $pools)
    {
        Console::log('Starting migration from ' . $from . ' to ' . $to);

        $app = new App('UTC');
        $console = $app->getResource('console');
        // $redis = $register->get('cache');

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

        $migration = new Migration($from, $to);

        $fromBaseVersion = str_replace('.x', '', $from);
        $toBaseVersion = str_replace('.x', '', $to);
        $appBaseVersion = substr(APP_VERSION_STABLE, 0, strrpos(APP_VERSION_STABLE, '.'));
        
        if (version_compare($appBaseVersion, $fromBaseVersion, '==')) {
            $migration->setMode(Migration::MODE_BEFORE);
        } elseif (version_compare($appBaseVersion, $toBaseVersion, '==')) {
            $migration->setMode(Migration::MODE_AFTER);
        } else {
            throw new \Exception('Invalid version specified');
        }

        if(!$migration->confirm()) {
            Console::error('Migration aborted ... Exiting ... ');
            return;
        }

        Console::success("Starting Migration...");

        while (!empty($projects)) {
            foreach ($projects as $project) {
                try {
                    // TODO: Iterate through all project DBs
                    Console::success("Migrating project {$project->getId()}");
                    $projectDB = $getProjectDB($project);
                    $migration->setProject($project, $projectDB, $dbForConsole);
                    $migration->execute();
                } catch (\Throwable $th) {
                    throw $th;
                    Console::error("Failed to migrate project ({$project->getId()})");
                } finally {
                    $pools->reclaim();
                }

            }

            $sum = \count($projects);
            $projects = $dbForConsole->find('projects', [
                Query::limit($limit),
                Query::offset($offset),
            ]);

            $offset = $offset + $limit;
            $count = $count + $sum;

            Console::log('Migrated ' . $count . '/' . $totalProjects . ' projects...');
        }

        Console::success('Data Migration Completed');
    }
}
