<?php

declare(strict_types=1);

namespace Utopia\Mqtt\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Mqtt\Adapter;
use Utopia\Mqtt\Adapter\Swoole\Timer;
use Utopia\Mqtt\Adapter\Swoole\Timers\NoTimer;
use Utopia\Mqtt\Connection;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\Disconnect;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;

final class ConnectionTest extends TestCase
{
    public function testUpdateExpiresAtSetsTheDeadlineToKeepAliveTimesMultiplier(): void
    {
        $connection = new Connection(1);
        $connection->keepAlive = 20;

        $connection->updateExpiresAt(1000.0, 1.5);

        $this->assertSame(1000.0 + 20 * 1.5, $connection->expiresAt);
    }

    public function testUpdateExpiresAtIsANoOpWhenKeepAliveIsDisabled(): void
    {
        $connection = new Connection(1);
        // keepAlive defaults to 0 (disabled).

        $connection->updateExpiresAt(1000.0, 1.5);

        $this->assertEqualsWithDelta(0.0, $connection->expiresAt, PHP_FLOAT_EPSILON);
    }

    public function testClientSuppliedIdIsStoredVerbatim(): void
    {
        $connection = new Connection(1);

        $connection->setClientId('device-tv');

        $this->assertSame('device-tv', $connection->getClientId());
    }

    public function testEmptyClientIdIsServerAssigned(): void
    {
        $connection = new Connection(7);

        $connection->setClientId('');

        // The observable requirement: an empty client id yields a non-empty, server-assigned one.
        $this->assertNotSame('', $connection->getClientId());
    }

    public function testAcknowledgeResolvesATrackedDelivery(): void
    {
        $connection = new Connection(1);
        $connection->track(7, 'sensors/temp', 42);

        $ack = $connection->acknowledge(7);
        $this->assertSame('sensors/temp', $ack['topic']);
        $this->assertSame(42, $ack['sequence']);
        // Nothing else in flight, so the cursor advances to the acked sequence.
        $this->assertSame(42, $ack['cursor']);
    }

    public function testAcknowledgeAdvancesCursorOnlyToTheContiguousBoundary(): void
    {
        // Deliver 5, 6, 7; ack 5 and 7 while 6 is still pending. The cursor must stop below the
        // gap (5), never jumping to the acked 7 and skipping the unacked 6. Once 6 fills the gap,
        // every delivery through 7 is acked, so the cursor advances to 7 — not the last ack (6).
        $connection = new Connection(1);
        $connection->track(1, 'topic', 5);
        $connection->track(2, 'topic', 6);
        $connection->track(3, 'topic', 7);

        $this->assertSame(5, $connection->acknowledge(1)['cursor']); // 6,7 pending -> boundary 5
        $this->assertSame(5, $connection->acknowledge(3)['cursor']); // 6 pending    -> boundary 5
        $this->assertSame(7, $connection->acknowledge(2)['cursor']); // gap filled   -> highest (7)
    }

    public function testCursorStopsAtAGapInDeliveredSequences(): void
    {
        // The broker delivered 5 and 7 but never 6. Acking both must not advance the cursor past
        // 5 — persisting 7 would skip the still-undelivered 6 on replay.
        $connection = new Connection(1);
        $connection->track(1, 'topic', 5);
        $connection->track(2, 'topic', 7);

        $this->assertSame(5, $connection->acknowledge(1)['cursor']);
        $this->assertSame(5, $connection->acknowledge(2)['cursor'], 'the gap at 6 blocks the cursor');
    }

    public function testResumeAnchorsTheCursorRegardlessOfTrackOrder(): void
    {
        // Seeded with the persisted resume cursor (4), then deliveries are tracked out of order
        // (7 before 5). Acking 7 first must not advance past the still-missing 6 — the anchor
        // comes from resume(), not from whichever sequence happened to be tracked first.
        $connection = new Connection(1);
        $connection->resume('topic', 4);
        $connection->track(1, 'topic', 7);
        $connection->track(2, 'topic', 5);

        $this->assertSame(4, $connection->acknowledge(1)['cursor'], 'acking 7 first stops below the gap');
        $this->assertSame(5, $connection->acknowledge(2)['cursor'], 'acking 5 advances the cursor to 5');
    }

