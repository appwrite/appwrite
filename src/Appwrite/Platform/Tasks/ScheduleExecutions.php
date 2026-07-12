<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
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

            $data = $dbForPlatform->getDocument(
                'schedules',
                $schedule['$id'],
            )->getAttribute('data', []);

            $delay = $scheduledAt->getTimestamp() - (new \DateTime())->getTimestamp();

            $this->updateProjectAccess($schedule['project'], $dbForPlatform);

            \go(function () use ($publisherForFunctions, $schedule, $scheduledAt, $delay, $data, $dbForPlatform) {
                if ($delay > 0) {
                    Co::sleep($delay);
                }

                $publisherForFunctions->enqueue(new FunctionMessage(
                    project: $schedule['project'],
                    userId: $data['userId'] ?? '',
                    functionId: $schedule['resource']['resourceId'],
                    execution: $schedule['resource'],
                    type: 'schedule',
                    body: $data['body'] ?? '',
                    path: $data['path'] ?? '/',
                    headers: $data['headers'] ?? [],
                    method: $data['method'] ?? 'POST',
                ));

                $dbForPlatform->deleteDocument(
                    'schedules',
                    $schedule['$id'],
                );

                $this->recordEnqueueDelay($scheduledAt);
                unset($this->schedules[$schedule['$sequence']]);
            });
        }
    }
}
