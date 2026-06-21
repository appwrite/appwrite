<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Messaging as MessagingMessage;
use Appwrite\Event\Publisher\Messaging as MessagingPublisher;
use Utopia\Database\Database;
use Utopia\Queue\Queue;
use Utopia\System\System;

class ScheduleMessages extends ScheduleBase
{
    public const UPDATE_TIMER = 3; // seconds
    public const ENQUEUE_TIMER = 4; // seconds

    private ?MessagingPublisher $publisherForMessaging = null;

    public static function getName(): string
    {
        return 'schedule-messages';
    }

    public static function getSupportedResource(): string
    {
        return SCHEDULE_RESOURCE_TYPE_MESSAGE;
    }

    public static function getCollectionId(): string
    {
        return RESOURCE_TYPE_MESSAGES;
    }

    protected function enqueueResources(Database $dbForPlatform, callable $getProjectDB): void
    {
        $publisherForMessaging = $this->publisherForMessaging ??= new MessagingPublisher(
            $this->publisherMessaging,
            new Queue(System::getEnv('_APP_MESSAGING_QUEUE_NAME', Event::MESSAGING_QUEUE_NAME))
        );

        foreach ($this->schedules as $schedule) {
            if (!$schedule['active']) {
                continue;
            }

            $now = new \DateTime();
            $scheduledAt = new \DateTime($schedule['schedule']);

            if ($scheduledAt > $now) {
                continue;
            }

            \go(function () use ($schedule, $scheduledAt, $dbForPlatform, $publisherForMessaging) {
                $this->updateProjectAccess($schedule['project'], $dbForPlatform);

                $publisherForMessaging->enqueue(new MessagingMessage(
                    type: MESSAGE_SEND_TYPE_EXTERNAL,
                    project: $schedule['project'],
                    messageId: $schedule['resourceId'],
                ));

                $dbForPlatform->deleteDocument(
                    'schedules',
                    $schedule['$id'],
                );

                $this->recordEnqueueDelay($scheduledAt);
                unset($this->schedules[$schedule['$sequence']]);
            });
        }
    }
}
