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
use Utopia\DI\Container;
use Utopia\Mqtt\Connection;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\Connack;
use Utopia\Mqtt\Packet\Disconnect;
use Utopia\Mqtt\Packet\Specs\V5;
use Utopia\Mqtt\Packet\Suback;
use Utopia\Mqtt\Packet\Subscribe;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;
use Utopia\Mqtt\Server;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

/**
 * Revoking a user who lost access, with connections opened through the broker's own CONNECT and
 * close paths so they maintain the Handler's per-user index.
 */
final class RevokeTest extends TestCase
{
    private const PROJECT = 'project1';

    private const OTHER_PROJECT = 'project2';

    private const CONSOLE_PROJECT = 'console';

    private const AUTH_METHOD = 'appwrite-jwt';

    private const ABUSE_OPTION = '_APP_OPTIONS_ABUSE';

    private RecordingAdapter $network;

    private Accounts $accounts;

    private Handler $handler;

    private Mqtt $mqtt;

    private string|false $previousAbuseOption = false;

    protected function setUp(): void
    {
        $this->previousAbuseOption = \getenv(self::ABUSE_OPTION);
        \putenv(self::ABUSE_OPTION . '=disabled');

        $this->accounts = new Accounts();

        $container = new Container();
        $container->set('authenticator', fn (): \Closure => $this->accounts->authenticate(...));
        $container->set('authorizer', fn (): \Closure => $this->accounts->authorize(...));

        $this->mqtt = new Mqtt(new NoTelemetry(), new NullPubSub());
        $this->handler = new Handler(
            $container,
            $this->mqtt,
            fn () => throw new \LogicException('revoking must not touch the cache'),
            fn () => 5,
            fn () => throw new \LogicException('revoking must not read the clock'),
        );

        $this->network = new RecordingAdapter();
        (new Server($this->network, $this->handler))->start();
    }

    protected function tearDown(): void
    {
        \putenv($this->previousAbuseOption === false ? self::ABUSE_OPTION : self::ABUSE_OPTION . '=' . $this->previousAbuseOption);
    }

    /** Open a connection as $userId and return the CONNACK reason code. */
    private function connect(int $fd, string $userId, string $projectId = self::PROJECT): int
    {
        $properties = new Properties();
        $properties->add(new Property(Property::USER, ['projectId' => $projectId]));
        $properties->add(new Property(Property::AUTHENTICATION_METHOD, self::AUTH_METHOD));
        $properties->add(new Property(Property::AUTHENTICATION_DATA, $userId));

        $this->network->deliver($fd, V5::connect('client-' . $fd, 60, true, $properties));
        $connacks = $this->network->connacks($fd);
        $this->network->sent[$fd] = [];

        $this->assertCount(1, $connacks, "fd {$fd} was not sent a CONNACK");

        return $connacks[0];
    }

    public function testRevokeDisconnectsBlockedUser(): void
    {
        $this->connect(1, 'blocked');
        $this->connect(2, 'blocked');
        $this->accounts->blocked['blocked'] = true;

        $disconnected = $this->handler->revoke(self::PROJECT, 'blocked');

        $this->assertSame(2, $disconnected);
        foreach ([1, 2] as $fd) {
            $disconnects = $this->network->disconnects($fd);
            $this->assertCount(1, $disconnects, "fd {$fd} was not sent a DISCONNECT");
            $this->assertSame(Disconnect::NOT_AUTHORIZED, $disconnects[0]->reasonCode);
            $this->assertSame((new Exception(Exception::USER_BLOCKED))->getMessage(), $disconnects[0]->properties?->get(Property::REASON_STRING));
        }
        $this->assertSame([1, 2], $this->network->closed);
        $this->assertSame(0, $this->handler->revoke(self::PROJECT, 'blocked'), 'a revoked connection stayed in the index');
    }

    public function testRevokeKeepsAuthorizedUser(): void
    {
        $this->connect(1, 'active');

        $disconnected = $this->handler->revoke(self::PROJECT, 'active');

        $this->assertSame(0, $disconnected);
        $this->assertSame(1, $this->accounts->authorizations);
        $this->assertSame([], $this->network->disconnects(1));
        $this->assertSame([], $this->network->closed);

        $this->accounts->blocked['active'] = true;
        $this->assertSame(1, $this->handler->revoke(self::PROJECT, 'active'), 'a kept connection left the index');
    }

    public function testRevokeDisconnectsWhenAuthorizerFails(): void
    {
        $this->connect(1, 'active');
        $this->accounts->unavailable = true;

        $disconnected = $this->handler->revoke(self::PROJECT, 'active');

        $this->assertSame(1, $disconnected, 'a revoke the authorizer could not answer left the user connected');
        $this->assertSame(Disconnect::NOT_AUTHORIZED, $this->network->disconnects(1)[0]->reasonCode);
        $this->assertSame([1], $this->network->closed);
    }

    public function testRevokeIgnoresOtherUsersAndProjects(): void
    {
        $this->connect(1, 'blocked');
        $this->connect(2, 'other');
        $this->connect(3, 'blocked', self::OTHER_PROJECT);
        $this->accounts->blocked['blocked'] = true;
        $this->accounts->blocked['other'] = true;

        $disconnected = $this->handler->revoke(self::PROJECT, 'blocked');

        $this->assertSame(1, $disconnected);
        $this->assertSame([1], $this->network->closed);
        $this->assertSame([], $this->network->disconnects(2), 'another user of the project was disconnected');
        $this->assertSame([], $this->network->disconnects(3), 'the same user id in another project was disconnected');
        $this->assertSame(0, $this->handler->revoke(self::PROJECT, 'unknown'));
        $this->assertSame(1, $this->accounts->authorizations, 'a user without connections was checked against the database');
    }

