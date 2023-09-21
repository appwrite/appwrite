<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Query;

class V20 extends Migration
{
    /**
     * @throws \Throwable
     */
    public function execute(): void
    {
        if ($this->project->getInternalId() == 'console') {
            return;
        }

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

        Console::info('Migrating Functions');
        $this->migrateFunctionsMetric();

        Console::info('Migrating Databases');
        $this->migrateDatabases();

        Console::info('Migrating Collections');
        $this->migrateCollections();

        Console::info('Migrating Buckets');
        $this->migrateBuckets();
    }

    protected function migrateStatsMetric(string $from, string $to): void
    {
        try {
            $stats = $this->projectDB->find('stats', [
                Query::equal('metric', [$from]),
            ]);

            foreach ($stats as $stat) {
                var_dump($stat->getAttribute('metric'));
                $stat->setAttribute('metric', $to);
                var_dump($stat->getAttribute('metric'));
                //exit;
                $this->projectDB->updateDocument('stats', $stat->getId(), $stat);

                if ($stat['period'] === '1d') {
                    $sum = $this->projectDB->sum('stats', 'value', [
                        Query::equal('metric', [$from]),
                        Query::equal('period', ['1d']),
                        Query::greaterThan('value', 0),
                    ]);

                    $stat
                        ->setAttribute('$id', \md5("null_inf_{$to}"))
                        ->setAttribute('period', 'inf')
                        ->setAttribute('value', ($sum + 0))
                        ->setAttribute('region', 'default')
                    ;
                    //$x = $this->projectDB->createDocument('stats', $stat);
                    //var_dump($x);
                }
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

        $this->migrateStatsMetric('executions.$all.compute.time', 'executions.compute');
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

            $this->migrateStatsMetric("collections.$databaseId.count.total", "$databaseInternalId.collections");
            $this->migrateStatsMetric("documents.$databaseId.count.total", "$databaseInternalId.documents");

            foreach ($this->documentsIterator($databaseTable) as $collection) {
                $collectionTable = "{$databaseTable}_collection_{$collection->getInternalId()}";
                Console::log("Migrating Collections of {$collectionTable} {$collection->getId()} ({$collection->getAttribute('name')})");

                // collection level
                $collectionId =  $collection->getId() ;
                $collectionInternalId =  $collection->getInternalId();

                $this->migrateStatsMetric("documents.$databaseId/$collectionId.count.total", "$databaseInternalId.$collectionInternalId.documents");
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
                case 'stats':
                    try {
                        /**
                         * Delete 'type' attribute
                         */
                        $this->projectDB->deleteAttribute($id, 'type');
                        /**
                         * Alter `signed` attribute internal type
                         */
                        $this->projectDB->updateAttribute($id, 'signed', null, null, null, null, true);
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
