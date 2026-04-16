<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception;
use Utopia\Database\Query;

class V19 extends Migration
{
    public function execute(): void
    {

        /**
         * Disable SubQueries for Performance.
         */
        foreach (['subQueryIndexes', 'subQueryPlatforms', 'subQueryDomains', 'subQueryKeys', 'subQueryWebhooks', 'subQuerySessions', 'subQueryTokens', 'subQueryMemberships', 'subQueryVariables'] as $name) {
            Database::addFilter(
                $name,
                fn () => null,
                fn () => []
            );
        }

        Console::log('Migrating Project: ' . $this->project->getAttribute('name') . ' (' . $this->project->getId() . ')');
        $this->dbForProject->setNamespace("_{$this->project->getSequence()}");

        Console::info('Migrating Collections');
        $this->migrateCollections();

        if ($this->project->getId() == 'console') {
            Console::info('Migrating Domains');
            $this->migrateDomains();
        }

        Console::info('Migrating Buckets');
        $this->migrateBuckets();

        if ($this->project->getId() !== 'console') {
            Console::info('Migrating Enum Attribute Size');
            $this->migrateEnumAttributeSize();
        }

        Console::info('Migrating Documents');
        $this->forEachDocument([$this, 'fixDocument']);

        Console::log('Cleaning Up Collections');
        $this->cleanCollections();
    }

    protected function migrateDomains(): void
    {
        if ($this->dbForPlatform->exists($this->dbForPlatform->getDatabase(), 'domains')) {
            foreach ($this->documentsIterator('domains') as $domain) {
                $status = 'created';
                if ($domain->getAttribute('verification', false)) {
                    $status = 'verified';
                }

                $projectId = $domain->getAttribute('projectId');
                $projectInternalId = $domain->getAttribute('projectInternalId');

                if (empty($projectId) || empty($projectInternalId)) {
                    Console::warning("Error migrating domain {$domain->getAttribute('domain')}: Missing projectId or projectInternalId");
                    continue;
                }

                $ruleDocument = new Document([
                    'projectId' => $domain->getAttribute('projectId'),
                    'projectInternalId' => $domain->getAttribute('projectInternalId'),
                    'domain' => $domain->getAttribute('domain'),
                    'resourceType' => 'api',
                    'resourceInternalId' => '',
                    'resourceId' => '',
                    'status' => $status,
                    'certificateId' => $domain->getAttribute('certificateId'),
                ]);

                try {
                    $this->dbForPlatform->createDocument('rules', $ruleDocument);
                } catch (\Throwable $th) {
                    Console::warning("Error migrating domain {$domain->getAttribute('domain')}: {$th->getMessage()}");
                }
            }
        }
    }

    /**
     * Migrating all Bucket tables.
     *
     * @return void
     * @throws \Exception
     * @throws \PDOException
     */
    protected function migrateBuckets(): void
    {
        foreach ($this->documentsIterator('buckets') as $bucket) {
            $id = "bucket_{$bucket->getSequence()}";
            Console::log("Migrating Bucket {$id} {$bucket->getId()} ({$bucket->getAttribute('name')})");

            try {
                $this->createAttributeFromCollection($this->dbForProject, $id, 'bucketInternalId', 'files');
                $this->dbForProject->purgeCachedCollection($id);
            } catch (\Throwable $th) {
                Console::warning("'bucketInternalId' from {$id}: {$th->getMessage()}");
            }
        }
    }

