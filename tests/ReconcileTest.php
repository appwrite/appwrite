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

    public function testCountReportsWhatIsLoaded(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));
        $set = new RowSet([new Row('a', 'v1'), new Row('b', 'v1'), new Row('gone', 'v1')]);
        $scheduler = new Scheduler(
            source: new SnapshotSource(
                snapshot: $set->list(...),
                make: function (Row $row): Entry {
                    if ($row->id === 'gone') {
                        throw new \InvalidArgumentException('resource not found');
                    }

                    return new Entry(new Interval(60));
                },
            ),
            store: new MemoryStore(),
            clock: $clock,
            onError: function (\Throwable $error): void {},
        );

        $this->assertSame(0, $scheduler->count(), 'nothing is loaded before the first sync');

        $scheduler->reconcile();
        $this->assertSame(2, $scheduler->count(), 'a row that cannot be made is not loaded');

        $set->rows = [new Row('a', 'v1')];
        $scheduler->reconcile(full: true);
        $this->assertSame(1, $scheduler->count(), 'removals are reflected');
    }

    public function testARowDiscoveredBetweenSyncsIsCoveredFromWhenItTookEffect(): void
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

        // A sync has already happened, so the scheduler knows when it last
        // looked: anything appearing after that is genuinely new to it.
        $scheduler->reconcile();
        $scheduler->tick();
        $scheduler->commit(); // watermark at 03:05:10

        $clock->advance(50.0); // 03:06:00
        $set->rows = [new Row('a', 'v1', activeFrom: new \DateTimeImmutable('2026-08-18 03:05:30'))];
        $scheduler->reconcile();
        $clock->advance(15.0); // 03:06:15

        $this->assertSame(
            ['03:06:00'],
            array_map(fn(Occurrence $occurrence): string => $occurrence->due->format('H:i:s'), $scheduler->tick()),
            'coverage reaches back to activeFrom, past the watermark it missed',
        );
        $scheduler->commit();

        $clock->advance(60.0); // 03:07:15
        $this->assertSame(
            ['03:07:00'],
            array_map(fn(Occurrence $occurrence): string => $occurrence->due->format('H:i:s'), $scheduler->tick()),
            'after commit the entry rides the watermark: no re-delivery',
        );
    }

    public function testASyncAfterATickDoesNotClaimThatTickSawIt(): void
    {
        // A driver that reconciles between tick() and commit() — the pattern
        // the two-phase API invites — must not have the commit claim the
        // window was selected from that newer read. The replacement below
        // appeared after the window was chosen, so nothing in the pending
        // tick covers it, and a successor has to know that.
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:05:00.000000'));
        $store = new MemoryStore();
        $set = new RowSet([new Row('a', 'v1', activeFrom: new \DateTimeImmutable('2026-08-18 03:00:00'))]);
        $build = fn(string $token): Scheduler => new Scheduler(
            source: new SnapshotSource(
                snapshot: $set->list(...),
                make: fn(Row $row): Entry => new Entry(new Cron('* * * * *')),
            ),
            store: $store,
            tickSeconds: 60,
            leadSeconds: 120,
            recoverSeconds: 600,
            leaseSeconds: 360,
            token: $token,
            clock: $clock,
        );

        $predecessor = $build('predecessor');
        $predecessor->reconcile();   // read at 03:05:00
        $predecessor->tick();        // window selected here, out to 03:07:00

        $clock->advance(20.0);       // 03:05:20
        $set->rows = [new Row('a', 'v2', activeFrom: new \DateTimeImmutable('2026-08-18 03:05:20'))];
        $predecessor->reconcile();   // read at 03:05:20 — after the window was chosen
        $predecessor->commit();      // coverage to 03:07:00, from the 03:05:00 read

        $clock->advance(400.0);      // 03:12:00 — the claim has expired
        $successor = $build('successor');
        $successor->reconcile();

        $this->assertContains(
            '03:06:00',
            array_map(
                fn(Occurrence $occurrence): string => $occurrence->due->format('H:i:s'),
                $successor->tick(),
            ),
            'the replacement is owed the runs inside a window that was chosen before it existed',
        );
    }

    public function testASuccessorDoesNotReplayWhatItsPredecessorAlreadyRan(): void
    {
        // Coverage runs past the last read of the source: the predecessor read
        // at 03:05:00 and committed coverage to 03:07:00, so it dispatched
        // 03:06:00 and 03:07:00 for a schedule it already held. A successor
        // must not run those again just because they sit after that read.
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:05:00.000000'));
        $store = new MemoryStore();
        $set = new RowSet([new Row('known', 'v1', activeFrom: new \DateTimeImmutable('2026-08-18 03:01:00'))]);
        $build = fn(string $token): Scheduler => new Scheduler(
            source: new SnapshotSource(
                snapshot: $set->list(...),
                make: fn(Row $row): Entry => new Entry(new Cron('* * * * *')),
            ),
            store: $store,
            tickSeconds: 60,
            leaseSeconds: 120,
            token: $token,
            clock: $clock,
        );

        $predecessor = $build('predecessor');
        $predecessor->reconcile();   // source read at 03:05:00
        $clock->advance(120.0);      // 03:07:00
        $predecessor->tick();        // dispatches 03:05:00 and 03:06:00
        $predecessor->commit();      // coverage to 03:07:00, read to 03:05:00

        $clock->advance(130.0);      // 03:09:10 — the claim has expired
        $successor = $build('successor');
        $successor->reconcile();

        $this->assertSame(
            // 03:07:00 belongs to the successor: windows are half-open, so the
            // predecessor's ended just before it.
            ['known@03:07:00', 'known@03:08:00', 'known@03:09:00'],
            array_map(
                fn(Occurrence $occurrence): string => $occurrence->id . '@' . $occurrence->due->format('H:i:s'),
                $successor->tick(),
            ),
            'a schedule the predecessor held resumes at the watermark, not at its last read',
        );
    }

    public function testASuccessorCoversWhatItsPredecessorNeverSaw(): void
    {
        // The window a single watermark cannot describe: a predecessor
        // committed coverage up to 03:07:00, but its last read of the source
        // was at 03:05:00, so a schedule created at 03:05:30 was never in its
        // view. Its 03:06:00 run falls inside the committed window, and
        // treating that window as covered for this row would drop it.
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:05:00.000000'));
        $store = new MemoryStore();
        $set = new RowSet();
        $build = fn(string $token): Scheduler => new Scheduler(
            source: new SnapshotSource(
                snapshot: $set->list(...),
                make: fn(Row $row): Entry => new Entry(new Cron('* * * * *')),
            ),
            store: $store,
            tickSeconds: 60,
            leaseSeconds: 120,
            token: $token,
            clock: $clock,
        );

        $predecessor = $build('predecessor');
        $predecessor->reconcile();   // reads an empty source at 03:05:00
        $clock->advance(120.0);      // 03:07:00
        $predecessor->tick();
        $predecessor->commit();      // coverage to 03:07:00, source read to 03:05:00

        // Created inside that gap, and due inside it too.
        $set->rows = [new Row('late', 'v1', activeFrom: new \DateTimeImmutable('2026-08-18 03:05:30'))];

        $clock->advance(130.0);      // 03:09:10 — the predecessor is gone, its claim expired
        $successor = $build('successor');
        $successor->reconcile();

        $this->assertSame(
            ['late@03:06:00', 'late@03:07:00', 'late@03:08:00', 'late@03:09:00'],
            array_map(
                fn(Occurrence $occurrence): string => $occurrence->id . '@' . $occurrence->due->format('H:i:s'),
                $successor->tick(),
            ),
            'the successor owes every run since the row appeared, starting inside the committed window',
        );
    }

    public function testAColdStartDoesNotReplayHistoryForEverySchedule(): void
    {
        // The watermark is what a predecessor committed, and it already
        // covered the schedules it held. Reaching back to each row's own
        // activeFrom on the first sync would re-run everything edited within
        // the lookback, for the whole fleet, on every restart.
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:05:10.000000'));
        $store = new MemoryStore();
        $set = new RowSet();
        $build = fn(): Scheduler => new Scheduler(
            source: new SnapshotSource(
                snapshot: $set->list(...),
                make: fn(Row $row): Entry => new Entry(new Cron('* * * * *')),
            ),
            store: $store,
            token: 'replacement',
            clock: $clock,
        );

        // Rows edited well before the watermark, and read by the predecessor.
        $set->rows = [
            new Row('a', 'v1', activeFrom: new \DateTimeImmutable('2026-08-18 03:01:00')),
            new Row('b', 'v1', activeFrom: new \DateTimeImmutable('2026-08-18 02:00:00')),
        ];

        $predecessor = $build();
        $predecessor->reconcile();
        $predecessor->tick();
        $predecessor->commit(); // watermark at 03:05:10, source read at 03:05:10

        $replacement = $build();
        $replacement->reconcile();
        $clock->advance(65.0); // 03:06:15

        $this->assertSame(
            ['a@03:06:00', 'b@03:06:00'],
            array_map(
                fn(Occurrence $occurrence): string => $occurrence->id . '@' . $occurrence->due->format('H:i:s'),
                $replacement->tick(),
            ),
            'each schedule is covered from the watermark, not from its own last edit',
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

        // A sync first, so the row below is one the scheduler discovers
        // between syncs rather than on a cold start.
        $scheduler->reconcile();
        $scheduler->tick();
        $scheduler->commit(); // watermark at 03:05:10

        $clock->advance(50.0); // 03:06:00
        $set->rows = [new Row('a', 'v1', activeFrom: new \DateTimeImmutable('2026-08-18 03:05:30'))];
        $scheduler->reconcile();
        $clock->advance(15.0); // 03:06:15

        $underV1 = $scheduler->tick();
        $this->assertSame(['03:06:00'], $this->dues($underV1));

        // The source replaces the row before the commit lands.
        $set->rows = [new Row('a', 'v2', activeFrom: new \DateTimeImmutable('2026-08-18 03:05:45'))];
        $scheduler->reconcile();
        $scheduler->commit(); // watermark advances to 03:06:15 regardless

        $clock->advance(60.0); // 03:07:15
        $underV2 = $scheduler->tick();
        $this->assertSame(
            ['03:06:00', '03:07:00'],
            $this->dues($underV2),
            'the replacement runs from its own change time, repeating one already-committed run',
        );
        $this->assertSame(
            $underV1[0]->key(),
            $underV2[0]->key(),
            'the repeat is the same run, so a consumer keyed on it deduplicates',
        );
        $scheduler->commit();

        // Bounded to one span: the coverage is consumed, so it does not
        // reach back again on later ticks.
        $clock->advance(60.0);
        $this->assertSame(['03:08:00'], $this->dues($scheduler->tick()));
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
