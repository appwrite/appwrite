<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Migration\Migration;
use Redis;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Registry\Registry;
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
            ->param('version', APP_VERSION_STABLE, new Text(8), 'Version to migrate to.', true)
            ->inject('dbForPlatform')
            ->inject('getProjectDB')
            ->inject('register')
            ->callback($this->action(...));
    }

    /**
     * @param string $version
     * @param Database $dbForPlatform
     * @param callable(Document): Database $getProjectDB
     * @param Registry $register
     * @return void
     * @throws Exception
     */
    public function action(
        string $version,
        Database $dbForPlatform,
        callable $getProjectDB,
        Registry $register,
    ): void {
        Authorization::disable();

        if (!\array_key_exists($version, Migration::$versions)) {
            Console::error("Version {$version} not found.");
            Console::exit(1);
            return;
        }

        Console::success('Starting Data Migration to version ' . $version);

        $class = 'Appwrite\\Migration\\Version\\' . Migration::$versions[$version];

        /** @var Migration $migration */
        $migration = new $class();

        $count = 0;
        $total = $dbForPlatform->count('projects') + 1;

        $dbForPlatform->foreach('projects', function (Document $project) use ($dbForPlatform, $getProjectDB, $register, $migration, &$count, $total) {
            /** @var Database $dbForProject */
            $dbForProject = $getProjectDB($project);
            $dbForProject->disableValidation();

            try {
                $migration
                    ->setProject($project, $dbForProject, $dbForPlatform, $getProjectDB)
                    ->setPDO($register->get('db', true))
                    ->execute();
            } catch (\Throwable $th) {
                Console::error('Failed to migrate project "' . $project->getId() . '" with error: ' . $th->getMessage());
                throw $th;
            }

            Console::log('Migrated ' . ++$count . '/' . $total . ' projects...');
        });

        $console = (new App('UTC'))->getResource('console');

        try {
            $migration
                ->setProject($console, $getProjectDB($console), $dbForPlatform, $getProjectDB)
                ->setPDO($register->get('db', true))
                ->execute();
        } catch (\Throwable $th) {
            Console::error('Failed to migrate project "console" with error: ' . $th->getMessage());
            throw $th;
        }

        Console::success('Migration completed');
    }
}
