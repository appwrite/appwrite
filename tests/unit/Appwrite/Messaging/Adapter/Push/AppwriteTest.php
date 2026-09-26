<?php

declare(strict_types=1);

namespace Tests\Unit\Appwrite\Messaging\Adapter\Push;

use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Messaging\Adapter\Push\Appwrite as AppwritePush;
use Appwrite\PubSub\Adapter as PubSub;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Messaging\Exception\InvalidArgumentException;
use Utopia\Messaging\Messages\Email;
use Utopia\Messaging\Messages\Push;
use Utopia\Messaging\Priority;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

/**
 * A stand-in broker that records each fan-out instead of touching Redis. The real
 * Mqtt::send() publishes onto the pub/sub pool (a live pool, exercised by e2e); here
 * we only care that the adapter hands it the right project, topic, payload and QoS.
 */
final class FakeBroker extends Mqtt
{
    /** @var array<int, array{projectId: string, channels: array<int, string>, options: array<string, mixed>}> */
    public array $published = [];

    /** @var array<int, string> topics to reject, to simulate a fan-out failure */
    public array $failTopics = [];

    public function __construct()
    {
        // send() is overridden below, so the pub/sub is never used; a null-object satisfies it.
        parent::__construct(new NoTelemetry(), new class () implements PubSub {
            public function ping($message = null): bool
            {
                return true;
            }

            public function subscribe($channels, $callback)
            {
            }

            public function publish($channel, $message)
            {
            }
        });
    }

    public function send(string $projectId, array $payload, array $events, array $channels, array $roles, array $options = []): void
    {
        foreach ($channels as $topic) {
            if (\in_array($topic, $this->failTopics, true)) {
                throw new \RuntimeException("broker refused {$topic}");
            }
        }

        $this->published[] = [
            'projectId' => $projectId,
            'channels' => $channels,
            'options' => $options,
        ];
    }
}

/**
 * Unit tests for the built-in Appwrite push provider: the append-to-ledger plus
 * internal fan-out that process() performs per topic. A real in-memory Database
 * backs the topics counter and the messages_appwrite ledger, and a FakeBroker
 * captures the fan-out, so no Redis or transport is involved.
 *
 * The broker CONNECT/publish/subscribe transport is covered by the mqtt-grouped
 * e2e (MessagingMqttServerTest) instead.
 */
final class AppwriteTest extends TestCase
{
    private const PROJECT_ID = 'project-a';

    private Database $database;

    protected function setUp(): void
    {
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

        // The topic counter (`sequence`, incremented per publish) plus the `name` the adapter
        // resolves from the id and fans out under (subscribers match on the name, not the id).
        $this->database->createCollection('topics', [], [], $any, false);
        $this->database->createAttribute('topics', 'sequence', Database::VAR_INTEGER, 0, false, 0);
        $this->database->createAttribute('topics', 'name', Database::VAR_STRING, 255, false);

        // The append-only ledger. `data` is a plain string here (the adapter passes an
        // already-encoded JSON envelope); the production collection's json filter is a
        // storage detail, not adapter behaviour.
        $this->database->createCollection('pushLedger', [], [], $any, false);
        $this->database->createAttribute('pushLedger', 'topic', Database::VAR_STRING, 255, true);
        $this->database->createAttribute('pushLedger', 'data', Database::VAR_STRING, 65535, true);
        $this->database->createAttribute('pushLedger', 'messageId', Database::VAR_STRING, 255, false);
        $this->database->createAttribute('pushLedger', 'messageInternalId', Database::VAR_STRING, 255, false);
        $this->database->createAttribute('pushLedger', 'sequence', Database::VAR_INTEGER, 0, true);
    }

    /** Seed a topic row with a starting sequence (the current tail) and its subscriber-facing name. */
    private function seedTopic(string $topicId, int $sequence = 0, ?string $name = null): void
    {
        $this->database->createDocument('topics', new Document([
            '$id' => $topicId,
            'sequence' => $sequence,
            'name' => $name ?? $topicId . '-name',
        ]));
    }

    private function adapter(FakeBroker $broker, int $qos = 1, string $messageId = 'msg-1', ?string $messageInternalId = 'internal-1'): AppwritePush
    {
        return new AppwritePush(
            $broker,
            $this->database,
            self::PROJECT_ID,
            $messageId,
            $messageInternalId,
            $qos,
        );
    }

    /**
     * @return array<int, Document>
     */
    private function ledger(): array
    {
        return $this->database->getAuthorization()->skip(fn () => $this->database->find('pushLedger'));
    }

