# Utopia Schedule

An opinionated scheduler for PHP. One running loop with two duties: reconcile schedules into memory from a source of truth (usually a database), and dispatch their occurrences at the right time — cron, fixed interval, delayed, or at a fixed moment. Built so a run can be late, but never silently lost.

This library is part of the [Utopia PHP Framework](https://github.com/utopia-php).

## Why opinionated

Most schedulers ask "what is due *now*?" in a loop, and sync their schedule list with "what changed since last time?". Both questions harbor production-grade defects:

- **Evaluation races.** While the loop walks thousands of schedules, "now" keeps moving. When a tick starts milliseconds before a minute boundary, the boundary crosses mid-loop and every occurrence sitting on it falls between two answers — dropped, with no error and no trace.
- **Gaps have no owner.** Whatever falls between two ticks, or between a crash and a restart, was due in a moment nobody ever asks about again.
- **Deletes are invisible.** A change feed keyed on an updated-at column never sees a hard-deleted row, so removed schedules keep firing from memory until the next restart.

Utopia Schedule replaces the questions. Occurrences are selected from an explicit half-open window `[start, end)`; each window opens exactly where the previous committed window closed, and the boundary (the *watermark*) persists through a storage port as part of a leadership claim. Windows tile the timeline: every occurrence belongs to exactly one window, no matter how slowly the loop runs, how far the tick phase drifts, or how often the process restarts. And reconciliation is level-based: the source states the full desired set, the scheduler diffs — so removals converge by construction.

## Getting started

Install with Composer:

```bash
composer require utopia-php/schedule
```

Describe the source of truth by implementing two methods:

```php
<?php

use Utopia\Schedule\Source;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Source\Row;
use Utopia\Schedule\Trigger\Cron;

final class FunctionSchedules implements Source
{
    public function __construct(private Database $database) {}

    /** Every schedule that should be running, as cheap descriptors. */
    public function snapshot(): iterable
    {
        foreach ($this->database->find('schedules') as $document) {
            yield new Row(
                id: $document->getId(),
                version: $document->getAttribute('updatedAt'),
                data: $document,
                activeFrom: new DateTimeImmutable($document->getAttribute('updatedAt')),
            );
        }
    }

    /**
     * The expensive part — parsing, hydrating context — called only when a
     * row is new or its version changed.
     */
    public function make(Row $row): Entry
    {
        return new Entry(
            trigger: new Cron($row->data->getAttribute('schedule')),
            payload: ['projectId' => $row->data->getAttribute('projectId')],
        );
    }
}
```

Then run it:

```php
<?php

use Utopia\Schedule\Occurrence;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\Store;

$scheduler = new Scheduler(
    source: new FunctionSchedules($database),
    store: new Store\Redis($redis),
    sync: 10,   // seconds between reconciliations
);

// The handler gets the tick's whole batch, oldest first, and owns how the
// work runs: one round trip for the lot, a coroutine per schedule, or a
// plain loop.
$scheduler->run(function (array $occurrences) use ($queue): void {
    foreach ($occurrences as $occurrence) {
        // $occurrence->id      — the row's identity
        // $occurrence->due     — when the run was scheduled for
        // $occurrence->payload — whatever make() attached
        // $occurrence->key()   — stable identity for deduplication
        $queue->enqueue($occurrence->id, $occurrence->payload);
    }
});
```

The scheduler decides *what runs when* and hands the batch over; it has no opinion about how the work is done. Batch it into one round trip, fan it out across coroutines, keep it serial — that is the handler's choice, because the right answer differs per workload. A handler must only return once its work has settled, since the window is committed as soon as it returns.

`run()` ticks on a wall-anchored cadence: it sleeps to the next multiple of the tick interval instead of sleeping a fixed span after variable work, so the tick phase never drifts. A handler exception propagates before the tick commits, which means a supervised restart re-delivers the tick instead of losing it. Reconciliation errors go the other way — through the `onError` callback, leaving the last good view dispatching, because stale schedules beat a stopped scheduler. Call `stop()` — from the handler or a signal handler — to return after the current tick completes.

## Triggers

A schedule's **trigger** answers one question: which moments does this schedule fall due at? Three implementations ship, all under `Utopia\Schedule\Trigger`:

| Trigger | Fires | Notes |
|----------|-------|-------|
| `new Cron('*/15 * * * *')` | on cron matches | Minute resolution, zero dependencies: the portable five-field dialect (`*`, values, ranges, steps over `*` or ranges, lists, `JAN`/`FRI` names, `7` as Sunday, `@daily`-style macros, the vixie either-day rule). Invalid, never-matching, and Quartz-extension expressions throw at construction instead of becoming a silent no-op. |
| `new Interval(900)` | every 900 seconds | Occurrences sit on a deterministic grid (anchor + k × seconds, epoch-anchored by default), so the cadence survives restarts instead of re-phasing to process boot. Pass an anchor to set the phase. |
| `At::in(300)` | once, 300 seconds after the call | The moment is absolute from construction. |
| `new At($dateTime)` | once, at `$dateTime` | |

All of them implement one contract, `Trigger`: `occurrencesBetween($start, $end)` returns the occurrences inside `[start, end)`, ascending. An occurrence exactly at `$start` belongs to the window; one exactly at `$end` belongs to the next.

A delivered one-shot is dropped from memory and tombstoned by its `(id, version)`, so the next sync does not re-add the row before the source records completion — the handler (or the worker it feeds) owns marking the row done. The same row returning with a new version is a genuine reschedule and runs again.

## Reconciliation

Reconciliation is level-based: `snapshot()` returns the full desired set and the scheduler converges memory to it — additions, updates, and removals, including hard deletes no change feed can see. Two properties keep it cheap and safe:

- `make()` runs only for new or version-changed rows; an unchanged row costs a string compare.
- A snapshot that throws mid-iteration discards the whole batch: a failed sync must never look like a mass removal. A row whose `make()` throws is skipped and reported; its previous entry stays.
- Discovery lag cannot lose runs: a new or changed entry is covered once from its own start — its `activeFrom`, or the previous sync time — even when the watermark has already passed it. A one-shot due sooner than the sync cadence runs late instead of never.

For large sets, implement `Changes` as well and syncs turn incremental between full snapshots:

```php
<?php

use Utopia\Schedule\Changes;
use Utopia\Schedule\Source;

final class FunctionSchedules implements Source, Changes
{
    // snapshot() and make() as above.

    public function since(DateTimeImmutable $moment): iterable
    {
        foreach ($this->database->find('schedules', [Query::greaterThanEqual('updatedAt', $moment)]) as $document) {
            yield new Row(/* ..., */ active: $document->getAttribute('enabled'));
        }
    }
}
```

A source either can answer "what changed?" or it cannot, so this is a second interface rather than a constructor argument left empty. The scheduler still takes a full snapshot every `relist` seconds (default 300) because a change feed cannot report a hard delete; between those it asks only for changes. The feed carries updates and soft deletes (`active: false`), and overlapping answers are harmless since the diff is by version.

A definition that changes is covered from its new change time, so a schedule edited while the scheduler was mid-tick runs under its new definition for that span — repeating a run the old definition already did rather than skipping one the new one never did. Delivery is at-least-once and the repeat carries the same `Occurrence::key()`, so a consumer keyed on it absorbs the second copy.

`Row::$activeFrom` anchors each entry's coverage: a schedule created — or edited — while the scheduler was down backfills only from its change time, never under the old watermark with the old definition, and a schedule the sync discovers late is still covered from its change time forward. Set it to the row's last change time.

## Surviving restarts and failover

Leadership and the watermark share one lifecycle — the leader advances both on every commit — so they share one record: the `Claim` (`token`, `expiresAt`, `windowEnd`), stored behind the `Store` interface (`load` and an atomic `swap`). Every commit is one compare-and-swap that renews the lease and advances the watermark together. That single write buys three properties:

- **Election is inherent.** Point replicas at the same store and exactly one dispatches; the others idle and take over when the claim expires — or immediately when `stop()` releases it. No lock service, no extra port.
- **Failover resumes coverage.** A successor takes the watermark from the claim it inherits: occurrences missed in the handover are delivered on its first tick, oldest first, bounded by `lookback` (default 300 seconds).
- **Commits are fenced.** A deposed leader's late commit no longer matches the stored token: nothing is written, the watermark never rewinds, and the new leader re-covers the in-flight window. A handover can duplicate a tick's occurrences; it can never lose them.

Tune with the `lease` option (seconds a claim lives before it must be renewed; must outlive at least two ticks) and `token` (the instance identity; defaults to a random one). A tick renews the claim before handing work over, so the lease has to exceed the time one batch takes: a slower handler loses leadership mid-dispatch, which costs a re-delivered window and shows up as `schedule.error.total{stage="lease"}` rather than passing silently. Replica clocks must agree to within a fraction of the lease. To shard instead of (or as well as) failing over, partition rows by a stable hash of their id — filter in `snapshot()` — and give each partition its own `Store` record; selection is pure per-schedule math, so any id-to-partition mapping that is exactly one-to-one is correct.

## Deduplication

Delivery is at-least-once, so a failover or a retried tick can hand the same run over twice. `Occurrence::key()` returns that run's stable identity — the schedule plus the moment it was due — and deriving the downstream identity from it turns a second delivery into a conflict the consumer ignores:

```php
<?php

$scheduler->run(function (array $occurrences) use ($database): void {
    foreach ($occurrences as $occurrence) {
        try {
            $database->createDocument('jobs', new Document([
                '$id' => \substr(\md5($occurrence->key()), 0, 32), // deterministic
                'scheduleId' => $occurrence->id,
                'dueAt' => $occurrence->due->format('Y-m-d H:i:s'),
            ]));
        } catch (Duplicate) {
            // already created by an earlier delivery of this same run
        }
    }
});
```

That is how at-least-once transport becomes effectively-once work, and it is the same trick Kubernetes plays by naming each CronJob's Job after its scheduled minute. A consumer that instead claims the row before publishing (an `UPDATE … WHERE status = 'pending'`) gets the same property from the other direction; the scheduler stays out of the way of either.

## Delivery model

`run()` wraps a two-phase primitive you can drive yourself:

```php
<?php

$scheduler->reconcile();

foreach ($scheduler->tick() as $occurrence) {
    $handle($occurrence);
}

$scheduler->commit();
```

`tick()` confirms (or takes) leadership and selects the next window's occurrences without advancing the watermark; `commit()` advances it and renews the claim in one swap. A retry loop keeps its leadership: `tick()` renews the claim when it is more than half spent, so retrying for longer than the lease does not hand the window to a standby mid-retry. Skip `commit()` when handling fails and the next `tick()` re-delivers — at-least-once, by construction. With the default `lookahead: 0`, occurrences are handed over after they fall due, late by at most one tick interval (default 1 second). Setting `lookahead` hands future occurrences over early — `$occurrence->due` says when they are meant to run — for callers that enqueue with precise delays, at the cost of losing occurrences committed but not yet run when a crash hits.

## Metrics

Pass a `utopia-php/telemetry` adapter and the scheduler records the four golden signals:

| Signal | Metric | Meaning |
|--------|--------|---------|
| Latency | `schedule.dispatch.delay` (histogram, s) | how late each occurrence was handed to the handler |
| Latency | `schedule.tick.duration`, `schedule.reconcile.duration` (histograms, s) | time spent selecting and syncing |
| Traffic | `schedule.dispatch.total` (counter), `schedule.entries` (gauge) | occurrences dispatched; schedules in memory |
| Errors | `schedule.error.total` (counter, `stage` attribute) | `reconcile`, `make` and `dispatch` failures, plus `lease` when a commit is fenced because leadership was lost mid-tick |
| Saturation | `schedule.lag` (gauge, s) | how far the window start trails "now" — steady at about one interval; growing means the loop is falling behind |

## Tracing

Everything worth tracing happens in code you own. The handler is the natural place for a span per run — it receives the batch, and each `Occurrence` carries its schedule, due time and `key()`:

```php
<?php

use Utopia\Span\Span;

$scheduler->run(function (array $occurrences) use ($queue): void {
    foreach ($occurrences as $occurrence) {
        Span::init('schedule.enqueue');
        try {
            Span::add('schedule.id', $occurrence->id);
            Span::add('schedule.due', $occurrence->due->format('c'));
            $queue->enqueue($occurrence->id, $occurrence->payload);
        } finally {
            Span::current()?->finish();
        }
    }
});
```

`snapshot()`, `make()`, `since()` and `onError` are your code too, so a span around a source query needs nothing from this library either. What happens inside the loop is reported as metrics rather than spans: selection cost as `schedule.tick.duration`, a lost claim as `schedule.error.total{stage="lease"}`, liveness as `schedule.lag` and `schedule.entries`. If you want per-tick control — a span covering the window, or a log line on an empty tick — drive `tick()`/`commit()` yourself; `commit()` returns whether the watermark advanced.

## Testing time

`Clock` isolates the scheduler from wall time, and the bundled `Clock\Test` makes timing defects reproducible fixtures — including the class of defect this library exists to prevent: the test suite replays a tick phase creeping across a minute boundary at 1.5ms per tick for an hour and asserts every occurrence is delivered exactly once.

## Scale

The design holds at fleet size: selection is pure per-schedule math and reconciliation is version-gated, so at 10,000 mixed schedules a full tick costs about 18ms against a 60-second interval, a warm snapshot diff under 1ms, and a cold one about 20ms. The exactly-once property is asserted at that scale in the test suite — 10,000 schedules through jittery ticks, expected counts derived with modular arithmetic rather than the schedule classes under test — and `composer bench` prints the current numbers.

## Layout

Each contract sits at the root of `src/` with its implementations in a folder beside it; value objects stay at the root, or with the concept that defines them:

```text
Scheduler.php          the loop
Occurrence.php         what a handler receives
Trigger.php            when a schedule runs — Trigger/{Cron,Interval,At}.php
Source.php             where schedules come from — Source/{Row,Entry}.php
Changes.php            a source that can also report what changed
Claim.php              leadership and coverage in one record
Store.php              where the claim lives — Store/{Memory,Redis}.php
Clock.php              time — Clock/{System,Test}.php
```

## Tests

```bash
composer test       # unit tier, bare host
```

The `Store\Redis` tests run against a real Redis on the host:

```bash
docker compose up -d --wait
composer test:e2e
docker compose down -v
```

## Copyright and license

The MIT License (MIT). Please see the [license file](LICENSE) for more information.