    public function testConnectWithoutProjectIsRevokedUnderConsole(): void
    {
        $this->connect(1, 'member', '');
        $this->accounts->blocked['member'] = true;

        $this->assertSame(1, $this->handler->revoke(self::CONSOLE_PROJECT, 'member'));
        $this->assertSame([1], $this->network->closed);
    }

    public function testDisconnectedConnectionLeavesIndex(): void
    {
        $this->connect(1, 'blocked');
        $this->connect(2, 'blocked');
        $this->network->close(1);
        $this->network->deliver(2, V5::disconnect(Disconnect::NORMAL));
        $this->network->sent = [];
        $this->accounts->blocked['blocked'] = true;

        $disconnected = $this->handler->revoke(self::PROJECT, 'blocked');

        $this->assertSame(0, $disconnected);
        $this->assertSame(0, $this->accounts->authorizations, 'a user without connections was checked against the database');
        $this->assertSame([], $this->network->sent);
    }

    public function testRepeatedConnectMovesConnectionInIndex(): void
    {
        $this->connect(1, 'previous');
        $this->connect(1, 'current', self::OTHER_PROJECT);
        $this->accounts->blocked['previous'] = true;
        $this->accounts->blocked['current'] = true;

        $this->assertSame(0, $this->handler->revoke(self::PROJECT, 'previous'));
        $this->assertSame(1, $this->handler->revoke(self::OTHER_PROJECT, 'current'));
    }

    public function testRevokeDuringConnectRefusesBlockedUser(): void
    {
        $this->accounts->whileAuthenticating = function (string $projectId, string $userId): void {
            $this->accounts->blocked[$userId] = true;
            $this->assertSame(0, $this->handler->revoke($projectId, $userId));
        };

        // Test for FAILURE: the user was blocked after the authenticator read their status.
        $this->assertSame(Connack::NOT_AUTHORIZED, $this->connect(1, 'racing'));
        $this->assertSame([1], $this->network->closed);

        $this->accounts->whileAuthenticating = null;
        $this->assertSame(0, $this->handler->revoke(self::PROJECT, 'racing'), 'a refused connection stayed in the index');
    }

    public function testConnectionClosedDuringConnectLeavesIndex(): void
    {
        $this->accounts->whileAuthenticating = function (): void {
            $this->network->close(1);
        };

        $properties = new Properties();
        $properties->add(new Property(Property::USER, ['projectId' => self::PROJECT]));
        $properties->add(new Property(Property::AUTHENTICATION_METHOD, self::AUTH_METHOD));
        $properties->add(new Property(Property::AUTHENTICATION_DATA, 'gone'));
        $this->network->deliver(1, V5::connect('client-1', 60, true, $properties));

        // Test for FAILURE: the client hung up while the authenticator ran.
        $this->assertSame([], $this->network->connacks(1), 'a connection closed mid-CONNECT was sent a CONNACK');
        $this->assertSame([], $this->mqtt->flushUsage(), 'a connection closed mid-CONNECT was counted towards usage');

        $this->accounts->whileAuthenticating = null;
        $this->accounts->blocked['gone'] = true;
        $this->assertSame(0, $this->handler->revoke(self::PROJECT, 'gone'), 'a connection closed mid-CONNECT stayed in the index');
        $this->assertSame([1], $this->network->closed);
    }

    public function testRevokeDuringConnectKeepsPermittedUser(): void
    {
        $this->accounts->whileAuthenticating = function (string $projectId, string $userId): void {
            $this->handler->revoke($projectId, $userId);
        };

        // Test for SUCCESS: a revoke for a user who is still permitted does not refuse their CONNECT.
        $this->assertSame(Connack::SUCCESS, $this->connect(1, 'active'));
        $this->assertSame(1, $this->accounts->authorizations, 'the user was not checked again after a revoke during CONNECT');

        $this->accounts->whileAuthenticating = null;
        $this->accounts->blocked['active'] = true;
        $this->assertSame(1, $this->handler->revoke(self::PROJECT, 'active'), 'the accepted connection was not indexed');
    }

    public function testConnectIsNotCheckedAgainForEarlierRevoke(): void
    {
        $this->handler->revoke(self::PROJECT, 'idle');
        $this->accounts->whileAuthenticating = function (string $projectId, string $userId): void {
            $this->handler->revoke($projectId, $userId);
        };
        $this->connect(1, 'racing');
        $this->accounts->whileAuthenticating = null;

        $this->connect(2, 'idle');
        $this->connect(3, 'racing');

        $this->assertSame(1, $this->accounts->authorizations, 'a CONNECT was checked again for a revoke that landed before it began');
    }

    public function testSubscribeIsRefusedForUserWhoseRevokeWasMissed(): void
    {
        $connection = new Connection(1);
        $connection->identity = ['projectId' => self::PROJECT, 'userId' => 'blocked'];
        $this->accounts->blocked['blocked'] = true;

        $subscribe = Subscribe::decode(Packet::parse(V5::subscribe(1, ['news', 'users/blocked'], Packet::QOS_1))->body, V5::PROTOCOL_LEVEL);

        // Test for FAILURE: without a revoke, the per-SUBSCRIBE re-check still refuses every filter.
        $this->assertSame([Suback::DENIED, Suback::DENIED], $this->handler->onSubscribe($subscribe, $connection)->codes());
    }
}
