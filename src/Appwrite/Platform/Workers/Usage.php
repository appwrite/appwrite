<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\Usage as UsageEvent;
use Appwrite\Event\UsageDump;
use Appwrite\Platform\Action;
use Exception;
use Throwable;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Queue\Message;
use Utopia\System\System;

class Usage extends Action
{
    /**
     * Date format for different periods
     */
    protected array $periods = [
        '1h' => 'Y-m-d H:00',
        '1d' => 'Y-m-d 00:00',
        'inf' => '0000-00-00 00:00'
    ];
    /**
     * Log Error Callback
     *
     * @var callable
     */
    protected mixed $logError;
    private array $stats = [];
    private int $lastTriggeredTime = 0;
    private int $aggregationInterval = 20;
    private int $keys = 0;
    private const INFINITY_PERIOD = '_inf_';
    private const KEYS_THRESHOLD = 20000;

    public static function getName(): string
    {
        return 'usage';
    }

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this
            ->desc('Usage worker')
            ->inject('message')
            ->inject('project')
            ->inject('getProjectDB')
            ->inject('queueForUsageDump')
            ->inject('getLogsDB')
            ->inject('dbForPlatform')
            ->inject('logError')
            ->callback([$this, 'action']);

        $this->aggregationInterval = (int) System::getEnv('_APP_USAGE_AGGREGATION_INTERVAL', '20');
        $this->lastTriggeredTime = time();
    }

    /**
     * @param Message $message
     * @param Document $project
     * @param callable $getProjectDB
     * @param UsageDump $queueForUsageDump
     * @return void
     * @throws \Utopia\Database\Exception
     * @throws Exception
     */
    public function action(Message $message, Document $project, callable $getProjectDB, UsageDump $queueForUsageDump, callable $getLogsDB, Database $dbForPlatform, callable $logError): void
    {
        $this->logError = $logError;

        $payload = $message->getPayload() ?? [];
        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        if (empty($project->getAttribute('database'))) {
            var_dump($payload);
            return;
        }

        $type = $payload['type'] ?? UsageEvent::TYPE_USAGE_DUMP;
        if ($type === UsageEvent::TYPE_USAGE_COUNT) {
            $this->countForProject($dbForPlatform, $getLogsDB, $getProjectDB, $project);
            return;
        }

        $projectId = $project->getInternalId();
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


        $this->stats[$projectId]['project'] = [
            '$id' => $project->getId(),
            '$internalId' => $project->getInternalId(),
            'database' => $project->getAttribute('database'),
        ];
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
            $this->keys >= self::KEYS_THRESHOLD ||
            (time() - $this->lastTriggeredTime > $this->aggregationInterval  && $this->keys > 0)
        ) {
            Console::warning('[' . DateTime::now() . '] Aggregated ' . $this->keys . ' keys');

            $queueForUsageDump
                ->setStats($this->stats)
                ->trigger();

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
    * @param  callable $getProjectDB
    * @return void
    */
    private function reduce(Document $project, Document $document, array &$metrics, callable $getProjectDB): void
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
                    $collections = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace('{databaseInternalId}', $document->getInternalId(), METRIC_DATABASE_ID_COLLECTIONS)));
                    $documents = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace('{databaseInternalId}', $document->getInternalId(), METRIC_DATABASE_ID_DOCUMENTS)));
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
                    $documents = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace(['{databaseInternalId}', '{collectionInternalId}'], [$databaseInternalId, $document->getInternalId()], METRIC_DATABASE_ID_COLLECTION_ID_DOCUMENTS)));

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
                    $files = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace('{bucketInternalId}', $document->getInternalId(), METRIC_BUCKET_ID_FILES)));
                    $storage = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace('{bucketInternalId}', $document->getInternalId(), METRIC_BUCKET_ID_FILES_STORAGE)));

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

                case $document->getCollection() === 'functions':
                    $deployments = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace(['{resourceType}', '{resourceInternalId}'], ['functions', $document->getInternalId()], METRIC_FUNCTION_ID_DEPLOYMENTS)));
                    $deploymentsStorage = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace(['{resourceType}', '{resourceInternalId}'], ['functions', $document->getInternalId()], METRIC_FUNCTION_ID_DEPLOYMENTS_STORAGE)));
                    $builds = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace('{functionInternalId}', $document->getInternalId(), METRIC_FUNCTION_ID_BUILDS)));
                    $buildsSuccess = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace('{functionInternalId}', $document->getInternalId(), METRIC_FUNCTION_ID_BUILDS_SUCCESS)));
                    $buildsFailed = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace('{functionInternalId}', $document->getInternalId(), METRIC_FUNCTION_ID_BUILDS_FAILED)));
                    $buildsStorage = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace('{functionInternalId}', $document->getInternalId(), METRIC_FUNCTION_ID_BUILDS_STORAGE)));
                    $buildsCompute = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace('{functionInternalId}', $document->getInternalId(), METRIC_FUNCTION_ID_BUILDS_COMPUTE)));
                    $buildsComputeSuccess = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace('{functionInternalId}', $document->getInternalId(), METRIC_FUNCTION_ID_BUILDS_COMPUTE_SUCCESS)));
                    $buildsComputeFailed = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace('{functionInternalId}', $document->getInternalId(), METRIC_FUNCTION_ID_BUILDS_COMPUTE_FAILED)));
                    $executions = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace('{functionInternalId}', $document->getInternalId(), METRIC_FUNCTION_ID_EXECUTIONS)));
                    $executionsCompute = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace('{functionInternalId}', $document->getInternalId(), METRIC_FUNCTION_ID_EXECUTIONS_COMPUTE)));

                    if (!empty($deployments['value'])) {
                        $metrics[] = [
                            'key' => METRIC_DEPLOYMENTS,
                            'value' => ($deployments['value'] * -1),
                        ];
                    }

                    if (!empty($deploymentsStorage['value'])) {
                        $metrics[] = [
                            'key' => METRIC_DEPLOYMENTS_STORAGE,
                            'value' => ($deploymentsStorage['value'] * -1),
                        ];
                    }

                    if (!empty($builds['value'])) {
                        $metrics[] = [
                            'key' => METRIC_BUILDS,
                            'value' => ($builds['value'] * -1),
                        ];
                    }

                    if (!empty($buildsSuccess['value'])) {
                        $metrics[] = [
                            'key' => METRIC_BUILDS_SUCCESS,
                            'value' => ($buildsSuccess['value'] * -1),
                        ];
                    }

                    if (!empty($buildsFailed['value'])) {
                        $metrics[] = [
                            'key' => METRIC_BUILDS_FAILED,
                            'value' => ($buildsFailed['value'] * -1),
                        ];
                    }

                    if (!empty($buildsStorage['value'])) {
                        $metrics[] = [
                            'key' => METRIC_BUILDS_STORAGE,
                            'value' => ($buildsStorage['value'] * -1),
                        ];
                    }

                    if (!empty($buildsCompute['value'])) {
                        $metrics[] = [
                            'key' => METRIC_BUILDS_COMPUTE,
                            'value' => ($buildsCompute['value'] * -1),
                        ];
                    }

                    if (!empty($buildsComputeSuccess['value'])) {
                        $metrics[] = [
                            'key' => METRIC_BUILDS_COMPUTE_SUCCESS,
                            'value' => ($buildsComputeSuccess['value'] * -1),
                        ];
                    }

                    if (!empty($buildsComputeFailed['value'])) {
                        $metrics[] = [
                            'key' => METRIC_BUILDS_COMPUTE_FAILED,
                            'value' => ($buildsComputeFailed['value'] * -1),
                        ];
                    }

                    if (!empty($executions['value'])) {
                        $metrics[] = [
                            'key' => METRIC_EXECUTIONS,
                            'value' => ($executions['value'] * -1),
                        ];
                    }

                    if (!empty($executionsCompute['value'])) {
                        $metrics[] = [
                            'key' => METRIC_EXECUTIONS_COMPUTE,
                            'value' => ($executionsCompute['value'] * -1),
                        ];
                    }
                    break;
                default:
                    break;
            }
        } catch (\Throwable $e) {
            console::error("[reducer] " . " {DateTime::now()} " . " {$project->getInternalId()} " . " {$e->getMessage()}");
        }
    }


    protected function countForProject(Database $dbForPlatform, callable $getLogsDB, callable $getProjectDB, Document $project): void
    {
        Console::info('Begining count for: ' . $project->getId());

        try {
            /** @var \Utopia\Database\Database $dbForLogs */
            $dbForLogs = call_user_func($getLogsDB, $project);
            /** @var \Utopia\Database\Database $dbForProject */
            $dbForProject = call_user_func($getProjectDB, $project);

            $region = $project->getAttribute('region');

            $platforms = $dbForPlatform->count('platforms', [
                Query::equal('projectInternalId', [$project->getInternalId()])
            ]);
            $webhooks = $dbForPlatform->count('webhooks', [
                Query::equal('projectInternalId', [$project->getInternalId()])
            ]);
            $keys = $dbForPlatform->count('keys', [
                Query::equal('projectInternalId', [$project->getInternalId()])
            ]);
            $databases = $dbForProject->count('databases');
            $buckets = $dbForProject->count('buckets');
            $users = $dbForProject->count('users');

            $last30Days = (new \DateTime())->sub(\DateInterval::createFromDateString('30 days'))->format('Y-m-d 00:00:00');
            $usersActive = $dbForProject->count('users', [
                Query::greaterThanEqual('accessedAt', $last30Days)
            ]);
            $teams = $dbForProject->count('teams');
            $functions = $dbForProject->count('functions');
            $messages = $dbForProject->count('messages');
            $providers = $dbForProject->count('providers');
            $topics = $dbForProject->count('topics');

            $metrics = [
                METRIC_DATABASES => $databases,
                METRIC_BUCKETS => $buckets,
                METRIC_USERS => $users,
                METRIC_FUNCTIONS => $functions,
                METRIC_TEAMS => $teams,
                METRIC_MESSAGES => $messages,
                METRIC_USERS_ACTIVE => $usersActive,
                METRIC_WEBHOOKS => $webhooks,
                METRIC_PLATFORMS => $platforms,
                METRIC_PROVIDERS => $providers,
                METRIC_TOPICS => $topics,
                METRIC_KEYS => $keys,
            ];

            foreach ($metrics as $metric => $value) {
                $this->createOrUpdateMetric($dbForLogs, $region, $metric, $value);
            }

            try {
                $this->countForBuckets($dbForProject, $dbForLogs, $region);
            } catch (Throwable $th) {
                call_user_func_array($this->logError, [$th, "usageCount", "count_for_buckets_{$project->getId()}"]);
            }

            try {
                $this->countForDatabase($dbForProject, $dbForLogs, $region);
            } catch (Throwable $th) {
                call_user_func_array($this->logError, [$th, "usageCount", "count_for_database_{$project->getId()}"]);
            }

            try {
                $this->countForFunctions($dbForProject, $dbForLogs, $region);
            } catch (Throwable $th) {
                call_user_func_array($this->logError, [$th, "usageCount", "count_for_functions_{$project->getId()}"]);
            }
        } catch (Throwable $th) {
            call_user_func_array($this->logError, [$th, "usageCount", "count_for_project_{$project->getId()}"]);
        }

        Console::info('End of count for: ' . $project->getId());
    }

    protected function countForBuckets(Database $dbForProject, Database $dbForLogs, string $region)
    {
        $totalFiles = 0;
        $totalStorage = 0;
        $this->foreachDocument($dbForProject, 'buckets', [], function ($bucket) use ($dbForProject, $dbForLogs, $region, &$totalFiles, &$totalStorage) {
            $files = $dbForProject->count('bucket_' . $bucket->getInternalId());

            $metric = str_replace('{bucketInternalId}', $bucket->getInternalId(), METRIC_BUCKET_ID_FILES);
            $this->createOrUpdateMetric($dbForLogs, $region, $metric, $files);

            $storage = $dbForProject->sum('bucket_' . $bucket->getInternalId(), 'sizeActual');
            $metric = str_replace('{bucketInternalId}', $bucket->getInternalId(), METRIC_BUCKET_ID_FILES_STORAGE);
            $this->createOrUpdateMetric($dbForLogs, $region, $metric, $storage);

            $totalStorage += $storage;
            $totalFiles += $files;
        });

        $this->createOrUpdateMetric($dbForLogs, $region, METRIC_FILES, $totalFiles);
        $this->createOrUpdateMetric($dbForLogs, $region, METRIC_FILES_STORAGE, $totalStorage);
    }

    protected function countForDatabase(Database $dbForProject, Database $dbForLogs, string $region)
    {
        $totalCollections = 0;
        $totalDocuments = 0;

        $this->foreachDocument($dbForProject, 'databases', [], function ($database) use ($dbForProject, $dbForLogs, $region, &$totalCollections, &$totalDocuments) {
            $collections = $dbForProject->count('database_' . $database->getInternalId());

            $metric = str_replace('{databaseInternalId}', $database->getInternalId(), METRIC_DATABASE_ID_COLLECTIONS);
            $this->createOrUpdateMetric($dbForLogs, $region, $metric, $collections);

            $documents = $this->countForCollections($dbForProject, $dbForLogs, $database, $region);

            $totalDocuments += $documents;
            $totalCollections += $collections;
        });

        $this->createOrUpdateMetric($dbForLogs, $region, METRIC_COLLECTIONS, $totalCollections);
        $this->createOrUpdateMetric($dbForLogs, $region, METRIC_DOCUMENTS, $totalDocuments);
    }
    protected function countForCollections(Database $dbForProject, Database $dbForLogs, Document $database, string $region): int
    {
        $databaseDocuments = 0;
        $this->foreachDocument($dbForProject, 'database_' . $database->getInternalId(), [], function ($collection) use ($dbForProject, $dbForLogs, $database, $region, &$totalCollections, &$databaseDocuments) {
            $documents = $dbForProject->count('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId());

            $metric = str_replace(['{databaseInternalId}', '{collectionInternalId}'], [$database->getInternalId(), $collection->getInternalId()], METRIC_DATABASE_ID_COLLECTION_ID_DOCUMENTS);
            $this->createOrUpdateMetric($dbForLogs, $region, $metric, $documents);

            $databaseDocuments += $documents;
        });

        $metric = str_replace(['{databaseInternalId}'], [$database->getInternalId()], METRIC_DATABASE_ID_DOCUMENTS);
        $this->createOrUpdateMetric($dbForLogs, $region, $metric, $databaseDocuments);

        return $databaseDocuments;
    }

    protected function countForFunctions(Database $dbForProject, Database $dbForLogs, string $region)
    {
        $deploymentsStorage = $dbForProject->sum('deployments', 'size');
        $buildsStorage = $dbForProject->sum('builds', 'size');
        $this->createOrUpdateMetric($dbForLogs, $region, METRIC_DEPLOYMENTS_STORAGE, $deploymentsStorage);
        $this->createOrUpdateMetric($dbForLogs, $region, METRIC_BUILDS_STORAGE, $buildsStorage);

        $deployments = $dbForProject->count('deployments');
        $builds = $dbForProject->count('builds');
        $this->createOrUpdateMetric($dbForLogs, $region, METRIC_DEPLOYMENTS, $deployments);
        $this->createOrUpdateMetric($dbForLogs, $region, METRIC_BUILDS, $builds);


        $this->foreachDocument($dbForProject, 'functions', [], function (Document $function) use ($dbForProject, $dbForLogs, $region) {
            $functionDeploymentsStorage = $dbForProject->sum('deployments', 'size', [
                Query::equal('resourceInternalId', [$function->getInternalId()]),
                Query::equal('resourceType', [RESOURCE_TYPE_FUNCTIONS]),
            ]);
            $this->createOrUpdateMetric($dbForLogs, $region, str_replace(['{resourceType}','{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS,$function->getInternalId()], METRIC_RESOURCE_TYPE_ID_DEPLOYMENTS_STORAGE), $functionDeploymentsStorage);

            $functionDeployments = $dbForProject->count('deployments', [
                Query::equal('resourceInternalId', [$function->getInternalId()]),
                Query::equal('resourceType', [RESOURCE_TYPE_FUNCTIONS]),
            ]);
            $this->createOrUpdateMetric($dbForLogs, $region, str_replace(['{resourceType}','{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS,$function->getInternalId()], METRIC_RESOURCE_TYPE_ID_DEPLOYMENTS), $functionDeployments);

            /**
             * As deployments and builds have 1-1 relationship,
             * the count for one should match the other
             */
            $this->createOrUpdateMetric($dbForLogs, $region, str_replace(['{resourceType}','{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS,$function->getInternalId()], METRIC_RESOURCE_TYPE_ID_BUILDS), $functionDeployments);

            $functionBuildsStorage = 0;

            $this->foreachDocument($dbForProject, 'deployments', [
                Query::equal('resourceInternalId', [$function->getInternalId()]),
                Query::equal('resourceType', [RESOURCE_TYPE_FUNCTIONS]),
            ], function (Document $deployment) use ($dbForProject, &$functionBuildsStorage): void {
                $build = $dbForProject->getDocument('builds', $deployment->getAttribute('buildId', ''));
                $functionBuildsStorage += $build->getAttribute('size', 0);
            });

            $this->createOrUpdateMetric($dbForLogs, $region, str_replace(['{resourceType}','{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS,$function->getInternalId()], METRIC_RESOURCE_TYPE_ID_BUILDS_STORAGE), $functionBuildsStorage);
        });
    }

    protected function createOrUpdateMetric(Database $dbForLogs, string $region, string $metric, int $value)
    {        
        foreach ($this->periods as $period => $format) {
            $time = 'inf' === $period ? null : \date($format, \time());
            $id = \md5("{$time}_{$period}_{$metric}");
            $current = $dbForLogs->getDocument('stats', $id);
            if ($current->isEmpty()) {
                $dbForLogs->createDocument('stats', new Document([
                    '$id' => $id,
                    'metric' => $metric,
                    'period' => $period,
                    'region' => $region,
                    'value' => $value,
                ]));
                Console::success('Usage logs created for metric: ' . $metric . ' period:'. $period);
            } else {
                $dbForLogs->updateDocument('stats', $id, $current->setAttribute('value', $value));
                Console::success('Usage logs updated for metric: ' . $metric . ' period:'. $period);
            }
        }
    }
}
