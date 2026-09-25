<?php

declare(strict_types=1);

namespace Utopia\Schedule\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Schedule\Clock\Test as TestClock;
use Utopia\Schedule\Occurrence;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Source\Row;
use Utopia\Schedule\Store\Memory;
use Utopia\Schedule\Trigger\Cron;
use Utopia\Schedule\Trigger\Interval;

final class ReadinessTest extends TestCase
{
    public function testAnEmptySourceIsReadyAfterLoading(): void
    {
        $scheduler = new Scheduler(
            source: new SnapshotSource(fn (): array => [], fn (Row $row): Entry => new Entry(new Cron('* * * * *'))),
        );
        $this->assertFalse($scheduler->isReady());
        $scheduler->reconcile();
        $this->assertTrue($scheduler->isReady());
    }

    public function testFailedInitializationNeverClaimsOrAdvancesCoverage(): void
    {
        $store = new Memory();
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:00'));
        $healthy = new Scheduler(
            source: new SnapshotSource(fn (): array => [new Row('job', 'v1')], fn (Row $row): Entry => new Entry(new Interval(1))),
            store: $store,
            clock: $clock,
        );
        $healthy->reconcile();
        $recovered = [];
        $attempts = 0;
        $scheduler = new Scheduler(
            source: new SnapshotSource(fn (): never => throw new \RuntimeException('source unavailable'), fn (Row $row): Entry => new Entry(new Cron('* * * * *'))),
            store: $store,
            clock: $clock,
            onError: function () use (&$scheduler, &$attempts, &$recovered, $healthy, $clock): void {
                $healthy->tick();
                $healthy->commit();
                $clock->advance(1);
                $recovered = array_merge($recovered, $healthy->tick());
                $healthy->commit();
                if (++$attempts === 2) {
                    $scheduler?->stop();
                }
            },
        );
        $delivered = [];
        $scheduler->run(function (array $batch) use (&$delivered): void {
            $delivered = array_merge($delivered, $batch);
        });
        $this->assertFalse($scheduler->isReady());
        $this->assertSame(['03:00:00', '03:00:10'], array_map(
            fn (Occurrence $occurrence): string => $occurrence->due->format('H:i:s'),
            $recovered,
        ), 'a healthy scheduler dispatches while the other keeps failing to initialize');
        $this->assertSame([], $delivered);
    }
}
