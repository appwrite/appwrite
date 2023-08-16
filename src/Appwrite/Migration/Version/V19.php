<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;

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

        $this->alterPermissionIndex('_metadata');
        $this->alterUidType('_metadata');

        Console::info('Migrating Databases');
        $this->migrateDatabases();

        Console::info('Migrating Collections');
        $this->migrateCollections();

        Console::info('Migrating Buckets');
        $this->migrateBuckets();

        Console::info('Migrating Documents');
        $this->forEachDocument([$this, 'fixDocument']);
    }

    /**
     * Migrate all Databases.
     *
     * @return void
     * @throws \Exception
     */
    private function migrateDatabases(): void
    {
        foreach ($this->documentsIterator('databases') as $database) {
            Console::log("Migrating Collections of {$database->getId()} ({$database->getAttribute('name')})");

            $databaseTable = "database_{$database->getInternalId()}";

            $this->alterPermissionIndex($databaseTable);
            $this->alterUidType($databaseTable);

            foreach ($this->documentsIterator($databaseTable) as $collection) {
                $collectionTable = "{$databaseTable}_collection_{$collection->getInternalId()}";
                Console::log("Migrating Collections of {$collectionTable} {$collection->getId()} ({$collection->getAttribute('name')})");
                $this->alterPermissionIndex($collectionTable);
                $this->alterUidType($collectionTable);
            }
        }
    }

    /**
     * Migrate all Collections.
     *
     * @return void
     */
    private function migrateCollections(): void
    {
        $collectionType = match ($this->project->getInternalId()) {
            'console' => 'console',
            default => 'projects',
        };
        $collections = $this->collections[$collectionType];
        foreach ($collections as $collection) {
            $id = $collection['$id'];

            $internalProjectId = $this->project->getInternalId();

            if ($id === 'schedules' && $internalProjectId === 'console') {
                continue;
            }

            Console::log("Migrating Collection \"{$id}\"");

            $this->projectDB->setNamespace("_$internalProjectId");

            switch ($id) {
                case '_metadata':
                    $this->createCollection('identities');
                    $this->createCollection('migrations');
                    $this->createCollection('statsLogger');
                    break;
                case 'attributes':
                case 'indexes':
                    try {
                        $this->projectDB->updateAttribute($id, 'databaseInternalId', required: true);
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'databaseInternalId' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->projectDB->updateAttribute($id, 'collectionInternalId', required: true);
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'collectionInternalId' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'error');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'error' from {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'builds':
                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'deploymentInternalId');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'deploymentInternalId' from {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'databases':
                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'enabled');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'enabled' from {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'deployments':
                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'resourceInternalId');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'resourceInternalId' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'buildInternalId');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'buildInternalId' from {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'executions':
                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'functionInternalId');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'functionInternalId' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'deploymentInternalId');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'deploymentInternalId' from {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'functions':
                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'live');
                        $this->createAttributeFromCollection($this->projectDB, $id, 'installationId');
                        $this->createAttributeFromCollection($this->projectDB, $id, 'installationInternalId');
                        $this->createAttributeFromCollection($this->projectDB, $id, 'providerRepositoryId');
                        $this->createAttributeFromCollection($this->projectDB, $id, 'repositoryId');
                        $this->createAttributeFromCollection($this->projectDB, $id, 'repositoryInternalId');
                        $this->createAttributeFromCollection($this->projectDB, $id, 'providerBranch');
                        $this->createAttributeFromCollection($this->projectDB, $id, 'providerRootDirectory');
                        $this->createAttributeFromCollection($this->projectDB, $id, 'providerSilentMode');
                        $this->createAttributeFromCollection($this->projectDB, $id, 'logging');
                        $this->createAttributeFromCollection($this->projectDB, $id, 'deploymentInternalId');
                        $this->createAttributeFromCollection($this->projectDB, $id, 'scheduleInternalId');
                        $this->createAttributeFromCollection($this->projectDB, $id, 'scheduleId');
                        $this->createAttributeFromCollection($this->projectDB, $id, 'version');
                        $this->createAttributeFromCollection($this->projectDB, $id, 'entrypoint');
                        $this->createAttributeFromCollection($this->projectDB, $id, 'commands');
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_installationId');
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_installationInternalId');
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_providerRepositoryId');
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_repositoryId');
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_repositoryInternalId');
                        $this->createIndexFromCollection($this->projectDB, $id, 'asdf');
                        $this->createIndexFromCollection($this->projectDB, $id, 'asdf');
                        $this->createIndexFromCollection($this->projectDB, $id, 'asdf');
                        $this->createIndexFromCollection($this->projectDB, $id, 'asdf');

                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'deploymentInternalId' from {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'memberships':
                    try {
                        $this->projectDB->updateAttribute($id, 'teamInternalId', required: true);
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'teamInternalId' from {$id}: {$th->getMessage()}");
                    }
                    // Intentional fall through to update memberships.userInternalId
                case 'sessions':
                case 'tokens':
                    try {
                        $this->projectDB->updateAttribute($id, 'userInternalId', required: true);
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'userInternalId' from {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'domains':
                case 'keys':
                case 'platforms':
                case 'webhooks':
                    try {
                        $this->projectDB->updateAttribute($id, 'projectInternalId', required: true);
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'projectInternalId' from {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'projects':
                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'database');
                        $this->createAttributeFromCollection($this->projectDB, $id, 'smtp');
                        $this->createAttributeFromCollection($this->projectDB, $id, 'templates');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'SMTP and Templates' from {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'stats':
                    try {
                        $this->projectDB->updateAttribute($id, 'value', signed: true);
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'value' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->projectDB->deleteAttribute($id, 'type');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'type' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->projectDB->deleteIndex($id, '_key_metric_period_time');
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_metric_period_time');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'_key_metric_period_time' from {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'users':
                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'labels');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'labels' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'accessedAt');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'accessedAt' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->projectDB->updateAttribute($id, 'search', filters: ['userSearch']);
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'search' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->createIndexFromCollection($this->projectDB, $id, '_key_accessedAt');
                    } catch (\Throwable $th) {
                        Console::warning("'_key_accessedAt' from {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'variables':
                    try {
                        $this->projectDB->updateAttribute($id, 'functionInternalId', required: true);
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'search' from {$id}: {$th->getMessage()}");
                    }
                    break;
                default:
                    break;
            }
            if (!in_array($id, ['files', 'collections'])) {
                $this->alterPermissionIndex($id);
                $this->alterUidType($id);
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
            case '_metadata':
                // TODO: migrate statsLogger?
                break;
            case 'builds':
                $deploymentId = $document->getAttribute('deploymentId');
                $deployment = $this->projectDB->getDocument('deployments', $deploymentId);
                $document->setAttribute('deploymentInternalId', $deployment->getInternalId());
                break;
            case 'collections':
            case 'databases':
                $document->setAttribute('enabled', true);
                break;
            case 'deployments':
                $resourceId = $document->getAttribute('resourceId');
                $function = $this->projectDB->getDocument('functions', $resourceId);
                $document->setAttribute('resourceInternalId', $function->getInternalId());

                $buildId = $document->getAttribute('buildId');
                $build = $this->projectDB->getDocument('builds', $buildId);
                $document->setAttribute('buildInternalId', $build->getInternalId());
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
                $deploymentId = $document->getAttribute('deployment');
                $deployment = $this->projectDB->getDocument('deployments', $deploymentId);
                $document->setAttribute('deploymentInternalId', $deployment->getInternalId());
                $document->setAttribute('entrypoint', $deployment->getAttribute('entrypoint'));

                // TODO: function schedule?
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
                /**
                 * Bump version number.
                 */
                $document->setAttribute('version', '1.4.0');

                $databases = Config::getParam('pools-database', []);
                $database = $databases[0];

                $document->setAttribute('database', $database);
                $document->setAttribute('smtp', []);
                $document->setAttribute('templates', []);

                break;
            default:
                break;
        }

        return $document;
    }

    protected function alterPermissionIndex($collectionName): void
    {
        try {
            $table = "`{$this->projectDB->getDefaultDatabase()}`.`_{$this->project->getInternalId()}_{$collectionName}_perms`";
            $this->pdo->prepare("
                ALTER TABLE {$table}
                DROP INDEX `_permission`, 
                ADD INDEX `_permission` (`_permission`, `_type`, `_document`);
            ")->execute();
        } catch (\Throwable $th) {
            Console::warning($th->getMessage());
        }
    }

    protected function alterUidType($collectionName): void
    {
        try {
            $table = "`{$this->projectDB->getDefaultDatabase()}`.`_{$this->project->getInternalId()}_{$collectionName}`";
            $this->pdo->prepare("
            ALTER TABLE {$table}
            CHANGE COLUMN `_uid` `_uid` VARCHAR(255) NOT NULL ;
            ")->execute();
        } catch (\Throwable $th) {
            Console::warning($th->getMessage());
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
            $id = "bucket_{$bucket->getInternalId()}";
            Console::log("Migrating Bucket {$id} {$bucket->getId()} ({$bucket->getAttribute('name')})");
            $this->alterPermissionIndex($id);
            $this->alterUidType($id);

            try {
                $this->createAttributeFromCollection($this->projectDB, $id, 'bucketInternalId', 'files');
                $this->projectDB->deleteCachedCollection($id);
            } catch (\Throwable $th) {
                Console::warning("'bucketInternalId' from {$id}: {$th->getMessage()}");
            }
        }
    }
}
