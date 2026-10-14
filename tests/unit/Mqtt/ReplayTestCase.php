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
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
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
 * Drives the offline replay of Handler through onSubscribe and onPuback against an in-memory
 * project database (topics, providers and the push ledger) and a hash cache, with a replay depth
 * of five messages per topic. Ledger writes, the cache and the handler all read the one clock $now.
 */
abstract class ReplayTestCase extends TestCase
{
    protected const PROJECT = 'project1';

    protected const USER = 'user1';

    protected const CLIENT = 'client1';

    protected const CURSOR_KEY = 'push:cursor:' . self::PROJECT . ':' . self::USER . ':' . self::CLIENT;

    protected const NOW = 1_700_000_000;

    protected const MAX_DEPTH = 5;

    protected InterleavingDatabase $database;

    protected FieldCache $cache;

    protected RecordingAdapter $adapter;

    protected Handler $handler;

    protected int $now = self::NOW;

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

        $this->database->createCollection('projects', [], [], $any, false);

        $this->database->createCollection('topics', [], [], $any, false);
        $this->database->createAttribute('topics', 'name', Database::VAR_STRING, 255, true);
        $this->database->createAttribute('topics', 'sequence', Database::VAR_INTEGER, 0, false, 0);
        $this->database->createAttribute('topics', 'qos', Database::VAR_INTEGER, 0, false, Packet::QOS_1);
        $this->database->createAttribute('topics', 'expiry', Database::VAR_INTEGER, 0, false, null, false);

        $this->database->createCollection('providers', [], [], $any, false);
        $this->database->createAttribute('providers', 'provider', Database::VAR_STRING, 255, true);
        $this->database->createAttribute('providers', 'enabled', Database::VAR_BOOLEAN, 0, false, true);
        $this->database->createAttribute('providers', 'options', Database::VAR_STRING, 16384, false, null, filters: ['json']);

        $this->database->createCollection('pushLedger', [], [], $any, false);
        $this->database->createAttribute('pushLedger', 'topic', Database::VAR_STRING, 255, true);
        $this->database->createAttribute('pushLedger', 'data', Database::VAR_STRING, 65535, true);
        $this->database->createAttribute('pushLedger', 'messageId', Database::VAR_STRING, 255, false);
        $this->database->createAttribute('pushLedger', 'sequence', Database::VAR_INTEGER, 0, true);

        $this->database->createCollection('subscribers', [], [], $any, false);
        $this->database->createAttribute('subscribers', 'topicInternalId', Database::VAR_STRING, 255, true);
        $this->database->createAttribute('subscribers', 'userId', Database::VAR_STRING, 255, true);

        $container = new Container();
        $container->set('getConsoleDB', fn () => fn (): Database => $this->database, []);
        $container->set('getProjectDB', fn () => fn (Document $project): Database => $this->database, []);
        $container->set('authorizer', fn () => fn (array $identity): bool => true, []);

