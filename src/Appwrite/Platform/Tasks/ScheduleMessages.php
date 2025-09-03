<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Messaging;
use Utopia\Database\Database;
use Utopia\Telemetry\Adapter as Telemetry;

class ScheduleMessages extends ScheduleBase
{
    public const UPDATE_TIMER = 3; // seconds
    public const ENQUEUE_TIMER = 4; // seconds

    protected Messaging $queueForMessaging;

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

    public function __construct()
    {
        $this
            ->desc('Execute messages scheduled in Appwrite')
            ->inject('queueForMessaging')
            ->inject('dbForPlatform')
            ->inject('getProjectDB')
            ->inject('telemetry')
            ->callback($this->action(...));
    }

    public function action(Messaging $queueForMessaging, Database $dbForPlatform, callable $getProjectDB, Telemetry $telemetry): void
    {
        $this->queueForMessaging = $queueForMessaging;
        $this->schedule($dbForPlatform, $getProjectDB, $telemetry);
    }

    protected function enqueueResources(Database $dbForPlatform, callable $getProjectDB): void
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

            \go(function () use ($schedule, $scheduledAt, $dbForPlatform) {
                $this->updateProjectAccess($schedule['project'], $dbForPlatform);

                $queueForMessaging = clone $this->queueForMessaging;
                $queueForMessaging
                    ->setType(MESSAGE_SEND_TYPE_EXTERNAL)
                    ->setMessageId($schedule['resourceId'])
                    ->setProject($schedule['project'])
                    ->trigger();

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
