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
 * - Delivery is at-least-once within the lookback horizon:
 *   {@see Scheduler::commit()} advances the watermark only after the
 *   caller has handled a tick's occurrences. A crash mid-tick
 *   re-delivers; it never silently skips.
 * - Catch-up is bounded: a watermark older than $lookback seconds is
 *   clamped, so recovery after a long outage replays a capped burst
 *   instead of everything since the outage began.
 * - Coverage extends to late discoveries: an entry that appears or
 *   changes between syncs is covered once from its own start — its
 *   activeFrom, or the previous sync — even when the watermark has
 *   already passed it. A one-shot due sooner than the sync lag runs
 *   late, never never.
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
 * handler, which receives the whole batch. Two loops over one instance
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

    private ?\DateTimeImmutable $pendingWindowEnd = null;

    /** @var array<string, string> delivered one-shots in the pending tick, id => version */
    private array $pendingOneShots = [];

    /** @var array<string, string> entries whose coverFrom the pending tick consumed, id => version */
    private array $pendingCovered = [];

    private bool $running = false;

    private readonly string $token;

    /** Cursor for incremental syncs; null forces the next sync to be full. */
    private ?\DateTimeImmutable $lastSyncAt = null;

    private float $nextSyncAt = 0.0;

    private float $nextRelistAt = 0.0;

    private readonly Histogram $dispatchDelay;

    private readonly Histogram $tickDuration;

    private readonly Histogram $reconcileDuration;

    private readonly Counter $dispatchTotal;

    private readonly Counter $errorTotal;

    private readonly Gauge $entriesGauge;

    private readonly Gauge $lagGauge;

    /**
     * @param Source $source the source of truth the loop reconciles against; implement
     *                        {@see Changes} on it as well to make syncs incremental
     * @param Store $store where the claim lives — leadership plus watermark; back it with
     *                     {@see Store\Redis} or a database row and standby replicas elect one
     *                     dispatcher and survive restarts. Replica clocks must agree to within
     *                     a fraction of $lease.
     * @param Clock $clock time source; swap for {@see Clock\Test} in tests
     * @param int $interval seconds between ticks in {@see Scheduler::run()}, wall-anchored
     * @param int $sync seconds between reconciliations
     * @param int $relist seconds between full snapshot syncs when the source implements
     *                    {@see Changes}; 0 disables periodic relisting, and with a source that
     *                    reports no changes every sync is a full snapshot regardless
     * @param int $lookahead seconds a tick may reach past "now". Zero (the default) delivers
     *                       occurrences after they are due, late by at most one interval, and
     *                       keeps at-least-once intact. Raising it trades that guarantee for
     *                       early hand-off: callers receive future occurrences (`$occurrence->due`)
     *                       to schedule precisely, but a crash after commit loses them.
     * @param int $lookback ceiling, in seconds, on how far behind the watermark may start a window
     * @param int $lease seconds a leadership claim lives before it must be renewed; a standby
     *                   takes over this long after the leader stops ticking. A tick renews the
     *                   claim before dispatching, so this must exceed the time one batch takes
     *                   to hand over — a slower handler loses leadership mid-dispatch, which
     *                   costs a re-delivered window and shows up as
     *                   schedule.error.total{stage="lease"}
     * @param string|null $token this instance's identity in the claim; defaults to a random one
     * @param Telemetry $telemetry metric backend; the four golden signals are recorded as
     *                             schedule.dispatch.delay and schedule.tick.duration (latency),
     *                             schedule.dispatch.total and schedule.entries (traffic),
     *                             schedule.error.total by stage (errors) and schedule.lag
     *                             (saturation: seconds the window start trails "now")
     * @param \Closure|null $onError receives reconciliation failures (a sync that throws, a row
     *                               `make` rejects) so dispatch keeps running on the last good
     *                               view; without it those failures rethrow
     *
     * @throws \InvalidArgumentException on a non-positive $interval or $sync, a negative
     *                                   $relist/$lookahead/$lookback, or a $lease shorter than
     *                                   two ticks
     */
    public function __construct(
        private readonly Source $source,
        private readonly Store $store = new MemoryStore(),
        private readonly Clock $clock = new SystemClock(),
        private readonly int $interval = 1,
        private readonly int $sync = 10,
        private readonly int $relist = 300,
        private readonly int $lookahead = 0,
        private readonly int $lookback = 300,
        private readonly int $lease = 60,
        ?string $token = null,
        Telemetry $telemetry = new NoTelemetry(),
        private readonly ?\Closure $onError = null,
    ) {
        if ($interval < 1) {
            throw new \InvalidArgumentException('Tick interval must be at least 1 second');
        }
        if ($sync < 1) {
            throw new \InvalidArgumentException('Sync cadence must be at least 1 second');
        }
        if ($relist < 0) {
            throw new \InvalidArgumentException('Relist cadence must not be negative');
        }
        if ($lookahead < 0 || $lookback < 0) {
            throw new \InvalidArgumentException('Lookahead and lookback must not be negative');
        }
        if ($lease < $interval * 2) {
            throw new \InvalidArgumentException('A leadership claim must outlive at least two ticks');
        }

        $this->token = $token ?? bin2hex(random_bytes(8));

        // Dispatch delay spans "on time" (well under a second) to a full
        // lookback of catch-up; the default OpenTelemetry buckets stop at
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
                // The entry is covered once from its own start — the row's
                // activeFrom, or failing that the previous sync (the earliest
                // moment it could have appeared) — even when the watermark
                // has already moved past it. Discovered late means run late,
                // never skipped.
                'coverFrom' => $row->activeFrom ?? $since,
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
     * Select every occurrence in the next window, oldest first — after
     * confirming (or taking) leadership; a follower gets an empty list.
     *
     * The window opens at the claimed watermark (clamped to $lookback,
     * initialized to "now" on first ever run) and closes at now plus
     * $lookahead; an entry with pending coverFrom is covered from there
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
        $end = $this->lookahead > 0 ? $now->modify("{$this->lookahead} seconds") : $now;
        $start = null;
        if ($claim->windowEnd !== null) {
            $watermark = \DateTimeImmutable::createFromFormat('U.u', $claim->windowEnd);
            $start = $watermark === false ? null : $watermark;
        }
        $start ??= $now;

        $floor = $now->modify("-{$this->lookback} seconds");
        if ($start < $floor) {
            $start = $floor;
        }

        $this->entriesGauge->record(\count($this->entries));
        $this->lagGauge->record(max(0.0, (float) $now->format('U.u') - (float) $start->format('U.u')));

        if ($start >= $end) {
            // Nothing to cover, but committing $start still initializes the
            // watermark on first run — and never rewinds it when the clock
            // has stepped backwards past the committed edge.
            $this->pendingWindowEnd = $start;
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
                $occurrences[] = new Occurrence($id, $due, $entry['payload']);
            }

            if ($entry['coverFrom'] !== null) {
                $covered[$id] = $entry['version'];
            }

            if ($dues !== [] && !$entry['trigger']->recurring()) {
                $oneShots[$id] = $entry['version'];
            }
        }

        usort($occurrences, fn(Occurrence $a, Occurrence $b): int => $a->due <=> $b->due ?: $a->id <=> $b->id);

        $this->pendingWindowEnd = $end;
        $this->pendingOneShots = $oneShots;
        $this->pendingCovered = $covered;
        $this->tickDuration->record(microtime(true) - $started);

        return $occurrences;
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
        if (!$this->pendingWindowEnd instanceof \DateTimeImmutable) {
            return false;
        }

        $next = new Claim(
            $this->token,
            (float) $this->clock->now()->format('U.u') + $this->lease,
            $this->pendingWindowEnd->format('U.u'),
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
     * The handler receives the tick's whole batch, oldest first, and owns
     * how it runs: one round trip for the lot, a coroutine per schedule,
     * or a plain loop. It must return only once that work has settled,
     * because the window is committed as soon as it returns. Throwing
     * means "do not commit", so the whole batch is re-delivered next
     * tick; to isolate one bad schedule instead, catch inside the handler
     * and return normally.
     *
     * @param callable(list<Occurrence>): void $handler
     */
    public function run(callable $handler): void
    {
        $this->running = true;

        try {
            while ($this->running) {
                if (!$this->elect() instanceof Claim) {
                    $this->clock->sleep((float) $this->interval);
                    continue;
                }

                $now = (float) $this->clock->now()->format('U.u');
                if ($now >= $this->nextSyncAt) {
                    $full = $this->relist > 0 && $now >= $this->nextRelistAt;

                    try {
                        $this->reconcile($full);
                    } catch (\Throwable $error) {
                        $this->report($error, 'reconcile');
                    }

                    $this->nextSyncAt = $now + $this->sync;
                    if ($full) {
                        $this->nextRelistAt = $now + $this->relist;
                    }
                }

                $occurrences = $this->tick();
                if ($occurrences !== []) {
                    // One clock read for the batch: lateness is measured at
                    // hand-over, so the metric stays about the scheduler's
                    // own punctuality rather than the handler's duration.
                    $handedOver = (float) $this->clock->now()->format('U.u');

                    try {
                        $handler($occurrences);
                    } catch (\Throwable $error) {
                        $this->errorTotal->add(1, ['stage' => 'dispatch']);

                        throw $error;
                    }

                    $this->dispatchTotal->add(\count($occurrences));
                    foreach ($occurrences as $occurrence) {
                        $this->dispatchDelay->record(max(0.0, $handedOver - (float) $occurrence->due->format('U.u')));
                    }
                }

                $this->commit();

                if (!$this->running) {
                    break;
                }

                $phase = fmod((float) $this->clock->now()->format('U.u'), (float) $this->interval);
                $pause = (float) $this->interval - $phase;
                if ($pause < 0.001) {
                    $pause += $this->interval;
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
        $windowEnd = null;

        if ($claim instanceof Claim) {
            if ($claim->token === $this->token) {
                // Renew before the tick, not only at commit, so the lease
                // covers the dispatch ahead instead of the gap since the
                // last commit. Half a lease of slack keeps this to one
                // extra write per lease rather than one per tick.
                if ($claim->expiresAt - $now >= $this->lease / 2) {
                    return $claim;
                }

                $renewed = new Claim($this->token, $now + $this->lease, $claim->windowEnd);
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
            $windowEnd = $claim->windowEnd; // coverage carries over from the predecessor
        }

        $next = new Claim($this->token, $now + $this->lease, $windowEnd);

        if (!$this->store->swap($expected, $next)) {
            return null; // lost the takeover race
        }

        // A tick left pending from before losing leadership must never
        // commit after re-acquiring: its window predates the successor's
        // coverage and the matching token would let it through the fence.
        // Memory staleness needs no special-casing — the relist timer is
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
        $this->store->swap($this->token, new Claim('', 0.0, $claim->windowEnd));
        $this->clearPending();
    }

    private function clearPending(): void
    {
        $this->pendingWindowEnd = null;
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
