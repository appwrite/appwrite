<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Func;
use Swoole\Coroutine as Co;
use Utopia\Database\Database;
use Utopia\Pools\Group;

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
        return 'execution';
    }

    protected function enqueueResources(Group $pools, Database $dbForConsole): void
    {
        $queue = $pools->get('queue')->pop();
        $connection = $queue->getResource();
        $queueForFunctions = new Func($connection);
        $intervalEnd = (new \DateTime())->modify('+' . self::ENQUEUE_TIMER . ' seconds');

        foreach ($this->schedules as $schedule) {
            if (!$schedule['active']) {
                $dbForConsole->deleteDocument(
                    'schedules',
                    $schedule['$id'],
                );

                unset($this->schedules[$schedule['resourceId']]);
                continue;
            }

            $scheduledAt = new \DateTime($schedule['schedule']);
            if ($scheduledAt <= $intervalEnd) {
                continue;
            }

            $delay = $scheduledAt->getTimestamp() - (new \DateTime())->getTimestamp();
            $schedule['data'] = $dbForConsole->getDocument(
                'schedules',
                $schedule['$id'],
            )->getAttribute('data');

            \go(function () use ($queueForFunctions, $schedule, $delay) {
                Co::sleep($delay);

                $queueForFunctions
                    ->setType('schedule')
                    // Set functionId instead of function as we don't have $dbForProject
                    // TODO: Refactor to use function instead of functionId
                    ->setFunctionId($schedule['resource']['functionId'])
                    ->setExecution($schedule['resource'])
                    ->setMethod($schedule['data']['method'] ?? 'POST')
                    ->setPath($schedule['data']['path'] ?? '/')
                    ->setHeaders($schedule['data']['headers'] ?? [])
                    ->setBody($schedule['data']['body'] ?? '')
                    ->setProject($schedule['project'])
                    ->trigger();
            });

            $dbForConsole->deleteDocument(
                'schedules',
                $schedule['$id'],
            );

            unset($this->schedules[$schedule['resourceId']]);
        }

        $queue->reclaim();
    }
}
