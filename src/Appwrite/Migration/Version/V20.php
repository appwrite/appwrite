<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use PDOException;
use Throwable;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Query;

class V20 extends Migration
{
    /**
     * @throws Throwable
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

        $this->migrateUsageMetrics('project.$all.network.requests', 'network.requests');
        $this->migrateUsageMetrics('project.$all.network.outbound', 'network.outbound');
        $this->migrateUsageMetrics('project.$all.network.inbound', 'network.inbound');
        $this->migrateUsageMetrics('users.$all.count.total', 'users');
        $this->migrateSessionsMetric();

        Console::log('Migrating Project: ' . $this->project->getAttribute('name') . ' (' . $this->project->getId() . ')');
        $this->projectDB->setNamespace("_{$this->project->getInternalId()}");

        Console::info('Migrating Functions');
        $this->migrateFunctions();

        Console::info('Migrating Databases');
        $this->migrateDatabases();

        Console::info('Migrating Collections');
        $this->migrateCollections();

        Console::info('Migrating Buckets');
        $this->migrateBuckets();
    }

    /**
     * @return void
     * @throws Authorization
     * @throws Exception
     * @throws Structure
     */
    protected function migrateSessionsMetric(): void
    {
        /**
         * Creating inf metric
         */

        Console::info('Migrating Sessions metric');

        $sessionsCreated = $this->projectDB->sum('stats', 'value', [
            Query::equal('metric', [
                'sessions.email-password.requests.create',
                'sessions.magic-url.requests.create',
                'sessions.anonymous.requests.create',
                'sessions.invites.requests.create',
                'sessions.jwt.requests.create',
                'sessions.phone.requests.create'
            ]),
            Query::equal('period', ['1d']),
        ]);

        $query = $this->projectDB->findOne('stats', [
            Query::equal('metric', ['sessions.$all.requests.delete']),
            Query::equal('period', ['1d']),
        ]);

        $sessionsDeleted =  $query['value'] ?? 0;
        $value = $sessionsCreated - $sessionsDeleted;
        var_dump($sessionsCreated);
        var_dump($sessionsDeleted);

        $this->createInfMetric('sessions', $value);
    }

    /**
     * @param string $metric
     * @param int $value
     * @return void
     * @throws Exception
     * @throws Authorization
     * @throws Structure
     */
    protected function createInfMetric(string $metric, int $value): void
    {

        try {
            /**
             * Creating inf metric
             */
            console::log("Creating inf metric  to {$metric}");
            $id = \md5("null_inf_{$metric}");
            $this->projectDB->createDocument('stats', new Document([
                '$id' => $id,
                'metric' => $metric,
                'period' => 'inf',
                'value'  => $value,
                'time'   => null,
                'region' => 'default',
            ]));
        } catch (Duplicate $th) {
            console::log("Error while creating inf metric: duplicate id {$metric}  {$id}");
        }
    }

