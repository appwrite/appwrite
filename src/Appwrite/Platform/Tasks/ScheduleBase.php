<?php

namespace Appwrite\Platform\Tasks;

use Swoole\Timer;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Queue\Broker\Pool as BrokerPool;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Gauge;
use Utopia\Telemetry\Histogram;

abstract class ScheduleBase extends Action
{
    protected const UPDATE_TIMER = 10; //seconds
    protected const ENQUEUE_TIMER = 60; //seconds

    protected array $schedules = [];

    protected BrokerPool $publisher;
    protected BrokerPool $publisherMigrations;
    protected BrokerPool $publisherFunctions;

    private ?Histogram $collectSchedulesTelemetryDuration = null;
    private ?Gauge $collectSchedulesTelemetryCount = null;
    private ?Gauge $scheduleTelemetryCount = null;
    private ?Histogram $enqueueDelayTelemetry = null;

    abstract public static function getName(): string;
    abstract public static function getSupportedResource(): string;
    abstract public static function getCollectionId(): string;
    abstract protected function enqueueResources(Database $dbForPlatform, callable $getProjectDB): void;

    public function __construct()
    {
        $type = static::getSupportedResource();

        $this
            ->desc("Execute {$type}s scheduled in Appwrite")
            ->inject('publisher')
            ->inject('publisherMigrations')
            ->inject('publisherFunctions')
            ->inject('dbForPlatform')
            ->inject('getProjectDB')
            ->inject('telemetry')
            ->callback($this->action(...));
    }

