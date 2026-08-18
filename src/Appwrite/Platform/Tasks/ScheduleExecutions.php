<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Message\Func as FunctionMessage;
use Swoole\Coroutine as Co;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;

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

    private bool $enqueuing = false;

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
        if ($this->enqueuing) {
            return;
        }

        $this->enqueuing = true;

        try {
            $intervalEnd = (new \DateTime())->modify('+' . self::ENQUEUE_TIMER . ' seconds');

            $schedules = [];
            foreach ($this->schedules as $schedule) {
                if (!$schedule['active']) {
                    unset($this->schedules[$schedule['$sequence']]);
                    continue;
                }

                $scheduledAt = new \DateTime($schedule['schedule']);
                if ($scheduledAt > $intervalEnd) {
                    continue;
                }

                $schedules[] = [$scheduledAt, $schedule];
            }

            usort($schedules, static fn (array $first, array $second) => $first[0] <=> $second[0]);

            foreach ($schedules as [$scheduledAt, $schedule]) {
                $delay = $scheduledAt->getTimestamp() - (new \DateTime())->getTimestamp();

                try {
                    if ($delay > 0) {
                        Co::sleep($delay);
                    }

                    $this->publisherForFunctions->enqueue(new FunctionMessage(
                        project: $schedule['project'],
                        functionId: $schedule['resource']->getAttribute('resourceId', ''),
                        execution: new Document([
                            '$id' => $schedule['resourceId'],
                            'scheduleId' => $schedule['$id'],
                        ]),
                        type: 'schedule',
                    ));

                    $this->recordEnqueueDelay($scheduledAt);
                    unset($this->schedules[$schedule['$sequence']]);
                } catch (\Throwable $th) {
                    Console::error("Failed to enqueue scheduled execution {$schedule['resourceId']}: {$th->getMessage()}");
                    break;
                }
            }
        } finally {
            $this->enqueuing = false;
        }
    }
}
