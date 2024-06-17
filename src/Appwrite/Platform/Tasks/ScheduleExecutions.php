<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Func;
use Cron\CronExpression;
use Utopia\CLI\Console;
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
        foreach ($this->schedules as $schedule) {
            if (!$schedule['active'] || CronExpression::isValidExpression($schedule['schedule'])) {
                unset($this->schedules[$schedule['resourceId']]);
                continue;
            }

            $now = new \DateTime();
            $scheduledAt = new \DateTime($schedule['schedule']);

            if ($scheduledAt > $now) {
                continue;
            }

            \go(function () use ($schedule, $pools, $dbForConsole) {
                $queue = $pools->get('queue')->pop();
                $connection = $queue->getResource();
                
                $queueForFunctions = new Func($connection);
                
                $queueForFunctions
                    ->setType('schedule')
                    ->setFunctionId($schedule['resource']['functionId'])
                    ->setExecution($schedule['resource'])
                    ->setMethod('POST')
                    ->setPath('/')
                    ->setProject($schedule['project'])
                    ->trigger();

                $dbForConsole->deleteDocument(
                    'schedules',
                    $schedule['$id'],
                );

                $queue->reclaim();

                unset($this->schedules[$schedule['resourceId']]);
            });
        }
    }
}
