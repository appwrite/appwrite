<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt;

use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Mqtt\Handler;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Mqtt\Fakes\FieldCache;
use Tests\Unit\Mqtt\Fakes\InterleavingDatabase;
use Tests\Unit\Mqtt\Fakes\NullPubSub;
use Tests\Unit\Mqtt\Fakes\RecordingAdapter;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Container;
use Utopia\Mqtt\Connection;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\Puback;
use Utopia\Mqtt\Packet\Publish;
use Utopia\Mqtt\Packet\Specs\V5;
use Utopia\Mqtt\Packet\Subscribe;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

/**
 * The offline-replay cursor of Handler, driven through onSubscribe and onPuback against an
 * in-memory ledger and a hash cache, with a replay depth of five messages per topic.
 */
final class ReplayCursorTest extends TestCase
{
    private const PROJECT = 'project1';

    private const USER = 'user1';

    private const CLIENT = 'client1';

    private const CURSOR_KEY = 'push:cursor:' . self::PROJECT . ':' . self::USER . ':' . self::CLIENT;

    private const NOW = 1_700_000_000;

    private const MAX_DEPTH = 5;

    private InterleavingDatabase $database;

    private FieldCache $cache;

    private RecordingAdapter $adapter;

    private Handler $handler;

    protected function setUp(): void
    {
        $authorization = new Authorization();
        $authorization->addRole(Role::any()->toString());

        $this->database = new InterleavingDatabase(new Memory(), new Cache(new NoCache()));
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
            Attribute::integer('sequence', default: 0),
            Attribute::integer('qos', default: Packet::QOS_1),
            Attribute::integer('expiry', default: 0),
        ], permissions: $any, documentSecurity: false));

        $this->database->createCollection(Collection::create('pushLedger', attributes: [
            Attribute::string('topic', 255, true),
            Attribute::string('data', 65535, true),
            Attribute::string('messageId', 255),
            Attribute::integer('sequence', true),
        ], permissions: $any, documentSecurity: false));

        $container = new Container();
        $container->set('getConsoleDB', fn () => fn (): Database => $this->database, []);
        $container->set('getProjectDB', fn () => fn (Document $project): Database => $this->database, []);
        $container->set('authorizer', fn () => fn (array $identity): bool => true, []);

        $this->cache = new FieldCache(fn (): int => self::NOW);
        $this->adapter = new RecordingAdapter();
        $this->handler = new Handler(
            $container,
            new Mqtt(new NoTelemetry(), new NullPubSub()),
            fn (): Cache => new Cache($this->cache),
            fn (): int => self::MAX_DEPTH,
        );
    }

    public function testTruncatedReplayAdvancesCursorToTail(): void
    {
        $this->seedTopic('news', 12);
        $this->appendLedger('news', 1, 12);
        $this->seedCursor('news', 2);
        $connection = $this->connect(1);

        $this->subscribe($connection, 'news');
        $replayed = $this->drainPublishes(1);
        $this->acknowledge($connection, ...$replayed);

        $this->assertSame(['message-8', 'message-9', 'message-10', 'message-11', 'message-12'], $this->payloads($replayed));
        $this->assertSame(12, $this->storedCursor('news'), 'acknowledging the truncated replay must advance the cursor to the tail');

        $this->subscribe($this->connect(2), 'news');

        $this->assertSame([], $this->payloads($this->drainPublishes(2)), 'a reconnect must not replay messages already acknowledged');
    }

    public function testFullReplayAdvancesCursor(): void
    {
        $this->seedTopic('news', 12);
        $this->appendLedger('news', 1, 12);
        $this->seedCursor('news', 9);
        $connection = $this->connect(1);

        $this->subscribe($connection, 'news');
        $replayed = $this->drainPublishes(1);
        $this->acknowledge($connection, ...$replayed);

        $this->assertSame(['message-10', 'message-11', 'message-12'], $this->payloads($replayed));
        $this->assertSame(12, $this->storedCursor('news'));
    }

    public function testPrunedLedgerAnchorsAtTail(): void
    {
        $this->seedTopic('news', 12);
        $this->appendLedger('news', 1, 2);
        $this->seedCursor('news', 2);

        $this->subscribe($this->connect(1), 'news');

        $this->assertSame([], $this->payloads($this->drainPublishes(1)));
        $this->assertSame(12, $this->storedCursor('news'), 'a backlog pruned from the ledger must not pin the cursor below it');
    }

    public function testResubscribeReplaysOnlyNewMessages(): void
    {
        $this->seedTopic('news', 12);
        $this->appendLedger('news', 1, 12);
        $this->seedCursor('news', 9);
        $connection = $this->connect(1);
        $this->subscribe($connection, 'news');
        $this->acknowledge($connection, ...$this->drainPublishes(1));
        $this->appendLedger('news', 13, 14);
        $this->updateTail('news', 14);

        $this->subscribe($connection, 'news');
        $persisted = $this->storedCursor('news');
        $replayed = $this->drainPublishes(1);
        $this->acknowledge($connection, ...$replayed);

        $this->assertSame(['message-13', 'message-14'], $this->payloads($replayed));
        $this->assertSame(12, $persisted, 'a re-SUBSCRIBE must not persist a cursor below what was acknowledged');
        $this->assertSame(14, $this->storedCursor('news'));
    }

    public function testInsertDuringSubscribeIsReplayed(): void
    {
        $this->seedTopic('news', 12);
        $this->appendLedger('news', 1, 12);
        $this->seedCursor('news', 2);
        $connection = $this->connect(1);
        $this->database->interleave('pushLedger', 1, function (): void {
            $this->appendLedger('news', 13, 13);
            $this->updateTail('news', 13);
        });

        $this->subscribe($connection, 'news');
        $replayed = $this->drainPublishes(1);
        $this->acknowledge($connection, ...$replayed);

        $this->assertSame(['message-9', 'message-10', 'message-11', 'message-12', 'message-13'], $this->payloads($replayed), 'a message committed after the topic was read must still be replayed');
        $this->assertSame(13, $this->storedCursor('news'), 'acknowledging the replay must advance the cursor past the late message');

        $this->subscribe($this->connect(2), 'news');

        $this->assertSame([], $this->payloads($this->drainPublishes(2)), 'a reconnect must not replay messages already acknowledged');
    }

    public function testLedgerLookupIsBoundedBelowByDepth(): void
    {
        $this->seedTopic('news', 12);
        $this->appendLedger('news', 1, 12);
        $this->seedCursor('news', 2);

        $this->subscribe($this->connect(1), 'news');

        $finds = $this->database->finds('pushLedger');
        $this->assertCount(1, $finds);
        $queries = $finds[0];

        $this->assertContainsEquals(
            Query::greaterThan('sequence', \max(2, 12 - self::MAX_DEPTH)),
            $queries,
            'the ledger lookup must be bounded below by the replay depth so it reads at most that many rows',
        );
        $this->assertContainsEquals(
            Query::orderDesc('$sequence'),
            $queries,
            'the ledger lookup must break ties descending so the (topic, sequence) index serves it with a backward range scan',
        );
    }

    public function testAcknowledgementDuringReplayIsNotOverwritten(): void
    {
        $this->seedTopic('news', 12);
        $this->appendLedger('news', 1, 12);
        $this->seedCursor('news', 9);
        $this->seedTopic('scores', 5);
        $this->appendLedger('scores', 1, 5);
        $this->seedCursor('scores', 3);
        $connection = $this->connect(1);
        $this->database->interleave('pushLedger', 2, function () use ($connection): void {
            $this->acknowledge($connection, ...$this->drainPublishes(1));
        });

        $this->subscribe($connection, 'news', 'scores');

        $this->assertSame(12, $this->storedCursor('news'), 'the replay persisted a cursor below acknowledgements made while it ran');
        $this->assertSame(3, $this->storedCursor('scores'));
        $this->assertSame(['message-4', 'message-5'], $this->payloads($this->drainPublishes(1)));
    }

    public function testAcknowledgementDuringLedgerFindIsNotLowered(): void
    {
        $this->seedTopic('news', 12);
        $this->appendLedger('news', 1, 12);
        $this->seedCursor('news', 9);
        $connection = $this->connect(1);
        $this->subscribe($connection, 'news');
        $this->acknowledge($connection, ...$this->drainPublishes(1));
        $connection->publish('news', 'message-13', qos: Packet::QOS_1, sequence: 13);
        $connection->publish('news', 'message-14', qos: Packet::QOS_1, sequence: 14);
        $live = $this->drainPublishes(1);
        $this->updateTail('news', 13);
        $this->database->interleave('pushLedger', 2, function () use ($connection, $live): void {
            $this->acknowledge($connection, ...$live);
        });

        $this->subscribe($connection, 'news');

        $this->assertSame(14, $connection->cursor('news'), 'acknowledgements made while the ledger was queried must not be lowered');
        $this->assertSame(14, $this->storedCursor('news'));

        $connection->publish('news', 'message-15', qos: Packet::QOS_1, sequence: 15);
        $this->acknowledge($connection, ...$this->drainPublishes(1));

        $this->assertSame(15, $this->storedCursor('news'), 'the next live acknowledgement must keep advancing the cursor');
    }

    public function testAcknowledgementDuringCursorReadIsNotRewoundOnResubscribe(): void
    {
        $this->seedTopic('news', 12);
        $this->appendLedger('news', 1, 12);
        $this->seedCursor('news', 9);
        $connection = $this->connect(1);
        $this->subscribe($connection, 'news');
        $this->acknowledge($connection, ...$this->drainPublishes(1));
        $connection->publish('news', 'message-13', qos: Packet::QOS_1, sequence: 13);
        $connection->publish('news', 'message-14', qos: Packet::QOS_1, sequence: 14);
        $live = $this->drainPublishes(1);
        $this->appendLedger('news', 13, 14);
        $this->updateTail('news', 14);
        $this->cache->interleaveNextLoad(function () use ($connection, $live): void {
            $this->acknowledge($connection, ...$live);
        });

        $this->subscribe($connection, 'news');

        $this->assertSame([], $this->payloads($this->drainPublishes(1)), 'messages acknowledged while the cursor was read must not be replayed');
        $this->assertSame(14, $connection->cursor('news'));
        $this->assertSame(14, $this->storedCursor('news'));
    }

    public function testLiveProgressOnATopicWithNoBacklogIsNotRewoundOnResubscribe(): void
    {
        $this->seedTopic('news', 12);
        $this->appendLedger('news', 1, 12);
        $this->seedCursor('news', 12);
        $connection = $this->connect(1);
        $this->subscribe($connection, 'news');
        $connection->publish('news', 'message-13', qos: Packet::QOS_1, sequence: 13);
        $connection->publish('news', 'message-14', qos: Packet::QOS_1, sequence: 14);
        [$first, $second] = $this->drainPublishes(1);
        $this->acknowledge($connection, $first);
        $this->appendLedger('news', 13, 14);
        $this->updateTail('news', 14);
        $this->cache->interleaveNextLoad(function () use ($connection, $second): void {
            $this->acknowledge($connection, $second);
        });

        $this->subscribe($connection, 'news');

        $this->assertSame([], $this->payloads($this->drainPublishes(1)), 'live messages acknowledged while the cursor was read must not be replayed');
        $this->assertSame(14, $this->storedCursor('news'));
    }

    public function testConcurrentAcknowledgementsDoNotLowerTheStoredCursor(): void
    {
        $this->seedTopic('news', 12);
        $this->appendLedger('news', 1, 12);
        $this->seedCursor('news', 12);
        $connection = $this->connect(1);
        $this->subscribe($connection, 'news');
        $connection->publish('news', 'message-13', qos: Packet::QOS_1, sequence: 13);
        $connection->publish('news', 'message-14', qos: Packet::QOS_1, sequence: 14);
        [$first, $second] = $this->drainPublishes(1);
        $this->cache->interleaveNextLoad(function () use ($connection, $second): void {
            $this->acknowledge($connection, $second);
        });

        $this->acknowledge($connection, $first);

        $this->assertSame(14, $this->storedCursor('news'), 'a PUBACK finishing after a later one must not persist its older cursor');
    }

    public function testTopicWithNothingToReplayKeepsAcknowledgedCursor(): void
    {
        $this->seedTopic('news', 12);
        $this->appendLedger('news', 1, 12);
        $this->seedCursor('news', 12);
        $this->seedTopic('scores', 5);
        $this->appendLedger('scores', 1, 5);
        $this->seedCursor('scores', 3);
        $connection = $this->connect(1);
        $this->subscribe($connection, 'news');
        $this->database->interleave('pushLedger', 1, function () use ($connection): void {
            $this->appendLedger('news', 13, 13);
            $this->updateTail('news', 13);
            $connection->publish('news', 'message-13', qos: Packet::QOS_1, sequence: 13);
            $this->acknowledge($connection, ...$this->drainPublishes(1));
        });

        $this->subscribe($connection, 'news', 'scores');

        $this->assertSame(13, $this->storedCursor('news'), 'a topic with nothing to replay must not persist a cursor below a live acknowledgement');
        $this->assertSame(['message-4', 'message-5'], $this->payloads($this->drainPublishes(1)));
    }

    private function seedTopic(string $name, int $tail): void
    {
        $this->database->createDocument('topics', new Document([
            '$id' => $name,
            'name' => $name,
            'sequence' => $tail,
            'qos' => Packet::QOS_1,
        ]));
    }

    private function updateTail(string $name, int $tail): void
    {
        $this->database->updateDocument('topics', $name, new Document(['sequence' => $tail]));
    }

    private function appendLedger(string $topic, int $from, int $to): void
    {
        foreach (\range($from, $to) as $sequence) {
            $this->database->createDocument('pushLedger', new Document([
                'topic' => $topic,
                'data' => 'message-' . $sequence,
                'messageId' => 'message' . $sequence,
                'sequence' => $sequence,
            ]));
        }
    }

    private function seedCursor(string $topic, int $sequence): void
    {
        $this->cache->seed(self::CURSOR_KEY, $topic, ['sequence' => $sequence], self::NOW);
    }

    private function storedCursor(string $topic): ?int
    {
        return $this->cache->fields[self::CURSOR_KEY][$topic]['value']['sequence'] ?? null;
    }

    private function connect(int $fd): Connection
    {
        $connection = new Connection($fd, $this->adapter);
        $connection->protocol = V5::PROTOCOL_LEVEL;
        $connection->prefix = self::PROJECT;
        $connection->identity = ['projectId' => self::PROJECT, 'userId' => self::USER];
        $connection->cleanStart = false;
        $connection->setClientId(self::CLIENT);

        return $connection;
    }

    private function subscribe(Connection $connection, string ...$topics): void
    {
        $packet = Packet::parse(V5::subscribe(1, $topics, Packet::QOS_1));

        $this->handler->onSubscribe(Subscribe::decode($packet->body, V5::PROTOCOL_LEVEL), $connection);
    }

    /** @return list<Publish> */
    private function drainPublishes(int $fd): array
    {
        return \array_map(
            fn (Packet $packet): Publish => Publish::decode($packet, V5::PROTOCOL_LEVEL),
            $this->adapter->publishes($fd),
        );
    }

    private function acknowledge(Connection $connection, Publish ...$publishes): void
    {
        foreach ($publishes as $publish) {
            $this->handler->onPuback(Puback::decode(\pack('n', $publish->packetId)), $connection);
        }
    }

    /**
     * @param list<Publish> $publishes
     * @return list<string>
     */
    private function payloads(array $publishes): array
    {
        return \array_map(fn (Publish $publish): string => $publish->payload, $publishes);
    }
}
