<?php

declare(strict_types=1);

namespace Utopia\Schedule;

use Utopia\Schedule\Clock\System as SystemClock;
use Utopia\Schedule\Source\Row;
use Utopia\Schedule\Store\Memory as MemoryStore;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\Gauge;
use Utopia\Telemetry\Histogram;

/**
 * A running loop with two duties: reconcile schedules into memory from
 * a source of truth, and dispatch their occurrences at the right time.
 *
 * Correctness rules the scheduler enforces, distilled from production
 * failures of "check what is due now" loops:
 *
 * - Selection happens over an explicit half-open window, never against a
 *   moving "now". A minute boundary the loop crosses mid-evaluation
 *   cannot drop the occurrence sitting on it.
 * - Windows tile: each opens where the previous committed window closed
 *   (the watermark, part of the {@see Claim} held in {@see Store}).
 *   Nothing falls between ticks, and with shared state nothing falls
 *   across a restart either.
 * - Delivery is at-least-once within the recovery horizon:
 *   {@see Scheduler::commit()} advances the watermark only after the
 *   caller has handled a tick's occurrences. A crash mid-tick
 *   re-delivers; it never silently skips.
 * - Catch-up is bounded: a watermark older than the recovery ceiling is
 *   clamped, so recovery after a long outage replays a capped burst
 *   instead of everything since the outage began.
 * - Coverage extends to late discoveries: an entry that appears or
 *   changes between syncs is covered once from the moment it took
 *   effect, even when the watermark has already passed it, so a one-shot
 *   due sooner than the sync lag runs late instead of never. That reach
 *   stops at the previous sync — earlier than that, coverage is the
 *   watermark's job — so a cold start does not replay history for every
 *   schedule it loads.
 * - The watermark never rewinds: a clock stepping backwards produces
 *   empty windows instead of duplicating already-delivered occurrences.
 * - Reconciliation is level-based: a full snapshot diff converges
 *   additions, updates and removals — including hard deletes no change
 *   feed can see. Incremental syncs are an optimization between full
 *   snapshots, never the correctness mechanism.
 * - Leadership and the watermark share one claim: a commit renews the
 *   lease and advances coverage in a single compare-and-swap, and a tick
 *   renews it up front so the lease covers the dispatch ahead. Replicas
 *   sharing a {@see Store} elect one dispatcher, a standby takes over
 *   when the claim expires, and a deposed leader's late commit is
 *   fenced instead of rewriting coverage — failover trades duplicates,
 *   never losses. Duplicates stop being work when the consumer derives
 *   its identity from {@see Occurrence::key()}.
 *
 * One instance is one loop: reconciliation, selection and the commit all
 * run on the caller's stack, so the schedule map is never touched
 * concurrently and needs no locking. Everything about *how* a tick's
 * occurrences are processed — batching them into one round trip, fanning
 * them out across coroutines, isolating a failure — belongs to the
 * handler, which receives each due moment as it arrives. Two loops over
 * one instance
 * are not supported; two loops over one {@see Store} are — that is the
 * leader election.
 *
 * @phpstan-type Registered array{trigger: Trigger, payload: mixed, version: string, coverFrom: \DateTimeImmutable|null}
 */
final class Scheduler
{
    /** @var array<string, Registered> */
    private array $entries = [];

    /**
     * Delivered one-shots, id => version. Kept so the next snapshot does
     * not re-add a row whose completion the source has not recorded yet;
     * evicted when the row leaves a full snapshot, re-armed when the row
     * returns with a new version.
     *
     * @var array<string, string>
     */
    private array $tombstones = [];

    private ?\DateTimeImmutable $pendingCoveredUntil = null;

    /**
     * The source read that fed the pending tick. Committed alongside the
     * window so the record's two halves describe the same moment: coverage,
     * and the freshness of the view that produced it.
     */
    private ?\DateTimeImmutable $pendingSyncedUntil = null;

