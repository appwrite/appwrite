<?php

namespace Appwrite\Database;

use Appwrite\Utopia\Database\Documents\User;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Adapter;
use Utopia\Database\Adapter\Pool as DatabasePool;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\DSN\DSN;
use Utopia\Pools\Group;
use Utopia\System\System;

class Factory
{
    public function __construct(
        protected Group $pools,
        protected Cache $cache,
        protected Authorization $authorization,
        protected string $database = APP_DATABASE,
        protected string $platformNamespace = '_console',
    ) {
    }

    public function platform(
        int $timeout = 0,
        int $maxQueryValues = 0,
        array $metadata = [],
    ): Database {
        $database = $this->newDatabase($this->adapter('console'));

        $database
            ->setDatabase($this->database)
            ->setAuthorization($this->authorization)
            ->setNamespace($this->platformNamespace);

        $this->configureDocumentTypes($database);
        $this->configureOptions($database, $timeout, $maxQueryValues, $metadata);

        return $database;
    }

    public function project(
        Document $project,
        int $timeout = 0,
        int $maxQueryValues = 0,
        array $metadata = [],
    ): Database {
        $dsn = $this->dsn($project->getAttribute('database'));
        $database = $this->newDatabase(
            $this->adapter($dsn->getHost()),
            $this->destinationFor($dsn)
        );

        $database
            ->setDatabase($this->database)
            ->setAuthorization($this->authorization);

        $this->configureDocumentTypes($database);
        $this->configureOptions($database, $timeout, $maxQueryValues, $metadata);

        return $this->configureProject($database, $project, $dsn);
    }

    public function logs(
        ?Document $project = null,
        int $timeout = 0,
        int $maxQueryValues = 0,
        array $metadata = [],
    ): Database {
        /** @var array $collections */
        $collections = Config::getParam('collections', []);
        $logsCollections = \array_keys($collections['logs'] ?? []);

        $database = $this->newDatabase($this->adapter('logs'));

        $database
            ->setDatabase($this->database)
            ->setAuthorization($this->authorization)
            ->setSharedTables(true)
            ->setGlobalCollections($logsCollections)
            ->setNamespace('logsV1');

        if ($project !== null && !$project->isEmpty() && $project->getId() !== 'console') {
            $database->setTenant($project->getSequence());
        }

        $this->configureOptions($database, $timeout, $maxQueryValues, $metadata);

        return $database;
    }

    public function tenant(
        Document $databaseDocument,
        Document $project,
        int $timeout = 0,
        int $maxQueryValues = 0,
        array $metadata = [],
        bool $preserveDates = false,
    ): Database {
        $databaseDsn = $this->dsn($databaseDocument->getAttribute('database') ?: $project->getAttribute('database', ''));
        $projectDsn = $this->dsn($project->getAttribute('database'));
        $databaseType = $databaseDocument->getAttribute('type', '');

        $database = $this->newDatabase($this->adapter($databaseDsn->getHost()));

        $database
            ->setDatabase($this->database)
            ->setAuthorization($this->authorization);

        $database->getAdapter()->setSupportForAttributes($databaseType !== DOCUMENTSDB);

        if ($preserveDates) {
            $database->setPreserveDates(true);
        }

        $this->configureOptions($database, $timeout, $maxQueryValues, $metadata);

        $sharedTables = \array_filter(\explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES', '')));
        $globalCollections = $this->projectGlobalCollections();
        $databaseHost = $databaseDsn->getHost();

        if ($databaseHost !== $projectDsn->getHost()) {
            $dbTypeSharedTables = match ($databaseType) {
                DOCUMENTSDB => \array_filter(\explode(',', System::getEnv('_APP_DATABASE_DOCUMENTSDB_SHARED_TABLES', ''))),
                VECTORSDB => \array_filter(\explode(',', System::getEnv('_APP_DATABASE_VECTORSDB_SHARED_TABLES', ''))),
                default => [],
            };

            if (\in_array($databaseHost, $dbTypeSharedTables, true)) {
                return $database
                    ->setSharedTables(true)
                    ->setGlobalCollections($globalCollections)
                    ->setTenant($project->getSequence())
                    ->setNamespace($databaseDsn->getParam('namespace'));
            }

            return $database
                ->setSharedTables(false)
                ->setTenant(null)
                ->setNamespace('_' . $project->getSequence());
        }

        if (\in_array($projectDsn->getHost(), $sharedTables, true)) {
            return $database
                ->setSharedTables(true)
                ->setGlobalCollections($globalCollections)
                ->setTenant($project->getSequence())
                ->setNamespace($projectDsn->getParam('namespace'));
        }

        return $database
            ->setSharedTables(false)
            ->setTenant(null)
            ->setNamespace('_' . $project->getSequence());
    }

    protected function newDatabase(Adapter $adapter, ?Database $destination = null): Database
    {
        return new Database($adapter, $this->cache);
    }

    protected function adapter(string $name): DatabasePool
    {
        return new DatabasePool($this->pools->get($name));
    }

    protected function configureDocumentTypes(Database $database): Database
    {
        return $database->setDocumentType('users', User::class);
    }

    protected function destinationFor(DSN $dsn): ?Database
    {
        return null;
    }

    protected function configureProject(Database $database, Document $project, DSN $dsn): Database
    {
        $sharedTables = \explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES', ''));

        if (\in_array($dsn->getHost(), $sharedTables, true)) {
            return $database
                ->setSharedTables(true)
                ->setGlobalCollections($this->projectGlobalCollections())
                ->setTenant($project->getSequence())
                ->setNamespace($dsn->getParam('namespace'));
        }

        return $database
            ->setSharedTables(false)
            ->setTenant(null)
            ->setNamespace('_' . $project->getSequence());
    }

    private function configureOptions(Database $database, int $timeout, int $maxQueryValues, array $metadata): void
    {
        foreach ($metadata as $key => $value) {
            $database->setMetadata($key, $value);
        }

        if ($timeout > 0) {
            $database->setTimeout($timeout);
        }

        if ($maxQueryValues > 0) {
            $database->setMaxQueryValues($maxQueryValues);
        }
    }

    private function dsn(?string $value): DSN
    {
        try {
            return new DSN($value ?? '');
        } catch (\InvalidArgumentException) {
            return new DSN('mysql://' . $value);
        }
    }

    private function projectGlobalCollections(): array
    {
        /** @var array $collections */
        $collections = Config::getParam('collections', []);
        $globalCollections = \array_keys($collections['projects'] ?? []);
        $globalCollections[] = 'audit';

        return $globalCollections;
    }
}
