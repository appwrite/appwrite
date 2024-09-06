<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Func;
use Swoole\Coroutine as Co;
use Utopia\Queue\Connection\Redis;

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

    protected function enqueueResources(array $pools, callable $getConsoleDB): void
    {
        [$connection,$pool, $dbForConsole] = $getConsoleDB();
        $this->connections->add($connection, $pool);

        $pool = $pools['pools-queue-queue']['pool'];
        $connection = $pool->get();
        $this->connections->add($connection, $pool);

        $queueForFunctions = new Func(new Redis($connection));
        $intervalEnd = (new \DateTime())->modify('+' . self::ENQUEUE_TIMER . ' seconds');

        foreach ($this->schedules as $schedule) {
            if (!$schedule['active']) {
                $dbForConsole->deleteDocument(
                    'schedules',
                    $schedule['$id'],
                );

                unset($this->schedules[$schedule['resourceId']]);
                continue;
            }

            $scheduledAt = new \DateTime($schedule['schedule']);
            if ($scheduledAt <= $intervalEnd) {
                continue;
            }

            $delay = $scheduledAt->getTimestamp() - (new \DateTime())->getTimestamp();


            \go(function () use ($queueForFunctions, $schedule, $delay, $dbForConsole) {
                Co::sleep($delay);

                $queueForFunctions
                    ->setType('schedule')
                    // Set functionId instead of function as we don't have $dbForProject
                    // TODO: Refactor to use function instead of functionId
                    ->setFunctionId($schedule['resource']['functionId'])
                    ->setExecution($schedule['resource'])
                    ->setMethod($schedule['data']['method'] ?? 'POST')
                    ->setPath($schedule['data']['path'] ?? '/')
                    ->setHeaders($schedule['data']['headers'] ?? [])
                    ->setBody($schedule['data']['body'] ?? '')
                    ->setProject($schedule['project'])
                    ->trigger();

                $dbForConsole->deleteDocument(
                    'schedules',
                    $schedule['$id'],
                );
            });

            unset($this->schedules[$schedule['resourceId']]);
        }

        $this->connections->reclaim();
    }
}