    /** @var array<string, string> delivered one-shots in the pending tick, id => version */
    private array $pendingOneShots = [];

    /** @var array<string, string> entries whose coverFrom the pending tick consumed, id => version */
    private array $pendingCovered = [];

    private bool $running = false;

    private readonly string $token;

    /** Cursor for incremental syncs; null forces the next sync to be full. */
    private ?\DateTimeImmutable $lastSyncAt = null;

    private float $nextSyncAt = 0.0;

    private float $nextSnapshotAt = 0.0;

    private readonly Histogram $dispatchDelay;

    private readonly Histogram $tickDuration;

    private readonly Histogram $reconcileDuration;

    private readonly Counter $dispatchTotal;

    private readonly Counter $errorTotal;

    private readonly Gauge $entriesGauge;

    private readonly Gauge $lagGauge;

    private readonly int $snapshotSeconds;

    private readonly int $recoverSeconds;

    private readonly int $leaseSeconds;

    /**
     * @param Source $source the source of truth the loop reconciles against; implement
     *                        {@see Changes} on it as well to make syncs incremental
     * @param Store $store where the claim lives — leadership plus watermark; back it with
     *                     {@see Store\Redis} or a database row and standby replicas elect one
     *                     dispatcher and survive restarts. Replica clocks must agree to within
     *                     a fraction of the lease.
     * @param int $tickSeconds between ticks in {@see Scheduler::run()}, wall-anchored
     * @param int $syncSeconds between reads of the source
     * @param int $leadSeconds a tick may reach this far past "now". Zero (the default) hands
     *                         occurrences over once due; raising it hands them over early so the
     *                         wait for the exact second happens inside {@see Scheduler::run()}
     * @param int|null $snapshotSeconds between full snapshot syncs when the source implements
     *                                  {@see Changes}; defaults to 30 sync cadences, 0 disables
     *                                  periodic relisting, and a source reporting no changes
     *                                  takes a full snapshot every sync regardless
     * @param int|null $recoverSeconds ceiling on how far behind the watermark may start a window,
     *                                 which bounds the catch-up burst after downtime; defaults
     *                                 to 300
     * @param int|null $leaseSeconds a leadership claim lives this long before it must be renewed,
     *                               so a standby takes over this long after the leader stops
     *                               ticking. Defaults to three ticks of delivery, and must
     *                               outlive two: a tick holds its claim until the last of its
     *                               occurrences has gone out, and losing leadership mid-delivery
     *                               costs a re-delivered window, reported as
     *                               schedule.error.total{stage="lease"}
     * @param string|null $token this instance's identity in the claim; defaults to a random one
     * @param Clock $clock time source; swap for {@see Clock\Test} in tests
     * @param Telemetry $telemetry metric backend; the four golden signals are recorded as
     *                             schedule.dispatch.delay and schedule.tick.duration (latency),
     *                             schedule.dispatch.total and schedule.entries (traffic),
     *                             schedule.error.total by stage (errors) and schedule.lag
     *                             (saturation: seconds the window start trails "now")
     * @param \Closure|null $onError receives reconciliation failures (a sync that throws, a row
     *                               `make` rejects) so dispatch keeps running on the last good
     *                               view; without it those failures rethrow
     *
     * @throws \InvalidArgumentException on a non-positive tick or sync cadence, a negative
     *                                   snapshot cadence, lead time or recovery ceiling, or a
     *                                   lease shorter than two ticks of delivery
     */
    public function __construct(
        private readonly Source $source,
        private readonly Store $store = new MemoryStore(),
        private readonly int $tickSeconds = 1,
        private readonly int $syncSeconds = 10,
        private readonly int $leadSeconds = 0,
        ?int $snapshotSeconds = null,
        ?int $recoverSeconds = null,
        ?int $leaseSeconds = null,
        ?string $token = null,
        private readonly Clock $clock = new SystemClock(),
        Telemetry $telemetry = new NoTelemetry(),
        private readonly ?\Closure $onError = null,
    ) {
        // Derived from the three cadences above, so a caller sets what it
        // knows — how often to tick, how often to read, how much lead time —
        // and nothing else has to restate the arithmetic between them.
        $this->snapshotSeconds = $snapshotSeconds ?? $syncSeconds * 30;
        $this->recoverSeconds = $recoverSeconds ?? 300;
        $this->leaseSeconds = $leaseSeconds ?? ($tickSeconds + $leadSeconds) * 3;

        if ($tickSeconds < 1) {
            throw new \InvalidArgumentException('Tick interval must be at least 1 second');
        }
        if ($syncSeconds < 1) {
            throw new \InvalidArgumentException('Sync cadence must be at least 1 second');
        }
        if ($this->snapshotSeconds < 0) {
            throw new \InvalidArgumentException('Snapshot cadence must not be negative');
        }
        if ($leadSeconds < 0 || $this->recoverSeconds < 0) {
            throw new \InvalidArgumentException('Lead time and recovery ceiling must not be negative');
        }
        // A tick holds its claim through delivery, to the end of the lead time.
        if ($this->leaseSeconds < ($tickSeconds + $leadSeconds) * 2) {
            throw new \InvalidArgumentException('A leadership claim must outlive at least two ticks, delivery included');
        }

        $this->token = $token ?? bin2hex(random_bytes(8));

        // Dispatch delay spans "on time" (well under a second) to a full
        // recovery window of catch-up; the default OpenTelemetry buckets stop at
        // 10 seconds and would flatten every recovery into one bucket.
        $this->dispatchDelay = $telemetry->createHistogram(
            'schedule.dispatch.delay',
            's',
            null,
            ['ExplicitBucketBoundaries' => [0.1, 0.5, 1, 2, 5, 15, 30, 60, 120, 300]],
        );
        $this->tickDuration = $telemetry->createHistogram('schedule.tick.duration', 's');
        $this->reconcileDuration = $telemetry->createHistogram('schedule.reconcile.duration', 's');
        $this->dispatchTotal = $telemetry->createCounter('schedule.dispatch.total');
        $this->errorTotal = $telemetry->createCounter('schedule.error.total');
        $this->entriesGauge = $telemetry->createGauge('schedule.entries');
        $this->lagGauge = $telemetry->createGauge('schedule.lag', 's');
    }

