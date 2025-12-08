<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\StatsResources as EventStatsResources;
use Appwrite\Platform\Action;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Query;
use Utopia\System\System;

/**
 * Usage count
 *
 * Runs every hour, schedules project
 * for aggregating resource count
 */
class StatsResources extends Action
{
    /**
     * Log Error Callback
     *
     * @var callable
     */
    protected mixed $logError;

    /**
     * Console DB
     *
     * @var Database
     */
    protected Database $dbForPlatform;

    public static function getName()
    {
        return 'stats-resources';
    }

    public function __construct()
    {
        $this
            ->desc('Schedules projects for usage count')
            ->inject('dbForPlatform')
            ->inject('logError')
            ->inject('queueForStatsResources')
            ->callback($this->action(...));
    }

    public function action(Database $dbForPlatform, callable $logError, EventStatsResources $queue): void
    {
        $this->logError = $logError;
        $this->dbForPlatform = $dbForPlatform;

        $this->disableSubqueries();

        Console::title("Stats resources V1");

        Console::success('Stats resources: started');

        $interval = (int) System::getEnv('_APP_STATS_RESOURCES_INTERVAL', '3600');

        Console::loop(function () use ($queue, $dbForPlatform) {

            $last24Hours = (new \DateTime())->sub(\DateInterval::createFromDateString('24 hours'));
            /**
             * For each project that were accessed in last 24 hours
             */
            $this->foreachDocument($this->dbForPlatform, 'projects', [
                Query::greaterThanEqual('accessedAt', DateTime::format($last24Hours)),
                Query::equal('region', [System::getEnv('_APP_REGION', 'default')])
            ], function ($project) use ($queue) {
                $queue
                    ->setProject($project)
                    ->trigger();
                Console::success('project: ' . $project->getId() . '(' . $project->getSequence() . ')' . ' queued');
            });
        }, $interval);

        Console::log("Stats resources: exited");
    }
}