        $this->now = self::NOW;
        $this->cache = new FieldCache(fn (): int => $this->now);
        $this->adapter = new RecordingAdapter();
        $this->handler = new Handler(
            $container,
            new Mqtt(new NoTelemetry(), new NullPubSub()),
            fn (): Cache => new Cache($this->cache),
            fn (): int => self::MAX_DEPTH,
            fn (): \DateTimeImmutable => new \DateTimeImmutable('@' . $this->now),
        );
    }

    protected function seedTopic(string $name, int $tail, ?int $expiry = null, ?string $id = null, bool $subscribed = true): string
    {
        $topic = $this->database->createDocument('topics', new Document([
            '$id' => $id ?? $name,
            'name' => $name,
            'sequence' => $tail,
            'qos' => Packet::QOS_1,
            'expiry' => $expiry,
        ]));

        // Ordinary topics are gated by the subscriber list; seed the test user's membership so replay
        // is granted. Reserved users/<id> topics are owner-gated and ignore this.
        if ($subscribed) {
            $this->database->createDocument('subscribers', new Document([
                '$id' => ID::unique(),
                'topicInternalId' => (string) $topic->getSequence(),
                'userId' => self::USER,
            ]));
        }

        return $topic->getId();
    }

    protected function updateTail(string $topic, int $tail): void
    {
        $this->database->updateDocument('topics', $topic, new Document(['sequence' => $tail]));
    }

    /** Append messages $from..$to to a topic's ledger, written $age seconds ago. */
    protected function appendLedger(string $topic, int $from, int $to, int $age = 0): void
    {
        $createdAt = DateTime::format((new \DateTime())->setTimestamp($this->now - $age));

        $this->database->withPreserveDates(function () use ($topic, $from, $to, $createdAt): void {
            foreach (\range($from, $to) as $sequence) {
                $this->database->createDocument('pushLedger', new Document([
                    '$createdAt' => $createdAt,
                    '$updatedAt' => $createdAt,
                    'topic' => $topic,
                    'data' => 'message-' . $sequence,
                    'messageId' => 'message' . $sequence,
                    'sequence' => $sequence,
                ]));
            }
        });
    }

    protected function seedProvider(string $provider, bool $enabled, ?int $expiry): string
    {
        return $this->database->createDocument('providers', new Document([
            '$id' => ID::unique(),
            'provider' => $provider,
            'enabled' => $enabled,
            'options' => ['qos' => null, 'expiry' => $expiry],
        ]))->getId();
    }

    protected function updateProviderExpiry(string $provider, ?int $expiry): void
    {
        $this->database->updateDocument('providers', $provider, new Document([
            'options' => ['qos' => null, 'expiry' => $expiry],
        ]));
    }

    protected function seedCursor(string $topic, int $sequence, ?int $writtenAt = null, ?string $topicSequence = null): void
    {
        $value = $topicSequence === null ? ['sequence' => $sequence] : ['sequence' => $sequence, 'topic' => $topicSequence];
        $this->cache->seed(self::CURSOR_KEY, $topic, $value, $writtenAt ?? $this->now);
    }

    protected function storedCursor(string $topic): ?int
    {
        return $this->cache->fields[self::CURSOR_KEY][$topic]['value']['sequence'] ?? null;
    }

    protected function storedCursorTopic(string $topic): ?string
    {
        return $this->cache->fields[self::CURSOR_KEY][$topic]['value']['topic'] ?? null;
    }

    protected function topicSequence(string $topic): string
    {
        return (string) $this->database->getDocument('topics', $topic)->getSequence();
    }

    protected function cursorTtl(): ?int
    {
        return $this->cache->ttls[self::CURSOR_KEY] ?? null;
    }

    protected function connect(int $fd): Connection
    {
        $connection = new Connection($fd, $this->adapter);
        $connection->protocol = V5::PROTOCOL_LEVEL;
        $connection->prefix = self::PROJECT;
        $connection->identity = ['projectId' => self::PROJECT, 'userId' => self::USER];
        $connection->cleanStart = false;
        $connection->setClientId(self::CLIENT);

        return $connection;
    }

    protected function subscribe(Connection $connection, string ...$topics): void
    {
        $this->subscribeCodes($connection, ...$topics);
    }

    /**
     * Subscribe and return the SUBACK reason code per filter (QoS granted, or Suback::DENIED).
     *
     * @return list<int>
     */
    protected function subscribeCodes(Connection $connection, string ...$topics): array
    {
        $packet = Packet::parse(V5::subscribe(1, $topics, Packet::QOS_1));

        return $this->handler->onSubscribe(Subscribe::decode($packet->body, V5::PROTOCOL_LEVEL), $connection)->codes();
    }

    /** @return list<Publish> */
    protected function drainPublishes(int $fd): array
    {
        return \array_map(
            fn (Packet $packet): Publish => Publish::decode($packet, V5::PROTOCOL_LEVEL),
            $this->adapter->publishes($fd),
        );
    }

    protected function acknowledge(Connection $connection, Publish ...$publishes): void
    {
        foreach ($publishes as $publish) {
            $this->handler->onPuback(Puback::decode(\pack('n', $publish->packetId)), $connection);
        }
    }

    /**
     * @param list<Publish> $publishes
     * @return list<string>
     */
    protected function payloads(array $publishes): array
    {
        return \array_map(fn (Publish $publish): string => $publish->payload, $publishes);
    }
}
