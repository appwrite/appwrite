<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Messaging;
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

    protected function enqueueResources(array $pools, callable $getConsoleDB): void
    {
        [$connection,$pool, $dbForConsole] = $getConsoleDB();
        $this->connections->add($connection, $pool);

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
                $pool = $pools['pools-queue-queue']['pool'];
                $dsn = $pools['pools-queue-queue']['dsn'];
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
                unset($this->schedules[$schedule['$internalId']]);
            });
        }
    }
}
