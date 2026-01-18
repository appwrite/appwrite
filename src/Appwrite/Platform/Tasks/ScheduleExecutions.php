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
        $intervalEnd = (new \DateTime())->modify('+' . self::ENQUEUE_TIMER . ' seconds');

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
            if ($scheduledAt > $intervalEnd) {
                continue;
            }

            $data = $dbForPlatform->getDocument(
                'schedules',
                $schedule['$id'],
            )->getAttribute('data', []);

            $delay = $scheduledAt->getTimestamp() - (new \DateTime())->getTimestamp();

            $this->updateProjectAccess($schedule['project'], $dbForPlatform);

            \go(function () use ($queueForFunctions, $schedule, $scheduledAt, $delay, $data, $dbForPlatform) {
                if ($delay > 0) {
                    Co::sleep($delay);
                }

                $queueForFunctions->setType('schedule')
                    // Set functionId instead of function as we don't have $dbForProject
                    // TODO: Refactor to use function instead of functionId
                    ->setFunctionId($schedule['resource']['resourceId'])
                    ->setExecution($schedule['resource'])
                    ->setMethod($data['method'] ?? 'POST')
                    ->setPath($data['path'] ?? '/')
                    ->setHeaders($data['headers'] ?? [])
                    ->setBody($data['body'] ?? '')
                    ->setProject($schedule['project'])
                    ->setUserId($data['userId'] ?? '')
                    ->trigger();

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
