<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Delete;
use Utopia\Database\Database;
use Utopia\Pools\Group;

class ScheduleProjects extends ScheduleBase
{
    public const UPDATE_TIMER = 3; // seconds
    public const ENQUEUE_TIMER = 4; // seconds

    public static function getName(): string
    {
        return 'schedule-projects';
    }

    public static function getSupportedResource(): string
    {
        return 'project';
    }

    public static function getCollectionId(): string
    {
        return 'projects';
    }

    protected function enqueueResources(Group $pools, Database $dbForPlatform, callable $getProjectDB): void
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

                (new Delete($this->publisher))
                    ->setProject($schedule['project'])
                    ->setType(DELETE_TYPE_DOCUMENT)
                    ->setDocument($schedule['project'])
                    ->trigger();

                $dbForPlatform->deleteDocument('projects', $schedule['project']->getId());
                $dbForPlatform->deleteDocument('schedules', $schedule['$id']);

                $this->recordEnqueueDelay($scheduledAt);
                unset($this->schedules[$schedule['$internalId']]);
            });
        }
    }
}
