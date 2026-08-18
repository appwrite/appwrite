<?php

declare(strict_types=1);

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Schedule\Clock\Test as TestClock;
use Utopia\Schedule\Occurrence;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Source\Row;
use Utopia\Schedule\Store\Memory as MemoryStore;
use Utopia\Schedule\Trigger\At;
use Utopia\Schedule\Trigger\Cron;
use Utopia\Schedule\Trigger\Interval;
use Utopia\Telemetry\Adapter\Test as TestTelemetry;

final class ReconcileTest extends TestCase
{
    /**
     * @param list<Occurrence> $occurrences
     * @return list<string>
     */
    private function dues(array $occurrences): array
    {
        return array_map(fn(Occurrence $occurrence): string => $occurrence->due->format('H:i:s'), $occurrences);
    }

    public function testFullSnapshotDiffAddsUpdatesAndRemoves(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));

        $set = new RowSet([
            new Row('a', 'v1', '* * * * *'),
            new Row('b', 'v1', 'interval'),
        ]);
        $made = new class {
            public int $count = 0;
        };
        $scheduler = new Scheduler(
            source: new SnapshotSource(
                snapshot: $set->list(...),
                make: function (Row $row) use ($made): Entry {
                    ++$made->count;
                    $spec = $row->data;
                    if (!\is_string($spec)) {
                        throw new \InvalidArgumentException('spec must be a string');
                    }

                    return $spec === 'interval'
                        ? new Entry(new Interval(60), $row->id)
                        : new Entry(new Cron($spec), $row->id);
                },
            ),
            store: new MemoryStore(),
            clock: $clock,
        );

        $scheduler->reconcile();
        $this->assertSame(2, $made->count);

        $scheduler->reconcile();
        $this->assertSame(2, $made->count, 'unchanged versions must not be re-made');

        $set->rows = [
            new Row('a', 'v2', '*/2 * * * *'), // updated
            // 'b' hard-deleted
        ];
        $scheduler->reconcile();
        $this->assertSame(3, $made->count, 'only the changed row is re-made');

        $scheduler->tick();
        $scheduler->commit();
        $clock->advance(120.0);

        $this->assertSame(
            [['a', '03:02:00', 'a']],
            array_map(
                fn(Occurrence $occurrence): array => [$occurrence->id, $occurrence->due->format('H:i:s'), $occurrence->payload],
                $scheduler->tick(),
            ),
            'removed rows stop firing; payload rides along',
        );
    }

    public function testIncrementalSyncConvergesRemovalsOnlyThroughRelistOrSoftDelete(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));

        $full = new RowSet([new Row('a', 'v1'), new Row('b', 'v1')]);
        $changed = new RowSet();
        $scheduler = new Scheduler(
            source: new IncrementalSource(
                snapshot: $full->list(...),
                make: fn(Row $row): Entry => new Entry(new Interval(60), $row->id),
                since: fn(\DateTimeImmutable $moment): array => $changed->list(),
            ),
            store: new MemoryStore(),
            clock: $clock,
        );

        $scheduler->reconcile(); // first sync is always full
        $scheduler->tick();
        $scheduler->commit();

        // 'b' is hard-deleted at the source; the change feed cannot see it.
        $full->rows = [new Row('a', 'v1')];
        $scheduler->reconcile();
        $clock->advance(60.0);

        $ids = array_map(fn(Occurrence $occurrence): string => $occurrence->id, $scheduler->tick());
        $this->assertSame(['a', 'b'], $ids, 'a change feed cannot converge a hard delete');
        $scheduler->commit();

        $scheduler->reconcile(full: true);
        $clock->advance(60.0);
        $ids = array_map(fn(Occurrence $occurrence): string => $occurrence->id, $scheduler->tick());
        $this->assertSame(['a'], $ids, 'the relist converges the hard delete');
        $scheduler->commit();

        // Soft deletes do flow through the change feed.
        $changed->rows = [new Row('a', 'v2', active: false)];
        $scheduler->reconcile();
        $clock->advance(60.0);

        $this->assertSame([], $scheduler->tick());
    }

    public function testDeliveredOneShotIsTombstonedUntilTheSourceForgetsIt(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:00.000000'));

        $set = new RowSet([new Row('job', 'v1', '2026-08-18 03:00:30')]);
        $scheduler = new Scheduler(
            source: new SnapshotSource(
                snapshot: $set->list(...),
                make: function (Row $row): Entry {
                    $at = $row->data;
                    if (!\is_string($at)) {
                        throw new \InvalidArgumentException('time must be a string');
                    }

                    return new Entry(new At(new \DateTimeImmutable($at)));
                },
            ),
            store: new MemoryStore(),
            clock: $clock,
        );

        $scheduler->reconcile();
        $scheduler->tick();
        $scheduler->commit();

        $clock->advance(45.0);
        $this->assertCount(1, $scheduler->tick());
        $scheduler->commit();

        // The source still lists the row (the worker has not marked it
        // done yet): it must not re-arm.
        $scheduler->reconcile();
        $clock->advance(30.0);
        $this->assertSame([], $scheduler->tick());
        $scheduler->commit();

        // A new version is a genuine reschedule.
        $set->rows = [new Row('job', 'v2', '2026-08-18 03:01:30')];
        $scheduler->reconcile();
        $clock->advance(60.0); // 03:02:15
        $this->assertCount(1, $scheduler->tick());
        $scheduler->commit();

        // The row leaves a full snapshot: the tombstone is evicted, so a
        // later row reusing the same id and version schedules again.
        $set->rows = [];
        $scheduler->reconcile(full: true);

        $set->rows = [new Row('job', 'v2', '2026-08-18 03:03:00')];
        $scheduler->reconcile();
        $clock->advance(60.0); // 03:03:15

        $this->assertCount(1, $scheduler->tick());
    }

    public function testActiveFromStopsBackfillUnderAnOldWatermark(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:00.000000'));

        $set = new RowSet();
        $scheduler = new Scheduler(
            source: new SnapshotSource(
                snapshot: $set->list(...),
                make: fn(Row $row): Entry => new Entry(new Cron('* * * * *')),
            ),
            store: new MemoryStore(),
            clock: $clock,
        );
        $scheduler->tick();
        $scheduler->commit(); // watermark at 03:00:00

        $set->rows = [new Row('a', 'v1', activeFrom: new \DateTimeImmutable('2026-08-18 03:00:30.000000'))];
        $scheduler->reconcile();

        $clock->advance(120.0); // window [03:00:00, 03:02:00) — but the schedule changed at 03:00:30

        $this->assertSame(
            ['03:01:00'],
            array_map(fn(Occurrence $occurrence): string => $occurrence->due->format('H:i:s'), $scheduler->tick()),
            'occurrences from before the schedule (last) changed are never delivered',
        );
    }

    public function testAFailedListingDiscardsTheWholeBatch(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));

        $link = new class {
            public bool $broken = false;
        };
        $scheduler = new Scheduler(
            source: new SnapshotSource(
                snapshot: function () use ($link): iterable {
                    yield new Row('a', 'v1');
                    if ($link->broken) {
                        throw new \RuntimeException('connection lost');
                    }
                    yield new Row('b', 'v1');
                },
                make: fn(Row $row): Entry => new Entry(new Interval(60), $row->id),
            ),
            store: new MemoryStore(),
            clock: $clock,
        );

        $scheduler->reconcile();
        $scheduler->tick();
        $scheduler->commit();

        $link->broken = true;
        try {
            $scheduler->reconcile(full: true);
            $this->fail('a failed listing must surface');
        } catch (\RuntimeException) {
        }

        $clock->advance(60.0);
        $ids = array_map(fn(Occurrence $occurrence): string => $occurrence->id, $scheduler->tick());

        $this->assertSame(['a', 'b'], $ids, 'a failed listing must not look like a mass removal');
    }

    public function testARowThatFailsToMakeIsSkippedAndReported(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));
        $telemetry = new TestTelemetry();
        $errors = new class {
            /** @var list<string> */
            public array $messages = [];
        };
        $scheduler = new Scheduler(
            source: new SnapshotSource(
                snapshot: fn(): array => [new Row('a', 'v1'), new Row('bad', 'v1'), new Row('c', 'v1')],
                make: function (Row $row): Entry {
                    if ($row->id === 'bad') {
                        throw new \InvalidArgumentException('poison row');
                    }

                    return new Entry(new Interval(60), $row->id);
                },
            ),
            store: new MemoryStore(),
            clock: $clock,
            telemetry: $telemetry,
            onError: function (\Throwable $error) use ($errors): void {
                $errors->messages[] = $error->getMessage();
            },
        );

        $scheduler->reconcile();
        $scheduler->tick();
        $scheduler->commit();
        $clock->advance(60.0);

        $ids = array_map(fn(Occurrence $occurrence): string => $occurrence->id, $scheduler->tick());

        $this->assertSame(['a', 'c'], $ids);
        $this->assertSame(['poison row'], $errors->messages);

        /** @var list<float|int> $counted */
        $counted = get_object_vars($telemetry->counters['schedule.error.total'])['values'];
        $this->assertSame([1], $counted);
    }

    public function testOneShotDueSoonerThanTheSyncLagFiresLate(): void
    {
        // A row created with a near-immediate due time is discovered by a
        // sync that runs after the watermark already passed the due time.
        // Coverage from the row's activeFrom delivers it late, not never.
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:00.000000'));
        $set = new RowSet();
        $scheduler = new Scheduler(
            source: new SnapshotSource(
                snapshot: $set->list(...),
                make: fn(Row $row): Entry => new Entry(new At(new \DateTimeImmutable('2026-08-18 03:00:04'))),
            ),
            store: new MemoryStore(),
            clock: $clock,
        );

        $scheduler->reconcile();
        $scheduler->tick();
        $scheduler->commit();

        $clock->advance(10.0); // watermark moves to 03:00:10
        $scheduler->tick();
        $scheduler->commit();

        // Created at 03:00:02, due 03:00:04 — first seen by this sync.
        $set->rows = [new Row('job', 'v1', activeFrom: new \DateTimeImmutable('2026-08-18 03:00:02'))];
        $scheduler->reconcile();

        $clock->advance(1.0); // 03:00:11
        $delivered = $scheduler->tick();
        $scheduler->commit();

        $this->assertSame(
            ['03:00:04'],
            array_map(fn(Occurrence $occurrence): string => $occurrence->due->format('H:i:s'), $delivered),
        );

        // Covered exactly once: nothing re-delivers on later ticks.
        $clock->advance(5.0);
        $this->assertSame([], $scheduler->tick());
    }

    public function testCoverageFromActiveFromDoesNotRedeliverAfterCommit(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:05:10.000000'));
        $set = new RowSet();
        $scheduler = new Scheduler(
            source: new SnapshotSource(
                snapshot: $set->list(...),
                make: fn(Row $row): Entry => new Entry(new Cron('* * * * *')),
            ),
            store: new MemoryStore(),
            clock: $clock,
        );

        $scheduler->tick();
        $scheduler->commit(); // watermark at 03:05:10

        $set->rows = [new Row('a', 'v1', activeFrom: new \DateTimeImmutable('2026-08-18 03:03:30'))];
        $scheduler->reconcile();
        $clock->advance(5.0); // 03:05:15

        $this->assertSame(
            ['03:04:00', '03:05:00'],
            array_map(fn(Occurrence $occurrence): string => $occurrence->due->format('H:i:s'), $scheduler->tick()),
            'the first tick covers back to activeFrom, past the watermark',
        );
        $scheduler->commit();

        $clock->advance(60.0); // 03:06:15
        $this->assertSame(
            ['03:06:00'],
            array_map(fn(Occurrence $occurrence): string => $occurrence->due->format('H:i:s'), $scheduler->tick()),
            'after commit the entry rides the watermark: no re-delivery',
        );
    }

    public function testAReplacementIsCoveredFromItsOwnChangeTimeAcrossACommit(): void
    {
        // A recurring row replaced between tick() and commit() keeps its new
        // coverFrom, so the next tick covers the replacement from its own
        // change time — even though the watermark has already passed it.
        // That re-delivers occurrences the previous definition already ran,
        // which is the deliberate direction of the trade: clearing coverFrom
        // instead would mean the new definition never runs for that span,
        // and this library loses runs to nothing. Delivery is at-least-once
        // and the repeat carries the same Occurrence::key(), so a consumer
        // keyed on it absorbs the second copy.
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:05:10.000000'));
        $set = new RowSet();
        $scheduler = new Scheduler(
            source: new SnapshotSource(
                snapshot: $set->list(...),
                make: fn(Row $row): Entry => new Entry(new Cron('* * * * *')),
            ),
            store: new MemoryStore(),
            clock: $clock,
        );

        $scheduler->tick();
        $scheduler->commit(); // watermark at 03:05:10

        $set->rows = [new Row('a', 'v1', activeFrom: new \DateTimeImmutable('2026-08-18 03:03:30'))];
        $scheduler->reconcile();
        $clock->advance(5.0); // 03:05:15

        $underV1 = $scheduler->tick();
        $this->assertSame(['03:04:00', '03:05:00'], $this->dues($underV1));

        // The source replaces the row before the commit lands.
        $set->rows = [new Row('a', 'v2', activeFrom: new \DateTimeImmutable('2026-08-18 03:04:30'))];
        $scheduler->reconcile();
        $scheduler->commit(); // watermark advances to 03:05:15 regardless

        $clock->advance(60.0); // 03:06:15
        $underV2 = $scheduler->tick();
        $this->assertSame(
            ['03:05:00', '03:06:00'],
            $this->dues($underV2),
            'the replacement runs from its own change time, repeating one already-committed run',
        );
        $this->assertSame(
            $underV1[1]->key(),
            $underV2[0]->key(),
            'the repeat is the same run, so a consumer keyed on it deduplicates',
        );
        $scheduler->commit();

        // Bounded to one span: the coverage is consumed, so it does not
        // reach back again on later ticks.
        $clock->advance(60.0);
        $this->assertSame(['03:07:00'], $this->dues($scheduler->tick()));
    }

    public function testOneShotReplacedBetweenTickAndCommitSurvives(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:00.000000'));
        $set = new RowSet([new Row('job', 'v1', '2026-08-18 03:00:30')]);
        $scheduler = new Scheduler(
            source: new SnapshotSource(
                snapshot: $set->list(...),
                make: function (Row $row): Entry {
                    $at = $row->data;
                    if (!\is_string($at)) {
                        throw new \InvalidArgumentException('time must be a string');
                    }

                    return new Entry(new At(new \DateTimeImmutable($at)));
                },
            ),
            store: new MemoryStore(),
            clock: $clock,
        );

        $scheduler->reconcile();
        $scheduler->tick();
        $scheduler->commit();

        $clock->advance(45.0);
        $this->assertCount(1, $scheduler->tick()); // v1 delivered, not yet committed

        // The source reschedules the same id before the commit lands.
        $set->rows = [new Row('job', 'v2', '2026-08-18 03:01:30')];
        $scheduler->reconcile();
        $scheduler->commit(); // retires v1 only: the v2 replacement must survive

        $clock->advance(60.0); // 03:01:45
        $delivered = $scheduler->tick();
        $scheduler->commit();

        $this->assertSame(
            ['03:01:30'],
            array_map(fn(Occurrence $occurrence): string => $occurrence->due->format('H:i:s'), $delivered),
            'a one-shot replaced mid-tick is not deleted by the stale commit',
        );
    }
}
