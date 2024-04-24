<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Hamster as EventHamster;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Http\Http;
use Utopia\Platform\Action;
use Utopia\System\System;

class Hamster extends Action
{
    public static function getName(): string
    {
        return 'hamster';
    }

    public function __construct()
    {
        $this
            ->desc('Get stats for projects')
            ->inject('queueForHamster')
            ->inject('dbForConsole')
            ->callback(function (EventHamster $queueForHamster, Database $dbForConsole) {
                $this->action($queueForHamster, $dbForConsole);
            });
    }

    public function action(EventHamster $queueForHamster, Database $dbForConsole): void
    {
        Console::title('Cloud Hamster V1');
        Console::success(APP_NAME . ' cloud hamster process has started');

        $sleep = (int) System::getEnv('_APP_HAMSTER_INTERVAL', '30'); // 30 seconds (by default)

        $jobInitTime = System::getEnv('_APP_HAMSTER_TIME', '22:00'); // (hour:minutes)

        $now = new \DateTime();
        $now->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        $next = new \DateTime($now->format("Y-m-d $jobInitTime"));
        $next->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        $delay = $next->getTimestamp() - $now->getTimestamp();
        /**
         * If time passed for the target day.
         */
        if ($delay <= 0) {
            $next->add(\DateInterval::createFromDateString('1 days'));
            $delay = $next->getTimestamp() - $now->getTimestamp();
        }

        Console::log('[' . $now->format("Y-m-d H:i:s.v") . '] Delaying for ' . $delay . ' setting loop to [' . $next->format("Y-m-d H:i:s.v") . ']');

        Console::loop(function () use ($queueForHamster, $dbForConsole, $sleep) {
            $now = date('d-m-Y H:i:s', time());
            Console::info("[{$now}] Queuing Cloud Usage Stats every {$sleep} seconds");
            $loopStart = microtime(true);

            Console::info('Queuing stats for all projects');
            $this->getStatsPerProject($queueForHamster, $dbForConsole, $loopStart);
            Console::success('Completed queuing stats for all projects');

            Console::info('Queuing stats for all organizations');
            $this->getStatsPerOrganization($queueForHamster, $dbForConsole, $loopStart);
            Console::success('Completed queuing stats for all organizations');

            Console::info('Queuing stats for all users');
            $this->getStatsPerUser($queueForHamster, $dbForConsole, $loopStart);
            Console::success('Completed queuing stats for all users');

            $loopTook = microtime(true) - $loopStart;
            $now = date('d-m-Y H:i:s', time());
            Console::info("[{$now}] Cloud Stats took {$loopTook} seconds");
        }, $sleep, $delay);
    }

    protected function calculateByGroup(string $collection, Database $database, callable $callback)
    {
        $count = 0;
        $chunk = 0;
        $limit = 50;
        $results = [];
        $sum = $limit;

        $executionStart = \microtime(true);

        while ($sum === $limit) {
            $chunk++;

            $results = $database->find($collection, \array_merge([
                Query::limit($limit),
                Query::offset($count)
            ]));

            $sum = count($results);

            Console::log('Processing chunk #' . $chunk . '. Found ' . $sum . ' documents');

            foreach ($results as $document) {
                call_user_func($callback, $database, $document);
                $count++;
            }
        }

        $executionEnd = \microtime(true);

        Console::log("Processed {$count} document by group in " . ($executionEnd - $executionStart) . " seconds");
    }

    protected function getStatsPerOrganization(EventHamster $hamster, Database $dbForConsole, float $loopStart)
    {
        $this->calculateByGroup('teams', $dbForConsole, function (Database $dbForConsole, Document $organization) use ($hamster, $loopStart) {
            try {
                $organization->setAttribute('$time', $loopStart);
                $hamster
                    ->setType(EventHamster::TYPE_ORGANISATION)
                    ->setOrganization($organization)
                    ->trigger();
            } catch (\Throwable $e) {
                Console::error($e->getMessage());
            }
        });
    }

    private function getStatsPerProject(EventHamster $hamster, Database $dbForConsole, float $loopStart)
    {
        $this->calculateByGroup('projects', $dbForConsole, function (Database $dbForConsole, Document $project) use ($hamster, $loopStart) {
            try {
                $project->setAttribute('$time', $loopStart);
                $hamster
                    ->setType(EventHamster::TYPE_PROJECT)
                    ->setProject($project)
                    ->trigger();
            } catch (\Throwable $e) {
                Console::error($e->getMessage());
            }
        });
    }

    protected function getStatsPerUser(EventHamster $hamster, Database $dbForConsole, float $loopStart)
    {
        $this->calculateByGroup('users', $dbForConsole, function (Database $dbForConsole, Document $user) use ($hamster, $loopStart) {
            try {
                $user->setAttribute('$time', $loopStart);
                $hamster
                    ->setType(EventHamster::TYPE_USER)
                    ->setUser($user)
                    ->trigger();
            } catch (\Throwable $e) {
                Console::error($e->getMessage());
            }
        });
    }
}
