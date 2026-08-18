<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Pools\Group;
use Utopia\Schedule\Claim;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Store;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

/**
 * How a schedule task runs a scheduler: the platform `schedules` collection as
 * its source, the lock pool as its claim store. Each task composes one of
 * these and keeps only what is its own — how its resource loads, what its
 * stored expression means, and how a due occurrence is published.
 */
final class Schedules
{
    public const LOOKBACK = 300; // seconds of missed runs a restart recovers
    public const RELIST = 30; // ticks between full snapshots

    private readonly ScheduleSource $source;

    private readonly Scheduler $scheduler;

    /**
     * @param \Closure(array<string, mixed>): \Utopia\Schedule\Trigger $trigger
     * @param \Closure(Database, array<string, mixed>): Document|null $resource
     */
    public function __construct(
        string $name,
        string $resourceType,
        string $collectionId,
        int $sync,
        int $tick,
        int $lookahead,
        Database $dbForPlatform,
        callable $getProjectDB,
        callable $isResourceBlocked,
        Group $pools,
        \Closure $trigger,
        ?\Closure $resource = null,
        Telemetry $telemetry = new NoTelemetry(),
    ) {
        $this->source = new ScheduleSource(
            dbForPlatform: $dbForPlatform,
            getProjectDB: $getProjectDB,
            isResourceBlocked: $isResourceBlocked,
            resourceType: $resourceType,
            collectionId: $collectionId,
            resource: $resource ?? fn (Database $projectDB, array $schedule): Document => $projectDB->getDocument($collectionId, $schedule['resourceId']),
            entry: fn (array $schedule): Entry => new Entry($trigger($schedule), $schedule),
            recency: $sync * 3,
        );

        $this->scheduler = new Scheduler(
            source: $this->source,
            // One record carries leadership and the committed window, so a
            // replacement resumes coverage where its predecessor stopped.
            store: $this->store($pools, 'utopia-schedule-' . $name),
            interval: $tick,
            sync: $sync,
            // A change feed cannot report a hard delete, so a full snapshot
            // still runs periodically to converge removals.
            relist: $sync * self::RELIST,
            lookahead: $lookahead,
            lookback: self::LOOKBACK,
            lease: $tick * 15,
            telemetry: $telemetry,
            onError: function (\Throwable $error) use ($resourceType): void {
                // A failed sync leaves the last good view dispatching: stale
                // schedules beat a stopped scheduler.
                Console::error("Failed to reconcile {$resourceType} schedules: {$error->getMessage()}");
            },
        );
    }

    /** Separate from run() so the combined task can bootstrap serially. */
    public function load(): void
    {
        $this->scheduler->reconcile();
    }

    /** Boot-time figure for the log. Becomes Scheduler::count() in 0.1.1. */
    public function count(): int
    {
        return $this->source->snapshotted();
    }

    /**
     * @param \Closure(list<\Utopia\Schedule\Occurrence>): void $dispatch
     */
    public function run(\Closure $dispatch): void
    {
        $this->scheduler->run($dispatch);
    }

    /**
     * Whether the definition a dispatch captured is still the one the source
     * reports, so work that slept does not run a cancelled schedule.
     *
     * @param array<string, mixed> $schedule
     */
    public function isLive(array $schedule): bool
    {
        return $this->source->isLive((string) $schedule['$sequence'], (string) $schedule['resourceUpdatedAt']);
    }

    /**
     * The claim in Redis, borrowed from the lock pool per call: the pool is
     * built from _APP_CONNECTIONS_LOCKS, which deployed environments set,
     * where the legacy `redis` resource reads the unset _APP_REDIS_HOST and
     * would resolve to localhost — a scheduler that never claims never
     * dispatches.
     */
    private function store(Group $pools, string $key): Store
    {
        return new class ($pools, $key) implements Store {
            public function __construct(
                private readonly Group $pools,
                private readonly string $key,
            ) {
            }

            #[\Override]
            public function load(): ?Claim
            {
                return $this->pools->get('lock')->use(fn (\Redis $redis): ?Claim => (new Store\Redis($redis, $this->key))->load());
            }

            #[\Override]
            public function swap(?string $expected, Claim $next): bool
            {
                return $this->pools->get('lock')->use(fn (\Redis $redis): bool => (new Store\Redis($redis, $this->key))->swap($expected, $next));
            }
        };
    }
}
