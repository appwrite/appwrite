<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\UsageInfinity;
use Exception;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Action;

class InfinityUsageScheduler extends Action
{
    public static function getName(): string
    {
        return 'infinity-usage-scheduler';
    }

    public function __construct()
    {
        $this
            ->desc('Get infinity stats for projects')
            ->inject('dbForConsole')
            ->inject('queueForUsageInfinity')
            ->callback(fn (Database $dbForConsole, UsageInfinity $queueForUsageInfinity) => $this->action($dbForConsole, $queueForUsageInfinity));

    }

    /**
     * @param Database $dbForConsole
     * @throws Exception
     */
    public function action(Database $dbForConsole, UsageInfinity $queueForUsageInfinity): void
    {
        Console::title('Infinity stats scheduler V1');
        Console::success(APP_NAME . ' Infinity stats scheduler process has started');

        $sleep = (int)App::getEnv('_APP_USAGE_INF_INTERVAL', '30'); // 30 seconds (by default)

        $jobInitTime = App::getEnv('_APP_USAGE_INF_TIME', '24:00'); // (hour:minutes)

        $now = new \DateTime();
        $now->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        $next = new \DateTime($now->format("Y-m-d $jobInitTime"));
        $next->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        $delay = $next->getTimestamp() - $now->getTimestamp();

        if ($delay <= 0) {
            $next->add(\DateInterval::createFromDateString('1 days'));
            $delay = $next->getTimestamp() - $now->getTimestamp();
        }

        Console::log('[' . $now->format("Y-m-d H:i:s.v") . '] Delaying for ' . $delay . ' setting loop to [' . $next->format("Y-m-d H:i:s.v") . ']');

        $sleep = 30;
        $delay = 0;

        Console::loop(function () use ($dbForConsole, $queueForUsageInfinity, $sleep) {
            $now = date('d-m-Y H:i:s', time());
            Console::info("[{$now}] Queuing Cloud Usage Stats every {$sleep} seconds");
            $loopStart = microtime(true);

            $count = 0;
            $chunk = 0;
            $limit = 50;
            $results = [];
            $sum = $limit;

            $executionStart = \microtime(true);

            while ($sum === $limit) {
                $chunk++;

                $results = $dbForConsole->find('projects', \array_merge([
                    Query::limit($limit),
                    Query::offset($count)
                ]));

                $sum = count($results);

                Console::log('Processing chunk #' . $chunk . '. Found ' . $sum . ' documents');

                foreach ($results as $document) {

                    $queueForUsageInfinity->setProject($document);
                    var_dump('Pushing project '.$document->getInternalId());
                    $count++;
                }
            }

            $executionEnd = \microtime(true);

            Console::log("Processed {$count} document by group in " . ($executionEnd - $executionStart) . " seconds");


            $loopTook = microtime(true) - $loopStart;
            $now = date('d-m-Y H:i:s', time());
            Console::info("[{$now}] usage Stats took {$loopTook} seconds");
        }, $sleep, $delay);
    }
}



