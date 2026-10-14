<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt;

use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Mqtt\Handler;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Mqtt\Fakes\Accounts;
use Tests\Unit\Mqtt\Fakes\NullPubSub;
use Tests\Unit\Mqtt\Fakes\RecordingAdapter;
use Utopia\DI\Container;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\Specs\V5;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;
use Utopia\Mqtt\Server;
use Utopia\Mqtt\Subscription\Store;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

/**
 * Dropping a user's live subscription when they are removed as a subscriber: the connection stays
 * open and keeps its other subscriptions, but stops receiving the removed topic.
 */
final class UnsubscribeTest extends TestCase
{
    private const PROJECT = 'project1';

    private const AUTH_METHOD = 'appwrite-jwt';

    private const ABUSE_OPTION = '_APP_OPTIONS_ABUSE';

    private RecordingAdapter $network;

    private Store $store;

    private Server $server;

    private Handler $handler;

    private string|false $previousAbuseOption = false;

    protected function setUp(): void
    {
        $this->previousAbuseOption = \getenv(self::ABUSE_OPTION);
        \putenv(self::ABUSE_OPTION . '=disabled');

        $container = new Container();
        $accounts = new Accounts();
        $container->set('authenticator', fn (): \Closure => $accounts->authenticate(...));
        $container->set('authorizer', fn (): \Closure => $accounts->authorize(...));

        $this->handler = new Handler(
            $container,
            new Mqtt(new NoTelemetry(), new NullPubSub()),
            fn () => throw new \LogicException('unsubscribe must not touch the cache'),
            fn () => 5,
            fn () => throw new \LogicException('unsubscribe must not read the clock'),
        );

        $this->network = new RecordingAdapter();
        $this->store = new Store();
        $this->server = new Server($this->network, $this->handler, $this->store);
        $this->server->start();
    }

    protected function tearDown(): void
    {
        \putenv($this->previousAbuseOption === false ? self::ABUSE_OPTION : self::ABUSE_OPTION . '=' . $this->previousAbuseOption);
    }

    /** Open and index a connection as $userId, then subscribe it to $topics in the store. */
    private function connect(int $fd, string $userId, string ...$topics): void
    {
        $properties = new Properties();
        $properties->add(new Property(Property::USER, ['projectId' => self::PROJECT]));
        $properties->add(new Property(Property::AUTHENTICATION_METHOD, self::AUTH_METHOD));
        $properties->add(new Property(Property::AUTHENTICATION_DATA, $userId));

        $this->network->deliver($fd, V5::connect('client-' . $fd, 60, true, $properties));
        $this->network->sent[$fd] = [];

        foreach ($topics as $topic) {
            $this->store->subscribe(self::PROJECT, 'client-' . $fd, $topic, $fd, Packet::QOS_1);
        }
    }

    public function testRemovedSubscriberStopsReceivingTheTopic(): void
    {
        $this->connect(1, 'member', 'scores', 'users/member');
        $this->assertArrayHasKey(1, $this->store->getSubscribers(self::PROJECT, 'scores'));

        $dropped = $this->handler->unsubscribe($this->server, self::PROJECT, 'member', 'scores');

        $this->assertSame(1, $dropped);
        $this->assertSame([], $this->store->getSubscribers(self::PROJECT, 'scores'), 'the removed topic still has a subscriber');
        $this->assertArrayHasKey(1, $this->store->getSubscribers(self::PROJECT, 'users/member'), 'an unrelated subscription was dropped');
        $this->assertSame([], $this->network->disconnects(1), 'the connection was disconnected rather than unsubscribed');
    }

    public function testUnsubscribeIgnoresOtherUsers(): void
    {
        $this->connect(1, 'member', 'scores');
        $this->connect(2, 'other', 'scores');

        $this->assertSame(1, $this->handler->unsubscribe($this->server, self::PROJECT, 'member', 'scores'));

        $this->assertSame([], $this->store->getSubscribers(self::PROJECT, 'scores')[1] ?? [], 'the removed user kept the subscription');
        $this->assertArrayHasKey(2, $this->store->getSubscribers(self::PROJECT, 'scores'), 'another user lost the subscription');
    }

    public function testUnsubscribeWithoutConnectionsDoesNothing(): void
    {
        $this->assertSame(0, $this->handler->unsubscribe($this->server, self::PROJECT, 'ghost', 'scores'));
    }
}