    /**
     * Synchronize memory with the source: one full snapshot diff, or one
     * incremental pass when a change feed is configured and a cursor
     * exists. {@see Scheduler::run()} calls this on the $sync cadence;
     * call it directly when driving the loop yourself.
     *
     * A source that throws mid-listing discards the whole batch — a
     * failed sync must never look like a mass removal. A row whose
     * {@see Source::make()} throws is skipped (reported through onError)
     * and its previous entry, if any, stays.
     */
    public function reconcile(bool $full = false): void
    {
        $started = microtime(true);
        $syncStart = $this->clock->now();

        $source = $this->source;
        $since = $this->lastSyncAt;

        // A change feed cannot report a hard delete, so only a full
        // snapshot converges removals; incremental syncs fill the gaps
        // between them.
        if (!$full && $since instanceof \DateTimeImmutable && $source instanceof Changes) {
            $feed = $source->since($since);
        } else {
            $full = true;
            $feed = $source->snapshot();
        }

        $rows = [];
        foreach ($feed as $row) {
            $rows[$row->id] = $row;
        }

        foreach ($rows as $id => $row) {
            if (!$row->active) {
                unset($this->entries[$id], $this->tombstones[$id]);
                continue;
            }

            if (($this->tombstones[$id] ?? null) === $row->version) {
                continue; // delivered one-shot whose row the source still lists
            }
            unset($this->tombstones[$id]);

            $existing = $this->entries[$id] ?? null;
            if ($existing !== null && $existing['version'] === $row->version) {
                continue;
            }

            try {
                $entry = $this->source->make($row);
            } catch (\Throwable $error) {
                $this->report($error, 'make');
                continue;
            }

            $this->entries[$id] = [
                'trigger' => $entry->trigger,
                'payload' => $entry->payload,
                'version' => $row->version,
                'coverFrom' => $this->coverFrom($row->activeFrom, $since, $existing !== null),
            ];
        }

        if ($full) {
            foreach (array_keys($this->entries) as $id) {
                if (!isset($rows[$id])) {
                    unset($this->entries[$id]);
                }
            }
            foreach (array_keys($this->tombstones) as $id) {
                if (!isset($rows[$id])) {
                    unset($this->tombstones[$id]);
                }
            }
        }

        $this->lastSyncAt = $syncStart;
        $this->reconcileDuration->record(microtime(true) - $started, ['full' => $full]);
    }

