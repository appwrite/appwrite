<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Messaging;
use Utopia\Database\Database;
use Utopia\Queue\Publisher;

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

    protected function enqueueResources(Publisher $publisher, Database $dbForPlatform, callable $getProjectDB): void
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

            \go(function () use ($schedule, $publisher, $dbForPlatform) {
                $this->updateProjectAccess($schedule['project'], $dbForPlatform);
                $queueForMessaging = new Messaging($publisher);
                $queueForMessaging
                    ->setType(MESSAGE_SEND_TYPE_EXTERNAL)
                    ->setMessageId($schedule['resourceId'])
                    ->setProject($schedule['project'])
                    ->trigger();

                $dbForPlatform->deleteDocument(
                    'schedules',
                    $schedule['$id'],
                );

                unset($this->schedules[$schedule['$internalId']]);
            });
        }
    }
}
