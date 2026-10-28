<?php

declare(strict_types=1);

namespace Utopia\Mqtt\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\Disconnect;
use Utopia\Mqtt\Packet\Specs\V3;
use Utopia\Mqtt\Packet\Specs\V5;
use Utopia\Mqtt\Server;

final class ServerTest extends TestCase
{
    public function testSubscribeWithinTheFilterLimitReachesTheHandler(): void
    {
        $adapter = new RecordingAdapter();
        $handler = new AcceptingHandler();
        (new Server($adapter, $handler, maxFilters: 2))->start();

        $adapter->deliver(9, V5::connect('client-9'), V5::subscribe(1, ['a', 'b'], Packet::QOS_1));

        // Test for SUCCESS
        $this->assertSame(1, $handler->subscribes);
        $this->assertSame(Packet::SUBACK, Packet::parse($adapter->sent[1][1])->type);
        $this->assertSame([], $adapter->closed);
    }

    public function testSubscribeOverTheFilterLimitIsRefusedBeforeTheHandler(): void
    {
        $adapter = new RecordingAdapter();
        $handler = new AcceptingHandler();
        (new Server($adapter, $handler, maxFilters: 2))->start();

        $adapter->deliver(9, V5::connect('client-9'), V5::subscribe(1, ['a', 'b', 'c'], Packet::QOS_1));

        // Test for FAILURE
        $this->assertSame(0, $handler->subscribes, 'an oversized SUBSCRIBE reached the handler');
        $this->assertCount(2, $adapter->sent, 'expected a CONNACK then a DISCONNECT');
        $disconnect = Packet::parse($adapter->sent[1][1]);
        $this->assertSame(Packet::DISCONNECT, $disconnect->type);
        $this->assertSame(Disconnect::QUOTA_EXCEEDED, \ord($disconnect->body[0]));
        $this->assertSame([9], $adapter->closed);
    }

    public function testSubscribeOverTheFilterLimitOnA311ClientClosesWithoutAPacket(): void
    {
        $adapter = new RecordingAdapter();
        $handler = new AcceptingHandler();
        (new Server($adapter, $handler, maxFilters: 2))->start();

        $adapter->deliver(9, V3::connect('client-9'), V3::subscribe(1, ['a', 'b', 'c'], Packet::QOS_1));

        // Test for FAILURE
        $this->assertSame(0, $handler->subscribes);
        $this->assertCount(1, $adapter->sent, 'only the CONNACK is sent to a 3.1.1 client');
        $this->assertSame([9], $adapter->closed);
    }
}
