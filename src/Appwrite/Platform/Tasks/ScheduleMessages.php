<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Delete;
use Swoole\Timer;
use Utopia\Platform\Action;
use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\Database\Query;
use Utopia\Database\Database;
use Utopia\Pools\Group;
use Appwrite\Event\Messaging;

use function Swoole\Coroutine\run;

class ScheduleMessages extends ScheduleBase
{
    public const UPDATE_TIMER = 3; // seconds
    public const ENQUEUE_TIMER = 60; // seconds

    public static function getName(): string
    {
        return 'schedule-messages';
    }

    public static function getSupportedResource(): string
    {
        return 'message';
    }

    protected function enqueueResources(Group $pools, Database $dbForConsole): void
    {
        foreach ($this->schedules as $schedule) {
            \go(function () use ($schedule, $pools, $dbForConsole) {
                $queue = $pools->get('queue')->pop();
                $connection = $queue->getResource();
                $queueForMessaging = new Messaging($connection);
                $queueForDeletes = new Delete($connection);

                $queueForMessaging
                    ->setMessageId($schedule['resourceId'])
                    ->setProject($schedule['project'])
                    ->trigger();

                $queueForDeletes
                    ->setType(DELETE_TYPE_SCHEDULES)
                    ->setDocument($schedule)
                    ->trigger();

                $queue->reclaim();

                unset($this->schedules[$schedule->getId()]);
            });
        }
    }
}
