<?php

declare(strict_types=1);

namespace Tests\Unit\Messaging;

use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Messaging\Connections;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Messaging\Fakes\FailingPubSub;
use Tests\Unit\Messaging\Fakes\RecordingPubSub;
use Tests\Unit\Messaging\Fakes\RecordingRealtime;
use Utopia\Database\Document;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

final class ConnectionsTest extends TestCase
{
    private Document $project;

    protected function setUp(): void
    {
        $this->project = new Document(['$id' => 'project1']);
    }

    public function testRevoke(): void
    {
        $pubsub = new RecordingPubSub();
        $realtime = new RecordingRealtime();
        $connections = new Connections(new Mqtt(new NoTelemetry(), $pubsub), $realtime);

        $connections->revoke($this->project, 'user1');

        $this->assertSame([[
            'channel' => 'mqtt',
            'message' => ['action' => Mqtt::ACTION_REVOKE, 'project' => 'project1', 'userId' => 'user1'],
        ]], $pubsub->published);
        // Realtime connections are left to the request's own event
        $this->assertSame([], $realtime->triggered);
    }

    public function testClose(): void
    {
        $pubsub = new RecordingPubSub();
        $realtime = new RecordingRealtime();
        $connections = new Connections(new Mqtt(new NoTelemetry(), $pubsub), $realtime);

        $connections->close($this->project, 'user1');

        $this->assertSame([[
            'channel' => 'mqtt',
            'message' => ['action' => Mqtt::ACTION_REVOKE, 'project' => 'project1', 'userId' => 'user1'],
        ]], $pubsub->published);
        $this->assertSame([[
            'project' => 'project1',
            'event' => 'users.[userId].sessions.delete',
            'params' => ['userId' => 'user1'],
            'payload' => [],
        ]], $realtime->triggered);
    }

    public function testCloseClearsEarlierState(): void
    {
        $realtime = new RecordingRealtime();
        $realtime
            ->setParam('sessionId', 'session1')
            ->setPayload(['secret' => 'value']);
        $connections = new Connections(new Mqtt(new NoTelemetry(), new RecordingPubSub()), $realtime);

        $connections->close($this->project, 'user1');

        $this->assertSame(['userId' => 'user1'], $realtime->triggered[0]['params']);
        $this->assertSame([], $realtime->triggered[0]['payload']);
    }

    public function testCloseWithoutMqtt(): void
    {
        $realtime = new RecordingRealtime();
        $connections = new Connections(new Mqtt(new NoTelemetry(), new FailingPubSub()), $realtime);

        $connections->close($this->project, 'user1');

        $this->assertCount(1, $realtime->triggered);
    }
}
