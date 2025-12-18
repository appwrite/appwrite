<?php

namespace Appwrite\Platform\Tasks;

use Swoole\Timer;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
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
    protected BrokerPool $publisherMessaging;

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
            ->inject('publisherMessaging')
            ->inject('isResourceBlocked')
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
                $dbForPlatform->updateDocument('projects', $project->getId(), $project);
            }
        }
    }

    /**
     * 1. Load all documents from 'schedules' collection to create local copy
     * 2. Create timer that sync all changes from 'schedules' collection to local copy. Only reading changes thanks to 'resourceUpdatedAt' attribute
     * 3. Create timer that prepares coroutines for soon-to-execute schedules. When it's ready, coroutine sleeps until exact time before sending request to worker.
     */
    public function action(BrokerPool $publisher, BrokerPool $publisherMigrations, BrokerPool $publisherFunctions, BrokerPool $publisherMessaging, callable $isResourceBlocked, Database $dbForPlatform, callable $getProjectDB, Telemetry $telemetry): void
    {
        Console::title(\ucfirst(static::getSupportedResource()) . ' scheduler V1');
        Console::success(APP_NAME . ' ' . \ucfirst(static::getSupportedResource()) . ' scheduler v1 has started');

        $this->publisher = $publisher;
        $this->publisherMigrations = $publisherMigrations;
        $this->publisherFunctions = $publisherFunctions;
        $this->publisherMessaging = $publisherMessaging;

        $this->scheduleTelemetryCount = $telemetry->createGauge('task.schedule.count');
        $this->collectSchedulesTelemetryDuration = $telemetry->createHistogram('task.schedule.collect_schedules.duration', 's');
        $this->collectSchedulesTelemetryCount = $telemetry->createGauge('task.schedule.collect_schedules.count');
        $this->enqueueDelayTelemetry = $telemetry->createHistogram('task.schedule.enqueue_delay', 's');

        // start with "0" to load all active documents.
        $lastSyncUpdate = "0";
        $this->collectSchedules($dbForPlatform, $getProjectDB, $lastSyncUpdate, $isResourceBlocked);

        Console::success("Starting timers at " . DateTime::now());
        /**
         * The timer synchronize $schedules copy with database collection.
         */
        Timer::tick(static::UPDATE_TIMER * 1000, function () use ($dbForPlatform, $getProjectDB, &$lastSyncUpdate, $isResourceBlocked) {
            $time = DateTime::now();
            Console::log("Sync tick: Running at $time");
            $this->collectSchedules($dbForPlatform, $getProjectDB, $lastSyncUpdate, $isResourceBlocked);
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

    private function collectSchedules(Database $dbForPlatform, callable $getProjectDB, string &$lastSyncUpdate, callable $isResourceBlocked): void
    {
        $initialLoad = $lastSyncUpdate === "0";
        $loadStart = microtime(true);
        $time = DateTime::now();

        $limit = 10_000;
        $sum = $limit;
        $total = 0;
        $latestDocument = null;
        $updatedProjectIds = []; // Track project IDs from updated/new schedules
        $updatedSequences = []; // Track sequences that need project/resource loading

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

            $collectionId = static::getCollectionId();
            $schedules = $dbForPlatform->find('schedules', $paginationQueries);
            $sum = count($schedules);
            $total += $sum;

            foreach ($schedules as $schedule) {
                $existing = $this->schedules[$schedule->getSequence()] ?? null;
                $updated = strtotime($existing['resourceUpdatedAt'] ?? '0') !== strtotime($schedule->getAttribute('resourceUpdatedAt') ?? '0');

                if ($existing === null || $updated) {
                    try {
                        $candidate = [
                            '$sequence' => $schedule->getSequence(),
                            '$id' => $schedule->getId(),
                            'projectId' => $schedule->getAttribute('projectId'),
                            'resourceId' => $schedule->getAttribute('resourceId'),
                            'resourceType' => $schedule->getAttribute('resourceType'),
                            'schedule' => $schedule->getAttribute('schedule'),
                            'active' => $schedule->getAttribute('active'),
                            'resourceUpdatedAt' => $schedule->getAttribute('resourceUpdatedAt'),
                        ];
                    } catch (\Throwable $th) {
                        Console::error("Failed to load schedule for project {$schedule->getAttribute('projectId')} {$collectionId} {$schedule->getAttribute('resourceId')}");
                        Console::error($th->getMessage());
                        continue;
                    }
                    // In case the resource is not active (deleted).
                    if (!$candidate['active']) {
                        Console::error("Resource is not active: {$candidate['resourceType']}::{$candidate['resourceId']}");
                        unset($this->schedules[$schedule->getSequence()]);
                        continue;
                    }

                    Console::info("Updating: {$candidate['resourceType']}::{$candidate['resourceId']}");
                    $this->schedules[$schedule->getSequence()] = $candidate;

                    // Track projectId and sequence for updated/new schedules
                    $updatedProjectIds[] = $candidate['projectId'];
                    $updatedSequences[] = $schedule->getSequence();
                }
            }

            $latestDocument = \end($schedules);
        }
        if (empty($this->schedules)) {
            Console::success("No resources found");
        }

        // On initial load: load all projects from all schedules
        if ($initialLoad) {
            $projectIds = array_unique(array_map(fn ($schedule) => $schedule['projectId'], $this->schedules));
        } else {
            // Only load projects for updated/new schedules
            $projectIds = array_unique($updatedProjectIds);
        }

        // Build existing project map from schedules that already have projects loaded
        $map = [];
        foreach ($this->schedules as $schedule) {
            if (isset($schedule['project'])) {
                $map[$schedule['projectId']] = $schedule['project'];
            }
        }

        // Only load projects that we don't already have in memory
        $projectIdsToLoad = array_filter($projectIds, fn ($projectId) => !isset($map[$projectId]));

        if (!empty($projectIdsToLoad)) {
            $projectIdsToLoad = array_values($projectIdsToLoad);
            $batchSize = APP_DATABASE_QUERY_MAX_VALUES_WORKER;
            $batches = array_chunk($projectIdsToLoad, $batchSize);
            $projectsLoadStart = microtime(true);

            foreach ($batches as $batch) {
                $documents = $dbForPlatform->find('projects', [
                    Query::equal('$id', $batch),
                    Query::limit(count($batch)),
                ]);

                foreach ($documents as $document) {
                    $map[$document->getId()] = $document;
                }
            }

            $projectsLoadDuration = microtime(true) - $projectsLoadStart;
            Console::success("Projects map loaded in " . $projectsLoadDuration . " seconds with " . count($projectIdsToLoad) . " new projects (total: " . count($map) . " projects)");
        } else {
            Console::success("No new projects to load (using " . count($map) . " cached projects)");
        }

        // Only process updated/new schedules, not all schedules
        foreach ($updatedSequences as $sequence) {
            $schedule = $this->schedules[$sequence] ?? null;
            if ($schedule === null) {
                continue;
            }

            $project = $map[$schedule['projectId']] ?? null;

            if ($project === null || $project->isEmpty()) {
                Console::error("Project not found: projectId::{$schedule['projectId']} resourceId::{$schedule['resourceId']}");
                unset($this->schedules[$sequence]);
                continue;
            }

            // In case the resource is blocked.
            if ($isResourceBlocked($project, $collectionId, $schedule['resourceId'])) {
                Console::error("Resource blocked: projectId::{$schedule['projectId']} resourceId::{$schedule['resourceId']}");
                unset($this->schedules[$sequence]);
                continue;
            }

            $this->schedules[$sequence]['project'] = $project;

            // In case the resource is not found (project deleted).
            try {
                $resource = $getProjectDB($project)->getDocument(static::getCollectionId(), $schedule['resourceId']);
            } catch (\Throwable $th) {
                Console::error("Failed to load resource: projectId::{$schedule['projectId']} resourceId::{$schedule['resourceId']}");
                Console::error($th->getMessage());
                unset($this->schedules[$sequence]);
                continue;
            }

            if ($resource->isEmpty()) {
                Console::error("Resource not found: projectId::{$schedule['projectId']} resourceId::{$schedule['resourceId']}");
                unset($this->schedules[$sequence]);
                continue;
            }

            $this->schedules[$sequence]['resource'] = $resource;
        }

        $lastSyncUpdate = $time;
        $duration = microtime(true) - $loadStart;
        $this->collectSchedulesTelemetryDuration->record($duration, ['initial' => $initialLoad, 'resourceType' => static::getSupportedResource()]);
        $this->collectSchedulesTelemetryCount->record($total, ['initial' => $initialLoad, 'resourceType' => static::getSupportedResource()]);
        Console::success("Timer loaded {$total} " . static::getName() . " in " . $duration . " seconds");
    }

    protected function recordEnqueueDelay(\DateTime $expectedExecutionSchedule): void
    {
        $this->enqueueDelayTelemetry->record(time() - $expectedExecutionSchedule->getTimestamp(), ['resourceType' => static::getSupportedResource()]);
    }
}
