<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Utopia\CLI\Console;
use Utopia\Database\Database;

class V20 extends Migration
{
    /**
     * @throws \Throwable
     */
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

        $this->migrateStatsMetric('project.$all.network.outbound', 'network.outbound');
        $this->migrateStatsMetric('project.$all.network.inbound', 'network.inbound');
        $this->migrateStatsMetric('project.$all.network.requests', 'network.requests');
        $this->migrateStatsMetric('users.$all.count.total', 'users');

        Console::log('Migrating Project: ' . $this->project->getAttribute('name') . ' (' . $this->project->getId() . ')');
        $this->projectDB->setNamespace("_{$this->project->getInternalId()}");

        Console::info('Migrating Function usage');
        $this->migrateFunctionsMetric();

        Console::info('Migrating Databases usage');
        $this->migrateDatabases();

        Console::info('Migrating Collections usage');
        $this->migrateCollections();

        Console::info('Migrating Buckets');
        $this->migrateBuckets();

        Console::info('Migrating Documents');
        $this->forEachDocument([$this, 'fixDocument']);
    }

    protected function migrateStatsMetric(string $from, string $to): void
    {
        try {
            $from = $this->pdo->quote($from);
            $to = $this->pdo->quote($to);

            $this->pdo->prepare("UPDATE `{$this->projectDB->getDefaultDatabase()}`.`_{$this->project->getInternalId()}_stats` SET metric = {$to} WHERE metric = {$from}")->execute();

            // Create Inf metric
            $result = $this->pdo->prepare("SELECT SUM(value) AS total FROM `{$this->projectDB->getDefaultDatabase()}`.`_{$this->project->getInternalId()}_stats` 
                                                 WHERE metric = {$from} 
                                                 AND period=1d 
                                                 AND value > 0")->execute();

            if (!empty($result)) {
                $id = \md5("null_inf_{$to}");
                $this->pdo->prepare("INSERT INTO `{$this->projectDB->getDefaultDatabase()}`.`_{$this->project->getInternalId()}_stats` 
                                        (id, metric, period ,time, `value`, region) values ({$id}, {$to}, null, inf, {$result['total']}, default)
                                 ")->execute();
            }
        } catch (\Throwable $th) {
            Console::warning("Migrating steps from {$this->projectDB->getDefaultDatabase()}`.`_{$this->project->getInternalId()}_stats:" . $th->getMessage());
        }
    }
    /**
     * Migrate Functions usage.
     *
     * @return void
     * @throws \Exception
     */
    private function migrateFunctionsMetric(): void
    {

        $this->migrateStatsMetric('deployment.$all.storage.size', 'deployments.storage');
        $this->migrateStatsMetric('builds.$all.compute.total', 'builds');
        $this->migrateStatsMetric('builds.$all.compute.time', 'builds.compute');
        $this->migrateStatsMetric('executions.$all.compute.total', 'executions');
        $this->migrateStatsMetric('executions.$all.compute.time', 'executions.compute');

        foreach ($this->documentsIterator('functions') as $function) {
            Console::log("Migrating Functions usage stats of {$function->getId()} ({$function->getAttribute('name')})");

            $functionId = $function->getId();
            $functionInternalId = $function->getInternalId();

            $this->migrateStatsMetric("deployment.$functionId.storage.size", "function.$functionInternalId.deployments.storage");
            $this->migrateStatsMetric("builds.$functionId.compute.total", "$functionInternalId.builds");
            $this->migrateStatsMetric("builds.$functionId.compute.time", "$functionInternalId.builds.compute");
            $this->migrateStatsMetric("executions.$functionId.compute.total", "$functionInternalId.executions");
            $this->migrateStatsMetric("executions.$functionId.compute.time", "$functionInternalId.executions.compute");
        }
    }

    /**
     * Migrate all Databases.
     *
     * @return void
     * @throws \Exception
     */
    private function migrateDatabases(): void
    {
        // project level
        $this->migrateStatsMetric('databases.$all.count.total', 'databases');
        $this->migrateStatsMetric('collections.$all.count.total', 'collections');
        $this->migrateStatsMetric('documents.$all.count.total', 'documents');

        foreach ($this->documentsIterator('databases') as $database) {
            Console::log("Migrating Collections of {$database->getId()} ({$database->getAttribute('name')})");

            $databaseTable = "database_{$database->getInternalId()}";

            // database level
            $databaseId = $database->getId();
            $databaseInternalId = $database->getInternalId();

            $this->migrateStatsMetric("databases.$databaseId.collections.count", "$databaseInternalId.collections");
            $this->migrateStatsMetric("databases.$databaseId.documents.count", "$databaseInternalId.documents");

            foreach ($this->documentsIterator($databaseTable) as $collection) {
                $collectionTable = "{$databaseTable}_collection_{$collection->getInternalId()}";
                Console::log("Migrating Collections of {$collectionTable} {$collection->getId()} ({$collection->getAttribute('name')})");

                // collection level
                $collectionId =  $collection->getId() ;
                $collectionInternalId =  $collection->getInternalId();

                $this->migrateStatsMetric("documents.$databaseId.$collectionId.count.total", "$databaseInternalId.$collectionInternalId.documents");
            }
        }
    }

    /**
     * Migrate all Collections.
     *
     * @return void
     * @throws \Exception
     */
    private function migrateCollections(): void
    {
        foreach ($this->collections as $collection) {
            $id = $collection['$id'];

            Console::log("Migrating Collection \"{$id}\"");

            $this->projectDB->setNamespace("_{$this->project->getInternalId()}");

            switch ($id) {
                case 'stats':
                    try {
                        /**
                         * Delete 'type' attribute
                         */
                        $this->projectDB->deleteAttribute($id, 'type');
                        /**
                         * Alter signed attribute internal yype
                         */
                        $this->changeAttributeInternalType($id, 'signed', true);
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (\Throwable $th) {
                        Console::warning("'type' from {$id}: {$th->getMessage()}");
                    }
                    break;
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
        // project level
        $this->migrateStatsMetric('buckets.$all.count.total', 'buckets');
        $this->migrateStatsMetric('files.$all.count.total', 'files');
        $this->migrateStatsMetric('files.$all.storage.size', 'files.storage');

        foreach ($this->documentsIterator('buckets') as $bucket) {
            $id = "bucket_{$bucket->getInternalId()}";
            Console::log("Migrating Bucket {$id} {$bucket->getId()} ({$bucket->getAttribute('name')})");

            // bucket level
            $bucketId = $bucket->getId();
            $bucketInternalId = $bucket->getInternalId();

            $this->migrateStatsMetric("files.$bucketId.count.total", "$bucketInternalId.files");
            $this->migrateStatsMetric("files.$bucketId.storage.size", "$bucketInternalId.files.storage");
        }
    }
}
