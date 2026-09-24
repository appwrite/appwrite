<?php

declare(strict_types=1);

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Schedule\Claim;
use Utopia\Schedule\Clock\Test as TestClock;
use Utopia\Schedule\Occurrence;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\Source;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Source\Row;
use Utopia\Schedule\Store\Memory as MemoryStore;
use Utopia\Schedule\Trigger;
use Utopia\Schedule\Trigger\At;
use Utopia\Schedule\Trigger\Cron;
use Utopia\Schedule\Trigger\Interval;
use Utopia\Schedule\Trigger\Shifted;
use Utopia\Telemetry\Adapter\Test as TestTelemetry;

final class SchedulerTest extends TestCase
{
    public function testTickPhaseDriftingAcrossMinuteBoundariesDropsNothing(): void
    {
        // Regression for a production incident: the tick phase sat just
        // before a minute boundary and crept ~1.5ms per tick across it.
        // A "now"-based scheduler dropped ~90% of runs for 45 minutes;
        // tiled windows must deliver every occurrence exactly once.
        $clock = new TestClock(new \DateTimeImmutable('2026-08-17 15:53:59.773000'));
        $scheduler = $this->scheduler($clock, ['fn' => new Cron('*/5 * * * *')], tickSeconds: 60);

        $delivered = [];
        for ($tick = 0; $tick < 60; ++$tick) {
            foreach ($scheduler->tick() as $occurrence) {
                $delivered[] = $occurrence->due->format('H:i:s');
            }
            $scheduler->commit();
            $clock->advance(60.0015); // sleep(60) resumes late, never early
        }

        $expected = [];
        foreach (['15:55', '16:00', '16:05', '16:10', '16:15', '16:20', '16:25', '16:30', '16:35', '16:40', '16:45', '16:50'] as $slot) {
            $expected[] = "{$slot}:00";
        }

        $this->assertSame($expected, $delivered);
    }

