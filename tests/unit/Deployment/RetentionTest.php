<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use Appwrite\Deployment\Retention;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RetentionTest extends TestCase
{
    public function testRequestedDaysWinOverTheDefault(): void
    {
        $this->assertSame(7, (new Retention(days: 30))->resolve(7));
    }

    public function testDefaultAppliesWhenNothingIsRequested(): void
    {
        $this->assertSame(30, (new Retention(days: 30))->resolve(0));
    }

    public function testRequestedDaysAreClampedToTheMaximum(): void
    {
        $this->assertSame(30, (new Retention(days: 7, maxDays: 30))->resolve(180));
    }

    public function testRequestedDaysWithinTheMaximumAreKept(): void
    {
        $this->assertSame(20, (new Retention(days: 7, maxDays: 30))->resolve(20));
    }

    public function testZeroMaximumLeavesRequestedDaysUnlimited(): void
    {
        $this->assertSame(3650, (new Retention(days: 30, maxDays: 0))->resolve(3650));
    }

    public function testAllZerosKeepDeploymentsForever(): void
    {
        $this->assertSame(0, (new Retention())->resolve(0));
    }

    public function testMaximumAppliesWhenNeitherRequestNorDefaultIsSet(): void
    {
        $this->assertSame(30, (new Retention(days: 0, maxDays: 30))->resolve(0));
    }

    public function testDefaultAboveTheMaximumIsClamped(): void
    {
        $this->assertSame(30, (new Retention(days: 90, maxDays: 30))->resolve(0));
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function negativeValues(): iterable
    {
        yield 'days' => [-1, 0, 0];
        yield 'maxDays' => [0, -1, 0];
        yield 'maxCount' => [0, 0, -1];
    }

    #[DataProvider('negativeValues')]
    public function testNegativeValuesAreRefused(int $days, int $maxDays, int $maxCount): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Retention(days: $days, maxDays: $maxDays, maxCount: $maxCount);
    }
}
