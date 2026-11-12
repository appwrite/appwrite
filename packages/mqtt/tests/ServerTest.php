<?php

declare(strict_types=1);

namespace Utopia\Mqtt\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\Specs\V3;
use Utopia\Mqtt\Packet\Specs\V5;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;
use Utopia\Mqtt\Server;

final class ServerTest extends TestCase
{
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

    public function testServerKeepAliveReplacesTheHandlersOwn(): void
    {
        $timer = new RecordingTimer();
        $adapter = new RecordingAdapter($timer);
        $handler = new AcceptingHandler();
        $handler->connackProperties = (new Properties())
            ->add(new Property(Property::SERVER_KEEP_ALIVE, 600))
            ->add(new Property(Property::AUTHENTICATION_METHOD, 'token'));
        (new Server($adapter, $handler, maxKeepAlive: 60))->start();

        $adapter->deliver(9, V5::connect('client-9', 0));

        // Server Keep Alive may appear only once, so the enforced value replaces the handler's.
        [$properties] = Properties::parse(Packet::parse($adapter->sent[0][1])->body, 2);
        $ids = \array_map(fn (Property $property): int => $property->id, $properties->all());
        $this->assertSame(1, \count(\array_keys($ids, Property::SERVER_KEEP_ALIVE, true)));
        $this->assertSame(60, $properties->get(Property::SERVER_KEEP_ALIVE));
        $this->assertSame('token', $properties->get(Property::AUTHENTICATION_METHOD));
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

    public function testKeepAliveAboveTheMaximumOnA311ClientIsKept(): void
    {
        $timer = new RecordingTimer();
        $adapter = new RecordingAdapter($timer);
        (new Server($adapter, new AcceptingHandler(), maxKeepAlive: 60))->start();

        $adapter->deliver(9, V3::connect('client-9', 600));

        // 3.1.1 cannot be told a shorter interval, so a client pinging as it declared is not reaped early.
        $this->assertSame(600, $timer->scheduled[9] ?? null);
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
        $this->assertTrue($value === null || \is_int($value), 'Server Keep Alive is not an integer');

        return $value;
    }
}
