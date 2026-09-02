<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\Queue\Broker\Nats;

/**
 * Constructor validation for the JetStream knob coupling. The Closure source is
 * never invoked: validation must fail at construction, before any connection.
 */
final class NatsBrokerConfigTest extends TestCase
{
    private function neverConnect(): \Closure
    {
        return static fn(): Connection => throw new \LogicException('constructor validation must not connect');
    }

    public function testBackoffMustBeNonEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Nats($this->neverConnect(), backoff: []);
    }

    public function testBackoffEntriesMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Nats($this->neverConnect(), ackWait: 30.0, backoff: [30.0, 0.0]);
    }

    public function testFirstBackoffEntryMustEqualAckWait(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Nats($this->neverConnect(), ackWait: 30.0, backoff: [10.0, 60.0]);
    }

    public function testMaxDeliverMustExceedBackoffCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Nats($this->neverConnect(), ackWait: 10.0, maxDeliver: 2, backoff: [10.0, 30.0]);
    }

    public function testDeadMaxAgeMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Nats($this->neverConnect(), deadMaxAge: 0.0);
    }

    public function testValidBackoffConstructs(): void
    {
        $broker = new Nats($this->neverConnect(), ackWait: 10.0, maxDeliver: 5, backoff: [10.0, 30.0, 120.0], deadMaxAge: 604800.0);
        $this->assertInstanceOf(Nats::class, $broker);
    }

    public function testDuplicateWindowMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicateWindow must be a positive number of seconds');
        new Nats($this->neverConnect(), duplicateWindow: 0.0);
    }

    public function testDuplicateWindowRejectsNegativeValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Nats($this->neverConnect(), duplicateWindow: -1.0);
    }
}
