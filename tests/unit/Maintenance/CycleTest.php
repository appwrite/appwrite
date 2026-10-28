<?php

declare(strict_types=1);

namespace Tests\Unit\Maintenance;

use Appwrite\Maintenance\Cycle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
