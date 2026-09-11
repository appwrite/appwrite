<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Messaging;

use Appwrite\Messaging\Status as MessageStatus;
use PHPUnit\Framework\Attributes\Group;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

/**
 * End-to-end tests for the MQTT push broker (src/Utopia/Mqtt). Publishing is a server
 * privilege driven entirely through the Messaging HTTP campaign path (there is no
 * outside MQTT publish); the subscriber side is a self-contained MqttSubscriber over
 * TCP. Exercises enhanced-auth CONNECT against the project/user graph, campaign
 * fan-out to a live subscriber, and offline QoS 1 session replay from the ledger.
 *
 * Blocked/unauthenticated accounts are refused at CONNECT (CONNACK reason 0x87).
 *
 * Grouped `mqtt` because it needs the broker container (appwrite-mqtt:1883): the
 * standard e2e stacks exclude this group, and a dedicated lane that runs the broker
 * selects it with --group mqtt.
 */
#[Group('mqtt')]
final class MessagingMqttServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    private const BROKER_HOST = 'appwrite-mqtt';
    private const BROKER_PORT = 1883;

    /**
     * Create a user and mint a session-less JWT for it. The broker's JWT path skips
     * the session check when the payload carries no sessionId, so the user resolves
     * as long as it exists in the project.
     *
     * @return array{userId: string, jwt: string}
     */
    private function createUser(): array
    {
        $userId = ID::unique();

        $user = $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => $userId,
            'email' => 'mqtt-' . $userId . '@appwrite.io',
            'password' => 'password',
        ]);
        $this->assertEquals(201, $user['headers']['status-code']);

        $jwt = $this->client->call(Client::METHOD_POST, '/users/' . $userId . '/jwts', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);
        $this->assertEquals(201, $jwt['headers']['status-code']);
        $this->assertNotEmpty($jwt['body']['jwt']);

        return ['userId' => $userId, 'jwt' => $jwt['body']['jwt']];
    }

    public function testUnauthorizedConnectRejected(): void
    {
        $projectId = $this->getProject()['$id'];

        // Test for FAILURE: a bogus credential is refused at CONNECT with reason 0x87
        // (not authorized).
        $subscriber = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0x87, $subscriber->connect($projectId, 'not.a.valid.jwt', 'e2e-reject', cleanStart: true));
        $subscriber->disconnect();
    }

    public function testBlockedUserConnectRejected(): void
    {
        $projectId = $this->getProject()['$id'];
        ['userId' => $userId, 'jwt' => $jwt] = $this->createUser();

        // Block the account.
        $status = $this->client->call(Client::METHOD_PATCH, '/users/' . $userId . '/status', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), ['status' => false]);
        $this->assertEquals(200, $status['headers']['status-code']);

        // Test for FAILURE: a blocked account is refused at CONNECT with reason 0x87.
        $subscriber = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0x87, $subscriber->connect($projectId, $jwt, 'e2e-blocked', cleanStart: true));
        $subscriber->disconnect();
    }

    /**
     * The messaging graph for the built-in Appwrite push provider: a provider, a topic
     * (whose id is the MQTT delivery channel), a user with a push target identified by
     * that topic id, and a subscription. Returns the topic id.
     *
     * @param  array<string, string>  $server server-key headers
     */
    private function setupPushTopic(array $server, string $userId, string $name): string
    {
        $provider = $this->client->call(Client::METHOD_POST, '/messaging/providers/appwrite', $server, [
            'providerId' => ID::unique(),
            'name' => $name,
            'enabled' => true,
        ]);
        $this->assertEquals(201, $provider['headers']['status-code']);

        $topic = $this->client->call(Client::METHOD_POST, '/messaging/topics', $server, [
            'topicId' => ID::unique(),
            'name' => $name,
            'qos' => 1,
        ]);
        $this->assertEquals(201, $topic['headers']['status-code']);
        $topicId = $topic['body']['$id'];

        $target = $this->client->call(Client::METHOD_POST, '/users/' . $userId . '/targets', $server, [
            'targetId' => ID::unique(),
            'providerType' => 'push',
            'providerId' => $provider['body']['$id'],
            'identifier' => $topicId,
        ]);
        $this->assertEquals(201, $target['headers']['status-code']);

        $subscriber = $this->client->call(Client::METHOD_POST, '/messaging/topics/' . $topicId . '/subscribers', $server, [
            'subscriberId' => ID::unique(),
            'targetId' => $target['body']['$id'],
        ]);
        $this->assertEquals(201, $subscriber['headers']['status-code']);

        return $topicId;
    }

    /**
     * Publish a push campaign to a topic and block until the worker marks it terminal
     * (SENT persists it to the ledger and fans it into the broker).
     *
     * @param  array<string, string>  $server
     * @param  array<string, mixed>  $data
     */
    private function publishCampaign(array $server, string $topicId, string $title, string $body, array $data = []): void
    {
        $push = $this->client->call(Client::METHOD_POST, '/messaging/messages/push', $server, [
            'messageId' => ID::unique(),
            'topics' => [$topicId],
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);
        $this->assertEquals(201, $push['headers']['status-code']);
        $messageId = $push['body']['$id'];

        $this->assertEventually(function () use ($server, $messageId) {
            $message = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $messageId, $server);
            $this->assertContains($message['body']['status'], [MessageStatus::SENT, MessageStatus::FAILED]);
        }, 30000, 500);
    }

    /**
     * Full flow: a Messaging push campaign fans out through the worker and the built-in
     * Appwrite provider into the broker, and a live MQTT subscriber on the topic-id
     * channel receives it. The subscriber is our own MqttSubscriber (no messaging
     * adapter): connect + subscribe, publish the campaign, then read the live delivery.
     */
    public function testCampaignFansOutToMqttSubscriber(): void
    {
        $projectId = $this->getProject()['$id'];
        ['userId' => $userId, 'jwt' => $jwt] = $this->createUser();

        $server = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $topicId = $this->setupPushTopic($server, $userId, 'appwrite-mqtt-flow');

        // Subscribe first (the fan-out only reaches connected subscribers), then publish,
        // then read the delivery that lands on the still-open socket.
        $subscriber = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $subscriber->connect($projectId, $jwt, 'e2e-flow-' . $userId, cleanStart: true));
        $subscriber->subscribe([$topicId]);

        try {
            $this->publishCampaign($server, $topicId, 'Match update', 'India needs 12 off 6', ['matchId' => '42']);
            $received = $subscriber->consume(limit: 1, timeout: 20.0);
        } finally {
            $subscriber->disconnect();
        }

        // Test for SUCCESS: the campaign reached the live subscriber through the broker.
        $this->assertCount(1, $received, 'subscriber did not receive the campaign');
        $this->assertSame($topicId, $received[0]['topic']);

        $payload = \json_decode($received[0]['payload'], true);
        $this->assertEquals('Match update', $payload['notification']['title']);
        $this->assertEquals('India needs 12 off 6', $payload['notification']['body']);
        $this->assertEquals(['matchId' => '42'], $payload['data']);
    }

    /**
     * Offline QoS 1 session replay: a persistent-session subscriber (cleanStart = false,
     * stable client id) subscribes once to register its cursor, disconnects, and while it
     * is offline two campaigns are published. On reconnect the broker replays the missed
     * messages from the ledger, in order, and PUBACK advances the cursor.
     */
    public function testSessionReplaysOfflineMessages(): void
    {
        $projectId = $this->getProject()['$id'];
        ['userId' => $userId, 'jwt' => $jwt] = $this->createUser();

        $server = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $topicId = $this->setupPushTopic($server, $userId, 'appwrite-mqtt-replay');

        $clientId = 'e2e-replay-' . $userId;

        // 1) Persistent session seeds its cursor at the current tail; a new topic does not
        // replay, so it receives nothing.
        $seed = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $seed->connect($projectId, $jwt, $clientId, cleanStart: false));
        $seed->subscribe([$topicId]);
        $this->assertCount(0, $seed->consume(limit: 1, timeout: 2.0));
        $seed->disconnect();

        // 2) Two campaigns are published while that subscriber is offline.
        $bodies = ['first offline message', 'second offline message'];
        foreach ($bodies as $index => $body) {
            $this->publishCampaign($server, $topicId, 'Update ' . $index, $body, ['n' => (string) $index]);
        }

        // 3) The same persistent session reconnects and the broker replays both, in order.
        $resume = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $resume->connect($projectId, $jwt, $clientId, cleanStart: false));
        $resume->subscribe([$topicId]);
        $received = $resume->consume(limit: 2, timeout: 10.0);
        $resume->disconnect();

        // Test for SUCCESS: both offline messages replayed, in sequence order.
        $this->assertCount(2, $received, 'offline messages were not replayed');
        foreach ($received as $message) {
            $this->assertSame($topicId, $message['topic']);
            $this->assertTrue($message['dup'], 'replayed messages carry the DUP flag');
        }
        $replayedBodies = \array_map(
            fn (array $message): string => \json_decode($message['payload'], true)['notification']['body'],
            $received,
        );
        $this->assertSame($bodies, $replayedBodies);

        // 4) The PUBACKs advanced the cursor past the earlier message, so a third connect
        // never re-delivers it. (Acks run in per-packet coroutines, so under QoS 1 the tail
        // may be re-delivered; the cursor never rewinds below the first ack.)
        \usleep(1000000);
        $again = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $again->connect($projectId, $jwt, $clientId, cleanStart: false));
        $again->subscribe([$topicId]);
        $leftover = $again->consume(limit: 2, timeout: 3.0);
        $again->disconnect();

        $leftoverBodies = \array_map(
            fn (array $message): string => \json_decode($message['payload'], true)['notification']['body'],
            $leftover,
        );
        $this->assertNotContains($bodies[0], $leftoverBodies, 'an already-acked message was re-delivered');
    }

    /**
     * Non-contiguous acks must not drop the gap. A persistent session replays three
     * messages and acks the first and third but not the middle; the cursor advances only
     * to the highest contiguous ack, so on reconnect the broker re-delivers the unacked
     * middle message rather than skipping past it.
     */
    public function testNonContiguousAckDoesNotDropMessages(): void
    {
        $projectId = $this->getProject()['$id'];
        ['userId' => $userId, 'jwt' => $jwt] = $this->createUser();

        $server = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $topicId = $this->setupPushTopic($server, $userId, 'appwrite-mqtt-gap');
        $clientId = 'e2e-gap-' . $userId;

        // Seed the persistent session, then publish three messages while offline.
        $seed = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $seed->connect($projectId, $jwt, $clientId, cleanStart: false));
        $seed->subscribe([$topicId]);
        $this->assertCount(0, $seed->consume(limit: 1, timeout: 2.0));
        $seed->disconnect();

        foreach (['first', 'second', 'third'] as $index => $body) {
            $this->publishCampaign($server, $topicId, 'Update ' . $index, $body, ['n' => (string) $index]);
        }

        // Replay all three, but ack only the first and third — leave the middle unacked.
        $resume = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $resume->connect($projectId, $jwt, $clientId, cleanStart: false));
        $resume->subscribe([$topicId]);
        $received = $resume->consume(limit: 3, timeout: 10.0, shouldAck: fn (int $i): bool => $i !== 1);
        $resume->disconnect();
        $this->assertCount(3, $received);

        // On reconnect the unacked middle message is re-delivered, not skipped.
        \usleep(1000000);
        $again = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $again->connect($projectId, $jwt, $clientId, cleanStart: false));
        $again->subscribe([$topicId]);
        $redelivered = $again->consume(limit: 3, timeout: 5.0);
        $again->disconnect();

        $redeliveredBodies = \array_map(
            fn (array $message): string => \json_decode($message['payload'], true)['notification']['body'],
            $redelivered,
        );
        $this->assertContains('second', $redeliveredBodies, 'the unacked middle message was dropped');
    }

    /**
     * Replay is bounded by the broker's max depth: when more messages accumulate offline
     * than the cap, only the most recent $maxDepth are replayed (the older ones are
     * dropped and the cursor jumps to the tail), and a later reconnect replays nothing.
     */
    public function testReplayIsBoundedByMaxDepth(): void
    {
        // Mirrors the handler's cap (Subscribe.php $maxDepth).
        $maxDepth = 5;
        $overflow = $maxDepth + 2;

        $projectId = $this->getProject()['$id'];
        ['userId' => $userId, 'jwt' => $jwt] = $this->createUser();

        $server = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $topicId = $this->setupPushTopic($server, $userId, 'appwrite-mqtt-depth');
        $clientId = 'e2e-depth-' . $userId;

        // Seed the persistent session at the current tail, then go offline.
        $seed = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $seed->connect($projectId, $jwt, $clientId, cleanStart: false));
        $seed->subscribe([$topicId]);
        $this->assertCount(0, $seed->consume(limit: 1, timeout: 2.0));
        $seed->disconnect();

        // Publish more than the cap while offline.
        $bodies = [];
        for ($i = 0; $i < $overflow; $i++) {
            $body = 'offline message ' . $i;
            $bodies[] = $body;
            $this->publishCampaign($server, $topicId, 'Update ' . $i, $body, ['n' => (string) $i]);
        }

        // Reconnect: only the last $maxDepth replay, in order.
        $resume = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $resume->connect($projectId, $jwt, $clientId, cleanStart: false));
        $resume->subscribe([$topicId]);
        $received = $resume->consume(limit: $overflow, timeout: 10.0);
        $resume->disconnect();

        $this->assertCount($maxDepth, $received, 'replay was not capped at max depth');
        $replayedBodies = \array_map(
            fn (array $message): string => \json_decode($message['payload'], true)['notification']['body'],
            $received,
        );
        $this->assertSame(\array_slice($bodies, -$maxDepth), $replayedBodies);

        // The dropped (capped-out) messages are gone for good: a later reconnect never
        // re-delivers them. (Acks are processed in per-packet coroutines, so under QoS 1 a
        // tail message may be re-delivered; the guarantee we assert is that the cursor never
        // rewinds below the replayed window, so the dropped set is never seen again.)
        \usleep(1000000);
        $again = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $again->connect($projectId, $jwt, $clientId, cleanStart: false));
        $again->subscribe([$topicId]);
        $leftover = $again->consume(limit: $overflow, timeout: 3.0);
        $again->disconnect();

        $leftoverBodies = \array_map(
            fn (array $message): string => \json_decode($message['payload'], true)['notification']['body'],
            $leftover,
        );
        foreach (\array_slice($bodies, 0, $overflow - $maxDepth) as $droppedBody) {
            $this->assertNotContains($droppedBody, $leftoverBodies, 'a capped-out message was re-delivered');
        }
    }

    /**
     * A clean-start reconnect discards the persisted session: the cursor is purged and the
     * client re-seeds at the current tail, so an offline backlog is NOT replayed.
     */
    public function testCleanStartDiscardsSession(): void
    {
        $projectId = $this->getProject()['$id'];
        ['userId' => $userId, 'jwt' => $jwt] = $this->createUser();

        $server = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $topicId = $this->setupPushTopic($server, $userId, 'appwrite-mqtt-clean');
        $clientId = 'e2e-clean-' . $userId;

        // Establish a persistent session, then go offline.
        $seed = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $seed->connect($projectId, $jwt, $clientId, cleanStart: false));
        $seed->subscribe([$topicId]);
        $this->assertCount(0, $seed->consume(limit: 1, timeout: 2.0));
        $seed->disconnect();

        // A message accumulates while offline.
        $this->publishCampaign($server, $topicId, 'Update', 'while offline', ['n' => '0']);

        // Reconnect with cleanStart = true: the session is discarded, so nothing replays.
        $fresh = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $fresh->connect($projectId, $jwt, $clientId, cleanStart: true));
        $fresh->subscribe([$topicId]);
        $this->assertCount(0, $fresh->consume(limit: 1, timeout: 3.0), 'clean-start session should not replay a backlog');
        $fresh->disconnect();
    }
}
