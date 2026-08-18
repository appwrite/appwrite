<?php

declare(strict_types=1);

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Schedule\Clock\Test as TestClock;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Source\Row;
use Utopia\Schedule\Store\Memory as MemoryStore;
use Utopia\Schedule\Trigger\Cron;
use Utopia\Schedule\Trigger\Interval;

/**
 * Exactly-once properties at production scale. Expected occurrence
 * counts are derived with plain modular arithmetic over the covered
 * span — never with the schedule classes under test — so a defect in
 * selection cannot vouch for itself.
 */
final class ReliabilityTest extends TestCase
{
    public function testTenThousandSchedulesDeliverExactlyOnceUnderTickJitter(): void
    {
        // 10,000 schedules whose slots all reduce to "timestamp ≡ r (mod m)".
        $classes = [
            // [count, modulus, remainder, schedule factory]
            [2000, 60, 0, fn(): Interval => new Interval(60)],
            [2000, 120, 0, fn(): Interval => new Interval(120)],
            [2000, 300, 0, fn(): Interval => new Interval(300)],
            [2000, 900, 0, fn(): Interval => new Interval(900)],
            [500, 300, 0, fn(): Cron => new Cron('*/5 * * * *')],
            [500, 900, 0, fn(): Cron => new Cron('*/15 * * * *')],
            [500, 3600, 0, fn(): Cron => new Cron('0 * * * *')],
            [500, 3600, 1800, fn(): Cron => new Cron('30 * * * *')],
        ];

        $rows = [];
        $triggers = [];
        $classOf = [];
        foreach ($classes as $index => [$count, $modulus, $remainder, $factory]) {
            $trigger = $factory(); // schedules are stateless: one instance serves its class
            for ($i = 0; $i < $count; ++$i) {
                $id = "s{$index}-{$i}";
                $rows[] = new Row($id, 'v1');
                $triggers[$id] = $trigger;
                $classOf[$id] = $index;
            }
        }

        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 13:20:30.250000'));
        $scheduler = new Scheduler(
            source: new SnapshotSource(
                snapshot: fn(): array => $rows,
                make: fn(Row $row): Entry => new Entry($triggers[$row->id]),
            ),
            store: new MemoryStore(),
            tickSeconds: 60,
            leaseSeconds: 600,
            clock: $clock,
        );
        $scheduler->reconcile();

        // Thirteen ticks a minute apart with deterministic sub-second
        // jitter: the loop resumes early and late, and the phase wanders
        // across second boundaries — the shape that broke "now"-based
        // selection in production.
        $deliveredPerClass = array_fill(0, \count($classes), 0);
        $seen = [];
        $duplicates = 0;
        $tickTimes = [];

        for ($tick = 0; $tick <= 12; ++$tick) {
            $tickTimes[] = (float) $clock->now()->format('U.u');

            foreach ($scheduler->tick() as $occurrence) {
                $key = $occurrence->id . '@' . $occurrence->due->format('U.u');
                if (isset($seen[$key])) {
                    ++$duplicates;
                }
                $seen[$key] = true;
                ++$deliveredPerClass[$classOf[$occurrence->id]];
            }

            $scheduler->commit();
            $clock->advance(60.0 + (($tick * 37) % 17) / 20.0 - 0.4);
        }

        $this->assertSame(0, $duplicates);

        // Covered span: [first tick, last tick), half-open. Slots are
        // whole seconds, so integer bounds are exact: a slot equal to the
        // span start is covered, one equal to the span end is not.
        $firstSlot = (int) ceil($tickTimes[0]);
        $lastSlot = (int) ceil($tickTimes[12]) - 1;

        foreach ($classes as $index => [$count, $modulus, $remainder]) {
            $slots = 0;
            for ($slot = $firstSlot; $slot <= $lastSlot; ++$slot) {
                if ($slot % $modulus === $remainder) {
                    ++$slots;
                }
            }

            $this->assertSame(
                $slots * $count,
                $deliveredPerClass[$index],
                "class {$index} (mod {$modulus}, r {$remainder}): expected {$slots} slots × {$count} schedules",
            );
        }
    }

