<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Messaging;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Validator\Authorization;
use Utopia\Pools\Group;

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

            \go(function () use ($schedule, $pools, $dbForPlatform) {
                $queue = $pools->get('queue')->pop();
                $connection = $queue->getResource();
                $queueForMessaging = new Messaging($connection);

                $project = $schedule['project'];

                if (!$project->isEmpty() && $project->getId() !== 'console') {
                    $accessedAt = $project->getAttribute('accessedAt', '');
                    if (DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_PROJECT_ACCESS)) > $accessedAt) {
                        $project->setAttribute('accessedAt', DateTime::now());
                        Authorization::skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), $project));
                    }
                }

                $queueForMessaging
                    ->setType(MESSAGE_SEND_TYPE_EXTERNAL)
                    ->setMessageId($schedule['resourceId'])
                    ->setProject($project)
                    ->trigger();

                $dbForPlatform->deleteDocument(
                    'schedules',
                    $schedule['$id'],
                );

                $queue->reclaim();

                unset($this->schedules[$schedule['$internalId']]);
            });
        }
    }
}
