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
use Utopia\Queue\Publisher;
use Utopia\System\System;

use function Swoole\Coroutine\run;

abstract class ScheduleBase extends Action
{
    protected const UPDATE_TIMER = 10; //seconds
    protected const ENQUEUE_TIMER = 60; //seconds

    protected array $schedules = [];

    abstract public static function getName(): string;
    abstract public static function getSupportedResource(): string;
    abstract public static function getCollectionId(): string;
    abstract protected function enqueueResources(Publisher $publisher, Database $dbForPlatform, callable $getProjectDB): void;

    public function __construct()
    {
        $type = static::getSupportedResource();

        $this
            ->desc("Execute {$type}s scheduled in Appwrite")
            ->inject('publisher')
            ->inject('dbForPlatform')
            ->inject('getProjectDB')
            ->callback(fn (Publisher $publisher, Database $dbForPlatform, callable $getProjectDB) => $this->action($publisher, $dbForPlatform, $getProjectDB));
    }

    protected function updateProjectAccess(Document $project, Database $dbForPlatform): void
    {
        if (!$project->isEmpty() && $project->getId() !== 'console') {
            $accessedAt = $project->getAttribute('accessedAt', '');
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
    public function action(Publisher $publisher, Database $dbForPlatform, callable $getProjectDB): void
    {
        Console::title(\ucfirst(static::getSupportedResource()) . ' scheduler V1');
        Console::success(APP_NAME . ' ' . \ucfirst(static::getSupportedResource()) . ' scheduler v1 has started');

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
                '$internalId' => $schedule->getInternalId(),
                '$id' => $schedule->getId(),
                'resourceId' => $schedule->getAttribute('resourceId'),
                'schedule' => $schedule->getAttribute('schedule'),
                'active' => $schedule->getAttribute('active'),
                'resourceUpdatedAt' => $schedule->getAttribute('resourceUpdatedAt'),
                'project' => $project, // TODO: @Meldiron Send only ID to worker to reduce memory usage here
                'resource' => $resource, // TODO: @Meldiron Send only ID to worker to reduce memory usage here
            ];
        };

        $lastSyncUpdate = DateTime::now();

        $limit = 10_000;
        $sum = $limit;
        $total = 0;
        $loadStart = \microtime(true);
        $latestDocument = null;

        while ($sum === $limit) {
            $paginationQueries = [Query::limit($limit)];

            if ($latestDocument) {
                $paginationQueries[] = Query::cursorAfter($latestDocument);
            }

            $results = $dbForPlatform->find('schedules', \array_merge($paginationQueries, [
                Query::equal('region', [System::getEnv('_APP_REGION', 'default')]),
                Query::equal('resourceType', [static::getSupportedResource()]),
                Query::equal('active', [true]),
            ]));

            $sum = \count($results);
            $total = $total + $sum;

            foreach ($results as $document) {
                try {
                    $this->schedules[$document->getInternalId()] = $getSchedule($document);
                } catch (\Throwable $th) {
                    $collectionId = static::getCollectionId();
                    Console::error("Failed to load schedule for project {$document['projectId']} {$collectionId} {$document['resourceId']}");
                    Console::error($th->getMessage());
                }
            }

            $latestDocument = \end($results);
        }

        Console::success("{$total} resources were loaded in " . (\microtime(true) - $loadStart) . " seconds");

        Console::success("Starting timers at " . DateTime::now());

        run(function () use ($dbForPlatform, &$lastSyncUpdate, $getSchedule, $publisher, $getProjectDB) {
            /**
             * The timer synchronize $schedules copy with database collection.
             */
            Timer::tick(static::UPDATE_TIMER * 1000, function () use ($dbForPlatform, &$lastSyncUpdate, $getSchedule) {
                $time = DateTime::now();
                $timerStart = \microtime(true);

                $limit = 1000;
                $sum = $limit;
                $total = 0;
                $latestDocument = null;

                Console::log("Sync tick: Running at $time");

                while ($sum === $limit) {
                    $paginationQueries = [Query::limit($limit)];

                    if ($latestDocument) {
                        $paginationQueries[] = Query::cursorAfter($latestDocument);
                    }

                    $results = $dbForPlatform->find('schedules', \array_merge($paginationQueries, [
                        Query::equal('region', [System::getEnv('_APP_REGION', 'default')]),
                        Query::equal('resourceType', [static::getSupportedResource()]),
                        Query::greaterThanEqual('resourceUpdatedAt', $lastSyncUpdate),
                    ]));

                    $sum = count($results);
                    $total = $total + $sum;

                    foreach ($results as $document) {
                        $localDocument = $this->schedules[$document->getInternalId()] ?? null;

                        // Check if resource has been updated since last sync
                        $org = $localDocument !== null ? \strtotime($localDocument['resourceUpdatedAt']) : null;
                        $new = \strtotime($document['resourceUpdatedAt']);

                        if (!$document['active']) {
                            Console::info("Removing: {$document['resourceType']}::{$document['resourceId']}");
                            unset($this->schedules[$document->getInternalId()]);
                        } elseif ($new !== $org) {
                            Console::info("Updating: {$document['resourceType']}::{$document['resourceId']}");
                            $this->schedules[$document->getInternalId()] = $getSchedule($document);
                        }
                    }

                    $latestDocument = \end($results);
                }

                $lastSyncUpdate = $time;
                $timerEnd = \microtime(true);

                Console::log("Sync tick: {$total} schedules were updated in " . ($timerEnd - $timerStart) . " seconds");
            });

            Timer::tick(
                static::ENQUEUE_TIMER * 1000,
                fn () => $this->enqueueResources($publisher, $dbForPlatform, $getProjectDB)
            );

            $this->enqueueResources($publisher, $dbForPlatform, $getProjectDB);
        });
    }
}