    public function testRestartWithSharedStateBackfillsTheGap(): void
    {
        $store = new MemoryStore();
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));

        $scheduler = $this->scheduler($clock, ['fn' => new Interval(60)], store: $store);
        $scheduler->tick();
        $scheduler->commit();

        // The process dies for 3 minutes; a replacement takes the expired
        // claim and delivers every occurrence its predecessor missed.
        $clock->advance(185.0);
        $replacement = $this->scheduler($clock, ['fn' => new Interval(60)], store: $store, token: 'replacement');

        $this->assertSame(
            ['03:01:00', '03:02:00', '03:03:00'],
            array_map(fn(Occurrence $occurrence): string => $occurrence->due->format('H:i:s'), $replacement->tick()),
        );
    }

    public function testLookbackCapsTheCatchUpBurst(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));
        $scheduler = $this->scheduler($clock, ['fn' => new Interval(60)], recoverSeconds: 120);
        $scheduler->tick();
        $scheduler->commit();

        $clock->advance(3600.0);

        $this->assertSame(
            ['03:59:00', '04:00:00'],
            array_map(fn(Occurrence $occurrence): string => $occurrence->due->format('H:i:s'), $scheduler->tick()),
        );
    }

    public function testTickWithoutCommitRedelivers(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));
        $scheduler = $this->scheduler($clock, ['fn' => new Interval(60)]);
        $scheduler->tick();
        $scheduler->commit();
        $clock->advance(60.0);

        $first = $scheduler->tick();
        $again = $scheduler->tick();

        $this->assertCount(1, $first);
        $this->assertEquals($first, $again);

        $scheduler->commit();
        $this->assertSame([], $scheduler->tick());
    }

    public function testOneShotsDeliverOnceAndAreDropped(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:00.000000'));
        $scheduler = $this->scheduler($clock, ['delayed' => At::in(30, $clock->now())]);
        $scheduler->tick();
        $scheduler->commit();

        $clock->advance(45.0);
        $delivered = $scheduler->tick();
        $scheduler->commit();

        $this->assertSame(['delayed'], array_map(fn(Occurrence $occurrence): string => $occurrence->id, $delivered));

        $clock->advance(600.0);
        $this->assertSame([], $scheduler->tick());
    }

    public function testOccurrencesAreOrderedOldestFirstAcrossSchedules(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));
        $scheduler = $this->scheduler($clock, [
            'b-minutely' => new Cron('* * * * *'),
            'a-half-minute' => new Interval(30),
        ]);
        $scheduler->tick();
        $scheduler->commit();

        $clock->advance(90.0);

        $this->assertSame(
            [
                ['a-half-minute', '03:00:30'], // exactly on the inclusive window start
                ['a-half-minute', '03:01:00'],
                ['b-minutely', '03:01:00'],
                ['a-half-minute', '03:01:30'],
            ],
            array_map(
                fn(Occurrence $occurrence): array => [$occurrence->id, $occurrence->due->format('H:i:s')],
                $scheduler->tick(),
            ),
        );
    }

    public function testWatermarkNeverRewindsWhenTheClockStepsBack(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:02:30.000000'));
        $scheduler = $this->scheduler($clock, ['fn' => new Interval(60)]);
        $scheduler->tick();
        $scheduler->commit();

        $clock->advance(60.0);
        $this->assertCount(1, $scheduler->tick()); // 03:03:00
        $scheduler->commit();

        $clock->advance(-90.0);
        $backwards = $scheduler->tick();
        $this->assertSame([], $backwards);
        $scheduler->commit();

        // Real time passes the committed edge again: 03:03:00 is not
        // delivered a second time.
        $clock->advance(150.0);
        $recovered = $scheduler->tick();
        $this->assertSame(
            ['03:04:00'],
            array_map(fn(Occurrence $occurrence): string => $occurrence->due->format('H:i:s'), $recovered),
        );
    }

    public function testLookaheadHandsOverFutureOccurrences(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));
        $scheduler = $this->scheduler($clock, ['fn' => new Cron('*/15 * * * *')], leadSeconds: 60);

        $first = $scheduler->tick();
        $this->assertSame([], $first, '03:15 is beyond the first window');
        $scheduler->commit();

        $clock->advance(14 * 60.0); // 03:14:30
        $occurrences = $scheduler->tick();

        $this->assertCount(1, $occurrences);
        $this->assertSame('03:15:00', $occurrences[0]->due->format('H:i:s'));
        $this->assertGreaterThan($clock->now(), $occurrences[0]->due);
    }

    public function testRunDeliversOnAWallAnchoredCadenceUntilStopped(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-01-01 00:00:30.000000'));
        $scheduler = $this->scheduler($clock, ['minutely' => new Cron('* * * * *')], tickSeconds: 60);

        $seen = [];
        $scheduler->run(function (array $occurrences) use (&$seen, $scheduler, $clock): void {
            foreach ($occurrences as $occurrence) {
                $seen[] = [$occurrence->due->format('H:i:s'), $clock->now()->format('H:i:s')];
            }
            if (\count($seen) === 2) {
                $scheduler->stop();
            }
        });

        // Each occurrence is handled on the tick after its minute closes,
        // and ticks land on wall-clock minute boundaries.
        $this->assertSame(
            [['00:01:00', '00:02:00'], ['00:02:00', '00:03:00']],
            $seen,
        );
    }

    public function testStandbyTakesOverWhenTheClaimExpires(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-01-01 00:00:30.000000'));
        $store = new MemoryStore();
        // Another instance holds the claim for the next 120 seconds.
        $store->swap(null, new Claim('incumbent', (float) $clock->now()->format('U') + 120.0, null));

        $scheduler = $this->scheduler($clock, ['minutely' => new Cron('* * * * *')], store: $store, tickSeconds: 60, token: 'standby');

        $delivered = [];
        $scheduler->run(function (array $occurrences) use (&$delivered, $scheduler): void {
            foreach ($occurrences as $occurrence) {
                $delivered[] = $occurrence->due->format('H:i:s');
            }
            $scheduler->stop();
        });

        // Idle at 00:00:30 and 00:01:30; the claim expires at 00:02:30, so
        // the first covered minute is 00:03.
        $this->assertSame(['00:03:00'], $delivered);

        // stop() released the claim but kept the watermark, so the next
        // instance resumes coverage instead of waiting out the lease.
        $claim = $store->load();
        $this->assertInstanceOf(Claim::class, $claim);
        $this->assertSame('', $claim->token);
        $this->assertNotNull($claim->coveredUntil);
    }

    public function testDeposedLeaderCommitIsFenced(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));
        $store = new MemoryStore();

        $leader = $this->scheduler($clock, ['fn' => new Cron('* * * * *')], store: $store, token: 'leader');
        $leader->tick();
        $leader->commit(); // claim held, expires at 03:01:30

        $clock->advance(60.0); // 03:01:30
        $inFlight = $leader->tick(); // delivers 03:01:00, not yet committed
        $this->assertCount(1, $inFlight);

        // The leader stalls past its lease; a standby takes over and
        // commits further coverage.
        $clock->advance(61.0); // 03:02:31
        $standby = $this->scheduler($clock, ['fn' => new Cron('* * * * *')], store: $store, token: 'standby');
        $this->assertSame(
            ['03:01:00', '03:02:00'], // the handover re-covers the in-flight window: duplicates, never losses
            array_map(fn(Occurrence $occurrence): string => $occurrence->due->format('H:i:s'), $standby->tick()),
        );
        $standby->commit();
        $watermark = $store->load()?->coveredUntil;

        // The deposed leader's late commit is fenced: no write, no rewind.
        $leader->commit();
        $this->assertSame($watermark, $store->load()?->coveredUntil);
        $this->assertSame('standby', $store->load()?->token);
        $this->assertSame([], $leader->tick(), 'a deposed leader must not dispatch');
    }

    public function testAFencedCommitIsCountedAsALeaseError(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));
        $store = new MemoryStore();
        $telemetry = new TestTelemetry();

        $leader = $this->scheduler($clock, ['fn' => new Cron('* * * * *')], store: $store, token: 'leader', telemetry: $telemetry);
        $leader->tick();
        $leader->commit();

        $clock->advance(60.0);
        $leader->tick(); // in flight, uncommitted

        // A standby takes the expired claim, then the leader tries to commit.
        $clock->advance(121.0);
        $standby = $this->scheduler($clock, ['fn' => new Cron('* * * * *')], store: $store, token: 'standby');
        $standby->tick();
        $standby->commit();
        $leader->commit();

        /** @var list<float|int> $errors */
        $errors = get_object_vars($telemetry->counters['schedule.error.total'])['values'];
        $this->assertSame([1], $errors, 'losing the claim mid-tick must be visible, not silent');
    }

    public function testARetryingLoopKeepsItsClaimWhileItRetries(): void
    {
        // The documented retry pattern ticks without committing until the
        // handler succeeds. Only a commit used to renew the claim, so a
        // retry loop longer than the lease lost leadership to a standby
        // and could then never commit — both instances re-covering the
        // same window forever.
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));
        $store = new MemoryStore();

        $leader = $this->scheduler($clock, ['fn' => new Cron('* * * * *')], store: $store, token: 'leader', leaseSeconds: 60);
        $leader->tick();
        $leader->commit(); // claim expires 03:01:30

        $standby = $this->scheduler($clock, ['fn' => new Cron('* * * * *')], store: $store, token: 'standby', leaseSeconds: 60);

        // Two failed attempts, 40 seconds apart, neither committing.
        foreach ([40.0, 40.0] as $backoff) {
            $clock->advance($backoff);
            $this->assertNotSame([], $leader->tick(), 'the retry keeps re-selecting its window');
            $this->assertSame([], $standby->tick(), 'the claim must still be held while retrying');
        }

        // 80 seconds after the last commit — past the original lease — the
        // retry finally succeeds and commits.
        $leader->tick();
        $leader->commit();
        $this->assertSame('leader', $store->load()?->token);
    }

    public function testHandlerReceivesEachDueMomentWhenItFallsDue(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));
        $scheduler = $this->scheduler($clock, [
            'b' => new Cron('* * * * *'),
            'a' => new Interval(30),
        ], tickSeconds: 60);

        $batches = [];
        $scheduler->run(function (array $occurrences) use (&$batches, $scheduler): void {
            $batches[] = array_map(
                fn(Occurrence $occurrence): string => $occurrence->id . '@' . $occurrence->due->format('H:i:s'),
                $occurrences,
            );
            if (\count($batches) === 3) {
                $scheduler->stop();
            }
        });

        // A tick selects a window; delivery hands over each due moment in it
        // once that moment arrives, everything sharing the moment together.
        // Batching, fan-out and failure isolation within a hand-over remain
        // the handler's to choose, and an empty window never calls it.
        $this->assertSame([
            ['a@03:00:30'],
            ['a@03:01:00', 'b@03:01:00'],
            ['a@03:01:30'],
        ], $batches);

    }

    /**
     * The reason a tick may select ahead of "now": the wait for the exact
     * second happens once, in the scheduler, rather than in every handler.
     */
    public function testAnOccurrenceSelectedAheadIsHeldUntilItIsDue(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));
        $scheduler = $this->scheduler($clock, ['fn' => new Cron('* * * * *')], tickSeconds: 60, leadSeconds: 60, leaseSeconds: 300);

        $seen = [];
        $scheduler->run(function (array $occurrences) use (&$seen, $clock, $scheduler): void {
            foreach ($occurrences as $occurrence) {
                $seen[] = $occurrence->due->format('H:i:s') . ' handed over at ' . $clock->now()->format('H:i:s');
            }
            $scheduler->stop();
        });

        $this->assertSame(['03:01:00 handed over at 03:01:00'], $seen);
    }

    /**
     * A burst that all falls due in the same second is what makes a
     * scheduler's own punctuality someone else's outage. A source spreads it
     * by shifting each schedule, and delivery follows the due times.
     */
    public function testShiftedSchedulesSpreadAcrossTheTickTheyFallIn(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:00.000000'));
        $offsets = ['a' => 0, 'b' => 20, 'c' => 40];
        $scheduler = new Scheduler(
            source: new SnapshotSource(
                snapshot: fn(): array => [new Row('a', '1'), new Row('b', '1'), new Row('c', '1')],
                // What a caller with thousands of schedules on `* * * * *`
                // writes: a stable position per id, so each keeps its slot.
                make: fn(Row $row): Entry => new Entry(new Shifted(new Cron('* * * * *'), $offsets[$row->id])),
            ),
            tickSeconds: 60,
            leadSeconds: 60,
            leaseSeconds: 300,
            clock: $clock,
        );
        $scheduler->reconcile();

        $seen = [];
        $scheduler->run(function (array $occurrences) use (&$seen, $clock, $scheduler): void {
            foreach ($occurrences as $occurrence) {
                $seen[] = $occurrence->id . ' at ' . $clock->now()->format('H:i:s');
            }
            if (\count($seen) === 3) {
                $scheduler->stop();
            }
        });

        $this->assertSame(['a at 03:00:00', 'b at 03:00:20', 'c at 03:00:40'], $seen);
    }

    /**
     * The window is committed once the whole tick has been delivered, so an
     * offset that outlives the process does not silently drop the runs behind
     * it: nothing is claimed as covered until it has gone out.
     */
    public function testTheWindowIsNotCommittedUntilTheHeldBackRunsHaveGoneOut(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:00.000000'));
        $store = new MemoryStore();
        $scheduler = new Scheduler(
            source: new SnapshotSource(
                snapshot: fn(): array => [new Row('held', '1')],
                make: fn(Row $row): Entry => new Entry(new Shifted(new Cron('* * * * *'), 30)),
            ),
            store: $store,
            tickSeconds: 60,
            leadSeconds: 60,
            leaseSeconds: 300,
            clock: $clock,
        );
        $scheduler->reconcile();

        // A first window, committed, so there is a watermark to compare against.
        $scheduler->tick();
        $scheduler->commit();
        $claimed = $store->load();
        $this->assertInstanceOf(Claim::class, $claimed);
        $watermark = $claimed->coveredUntil;
        $this->assertNotNull($watermark);

        $committedAtHandOver = null;
        $scheduler->run(function (array $occurrences) use (&$committedAtHandOver, $store, $scheduler): void {
            $committedAtHandOver = $store->load()?->coveredUntil;
            $scheduler->stop();
        });

        $this->assertSame($watermark, $committedAtHandOver, 'the window was still uncommitted while its run waited');
        $committed = $store->load();
        $this->assertInstanceOf(Claim::class, $committed);
        $this->assertGreaterThan(
            $watermark,
            (float) $committed->coveredUntil,
            'and committed once the held-back run had gone out',
        );
    }

    /**
     * The defect a held-back delivery invites: the run is already selected,
     * the schedule is then cancelled, and it goes out anyway. Selection
     * cannot answer this — only the view at the moment of hand-over can.
     */
    public function testARunCancelledWhileItWaitedIsNotHandedOver(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:00.000000'));

        // What the console does at 03:00:10, while the run waits for 03:00:30.
        $cancelledAt = new \DateTimeImmutable('2026-08-18 03:00:10.000000');
        $scheduler = new Scheduler(
            source: new SnapshotSource(
                snapshot: fn(): array => $clock->now() >= $cancelledAt
                    ? [new Row('kept', '1')]
                    : [new Row('doomed', '1'), new Row('kept', '1')],
                make: fn(Row $row): Entry => new Entry(new Shifted(new Cron('* * * * *'), 30), $row->id),
            ),
            tickSeconds: 60,
            syncSeconds: 5,
            leadSeconds: 60,
            leaseSeconds: 300,
            // Both fall due in the same second and are held back, which is
            // the window the cancellation lands in.
            clock: $clock,
        );
        $scheduler->reconcile();

        $handed = [];
        $scheduler->run(function (array $occurrences) use (&$handed, $scheduler): void {
            foreach ($occurrences as $occurrence) {
                $handed[] = $occurrence->payload;
            }
            $scheduler->stop();
        });

        $this->assertSame(['kept'], $handed);
    }

    /**
     * A held-back delivery outlives the moment leadership was confirmed. If
     * the claim has since gone to another instance, that instance is covering
     * this window too, so handing over here is a duplicate run.
     */
    public function testADeposedLeaderDoesNotHandOverWhatItWasHoldingBack(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:00.000000'));
        $store = new MemoryStore();
        /** @var ?Scheduler $scheduler */
        $scheduler = null;

        // The takeover lands at 03:00:10, while the run waits for 03:00:30.
        $stolenAt = new \DateTimeImmutable('2026-08-18 03:00:10.000000');
        $snapshot = function () use ($store, $clock, $stolenAt, &$scheduler): array {
            if ($clock->now() >= $stolenAt && $store->load()?->token === 'leader') {
                $store->swap('leader', new Claim('successor', (float) $clock->now()->format('U.u') + 300, null));
                if ($scheduler instanceof Scheduler) {
                    $scheduler->stop();
                }
            }

            return [new Row('fn', '1')];
        };

        $scheduler = new Scheduler(
            source: new SnapshotSource(
                snapshot: $snapshot,
                make: fn(Row $row): Entry => new Entry(new Shifted(new Cron('* * * * *'), 30)),
            ),
            store: $store,
            tickSeconds: 60,
            syncSeconds: 5,
            leadSeconds: 60,
            leaseSeconds: 300,
            token: 'leader',
            clock: $clock,
        );
        $scheduler->reconcile();

        $handed = 0;
        $scheduler->run(function (array $occurrences) use (&$handed): void {
            $handed += \count($occurrences);
        });

        $this->assertSame(0, $handed, 'the successor owns this window now');
        $this->assertSame('successor', $store->load()?->token, 'and the deposed leader did not write over its claim');
    }

    public function testNumericIdsSurviveTheScheduleMap(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));
        $scheduler = $this->scheduler($clock, ['1' => new Cron('* * * * *')], tickSeconds: 60, leaseSeconds: 300);

        $scheduler->tick();
        $scheduler->commit();
        $clock->advance(31.0);

        $occurrences = $scheduler->tick();

        $this->assertCount(1, $occurrences);
        $this->assertSame('1', $occurrences[0]->id);
        $this->assertTrue($scheduler->isCurrent($occurrences[0]));
        $this->assertSame('1@1787022060.000000', $occurrences[0]->key());
    }

    public function testARunLoopDeliversNumericIds(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));
        $scheduler = $this->scheduler($clock, ['7' => new Interval(30)], tickSeconds: 60, leaseSeconds: 300);

        $seen = [];
        $scheduler->run(function (array $occurrences) use (&$seen, $scheduler): void {
            foreach ($occurrences as $occurrence) {
                $seen[] = $occurrence->id;
            }
            $scheduler->stop();
        });

        $this->assertSame(['7'], $seen);
    }

    public function testOccurrenceKeyIsStableAcrossRedelivery(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));
        $scheduler = $this->scheduler($clock, ['fn' => new Cron('* * * * *')]);
        $scheduler->tick();
        $scheduler->commit();

        $clock->advance(95.0);

        // An uncommitted tick is re-delivered; the key of each run must not
        // move, or a consumer keyed on it would treat the retry as new work.
        $first = array_map(fn(Occurrence $occurrence): string => $occurrence->key(), $scheduler->tick());
        $again = array_map(fn(Occurrence $occurrence): string => $occurrence->key(), $scheduler->tick());

        $this->assertCount(2, $first);
        $this->assertSame($first, $again, 'a re-delivered run keeps its key');
        $this->assertSame($first, array_unique($first), 'distinct runs need distinct keys');
        $this->assertStringStartsWith('fn@', $first[0]);

        // The key is (schedule, due moment) and nothing else: same run,
        // same key, whatever payload it carries.
        $due = new \DateTimeImmutable('2026-08-18 03:01:00');
        $this->assertSame(
            (new Occurrence('fn', $due, 'payload-a'))->key(),
            (new Occurrence('fn', $due, 'payload-b'))->key(),
        );
        $this->assertNotSame(
            (new Occurrence('fn', $due))->key(),
            (new Occurrence('fn', $due->modify('+1 minute')))->key(),
        );
    }

    public function testGoldenSignalsAreRecorded(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-01-01 00:00:30.000000'));
        $telemetry = new TestTelemetry();
        $scheduler = $this->scheduler($clock, ['minutely' => new Cron('* * * * *')], tickSeconds: 60, telemetry: $telemetry);

        $scheduler->run(function (array $occurrences) use ($scheduler): void {
            $scheduler->stop();
        });

        /** @var list<float|int> $dispatched */
        $dispatched = get_object_vars($telemetry->counters['schedule.dispatch.total'])['values'];
        $this->assertSame([1], $dispatched);

        /** @var list<float|int> $delays */
        $delays = get_object_vars($telemetry->histograms['schedule.dispatch.delay'])['values'];
        $this->assertCount(1, $delays);
        // The 00:01:00 occurrence is handed over on the 00:02:00 tick.
        $this->assertEqualsWithDelta(60.0, $delays[0], 0.001);

        /** @var list<float|int> $entries */
        $entries = get_object_vars($telemetry->gauges['schedule.entries'])['values'];
        $this->assertContains(1, $entries);

        /** @var list<float|int> $lags */
        $lags = get_object_vars($telemetry->gauges['schedule.lag'])['values'];
        // Saturation reads steady at about one interval behind "now".
        $this->assertEqualsWithDelta(60.0, max([0.0, ...$lags]), 0.001);

        $this->assertArrayHasKey('schedule.tick.duration', $telemetry->histograms);
        $this->assertArrayHasKey('schedule.reconcile.duration', $telemetry->histograms);
    }

    public function testAFailingHandlerCountsADispatchErrorAndSkipsCommit(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-01-01 00:00:30.000000'));
        $telemetry = new TestTelemetry();
        $scheduler = $this->scheduler($clock, ['minutely' => new Cron('* * * * *')], tickSeconds: 60, telemetry: $telemetry);

        try {
            $scheduler->run(function (array $occurrences): never {
                throw new \RuntimeException('downstream unavailable');
            });
            $this->fail('the handler failure must propagate');
        } catch (\RuntimeException) {
        }

        /** @var list<float|int> $errors */
        $errors = get_object_vars($telemetry->counters['schedule.error.total'])['values'];
        $this->assertSame([1], $errors);

        // The failed tick was never committed: it re-delivers.
        $this->assertCount(1, $scheduler->tick());
    }

    public function testRejectsNonPositiveInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Scheduler(source: $this->emptySource(), tickSeconds: 0);
    }

    public function testRejectsALeaseShorterThanTwoTicks(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Scheduler(source: $this->emptySource(), tickSeconds: 30, leaseSeconds: 45);
    }

    public function testRejectsNonPositiveSyncCadence(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Scheduler(source: $this->emptySource(), syncSeconds: 0);
    }

    public function testRejectsANegativeRelistCadence(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Scheduler(source: $this->emptySource(), snapshotSeconds: -1);
    }

    /**
     * @param array<array-key, Trigger> $triggers
     */
    private function scheduler(
        TestClock $clock,
        array $triggers,
        ?MemoryStore $store = null,
        int $tickSeconds = 1,
        int $leadSeconds = 0,
        int $recoverSeconds = 300,
        ?string $token = null,
        ?TestTelemetry $telemetry = null,
        ?int $leaseSeconds = null,
    ): Scheduler {
        $rows = [];
        foreach (array_keys($triggers) as $id) {
            $rows[] = new Row((string) $id, '1');
        }

        $scheduler = new Scheduler(
            source: new SnapshotSource(
                snapshot: fn(): array => $rows,
                make: fn(Row $row): Entry => new Entry($triggers[$row->id]),
            ),
            store: $store ?? new MemoryStore(),
            tickSeconds: $tickSeconds,
            leadSeconds: $leadSeconds,
            recoverSeconds: $recoverSeconds,
            leaseSeconds: $leaseSeconds ?? max(60, ($tickSeconds + $leadSeconds) * 4),
            token: $token,
            clock: $clock,
            telemetry: $telemetry ?? new \Utopia\Telemetry\Adapter\None(),
        );
        $scheduler->reconcile();

        return $scheduler;
    }

    private function emptySource(): Source
    {
        return new SnapshotSource(snapshot: fn(): array => [], make: fn(Row $row): Entry => new Entry(new Interval(60)));
    }
}
