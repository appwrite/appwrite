<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Func;
use Swoole\Coroutine as Co;
use Utopia\Database\Database;
use Utopia\Pools\Group;
use Utopia\System\System;

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
        $isRedisFallback = \str_contains(System::getEnv('_APP_WORKER_REDIS_FALLBACK', ''), 'functions');

        $queueForFunctions = new Func(
            $isRedisFallback
            ? $this->publisherRedis
            : $this->publisher
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

            $data = $dbForPlatform->getDocument(
                'schedules',
                $schedule['$id'],
            )->getAttribute('data', []);

            $delay = floor($scheduledAt->getTimestamp() - (new \DateTime())->getTimestamp());
            $delay = max($delay, 0);

            $this->updateProjectAccess($schedule['project'], $dbForPlatform);

            \go(function () use ($queueForFunctions, $schedule, $scheduledAt, $delay, $data, $dbForPlatform) {
                Co::sleep($delay);

                $executedAt = new \DateTime();
                $executionDelay = $executedAt->getTimestamp() - $scheduledAt->getTimestamp();

                $headers = $data['headers'] ?? [];
                $headers['x-appwrite-schedule-delay'] = (string)$executionDelay;
                $headers['x-appwrite-scheduled-at'] = $scheduledAt->format('Y-m-d\TH:i:s.v\Z');
                $headers['x-appwrite-executed-at'] = $executedAt->format('Y-m-d\TH:i:s.v\Z');

                $result = $queueForFunctions->setType('schedule')
                    // Set functionId instead of function as we don't have $dbForProject
                    // TODO: Refactor to use function instead of functionId
                    ->setFunctionId($schedule['resource']['resourceId'])
                    ->setExecution($schedule['resource'])
                    ->setMethod($data['method'] ?? 'POST')
                    ->setPath($data['path'] ?? '/')
                    ->setHeaders($headers)
                    ->setBody($data['body'] ?? '')
                    ->setProject($schedule['project'])
                    ->setUserId($data['userId'] ?? '')
                    ->trigger();

                $this->recordEnqueueDelay($scheduledAt);

                //Only delete schedule if it was successfully enqueued
                if ($result) {
                    $dbForPlatform->deleteDocument(
                        'schedules',
                        $schedule['$id'],
                    );
                }
            });

            unset($this->schedules[$schedule['$sequence']]);
        }
    }
}