    /**
     * Where a freshly made entry's own coverage starts.
     *
     * There are only two answers, and syncedUntil decides between them: an
     * entry is either owed coverage from the moment it took effect, or it
     * rides the watermark like everything else.
     *
     * It is owed its own coverage when nobody who committed the current
     * coverage could have known about it — its definition took effect after
     * the source was last read — or when this process held the previous
     * definition and the source has since replaced it. Either way the
     * watermark has moved over occurrences that were never dispatched for
     * this definition, and they are run late rather than dropped.
     *
     * Otherwise the definition existed when the source was last read, so
     * whoever committed the coverage accounted for it, and reaching back
     * would re-deliver runs that already happened. That is the trap in
     * treating syncedUntil as a floor rather than a question: a predecessor
     * commits coverage past its last read of the source, and every schedule
     * it already knew about would be replayed across that gap on takeover.
     */
    private function coverFrom(?\DateTimeImmutable $activeFrom, ?\DateTimeImmutable $since, bool $replaced): ?\DateTimeImmutable
    {
        if (!$activeFrom instanceof \DateTimeImmutable) {
            return null;
        }

        if ($replaced) {
            return $activeFrom;
        }

        $seen = $since ?? $this->moment($this->store->load()?->syncedUntil);

        return !$seen instanceof \DateTimeImmutable || $activeFrom > $seen ? $activeFrom : null;
    }

    /**
     * A committed window end as a moment, or null when there is none to read.
     */
    private function moment(?float $seconds): ?\DateTimeImmutable
    {
        if ($seconds === null) {
            return null;
        }

        $moment = \DateTimeImmutable::createFromFormat('U.u', \sprintf('%.6F', $seconds));

        return $moment === false ? null : $moment;
    }

    /**
     * Whether the definition an occurrence was selected against is still the
     * one memory holds — false once the source disables, deletes or rewrites
     * it. {@see Scheduler::run()} applies this before every hand-over; a
     * handler that defers work of its own should apply it again when that
     * work comes due.
     */
    public function isCurrent(Occurrence $occurrence): bool
    {
        return ($this->entries[$occurrence->id]['version'] ?? null) === $occurrence->version;
    }

    /**
     * How many schedules are loaded. The same figure the schedule.entries
     * gauge reports, for a caller that wants to log or assert it.
     */
    public function count(): int
    {
        return \count($this->entries);
    }

