<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Message\DistributionBuild as DistributionBuildMessage;
use Appwrite\Event\Message\DistributionBuildAction;
use Appwrite\Event\Message\DistributionSubmission as DistributionSubmissionMessage;
use Appwrite\Event\Message\DistributionSubmissionAction;
use Appwrite\Event\Publisher\DistributionBuild as DistributionBuildPublisher;
use Appwrite\Event\Publisher\DistributionSubmission as DistributionSubmissionPublisher;
use Appwrite\Schedule\Source;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use Utopia\Database\Database;
use Utopia\Platform\Action;
use Utopia\Pools\Group;
use Utopia\Schedule\Occurrence;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\Store\Redis as ClaimStore;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;

/**
 * Enqueues a reconcile of every in-flight Distribution build and active
 * submission in the region on its row's interval. Builds and submissions are
 * read from `schedules` by one scheduler each, with its own claim.
 */
class ScheduleDistribution extends Action
{
    private const int SYNC = 10;

    private const int SNAPSHOT = 120;

    private const int RECOVER = 60;

    private const string BUILDS = 'builds';

    private const string SUBMISSIONS = 'submissions';

    public static function getName(): string
    {
        return 'schedule-distribution';
    }

    public function __construct()
    {
        $this
            ->desc('Schedule reconciliation of in-flight Distribution builds and submissions')
            ->inject('publisherForDistributionBuilds')
            ->inject('publisherForDistributionSubmissions')
            ->inject('dbForPlatform')
            ->inject('telemetry')
            ->inject('pools')
            ->callback($this->action(...));
    }

    public function action(
        DistributionBuildPublisher $publisherForDistributionBuilds,
        DistributionSubmissionPublisher $publisherForDistributionSubmissions,
        Database $dbForPlatform,
        Telemetry $telemetry,
        Group $pools,
    ): void {
        $loops = [
            fn () => $this->builds($publisherForDistributionBuilds, $dbForPlatform, $telemetry, $pools),
            fn () => $this->submissions($publisherForDistributionSubmissions, $dbForPlatform, $telemetry, $pools),
        ];

        $group = new WaitGroup();
        foreach ($loops as $loop) {
            $group->add();
            Coroutine::create(function () use ($group, $loop): void {
                try {
                    $loop();
                } finally {
                    $group->done();
                }
            });
        }
        $group->wait();

        Span::init('schedule.distribution.stopped');
        Span::current()?->finish(error: new \RuntimeException('Scheduler loop returned'));
    }

    public function builds(DistributionBuildPublisher $publisherForDistributionBuilds, Database $dbForPlatform, Telemetry $telemetry, Group $pools): void
    {
        $this->run(
            self::BUILDS,
            new Source\DistributionBuilds($dbForPlatform),
            $telemetry,
            $pools,
            fn (Occurrence $occurrence): bool => $publisherForDistributionBuilds->enqueue(new DistributionBuildMessage(
                project: $occurrence->payload['project'],
                action: DistributionBuildAction::Reconcile,
                id: $occurrence->payload['id'],
            )) !== false,
        );
    }

    public function submissions(DistributionSubmissionPublisher $publisherForDistributionSubmissions, Database $dbForPlatform, Telemetry $telemetry, Group $pools): void
    {
        $this->run(
            self::SUBMISSIONS,
            new Source\DistributionSubmissions($dbForPlatform),
            $telemetry,
            $pools,
            fn (Occurrence $occurrence): bool => $publisherForDistributionSubmissions->enqueue(new DistributionSubmissionMessage(
                project: $occurrence->payload['project'],
                action: DistributionSubmissionAction::Reconcile,
                id: $occurrence->payload['id'],
            )) !== false,
        );
    }

    /**
     * @param \Closure(Occurrence): bool $enqueue
     */
    private function run(string $name, Source\Database $source, Telemetry $telemetry, Group $pools, \Closure $enqueue): void
    {
        // A Redis failure ends the loop that holds the connection; the pool drops it.
        $pools->get('lock')->use(function (\Redis $redis) use ($name, $source, $telemetry, $enqueue): void {
            $scheduler = new Scheduler(
                source: $source,
                store: new ClaimStore($redis, self::getName() . ':' . $name . ':' . System::getEnv('_APP_REGION', 'default')),
                syncSeconds: self::SYNC,
                snapshotSeconds: self::SNAPSHOT,
                recoverSeconds: self::RECOVER,
                telemetry: $telemetry,
                onError: function (\Throwable $error) use ($name): void {
                    Span::init("schedule.distribution.{$name}.reconcile");
                    Span::current()?->finish(error: $error);
                },
            );

            $scheduler->run(fn (array $occurrences): null => $this->dispatch($name, $occurrences, $enqueue));
        });
    }

    /**
     * Nothing here may throw: an exception ends the scheduler loop. A failed
     * enqueue costs that resource one reconcile.
     *
     * @param list<Occurrence> $occurrences
     * @param \Closure(Occurrence): bool $enqueue
     */
    private function dispatch(string $name, array $occurrences, \Closure $enqueue): null
    {
        foreach ($occurrences as $occurrence) {
            Span::init("schedule.distribution.{$name}.enqueue");
            $error = null;

            try {
                Span::add('schedule.id', $occurrence->id);
                Span::add('resource.id', $occurrence->payload['id']);
                Span::add('project.id', $occurrence->payload['project']->getId());
                Span::add('occurrence.due', $occurrence->due->format('c'));

                if (!$enqueue($occurrence)) {
                    $error = new \RuntimeException('Failed to enqueue');
                }
            } catch (\Throwable $th) {
                $error = $th;
            } finally {
                Span::current()?->finish(error: $error);
            }
        }

        return null;
    }
}
