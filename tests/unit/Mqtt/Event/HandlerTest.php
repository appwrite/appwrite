<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt\Event;

use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Mqtt\Event\Handler as EventHandler;
use Appwrite\Mqtt\Handler;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Tests\Unit\Mqtt\Fakes\Accounts;
use Tests\Unit\Mqtt\Fakes\MutedStderr;
use Tests\Unit\Mqtt\Fakes\NullPubSub;
use Tests\Unit\Mqtt\Fakes\RecordingAdapter;
use Tests\Unit\Mqtt\Fakes\RecordingRedis;
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
use Utopia\Mqtt\Packet\Disconnect;
use Utopia\Mqtt\Packet\Specs\V5;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;
use Utopia\Mqtt\Server;
use Utopia\Mqtt\Subscription\Store;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

use function Swoole\Coroutine\run;

/**
 * The broker's event handler for the 'mqtt' pub/sub channel processes every event of a worker
 * in one subscriber coroutine. A revoke verification stuck on a half-open database read (a read
 * that never returns and never errors) must not park that coroutine: the wait is bounded, the
 * broker fails closed, and the subscriber keeps delivering.
 *
 * run() leaves every runtime hook enabled and a reactor behind, so each test gets its own process.
 */
final class HandlerTest extends TestCase
{
    private const PROJECT = 'project1';

    private const AUTH_METHOD = 'appwrite-jwt';

    private const ABUSE_OPTION = '_APP_OPTIONS_ABUSE';

    /** Verification timeout: short so a red run fails fast rather than hanging. */
    private const float TIMEOUT = 0.2;

    private RecordingAdapter $network;

    private Store $store;

    private Server $server;

    private Handler $handler;

    private Accounts $accounts;

    private Database $database;

    /** A read on a half-open database connection: parks until the test pushes, like a dead TCP read. */
    private Channel $gate;

    private bool $blocking = false;

    private EventHandler $eventHandler;

    private string|false $previousAbuseOption = false;

    protected function setUp(): void
    {
        $this->previousAbuseOption = \getenv(self::ABUSE_OPTION);
        \putenv(self::ABUSE_OPTION . '=disabled');
        MutedStderr::mute(); // the fail-closed paths log to STDERR, which would fail the isolated process
    }

    protected function tearDown(): void
    {
        \putenv($this->previousAbuseOption === false ? self::ABUSE_OPTION : self::ABUSE_OPTION . '=' . $this->previousAbuseOption);
    }