    /**
     * @param string $from
     * @param string $to
     * @return void
     * @throws Exception
     */
    protected function migrateUsageMetrics(string $from, string $to): void
    {
        /**
         * inf metric
         */
        if (
            str_contains($from, '$all')
            && str_contains($from, 'total')
        ) {
            $result = $this->projectDB->findOne('stats', [
                Query::equal('metric', [$from]),
                Query::equal('period', ['1d']),
            ]);
            $value = $result['value'] ?? 0;
            $this->createInfMetric($to, $value);
        }

        try {
            /**
             * Update old metric format to new
             */
            $limit = 1000;
            $sum = $limit;
            $total = 0;
            $latestDocument = null;
            while ($sum === $limit) {
                $paginationQueries = [Query::limit($limit)];
                if ($latestDocument !== null) {
                    $paginationQueries[] =  Query::cursorAfter($latestDocument);
                }
                $stats = $this->projectDB->find('stats', \array_merge($paginationQueries, [
                    Query::equal('metric', [$from]),
                ]));

                $sum = count($stats);
                $total = $total + $sum;
                foreach ($stats as $stat) {
                    $stat->setAttribute('metric', $to);
                    console::log("updating metric  {$from} to {$to}");
                    $this->projectDB->updateDocument('stats', $stat->getId(), $stat);
                }

                $latestDocument = !empty(array_key_last($stats)) ? $stats[array_key_last($stats)] : null;
            }
        } catch (Throwable $th) {
            Console::warning("Error while updating metric  {$from}  " . $th->getMessage());
        }
    }
    /**
     * Migrate functions usage.
     *
     * @return void
     * @throws \Exception
     */
    private function migrateFunctions(): void
    {

        $this->migrateUsageMetrics('deployment.$all.storage.size', 'deployments.storage');
        $this->migrateUsageMetrics('builds.$all.compute.total', 'builds');
        $this->migrateUsageMetrics('builds.$all.compute.time', 'builds.compute');
        $this->migrateUsageMetrics('executions.$all.compute.total', 'executions');
        $this->migrateUsageMetrics('executions.$all.compute.time', 'executions.compute');

        foreach ($this->documentsIterator('functions') as $function) {
            Console::log("Migrating Functions usage stats of {$function->getId()} ({$function->getAttribute('name')})");

            $functionId = $function->getId();
            $functionInternalId = $function->getInternalId();

            $this->migrateUsageMetrics("deployment.$functionId.storage.size", "function.$functionInternalId.deployments.storage");
            $this->migrateUsageMetrics("builds.$functionId.compute.total", "$functionInternalId.builds");
            $this->migrateUsageMetrics("builds.$functionId.compute.time", "$functionInternalId.builds.compute");
            $this->migrateUsageMetrics("executions.$functionId.compute.total", "$functionInternalId.executions");
            $this->migrateUsageMetrics("executions.$functionId.compute.time", "$functionInternalId.executions.compute");
        }
    }

    /**
     * Migrate  Databases.
     *
     * @return void
     * @throws \Exception
     */
    private function migrateDatabases(): void
    {
        // Project level
        $this->migrateUsageMetrics('databases.$all.count.total', 'databases');
        $this->migrateUsageMetrics('collections.$all.count.total', 'collections');
        $this->migrateUsageMetrics('documents.$all.count.total', 'documents');

        foreach ($this->documentsIterator('databases') as $database) {
            Console::log("Migrating Collections of {$database->getId()} ({$database->getAttribute('name')})");

            $databaseTable = "database_{$database->getInternalId()}";

            // Database level
            $databaseId = $database->getId();
            $databaseInternalId = $database->getInternalId();

            $this->migrateUsageMetrics("collections.$databaseId.count.total", "$databaseInternalId.collections");
            $this->migrateUsageMetrics("documents.$databaseId.count.total", "$databaseInternalId.documents");

            foreach ($this->documentsIterator($databaseTable) as $collection) {
                $collectionTable = "{$databaseTable}_collection_{$collection->getInternalId()}";
                Console::log("Migrating Collections of {$collectionTable} {$collection->getId()} ({$collection->getAttribute('name')})");

                // Collection level
                $collectionId =  $collection->getId() ;
                $collectionInternalId =  $collection->getInternalId();

                $this->migrateUsageMetrics("documents.$databaseId/$collectionId.count.total", "$databaseInternalId.$collectionInternalId.documents");
            }
        }
    }

    /**
     * Migrate Collections.
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
                         * Alter `signed`  internal type on `value` attr
                         */
                        $this->projectDB->updateAttribute($id, 'value', null, null, null, null, true);
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (Throwable $th) {
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
     * @throws PDOException
     */
    protected function migrateBuckets(): void
    {
        // Project level
        $this->migrateUsageMetrics('buckets.$all.count.total', 'buckets');
        $this->migrateUsageMetrics('files.$all.count.total', 'files');
        $this->migrateUsageMetrics('files.$all.storage.size', 'files.storage');
        // There is also project.$all.storage.size which is the same as  files.$all.storage.size

        foreach ($this->documentsIterator('buckets') as $bucket) {
            $id = "bucket_{$bucket->getInternalId()}";
            Console::log("Migrating Bucket {$id} {$bucket->getId()} ({$bucket->getAttribute('name')})");

            // Bucket level
            $bucketId = $bucket->getId();
            $bucketInternalId = $bucket->getInternalId();

             $this->migrateUsageMetrics("files.$bucketId.count.total", "$bucketInternalId.files");
            $this->migrateUsageMetrics("files.$bucketId.storage.size", "$bucketInternalId.files.storage");
            // some stats come with $ prefix infront of the id -> files.$650c3fda307b7fec4934.storage.size;
        }
    }
}