    protected function updateProjectAccess(Document $project, Database $dbForPlatform): void
    {
        if (!$project->isEmpty() && $project->getId() !== 'console') {
            $accessedAt = $project->getAttribute('accessedAt', 0);
            if (DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_PROJECT_ACCESS)) > $accessedAt) {
                $project->setAttribute('accessedAt', DateTime::now());
                Authorization::skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), $project));
            }
        }
    }

    /**
     * 1. Load all documents from 'schedules' collection to create local copy
     * 2. Create timer that sync all changes from 'schedules' collection to local copy. Only reading changes thanks to 'resourceUpdatedAt' attribute
     * 3. Create timer that prepares coroutines for soon-to-execute schedules. When it's ready, coroutine sleeps until exact time before sending request to worker.
     */
    public function action(BrokerPool $publisher, BrokerPool $publisherMigrations, BrokerPool $publisherFunctions, Database $dbForPlatform, callable $getProjectDB, Telemetry $telemetry): void
    {
        Console::title(\ucfirst(static::getSupportedResource()) . ' scheduler V1');
        Console::success(APP_NAME . ' ' . \ucfirst(static::getSupportedResource()) . ' scheduler v1 has started');

        $this->publisher = $publisher;
        $this->publisherMigrations = $publisherMigrations;
        $this->publisherFunctions = $publisherFunctions;

        $this->scheduleTelemetryCount = $telemetry->createGauge('task.schedule.count');
        $this->collectSchedulesTelemetryDuration = $telemetry->createHistogram('task.schedule.collect_schedules.duration', 's');
        $this->collectSchedulesTelemetryCount = $telemetry->createGauge('task.schedule.collect_schedules.count');
        $this->enqueueDelayTelemetry = $telemetry->createHistogram('task.schedule.enqueue_delay', 's');

        // start with "0" to load all active documents.
        $lastSyncUpdate = "0";
        $this->collectSchedules($dbForPlatform, $getProjectDB, $lastSyncUpdate);

        Console::success("Starting timers at " . DateTime::now());
        /**
         * The timer synchronize $schedules copy with database collection.
         */
        Timer::tick(static::UPDATE_TIMER * 1000, function () use ($dbForPlatform, $getProjectDB, &$lastSyncUpdate) {
            $time = DateTime::now();
            Console::log("Sync tick: Running at $time");
            $this->collectSchedules($dbForPlatform, $getProjectDB, $lastSyncUpdate);
        });

        while (true) {
            try {
                go(fn () => $this->enqueueResources($dbForPlatform, $getProjectDB));
                $this->scheduleTelemetryCount->record(count($this->schedules), ['resourceType' => static::getSupportedResource()]);
                sleep(static::ENQUEUE_TIMER);
            } catch (\Throwable $th) {
                Console::error('Failed to enqueue resources: ' . $th->getMessage());
            }

        }
    }

    private function collectSchedules(Database $dbForPlatform, callable $getProjectDB, string &$lastSyncUpdate): void
    {
        // If we haven't synced yet, load all active schedules
        $initialLoad = $lastSyncUpdate === "0";

        /**
         * Extract only necessary attributes to lower memory used.
         *
         * @return  array
         * @throws Exception
         * @var Document $schedule
         */
        $getSchedule = function (Document $schedule) use ($dbForPlatform, $getProjectDB): array {
            $project = $dbForPlatform->getDocument('projects', $schedule->getAttribute('projectId'));

            $resource = $getProjectDB($project)->getDocument(
                static::getCollectionId(),
                $schedule->getAttribute('resourceId')
            );

            return [
                '$sequence' => $schedule->getSequence(),
                '$id' => $schedule->getId(),
                'resourceId' => $schedule->getAttribute('resourceId'),
                'schedule' => $schedule->getAttribute('schedule'),
                'active' => $schedule->getAttribute('active'),
                'resourceUpdatedAt' => $schedule->getAttribute('resourceUpdatedAt'),
                'project' => $project, // TODO: @Meldiron Send only ID to worker to reduce memory usage here
                'resource' => $resource, // TODO: @Meldiron Send only ID to worker to reduce memory usage here
            ];
        };

        $loadStart = microtime(true);
        $time = DateTime::now();

        $limit = 10_000;
        $sum = $limit;
        $total = 0;
        $latestDocument = null;

        while ($sum === $limit) {
            $paginationQueries = [Query::limit($limit)];

            if ($latestDocument) {
                $paginationQueries[] = Query::cursorAfter($latestDocument);
            }

            // Temporarly accepting both 'fra' and 'default'
            // When all migrated, only use _APP_REGION with 'default' as default value
            $regions = [System::getEnv('_APP_REGION', 'default')];
            if (!in_array('default', $regions)) {
                $regions[] = 'default';
            }

            $paginationQueries = [
                ...$paginationQueries,
                Query::equal('region', $regions),
                Query::equal('resourceType', [static::getSupportedResource()]),
            ];

            if ($initialLoad) {
                $paginationQueries[] = Query::equal('active', [true]);
            } else {
                $paginationQueries[] = Query::greaterThanEqual('resourceUpdatedAt', $lastSyncUpdate);
            }

            $results = $dbForPlatform->find('schedules', $paginationQueries);

            $sum = count($results);
            $total = $total + $sum;

            foreach ($results as $document) {
                $localDocument = $this->schedules[$document->getSequence()] ?? null;

                if ($localDocument !== null) {
                    if (!$document['active']) {
                        Console::info("Removing: {$document['resourceType']}::{$document['resourceId']}");
                        unset($this->schedules[$document->getSequence()]);
                    } elseif (strtotime($localDocument['resourceUpdatedAt']) !== strtotime($document['resourceUpdatedAt'])) {
                        Console::info("Updating: {$document['resourceType']}::{$document['resourceId']}");
                        $this->schedules[$document->getSequence()] = $getSchedule($document);
                    }
                } else {
                    try {
                        $this->schedules[$document->getSequence()] = $getSchedule($document);
                    } catch (\Throwable $th) {
                        $collectionId = static::getCollectionId();
                        Console::error("Failed to load schedule for project {$document['projectId']} {$collectionId} {$document['resourceId']}");
                        Console::error($th->getMessage());
                    }
                }
            }

            $latestDocument = \end($results);
        }

        $lastSyncUpdate = $time;
        $duration = microtime(true) - $loadStart;
        $this->collectSchedulesTelemetryDuration->record($duration, ['initial' => $initialLoad, 'resourceType' => static::getSupportedResource()]);
        $this->collectSchedulesTelemetryCount->record($total, ['initial' => $initialLoad, 'resourceType' => static::getSupportedResource()]);
        Console::success("{$total} resources were loaded in " . $duration . " seconds");
    }

    protected function recordEnqueueDelay(\DateTime $expectedExecutionSchedule): void
    {
        $this->enqueueDelayTelemetry->record(time() - $expectedExecutionSchedule->getTimestamp(), ['resourceType' => static::getSupportedResource()]);
    }
}
