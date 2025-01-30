<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\StatsResources as EventStatsResources;
use Appwrite\Platform\Action;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
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
            ->callback([$this, 'action']);
    }

    public function action(Database $dbForPlatform, callable $logError, EventStatsResources $queueForStatsResources): void
    {
        $this->logError = $logError;
        $this->dbForPlatform = $dbForPlatform;

        Console::title("Usage count V1");

        Console::success('Usage count: Started');

        $interval = (int) System::getEnv('_APP_USAGE_COUNT_INTERVAL', '3600');
        Console::loop(function () use ($queueForStatsResources) {
            $this->enqueueProjects($queueForStatsResources);
        }, $interval);

        Console::log("Usage count: Exited");
    }

    /**
     * Enqueue projects for counting
     * @param Database $dbForPlatform
     * @param EventStatsResources $queue
     * @return void
     */
    protected function enqueueProjects(EventStatsResources $queue): void
    {
        Authorization::disable();
        Authorization::setDefaultStatus(false);

        $last24Hours = (new \DateTime())->sub(\DateInterval::createFromDateString('24 hours'));
        /**
         * For each project that were accessed in last 24 hours
         */
        $this->foreachDocument($this->dbForPlatform, 'projects', [
            Query::greaterThanEqual('accessedAt', DateTime::format($last24Hours))
        ], function ($project) use ($queue) {
            $queue
                ->setProject($project)
                ->trigger();
            Console::success('project: ' . $project->getId() . '(' . $project->getInternalId() . ')' . ' queued');
        });
    }
}
