<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Usage;
use Appwrite\Platform\Action;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\System\System;

const INFINITY_PERIOD = '_inf_';

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

    /**
     * Queue for usage
     *
     * @var Usage
     */
    protected Usage $queue;

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
            ->inject('queueForUsage')
            ->callback(fn ($dbForPlatform, $logError, $queueForUsage) => $this->action($dbForPlatform, $logError, $queueForUsage));
    }

    public function action(Database $dbForPlatform, callable $logError, Usage $queueForUsage): void
    {
        $this->logError = $logError;
        $this->dbForPlatform = $dbForPlatform;
        $this->queue = $queueForUsage;

        Console::title("Usage count V1");

        Console::success('Usage count: Started');

        $interval = (int) System::getEnv('_APP_USAGE_COUNT_INTERVAL', '3600');
        Console::loop(function () use ($queueForUsage) {
            $this->enqueueProjects($queueForUsage);
        }, $interval);

        Console::log("Usage count: Exited");
    }

    /**
     * Enqueue projects for counting
     * @param Database $dbForPlatform
     * @param Usage $queue
     * @return void
     */
    protected function enqueueProjects(Usage $queue): void
    {
        Authorization::disable();
        Authorization::setDefaultStatus(false);

        $last24Hours = (new \DateTime())->sub(\DateInterval::createFromDateString('24 hours'));
        // Foreach Team
        $this->foreachDocument($this->dbForPlatform, 'projects', [
            Query::greaterThanEqual('accessedAt', DateTime::format($last24Hours))
        ], function ($project) use ($queue) {
            $queue
                ->setProject($project)
                ->setType(Usage::TYPE_USAGE_COUNT)
                ->trigger();
            Console::success('project: ' . $project->getId() . '(' . $project->getInternalId() . ')' . ' queued');
        });
    }
}
