<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Message;
use Utopia\NATS\Subscription;

/**
 * A sync subscription whose pending queue is never drained must not grow without
 * bound: once its message/byte limit is exceeded it drops and signals instead.
 */
final class SubscriptionSlowConsumerTest extends TestCase
{
    public function testExceedingMessageLimitSignalsAndDrops(): void
    {
        $signaled = [];
        $sub = new Subscription(
            sid: '1',
            subject: 'foo',
            pendingMsgsLimit: 3,
            pendingBytesLimit: 1_000_000,
            onSlowConsumer: function (Subscription $s) use (&$signaled): void {
                $signaled[] = $s->sid;
            },
        );

        for ($i = 0; $i < 10; $i++) {
            $sub->deliver(new Message('foo', 'x'));
        }

        $this->assertNotEmpty($signaled, 'slow consumer callback fired');
        $this->assertSame('1', $signaled[0]);
        // Queue is bounded at the limit rather than holding all 10 messages.
        $this->assertSame(3, $sub->getPendingCount());
    }

    public function testExceedingByteLimitSignals(): void
    {
        $fired = 0;
        $sub = new Subscription(
            sid: '2',
            subject: 'foo',
            pendingMsgsLimit: 1_000_000,
            pendingBytesLimit: 10,
            onSlowConsumer: function () use (&$fired): void {
                $fired++;
            },
        );

        $sub->deliver(new Message('foo', str_repeat('a', 6)));
        $this->assertSame(0, $fired);
        $this->assertSame(6, $sub->getPendingBytes());

        // Next 6 bytes would push past the 10-byte cap.
        $sub->deliver(new Message('foo', str_repeat('a', 6)));
        $this->assertSame(1, $fired);
        $this->assertSame(6, $sub->getPendingBytes(), 'over-limit message dropped');
        $this->assertSame(1, $sub->getPendingCount());
    }

    public function testDrainingResetsSignalAndBytes(): void
    {
        $fired = 0;
        $sub = new Subscription(
            sid: '3',
            subject: 'foo',
            pendingMsgsLimit: 2,
            pendingBytesLimit: 1_000_000,
            onSlowConsumer: function () use (&$fired): void {
                $fired++;
            },
        );

        $sub->deliver(new Message('foo', 'aa'));
        $sub->deliver(new Message('foo', 'bb'));
        $sub->deliver(new Message('foo', 'cc')); // dropped + signal
        $this->assertSame(1, $fired);

        // Drain one, freeing a slot; the signal latch resets so a later overflow re-fires.
        $sub->nextMessage(0.0);
        $this->assertSame(1, $sub->getPendingCount());

        $sub->deliver(new Message('foo', 'dd')); // back to limit
        $sub->deliver(new Message('foo', 'ee')); // dropped + signal again
        $this->assertSame(2, $fired);
    }

    public function testCallbackSubscriptionsNeverQueue(): void
    {
        $received = 0;
        $sub = new Subscription(
            sid: '4',
            subject: 'foo',
            callback: function () use (&$received): void {
                $received++;
            },
            pendingMsgsLimit: 1,
            pendingBytesLimit: 1,
        );

        for ($i = 0; $i < 5; $i++) {
            $sub->deliver(new Message('foo', 'payload'));
        }

        $this->assertSame(5, $received);
        $this->assertSame(0, $sub->getPendingCount());
    }
}
