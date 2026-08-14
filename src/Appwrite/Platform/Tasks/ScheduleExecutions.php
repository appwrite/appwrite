<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Swoole\Coroutine as Co;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;

/**
 * ScheduleExecutions
 *
 * Handles delayed executions by processing one-time scheduled tasks
 * that are executed at a specific future time.
 */
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
        return SCHEDULE_RESOURCE_TYPE_EXECUTION;
    }

    public static function getCollectionId(): string
    {
        return RESOURCE_TYPE_EXECUTIONS;
    }

    protected function loadResource(Document $project, callable $getProjectDB, array $schedule): Document
    {
        // Executions are not persisted; the schedule carries what the worker
        // needs. Schedules from before the executions collection was dropped
        // can still resolve their document for the functionId their data lacks.
        try {
            $resource = parent::loadResource($project, $getProjectDB, $schedule);
        } catch (\Throwable) {
            $resource = new Document();
        }

        return $resource->isEmpty()
            ? new Document(['$id' => $schedule['resourceId']])
            : $resource;
    }

    protected function enqueueResources(Database $dbForPlatform, callable $getProjectDB): void
    {
        $intervalEnd = (new \DateTime())->modify('+' . self::ENQUEUE_TIMER . ' seconds');

        $publisherForFunctions = new FunctionPublisher(
            $this->publisherFunctions,
            new \Utopia\Queue\Queue(\Utopia\System\System::getEnv('_APP_FUNCTIONS_QUEUE_NAME', \Appwrite\Event\Event::FUNCTIONS_QUEUE_NAME), 'utopia-queue', \Appwrite\Event\Event::FUNCTIONS_QUEUE_TTL)
        );

        foreach ($this->schedules as $schedule) {
            if (!$schedule['active']) {
                $dbForPlatform->deleteDocument(
                    'schedules',
                    $schedule['$id'],
                );

                unset($this->schedules[$schedule['$sequence']]);
                continue;
            }

            $scheduledAt = new \DateTime($schedule['schedule']);
            if ($scheduledAt > $intervalEnd) {
                continue;
            }

            $data = $dbForPlatform->getDocument(
                'schedules',
                $schedule['$id'],
            )->getAttribute('data', []);

            $functionId = $data['functionId'] ?? $schedule['resource']->getAttribute('resourceId', '');

            if (empty($functionId)) {
                Console::error("Missing functionId for scheduled execution {$schedule['resourceId']}, skipping");

                $dbForPlatform->deleteDocument(
                    'schedules',
                    $schedule['$id'],
                );

                unset($this->schedules[$schedule['$sequence']]);
                continue;
            }

            $delay = $scheduledAt->getTimestamp() - (new \DateTime())->getTimestamp();

            $this->updateProjectAccess($schedule['project'], $dbForPlatform);

            \go(function () use ($publisherForFunctions, $schedule, $scheduledAt, $delay, $data, $functionId, $dbForPlatform) {
                if ($delay > 0) {
                    Co::sleep($delay);
                }

                $publisherForFunctions->enqueue(new FunctionMessage(
                    project: $schedule['project'],
                    userId: $data['userId'] ?? '',
                    functionId: $functionId,
                    execution: $schedule['resource'],
                    type: 'schedule',
                    body: $data['body'] ?? '',
                    path: $data['path'] ?? '/',
                    headers: $data['headers'] ?? [],
                    method: $data['method'] ?? 'POST',
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