    /**
     * Migrate all Collections.
     *
     * @return void
     * @throws \Throwable
     * @throws Exception
     */
    private function migrateCollections(): void
    {
        $internalProjectId = $this->project->getSequence();
        $collectionType = match ($internalProjectId) {
            'console' => 'console',
            default => 'projects',
        };
        $collections = $this->collections[$collectionType];
        foreach ($collections as $collection) {
            $id = $collection['$id'];

            if ($id === 'schedules' && $internalProjectId === 'console') {
                continue;
            }

            Console::log("Migrating Collection \"{$id}\"");

            $this->dbForProject->setNamespace("_$internalProjectId");

            switch ($id) {
                case '_metadata':
                    $this->createCollection('identities');
                    $this->createCollection('migrations');
                    // Holding off on this until a future release
                    // $this->createCollection('statsLogger');
                    break;
                case 'attributes':
                case 'indexes':
                    try {
                        $this->dbForProject->updateAttribute($id, 'databaseInternalId', required: true);
                    } catch (\Throwable $th) {
                        Console::warning("'databaseInternalId' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->dbForProject->updateAttribute($id, 'collectionInternalId', required: true);
                    } catch (\Throwable $th) {
                        Console::warning("'collectionInternalId' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'error');
                    } catch (\Throwable $th) {
                        Console::warning("'error' from {$id}: {$th->getMessage()}");
                    }

                    $this->dbForProject->purgeCachedCollection($id);

                    break;
                case 'buckets':
                    // Recreate indexes so they're the right size
                    $indexesToDelete = [
                        '_key_name',
                    ];
                    foreach ($indexesToDelete as $index) {
                        try {
                            $this->dbForProject->deleteIndex($id, $index);
                        } catch (\Throwable $th) {
                            Console::warning("'$index' from {$id}: {$th->getMessage()}");
                        }
                    }

                    $indexesToCreate = [
                        ...$indexesToDelete
                    ];

                    foreach ($indexesToCreate as $index) {
                        try {
                            $this->createIndexFromCollection($this->dbForProject, $id, $index);
                        } catch (\Throwable $th) {
                            Console::warning("'$index' from {$id}: {$th->getMessage()}");
                        }
                    }

                    $this->dbForProject->purgeCachedCollection($id);

                    break;
                case 'builds':
                    $attributesToCreate = [
                        'size',
                        'deploymentInternalId',
                        'logs',
                    ];
                    foreach ($attributesToCreate as $attribute) {
                        try {
                            $this->createAttributeFromCollection($this->dbForProject, $id, $attribute);
                        } catch (\Throwable $th) {
                            Console::warning("$attribute from {$id}: {$th->getMessage()}");
                        }
                    }

                    try {
                        $this->dbForProject->renameAttribute($id, 'outputPath', 'path');
                    } catch (\Throwable $th) {
                        Console::warning("'path' from {$id}: {$th->getMessage()}");
                    }

                    $this->dbForProject->purgeCachedCollection($id);

                    break;
                case 'certificates':
                    try {
                        $this->dbForProject->renameAttribute($id, 'log', 'logs');
                    } catch (\Throwable $th) {
                        Console::warning("'logs' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->dbForProject->updateAttribute($id, 'logs', size: 1000000);
                    } catch (\Throwable $th) {
                        Console::warning("'logs' from {$id}: {$th->getMessage()}");
                    }

                    $this->dbForProject->purgeCachedCollection($id);

                    break;
                case 'databases':
                    try {
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'enabled');
                    } catch (\Throwable $th) {
                        Console::warning("'enabled' from {$id}: {$th->getMessage()}");
                    }

                    $this->dbForProject->purgeCachedCollection($id);

                    break;
                case 'deployments':
                    $attributesToCreate = [
                        'resourceInternalId',
                        'buildInternalId',
                        'commands',
                        'type',
                        'installationId',
                        'installationInternalId',
                        'providerRepositoryId',
                        'repositoryId',
                        'repositoryInternalId',
                        'providerRepositoryName',
                        'providerRepositoryOwner',
                        'providerRepositoryUrl',
                        'providerCommitHash',
                        'providerCommitAuthorUrl',
                        'providerCommitAuthor',
                        'providerCommitMessage',
                        'providerCommitUrl',
                        'providerBranch',
                        'providerBranchUrl',
                        'providerRootDirectory',
                        'providerCommentId',
                    ];
                    foreach ($attributesToCreate as $attribute) {
                        try {
                            $this->createAttributeFromCollection($this->dbForProject, $id, $attribute);
                        } catch (\Throwable $th) {
                            Console::warning("$attribute from {$id}: {$th->getMessage()}");
                        }
                    }

                    // Recreate indexes so they're the right size
                    $indexesToDelete = [
                        '_key_entrypoint',
                        '_key_resource',
                        '_key_resource_type',
                        '_key_buildId',
                    ];
                    foreach ($indexesToDelete as $index) {
                        try {
                            $this->dbForProject->deleteIndex($id, $index);
                        } catch (\Throwable $th) {
                            Console::warning("'$index' from {$id}: {$th->getMessage()}");
                        }
                    }

                    $indexesToCreate = [
                        '_key_resource',
                        '_key_resource_type',
                        '_key_buildId',
                    ];
                    foreach ($indexesToCreate as $index) {
                        try {
                            $this->createIndexFromCollection($this->dbForProject, $id, $index);
                        } catch (\Throwable $th) {
                            Console::warning("'$index' from {$id}: {$th->getMessage()}");
                        }
                    }

                    $this->dbForProject->purgeCachedCollection($id);

                    break;
                case 'executions':
                    $attributesToCreate = [
                        'functionInternalId',
                        'deploymentInternalId',
                        'requestMethod',
                        'requestPath',
                        'requestHeaders',
                        'responseHeaders',
                    ];
                    foreach ($attributesToCreate as $attribute) {
                        try {
                            $this->createAttributeFromCollection($this->dbForProject, $id, $attribute);
                        } catch (\Throwable $th) {
                            Console::warning("$attribute from {$id}: {$th->getMessage()}");
                        }
                    }

                    $attributesToDelete = [
                        'response'
                    ];
                    foreach ($attributesToDelete as $attribute) {
                        try {
                            $this->dbForProject->deleteAttribute($id, $attribute);
                        } catch (\Throwable $th) {
                            Console::warning("'$attribute' from {$id}: {$th->getMessage()}");
                        }
                    }

                    try {
                        $this->dbForProject->renameAttribute($id, 'stderr', 'errors');
                    } catch (\Throwable $th) {
                        Console::warning("'errors' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->dbForProject->renameAttribute($id, 'stdout', 'logs');
                    } catch (\Throwable $th) {
                        Console::warning("'logs' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->dbForProject->renameAttribute($id, 'statusCode', 'responseStatusCode');
                    } catch (\Throwable $th) {
                        Console::warning("'responseStatusCode' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->dbForProject->deleteIndex($id, '_key_statusCode');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_statusCode' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->createIndexFromCollection($this->dbForProject, $id, '_key_responseStatusCode');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_responseStatusCode' from {$id}: {$th->getMessage()}");
                    }

                    $this->dbForProject->purgeCachedCollection($id);

                    break;
                case 'files':
                    // Recreate indexes so they're the right size
                    $indexesToDelete = [
                        '_key_name',
                        '_key_signature',
                        '_key_mimeType',
                    ];
                    foreach ($indexesToDelete as $index) {
                        try {
                            $this->dbForProject->deleteIndex($id, $index);
                        } catch (\Throwable $th) {
                            Console::warning("'$index' from {$id}: {$th->getMessage()}");
                        }
                    }

                    $indexesToCreate = $indexesToDelete;
                    foreach ($indexesToCreate as $index) {
                        try {
                            $this->createIndexFromCollection($this->dbForProject, $id, $index);
                        } catch (\Throwable $th) {
                            Console::warning("'$index' from {$id}: {$th->getMessage()}");
                        }
                    }

                    $this->dbForProject->purgeCachedCollection($id);

                    break;
                case 'functions':
                    $attributesToCreate = [
                        'live',
                        'installationId',
                        'installationInternalId',
                        'providerRepositoryId',
                        'repositoryId',
                        'repositoryInternalId',
                        'providerBranch',
                        'providerRootDirectory',
                        'providerSilentMode',
                        'logging',
                        'deploymentInternalId',
                        'schedule',
                        'scheduleInternalId',
                        'scheduleId',
                        'version',
                        'entrypoint',
                        'commands',
                        'varsProject'
                    ];
                    foreach ($attributesToCreate as $attribute) {
                        try {
                            $this->createAttributeFromCollection($this->dbForProject, $id, $attribute);
                        } catch (\Throwable $th) {
                            Console::warning("'$attribute' from {$id}: {$th->getMessage()}");
                        }
                    }

                    // Recreate indexes so they're the right size
                    $indexesToDelete = [
                        '_key_name',
                        '_key_runtime',
                        '_key_deployment',
                    ];
                    foreach ($indexesToDelete as $index) {
                        try {
                            $this->dbForProject->deleteIndex($id, $index);
                        } catch (\Throwable $th) {
                            Console::warning("'$index' from {$id}: {$th->getMessage()}");
                        }
                    }

                    $indexesToCreate = [
                        ...$indexesToDelete,
                        '_key_installationId',
                        '_key_installationInternalId',
                        '_key_providerRepositoryId',
                        '_key_repositoryId',
                        '_key_repositoryInternalId',
                    ];
                    foreach ($indexesToCreate as $index) {
                        try {
                            $this->createIndexFromCollection($this->dbForProject, $id, $index);
                        } catch (\Throwable $th) {
                            Console::warning("'$index' from {$id}: {$th->getMessage()}");
                        }
                    }

                    $this->dbForProject->purgeCachedCollection($id);

                    break;
                case 'memberships':
                    try {
                        $this->dbForProject->updateAttribute($id, 'teamInternalId', required: true);
                    } catch (\Throwable $th) {
                        Console::warning("'teamInternalId' from {$id}: {$th->getMessage()}");
                    }

                    $this->dbForProject->purgeCachedCollection($id);

                    // Intentional fall through to update memberships.userInternalId
                case 'sessions':
                case 'tokens':
                    try {
                        $this->dbForProject->updateAttribute($id, 'userInternalId', required: true);
                    } catch (\Throwable $th) {
                        Console::warning("'userInternalId' from {$id}: {$th->getMessage()}");
                    }

                    $this->dbForProject->purgeCachedCollection($id);

                    break;
                case 'domains':
                case 'keys':
                case 'platforms':
                case 'webhooks':
                    try {
                        $this->dbForProject->updateAttribute($id, 'projectInternalId', required: true);
                    } catch (\Throwable $th) {
                        Console::warning("'projectInternalId' from {$id}: {$th->getMessage()}");
                    }

                    $this->dbForProject->purgeCachedCollection($id);

                    break;
                case 'projects':
                    $attributesToCreate = [
                        'database',
                        'smtp',
                        'templates',
                    ];
                    foreach ($attributesToCreate as $attribute) {
                        try {
                            $this->createAttributeFromCollection($this->dbForProject, $id, $attribute);
                        } catch (\Throwable $th) {
                            Console::warning("'$attribute' from {$id}: {$th->getMessage()}");
                            Console::warning($th->getTraceAsString());
                        }
                    }

                    $this->dbForProject->purgeCachedCollection($id);

                    break;
                case 'stats':
                    try {
                        $this->dbForProject->updateAttribute($id, 'value', signed: true);
                    } catch (\Throwable $th) {
                        Console::warning("'value' from {$id}: {$th->getMessage()}");
                    }

                    // Holding off on these until a future release
                    // try {
                    //     $this->projectDB->deleteAttribute($id, 'type');
                    //     $this->projectDB->purgeCachedCollection($id);
                    // } catch (\Throwable $th) {
                    //     Console::warning("'type' from {$id}: {$th->getMessage()}");
                    // }

                    // try {
                    //     $this->projectDB->deleteIndex($id, '_key_metric_period_time');
                    //     $this->projectDB->purgeCachedCollection($id);
                    // } catch (\Throwable $th) {
                    //     Console::warning("'_key_metric_period_time' from {$id}: {$th->getMessage()}");
                    // }

                    // try {
                    //     $this->createIndexFromCollection($this->projectDB, $id, '_key_metric_period_time');
                    //     $this->projectDB->purgeCachedCollection($id);
                    // } catch (\Throwable $th) {
                    //     Console::warning("'_key_metric_period_time' from {$id}: {$th->getMessage()}");
                    // }

                    $this->dbForProject->purgeCachedCollection($id);

                    break;
                case 'users':
                    try {
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'labels');
                    } catch (\Throwable $th) {
                        Console::warning("'labels' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'accessedAt');
                    } catch (\Throwable $th) {
                        Console::warning("'accessedAt' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->dbForProject->updateAttribute($id, 'search', filters: ['userSearch']);
                    } catch (\Throwable $th) {
                        Console::warning("'search' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->createIndexFromCollection($this->dbForProject, $id, '_key_accessedAt');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_accessedAt' from {$id}: {$th->getMessage()}");
                    }

                    $this->dbForProject->purgeCachedCollection($id);

                    break;
                case 'variables':
                    try {
                        $this->dbForProject->deleteIndex($id, '_key_function');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_function' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->dbForProject->deleteIndex($id, '_key_uniqueKey');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_uniqueKey' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'resourceType');
                    } catch (\Throwable $th) {
                        Console::warning("'resourceType' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->dbForProject->renameAttribute($id, 'functionInternalId', 'resourceInternalId');
                    } catch (\Throwable $th) {
                        Console::warning("'resourceInternalId' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->dbForProject->renameAttribute($id, 'functionId', 'resourceId');
                    } catch (\Throwable $th) {
                        Console::warning("'resourceId' from {$id}: {$th->getMessage()}");
                    }

                    $indexesToCreate = [
                        '_key_resourceInternalId',
                        '_key_resourceId_resourceType',
                        '_key_resourceType',
                        '_key_uniqueKey',
                    ];
                    foreach ($indexesToCreate as $index) {
                        try {
                            $this->createIndexFromCollection($this->dbForProject, $id, $index);
                        } catch (\Throwable $th) {
                            Console::warning("'$index' from {$id}: {$th->getMessage()}");
                        }
                    }

                    $this->dbForProject->purgeCachedCollection($id);

                    break;
                default:
                    break;
            }

            usleep(50000);
        }
    }

    private function getFunctionCommands(Document $function): string
    {
        $runtime = $function->getAttribute('runtime');
        $language = explode('-', $runtime)[0];
        $commands = match ($language) {
            'dart' => 'dart pub get',
            'deno' => 'deno cache ' . $function->getAttribute('entrypoint'),
            'dotnet' => 'dotnet restore',
            'node' => 'npm install',
            'php' => 'composer install',
            'python' => 'pip install -r requirements.txt',
            'ruby' => 'bundle install',
            'swift' => 'swift package resolve',
            default => '',
        };

        return $commands;
    }

    private function migrateEnumAttributeSize(): void
    {
        foreach (
            $this->documentsIterator('attributes', [
                Query::equal('format', ['enum']),
                Query::lessThan('size', Database::LENGTH_KEY)
            ]) as $attribute
        ) {
            $attribute->setAttribute('size', Database::LENGTH_KEY);
            $this->dbForProject->updateDocument('attributes', $attribute->getId(), $attribute);
            $databaseInternalId = $attribute->getAttribute('databaseInternalId');
            $collectionInternalId = $attribute->getAttribute('collectionInternalId');
            $this->dbForProject->updateAttribute('database_' . $databaseInternalId . '_collection_' . $collectionInternalId, $attribute->getAttribute('key'), size: 255);
        }
    }

    /**
     * Fix run on each document
     *
     * @param Document $document
     * @return Document
     */
    protected function fixDocument(Document $document): Document
    {
        switch ($document->getCollection()) {
            case 'attributes':
            case 'indexes':
                $status = $document->getAttribute('status');
                if ($status === 'failed') {
                    $document->setAttribute('error', $document->getAttribute('error', 'Unknown problem'));
                }
                break;
            case 'builds':
                $deploymentId = $document->getAttribute('deploymentId');
                $deployment = $this->dbForProject->getDocument('deployments', $deploymentId);
                $document->setAttribute('deploymentInternalId', $deployment->getSequence());

                $stdout = $document->getAttribute('stdout', '');
                $stderr = $document->getAttribute('stderr', '');
                $document->setAttribute('logs', $document->getAttribute('logs', $stdout . PHP_EOL . $stderr));
                break;
            case 'databases':
                $document->setAttribute('enabled', $document->getAttribute('enabled', true));
                break;
            case 'deployments':
                $resourceId = $document->getAttribute('resourceId');
                $function = $this->dbForProject->getDocument('functions', $resourceId);
                $document->setAttribute('resourceInternalId', $function->getSequence());

                $buildId = $document->getAttribute('buildId');
                if (!empty($buildId)) {
                    $build = $this->dbForProject->getDocument('builds', $buildId);
                    $document->setAttribute('buildInternalId', $build->getSequence());
                }

                $commands = $this->getFunctionCommands($function);
                $document->setAttribute('commands', $document->getAttribute('commands', $commands));
                $document->setAttribute('type', $document->getAttribute('type', 'manual'));
                break;
            case 'executions':
                $functionId = $document->getAttribute('functionId');
                $function = $this->dbForProject->getDocument('functions', $functionId);
                $document->setAttribute('functionInternalId', $function->getSequence());

                $deploymentId = $document->getAttribute('deploymentId');
                $deployment = $this->dbForProject->getDocument('deployments', $deploymentId);
                $document->setAttribute('deploymentInternalId', $deployment->getSequence());
                break;
            case 'functions':
                $document->setAttribute('live', $document->getAttribute('live', true));
                $document->setAttribute('logging', $document->getAttribute('logging', true));
                $document->setAttribute('version', $document->getAttribute('version', 'v2'));
                $deploymentId = $document->getAttribute('deployment');

                if (!empty($deploymentId)) {
                    $deployment = $this->dbForProject->getDocument('deployments', $deploymentId);
                    $document->setAttribute('deploymentInternalId', $deployment->getSequence());
                    $document->setAttribute('entrypoint', $deployment->getAttribute('entrypoint'));
                }

                $commands = $this->getFunctionCommands($document);
                $document->setAttribute('commands', $document->getAttribute('commands', $commands));

                if (empty($document->getAttribute('scheduleId', null))) {
                    $schedule = $this->dbForPlatform->createDocument('schedules', new Document([
                        'region' => $this->project->getAttribute('region'),
                        'resourceType' => SCHEDULE_RESOURCE_TYPE_FUNCTION,
                        'resourceId' => $document->getId(),
                        'resourceInternalId' => $document->getSequence(),
                        'resourceUpdatedAt' => DateTime::now(),
                        'projectId' => $this->project->getId(),
                        'schedule'  => $document->getAttribute('schedule'),
                        'active' => !empty($document->getAttribute('schedule')) && !empty($document->getAttribute('deployment')),
                    ]));

                    $document->setAttribute('scheduleId', $schedule->getId());
                    $document->setAttribute('scheduleInternalId', $schedule->getSequence());
                }

                break;
            case 'projects':
                $document->setAttribute('version', '1.4.0');

                $databases = Config::getParam('pools-database', []);
                $database = $databases[0];

                $document->setAttribute('database', $document->getAttribute('database', $database));
                $document->setAttribute('smtp', $document->getAttribute('smtp', []));
                $document->setAttribute('templates', $document->getAttribute('templates', []));

                break;
            case 'variables':
                $document->setAttribute('resourceType', $document->getAttribute('resourceType', 'function'));
                break;
            default:
                break;
        }

        return $document;
    }

    private function cleanCollections(): void
    {
        try {
            $this->dbForProject->deleteAttribute('projects', 'domains');
        } catch (\Throwable $th) {
            Console::warning("'domains' from projects: {$th->getMessage()}");
        }

        $this->dbForProject->purgeCachedCollection('projects');

        try {
            $this->dbForProject->deleteAttribute('builds', 'stderr');
        } catch (\Throwable $th) {
            Console::warning("'stderr' from builds: {$th->getMessage()}");
        }

        try {
            $this->dbForProject->deleteAttribute('builds', 'stdout');
        } catch (\Throwable $th) {
            Console::warning("'stdout' from builds: {$th->getMessage()}");
        }

        $this->dbForProject->purgeCachedCollection('builds');
    }

    /**
     * Overwrite parent to skip cache collection as well
     *
     * @param callable $callback
     * @return void
     */
    public function forEachDocument(callable $callback): void
    {
        $internalProjectId = $this->project->getSequence();

        $collections = match ($internalProjectId) {
            'console' => $this->collections['console'],
            default => $this->collections['projects'],
        };

        foreach ($collections as $collection) {
            // Also skip cache collection because the we don't need to migrate
            // it and the $ids cause issues with the cursor pagination
            if ($collection['$collection'] !== Database::METADATA || $collection['$id'] === 'cache') {
                continue;
            }

            Console::log('Migrating Collection ' . $collection['$id'] . ':');

            foreach ($this->documentsIterator($collection['$id']) as $document) {
                go(function (Document $document, callable $callback) {
                    if (empty($document->getId()) || empty($document->getCollection())) {
                        return;
                    }

                    $old = $document->getArrayCopy();
                    $new = call_user_func($callback, $document);

                    if (is_null($new) || $new->getArrayCopy() == $old) {
                        return;
                    }

                    try {
                        $this->dbForProject->updateDocument($document->getCollection(), $document->getId(), $document);
                    } catch (\Throwable $th) {
                        Console::error('Failed to update document: ' . $th->getMessage());
                        return;
                    }
                }, $document, $callback);
            }
        }
    }
}
