<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Delete;
use Swoole\Timer;
use Utopia\Platform\Action;
use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\Database\Query;
use Utopia\Database\Database;
use Utopia\Pools\Group;
use Appwrite\Event\Messaging;

use function Swoole\Coroutine\run;

class ScheduleMessage extends Action
{
    public const MESSAGE_UPDATE_TIMER = 10; //seconds
    public const MESSAGE_ENQUEUE_TIMER = 60; //seconds

    public static function getName(): string
    {
        return 'schedule-message';
    }

    public function __construct()
    {
        $this
            ->desc('Execute functions scheduled in Appwrite')
            ->inject('pools')
            ->inject('dbForConsole')
            ->inject('getProjectDB')
            ->callback(fn (Group $pools, Database $dbForConsole, callable $getProjectDB) => $this->action($pools, $dbForConsole, $getProjectDB));
    }

    /**
     * 1. Load all documents from 'schedules' collection to create local copy
     * 2. Create timer that sync all changes from 'schedules' collection to local copy. Only reading changes thanks to 'resourceUpdatedAt' attribute
     * 3. Create timer that prepares coroutines for soon-to-execute schedules. When it's ready, coroutime sleeps until exact time before sending request to worker.
     */
    public function action(Group $pools, Database $dbForConsole, callable $getProjectDB): void
    {
        Console::title('Scheduler V1');
        Console::success(APP_NAME . ' Scheduler v1 has started');

        $schedules = []; // Local copy of 'schedules' collection
        $lastSyncUpdate = DateTime::now();

        $limit = 10000;
        $sum = $limit;
        $total = 0;
        $loadStart = \microtime(true);
        $latestDocument = null;

        while ($sum === $limit) {
            $paginationQueries = [Query::limit($limit)];
            if ($latestDocument !== null) {
                $paginationQueries[] = Query::cursorAfter($latestDocument);
            }
            try{


            $results = $dbForConsole->find('schedules', \array_merge($paginationQueries, [
                Query::lessThanEqual('schedule', DateTime::formatTz(DateTime::now())),
                Query::equal('resourceType', ['message']),
                Query::equal('active', [true]),
            ]));
            } catch (\Exception $e) {
                var_dump($e->getTraceAsString());
            }

            $sum = count($results);
            $total = $total + $sum;
            foreach($results as $schedule) {
                $schedules[$schedule->getId()] = $schedule;
            }

            $latestDocument = !empty(array_key_last($results)) ? $results[array_key_last($results)] : null;
        }

        $pools->reclaim();

        Console::success("{$total} message were loaded in " . (microtime(true) - $loadStart) . " seconds");

        Console::success("Starting timers at " . DateTime::now());

        run(
            function () use ($dbForConsole, &$schedules, &$lastSyncUpdate, $pools) {
                /**
                 * The timer synchronize $schedules copy with database collection.
                 */
                Timer::tick(self::MESSAGE_UPDATE_TIMER * 1000, function () use ($dbForConsole, &$schedules, &$lastSyncUpdate, $pools) {
                    $time = DateTime::now();
                    $timerStart = \microtime(true);

                    $limit = 1000;
                    $sum = $limit;
                    $total = 0;
                    $latestDocument = null;

                    Console::log("Sync tick: Running at $time");

                    while ($sum === $limit) {
                        $paginationQueries = [Query::limit($limit)];
                        if ($latestDocument !== null) {
                            $paginationQueries[] =  Query::cursorAfter($latestDocument);
                        }
                        $results = $dbForConsole->find('schedules', \array_merge($paginationQueries, [
                            Query::lessThanEqual('schedule', DateTime::formatTz(DateTime::now())),
                            Query::equal('resourceType', ['message']),
                            Query::equal('active', [true]),
                        ]));
                        $sum = \count($results);
                        $total = $total + $sum;
                        foreach ($results as $schedule) {
                            $schedules[$schedule->getId()] = $schedule;
                        }

                        $latestDocument = !empty(array_key_last($results)) ? $results[array_key_last($results)] : null;
                    }

                    $lastSyncUpdate = $time;
                    $timerEnd = \microtime(true);

                    $pools->reclaim();

                    Console::log("Sync tick: {$total} schedules were updated in " . ($timerEnd - $timerStart) . " seconds");
                });

                /**
                 * The timer to prepare soon-to-execute schedules.
                 */
                $enqueueMessages = function () use (&$schedules, $pools, $dbForConsole) {
                    foreach ($schedules as $scheduleId => $schedule) {
                        \go(function () use ($schedules, $schedule, $pools, $dbForConsole) {
                            $queue = $pools->get('queue')->pop();
                            $connection = $queue->getResource();
                            $queueForMessaging = new Messaging($connection);
                            $queueForDeletes = new Delete($connection);
                            $project = $dbForConsole->getDocument('projects', $schedule->getAttribute('projectId'));
                            $queueForMessaging
                                ->setMessageId($schedule->getAttribute('resourceId'))
                                ->setProject($project)
                                ->trigger();
                            $schedule->setAttribute('active', false);
                            $dbForConsole->updateDocument('schedules', $schedule->getId(), $schedule);
                            
                            $queueForDeletes
                                ->setType(DELETE_TYPE_SCHEDULES)
                                ->setDocument($schedule);
                            
                            $queue->reclaim();
                            unset($schedules[$schedule->getId()]);
                        });
                    }
                };

                Timer::tick(self::MESSAGE_ENQUEUE_TIMER * 1000, fn () => $enqueueMessages());
                $enqueueMessages();
            }
        );
    }
}
