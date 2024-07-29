<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Func;
use Utopia\Database\Database;
use Utopia\Queue\Connection\Redis;

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

    protected function enqueueResources(array $pools, Database $dbForConsole): void
    {
        $pool = $pools['pools-queue-queue']['pool'];
        $connection = $pool->get();
        $this->connections->add($connection, $pool);

        $queueForFunctions = new Func(new Redis($connection));

        foreach ($this->schedules as $schedule) {
            if (!$schedule['active']) {
                $dbForConsole->deleteDocument(
                    'schedules',
                    $schedule['$id'],
                );

                unset($this->schedules[$schedule['resourceId']]);
                continue;
            }

            $now = new \DateTime();
            $scheduledAt = new \DateTime($schedule['schedule']);

            if ($scheduledAt > $now) {
                continue;
            }

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

            $dbForConsole->deleteDocument(
                'schedules',
                $schedule['$id'],
            );

            unset($this->schedules[$schedule['resourceId']]);
        }

        $this->connections->reclaim();
    }
}
