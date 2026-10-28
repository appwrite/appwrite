<?php

declare(strict_types=1);

namespace Utopia\Mqtt\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\Disconnect;
use Utopia\Mqtt\Packet\Specs\V3;
use Utopia\Mqtt\Packet\Specs\V5;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;
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

    public function testSubscribeThatClosedTheConnectionIsNotAnswered(): void
    {
        $adapter = new RecordingAdapter();
        $handler = new AcceptingHandler();
        $handler->closeOnSubscribe = true;
        (new Server($adapter, $handler))->start();

        $adapter->deliver(9, V5::connect('client-9'), V5::subscribe(1, ['a'], Packet::QOS_1));

        // Test for FAILURE: nothing is written to, or subscribed on, a connection the handler dropped.
        $this->assertSame(1, $handler->subscribes);
        $this->assertCount(1, $adapter->sent, 'a SUBACK was written after the handler closed the connection');
        $this->assertSame([9], $adapter->closed);
    }

    public function testDisabledKeepAliveIsReplacedByTheMaximum(): void
    {
        $timer = new RecordingTimer();
        $adapter = new RecordingAdapter($timer);
        (new Server($adapter, new AcceptingHandler(), maxKeepAlive: 60))->start();

        $adapter->deliver(9, V5::connect('client-9', 0));

        // Test for SUCCESS: the client is told to ping, and reaped if it falls silent.
        $this->assertSame(60, $timer->scheduled[9] ?? null, 'a keep-alive of 0 was never reaped');
        $this->assertSame(60, $this->serverKeepAlive($adapter->sent[0][1]));
    }

    public function testKeepAliveAboveTheMaximumIsLowered(): void
    {
        $timer = new RecordingTimer();
        $adapter = new RecordingAdapter($timer);
        (new Server($adapter, new AcceptingHandler(), maxKeepAlive: 60))->start();

        $adapter->deliver(9, V5::connect('client-9', 600));

        $this->assertSame(60, $timer->scheduled[9] ?? null);
        $this->assertSame(60, $this->serverKeepAlive($adapter->sent[0][1]));
    }

    public function testKeepAliveWithinTheMaximumIsKept(): void
    {
        $timer = new RecordingTimer();
        $adapter = new RecordingAdapter($timer);
        (new Server($adapter, new AcceptingHandler(), maxKeepAlive: 60))->start();

        $adapter->deliver(9, V5::connect('client-9', 30));

        $this->assertSame(30, $timer->scheduled[9] ?? null);
        $this->assertNull($this->serverKeepAlive($adapter->sent[0][1]), 'an unchanged keep-alive was announced');
    }

    public function testDisabledKeepAliveOnA311ClientIsReapedAtTheMaximum(): void
    {
        $timer = new RecordingTimer();
        $adapter = new RecordingAdapter($timer);
        (new Server($adapter, new AcceptingHandler(), maxKeepAlive: 60))->start();

        $adapter->deliver(9, V3::connect('client-9', 0));

        // 3.1.1 cannot announce it, but lets a server disconnect an inactive client whatever its keep-alive.
        $this->assertSame(60, $timer->scheduled[9] ?? null);
    }

    public function testDisabledKeepAliveStandsWithoutAMaximum(): void
    {
        $timer = new RecordingTimer();
        $adapter = new RecordingAdapter($timer);
        (new Server($adapter, new AcceptingHandler()))->start();

        $adapter->deliver(9, V5::connect('client-9', 0));

        $this->assertSame(0, $timer->scheduled[9] ?? null);
        $this->assertNull($this->serverKeepAlive($adapter->sent[0][1]));
    }

    /** The Server Keep Alive a 5.0 CONNACK announces, or null when it carries none. */
    private function serverKeepAlive(string $connack): ?int
    {
        $packet = Packet::parse($connack);
        $this->assertSame(Packet::CONNACK, $packet->type);
        [$properties] = Properties::parse($packet->body, 2);
        $value = $properties->get(Property::SERVER_KEEP_ALIVE);

        return $value === null ? null : (int) $value;
    }
}