    /**
     * Select every occurrence in the next window, oldest first — after
     * confirming (or taking) leadership; a follower gets an empty list.
     *
     * The window opens at the claimed watermark (clamped to the recovery
     * ceiling, initialized to "now" on first ever run) and closes at now
     * plus the lead time; an entry with pending coverFrom is covered from there
     * instead, once. The result is remembered as pending until
     * {@see Scheduler::commit()}; ticking again without committing
     * re-selects the same occurrences.
     *
     * @return list<Occurrence>
     */
    public function tick(): array
    {
        $started = microtime(true);

        $claim = $this->elect();
        if (!$claim instanceof Claim) {
            $this->clearPending();

            return [];
        }

        $now = $this->clock->now();
        $end = $this->leadSeconds > 0 ? $now->modify("{$this->leadSeconds} seconds") : $now;
        $start = $this->moment($claim->coveredUntil) ?? $now;

        $floor = $now->modify("-{$this->recoverSeconds} seconds");
        if ($start < $floor) {
            $start = $floor;
        }

        $this->entriesGauge->record(\count($this->entries));
        $this->lagGauge->record(max(0.0, (float) $now->format('U.u') - (float) $start->format('U.u')));

        if ($start >= $end) {
            // Nothing to cover, but committing $start still initializes the
            // watermark on first run — and never rewinds it when the clock
            // has stepped backwards past the committed edge.
            $this->pendingCoveredUntil = $start;
            $this->pendingSyncedUntil = $this->lastSyncAt;
            $this->pendingOneShots = [];
            $this->pendingCovered = [];

            return [];
        }

        $occurrences = [];
        $oneShots = [];
        $covered = [];

        foreach ($this->entries as $id => $entry) {
            $entryStart = $entry['coverFrom'] ?? $start;
            if ($entryStart < $floor) {
                $entryStart = $floor;
            }
            if ($entryStart >= $end) {
                continue;
            }

            $dues = $entry['trigger']->occurrencesBetween($entryStart, $end);

            foreach ($dues as $due) {
                $occurrences[] = new Occurrence($id, $due, $entry['payload'], $entry['version']);
            }

            if ($entry['coverFrom'] !== null) {
                $covered[$id] = $entry['version'];
            }

            if ($dues !== [] && !$entry['trigger']->recurring()) {
                $oneShots[$id] = $entry['version'];
            }
        }

        usort($occurrences, fn(Occurrence $a, Occurrence $b): int => $a->due <=> $b->due ?: $a->id <=> $b->id);

        $this->pendingCoveredUntil = $end;
        $this->pendingSyncedUntil = $this->lastSyncAt;
        $this->pendingOneShots = $oneShots;
        $this->pendingCovered = $covered;
        $this->tickDuration->record(microtime(true) - $started);

        return $occurrences;
    }

    /** Reconcile if the source's cadence says it is time. */
    private function syncIfDue(): void
    {
        $now = (float) $this->clock->now()->format('U.u');
        if ($now < $this->nextSyncAt) {
            return;
        }

        $full = $this->snapshotSeconds > 0 && $now >= $this->nextSnapshotAt;

        try {
            $this->reconcile($full);
        } catch (\Throwable $error) {
            $this->report($error, 'reconcile');
        }

        $this->nextSyncAt = $now + $this->syncSeconds;
        if ($full) {
            $this->nextSnapshotAt = $now + $this->snapshotSeconds;
        }
    }

    /**
     * Wait for a delivery moment, still reconciling on the way: sleeping
     * straight through would leave a cancellation during the wait
     * unobservable, and the run would go out anyway.
     */
    private function wait(float $until): void
    {
        while (true) {
            $now = (float) $this->clock->now()->format('U.u');
            if ($now >= $until) {
                return;
            }

            $this->syncIfDue();

            $this->clock->sleep(min($until - $now, max(0.001, $this->nextSyncAt - $now)));
        }
    }

