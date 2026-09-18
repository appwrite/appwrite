<?php

declare(strict_types=1);

namespace Utopia\Mqtt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Mqtt\Connection;

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
}
