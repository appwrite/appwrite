<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Func;
use Swoole\Coroutine as Co;
use Utopia\Database\Database;
use Utopia\Telemetry\Adapter as Telemetry;

class ScheduleExecutions extends ScheduleBase
{
    public const UPDATE_TIMER = 3; // seconds
    public const ENQUEUE_TIMER = 4; // seconds

    protected Func $queueForFunctions;

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

    public function __construct()
    {
        $this
            ->desc('Execute executions scheduled in Appwrite')
            ->inject('queueForFunctions')
            ->inject('dbForPlatform')
            ->inject('getProjectDB')
            ->inject('telemetry')
            ->callback($this->action(...));
    }

    public function action(Func $queueForFunctions, Database $dbForPlatform, callable $getProjectDB, Telemetry $telemetry): void
    {
        $this->queueForFunctions = $queueForFunctions;
        $this->schedule($dbForPlatform, $getProjectDB, $telemetry);
    }

    protected function enqueueResources(Database $dbForPlatform, callable $getProjectDB): void
    {
        $intervalEnd = (new \DateTime())->modify('+' . self::ENQUEUE_TIMER . ' seconds');

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
            if ($scheduledAt <= $intervalEnd) {
                continue;
            }

            $data = $dbForPlatform->getDocument(
                'schedules',
                $schedule['$id'],
            )->getAttribute('data', []);

            $delay = $scheduledAt->getTimestamp() - (new \DateTime())->getTimestamp();

            $this->updateProjectAccess($schedule['project'], $dbForPlatform);

            \go(function () use ($schedule, $scheduledAt, $delay, $data) {
                $queueForFunctions = clone $this->queueForFunctions;
                Co::sleep($delay);

                $queueForFunctions->setType('schedule')
                    // Set functionId instead of function as we don't have $dbForProject
                    // TODO: Refactor to use function instead of functionId
                    ->setFunctionId($schedule['resource']['resourceId'])
                    ->setExecution($schedule['resource'])
                    ->setMethod($data['method'] ?? 'POST')
                    ->setPath($data['path'] ?? '/')
                    ->setHeaders($data['headers'] ?? [])
                    ->setBody($data['body'] ?? '')
                    ->setProject($schedule['project'])
                    ->setUserId($data['userId'] ?? '')
                    ->trigger();

                $this->recordEnqueueDelay($scheduledAt);
            });

            $dbForPlatform->deleteDocument(
                'schedules',
                $schedule['$id'],
            );

            unset($this->schedules[$schedule['$sequence']]);
        }
    }
}
