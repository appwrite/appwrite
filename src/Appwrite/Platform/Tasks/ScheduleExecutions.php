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
                ->setMethod($schedule['metadata']['method'] ?? 'POST')
                ->setPath($schedule['metadata']['path'] ?? '/')
                ->setHeaders($schedule['metadata']['headers'] ?? [])
                ->setBody($schedule['metadata']['body'] ?? '')
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