    /**
     * Hand a tick's occurrences to the handler as each falls due: those
     * sharing a moment together, those already past due immediately and in
     * order. Waiting is against an absolute target, so a slow handler cannot
     * push the ones behind it late.
     *
     * @param list<Occurrence> $occurrences
     * @param callable(list<Occurrence>): void $handler
     */
    private function deliver(array $occurrences, callable $handler): void
    {
        if ($occurrences === []) {
            return;
        }

        /** @var array<string, list<Occurrence>> $batches */
        $batches = [];
        foreach ($occurrences as $occurrence) {
            $batches[$occurrence->due->format('U.u')][] = $occurrence;
        }

        ksort($batches, SORT_NUMERIC);

        foreach ($batches as $at => $batch) {
            $wait = (float) $at - (float) $this->clock->now()->format('U.u');

            if ($wait > 0.0) {
                $this->wait((float) $at);

                // Only a waited batch can have outlived the tick's election.
                if (!$this->elect() instanceof Claim) {
                    return;
                }
            }

            $due = [];
            foreach ($batch as $occurrence) {
                if ($this->isCurrent($occurrence)) {
                    $due[] = $occurrence;
                }
            }

            if ($due === []) {
                continue;
            }

            // Lateness is measured at hand-over, not after the handler.
            $handedOver = (float) $this->clock->now()->format('U.u');

            try {
                $handler($due);
            } catch (\Throwable $error) {
                $this->errorTotal->add(1, ['stage' => 'dispatch']);

                throw $error;
            }

            $this->dispatchTotal->add(\count($due));
            foreach ($due as $occurrence) {
                $this->dispatchDelay->record(max(0.0, $handedOver - (float) $at));
            }
        }
    }

    /**
     * Advance the watermark past the last tick's window, renew the
     * leadership claim — one compare-and-swap does both — and retire
     * what the tick consumed. Call after handling the tick's
     * occurrences; skipping it on failure is what makes re-delivery
     * work.
     *
     * The swap is fenced: if another instance took the claim while the
     * tick was in flight, nothing is written and the pending tick is
     * dropped — the new leader re-covers it. All retirement is guarded
     * by the version seen at tick time, so an entry the source replaced
     * mid-tick is left alone.
     *
     * @return bool whether the watermark advanced; false means the window
     *              is re-covered next tick, because the claim was lost
     *              mid-tick or there was nothing pending
     */
    public function commit(): bool
    {
        if (!$this->pendingCoveredUntil instanceof \DateTimeImmutable) {
            return false;
        }

        $next = new Claim(
            $this->token,
            (float) $this->clock->now()->format('U.u') + $this->leaseSeconds,
            (float) $this->pendingCoveredUntil->format('U.u'),
            // The read that fed *this* tick, not the newest one: a sync that
            // landed after the window was selected did not contribute to it,
            // and claiming otherwise would tell a successor that schedules
            // this tick never saw are already covered. A tick that has not
            // reconciled at all leaves what a predecessor recorded intact,
            // rather than erasing the only view anyone has.
            $this->pendingSyncedUntil instanceof \DateTimeImmutable ? (float) $this->pendingSyncedUntil->format('U.u') : $this->store->load()?->syncedUntil,
        );

        if (!$this->store->swap($this->token, $next)) {
            // Deposed mid-tick: the new leader re-covers this window, so
            // nothing is lost — but a lease shorter than a dispatch takes
            // would do this every tick, which is worth seeing.
            $this->errorTotal->add(1, ['stage' => 'lease']);
            $this->clearPending();

            return false;
        }

        foreach ($this->pendingCovered as $id => $version) {
            if (isset($this->entries[$id]) && $this->entries[$id]['version'] === $version) {
                $this->entries[$id]['coverFrom'] = null;
            }
        }

        foreach ($this->pendingOneShots as $id => $version) {
            $this->tombstones[$id] = $version;

            $entry = $this->entries[$id] ?? null;
            if ($entry !== null && $entry['version'] === $version) {
                unset($this->entries[$id]);
            }
        }

        $this->clearPending();

        return true;
    }

