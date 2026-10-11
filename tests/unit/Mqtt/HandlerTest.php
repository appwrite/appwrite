<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt;

use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Mqtt\Handler;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Mqtt\Fakes\GrantingHandler;
use Tests\Unit\Mqtt\Fakes\NullPubSub;
use Tests\Unit\Mqtt\Fakes\RecordingAdapter;
use Utopia\DI\Container;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\Specs\V5;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;
use Utopia\Mqtt\Server;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

/**
 * Delivery on the reserved users/<id> topic, with subscriptions installed through the broker's
 * own CONNECT/SUBSCRIBE path. The broker here grants every filter, standing in for any
 * subscription the subscribe gate did not catch, so what reaches each connection depends only
 * on Handler::deliver().
 */
final class HandlerTest extends TestCase
{
    private const PROJECT = 'project1';

    private RecordingAdapter $adapter;

    private Server $server;

    private Handler $handler;

    protected function setUp(): void
    {
        $this->adapter = new RecordingAdapter();
        $this->server = new Server($this->adapter, new GrantingHandler());
        $this->server->start();

        $this->handler = new Handler(
            new Container(),
            new Mqtt(new NoTelemetry(), new NullPubSub()),
            fn () => throw new \LogicException('deliver() must not touch the cache'),
            fn () => 5,
        );
    }

    private function subscribe(int $fd, string $userId, string ...$filters): void
    {
        $properties = new Properties();
        $properties->add(new Property(Property::USER, ['projectId' => self::PROJECT, 'userId' => $userId]));

        $this->adapter->deliver(
            $fd,
            V5::connect('client-' . $fd, 60, true, $properties),
            V5::subscribe(1, $filters, Packet::QOS_1),
        );
        $this->adapter->sent[$fd] = []; // drop CONNACK/SUBACK; only deliveries matter below
    }

    public function testUserTopicReachesOnlyItsOwner(): void
    {
        $this->subscribe(1, 'owner', 'users/owner');
        $this->subscribe(2, 'other', '#');
        $this->subscribe(3, 'other', '+/+');
        $this->subscribe(4, 'other', '+/owner');

        $delivered = $this->handler->deliver($this->server, self::PROJECT, 'users/owner', '{"body":"private"}', Packet::QOS_1, 7);

        $this->assertSame(1, $delivered);
        $this->assertCount(1, $this->adapter->publishes(1), 'the owner did not receive their push');
        $this->assertSame([], $this->adapter->publishes(2), "a '#' subscriber of another user received users/owner");
        $this->assertSame([], $this->adapter->publishes(3), "a '+/+' subscriber of another user received users/owner");
        $this->assertSame([], $this->adapter->publishes(4), "a '+/owner' subscriber of another user received users/owner");
    }

    public function testOwnerReceivesUserTopicThroughWildcard(): void
    {
        // Ownership is by identity, not by filter: the owner's own wildcard still matches.
        $this->subscribe(1, 'owner', '#');

        $delivered = $this->handler->deliver($this->server, self::PROJECT, 'users/owner', '{}', Packet::QOS_1, 1);

        $this->assertSame(1, $delivered);
        $this->assertCount(1, $this->adapter->publishes(1));
    }

    public function testOrdinaryTopicsStillFanOutToWildcards(): void
    {
        // Only the two-level users/<id> topic is owner-scoped; a deeper users/<id>/… path and any
        // other topic keep ordinary wildcard fan-out.
        $this->subscribe(1, 'other', '#');

        $this->assertSame(1, $this->handler->deliver($this->server, self::PROJECT, 'scores/live', '{}', Packet::QOS_1, 1));
        $this->assertSame(1, $this->handler->deliver($this->server, self::PROJECT, 'users/owner/status', '{}', Packet::QOS_1, 2));
        $this->assertCount(2, $this->adapter->publishes(1));
    }
}
