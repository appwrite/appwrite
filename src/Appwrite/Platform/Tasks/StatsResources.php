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
use Utopia\Span\Span;
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
        // Floor of 1 guards against a zero/negative interval spinning the loop;
        // test stacks legitimately run short intervals (Cloud CI uses 2s).
        $interval = max(1, (int) System::getEnv('_APP_STATS_RESOURCES_INTERVAL', 3600));

        Console::loop(function () use ($dbForPlatform, $publisherForStatsResources, $usageConnection): void {
            // A healthy cycle used to print nothing at all, so "is the scheduler
            // still seeing this region's projects?" had no answer -- the only way
            // to tell a stalled scheduler from a stalled worker was to guess. One
            // span per cycle carrying the enqueue count makes that queryable.
            Span::init('stats_resources_task');
            $projectsQueued = 0;

            // Nothing here may end the loop. An exception escaping this closure
            // ends Console::loop, and the process then stays alive and idle: it
            // never exits, so restartPolicy never fires, and with no liveness
            // probe nothing observes that scheduling has stopped. A single
            // transient ClickHouse timeout on one tick silently ended gauge
            // collection for a whole region, and the task went on reporting
            // Ready for weeks while queueing nothing.
            try {
                if (!$usageConnection->isReady()) {
                    Span::add('stats_resources_task.skipped', 'usage_schema_not_ready');
                    Console::error('stats resources: usage schema is not ready, skipping cycle');
                    return;
                }

                // Concurrency sampling reads the usage store; project scheduling
                // reads the platform DB. They share no data, so a failure in the
                // first must not cost the second -- that coupling is what turned
                // one bad read into no scheduling at all.
                try {
                    $this->concurrency->sample($usageConnection->getUsage());
                } catch (\Throwable $th) {
                    Console::error('stats resources: concurrency sample failed, continuing: ' . $th->getMessage());
                }

                $last24Hours = (new \DateTime())->sub(new \DateInterval('P1D'));
                $this->foreachDocument($dbForPlatform, 'projects', [
                    Query::greaterThanEqual('accessedAt', DateTime::format($last24Hours)),
                    Query::equal('region', [System::getEnv('_APP_REGION', 'default')]),
                    Query::orderAsc('$sequence'), // accessedAt Can be updated during iteration
                ], function ($project) use ($publisherForStatsResources, &$projectsQueued): void {
                    $publisherForStatsResources->enqueue(new StatsResourcesMessage(project: $project));
                    $projectsQueued++;
                });
            } catch (\Throwable $th) {
                // Cost a cycle, not the process: the next tick retries in full.
                Span::add('stats_resources_task.error', $th->getMessage());
                Console::error('stats resources: cycle failed, retrying next interval: ' . $th->getMessage());
            } finally {
                // Every exit path finishes the span, the early return above
                // included: an unfinished span exports nothing, and a cycle that
                // reports nothing is exactly the blind spot this closes.
                Span::add('stats_resources_task.projects_queued', $projectsQueued);
                Span::current()?->finish();
            }
        }, $interval, 0, function (\Throwable $th) {
            Span::current()?->finish(error: $th);
        });
    }
}
