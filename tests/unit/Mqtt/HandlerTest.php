<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt;

use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Mqtt\Handler;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Mqtt\Fakes\Accounts;
use Tests\Unit\Mqtt\Fakes\GrantingHandler;
use Tests\Unit\Mqtt\Fakes\NullPubSub;
use Tests\Unit\Mqtt\Fakes\RecordingAdapter;
use Utopia\DI\Container;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\Disconnect;
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

    private Accounts $accounts;

    protected function setUp(): void
    {
        $this->adapter = new RecordingAdapter();
        $this->server = new Server($this->adapter, new GrantingHandler());
        $this->server->start();

        $this->accounts = new Accounts();
        $container = new Container();
        $container->set('authorizer', fn (): \Closure => $this->accounts->authorize(...));

        $this->handler = new Handler(
            $container,
            new Mqtt(new NoTelemetry(), new NullPubSub()),
            fn () => throw new \LogicException('deliver() must not touch the cache'),
            fn () => 5,
            fn () => throw new \LogicException('deliver() must not read the clock'),
        );
    }

    /** @param array<string, int|string> $credential sessionId, and expire and recheckAt as unix timestamps */
    private function subscribe(int $fd, string $userId, string $filter, array $credential = []): void
    {
        $properties = new Properties();
        $properties->add(new Property(Property::USER, [
            'projectId' => self::PROJECT,
            'userId' => $userId,
            ...\array_map(\strval(...), $credential),
        ]));

        $this->adapter->deliver(
            $fd,
            V5::connect('client-' . $fd, 60, true, $properties),
            V5::subscribe(1, [$filter], Packet::QOS_1),
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

    public function testExpiredCredentialIsDisconnectedOnDelivery(): void
    {
        $this->subscribe(1, 'owner', 'users/owner', ['expire' => \time() - 1]);
        $this->subscribe(2, 'owner', 'users/owner', ['expire' => \time() + 3600]);

        $delivered = $this->handler->deliver($this->server, self::PROJECT, 'users/owner', '{}', Packet::QOS_1, 1);

        // Test for FAILURE: the connection whose credential expired gets nothing and is closed.
        $this->assertSame(1, $delivered);
        $this->assertSame(Disconnect::NOT_AUTHORIZED, $this->adapter->disconnects(1)[0]->reasonCode);
        $this->assertSame([], $this->adapter->publishes(1), 'a connection with an expired credential received a push');
        $this->assertSame([1], $this->adapter->closed);
        $this->assertCount(1, $this->adapter->publishes(2));
    }

    public function testDueSessionIsCheckedAgain(): void
    {
        $this->subscribe(1, 'owner', 'users/owner', ['sessionId' => 'session', 'recheckAt' => \time() - 1]);

        // Test for SUCCESS: a session that falls due and still authorizes, as an extended one would, keeps receiving.
        $this->assertSame(1, $this->handler->deliver($this->server, self::PROJECT, 'users/owner', '{}', Packet::QOS_1, 1));
        $this->assertCount(1, $this->adapter->publishes(1));
        $this->assertSame(1, $this->accounts->authorizations);

        // Checked again only once the recheck interval passes.
        $this->assertSame(1, $this->handler->deliver($this->server, self::PROJECT, 'users/owner', '{}', Packet::QOS_1, 2));
        $this->assertSame(1, $this->accounts->authorizations);
    }

    public function testEndedSessionIsDisconnectedOnDelivery(): void
    {
        $this->subscribe(1, 'owner', 'users/owner', ['sessionId' => 'session', 'recheckAt' => \time() - 1]);
        $this->subscribe(2, 'owner', 'users/owner', ['sessionId' => 'session', 'recheckAt' => \time() + 3600]);
        $this->accounts->ended['session'] = true;

        // Test for FAILURE: once due, a session that ended or lost its factors without a revoke is closed.
        $this->assertSame(1, $this->handler->deliver($this->server, self::PROJECT, 'users/owner', '{}', Packet::QOS_1, 1));
        $this->assertSame(Disconnect::NOT_AUTHORIZED, $this->adapter->disconnects(1)[0]->reasonCode);
        $this->assertSame([1], $this->adapter->closed);
        $this->assertCount(1, $this->adapter->publishes(2), 'a connection not yet due was checked early');
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
