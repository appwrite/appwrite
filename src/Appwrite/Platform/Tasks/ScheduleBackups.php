<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Migration;
use Cron\CronExpression;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Migration\Destinations\Backup;
use Utopia\Migration\Resource;
use Utopia\Migration\Sources\Appwrite;
use Utopia\Pools\Group;

class ScheduleBackups extends ScheduleBase
{
    public const UPDATE_TIMER = 10; // seconds
    public const ENQUEUE_TIMER = 60; // seconds
    private ?float $lastEnqueueUpdate = null;

    public static function getName(): string
    {
        return 'schedule-backups';
    }

    public static function getSupportedResource(): string
    {
        return 'backup-policy';
    }

    protected function enqueueResources(Group $pools, Database $dbForConsole, callable $getProjectDB): void
    {
        $timerStart = \microtime(true);
        $time = DateTime::now();
        $enqueueDiff = $this->lastEnqueueUpdate === null ? 0 : $timerStart - $this->lastEnqueueUpdate;
        $timeFrame = DateTime::addSeconds(new \DateTime(), static::ENQUEUE_TIMER - $enqueueDiff);

        Console::log("Enqueue tick: started at: $time (with diff $enqueueDiff)");

        $total = 0;

        $delayedExecutions = []; // Group executions with same delay to share one coroutine

        foreach ($this->schedules as $key => $schedule) {
            $cron = new CronExpression($schedule['schedule']);
            $nextDate = $cron->getNextRunDate();
            $next = DateTime::format($nextDate);

            $currentTick = $next < $timeFrame;

            if (!$currentTick) {
                continue;
            }

            $total++;

            $promiseStart = \time(); // in seconds
            $executionStart = $nextDate->getTimestamp(); // in seconds
            $delay = $executionStart - $promiseStart; // Time to wait from now until execution needs to be queued

            if (!isset($delayedExecutions[$delay])) {
                $delayedExecutions[$delay] = [];
            }

            $delayedExecutions[$delay][] = $key;
        }

        foreach ($delayedExecutions as $delay => $scheduleKeys) {
            \go(/**
             * @throws \Utopia\Database\Exception
             */ function () use ($getProjectDB, $delay, $scheduleKeys, $pools) {
                \sleep($delay); // in seconds

                $queue = $pools->get('queue')->pop();
                $connection = $queue->getResource();

                foreach ($scheduleKeys as $scheduleKey) {
                    // Ensure schedule was not deleted
                    if (!\array_key_exists($scheduleKey, $this->schedules)) {
                        return;
                    }

                    $schedule = $this->schedules[$scheduleKey];

                    $resources = Appwrite::getSupportedResources();

                    if($schedule === BACKUP_RESOURCE_DATABASE) {
                        $resources = [
                            Resource::TYPE_DATABASE,
                            Resource::TYPE_COLLECTION,
                            Resource::TYPE_ATTRIBUTE,
                            Resource::TYPE_INDEX,
                            Resource::TYPE_DOCUMENT,
                        ];
                    }

                    $project = $schedule['project'];
                    $apiKey = $project['keys'][0]['secret'] ?? null;

                    if(empty($apiKey)){
                        Console::error('No api key was found for project: ' . $project->getId());
                        continue;
                    }

                    $policy = $schedule['resource'];
                    var_dump($schedule);
                    $dbForProject = $getProjectDB($project);
                    $migration = $dbForProject->createDocument('migrations', new Document([
                        '$id' => ID::unique(),
                        'status' => 'pending',
                        'stage'  => 'init',
                        'source' => Appwrite::getName(),
                        'destination' => Backup::getName(),
                        'credentials'   => [
                            'endpoint'  => 'http://localhost/v1',
                            'projectId' => $project->getId(),
                            'apiKey' => $apiKey,
                        ],
                        'resources' => $resources,
                        'statusCounters' => '{}',
                        'resourceData' => '{}',
                        'errors' => [],
                    ]));

                    $backup = $dbForProject->createDocument('backups', new Document([
                        'migrationId' => $migration->getId(),
                        'migrationInternalId' => $migration->getInternalId(),
                        'status' => 'pending',
                        'policyId' => $policy->getId(),
                        'policyInternalId' => $policy->getInternalId(),
                    ]));

                    (new Migration($connection))
                        ->setMigration($migration)
                        ->setProject($project)
                        ->setBackup($backup)
                        ->trigger();
                }

                $queue->reclaim();
            });
        }

        $timerEnd = \microtime(true);

        Console::log("Enqueue tick: {$total} executions were enqueued in " . ($timerEnd - $timerStart) . " seconds");
    }
}