    /** The topic counter row, read past authorization. */
    private function topic(string $topicId): Document
    {
        return $this->database->getAuthorization()->skip(fn () => $this->database->getDocument('topics', $topicId));
    }

    public function testMetadata(): void
    {
        $adapter = $this->adapter(new FakeBroker());

        $this->assertSame('Appwrite', $adapter->getName());
        $this->assertSame('push', $adapter->getType());
        $this->assertSame(Push::class, $adapter->getMessageType());
        $this->assertSame(5000, $adapter->getMaxMessagesPerRequest());
    }

    public function testPublishWritesLedgerAndFansOut(): void
    {
        $this->seedTopic('topic-1', 0);
        $broker = new FakeBroker();

        $response = $this->adapter($broker, qos: 1)->send(new Push(
            to: ['topic-1'],
            title: 'Hi',
            body: 'Hello',
        ));

        // Response: one topic, delivered, no failures.
        $this->assertSame('push', $response['type']);
        $this->assertSame(1, $response['deliveredTo']);
        $this->assertCount(1, $response['results']);
        $this->assertSame('success', $response['results'][0]['status']);
        $this->assertSame('topic-1', $response['results'][0]['recipient']);

        // The topic counter advanced 0 -> 1.
        $topic = $this->topic('topic-1');
        $this->assertSame(1, $topic->getAttribute('sequence'));

        // One ledger row, carrying the copied sequence and the source message ids.
        $ledger = $this->ledger();
        $this->assertCount(1, $ledger);
        $row = $ledger[0];
        $this->assertSame('topic-1', $row->getAttribute('topic'));
        $this->assertSame(1, $row->getAttribute('sequence'));
        $this->assertSame('msg-1', $row->getAttribute('messageId'));
        $this->assertSame('internal-1', $row->getAttribute('messageInternalId'));

        // The fan-out reached the broker with the same project, topic, payload and QoS.
        $this->assertCount(1, $broker->published);
        $publish = $broker->published[0];
        $this->assertSame(self::PROJECT_ID, $publish['projectId']);
        // The fan-out carries the topic name subscribers match on, not the id.
        $this->assertSame(['topic-1-name'], $publish['channels']);
        $this->assertSame(1, $publish['options']['qos']);
        // The ledger stores exactly what was published.
        $this->assertSame($row->getAttribute('data'), $publish['options']['payload']);
    }

    public function testPayloadEnvelopeShape(): void
    {
        $this->seedTopic('topic-1', 0);
        $broker = new FakeBroker();

        $this->adapter($broker)->send(new Push(
            to: ['topic-1'],
            title: 'Match tonight',
            body: 'India vs Australia',
            data: ['matchId' => '42'],
            priority: Priority::HIGH,
        ));

        $payload = \json_decode($broker->published[0]['options']['payload'], true);

        $this->assertSame('Match tonight', $payload['notification']['title']);
        $this->assertSame('India vs Australia', $payload['notification']['body']);
        $this->assertSame(['matchId' => '42'], $payload['data']);
        $this->assertSame('high', $payload['priority']);
    }

    public function testSequenceAdvancesPerPublish(): void
    {
        $this->seedTopic('topic-1', 0);
        $broker = new FakeBroker();

        // Two distinct campaigns (distinct message ids) to the same topic.
        $this->adapter($broker, messageId: 'msg-1')->send(new Push(to: ['topic-1'], title: 'first'));
        $this->adapter($broker, messageId: 'msg-2')->send(new Push(to: ['topic-1'], title: 'second'));

        $sequences = \array_map(
            static fn (Document $row): int => $row->getAttribute('sequence'),
            $this->ledger(),
        );
        \sort($sequences);
        $this->assertSame([1, 2], $sequences);

        $topic = $this->topic('topic-1');
        $this->assertSame(2, $topic->getAttribute('sequence'));
    }

    public function testRepublishIsIdempotent(): void
    {
        // Re-sending the same campaign (a retry after a transient failure) must not
        // advance the sequence or add a second ledger row for the (message, topic) pair.
        $this->seedTopic('topic-1', 0);
        $broker = new FakeBroker();

        $this->adapter($broker, messageId: 'msg-1')->send(new Push(to: ['topic-1'], title: 'once'));
        $this->adapter($broker, messageId: 'msg-1')->send(new Push(to: ['topic-1'], title: 'once'));

        $this->assertCount(1, $this->ledger());
        $this->assertSame(1, $this->topic('topic-1')->getAttribute('sequence'));
    }

