<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Message\StatsResources as StatsResourcesMessage;
use Appwrite\Event\Publisher\StatsResources as StatsResourcesPublisher;
use Appwrite\Schedule\Source;
use Appwrite\Usage\Concurrency;
use Appwrite\Usage\Connection;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Platform\Action;
use Utopia\Schedule\Occurrence;
use Utopia\Schedule\Scheduler;
use Utopia\Span\Span;
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

        // Floor of 1 guards against a zero/negative interval; test stacks
        // legitimately run short intervals (Cloud CI uses 2s).
        $interval = max(1, (int) System::getEnv('_APP_STATS_RESOURCES_INTERVAL', 3600));

        $source = new Source\Stats($dbForPlatform, $interval, $this->subqueries());

        $scheduler = new Scheduler(
            source: $source,
            syncSeconds: min($interval, 60),
            snapshotSeconds: $interval,
            telemetry: $telemetry,
            onError: function (\Throwable $error): void {
                Span::init('schedule.stats.reconcile');
                Span::current()?->finish(error: $error);
            },
        );

        $scheduler->run(fn (array $occurrences): null => $this->dispatch($occurrences, $publisherForStatsResources, $usageConnection));

        Span::init('schedule.stats.stopped');
        Span::current()?->finish(error: new \RuntimeException('Scheduler loop returned'));
    }

    /**
     * Project decode filters the listing skips. Editions that register more
     * subqueries on projects extend this.
     *
     * @return list<string>
     */
    protected function subqueries(): array
    {
        return APP_PROJECTS_SUBQUERIES;
    }

    /**
     * Nothing here may throw: an exception ends the scheduler loop, and the
     * process then stays alive and idle with nothing observing that
     * scheduling has stopped. A failure costs one occurrence, not the loop.
     *
     * @param list<Occurrence> $occurrences
     */
    private function dispatch(array $occurrences, StatsResourcesPublisher $publisherForStatsResources, Connection $usageConnection): null
    {
        if (!$usageConnection->isReady()) {
            Console::error('stats resources: usage schema is not ready, skipping ' . \count($occurrences) . ' occurrences');
            return null;
        }

        $batch = \count($occurrences);

        // A sweep costs far more than the interval that schedules it, so on a
        // short interval the scheduler outruns the worker and the queue grows
        // without bound. Every message in that backlog asks for the same thing
        // -- a fresh count of one project -- so the surplus buys nothing, while
        // a project created behind it waits the whole backlog for its first
        // sweep. Skip the round when the queue still holds an unstarted batch
        // and let the worker catch up; each project is then swept once per
        // drain rather than once per interval.
        try {
            $pending = $publisherForStatsResources->getSize();
            if ($pending >= $batch) {
                Span::init('schedule.stats.backpressure');
                Span::add('queue.pending', $pending);
                Span::add('occurrence.batch', $batch);
                Span::current()?->finish();

                return null;
            }
        } catch (\Throwable $th) {
            // The size probe is advisory; a broker that cannot answer it must
            // not stop the sweep being scheduled at all.
            Console::warning('stats resources: could not read queue size: ' . $th->getMessage());
        }

        foreach (\array_values($occurrences) as $index => $occurrence) {
            Span::init('schedule.stats.enqueue');
            $error = null;

            try {
                Span::add('occurrence.due', $occurrence->due->format('c'));
                Span::add('occurrence.late', \round(\microtime(true) - (float) $occurrence->due->format('U.u'), 3));
                Span::add('occurrence.batch', $batch);
                Span::add('occurrence.index', $index);

                if ($occurrence->id === Source\Stats::CONCURRENCY) {
                    $this->concurrency->sample($usageConnection->getUsage());
                    continue;
                }

                Span::add('project.id', $occurrence->id);
                if ($publisherForStatsResources->enqueue(new StatsResourcesMessage(project: $occurrence->payload)) === false) {
                    $error = new \RuntimeException('Failed to enqueue');
                }
            } catch (\Throwable $th) {
                $error = $th;
                Console::error('stats resources: ' . $occurrence->id . ' failed: ' . $th->getMessage());
            } finally {
                Span::current()?->finish(error: $error);
            }
        }

        return null;
    }
}
