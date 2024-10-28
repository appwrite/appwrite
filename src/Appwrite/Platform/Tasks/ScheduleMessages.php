<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Messaging;
use Utopia\Database\Database;
use Utopia\Pools\Group;
use Utopia\Pools\Pool;

class ScheduleMessages extends ScheduleBase
{
    public const UPDATE_TIMER = 3; // seconds
    public const ENQUEUE_TIMER = 4; // seconds

    public static function getName(): string
    {
        return 'schedule-messages';
    }

    public static function getSupportedResource(): string
    {
        return 'message';
    }

    public static function getCollectionId(): string
    {
        return 'messages';
    }

    protected function enqueueResources(Pool $poolForQueue, Database $dbForConsole, callable $getProjectDB): void
    {
        foreach ($this->schedules as $schedule) {
            if (!$schedule['active']) {
                continue;
            }

            $now = new \DateTime();
            $scheduledAt = new \DateTime($schedule['schedule']);

            if ($scheduledAt > $now) {
                continue;
            }

            \go(function () use ($schedule, $poolForQueue, $dbForConsole) {
                $queue = $poolForQueue->pop();
                $connection = $queue->getResource();
                $queueForMessaging = new Messaging($connection);

                $queueForMessaging
                    ->setType(MESSAGE_SEND_TYPE_EXTERNAL)
                    ->setMessageId($schedule['resourceId'])
                    ->setProject($schedule['project'])
                    ->trigger();

                $dbForConsole->deleteDocument(
                    'schedules',
                    $schedule['$id'],
                );

                $queue->reclaim();

                unset($this->schedules[$schedule['$internalId']]);
            });
        }
    }
}
