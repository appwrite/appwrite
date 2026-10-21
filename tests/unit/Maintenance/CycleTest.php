<?php

declare(strict_types=1);

namespace Tests\Unit\Maintenance;

use Appwrite\Maintenance\Cycle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Schedule\Clock\Test as TestClock;
use Utopia\Schedule\Store\Memory as MemoryStore;
use Utopia\Telemetry\Adapter\Test as TestTelemetry;

final class CycleTest extends TestCase
{
    /**
     * @return \Iterator<string, array{string, int, int, int}>
     */
    public static function periods(): \Iterator
    {
        yield 'week' => ['604800', 300, 2016, 604800];
        yield 'one interval' => ['300', 300, 1, 300];
        yield 'under two intervals' => ['599', 300, 1, 300];
        yield 'under one interval' => ['30', 30, 1, 30];
        yield 'one second' => ['1', 1, 1, 1];
        yield 'not a multiple of the interval' => ['1000', 300, 3, 900];
    }

    #[DataProvider('periods')]
    public function testPeriodSplitsIntoSlicesOfAtMostOneInterval(string $period, int $interval, int $slots, int $seconds): void
    {
        $cycle = Cycle::parse($period);

        $this->assertSame($interval, $cycle->interval);
        $this->assertSame($slots, $cycle->slots);
        $this->assertSame($seconds, $cycle->seconds());
    }

    public function testLagStaysUnderASecondWhileSlotsComeOncePerInterval(): void
    {
        $start = new \DateTimeImmutable('2026-03-02 06:00:00', new \DateTimeZone('UTC'))->getTimestamp();
        $clock = new TestClock(new \DateTimeImmutable('@' . ($start - 30)));
        $telemetry = new TestTelemetry();
        $cycle = new Cycle(604800);
        $scheduler = $cycle->scheduler(new MemoryStore(), telemetry: $telemetry, clock: $clock);

        $dues = [];
        $scheduler->run(function (array $occurrences) use (&$dues, $scheduler, $start): void {
            foreach ($occurrences as $occurrence) {
                $dues[] = $occurrence->due->getTimestamp();
            }
            if (\end($dues) >= $start + 3600) {
                $scheduler->stop();
            }
        });

        $this->assertSame(\range($start, $start + 3600, 300), $dues);
        $this->assertSame(
            \array_map(fn (int $due): int => \intdiv($due, 300) % 2016, $dues),
            \array_map($cycle->slot(...), $dues),
        );

        /** @var list<float|int> $lags */
        $lags = \get_object_vars($telemetry->gauges['schedule.lag'])['values'];
        $this->assertLessThanOrEqual(1.0, \max($lags));
        // Reported every second, so a series alerting on it never goes missing between slots.
        $this->assertGreaterThan(3500, \count($lags));

        /** @var list<float|int> $delays */
        $delays = \get_object_vars($telemetry->histograms['schedule.dispatch.delay'])['values'];
        $this->assertCount(13, $delays);
        $this->assertLessThanOrEqual(1.0, \max($delays));
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function invalid(): \Iterator
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-300'];
        yield 'fraction' => ['300.5'];
        yield 'words' => ['weekly'];
        yield 'unit suffix' => ['300s'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalid')]
    public function testInvalidPeriodIsRefused(string $period): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Cycle::parse($period);
    }
}
