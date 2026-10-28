<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt;

use Appwrite\Extend\Exception;
use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Mqtt\Handler;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Mqtt\Fakes\Accounts;
use Tests\Unit\Mqtt\Fakes\NullPubSub;
use Tests\Unit\Mqtt\Fakes\RecordingAdapter;
use Tests\Unit\Mqtt\Fakes\RecordingRedis;
use Utopia\DI\Container;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\Connack;
use Utopia\Mqtt\Packet\Disconnect;
use Utopia\Mqtt\Packet\Specs\V5;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;
use Utopia\Mqtt\Server;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

/**
 * The broker's per-connection and per-user limits, driven through the broker's own CONNECT,
 * SUBSCRIBE and close paths.
 */
final class LimitsTest extends TestCase
{
    private const PROJECT = 'project1';

    private const ABUSE_OPTION = '_APP_OPTIONS_ABUSE';

    private const SUBSCRIBE_LIMIT = 32;

    private RecordingAdapter $network;

    private Accounts $accounts;

    private string|false $previousAbuseOption = false;

    protected function setUp(): void
    {
        $this->previousAbuseOption = \getenv(self::ABUSE_OPTION);
        \putenv(self::ABUSE_OPTION . '=enabled');

        $this->accounts = new Accounts();
        $redis = new RecordingRedis();

        $container = new Container();
        $container->set('getRedis', fn (): \Closure => fn (): \Redis => $redis);
        $container->set('authenticator', fn (): \Closure => $this->accounts->authenticate(...));
        $container->set('authorizer', fn (): \Closure => $this->accounts->authorize(...));

        $handler = new Handler(
            $container,
            new Mqtt(new NoTelemetry(), new NullPubSub()),
            fn () => throw new \LogicException('limits must not touch the cache'),
            fn () => 5,
            fn () => throw new \LogicException('limits must not read the clock'),
        );

        $this->network = new RecordingAdapter();
        (new Server($this->network, $handler))->start();
    }

    protected function tearDown(): void
    {
        \putenv($this->previousAbuseOption === false ? self::ABUSE_OPTION : self::ABUSE_OPTION . '=' . $this->previousAbuseOption);
    }

    public function testSubscribeRateIsLimitedPerConnection(): void
    {
        $this->connect(1, 'user');
        // A user whose access is refused keeps every SUBSCRIBE off the database, so only the limiter is exercised.
        $this->accounts->blocked['user'] = true;
        $authorizations = $this->accounts->authorizations;

        for ($packetId = 1; $packetId <= self::SUBSCRIBE_LIMIT; $packetId++) {
            $this->network->deliver(1, V5::subscribe($packetId, ['news'], Packet::QOS_1));
        }

        // Test for SUCCESS
        $this->assertSame(self::SUBSCRIBE_LIMIT, $this->subacks(1));
        $this->assertSame([], $this->network->closed);

        $this->network->deliver(1, V5::subscribe(self::SUBSCRIBE_LIMIT + 1, ['news'], Packet::QOS_1));

        // Test for FAILURE
        $this->assertSame(self::SUBSCRIBE_LIMIT, $this->subacks(1), 'a SUBSCRIBE over the limit was answered');
        $this->assertSame(self::SUBSCRIBE_LIMIT, $this->accounts->authorizations - $authorizations, 'a SUBSCRIBE over the limit reached the authorizer');
        $disconnects = $this->network->disconnects(1);
        $this->assertCount(1, $disconnects);
        $this->assertSame(Disconnect::MESSAGE_RATE_TOO_HIGH, $disconnects[0]->reasonCode);
        $this->assertSame((new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED))->getMessage(), $disconnects[0]->properties?->get(Property::REASON_STRING));
        $this->assertSame([1], $this->network->closed);
    }

    public function testSubscribeRateIsCountedPerConnection(): void
    {
        $this->connect(1, 'user');
        $this->connect(2, 'user');
        $this->accounts->blocked['user'] = true;

        for ($packetId = 1; $packetId <= self::SUBSCRIBE_LIMIT + 1; $packetId++) {
            $this->network->deliver(1, V5::subscribe($packetId, ['news'], Packet::QOS_1));
        }
        $this->network->deliver(2, V5::subscribe(1, ['news'], Packet::QOS_1));

        // Test for SUCCESS: the user's other connection keeps its own budget.
        $this->assertSame([1], $this->network->closed);
        $this->assertSame(1, $this->subacks(2));
    }

    public function testSubscribeRateIsNotLimitedWithAbuseDisabled(): void
    {
        \putenv(self::ABUSE_OPTION . '=disabled');
        $this->connect(1, 'user');
        $this->accounts->blocked['user'] = true;

        for ($packetId = 1; $packetId <= self::SUBSCRIBE_LIMIT + 1; $packetId++) {
            $this->network->deliver(1, V5::subscribe($packetId, ['news'], Packet::QOS_1));
        }

        $this->assertSame(self::SUBSCRIBE_LIMIT + 1, $this->subacks(1));
        $this->assertSame([], $this->network->closed);
    }

    public function testOpenConnectionsPerUserAreCapped(): void
    {
        for ($fd = 1; $fd <= APP_LIMIT_MQTT_CONNECTIONS; $fd++) {
            $this->assertSame(Connack::SUCCESS, $this->connect($fd, 'user'));
        }

        // Test for FAILURE
        $overflow = APP_LIMIT_MQTT_CONNECTIONS + 1;
        $this->assertSame(Connack::QUOTA_EXCEEDED, $this->connect($overflow, 'user'));
        $this->assertSame([$overflow], $this->network->closed);

        // Test for SUCCESS: closing a connection frees its slot, and others are counted apart.
        $this->network->close(1);
        $this->assertSame(Connack::SUCCESS, $this->connect($overflow + 1, 'user'));
        $this->assertSame(Connack::SUCCESS, $this->connect($overflow + 2, 'other'));
        $this->assertSame(Connack::SUCCESS, $this->connect($overflow + 3, 'user', 'project2'));
    }

    public function testRefusedConnectionDoesNotHoldASlot(): void
    {
        for ($fd = 1; $fd <= APP_LIMIT_MQTT_CONNECTIONS + 1; $fd++) {
            $this->connect($fd, 'user');
        }
        $this->connect(100, 'user');

        // Test for SUCCESS: a refused CONNECT is never counted, so one close admits exactly one more.
        $this->network->close(1);
        $this->assertSame(Connack::SUCCESS, $this->connect(101, 'user'));
        $this->assertSame(Connack::QUOTA_EXCEEDED, $this->connect(102, 'user'));
    }

    /** Open a connection as $userId and return the CONNACK reason code. */
    private function connect(int $fd, string $userId, string $projectId = self::PROJECT): int
    {
        $properties = new Properties();
        $properties->add(new Property(Property::USER, ['projectId' => $projectId]));
        $properties->add(new Property(Property::AUTHENTICATION_METHOD, 'appwrite-jwt'));
        $properties->add(new Property(Property::AUTHENTICATION_DATA, $userId));

        $this->network->deliver($fd, V5::connect('client-' . $fd, 60, true, $properties));
        $connacks = $this->network->connacks($fd);
        $this->network->sent[$fd] = [];

        $this->assertCount(1, $connacks, "fd {$fd} was not sent a CONNACK");

        return $connacks[0];
    }

    /** The SUBACK packets written to $fd. */
    private function subacks(int $fd): int
    {
        $subacks = 0;
        foreach ($this->network->sent[$fd] ?? [] as $packet) {
            if (Packet::parse($packet)->type === Packet::SUBACK) {
                $subacks++;
            }
        }

        return $subacks;
    }
}
