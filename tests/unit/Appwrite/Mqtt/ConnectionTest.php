<?php

declare(strict_types=1);

namespace Tests\Unit\Appwrite\Mqtt;

use Appwrite\Extend\Exception;
use Appwrite\Mqtt\Connection;
use Appwrite\Mqtt\KeepAlive;
use PHPUnit\Framework\TestCase;

final class ConnectionTest extends TestCase
{
    public function testCleanStartDefaultsToTrue(): void
    {
        $connection = new Connection(1);

        $this->assertTrue($connection->cleanStart);
    }

    public function testTouchSetsTheDeadlineToKeepAliveTimesMultiplier(): void
    {
        $connection = new Connection(1);
        $connection->keepAlive = 20;

        $connection->updateExpiresAt(1000.0);

        $this->assertSame(1000.0 + 20 * KeepAlive::MULTIPLIER, $connection->expiresAt);
    }

    public function testTouchIsANoOpWhenKeepAliveIsDisabled(): void
    {
        $connection = new Connection(1);
        // keepAlive defaults to 0 (disabled).

        $connection->updateExpiresAt(1000.0);

        $this->assertEqualsWithDelta(0.0, $connection->expiresAt, PHP_FLOAT_EPSILON);
    }

    public function testClientSuppliedIdIsStoredVerbatim(): void
    {
        $connection = new Connection(1);
        $connection->identity = ['userId' => 'user-1'];
        $connection->projectId = 'project-1';

        $connection->setClientId('device-tv');

        $this->assertSame('device-tv', $connection->getClientId());
    }

    public function testEmptyIdDerivesAccountLevelAnchor(): void
    {
        $connection = new Connection(1);
        $connection->identity = ['userId' => 'user-1'];
        $connection->projectId = 'project-1';

        $connection->setClientId('');

        $this->assertSame('custom_project-1_user-1', $connection->getClientId());
    }

    public function testSetClientIdBeforeIdentityIsRejected(): void
    {
        $connection = new Connection(1);

        try {
            $connection->setClientId('device-tv');
            $this->fail('setClientId must reject a connection with no resolved identity');
        } catch (Exception $e) {
            $this->assertSame(Exception::USER_UNAUTHORIZED, $e->getType());
        }
    }

    public function testAcknowledgeResolvesATrackedDelivery(): void
    {
        $connection = new Connection(1);
        $connection->track(7, 'appwrite/push/user-1', 42);

        $ack = $connection->acknowledge(7);
        $this->assertSame('appwrite/push/user-1', $ack['topic']);
        $this->assertSame(42, $ack['sequence']);
        // Nothing else in flight, so the cursor advances to the acked sequence.
        $this->assertSame(42, $ack['cursor']);
    }

    public function testAcknowledgeAdvancesCursorOnlyToTheContiguousBoundary(): void
    {
        // Deliver 5, 6, 7; ack 5 and 7 while 6 is still pending. The cursor must stop
        // below the gap (5), never jumping to the acked 7 and skipping the unacked 6.
        $connection = new Connection(1);
        $connection->track(1, 'topic', 5);
        $connection->track(2, 'topic', 6);
        $connection->track(3, 'topic', 7);

        $this->assertSame(5, $connection->acknowledge(1)['cursor']); // 6,7 pending -> boundary 5
        $this->assertSame(5, $connection->acknowledge(3)['cursor']); // 6 pending    -> boundary 5
        $this->assertSame(6, $connection->acknowledge(2)['cursor']); // none pending -> this ack (6)
    }

    public function testAcknowledgeIsIdempotentForAnUnknownOrRepeatedAck(): void
    {
        $connection = new Connection(1);
        $connection->track(7, 'appwrite/push/user-1', 42);

        $connection->acknowledge(7);

        $this->assertNull($connection->acknowledge(7), 'a second ack for the same id resolves to nothing');
        $this->assertNull($connection->acknowledge(99), 'an ack for an untracked id resolves to nothing');
    }
}