    public function testEachTopicGetsItsOwnSequence(): void
    {
        // Two topics at different tails: each advances independently.
        $this->seedTopic('topic-1', 5);
        $this->seedTopic('topic-2', 0);
        $broker = new FakeBroker();

        $response = $this->adapter($broker)->send(new Push(
            to: ['topic-1', 'topic-2'],
            title: 'broadcast',
        ));

        $this->assertSame(2, $response['deliveredTo']);
        $this->assertCount(2, $broker->published);

        $sequenceByTopic = [];
        foreach ($this->ledger() as $row) {
            $sequenceByTopic[$row->getAttribute('topic')] = $row->getAttribute('sequence');
        }
        $this->assertSame(6, $sequenceByTopic['topic-1']);
        $this->assertSame(1, $sequenceByTopic['topic-2']);
    }

    public function testUserAddressedPushIsDelivered(): void
    {
        // A user target (users/<id>) needs no pre-created topic and is delivered on that channel
        // with the notification payload intact.
        $broker = new FakeBroker();

        $response = $this->adapter($broker)->send(new Push(
            to: ['users/user-1'],
            title: 'Hi',
            body: 'Hello',
        ));

        $this->assertSame(1, $response['deliveredTo']);
        $this->assertSame('success', $response['results'][0]['status']);
        $this->assertSame('users/user-1', $broker->published[0]['channels'][0]);
        $payload = \json_decode($broker->published[0]['options']['payload'], true);
        $this->assertSame('Hello', $payload['notification']['body']);
    }

    public function testRepeatedUserPushesRemainDeliverable(): void
    {
        // Sending to the same user again still delivers on its channel (the implicit topic persists
        // across campaigns without being re-created by the caller).
        $broker = new FakeBroker();

        $first = $this->adapter($broker, messageId: 'msg-1')->send(new Push(to: ['users/user-1'], title: 'first'));
        $second = $this->adapter($broker, messageId: 'msg-2')->send(new Push(to: ['users/user-1'], title: 'second'));

        $this->assertSame(1, $first['deliveredTo']);
        $this->assertSame(1, $second['deliveredTo']);
        $this->assertSame('users/user-1', $broker->published[0]['channels'][0]);
        $this->assertSame('users/user-1', $broker->published[1]['channels'][0]);
    }

    public function testUnknownTopicFailsWithoutSinkingOthers(): void
    {
        // Only the first topic exists; the second has no counter row to increment.
        $this->seedTopic('topic-1', 0);
        $broker = new FakeBroker();

        $response = $this->adapter($broker)->send(new Push(
            to: ['topic-1', 'missing-topic'],
            title: 'Hi',
        ));

        // The good topic is delivered; the missing one is a per-recipient failure.
        $this->assertSame(1, $response['deliveredTo']);
        $this->assertCount(2, $response['results']);

        $byRecipient = [];
        foreach ($response['results'] as $result) {
            $byRecipient[$result['recipient']] = $result;
        }
        $this->assertSame('success', $byRecipient['topic-1']['status']);
        $this->assertSame('failure', $byRecipient['missing-topic']['status']);
        $this->assertNotSame('', $byRecipient['missing-topic']['error']);

        // The failure left no ledger row and never reached the broker.
        $ledger = $this->ledger();
        $this->assertCount(1, $ledger);
        $this->assertSame('topic-1', $ledger[0]->getAttribute('topic'));
        $this->assertCount(1, $broker->published);
        $this->assertSame(['topic-1-name'], $broker->published[0]['channels']);
    }

    public function testFanOutFailureIsRecordedPerTopic(): void
    {
        // The ledger write succeeds but the broker rejects the fan-out: the topic is a
        // failure, yet the durable row is already written for later replay.
        $this->seedTopic('topic-1', 0);
        $broker = new FakeBroker();
        // The broker rejects by the fanned-out channel, which is the topic name.
        $broker->failTopics = ['topic-1-name'];

        $response = $this->adapter($broker)->send(new Push(
            to: ['topic-1'],
            title: 'Hi',
        ));

        $this->assertSame(0, $response['deliveredTo']);
        $this->assertSame('failure', $response['results'][0]['status']);
        $this->assertCount(1, $this->ledger());
        $this->assertCount(0, $broker->published);
    }

    public function testInvalidMessageTypeRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // A non-push message is rejected by the base adapter before process() runs.
        $this->adapter(new FakeBroker())->send(new Email(
            to: ['nobody@appwrite.io'],
            subject: 'nope',
            content: 'nope',
            fromName: 'Appwrite',
            fromEmail: 'noreply@appwrite.io',
        ));
    }
}