    public function testAcknowledgeIsIdempotentForAnUnknownOrRepeatedAck(): void
    {
        $connection = new Connection(1);
        $connection->track(7, 'sensors/temp', 42);

        $connection->acknowledge(7);

        $this->assertNull($connection->acknowledge(7), 'a second ack for the same id resolves to nothing');
        $this->assertNull($connection->acknowledge(99), 'an ack for an untracked id resolves to nothing');
    }

    public function testDisconnectSendsAReasonCodeAndStringThenCloses(): void
    {
        $adapter = new RecordingAdapter();
        $connection = new Connection(9, $adapter);
        $connection->protocol = 5; // MQTT 5.0

        $connection->disconnect(Disconnect::NOT_AUTHORIZED, 'Session expired');

        $this->assertCount(1, $adapter->sent, 'a 5.0 client is sent one DISCONNECT');
        $packet = Packet::parse($adapter->sent[0][1]);
        $this->assertSame(Packet::DISCONNECT, $packet->type);
        $this->assertSame(Disconnect::NOT_AUTHORIZED, ord($packet->body[0]));

        [$parsed] = Properties::parse($packet->body, 1);
        $this->assertSame('Session expired', $parsed->get(Property::REASON_STRING));

        $this->assertSame([9], $adapter->closed, 'the socket is always closed');
    }

    public function testDisconnectWithoutAReasonStringSendsOnlyTheCode(): void
    {
        $adapter = new RecordingAdapter();
        $connection = new Connection(9, $adapter);
        $connection->protocol = 5;

        $connection->disconnect(Disconnect::NOT_AUTHORIZED);

        $packet = Packet::parse($adapter->sent[0][1]);
        [$parsed] = Properties::parse($packet->body, 1);
        $this->assertNull($parsed->get(Property::REASON_STRING), 'no reason string means an empty property block');
        $this->assertSame([9], $adapter->closed);
    }

    public function testDisconnectOnA311ClientClosesWithoutSendingAPacket(): void
    {
        // MQTT 3.1.1 has no server-initiated DISCONNECT, so the reason is dropped and only the
        // socket is closed.
        $adapter = new RecordingAdapter();
        $connection = new Connection(9, $adapter);
        $connection->protocol = 4;

        $connection->disconnect(Disconnect::NOT_AUTHORIZED, 'Session expired');

        $this->assertSame([], $adapter->sent, 'nothing is sent to a 3.1.1 client');
        $this->assertSame([9], $adapter->closed, 'but the socket is still closed');
    }

    public function testDisconnectWithReasonZeroClosesWithoutSendingAPacket(): void
    {
        $adapter = new RecordingAdapter();
        $connection = new Connection(9, $adapter);
        $connection->protocol = 5;

        $connection->disconnect(); // reason 0 (normal)

        $this->assertSame([], $adapter->sent, 'reason 0 sends no DISCONNECT');
        $this->assertSame([9], $adapter->closed);
    }

    public function testDisconnectClosesEvenWhenSendFails(): void
    {
        // The courtesy DISCONNECT is best-effort; a failing transport must not leak the socket.
        $adapter = new RecordingAdapter();
        $adapter->failSend = true;
        $connection = new Connection(9, $adapter);
        $connection->protocol = 5;

        try {
            $connection->disconnect(Disconnect::NOT_AUTHORIZED, 'boom');
            $this->fail('the send failure should propagate');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame([9], $adapter->closed, 'the socket is closed despite the send failure');
    }
}

/**
 * A minimal Adapter that records send() and close() calls so a Connection's outbound behaviour
 * can be asserted without a real transport.
 */
final class RecordingAdapter extends Adapter
{
    /** @var array<int, array{0: int, 1: string}> */
    public array $sent = [];

    /** @var array<int, int> */
    public array $closed = [];

    /** When true, send() throws — to prove disconnect() still closes the socket. */
    public bool $failSend = false;

    public function send(int $connection, string $message): void
    {
        if ($this->failSend) {
            throw new \RuntimeException('transport gone');
        }

        $this->sent[] = [$connection, $message];
    }

    public function close(int $connection): void
    {
        $this->closed[] = $connection;
    }

    public function onStart(callable $callback): self
    {
        return $this;
    }

    public function onWorkerStart(callable $callback): self
    {
        return $this;
    }

    public function onOpen(callable $callback): self
    {
        return $this;
    }

    public function onReceive(callable $callback): self
    {
        return $this;
    }

    public function onClose(callable $callback): self
    {
        return $this;
    }

    public function timer(): Timer
    {
        return new NoTimer();
    }

    public function start(): void
    {
    }

    public function shutdown(): void
    {
    }
}
