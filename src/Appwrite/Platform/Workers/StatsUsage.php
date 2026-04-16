<?php

namespace Appwrite\Platform\Workers;

use Exception;
use Throwable;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Registry\Registry;
use Utopia\System\System;

class StatsUsage extends Action
{
    /**
     * In memory per project metrics calculation
     */
    protected array $stats = [];
    protected int $lastTriggeredTime = 0;
    protected int $keys = 0;
    protected const INFINITY_PERIOD = '_inf_';
    protected const BATCH_SIZE_DEVELOPMENT = 1;
    protected const BATCH_SIZE_PRODUCTION = 10_000;

    /**
    * Stats for batch write separated per project
    * @var array
    */
    protected array $projects = [];

    /**
     * Array of stat documents to batch write to logsDB
     * @var array
     */
    protected array $statDocuments = [];

    protected Registry $register;

    /**
     * Metrics to skip writing to logsDB
     * As these metrics are calculated separately
     * by logs DB
     * @var array
     */
    protected array $skipBaseMetrics = [
        METRIC_DATABASES => true,
        METRIC_BUCKETS => true,
        METRIC_USERS => true,
        METRIC_FUNCTIONS => true,
        METRIC_TEAMS => true,
        METRIC_MESSAGES => true,
        METRIC_MAU => true,
        METRIC_WEBHOOKS => true,
        METRIC_PLATFORMS => true,
        METRIC_PROVIDERS => true,
        METRIC_TOPICS => true,
        METRIC_KEYS => true,
        METRIC_FILES => true,
        METRIC_FILES_STORAGE => true,
        METRIC_DEPLOYMENTS_STORAGE => true,
        METRIC_BUILDS_STORAGE => true,
        METRIC_DEPLOYMENTS => true,
        METRIC_BUILDS => true,
        METRIC_COLLECTIONS => true,
        METRIC_DOCUMENTS => true,
        METRIC_DATABASES_STORAGE => true,
    ];

    /**
     * Skip metrics associated with parent IDs
     * these need to be checked individually with `str_ends_with`
     */
    protected array $skipParentIdMetrics = [
        '.files',
        '.files.storage',
        '.collections',
        '.documents',
        '.deployments',
        '.deployments.storage',
        '.builds',
        '.builds.storage',
        '.databases.storage'
    ];

    /**
     * @var callable(): Database
     */
    protected mixed $getLogsDB;

    protected array $periods = [
        '1h' => 'Y-m-d H:00',
        '1d' => 'Y-m-d 00:00',
        'inf' => '0000-00-00 00:00'
    ];

    public static function getName(): string
    {
        return 'stats-usage';
    }

    protected function getBatchSize(): int
    {
        return System::getEnv('_APP_ENV', 'development') === 'development'
            ? self::BATCH_SIZE_DEVELOPMENT
            : self::BATCH_SIZE_PRODUCTION;
    }
    /**
     * @throws Exception
     */
    public function __construct()
    {

        $this
            ->desc('Stats usage worker')
            ->inject('message')
            ->inject('getProjectDB')
            ->inject('getLogsDB')
            ->inject('register')
            ->callback($this->action(...));

        $this->lastTriggeredTime = time();
    }

    /**
     * @param Message $message
     * @param callable(): Database $getProjectDB
     * @param callable(): Database $getLogsDB
     * @param Registry $register
     * @return void
     * @throws \Utopia\Database\Exception
     * @throws Exception
     */
    public function action(Message $message, callable $getProjectDB, callable $getLogsDB, Registry $register): void
    {
        $this->getLogsDB = $getLogsDB;
        $this->register = $register;
        $payload = $message->getPayload() ?? [];
        if (empty($payload)) {
            throw new Exception('Missing payload');
        }
        //Todo Figure out way to preserve keys when the container is being recreated @shimonewman

        $aggregationInterval = (int) System::getEnv('_APP_USAGE_AGGREGATION_INTERVAL', '20');
        $project = new Document($payload['project'] ?? []);
        $projectId = $project->getSequence();
        foreach ($payload['reduce'] ?? [] as $document) {
            if (empty($document)) {
                continue;
            }

            $this->reduce(
                project: $project,
                document: new Document($document),
                metrics:  $payload['metrics'],
                getProjectDB: $getProjectDB
            );
        }

        $this->stats[$projectId]['project'] = $project;
        $this->stats[$projectId]['receivedAt'] = DateTime::now();
        foreach ($payload['metrics'] ?? [] as $metric) {
            $this->keys++;
            if (!isset($this->stats[$projectId]['keys'][$metric['key']])) {
                $this->stats[$projectId]['keys'][$metric['key']] = $metric['value'];
                continue;
            }

            $this->stats[$projectId]['keys'][$metric['key']] += $metric['value'];
        }

        // If keys crossed threshold or X time passed since the last send and there are some keys in the array ($this->stats)
        if (
            $this->keys >= $this->getBatchSize() ||
            (time() - $this->lastTriggeredTime > $aggregationInterval  && $this->keys > 0)
        ) {
            Console::warning('[' . DateTime::now() . '] Aggregated ' . $this->keys . ' keys');

            $this->commitToDB($getProjectDB);

            $this->stats = [];
            $this->keys = 0;
            $this->lastTriggeredTime = time();
        }
    }

