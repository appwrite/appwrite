<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Func;
use Swoole\Coroutine as Co;
use Utopia\Database\Database;

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

    protected function enqueueResources(Database $dbForPlatform, callable $getProjectDB): void
    {
        $now = new \DateTime();
        $intervalEnd = (clone $now)->modify('+' . self::ENQUEUE_TIMER . ' seconds');

        $queueForFunctions = new Func($this->publisherFunctions);

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

            // Only process schedules that fall within the enqueue window.
            // If the scheduled time is after the window, skip it for now.
            if ($scheduledAt > $intervalEnd) {
                continue;
            }

            $data = $dbForPlatform->getDocument(
                'schedules',
                $schedule['$id'],
            )->getAttribute('data', []);

            $delay = $scheduledAt->getTimestamp() - $now->getTimestamp();
            if ($delay < 0) {
                $delay = 0;
            }

            $this->updateProjectAccess($schedule['project'], $dbForPlatform);

            \go(function () use ($queueForFunctions, $schedule, $scheduledAt, $delay, $data) {
                Co::sleep($delay);

                $queueForFunctions->setType('schedule')
                    ->setFunctionId($schedule['resource']['resourceId'])
                    ->setExecution($schedule['resource'])
                    ->setMethod($data['method'] ?? 'POST')
                    ->setPath($data['path'] ?? '/')
                    ->setHeaders($data['headers'] ?? [])
                    ->setBody($data['body'] ?? '')
                    ->setProject($schedule['project'])
                    ->setUserId($data['userId'] ?? '')
                    ->trigger();

                $this->recordEnqueueDelay($scheduledAt);
            });

            $dbForPlatform->deleteDocument(
                'schedules',
                $schedule['$id'],
            );

            unset($this->schedules[$schedule['$sequence']]);
        }
    }
}
