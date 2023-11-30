<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Hamster as EventHamster;
use Appwrite\Network\Validator\Origin;
use Exception;
use Utopia\App;
use Utopia\Platform\Action;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Analytics\Adapter\Mixpanel;
use Utopia\Analytics\Event;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Pools\Group;

class Hamster extends Action
{
    protected string $directory = '/usr/local';

    protected string $path;

    protected string $date;

    protected Mixpanel $mixpanel;

    public static function getName(): string
    {
        return 'hamster';
    }

    public function __construct()
    {
        $this->mixpanel = new Mixpanel(App::getEnv('_APP_MIXPANEL_TOKEN', ''));

        $this
            ->desc('Get stats for projects')
            ->inject('pools')
            ->inject('cache')
            ->inject('dbForConsole')
            ->callback(function (Group $pools, Cache $cache, Database $dbForConsole) {
                $this->action($pools, $cache, $dbForConsole);
            });
    }

    private function getStatsPerProject(Group $pools, Database $dbForConsole)
    {
        $this->calculateByGroup('projects', $dbForConsole, function (Database $dbForConsole, Document $project) use ($pools) {
            $queue = $pools->get('queue')->pop();
            $connection = $queue->getResource();

            $hamsterTask = new EventHamster($connection);

            $hamsterTask
                ->setType('project')
                ->setProject($project)
                ->trigger();

            $queue->reclaim();
        });
    }

    public function action(Group $pools, Cache $cache, Database $dbForConsole): void
    {

        Console::title('Cloud Hamster V1');
        Console::success(APP_NAME . ' cloud hamster process has started');

        $sleep = (int) App::getEnv('_APP_HAMSTER_INTERVAL', '30'); // 30 seconds (by default)

        $jobInitTime = App::getEnv('_APP_HAMSTER_TIME', '22:00'); // (hour:minutes)
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

        Console::loop(function () use ($pools, $cache, $dbForConsole, $sleep) {
            $now = date('d-m-Y H:i:s', time());
            Console::info("[{$now}] Getting Cloud Usage Stats every {$sleep} seconds");
            $loopStart = microtime(true);

            /* Initialise new Utopia app */
            $app = new App('UTC');

            Console::info('Queuing stats for all projects');
            $this->getStatsPerProject($pools, $dbForConsole);
            Console::success('Completed queuing stats for all projects');

            Console::info('Queuing stats for all organizations');
            $this->getStatsPerOrganization($pools, $dbForConsole);
            Console::success('Completed queuing stats for all organizations');

            Console::info('Queuing stats for all users');
            $this->getStatsPerUser($pools, $dbForConsole);
            Console::success('Completed queuing stats for all users');

            $pools
                ->get('console')
                ->reclaim();

            $loopTook = microtime(true) - $loopStart;
            $now = date('d-m-Y H:i:s', time());
            Console::info("[{$now}] Cloud Stats took {$loopTook} seconds");
        }, $sleep, $delay);
    }

    protected function calculateByGroup(string $collection, Database $dbForConsole, callable $callback)
    {
        $count = 0;
        $chunk = 0;
        $limit = 50;
        $results = [];
        $sum = $limit;

        $executionStart = \microtime(true);

        while ($sum === $limit) {
            $chunk++;

            $results = $dbForConsole->find($collection, \array_merge([
                Query::limit($limit),
                Query::offset($count)
            ]));

            $sum = count($results);

            Console::log('Processing chunk #' . $chunk . '. Found ' . $sum . ' documents');

            foreach ($results as $document) {
                call_user_func($callback, $dbForConsole, $document);
                $count++;
            }
        }

        $executionEnd = \microtime(true);

        Console::log("Processed {$count} document by group in " . ($executionEnd - $executionStart) . " seconds");
    }

    protected function getStatsPerOrganization(Group $pools, Database $dbForConsole)
    {

        $this->calculateByGroup('teams', $dbForConsole, function (Database $dbForConsole, Document $organization) use ($pools) {
            try {
                $queue = $pools->get('queue')->pop();
                $connection = $queue->getResource();
    
                $hamsterTask = new EventHamster($connection);
    
                $hamsterTask
                    ->setType('organization')
                    ->setOrganization($organization)
                    ->trigger();
    
                $queue->reclaim();
            } catch (Exception $e) {
                Console::error($e->getMessage());
            }
        });
    }

    protected function getStatsPerUser(Group $pools, Database $dbForConsole)
    {
        $this->calculateByGroup('users', $dbForConsole, function (Database $dbForConsole, Document $user) use ($pools) {
            try {
                $queue = $pools->get('queue')->pop();
                $connection = $queue->getResource();
    
                $hamsterTask = new EventHamster($connection);
    
                $hamsterTask
                    ->setType('user')
                    ->setUser($user)
                    ->trigger();
    
                $queue->reclaim();
            } catch (Exception $e) {
                Console::error($e->getMessage());
            }
        });
    }
}
