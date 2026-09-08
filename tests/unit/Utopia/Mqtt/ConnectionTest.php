<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Mqtt;

use Appwrite\Extend\Exception;
use PHPUnit\Framework\TestCase;
use Utopia\Mqtt\Connection;

final class ConnectionTest extends TestCase
{
    public function testCleanStartDefaultsToTrue(): void
    {
        $connection = new Connection(1);

        $this->assertTrue($connection->cleanStart);
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

        $this->assertSame(['topic' => 'appwrite/push/user-1', 'sequence' => 42], $connection->acknowledge(7));
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
