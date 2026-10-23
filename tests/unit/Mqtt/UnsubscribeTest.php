<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt;

use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Mqtt\Handler;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Mqtt\Fakes\Accounts;
use Tests\Unit\Mqtt\Fakes\NullPubSub;
use Tests\Unit\Mqtt\Fakes\RecordingAdapter;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
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
 * open and keeps its other subscriptions, but stops receiving the removed topic. The drop re-checks
 * membership so a late event cannot tear down a subscription the user re-created.
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

    private Database $database;

    private string|false $previousAbuseOption = false;

    protected function setUp(): void
    {
        $this->previousAbuseOption = \getenv(self::ABUSE_OPTION);
        \putenv(self::ABUSE_OPTION . '=disabled');

        $authorization = new Authorization();
        $authorization->addRole(Role::any()->toString());

        $this->database = new Database(new Memory(), new Cache(new NoCache()));
        $this->database
            ->setAuthorization($authorization)
            ->setDatabase('mqttTests')
            ->setNamespace('mqtt_' . \uniqid());
        $this->database->create();

        $any = [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ];
        $this->database->createCollection(Collection::create('projects', permissions: $any, documentSecurity: false));
        $this->database->createCollection(Collection::create('topics', attributes: [
            Attribute::string('name', 255, true),
        ], permissions: $any, documentSecurity: false));
        $this->database->createCollection(Collection::create('subscribers', attributes: [
            Attribute::string('topicInternalId', 255, true),
            Attribute::string('userId', 255, true),
        ], permissions: $any, documentSecurity: false));

        $container = new Container();
        $accounts = new Accounts();
        $container->set('authenticator', fn (): \Closure => $accounts->authenticate(...));
        $container->set('authorizer', fn (): \Closure => $accounts->authorize(...));
        $container->set('getConsoleDB', fn () => fn (): Database => $this->database, []);
        $container->set('getProjectDB', fn () => fn (Document $project): Database => $this->database, []);

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

    /** Create a topic whose id is its wire name and return its internal id (the subscribers' topicInternalId). */
    private function seedTopic(string $name): string
    {
        return (string) $this->database->createDocument('topics', new Document([
            '$id' => $name,
            'name' => $name,
        ]))->getSequence();
    }

    private function subscribe(string $topicInternalId, string $userId): void
    {
        $this->database->createDocument('subscribers', new Document([
            '$id' => Id::unique(),
            'topicInternalId' => $topicInternalId,
            'userId' => $userId,
        ]));
    }

    /** Open and index a connection as $userId, then seed its store subscriptions to $topics. */
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
        $this->seedTopic('scores'); // the subscriber row is already gone (removed)
        $this->connect(1, 'member', 'scores', 'users/member');
        $this->assertArrayHasKey(1, $this->store->getSubscribers(self::PROJECT, 'scores'));

        $dropped = $this->handler->unsubscribe($this->server, self::PROJECT, 'member', 'scores');

        $this->assertSame(1, $dropped);
        $this->assertSame([], $this->store->getSubscribers(self::PROJECT, 'scores'), 'the removed topic still has a subscriber');
        $this->assertArrayHasKey(1, $this->store->getSubscribers(self::PROJECT, 'users/member'), 'an unrelated subscription was dropped');
        $this->assertSame([], $this->network->disconnects(1), 'the connection was disconnected rather than unsubscribed');
    }

    public function testResubscribedUserKeepsTheSubscription(): void
    {
        $topicInternalId = $this->seedTopic('scores');
        $this->subscribe($topicInternalId, 'member'); // re-subscribed before the event landed
        $this->connect(1, 'member', 'scores');

        $dropped = $this->handler->unsubscribe($this->server, self::PROJECT, 'member', 'scores');

        $this->assertSame(0, $dropped, 'a re-created subscription was torn down by a late event');
        $this->assertArrayHasKey(1, $this->store->getSubscribers(self::PROJECT, 'scores'));
    }

    public function testUnsubscribeIgnoresOtherUsers(): void
    {
        $this->seedTopic('scores');
        $this->connect(1, 'member', 'scores');
        $this->connect(2, 'other', 'scores');

        $this->assertSame(1, $this->handler->unsubscribe($this->server, self::PROJECT, 'member', 'scores'));

        $this->assertArrayNotHasKey(1, $this->store->getSubscribers(self::PROJECT, 'scores'), 'the removed user kept the subscription');
        $this->assertArrayHasKey(2, $this->store->getSubscribers(self::PROJECT, 'scores'), 'another user lost the subscription');
    }

    public function testUnsubscribeWithoutConnectionsDoesNothing(): void
    {
        $this->assertSame(0, $this->handler->unsubscribe($this->server, self::PROJECT, 'ghost', 'scores'));
    }

    public function testFailsClosedWhenMembershipCannotBeChecked(): void
    {
        $this->connect(1, 'member', 'scores');
        // Make the membership check throw (the collection is gone); the subscription must still drop.
        $this->database->deleteCollection('topics');

        $dropped = $this->handler->unsubscribe($this->server, self::PROJECT, 'member', 'scores');

        $this->assertSame(1, $dropped);
        $this->assertSame([], $this->store->getSubscribers(self::PROJECT, 'scores'));
    }
}