    /**
    * On Documents that tied by relations like functions>deployments>build || documents>collection>database || buckets>files.
    * When we remove a parent document we need to deduct his children aggregation from the project scope.
    * @param Document $project
    * @param Document $document
    * @param array $metrics
    * @param  callable(): Database $getProjectDB
    * @return void
    */
    protected function reduce(Document $project, Document $document, array &$metrics, callable $getProjectDB): void
    {
        $dbForProject = $getProjectDB($project);

        try {
            switch (true) {
                case $document->getCollection() === 'users': // users
                    $sessions = count($document->getAttribute(METRIC_SESSIONS, 0));
                    if (!empty($sessions)) {
                        $metrics[] = [
                            'key' => METRIC_SESSIONS,
                            'value' => ($sessions * -1),
                        ];
                    }
                    break;
                case $document->getCollection() === 'databases': // databases
                    $collections = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace('{databaseInternalId}', $document->getSequence(), METRIC_DATABASE_ID_COLLECTIONS)));
                    $documents = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace('{databaseInternalId}', $document->getSequence(), METRIC_DATABASE_ID_DOCUMENTS)));
                    if (!empty($collections['value'])) {
                        $metrics[] = [
                            'key' => METRIC_COLLECTIONS,
                            'value' => ($collections['value'] * -1),
                        ];
                    }

                    if (!empty($documents['value'])) {
                        $metrics[] = [
                            'key' => METRIC_DOCUMENTS,
                            'value' => ($documents['value'] * -1),
                        ];
                    }
                    break;
                case str_starts_with($document->getCollection(), 'database_') && !str_contains($document->getCollection(), 'collection'): //collections
                    $parts = explode('_', $document->getCollection());
                    $databaseInternalId = $parts[1] ?? 0;
                    $documents = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace(
                        ['{databaseInternalId}', '{collectionInternalId}'],
                        [$databaseInternalId, $document->getSequence()],
                        METRIC_DATABASE_ID_COLLECTION_ID_DOCUMENTS
                    )));

                    if (!empty($documents['value'])) {
                        $metrics[] = [
                            'key' => METRIC_DOCUMENTS,
                            'value' => ($documents['value'] * -1),
                        ];
                        $metrics[] = [
                            'key' => str_replace('{databaseInternalId}', $databaseInternalId, METRIC_DATABASE_ID_DOCUMENTS),
                            'value' => ($documents['value'] * -1),
                        ];
                    }
                    break;

                case $document->getCollection() === 'buckets':
                    $files = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace('{bucketInternalId}', $document->getSequence(), METRIC_BUCKET_ID_FILES)));
                    $storage = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace('{bucketInternalId}', $document->getSequence(), METRIC_BUCKET_ID_FILES_STORAGE)));

                    if (!empty($files['value'])) {
                        $metrics[] = [
                            'key' => METRIC_FILES,
                            'value' => ($files['value'] * -1),
                        ];
                    }

                    if (!empty($storage['value'])) {
                        $metrics[] = [
                            'key' => METRIC_FILES_STORAGE,
                            'value' => ($storage['value'] * -1),
                        ];
                    }
                    break;

                case $document->getCollection() === 'functions' || $document->getCollection() === 'sites':
                    $deployments = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace(['{resourceType}', '{resourceInternalId}'], [$document->getCollection(), $document->getSequence()], METRIC_RESOURCE_TYPE_ID_DEPLOYMENTS)));
                    $deploymentsStorage = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace(['{resourceType}', '{resourceInternalId}'], [$document->getCollection(), $document->getSequence()], METRIC_RESOURCE_TYPE_ID_DEPLOYMENTS_STORAGE)));
                    $builds = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace(['{resourceType}', '{resourceInternalId}'], [$document->getCollection(), $document->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS)));
                    $buildsStorage = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace(['{resourceType}', '{resourceInternalId}'], [$document->getCollection(), $document->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS_STORAGE)));
                    $buildsCompute = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace(['{resourceType}', '{resourceInternalId}'], [$document->getCollection(), $document->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS_COMPUTE)));
                    $executions = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace(['{resourceType}', '{resourceInternalId}'], [$document->getCollection(), $document->getSequence()], METRIC_RESOURCE_TYPE_ID_EXECUTIONS)));
                    $executionsCompute = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace(['{resourceType}', '{resourceInternalId}'], [$document->getCollection(), $document->getSequence()], METRIC_RESOURCE_TYPE_ID_EXECUTIONS_COMPUTE)));

                    if (!empty($deployments['value'])) {
                        $metrics[] = [
                            'key' => METRIC_DEPLOYMENTS,
                            'value' => ($deployments['value'] * -1),
                        ];
                        $metrics[] = [
                            'key' => str_replace("{resourceType}", $document->getCollection(), METRIC_RESOURCE_TYPE_DEPLOYMENTS),
                            'value' => ($deployments['value'] * -1),
                        ];
                    }

                    if (!empty($deploymentsStorage['value'])) {
                        $metrics[] = [
                            'key' => METRIC_DEPLOYMENTS_STORAGE,
                            'value' => ($deploymentsStorage['value'] * -1),
                        ];
                        $metrics[] = [
                            'key' => str_replace("{resourceType}", $document->getCollection(), METRIC_RESOURCE_TYPE_DEPLOYMENTS_STORAGE),
                            'value' => ($deploymentsStorage['value'] * -1),
                        ];
                    }

                    if (!empty($builds['value'])) {
                        $metrics[] = [
                            'key' => METRIC_BUILDS,
                            'value' => ($builds['value'] * -1),
                        ];
                        $metrics[] = [
                            'key' => str_replace("{resourceType}", $document->getCollection(), METRIC_RESOURCE_TYPE_BUILDS),
                            'value' => ($builds['value'] * -1),
                        ];
                    }

                    if (!empty($buildsStorage['value'])) {
                        $metrics[] = [
                            'key' => METRIC_BUILDS_STORAGE,
                            'value' => ($buildsStorage['value'] * -1),
                        ];
                        $metrics[] = [
                            'key' => str_replace("{resourceType}", $document->getCollection(), METRIC_RESOURCE_TYPE_BUILDS_STORAGE),
                            'value' => ($buildsStorage['value'] * -1),
                        ];
                    }

                    if (!empty($buildsCompute['value'])) {
                        $metrics[] = [
                            'key' => METRIC_BUILDS_COMPUTE,
                            'value' => ($buildsCompute['value'] * -1),
                        ];
                        $metrics[] = [
                            'key' => str_replace("{resourceType}", $document->getCollection(), METRIC_RESOURCE_TYPE_BUILDS_COMPUTE),
                            'value' => ($buildsCompute['value'] * -1),
                        ];
                    }

                    if (!empty($executions['value'])) {
                        $metrics[] = [
                            'key' => METRIC_EXECUTIONS,
                            'value' => ($executions['value'] * -1),
                        ];
                        $metrics[] = [
                            'key' => str_replace("{resourceType}", $document->getCollection(), METRIC_RESOURCE_TYPE_EXECUTIONS),
                            'value' => ($executions['value'] * -1),
                        ];
                    }

                    if (!empty($executionsCompute['value'])) {
                        $metrics[] = [
                            'key' => METRIC_EXECUTIONS_COMPUTE,
                            'value' => ($executionsCompute['value'] * -1),
                        ];
                        $metrics[] = [
                            'key' => str_replace("{resourceType}", $document->getCollection(), METRIC_RESOURCE_TYPE_EXECUTIONS_COMPUTE),
                            'value' => ($executionsCompute['value'] * -1),
                        ];
                    }
                    break;
                default:
                    break;
            }
        } catch (Throwable $e) {
            Console::error("[reducer] " . " {DateTime::now()} " . " {$project->getSequence()} " . " {$e->getMessage()}");
        }
    }

    /**
     * Commit stats to DB
     * @param callable(): Database $getProjectDB
     * @return void
     */
    public function commitToDb(callable $getProjectDB): void
    {
        foreach ($this->stats as $stats) {
            $project = $stats['project'] ?? new Document([]);
            $numberOfKeys = !empty($stats['keys']) ? count($stats['keys']) : 0;
            $receivedAt = $stats['receivedAt'] ?? null;
            if ($numberOfKeys === 0) {
                continue;
            }

            Console::log('['.DateTime::now().'] Id: '.$project->getId(). ' InternalId: '.$project->getSequence(). ' Db: '.$project->getAttribute('database').' ReceivedAt: '.$receivedAt. ' Keys: '.$numberOfKeys);

            try {
                foreach ($stats['keys'] ?? [] as $key => $value) {
                    if ($value == 0) {
                        continue;
                    }

                    foreach ($this->periods as $period => $format) {
                        $time = null;

                        if ($period !== 'inf') {
                            $time = !empty($receivedAt) ? (new \DateTime($receivedAt))->format($format) : date($format, time());
                        }
                        $id = \md5("{$time}_{$period}_{$key}");

                        $document = new Document([
                            '$id' => $id,
                            'period' => $period,
                            'time' => $time,
                            'metric' => $key,
                            'value' => $value,
                            'region' => System::getEnv('_APP_REGION', 'default'),
                        ]);


                        $this->projects[$project->getSequence()]['project']  = new Document([
                            '$id' => $project->getId(),
                            '$sequence' => $project->getSequence(),
                            'database' => $project->getAttribute('database'),
                        ]);
                        $this->projects[$project->getSequence()]['stats'][] = $document;

                        $this->prepareForLogsDB($project, $document);
                    }
                }
            } catch (Exception $e) {
                Console::error('[' . DateTime::now() . '] project [' . $project->getSequence() . '] database [' . $project['database'] . '] ' . ' ' . $e->getMessage());
            }
        }

        foreach ($this->projects as $sequence => $projectStats) {
            if (empty($sequence)) {
                continue;
            }
            try {
                $dbForProject = $getProjectDB($projectStats['project']);
                Console::log('Processing batch with ' . count($projectStats['stats']) . ' stats');

                /**
                 * Sort by unique index key reduce locks/deadlocks
                 */
                usort($projectStats['stats'], function ($a, $b) use ($sequence) {
                    // Metric DESC
                    $cmp = strcmp($b['metric'], $a['metric']);
                    if ($cmp !== 0) {
                        return $cmp;
                    }

                    // Period ASC
                    $cmp = strcmp($a['period'], $b['period']);
                    if ($cmp !== 0) {
                        return $cmp;
                    }

                    // Time ASC, NULLs first
                    if ($a['time'] === null) {
                        return ($b['time'] === null) ? 0 : -1;
                    }
                    if ($b['time'] === null) {
                        return 1;
                    }

                    return strcmp($a['time'], $b['time']);
                });

                $dbForProject->upsertDocumentsWithIncrease('stats', 'value', $projectStats['stats']);
                Console::success('Batch successfully written to DB');
            } catch (Throwable $e) {
                Console::error('Error processing stats: ' . $e->getMessage());
            } finally {
                unset($this->projects[$sequence]);
            }
        }

        $this->writeToLogsDB();

    }

    protected function prepareForLogsDB(Document $project, Document $stat): void
    {
        if (System::getEnv('_APP_STATS_USAGE_DUAL_WRITING', 'disabled') === 'disabled') {
            return;
        }
        if (array_key_exists($stat->getAttribute('metric'), $this->skipBaseMetrics)) {
            return;
        }
        foreach ($this->skipParentIdMetrics as $skipMetric) {
            if (str_ends_with($stat->getAttribute('metric'), $skipMetric)) {
                return;
            }
        }
        $documentClone = clone $stat;
        $documentClone->setAttribute('$tenant', (int) $project->getSequence());
        $this->statDocuments[] = $documentClone;
    }

    protected function writeToLogsDB(): void
    {
        if (System::getEnv('_APP_STATS_USAGE_DUAL_WRITING', 'disabled') === 'disabled') {
            Console::log('Dual Writing is disabled. Skipping...');
            return;
        }

        $dbForLogs = ($this->getLogsDB)()
            ->setTenant(null)
            ->setTenantPerDocument(true);

        try {
            Console::log('Processing batch with ' . count($this->statDocuments) . ' stats');

            /**
             * Sort by UNIQUE KEY "_key_metric_period_time" ("_tenant","metric" DESC,"period","time")
             * Here we sort by _tenant as well because of setTenantPerDocument
             */

            usort($this->statDocuments, function ($a, $b) {
                // Tenant ASC
                $cmp = $a['$tenant'] <=> $b['$tenant'];
                if ($cmp !== 0) {
                    return $cmp;
                }

                // Metric DESC
                $cmp = strcmp($b['metric'], $a['metric']);
                if ($cmp !== 0) {
                    return $cmp;
                }

                // Period ASC
                $cmp = strcmp($a['period'], $b['period']);
                if ($cmp !== 0) {
                    return $cmp;
                }

                // Time ASC, NULLs first
                if ($a['time'] === null) {
                    return ($b['time'] === null) ? 0 : -1;
                }
                if ($b['time'] === null) {
                    return 1;
                }

                return strcmp($a['time'], $b['time']);
            });

            $dbForLogs->upsertDocumentsWithIncrease(
                'stats',
                'value',
                $this->statDocuments
            );
            Console::success('Usage logs pushed to Logs DB');

            /**
             * todo: Do we need to unset $this->statDocuments?
             */

        } catch (Throwable $th) {
            Console::error($th->getMessage());
        }
    }
}
