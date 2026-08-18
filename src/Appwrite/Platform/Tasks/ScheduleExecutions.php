<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Swoole\Coroutine as Co;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Lock\Semaphore;

/**
 * ScheduleExecutions
 *
 * Handles delayed executions by processing one-time scheduled tasks
 * that are executed at a specific future time.
 */
class ScheduleExecutions extends ScheduleBase
{
    public const UPDATE_TIMER = 3; // seconds
    public const ENQUEUE_TIMER = 4; // seconds
    protected const ENQUEUE_CONCURRENCY = 10;

    private ?Semaphore $enqueueSemaphore = null;
    private array $enqueuing = [];

    public static function getName(): string
    {
        return 'schedule-executions';
    }

    public static function getSupportedResource(): string
    {
        return SCHEDULE_RESOURCE_TYPE_EXECUTION;
    }

    public static function getCollectionId(): string
    {
        return RESOURCE_TYPE_EXECUTIONS;
    }

    protected function loadResource(Document $project, callable $getProjectDB, array $schedule): Document
    {
        // Executions are not persisted; the schedule carries what the worker
        // needs. Schedules from before the executions collection was dropped
        // can still resolve their document for the functionId their data lacks.
        try {
            $resource = parent::loadResource($project, $getProjectDB, $schedule);
        } catch (\Throwable) {
            $resource = new Document();
        }

        return $resource->isEmpty()
            ? new Document(['$id' => $schedule['resourceId']])
            : $resource;
    }

    protected function enqueueResources(Database $dbForPlatform, callable $getProjectDB): void
    {
        $intervalEnd = (new \DateTime())->modify('+' . self::ENQUEUE_TIMER . ' seconds');

        $publisherForFunctions = new FunctionPublisher(
            $this->publisherFunctions,
            new \Utopia\Queue\Queue(\Utopia\System\System::getEnv('_APP_FUNCTIONS_QUEUE_NAME', \Appwrite\Event\Event::FUNCTIONS_QUEUE_NAME), 'utopia-queue', \Appwrite\Event\Event::FUNCTIONS_QUEUE_TTL)
        );

        foreach ($this->schedules as $schedule) {
            if (!$schedule['active']) {
                $dbForPlatform->deleteDocument(
                    'schedules',
                    $schedule['$id'],
                );

                unset($this->schedules[$schedule['$sequence']]);
                continue;
            }

            $scheduledAt = new \DateTime($schedule['schedule']);
            if ($scheduledAt > $intervalEnd) {
                continue;
            }

            $sequence = $schedule['$sequence'];
            if (isset($this->enqueuing[$sequence])) {
                continue;
            }

            $delay = $scheduledAt->getTimestamp() - (new \DateTime())->getTimestamp();
            $this->enqueuing[$sequence] = true;

            \go(function () use ($publisherForFunctions, $schedule, $scheduledAt, $delay, $dbForPlatform, $sequence) {
                try {
                    if ($delay > 0) {
                        Co::sleep($delay);
                    }

                    $this->withEnqueueSlot(function () use ($publisherForFunctions, $schedule, $scheduledAt, $dbForPlatform, $sequence) {
                        $data = $dbForPlatform->getDocument(
                            'schedules',
                            $schedule['$id'],
                        )->getAttribute('data', []);

                        $functionId = $data['functionId'] ?? $schedule['resource']->getAttribute('resourceId', '');

                        if (empty($functionId)) {
                            Console::error("Missing functionId for scheduled execution {$schedule['resourceId']}, skipping");

                            $dbForPlatform->deleteDocument(
                                'schedules',
                                $schedule['$id'],
                            );

                            unset($this->schedules[$sequence]);
                            return;
                        }

                        $this->updateProjectAccess($schedule['project'], $dbForPlatform);

                        // Atomically claim the schedule before publishing. A
                        // cancellation takes the same lock, so exactly one path can
                        // claim the scheduled execution.
                        $enqueued = $this->enqueueIfActive(
                            $dbForPlatform,
                            $schedule['$id'],
                            fn () => $publisherForFunctions->enqueue(new FunctionMessage(
                                project: $schedule['project'],
                                userId: $data['userId'] ?? '',
                                functionId: $functionId,
                                execution: $schedule['resource'],
                                type: 'schedule',
                                body: $data['body'] ?? '',
                                path: $data['path'] ?? '/',
                                headers: $data['headers'] ?? [],
                                method: $data['method'] ?? 'POST',
                            )),
                        );

                        if ($enqueued) {
                            $this->recordEnqueueDelay($scheduledAt);
                        }

                        unset($this->schedules[$sequence]);
                    });
                } catch (\Throwable $th) {
                    Console::error("Failed to enqueue scheduled execution {$schedule['resourceId']}: {$th->getMessage()}");
                } finally {
                    unset($this->enqueuing[$sequence]);
                }
            });
        }
    }

    protected function withEnqueueSlot(callable $callback): mixed
    {
        $this->enqueueSemaphore ??= new Semaphore(static::ENQUEUE_CONCURRENCY);

        return $this->enqueueSemaphore->withLock($callback);
    }

    protected function enqueueIfActive(Database $dbForPlatform, string $scheduleId, callable $enqueue): bool
    {
        $claimed = $dbForPlatform->withTransaction(function () use ($dbForPlatform, $scheduleId) {
            $schedule = $dbForPlatform->getDocument('schedules', $scheduleId, forUpdate: true);

            if ($schedule->isEmpty() || !$schedule->getAttribute('active', false)) {
                return false;
            }

            $schedule = $dbForPlatform->updateDocument('schedules', $scheduleId, new Document([
                'resourceUpdatedAt' => DateTime::now(),
                'active' => false,
            ]));

            return !$schedule->isEmpty();
        });

        if (!$claimed) {
            return false;
        }

        $published = false;
        try {
            return $dbForPlatform->withTransaction(function () use ($dbForPlatform, $scheduleId, $enqueue, &$published) {
                $schedule = $dbForPlatform->getDocument('schedules', $scheduleId, forUpdate: true);

                if ($schedule->isEmpty()) {
                    return false;
                }

                $enqueue();
                $published = true;

                if (!$dbForPlatform->deleteDocument('schedules', $scheduleId)) {
                    throw new \RuntimeException('Failed to remove claimed execution schedule');
                }

                return true;
            });
        } catch (\Throwable $error) {
            // A failed publish releases the claim for a later retry. Once the
            // publish succeeds, keep the schedule inactive even if cleanup
            // fails so another scheduler cannot publish it again.
            if (!$published) {
                $dbForPlatform->updateDocument('schedules', $scheduleId, new Document([
                    'resourceUpdatedAt' => DateTime::now(),
                    'active' => true,
                ]));
            }
            throw $error;
        }
    }
}
