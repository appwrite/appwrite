<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt;

use Appwrite\Mqtt\Clock;
use PHPUnit\Framework\TestCase;

/**
 * The access clock: a change set() while an operation is in flight drifts past the reading that
 * operation took at start(); a change outside any in-flight window is dropped so the map cannot grow.
 */
final class ClockTest extends TestCase
{
    private const PROJECT = 'project1';

    private const USER = 'user1';

    public function testDetectsAChangeThatDriftsInAfterTheReading(): void
    {
        $clock = new Clock();

        $reading = $clock->start();
        $clock->set(self::PROJECT, self::USER);

        $this->assertTrue($clock->isDrifted(self::PROJECT, self::USER, $reading));
        $clock->stop();
    }

    public function testQuietOperationDoesNotDrift(): void
    {
        $clock = new Clock();

        $reading = $clock->start();

        $this->assertFalse($clock->isDrifted(self::PROJECT, self::USER, $reading));
        $clock->stop();
    }

    public function testChangeIsScopedToItsIdentity(): void
    {
        $clock = new Clock();

        $reading = $clock->start();
        $clock->set(self::PROJECT, 'other');

        $this->assertFalse($clock->isDrifted(self::PROJECT, self::USER, $reading));
        $clock->stop();
    }

    public function testChangeOutsideAnyOperationIsDropped(): void
    {
        $clock = new Clock();

        $clock->set(self::PROJECT, self::USER); // nothing in flight to observe it

        $reading = $clock->start();
        $this->assertFalse($clock->isDrifted(self::PROJECT, self::USER, $reading), 'a change before the operation began counted as a drift');
        $clock->stop();
    }

    public function testTicksClearOnceTheLastOperationStops(): void
    {
        $clock = new Clock();

        $outer = $clock->start();
        $inner = $clock->start();
        $clock->set(self::PROJECT, self::USER);
        $clock->stop(); // inner leaves, but the outer operation still holds the ticks

        $this->assertTrue($clock->isDrifted(self::PROJECT, self::USER, $outer));

        $clock->stop(); // last operation leaves

        $this->assertFalse($clock->isDrifted(self::PROJECT, self::USER, $clock->start()), 'ticks survived the last operation');
        $clock->stop();
    }
}
