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
use Utopia\Mqtt\Packet\Specs\V3;
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

    private RecordingRedis $redis;

    /** @var array<string, mixed> the plan every project resolves to */
    private array $plan = [];

    private string|false $previousAbuseOption = false;

    protected function setUp(): void
    {
        $this->previousAbuseOption = \getenv(self::ABUSE_OPTION);
        \putenv(self::ABUSE_OPTION . '=enabled');

        $this->accounts = new Accounts();
        $this->redis = new RecordingRedis();
        $redis = $this->redis;

        $container = new Container();
        $container->set('getRedis', fn (): \Closure => fn (): \Redis => $redis);
        $container->set('authenticator', fn (): \Closure => $this->accounts->authenticate(...));
        $container->set('authorizer', fn (): \Closure => $this->accounts->authorize(...));
        $container->set('getPlan', fn (): \Closure => fn (string $projectId): array => $this->plan);

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

    public function testSubscribeRateSurvivesReconnect(): void
    {
        $this->connect(1, 'user');
        $this->accounts->blocked['user'] = true;
        for ($packetId = 1; $packetId <= self::SUBSCRIBE_LIMIT; $packetId++) {
            $this->network->deliver(1, V5::subscribe($packetId, ['news'], Packet::QOS_1));
        }
        $this->network->close(1);
        unset($this->accounts->blocked['user']);
        $this->connect(2, 'user');
        $this->accounts->blocked['user'] = true;

        $this->network->deliver(2, V5::subscribe(1, ['news'], Packet::QOS_1));

        // Test for FAILURE: a new connection does not get a fresh budget.
        $this->assertSame(0, $this->subacks(2));
        $this->assertSame(Disconnect::MESSAGE_RATE_TOO_HIGH, $this->network->disconnects(2)[0]->reasonCode ?? null);
    }

    public function testSubscribeRateIsSharedAcrossAUsersConnections(): void
    {
        $this->connect(1, 'user');
        $this->connect(2, 'user');
        $this->connect(3, 'other');
        $this->accounts->blocked['user'] = true;
        $this->accounts->blocked['other'] = true;

        for ($packetId = 1; $packetId <= self::SUBSCRIBE_LIMIT; $packetId++) {
            $this->network->deliver(1, V5::subscribe($packetId, ['news'], Packet::QOS_1));
        }
        $this->network->deliver(2, V5::subscribe(1, ['news'], Packet::QOS_1));
        $this->network->deliver(3, V5::subscribe(1, ['news'], Packet::QOS_1));

        // Test for FAILURE: the user's other connection draws on the same budget.
        $this->assertSame([2], $this->network->closed);
        // Test for SUCCESS: another user keeps their own.
        $this->assertSame(1, $this->subacks(3));
    }

    public function testSubscribeWithoutAConnectIsRefusedWithoutLimiting(): void
    {
        $this->network->deliver(1, V3::subscribe(1, ['news'], Packet::QOS_1));

        // Test for FAILURE: a connection that never completed CONNECT is refused outright.
        $this->assertSame(0, $this->accounts->authorizations);
        $this->assertSame([], $this->redis->keys, 'a SUBSCRIBE before CONNECT reached the limiter');
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

    public function testSubscribeOverTheFilterLimitIsRefused(): void
    {
        $this->connect(1, 'user');
        $this->accounts->blocked['user'] = true;
        $authorizations = $this->accounts->authorizations;
        $filters = \array_map(fn (int $index): string => 'topic' . $index, \range(1, APP_LIMIT_MQTT_FILTERS + 1));

        $this->network->deliver(1, V5::subscribe(1, $filters, Packet::QOS_1));

        // Test for FAILURE
        $this->assertSame(0, $this->subacks(1), 'an oversized SUBSCRIBE was answered');
        $this->assertSame($authorizations, $this->accounts->authorizations, 'an oversized SUBSCRIBE reached the authorizer');
        $disconnects = $this->network->disconnects(1);
        $this->assertCount(1, $disconnects);
        $this->assertSame(Disconnect::QUOTA_EXCEEDED, $disconnects[0]->reasonCode);
        $this->assertSame([1], $this->network->closed);
    }

    public function testSubscribeFiltersFollowThePlan(): void
    {
        $this->plan = ['mqttFilters' => 2];
        $this->connect(1, 'user');
        $this->accounts->blocked['user'] = true;

        $this->network->deliver(1, V5::subscribe(1, ['a', 'b'], Packet::QOS_1));

        // Test for SUCCESS
        $this->assertSame(1, $this->subacks(1));
        $this->network->sent[1] = [];

        $this->network->deliver(1, V5::subscribe(2, ['a', 'b', 'c'], Packet::QOS_1));

        // Test for FAILURE: the plan's limit replaces the default.
        $this->assertSame(0, $this->subacks(1));
        $this->assertSame(Disconnect::QUOTA_EXCEEDED, $this->network->disconnects(1)[0]->reasonCode ?? null);
        $this->assertSame([1], $this->network->closed);
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

    public function testOpenConnectionsPerUserFollowThePlan(): void
    {
        $this->plan = ['mqttConnections' => 2];

        $this->assertSame(Connack::SUCCESS, $this->connect(1, 'user'));
        $this->assertSame(Connack::SUCCESS, $this->connect(2, 'user'));

        // Test for FAILURE: the plan's limit replaces the default.
        $this->assertSame(Connack::QUOTA_EXCEEDED, $this->connect(3, 'user'));
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
