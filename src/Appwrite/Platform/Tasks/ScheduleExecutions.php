<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Func;
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
                ->setFunction($schedule['function'])
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

        $queue->reclaim();
    }
}
