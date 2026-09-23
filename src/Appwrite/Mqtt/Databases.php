<?php

namespace Appwrite\Mqtt;

use Appwrite\Utopia\Database\Documents\User;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Adapter\Pool as DatabasePool;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\DSN\DSN;
use Utopia\Pools\Group;
use Utopia\System\System;

final readonly class Databases
{
    public function __construct(
        private Group $pools,
        private Cache $cache,
    ) {
    }

    public function console(): Database
    {
        $database = new Database(new DatabasePool($this->pools->get('console')), $this->cache);
        $database
            ->setDatabase(APP_DATABASE)
            ->setNamespace('_console')
            ->setMetadata('host', \gethostname())
            ->setMetadata('project', '_console');
        $database->setDocumentType('users', User::class);

        return $database;
    }

    public function project(Document $project): Database
    {
        try {
            $dsn = new DSN($project->getAttribute('database'));
        } catch (\InvalidArgumentException) {
            $dsn = new DSN('mysql://' . $project->getAttribute('database'));
        }

        $database = new Database(new DatabasePool($this->pools->get($dsn->getHost())), $this->cache);

        $sharedTables = \explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES', ''));

        if (\in_array($dsn->getHost(), $sharedTables)) {
            $projectCollections = Config::getParam('collections', [])['projects'] ?? [];
            $globalCollections = array_keys($projectCollections);
            $globalCollections[] = 'audit';

            $database
                ->setSharedTables(true)
                ->setGlobalCollections($globalCollections)
                ->setTenant($project->getSequence())
                ->setNamespace($dsn->getParam('namespace'));
        } else {
            $database
                ->setSharedTables(false)
                ->setTenant(null)
                ->setNamespace('_' . $project->getSequence());
        }

        $database
            ->setDatabase(APP_DATABASE)
            ->setMetadata('host', \gethostname())
            ->setMetadata('project', $project->getId());
        $database->setDocumentType('users', User::class);

        return $database;
    }
}
