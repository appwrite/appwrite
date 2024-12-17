<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Func;
use Swoole\Coroutine as Co;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Validator\Authorization;
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

    public static function getCollectionId(): string
    {
        return 'executions';
    }

    protected function enqueueResources(Group $pools, Database $dbForPlatform, callable $getProjectDB): void
    {
        $queue = $pools->get('queue')->pop();
        $connection = $queue->getResource();
        $queueForFunctions = new Func($connection);
        $intervalEnd = (new \DateTime())->modify('+' . self::ENQUEUE_TIMER . ' seconds');

        foreach ($this->schedules as $schedule) {
            if (!$schedule['active']) {
                $dbForPlatform->deleteDocument(
                    'schedules',
                    $schedule['$id'],
                );

                unset($this->schedules[$schedule['$internalId']]);
                continue;
            }

            $scheduledAt = new \DateTime($schedule['schedule']);
            if ($scheduledAt <= $intervalEnd) {
                continue;
            }

            $data = $dbForPlatform->getDocument(
                'schedules',
                $schedule['$id'],
            )->getAttribute('data', []);

            $delay = $scheduledAt->getTimestamp() - (new \DateTime())->getTimestamp();

            $project = $schedule['project'];

            if (!$project->isEmpty() && $project->getId() !== 'console') {
                $accessedAt = $project->getAttribute('accessedAt', '');
                if (DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_PROJECT_ACCESS)) > $accessedAt) {
                    $project->setAttribute('accessedAt', DateTime::now());
                    Authorization::skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), $project));
                }
            }

            \go(function () use ($queueForFunctions, $schedule, $delay, $data) {
                Co::sleep($delay);

                $queueForFunctions->setType('schedule')
                    // Set functionId instead of function as we don't have $dbForProject
                    // TODO: Refactor to use function instead of functionId
                    ->setFunctionId($schedule['resource']['functionId'])
                    ->setExecution($schedule['resource'])
                    ->setMethod($data['method'] ?? 'POST')
                    ->setPath($data['path'] ?? '/')
                    ->setHeaders($data['headers'] ?? [])
                    ->setBody($data['body'] ?? '')
                    ->setProject($schedule['project'])
                    ->setUserId($data['userId'] ?? '')
                    ->trigger();
            });

            $dbForPlatform->deleteDocument(
                'schedules',
                $schedule['$id'],
            );

            unset($this->schedules[$schedule['$internalId']]);
        }

        $queue->reclaim();
    }
}
