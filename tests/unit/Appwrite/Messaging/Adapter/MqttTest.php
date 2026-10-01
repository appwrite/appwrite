<?php

namespace Tests\Unit\Appwrite\Messaging\Adapter;

use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\PubSub\Adapter as PubSub;
use PHPUnit\Framework\TestCase;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

class MqttTest extends TestCase
{
    private function adapter(): Mqtt
    {
        return new Mqtt(new NoTelemetry(), new class () implements PubSub {
            public function ping($message = null): bool
            {
                return true;
            }

            public function subscribe($channels, $callback)
            {
            }

            public function publish($channel, $message)
            {
            }
        });
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

        // A second flush with nothing recorded in between returns an empty set.
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
}