    /**
     * Build the broker and its event handler. While $blocking is set, the authorizer and the first
     * database touch park on the gate, as a read on a half-open connection would.
     */
    private function boot(): void
    {
        $this->accounts = new Accounts();
        $this->gate = new Channel(1);

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

        $authorize = function (array $identity): bool {
            if ($this->blocking) {
                $this->gate->pop();
            }

            return $this->accounts->authorize($identity);
        };

        $container = new Container();
        $container->set('getRedis', fn (): \Closure => fn (): \Redis => new RecordingRedis());
        $container->set('authenticator', fn (): \Closure => $this->accounts->authenticate(...));
        $container->set('authorizer', fn (): \Closure => $authorize);
        $container->set('getPlan', fn (): \Closure => fn (string $projectId): array => []);
        $container->set('getConsoleDB', fn (): \Closure => function (): Database {
            if ($this->blocking) {
                $this->gate->pop();
            }

            return $this->database;
        });
        $container->set('getProjectDB', fn (): \Closure => fn (Document $project): Database => $this->database);

        $mqtt = new Mqtt(new NoTelemetry(), new NullPubSub());
        $this->handler = new Handler(
            $container,
            $mqtt,
            fn () => throw new \LogicException('the event handler must not touch the cache'),
            fn () => 5,
            fn () => throw new \LogicException('the event handler must not read the clock'),
        );

        $this->network = new RecordingAdapter();
        $this->store = new Store();
        $this->server = new Server($this->network, $this->handler, $this->store);
        $this->server->start();

        $this->eventHandler = new EventHandler($this->server, $this->handler, $mqtt, self::TIMEOUT);
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

    private function event(array $event): string
    {
        return (string) \json_encode(\array_merge(['project' => self::PROJECT], $event));
    }

    private function deliverEvent(string $topic, string $message): string
    {
        return $this->event([
            'topic' => $topic,
            'qos' => 0,
            'sequence' => 1,
            'publishedAt' => \microtime(true),
            'payload' => \base64_encode($message),
        ]);
    }

    /** Wait at most $seconds for $done, yielding so other coroutines run. */
    private function await(\Closure $done, float $seconds = 5.0): void
    {
        $deadline = \microtime(true) + $seconds;
        while (!$done() && \microtime(true) < $deadline) {
            Coroutine::sleep(0.01);
        }
    }

    #[RunInSeparateProcess]
    public function testStuckRevokeVerificationFailsClosedAndKeepsDelivering(): void
    {
        run(function (): void {
            $this->boot();
            $this->connect(1, 'victim');
            $this->connect(2, 'reader', 'news');
            $this->accounts->blocked['victim'] = true;
            $this->blocking = true;

            // The subscriber coroutine: events arrive in order, so a parked revoke starves delivery.
            $processed = [];
            go(function () use (&$processed): void {
                $this->eventHandler->handle(null, 'mqtt', $this->event(['action' => Mqtt::ACTION_REVOKE, 'userId' => 'victim']));
                $processed[] = 'revoke';
                $this->eventHandler->handle(null, 'mqtt', $this->deliverEvent('news', 'hello'));
                $processed[] = 'deliver';
            });

            $this->await(function () use (&$processed): bool {
                return \count($processed) === 2;
            });
            $this->assertSame(['revoke', 'deliver'], $processed, 'a stuck revoke verification starved the subscriber');

            // Test for SUCCESS: fail closed — the unverifiable user is excluded, delivery goes on.
            $disconnects = $this->network->disconnects(1);
            $this->assertCount(1, $disconnects, 'the unverifiable user was left connected');
            $this->assertSame(Disconnect::NOT_AUTHORIZED, $disconnects[0]->reasonCode);
            $this->assertSame([1], $this->network->closed);
            $this->assertCount(1, $this->network->publishes(2), 'the reader did not receive the message');

            // The read returns at last: the late check must tolerate connections already closed.
            $this->blocking = false;
            $this->gate->push(true);
            $this->await(fn (): bool => $this->accounts->authorizations > 0);
            Coroutine::sleep(0.05);
            $this->assertCount(1, $this->network->disconnects(1), 'the late verification disconnected the connection again');
            $this->assertSame([1], $this->network->closed);
        });
    }

    /** Run $dispatch with the coroutine table full, so go() cannot start a child. */
    private function saturated(\Closure $dispatch): void
    {
        Coroutine::set(['max_coroutine' => Coroutine::stats()['coroutine_num']]);
        try {
            // go() warns when refused; the refusal itself is what is under test.
            \set_error_handler(fn (): bool => true, E_WARNING);
            $dispatch();
        } finally {
            \restore_error_handler();
            Coroutine::set(['max_coroutine' => 100000]);
        }
    }

    #[RunInSeparateProcess]
    public function testRevokeAtCoroutineLimitFailsClosed(): void
    {
        run(function (): void {
            $this->boot();
            $this->connect(1, 'active'); // authorized: only a check could keep it connected
            $this->blocking = true; // an inline check would park here forever

            $this->saturated(function (): void {
                $this->eventHandler->handle(null, 'mqtt', $this->event(['action' => Mqtt::ACTION_REVOKE, 'userId' => 'active']));
            });

            // Test for SUCCESS: no inline check; the user is closed unverified and re-authenticates.
            $this->assertSame(0, $this->accounts->authorizations, 'the revoke was checked inline at the coroutine limit');
            $this->assertSame(Disconnect::NOT_AUTHORIZED, $this->network->disconnects(1)[0]->reasonCode);
            $this->assertSame([1], $this->network->closed);
        });
    }

    #[RunInSeparateProcess]
    public function testUnsubscribeAtCoroutineLimitFailsClosed(): void
    {
        run(function (): void {
            $this->boot();
            $this->connect(1, 'member', 'scores', 'news');
            $this->blocking = true;

            $this->saturated(function (): void {
                $this->eventHandler->handle(null, 'mqtt', $this->event(['action' => Mqtt::ACTION_UNSUBSCRIBE, 'userId' => 'member', 'topic' => 'scores']));
            });

            // Test for SUCCESS: the subscription is dropped unverified; the connection stays.
            $this->assertSame([], $this->store->getSubscribers(self::PROJECT, 'scores'));
            $this->assertArrayHasKey(1, $this->store->getSubscribers(self::PROJECT, 'news'));
            $this->assertSame([], $this->network->closed);
        });
    }

    #[RunInSeparateProcess]
    public function testFastRevokeDisconnectsExactlyTheUnauthorized(): void
    {
        run(function (): void {
            $this->boot();
            $this->connect(1, 'blocked');
            $this->connect(2, 'active', 'news');
            $this->accounts->blocked['blocked'] = true;

            $this->eventHandler->handle(null, 'mqtt', $this->event(['action' => Mqtt::ACTION_REVOKE, 'userId' => 'blocked']));
            $this->eventHandler->handle(null, 'mqtt', $this->event(['action' => Mqtt::ACTION_REVOKE, 'userId' => 'active']));
            $this->eventHandler->handle(null, 'mqtt', $this->deliverEvent('news', 'hello'));

            // Test for SUCCESS: the normal path is unchanged — verified revoke, no fail-closed cut.
            $this->assertSame(Disconnect::NOT_AUTHORIZED, $this->network->disconnects(1)[0]->reasonCode);
            $this->assertSame([1], $this->network->closed, 'an authorized user was disconnected');
            $this->assertCount(1, $this->network->publishes(2));
            $this->assertSame(2, $this->accounts->authorizations, 'a revoke was decided without verification');
        });
    }

    #[RunInSeparateProcess]
    public function testStuckUnsubscribeCheckDropsTheSubscriptionAndKeepsDelivering(): void
    {
        run(function (): void {
            $this->boot();
            // The membership still exists: only the timeout, never a completed check, drops it.
            $topicInternalId = (string) $this->database->createDocument('topics', new Document([
                '$id' => 'scores',
                'name' => 'scores',
            ]))->getSequence();
            $this->database->createDocument('subscribers', new Document([
                '$id' => Id::unique(),
                'topicInternalId' => $topicInternalId,
                'userId' => 'member',
            ]));
            $this->connect(1, 'member', 'scores', 'news');
            $this->blocking = true;

            $processed = [];
            go(function () use (&$processed): void {
                $this->eventHandler->handle(null, 'mqtt', $this->event(['action' => Mqtt::ACTION_UNSUBSCRIBE, 'userId' => 'member', 'topic' => 'scores']));
                $processed[] = 'unsubscribe';
                $this->eventHandler->handle(null, 'mqtt', $this->deliverEvent('news', 'hello'));
                $processed[] = 'deliver';
            });

            $this->await(function () use (&$processed): bool {
                return \count($processed) === 2;
            });
            $this->assertSame(['unsubscribe', 'deliver'], $processed, 'a stuck membership check starved the subscriber');

            // Test for SUCCESS: fail closed — only the unverifiable subscription is dropped.
            $this->assertSame([], $this->store->getSubscribers(self::PROJECT, 'scores'), 'the unverifiable subscription was kept');
            $this->assertCount(1, $this->network->publishes(1), 'the connection stopped receiving its other topics');
            $this->assertSame([], $this->network->disconnects(1), 'the connection was disconnected rather than unsubscribed');
            $this->assertSame([], $this->network->closed);

            // The read returns at last: the late check finds the membership and leaves things alone.
            $this->blocking = false;
            $this->gate->push(true);
            Coroutine::sleep(0.05);
            $this->assertArrayHasKey(1, $this->store->getSubscribers(self::PROJECT, 'news'), 'the late check dropped another subscription');
            $this->assertSame([], $this->network->closed);
        });
    }
}
