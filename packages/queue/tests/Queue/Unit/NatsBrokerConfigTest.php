<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\JetStream\DiscardPolicy;
use Utopia\Queue\Broker\Nats;
use Utopia\Queue\Broker\Provisioning;

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

    public function testMaxAgeMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Nats($this->neverConnect(), maxAge: 0.0);
    }

    public function testMaxMsgSizeMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Nats($this->neverConnect(), maxMsgSize: 0);
    }

    public function testMaxMsgsRejectsZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxMsgs must be a positive limit, or -1 for unlimited');
        new Nats($this->neverConnect(), maxMsgs: 0);
    }

    public function testMaxBytesRejectsValuesBelowUnlimited(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxBytes must be a positive limit, or -1 for unlimited');
        new Nats($this->neverConnect(), maxBytes: -2);
    }

    public function testUnlimitedIsAcceptedForBothSizeLimits(): void
    {
        $broker = new Nats($this->neverConnect(), maxMsgs: -1, maxBytes: -1);
        $this->assertInstanceOf(Nats::class, $broker);
    }

    public function testDiscardNewRequiresALimitToApplyTo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('discard: New requires a maxMsgs or maxBytes limit');
        new Nats($this->neverConnect(), discard: DiscardPolicy::New);
    }

    public function testDiscardNewConstructsWithALimit(): void
    {
        $broker = new Nats($this->neverConnect(), maxBytes: 1_048_576, discard: DiscardPolicy::New);
        $this->assertInstanceOf(Nats::class, $broker);
    }

    public function testMaxAckPendingMustBeAtLeastOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Nats($this->neverConnect(), maxAckPending: 0);
    }

    public function testMaxWaitingMustBeAtLeastOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Nats($this->neverConnect(), maxWaiting: 0);
    }

    public function testInactiveThresholdMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Nats($this->neverConnect(), inactiveThreshold: 0.0);
    }

    public function testSizeAndInFlightKnobsConstructTogether(): void
    {
        $broker = new Nats(
            $this->neverConnect(),
            maxAge: 3600.0,
            maxMsgSize: 262_144,
            maxMsgs: 100_000,
            maxBytes: 1_073_741_824,
            discard: DiscardPolicy::New,
            maxAckPending: 8,
            maxWaiting: 16,
            inactiveThreshold: 86_400.0,
            provisioning: Provisioning::Require,
        );
        $this->assertInstanceOf(Nats::class, $broker);
    }
}