    public function testReconcilingTenThousandRowsIsVersionGatedAndConverges(): void
    {
        $rows = [];
        for ($i = 0; $i < 10000; ++$i) {
            $rows["r{$i}"] = new Row("r{$i}", 'v1');
        }

        $set = new RowSet(array_values($rows));
        $made = new class {
            public int $count = 0;
        };

        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 13:20:30.000000'));
        $scheduler = new Scheduler(
            source: new SnapshotSource(
                snapshot: $set->list(...),
                make: function (Row $row) use ($made): Entry {
                    ++$made->count;

                    return new Entry(new Interval(60));
                },
            ),
            store: new MemoryStore(),
            clock: $clock,
        );

        $scheduler->reconcile();
        $this->assertSame(10000, $made->count);

        // An unchanged snapshot costs string compares only.
        $scheduler->reconcile();
        $this->assertSame(10000, $made->count);

        // 100 updated, 50 removed, 50 added: exactly 150 rows re-made.
        for ($i = 0; $i < 100; ++$i) {
            $rows["r{$i}"] = new Row("r{$i}", 'v2');
        }
        for ($i = 100; $i < 150; ++$i) {
            unset($rows["r{$i}"]);
        }
        for ($i = 0; $i < 50; ++$i) {
            $rows["new{$i}"] = new Row("new{$i}", 'v1');
        }
        $set->rows = array_values($rows);

        $scheduler->reconcile();
        $this->assertSame(10150, $made->count);

        // The map converged: exactly the desired 10,000 ids fire.
        $scheduler->tick();
        $scheduler->commit();
        $clock->advance(60.0);

        $delivered = [];
        foreach ($scheduler->tick() as $occurrence) {
            $delivered[$occurrence->id] = true;
        }

        $this->assertCount(10000, $delivered);
        $this->assertArrayHasKey('r99', $delivered);
        $this->assertArrayHasKey('new49', $delivered);
        $this->assertArrayNotHasKey('r100', $delivered);
        $this->assertArrayNotHasKey('r149', $delivered);
    }

    public function testLeaderlessGapIsRecoveredExactlyOnceAfterTakeover(): void
    {
        $store = new MemoryStore();
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 12:00:30.000000'));

        $rows = [];
        for ($i = 0; $i < 50; ++$i) {
            $rows[] = new Row("s{$i}", 'v1');
        }
        $build = fn(string $token): Scheduler => new Scheduler(
            source: new SnapshotSource(
                snapshot: fn(): array => $rows,
                make: fn(Row $row): Entry => new Entry(new Interval(60)),
            ),
            store: $store,
            tickSeconds: 60,
            recoverSeconds: 600,
            leaseSeconds: 240,
            token: $token,
            clock: $clock,
        );

        $a = $build('a');
        $b = $build('b');
        $a->reconcile();
        $b->reconcile();

        $slotCounts = [];
        $deliver = function (Scheduler $scheduler) use (&$slotCounts): int {
            $occurrences = $scheduler->tick();
            foreach ($occurrences as $occurrence) {
                $key = $occurrence->id . '@' . $occurrence->due->format('U');
                $slotCounts[$key] = ($slotCounts[$key] ?? 0) + 1;
            }
            $scheduler->commit();

            return \count($occurrences);
        };

        // Rounds at 12:00:30 … 12:09:30: A leads; B contends every round
        // and gets nothing.
        for ($round = 0; $round < 10; ++$round) {
            $deliver($a);
            $this->assertSame(0, $deliver($b), 'a follower must not dispatch against a fresh claim');
            $clock->advance(60.0);
        }

        // Five minutes with no leader: A stalled after its 12:09:30
        // commit, and its claim (lease 240) expires during the gap.
        $clock->advance(4 * 60.0); // plus round 9's own advance = a 300s gap

        // Rounds at 12:14:30 … 12:19:30: B takes over; its first tick
        // recovers the whole leaderless gap from A's committed watermark.
        for ($round = 0; $round < 6; ++$round) {
            $deliver($b);
            $this->assertSame(0, $deliver($a), 'the deposed leader must not dispatch');
            $clock->advance(60.0);
        }

        // Every minute slot in [12:00:30, 12:19:30) — the union of both
        // leaders' windows — was delivered exactly once per schedule,
        // including the five minutes nobody was leading.
        $expected = 0;
        $spanStart = (new \DateTimeImmutable('2026-08-18 12:00:30'))->getTimestamp();
        $spanEnd = (new \DateTimeImmutable('2026-08-18 12:19:30'))->getTimestamp();
        for ($slot = $spanStart; $slot < $spanEnd; ++$slot) {
            if ($slot % 60 === 0) {
                ++$expected;
            }
        }

        $this->assertSame(19, $expected);
        $this->assertSame($expected * 50, array_sum($slotCounts));
        $this->assertSame([1], array_values(array_unique($slotCounts)), 'no slot may deliver twice');
    }
}
