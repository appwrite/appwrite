<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Messaging;
use Utopia\Database\Database;
use Utopia\Queue\Connection\Redis;

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

    protected function enqueueResources(array $pools, Database $dbForConsole): void
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

            \go(function () use ($now, $schedule, $pools, $dbForConsole) {
                $pool = $pools['pools-queue-main']['pool'];
                $dsn = $pools['pools-queue-main']['dsn'];
                $connection = $pool->get();
                $this->connections->add($connection, $pool);

                $queueConnection = new Redis($dsn->getHost(), $dsn->getPort());

                $queueForMessaging = new Messaging($queueConnection);

                $queueForMessaging
                    ->setType(MESSAGE_SEND_TYPE_EXTERNAL)
                    ->setMessageId($schedule['resourceId'])
                    ->setProject($schedule['project'])
                    ->trigger();

                $dbForConsole->deleteDocument(
                    'schedules',
                    $schedule['$id'],
                );

                $this->connections->reclaim();
                // $queue->reclaim(); // TODO: Do in try/catch/finally, or add to connectons resource

                unset($this->schedules[$schedule['resourceId']]);
            });
        }
    }
}
