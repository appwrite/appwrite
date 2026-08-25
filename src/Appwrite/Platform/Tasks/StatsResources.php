<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Message\StatsResources as StatsResourcesMessage;
use Appwrite\Event\Publisher\StatsResources as StatsResourcesPublisher;
use Appwrite\Platform\Action;
use Appwrite\Schedule\Source\Usage;
use Appwrite\Usage\Concurrency;
use Appwrite\Usage\Connection;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Schedule\Occurrence;
use Utopia\Schedule\Scheduler;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;

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
            ->inject('telemetry')
            ->callback($this->action(...));
    }

    public function action(Database $dbForPlatform, StatsResourcesPublisher $publisherForStatsResources, Connection $usageConnection, Telemetry $telemetry): void
    {
        if (!$usageConnection->isEnabled()) {
            Console::info('Usage statistics are disabled');
            return;
        }

        // Floor of 1 guards against a zero/negative cadence spinning the loop;
        // test stacks legitimately run short intervals (Cloud CI uses 2s).
        $interval = max(1, (int) System::getEnv('_APP_STATS_RESOURCES_INTERVAL', 3600));

        $source = new Usage(
            $dbForPlatform,
            $interval,
            System::getEnv('_APP_REGION', 'default'),
        );

        $scheduler = new Scheduler(
            source: $source,
            // Between snapshots the source only reports projects that became
            // active, so the window's departures converge on the snapshot --
            // which also re-reads every project document, keeping the payloads
            // exactly as fresh as re-scanning each cycle used to.
            syncSeconds: max(1, (int) ($interval / 10)),
            snapshotSeconds: $interval,
            // Catch up at most one missed run per project after a restart or a
            // handover; older ones are dropped rather than replayed as a burst.
            recoverSeconds: $interval,
            telemetry: $telemetry,
            onError: function (\Throwable $error): void {
                Console::error('stats resources: reconcile failed: ' . $error->getMessage());
            },
        );

        $scheduler->run(fn (array $due): null => $this->dispatch($due, $publisherForStatsResources, $usageConnection));

        // run() returns only if something stopped the loop. Say so loudly: the
        // failure this task had was that stopping looked like running.
        Console::error('stats resources: scheduler loop returned, scheduling has stopped');
    }

    /**
     * Nothing here may throw. The Scheduler records a dispatch error and
     * rethrows it, which ends run() -- and the process then stays alive and
     * idle: it never exits, so restartPolicy never fires, and with no liveness
     * probe nothing observes that scheduling has stopped. A single transient
     * ClickHouse timeout on one tick silently ended gauge collection for a
     * whole region, and the task went on reporting Ready for weeks while
     * queueing nothing.
     *
     * @param list<Occurrence> $due
     */
    private function dispatch(array $due, StatsResourcesPublisher $publisherForStatsResources, Connection $usageConnection): null
    {
        // Once per batch, not once per project: the schema being absent is a
        // property of the store, and enqueueing work the worker cannot write
        // is what skipping a cycle has always avoided.
        try {
            if (!$usageConnection->isReady()) {
                Console::error('stats resources: usage schema is not ready, skipping ' . \count($due) . ' due run(s)');
                return null;
            }
        } catch (\Throwable $th) {
            Console::error('stats resources: readiness check failed, skipping cycle: ' . $th->getMessage());
            return null;
        }

        foreach ($due as $occurrence) {
            // Per occurrence, so one project's failure costs that project one
            // cycle -- not the rest of the batch, and not the loop.
            try {
                if ($occurrence->id === Usage::CONCURRENCY) {
                    $this->sample($usageConnection);
                    continue;
                }

                $project = $occurrence->payload;
                \assert($project instanceof Document);

                $publisherForStatsResources->enqueue(new StatsResourcesMessage(project: $project));
            } catch (\Throwable $th) {
                Console::error('stats resources: ' . $occurrence->id . ' failed, retrying next interval: ' . $th->getMessage());
            }
        }

        return null;
    }

    private function sample(Connection $usageConnection): void
    {
        $this->concurrency->sample($usageConnection->getUsage());
    }
}
