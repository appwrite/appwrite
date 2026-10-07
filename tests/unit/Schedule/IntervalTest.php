<?php

declare(strict_types=1);

namespace Tests\Unit\Schedule;

use Appwrite\Schedule\Interval;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IntervalTest extends TestCase
{
    /**
     * @return \Iterator<string, array{string, int}>
     */
    public static function intervals(): \Iterator
    {
        yield 'minute' => ['1m', 60];
        yield 'five minutes' => ['5m', 300];
        yield 'fifteen minutes' => ['15m', 900];
        yield 'thirty minutes' => ['30m', 1800];
        yield 'hour' => ['1h', 3600];
        yield 'six hours' => ['6h', 21600];
        yield 'twelve hours' => ['12h', 43200];
        yield 'day' => ['1d', 86400];
    }

    #[DataProvider('intervals')]
    public function testSeconds(string $value, int $seconds): void
    {
        $this->assertSame($seconds, Interval::from($value)->seconds());
    }

    public function testValuesCoverEveryCase(): void
    {
        $this->assertSame(['1m', '5m', '15m', '30m', '1h', '6h', '12h', '1d'], Interval::values());
        $this->assertSame('OneHour', Interval::names()['1h']);
    }
}
