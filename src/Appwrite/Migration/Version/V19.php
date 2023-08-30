<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception;

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
        $this->projectDB->setNamespace("_{$this->project->getInternalId()}");

        Console::info('Migrating Collections');
        $this->migrateCollections();

        Console::info('Migrating Buckets');
        $this->migrateBuckets();

        Console::info('Migrating Documents');
        $this->forEachDocument([$this, 'fixDocument']);

        Console::log('Cleaning Up Collections');
        $this->cleanCollections();
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
            $id = "bucket_{$bucket->getInternalId()}";
            Console::log("Migrating Bucket {$id} {$bucket->getId()} ({$bucket->getAttribute('name')})");

            try {
                $this->createAttributeFromCollection($this->projectDB, $id, 'bucketInternalId', 'files');
                $this->projectDB->deleteCachedCollection($id);
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
        $internalProjectId = $this->project->getInternalId();
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

            $this->projectDB->setNamespace("_$internalProjectId");

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
                        $this->projectDB->updateAttribute($id, 'databaseInternalId', required: true);
                    } catch (\Throwable $th) {
                        Console::warning("'databaseInternalId' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->projectDB->updateAttribute($id, 'collectionInternalId', required: true);
                    } catch (\Throwable $th) {
                        Console::warning("'collectionInternalId' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'error');
                    } catch (\Throwable $th) {
                        Console::warning("'error' from {$id}: {$th->getMessage()}");
                    }

                    $this->projectDB->deleteCachedCollection($id);

                    break;
                case 'buckets':
                    // Recreate indexes so they're the right size
                    $indexesToDelete = [
                        '_key_name',
                    ];
                    foreach ($indexesToDelete as $index) {
                        try {
                            $this->projectDB->deleteIndex($id, $index);
                        } catch (\Throwable $th) {
                            Console::warning("'$index' from {$id}: {$th->getMessage()}");
                        }
                    }

                    $indexesToCreate = [
                        ...$indexesToDelete
                    ];

                    foreach ($indexesToCreate as $index) {
                        try {
                            $this->createIndexFromCollection($this->projectDB, $id, $index);
                        } catch (\Throwable $th) {
                            Console::warning("'$index' from {$id}: {$th->getMessage()}");
                        }
                    }

                    $this->projectDB->deleteCachedCollection($id);

                    break;
                case 'builds':
                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'deploymentInternalId');
                    } catch (\Throwable $th) {
                        Console::warning("'deploymentInternalId' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'logs');
                    } catch (\Throwable $th) {
                        Console::warning("'logs' from {$id}: {$th->getMessage()}");
                    }

                    $this->projectDB->deleteCachedCollection($id);

                    break;
                case 'certificates':
                    try {
                        $this->projectDB->renameAttribute($id, 'log', 'logs');
                    } catch (\Throwable $th) {
                        Console::warning("'errors' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->projectDB->updateAttribute($id, 'logs', size: 1000000);
                    } catch (\Throwable $th) {
                        Console::warning("'errors' from {$id}: {$th->getMessage()}");
                    }

                    $this->projectDB->deleteCachedCollection($id);

                    break;
                case 'databases':
                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'enabled');
                    } catch (\Throwable $th) {
                        Console::warning("'enabled' from {$id}: {$th->getMessage()}");
                    }

                    $this->projectDB->deleteCachedCollection($id);

                    break;
                case 'deployments':
                    $attributesToCreate = [
                        'resourceInternalId',
                        'buildInternalId',
                        'type',
                    ];
                    foreach ($attributesToCreate as $attribute) {
                        try {
                            $this->createAttributeFromCollection($this->projectDB, $id, $attribute);
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
                            $this->projectDB->deleteIndex($id, $index);
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
                            $this->createIndexFromCollection($this->projectDB, $id, $index);
                        } catch (\Throwable $th) {
                            Console::warning("'$index' from {$id}: {$th->getMessage()}");
                        }
                    }

                    $this->projectDB->deleteCachedCollection($id);

                    break;
                case 'executions':
                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'functionInternalId');
                    } catch (\Throwable $th) {
                        Console::warning("'functionInternalId' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'deploymentInternalId');
                    } catch (\Throwable $th) {
                        Console::warning("'deploymentInternalId' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->projectDB->renameAttribute($id, 'stderr', 'errors');
                    } catch (\Throwable $th) {
                        Console::warning("'errors' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->projectDB->renameAttribute($id, 'stdout', 'logs');
                    } catch (\Throwable $th) {
                        Console::warning("'logs' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->projectDB->renameAttribute($id, 'statusCode', 'responseStatusCode');
                    } catch (\Throwable $th) {
                        Console::warning("'responseStatusCode' from {$id}: {$th->getMessage()}");
                    }

                    $this->projectDB->deleteCachedCollection($id);

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
                            $this->projectDB->deleteIndex($id, $index);
                        } catch (\Throwable $th) {
                            Console::warning("'$index' from {$id}: {$th->getMessage()}");
                        }
                    }

                    $indexesToCreate = $indexesToDelete;
                    foreach ($indexesToCreate as $index) {
                        try {
                            $this->createIndexFromCollection($this->projectDB, $id, $index);
                        } catch (\Throwable $th) {
                            Console::warning("'$index' from {$id}: {$th->getMessage()}");
                        }
                    }

                    $this->projectDB->deleteCachedCollection($id);

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
                        'scheduleInternalId',
                        'scheduleId',
                        'version',
                        'entrypoint',
                        'commands',
                    ];
                    foreach ($attributesToCreate as $attribute) {
                        try {
                            $this->createAttributeFromCollection($this->projectDB, $id, $attribute);
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
                            $this->projectDB->deleteIndex($id, $index);
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
                            $this->createIndexFromCollection($this->projectDB, $id, $index);
                        } catch (\Throwable $th) {
                            Console::warning("'$index' from {$id}: {$th->getMessage()}");
                        }
                    }

                    $this->projectDB->deleteCachedCollection($id);

                    break;
                case 'memberships':
                    try {
                        $this->projectDB->updateAttribute($id, 'teamInternalId', required: true);
                    } catch (\Throwable $th) {
                        Console::warning("'teamInternalId' from {$id}: {$th->getMessage()}");
                    }

                    $this->projectDB->deleteCachedCollection($id);

                    // Intentional fall through to update memberships.userInternalId
                case 'sessions':
                case 'tokens':
                    try {
                        $this->projectDB->updateAttribute($id, 'userInternalId', required: true);
                    } catch (\Throwable $th) {
                        Console::warning("'userInternalId' from {$id}: {$th->getMessage()}");
                    }

                    $this->projectDB->deleteCachedCollection($id);

                    break;
                case 'domains':
                case 'keys':
                case 'platforms':
                case 'webhooks':
                    try {
                        $this->projectDB->updateAttribute($id, 'projectInternalId', required: true);
                    } catch (\Throwable $th) {
                        Console::warning("'projectInternalId' from {$id}: {$th->getMessage()}");
                    }

                    $this->projectDB->deleteCachedCollection($id);

                    break;
                case 'projects':
                    $attributesToCreate = [
                        'database',
                        'smtp',
                        'templates',
                    ];
                    foreach ($attributesToCreate as $attribute) {
                        try {
                            $this->createAttributeFromCollection($this->projectDB, $id, $attribute);
                        } catch (\Throwable $th) {
                            Console::warning("'$attribute' from {$id}: {$th->getMessage()}");
                            Console::warning($th->getTraceAsString());
                        }
                    }

                    $this->projectDB->deleteCachedCollection($id);

                    break;
                case 'stats':
                    try {
                        $this->projectDB->updateAttribute($id, 'value', signed: true);
                    } catch (\Throwable $th) {
                        Console::warning("'value' from {$id}: {$th->getMessage()}");
                    }

                    // Holding off on these until a future release
                    // try {
                    //     $this->projectDB->deleteAttribute($id, 'type');
                    //     $this->projectDB->deleteCachedCollection($id);
                    // } catch (\Throwable $th) {
                    //     Console::warning("'type' from {$id}: {$th->getMessage()}");
                    // }

                    // try {
                    //     $this->projectDB->deleteIndex($id, '_key_metric_period_time');
                    //     $this->projectDB->deleteCachedCollection($id);
                    // } catch (\Throwable $th) {
                    //     Console::warning("'_key_metric_period_time' from {$id}: {$th->getMessage()}");
                    // }

                    // try {
                    //     $this->createIndexFromCollection($this->projectDB, $id, '_key_metric_period_time');
                    //     $this->projectDB->deleteCachedCollection($id);
                    // } catch (\Throwable $th) {
                    //     Console::warning("'_key_metric_period_time' from {$id}: {$th->getMessage()}");
                    // }

                    $this->projectDB->deleteCachedCollection($id);

                    break;
                case 'users':
                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'labels');
                    } catch (\Throwable $th) {
                        Console::warning("'labels' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'accessedAt');
                    } catch (\Throwable $th) {
                        Console::warning("'accessedAt' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->projectDB->updateAttribute($id, 'search', filters: ['userSearch']);
                    } catch (\Throwable $th) {
                        Console::warning("'search' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_accessedAt');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_accessedAt' from {$id}: {$th->getMessage()}");
                    }

                    $this->projectDB->deleteCachedCollection($id);

                    break;
                case 'variables':
                    try {
                        $this->projectDB->deleteIndex($id, '_key_function');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_function' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->projectDB->deleteIndex($id, '_key_uniqueKey');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_uniqueKey' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'resourceType');
                    } catch (\Throwable $th) {
                        Console::warning("'resourceType' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->projectDB->renameAttribute($id, 'functionInternalId', 'resourceInternalId');
                    } catch (\Throwable $th) {
                        Console::warning("'resourceInternalId' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->projectDB->renameAttribute($id, 'functionId', 'resourceId');
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
                            $this->createIndexFromCollection($this->projectDB, $id, $index);
                        } catch (\Throwable $th) {
                            Console::warning("'$index' from {$id}: {$th->getMessage()}");
                        }
                    }

                    $this->projectDB->deleteCachedCollection($id);

                    break;
                default:
                    break;
            }

            usleep(50000);
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
                $status = $document->getAttribute('status', '');
                if ($status === 'failed') {
                    $document->setAttribute('error', 'Unknown problem');
                }
                break;
            case 'builds':
                $deploymentId = $document->getAttribute('deploymentId');
                $deployment = $this->projectDB->getDocument('deployments', $deploymentId);
                $document->setAttribute('deploymentInternalId', $deployment->getInternalId());

                $stdout = $document->getAttribute('stdout', '');
                $stderr = $document->getAttribute('stderr', '');
                $document->setAttribute('logs', $stdout . PHP_EOL . $stderr);
                break;
            case 'databases':
                $document->setAttribute('enabled', true);
                break;
            case 'deployments':
                $resourceId = $document->getAttribute('resourceId');
                $function = $this->projectDB->getDocument('functions', $resourceId);
                $document->setAttribute('resourceInternalId', $function->getInternalId());

                $buildId = $document->getAttribute('buildId');
                if (!empty($buildId)) {
                    $build = $this->projectDB->getDocument('builds', $buildId);
                    $document->setAttribute('buildInternalId', $build->getInternalId());
                }

                $document->setAttribute('type', 'manual');
                break;
            case 'executions':
                $functionId = $document->getAttribute('functionId');
                $function = $this->projectDB->getDocument('functions', $functionId);
                $document->setAttribute('functionInternalId', $function->getInternalId());

                $deploymentId = $document->getAttribute('deploymentId');
                $deployment = $this->projectDB->getDocument('deployments', $deploymentId);
                $document->setAttribute('deploymentInternalId', $deployment->getInternalId());
                break;
            case 'functions':
                $document->setAttribute('live', true);
                $document->setAttribute('logging', true);
                $document->setAttribute('version', 'v2');
                $deploymentId = $document->getAttribute('deployment');

                if (!empty($deploymentId)) {
                    $deployment = $this->projectDB->getDocument('deployments', $deploymentId);
                    $document->setAttribute('deploymentInternalId', $deployment->getInternalId());
                    $document->setAttribute('entrypoint', $deployment->getAttribute('entrypoint'));
                }

                $schedule = $this->consoleDB->createDocument('schedules', new Document([
                    'region' => App::getEnv('_APP_REGION', 'default'), // Todo replace with projects region
                    'resourceType' => 'function',
                    'resourceId' => $document->getId(),
                    'resourceInternalId' => $document->getInternalId(),
                    'resourceUpdatedAt' => DateTime::now(),
                    'projectId' => $this->project->getId(),
                    'schedule'  => $document->getAttribute('schedule'),
                    'active' => !empty($document->getAttribute('schedule')) && !empty($document->getAttribute('deployment')),
                ]));

                $document->setAttribute('scheduleId', $schedule->getId());
                $document->setAttribute('scheduleInternalId', $schedule->getInternalId());
                break;
            case 'projects':
                $document->setAttribute('version', '1.4.0');

                $databases = Config::getParam('pools-database', []);
                $database = $databases[0];

                $document->setAttribute('database', $database);
                $document->setAttribute('smtp', []);
                $document->setAttribute('templates', []);

                break;
            case 'rules':
                $status = 'created';
                if ($document->getAttribute('verification', false)) {
                    $status = 'verified';
                }

                $ruleDocument = new Document([
                    'projectId' => $this->project->getId(),
                    'projectInternalId' => $this->project->getInternalId(),
                    'domain' => $document->getAttribute('domain'),
                    'resourceType' => 'api',
                    'resourceInternalId' => '',
                    'resourceId' => '',
                    'status' => $status,
                    'certificateId' => $document->getAttribute('certificateId'),
                ]);

                try {
                    $this->consoleDB->createDocument('rules', $ruleDocument);
                } catch (\Throwable $th) {
                    Console::warning("Error migrating domain {$document->getAttribute('domain')}: {$th->getMessage()}");
                }

                break;
            default:
                break;
        }

        return $document;
    }

    private function cleanCollections(): void
    {
        try {
            $this->projectDB->deleteAttribute('projects', 'domains');
        } catch (\Throwable $th) {
            Console::warning("'domains' from projects: {$th->getMessage()}");
        }

        $this->projectDB->deleteCachedCollection('projects');

        try {
            $this->projectDB->deleteAttribute('functions', 'schedule');
        } catch (\Throwable $th) {
            Console::warning("'schedule' from functions: {$th->getMessage()}");
        }

        $this->projectDB->deleteCachedCollection('functions');

        try {
            $this->projectDB->deleteAttribute('builds', 'stderr');
        } catch (\Throwable $th) {
            Console::warning("'stderr' from builds: {$th->getMessage()}");
        }

        try {
            $this->projectDB->deleteAttribute('builds', 'stdout');
        } catch (\Throwable $th) {
            Console::warning("'stdout' from builds: {$th->getMessage()}");
        }

        $this->projectDB->deleteCachedCollection('builds');
    }
}
