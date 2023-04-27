<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Platform\Action;
use Utopia\CLI\Console;
use Appwrite\Migration\Migration;
use Appwrite\Migration\MigrationNew;
use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
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
        Console::log('Starting migration to version: ' . $version);
        $migration = new MigrationNew();
    }
}
