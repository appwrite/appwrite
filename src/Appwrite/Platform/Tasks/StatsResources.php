<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Message\StatsResources as StatsResourcesMessage;
use Appwrite\Event\Publisher\StatsResources as StatsResourcesPublisher;
use Appwrite\Platform\Action;
use Appwrite\Usage\Concurrency;
use Appwrite\Usage\Connection;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Query;
use Utopia\System\System;

class StatsResources extends Action
{
    private Concurrency $concurrency;

    public static function getName(): string
    {
        return 'stats-resources';
    }

    public function __construct()
    {
        $this->concurrency = new Concurrency();

        $this
            ->desc('Schedule active projects for usage resource counts')
            ->inject('dbForPlatform')
            ->inject('publisherForStatsResources')
            ->inject('usageConnection')
            ->callback($this->action(...));
    }

    public function action(Database $dbForPlatform, StatsResourcesPublisher $publisherForStatsResources, Connection $usageConnection): void
    {
        if (!$usageConnection->isEnabled()) {
            Console::info('Usage statistics are disabled');
            return;
        }

        $this->disableSubqueries();
        $interval = max(60, (int) System::getEnv('_APP_STATS_RESOURCES_INTERVAL', 3600));

        Console::loop(function () use ($dbForPlatform, $publisherForStatsResources, $usageConnection): void {
            if (!$usageConnection->isReady()) {
                throw new \RuntimeException('Usage schema is not ready');
            }
            $this->concurrency->sample($usageConnection->getUsage());

            $last24Hours = (new \DateTime())->sub(new \DateInterval('P1D'));
            $this->foreachDocument($dbForPlatform, 'projects', [
                Query::greaterThanEqual('accessedAt', DateTime::format($last24Hours)),
                Query::equal('region', [System::getEnv('_APP_REGION', 'default')]),
            ], function ($project) use ($publisherForStatsResources): void {
                $publisherForStatsResources->enqueue(new StatsResourcesMessage(project: $project));
            });
        }, $interval);
    }
}