    /**
     * The loop: elect, reconcile on the source's cadence, then tick,
     * dispatch and commit on a wall-anchored cadence. Followers idle and
     * poll for the claim.
     *
     * Anchoring ticks to the clock instead of sleeping a fixed span
     * after variable work keeps the tick phase from drifting. A handler
     * exception propagates before commit, so a supervised restart
     * re-delivers the tick instead of losing it. Reconciliation errors
     * go through onError and leave the last good view dispatching —
     * stale schedules beat a stopped scheduler.
     *
     * The handler is called once per due moment in the tick, not once per
     * tick: everything sharing a moment arrives together, oldest moment
     * first, and a moment still ahead is held until it arrives. Within a
     * hand-over the handler owns how the work runs — one round trip, a
     * coroutine each, or a plain loop — and must return only once that work
     * has settled, because the window is committed after the last hand-over
     * returns. Throwing means "do not commit", so the whole tick is
     * re-delivered — including the moments already handed over; to isolate
     * one bad schedule instead, catch inside the handler and return
     * normally.
     *
     * @param callable(list<Occurrence>): void $handler
     */
    public function run(callable $handler): void
    {
        $this->running = true;

        try {
            while ($this->running) {
                if (!$this->elect() instanceof Claim) {
                    $this->clock->sleep((float) $this->tickSeconds);
                    continue;
                }

                $this->syncIfDue();

                $this->deliver($this->tick(), $handler);
                $this->commit();

                if (!$this->running) {
                    break;
                }

                $phase = fmod((float) $this->clock->now()->format('U.u'), (float) $this->tickSeconds);
                $pause = (float) $this->tickSeconds - $phase;
                if ($pause < 0.001) {
                    $pause += $this->tickSeconds;
                }

                $this->clock->sleep($pause);
            }
        } finally {
            $this->release();
        }
    }

    /**
     * Make {@see Scheduler::run()} return after the current tick
     * finishes delivering and committing. The claim is released so a
     * standby takes over immediately instead of waiting out the lease.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Confirm or take leadership. Returns the current claim when this
     * instance holds it (or just took it), null when another holder's
     * claim is still live.
     */
    private function elect(): ?Claim
    {
        $claim = $this->store->load();
        $now = (float) $this->clock->now()->format('U.u');
        $expected = null;
        $coveredUntil = null;
        $syncedUntil = null;

        if ($claim instanceof Claim) {
            if ($claim->token === $this->token) {
                // Renew before the tick, not only at commit, so the lease
                // covers the dispatch ahead instead of the gap since the
                // last commit. Half a lease of slack keeps this to one
                // extra write per lease rather than one per tick.
                if ($claim->expiresAt - $now >= $this->leaseSeconds / 2) {
                    return $claim;
                }

                $renewed = new Claim($this->token, $now + $this->leaseSeconds, $claim->coveredUntil, $claim->syncedUntil);
                if ($this->store->swap($this->token, $renewed)) {
                    return $renewed;
                }

                $this->clearPending();

                return null; // lost the claim between load and swap
            }

            if ($claim->expiresAt > $now) {
                return null; // another instance leads
            }

            $expected = $claim->token;
            // Coverage and how much of the source produced it both carry over
            // from the predecessor.
            $coveredUntil = $claim->coveredUntil;
            $syncedUntil = $claim->syncedUntil;
        }

        $next = new Claim($this->token, $now + $this->leaseSeconds, $coveredUntil, $syncedUntil);

        if (!$this->store->swap($expected, $next)) {
            return null; // lost the takeover race
        }

        // A tick left pending from before losing leadership must never
        // commit after re-acquiring: its window predates the successor's
        // coverage and the matching token would let it through the fence.
        // Memory staleness needs no special-casing — the snapshot timer is
        // already due after any stretch spent as a follower.
        $this->clearPending();

        return $next;
    }

    private function release(): void
    {
        $claim = $this->store->load();
        if (!$claim instanceof Claim || $claim->token !== $this->token) {
            return;
        }

        // An empty, expired claim keeps the watermark and frees the lease.
        $this->store->swap($this->token, new Claim('', 0.0, $claim->coveredUntil, $claim->syncedUntil));
        $this->clearPending();
    }

    private function clearPending(): void
    {
        $this->pendingCoveredUntil = null;
        $this->pendingSyncedUntil = null;
        $this->pendingOneShots = [];
        $this->pendingCovered = [];
    }

    private function report(\Throwable $error, string $stage): void
    {
        $this->errorTotal->add(1, ['stage' => $stage]);

        if (!$this->onError instanceof \Closure) {
            throw $error;
        }

        ($this->onError)($error);
    }
}
