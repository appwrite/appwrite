<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\UsageDump;
use Exception;
use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\System\System;

class Usage extends Action
{
    private array $stats = [];
    private int $lastTriggeredTime = 0;
    private int $keys = 0;
    private const INFINITY_PERIOD = '_inf_';
    private const KEYS_THRESHOLD = 10000;

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
        ->inject('getProjectDB')
        ->inject('queueForUsageDump')
        ->callback(function (Message $message, callable $getProjectDB, UsageDump $queueForUsageDump) {
            $this->action($message, $getProjectDB, $queueForUsageDump);
        });

        $this->lastTriggeredTime = time();
    }

    /**
     * @param Message $message
     * @param callable $getProjectDB
     * @param UsageDump $queueForUsageDump
     * @return void
     * @throws \Utopia\Database\Exception
     * @throws Exception
     */
    public function action(Message $message, callable $getProjectDB, UsageDump $queueForUsageDump): void
    {
        $payload = $message->getPayload() ?? [];
        if (empty($payload)) {
            throw new Exception('Missing payload');
        }
        //Todo Figure out way to preserve keys when the container is being recreated @shimonewman

        $aggregationInterval = (int) System::getEnv('_APP_USAGE_AGGREGATION_INTERVAL', '20');
        $project = new Document($payload['project'] ?? []);
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
            $this->keys >= self::KEYS_THRESHOLD ||
            (time() - $this->lastTriggeredTime > $aggregationInterval  && $this->keys > 0)
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
                    $buildsStorage = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace('{functionInternalId}', $document->getInternalId(), METRIC_FUNCTION_ID_BUILDS_STORAGE)));
                    $buildsCompute = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace('{functionInternalId}', $document->getInternalId(), METRIC_FUNCTION_ID_BUILDS_COMPUTE)));
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
}
