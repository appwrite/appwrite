<?php

declare(strict_types=1);

namespace Tests\Unit\Appwrite\Messaging\Adapter;

use Appwrite\Messaging\Adapter\Mqtt;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Appwrite\Messaging\Adapter\Fakes\RecordingPubSub;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

final class MqttTest extends TestCase
{
    private RecordingPubSub $pubsub;

    protected function setUp(): void
    {
        $this->pubsub = new RecordingPubSub();
    }

    private function adapter(): Mqtt
    {
        return new Mqtt(new NoTelemetry(), $this->pubsub);
    }

    public function testAccumulatesPerProjectAndKeepsProjectsIsolated(): void
    {
        $mqtt = $this->adapter();

        $mqtt->recordConnection('project-a');
        $mqtt->recordConnection('project-a');
        $mqtt->recordDeliveries('project-a', 3);
        $mqtt->recordDeliveries('project-a', 2);
        $mqtt->recordDeliveries('project-b', 5);

        $usage = $mqtt->flushUsage();

        $this->assertSame(2, $usage['project-a']['connections']);
        $this->assertSame(5, $usage['project-a']['delivered']);
        $this->assertArrayNotHasKey('connections', $usage['project-b']);
        $this->assertSame(5, $usage['project-b']['delivered']);
    }

    public function testFlushDrainsAndResets(): void
    {
        $mqtt = $this->adapter();

        $mqtt->recordDeliveries('project-a', 4);
        $this->assertSame(4, $mqtt->flushUsage()['project-a']['delivered']);

        $this->assertSame([], $mqtt->flushUsage());
    }

    public function testIgnoresEmptyProjectAndNonPositiveCounts(): void
    {
        $mqtt = $this->adapter();

        $mqtt->recordConnection('');
        $mqtt->recordDeliveries('', 3);
        $mqtt->recordDeliveries('project-a', 0);
        $mqtt->recordDeliveries('project-a', -2);

        $this->assertSame([], $mqtt->flushUsage());
    }

    public function testRevokePublishesControlEvent(): void
    {
        $mqtt = $this->adapter();

        $mqtt->revoke('project-a', 'user-a');

        $this->assertSame([[
            'channel' => 'mqtt',
            'message' => '{"action":"revoke","project":"project-a","userId":"user-a"}',
        ]], $this->pubsub->published);
    }
}
