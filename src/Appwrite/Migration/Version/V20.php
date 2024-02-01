<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Exception;
use PDOException;
use Throwable;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;

class V20 extends Migration
{
    /**
     * @throws Throwable
     */
    public function execute(): void
    {
        /**
         * Disable SubQueries for Performance.
         */
        foreach (['subQueryIndexes', 'subQueryPlatforms', 'subQueryDomains', 'subQueryKeys', 'subQueryWebhooks', 'subQuerySessions', 'subQueryTokens', 'subQueryMemberships', 'subQueryVariables'] as $name) {
            Database::addFilter(
                $name,
                fn() => null,
                fn() => []
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

        Console::info('Migrating Documents');
        $this->forEachDocument([$this, 'fixDocument']);
    }

    /**
     * Migrate Collections.
     *
     * @return void
     * @throws Exception|Throwable
     */
    private function migrateCollections(): void
    {
        $internalProjectId = $this->project->getInternalId();
        $collectionType = match ($internalProjectId) {
            'console' => 'console',
            default => 'projects',
        };

        $databases = $this->projectDB->find('databases', []);
        foreach ($databases as $database) {
            var_dump($database);
        }

        $collections = $this->collections[$collectionType];
        foreach ($collections as $collection) {
            $id = $collection['$id'];

            Console::log("Migrating Collection \"{$id}\"");

            $this->projectDB->setNamespace("_$internalProjectId");

            // Support database array type migration
            foreach ($collection['attributes'] ?? [] as $attribute) {
                if ($attribute['array'] === true) {
                    foreach ($collection['indexes'] ?? [] as $index) {
                        if (in_array($attribute['$id'], $index['attributes'])) {
                            $this->projectDB->deleteIndex($id, $index['$id']);
                        }
                    }
                    $this->projectDB->updateAttribute($id, $attribute['$id'], Database::VAR_STRING);
                }
            }

            switch ($id) {
                case '_metadata':
                    $this->createCollection('providers');
                    $this->createCollection('messages');
                    $this->createCollection('topics');
                    $this->createCollection('subscribers');
                    $this->createCollection('targets');

                    break;
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

                    // update stats index
                    $index = '_key_metric_period_time';

                    try {
                        $this->projectDB->deleteIndex($id, $index);
                    } catch (\Throwable $th) {
                        Console::warning("'$index' from {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->createIndexFromCollection($this->projectDB, $id, $index);
                    } catch (\Throwable $th) {
                        Console::warning("'$index' from {$id}: {$th->getMessage()}");
                    }

                    break;
                case 'sessions':
                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'expire');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (Throwable $th) {
                        Console::warning("'expire' from {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'users':
                    // Create targets attribute
                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'targets');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (Throwable $th) {
                        Console::warning("'targets' from {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'projects':
                    // Rename providers authProviders to oAuthProviders
                    try {
                        $this->projectDB->renameAttribute($id, 'authProviders', 'oAuthProviders');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (Throwable $th) {
                        Console::warning("'oAuthProviders' from {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'schedules':
                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'resourceCollection');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (Throwable $th) {
                        Console::warning("'schedules' from {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'webhooks':
                    try {
                        $this->createAttributeFromCollection($this->projectDB, $id, 'enabled');
                        $this->createAttributeFromCollection($this->projectDB, $id, 'logs');
                        $this->createAttributeFromCollection($this->projectDB, $id, 'attempts');
                        $this->projectDB->deleteCachedCollection($id);
                    } catch (Throwable $th) {
                        Console::warning("'webhooks' from {$id}: {$th->getMessage()}");
                    }
                    break;
                default:
                    break;
            }

            usleep(50000);
        }
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

        $sessionsDeleted = $query['value'] ?? 0;
        $value = $sessionsCreated - $sessionsDeleted;
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
            console::log("Creating inf metric to {$metric}");
            $id = \md5("_inf_{$metric}");
            $this->projectDB->createDocument('stats', new Document([
                '$id' => $id,
                'metric' => $metric,
                'period' => 'inf',
                'value' => $value,
                'time' => null,
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
            str_contains($from, '$all') ||
            str_contains($from, '.total')
        ) {
            $query = $this->projectDB->sum('stats', 'value', [
                Query::equal('metric', [$from]),
                Query::equal('period', ['1d']),
            ]);

            $value = $query ?? 0;
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
                    $paginationQueries[] = Query::cursorAfter($latestDocument);
                }
                $stats = $this->projectDB->find('stats', \array_merge($paginationQueries, [
                    Query::equal('metric', [$from]),
                ]));

                $sum = count($stats);
                $total = $total + $sum;
                foreach ($stats as $stat) {
                    $format = $stat['period'] === '1d' ? 'Y-m-d 00:00' : 'Y-m-d H:00';
                    $time = date($format, strtotime($stat['time']));
                    $this->projectDB->deleteDocument('stats', $stat->getId());
                    $stat->setAttribute('$id', \md5("{$time}_{$stat['period']}_{$to}"));
                    $stat->setAttribute('metric', $to);
                    $this->projectDB->createDocument('stats', $stat);
                    console::log("deleting metric {$from} and creating {$to}");
                }
                $latestDocument = !empty(array_key_last($stats)) ? $stats[array_key_last($stats)] : null;
            }
        } catch (Throwable $th) {
            Console::warning("Error while updating metric  {$from}  " . $th->getMessage());
        }
    }

    /**
     * Migrate functions.
     *
     * @return void
     * @throws Exception
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
     * @throws Exception
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
     * Migrating Buckets.
     *
     * @return void
     * @throws Exception
     * @throws PDOException
     */
    protected function migrateBuckets(): void
    {
        // Project level
        $this->migrateUsageMetrics('buckets.$all.count.total', 'buckets');
        $this->migrateUsageMetrics('files.$all.count.total', 'files');
        $this->migrateUsageMetrics('files.$all.storage.size', 'files.storage');

        foreach ($this->documentsIterator('buckets') as $bucket) {
            $id = "bucket_{$bucket->getInternalId()}";
            Console::log("Migrating Bucket {$id} {$bucket->getId()} ({$bucket->getAttribute('name')})");

            // Bucket level
            $bucketId = $bucket->getId();
            $bucketInternalId = $bucket->getInternalId();

            $this->migrateUsageMetrics("files.$bucketId.count.total", "$bucketInternalId.files");
            $this->migrateUsageMetrics("files.$bucketId.storage.size", "$bucketInternalId.files.storage");
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
            case 'projects':
                /**
                 * Bump version number.
                 */
                $document->setAttribute('version', '1.5.0');
                break;
            case 'schedules':
                $document->setAttribute('resourceCollection', 'functions');
                break;
            case 'users':
                if ($document->getAttribute('email', '') !== '') {
                    $target = new Document([
                        '$id' => ID::unique(),
                        'userId' => $document->getId(),
                        'userInternalId' => $document->getInternalId(),
                        'providerType' => MESSAGE_TYPE_EMAIL,
                        'identifier' => $document->getAttribute('email'),
                    ]);
                    $this->projectDB->createDocument('targets', $target);
                }

                if ($document->getAttribute('phone', '') !== '') {
                    $target = new Document([
                        '$id' => ID::unique(),
                        'userId' => $document->getId(),
                        'userInternalId' => $document->getInternalId(),
                        'providerType' => MESSAGE_TYPE_SMS,
                        'identifier' => $document->getAttribute('phone'),
                    ]);
                    $this->projectDB->createDocument('targets', $target);
                }
                break;
        }
        return $document;
    }
}
