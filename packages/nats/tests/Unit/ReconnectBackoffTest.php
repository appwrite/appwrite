<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;

/**
 * Covers the reconnect backoff growth/cap and the reconnect buffer cap logic.
 */
final class ReconnectBackoffTest extends TestCase
{
    public function testFirstAttemptIsImmediate(): void
    {
        $this->assertEqualsWithDelta(0.0, Connection::reconnectBackoff(0, 2.0, 30.0), PHP_FLOAT_EPSILON);
    }

    public function testBackoffGrowsExponentially(): void
    {
        // base 2.0, factor 2.0: 2, 4, 8, 16 ...
        $this->assertEqualsWithDelta(2.0, Connection::reconnectBackoff(1, 2.0, 100.0), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(4.0, Connection::reconnectBackoff(2, 2.0, 100.0), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(8.0, Connection::reconnectBackoff(3, 2.0, 100.0), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(16.0, Connection::reconnectBackoff(4, 2.0, 100.0), PHP_FLOAT_EPSILON);
    }

    public function testBackoffIsCapped(): void
    {
        $cap = 8.0;
        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $this->assertLessThanOrEqual($cap, Connection::reconnectBackoff($attempt, 2.0, $cap));
        }

        // Well past the cap it stays pinned.
        $this->assertSame($cap, Connection::reconnectBackoff(10, 2.0, $cap));
    }

    public function testCustomFactor(): void
    {
        $this->assertEqualsWithDelta(1.0, Connection::reconnectBackoff(1, 1.0, 100.0, 3.0), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(3.0, Connection::reconnectBackoff(2, 1.0, 100.0, 3.0), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(9.0, Connection::reconnectBackoff(3, 1.0, 100.0, 3.0), PHP_FLOAT_EPSILON);
    }

    public function testBufferAcceptsUntilCap(): void
    {
        $cap = 100;

        $this->assertTrue(Connection::reconnectBufferAccepts(0, 50, $cap));
        $this->assertTrue(Connection::reconnectBufferAccepts(50, 50, $cap), 'exactly at cap fits');
        $this->assertFalse(Connection::reconnectBufferAccepts(50, 51, $cap), 'one byte over cap');
        $this->assertFalse(Connection::reconnectBufferAccepts(100, 1, $cap));
    }

    public function testZeroCapDisablesBuffering(): void
    {
        $this->assertFalse(Connection::reconnectBufferAccepts(0, 1, 0));
        $this->assertFalse(Connection::reconnectBufferAccepts(0, 1, -5));
    }
}
