<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Mqtt;

use Appwrite\Messaging\Status as MessageStatus;
use PHPUnit\Framework\Attributes\Group;
use Swoole\Coroutine\Http\Client as WebSocketClient;
use Swoole\WebSocket\Frame;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\Specs\V5;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;

use function Swoole\Coroutine\run;

/**
 * End-to-end tests for the MQTT push broker (src/Appwrite/Mqtt). Publishing is a server
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
final class MqttServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    private const BROKER_HOST = 'appwrite-mqtt';
    private const BROKER_PORT = 1883;
    private const BROKER_WS_PORT = 8083;

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

    /**
     * Create a server-side session for a user and return its credential — the base64({id,
     * secret}) store blob the broker's appwrite-session auth decodes (the same value a client
     * holds as its session cookie).
     */
    private function createSession(string $userId): string
    {
        $session = $this->client->call(Client::METHOD_POST, '/users/' . $userId . '/sessions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(201, $session['headers']['status-code']);
        $this->assertNotEmpty($session['body']['secret']);

        return $session['body']['secret'];
    }

    public function testUnauthorizedConnectRejected(): void
    {
        $projectId = $this->getProject()['$id'];

        // Test for FAILURE: a bogus credential is refused at CONNECT with reason 0x87
        // (not authorized).
        $subscriber = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0x87, $subscriber->connect($projectId, 'not.a.valid.jwt', 'e2e-reject', cleanStart: true));
        // The CONNACK carries a human-readable reason so clients learn why they were refused.
        $this->assertNotEmpty($subscriber->connackReason());
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
        $this->assertNotEmpty($subscriber->connackReason());
        $subscriber->disconnect();
    }

    public function testSessionAuthConnect(): void
    {
        $projectId = $this->getProject()['$id'];
        ['userId' => $userId] = $this->createUser();
        $credential = $this->createSession($userId);

        // Test for SUCCESS: a valid session credential authenticates via appwrite-session,
        // the session-based analogue of the JWT auth method.
        $subscriber = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $subscriber->connect($projectId, $credential, 'e2e-session-' . $userId, cleanStart: true, authMethod: 'appwrite-session'));
        $subscriber->disconnect();
    }

    public function testSessionAuthWithInvalidSecretRejected(): void
    {
        $projectId = $this->getProject()['$id'];
        ['userId' => $userId] = $this->createUser();

        // Test for FAILURE: a well-formed store blob with a wrong secret is refused at CONNECT
        // with reason 0x87 (the session does not verify).
        $credential = base64_encode((string) json_encode(['id' => $userId, 'secret' => 'not-a-real-secret']));
        $subscriber = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0x87, $subscriber->connect($projectId, $credential, 'e2e-badsession-' . $userId, cleanStart: true, authMethod: 'appwrite-session'));
        $subscriber->disconnect();
    }

    public function testSubscribeToArbitraryTopicIsGranted(): void
    {
        $projectId = $this->getProject()['$id'];
        ['userId' => $userId, 'jwt' => $jwt] = $this->createUser();

        $subscriber = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $subscriber->connect($projectId, $jwt, 'e2e-open-' . $userId, cleanStart: true));

        try {
            // Subscription is open: a connected user may subscribe to any topic, including one that
            // maps to no topic document. It is granted (live-only, no backlog), not refused.
            $codes = $subscriber->subscribe(['does-not-exist-' . $userId]);
            $this->assertSame([1], $codes);
        } finally {
            $subscriber->disconnect();
        }
    }

    public function testSubscriptionIgnoresTopicRoles(): void
    {
        $projectId = $this->getProject()['$id'];
        ['userId' => $ownerId] = $this->createUser();
        ['userId' => $otherId, 'jwt' => $otherJwt] = $this->createUser();

        $server = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        // A topic whose subscribe roles name only the owner. Subscription authorization has been
        // removed, so the broker does not enforce these roles.
        $topicName = 'mqtt-open-' . $ownerId;
        $topic = $this->client->call(Client::METHOD_POST, '/messaging/topics', $server, [
            'topicId' => ID::unique(),
            'name' => $topicName,
            'subscribe' => ['user:' . $ownerId],
        ]);
        $this->assertEquals(201, $topic['headers']['status-code']);

        // Test for SUCCESS: a user outside the topic's subscribe roles is still granted.
        $other = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $other->connect($projectId, $otherJwt, 'e2e-open-other-' . $otherId, cleanStart: true));
        try {
            $this->assertSame([1], $other->subscribe([$topicName]));
        } finally {
            $other->disconnect();
        }
    }

    public function testBlockedUserSubscribeRejected(): void
    {
        $projectId = $this->getProject()['$id'];
        ['userId' => $userId, 'jwt' => $jwt] = $this->createUser();

        // Connect while the account is still valid.
        $subscriber = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $subscriber->connect($projectId, $jwt, 'e2e-block-sub-' . $userId, cleanStart: true));

        try {
            // Block the account after CONNECT.
            $status = $this->client->call(Client::METHOD_PATCH, '/users/' . $userId . '/status', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], $this->getHeaders()), ['status' => false]);
            $this->assertEquals(200, $status['headers']['status-code']);

            // Test for FAILURE: subscription is open, but the per-SUBSCRIBE re-check refuses a user
            // blocked since CONNECT (SUBACK 0x80), even though topic selection itself is unrestricted.
            $this->assertSame([0x80], $subscriber->subscribe(['any-topic-' . $userId]));
        } finally {
            $subscriber->disconnect();
        }
    }

    public function testUserTopicOwnershipOnSubscribe(): void
    {
        $projectId = $this->getProject()['$id'];
        ['userId' => $userId, 'jwt' => $jwt] = $this->createUser();
        ['userId' => $otherId] = $this->createUser();

        $subscriber = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $subscriber->connect($projectId, $jwt, 'e2e-usertopic-' . $userId, cleanStart: true));

        try {
            // Test for SUCCESS: the caller's own reserved topic is granted (live-only until a publish
            // provisions its row).
            $this->assertSame([1], $subscriber->subscribe(['users/' . $userId]));

            // Test for FAILURE: another user's topic, and any wildcard in the users/ namespace, are
            // refused (0x80) so a client can never reach another user's pushes.
            $this->assertSame([0x80], $subscriber->subscribe(['users/' . $otherId]));
            $this->assertSame([0x80], $subscriber->subscribe(['users/#']));
            $this->assertSame([0x80], $subscriber->subscribe(['users/+']));
        } finally {
            $subscriber->disconnect();
        }
    }

    public function testUserTopicReceivesUserTargetedPush(): void
    {
        $projectId = $this->getProject()['$id'];
        ['userId' => $userId, 'jwt' => $jwt] = $this->createUser();

        $server = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        // The user has an appwrite push target with an arbitrary device identifier; delivery lands on
        // users/<userId>, derived from the target's user, not from that identifier — no topic needed.
        $this->setupUserPushTarget($server, $userId);

        $subscriber = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $subscriber->connect($projectId, $jwt, 'e2e-usertopic-recv-' . $userId, cleanStart: true));
        $this->assertSame([1], $subscriber->subscribe(['users/' . $userId]));

        try {
            $this->publishToUser($server, $userId, 'Ping', 'you have mail', ['k' => 'v']);
            $received = $subscriber->consume(limit: 1, timeout: 20.0);
        } finally {
            $subscriber->disconnect();
        }

        // Test for SUCCESS: the user-targeted campaign auto-provisioned users/<userId> and reached
        // the owner on that topic.
        $this->assertCount(1, $received, 'the user did not receive the user-targeted push');
        $this->assertSame('users/' . $userId, $received[0]['topic']);
        $payload = \json_decode($received[0]['payload'], true);
        $this->assertSame('you have mail', $payload['notification']['body']);
    }

    public function testKeepAliveReapsSilentClient(): void
    {
        $projectId = $this->getProject()['$id'];
        ['userId' => $userId, 'jwt' => $jwt] = $this->createUser();

        // Connect with a 1s keep-alive (reap deadline 1.5s) and then stay silent. The reaper
        // must close the socket. Its tick period bounds detection, so allow generous slack.
        $subscriber = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $subscriber->connect($projectId, $jwt, 'e2e-keepalive-' . $userId, cleanStart: true, keepAlive: 1));

        try {
            $this->assertTrue($subscriber->awaitClose(40.0), 'broker did not reap a silent client past its keep-alive');
        } finally {
            $subscriber->disconnect();
        }
    }

    public function testKeepAlivePingKeepsClientAlive(): void
    {
        $projectId = $this->getProject()['$id'];
        ['userId' => $userId, 'jwt' => $jwt] = $this->createUser();

        // 1s keep-alive (reap deadline 1.5s). Ping steadily across a full reaper interval: each
        // PINGREQ must draw a PINGRESP and push the deadline forward, so the client is never reaped.
        $subscriber = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $subscriber->connect($projectId, $jwt, 'e2e-ping-' . $userId, cleanStart: true, keepAlive: 1));

        try {
            $deadline = microtime(true) + 25; // span at least one reaper tick (broker interval is 20s)
            while (microtime(true) < $deadline) {
                $this->assertTrue($subscriber->ping(2.0), 'broker did not answer PINGRESP');
                usleep(800_000); // < the 1.5s deadline, so activity stays ahead of the reaper
            }

            // Still connected: an immediate close-await times out rather than seeing an EOF.
            $this->assertFalse($subscriber->awaitClose(0.5), 'an actively pinging client was reaped');
        } finally {
            $subscriber->disconnect();
        }
    }

    public function testGrantedQosIsCappedByTopicConfig(): void
    {
        $projectId = $this->getProject()['$id'];
        ['userId' => $userId, 'jwt' => $jwt] = $this->createUser();

        $server = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $topicQos1 = $this->setupPushTopic($server, $userId, 'mqtt-qos1', qos: 1)['name'];
        $topicQos0 = $this->setupPushTopic($server, $userId, 'mqtt-qos0', qos: 0)['name'];

        $subscriber = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $subscriber->connect($projectId, $jwt, 'e2e-qos-' . $userId, cleanStart: true));

        try {
            // The client requests QoS 1 for both: the qos-1 topic grants 1, the qos-0 topic
            // caps the grant to 0 (the topic's configured QoS wins from the DB).
            $this->assertSame([1], $subscriber->subscribe([$topicQos1], 1));
            $this->assertSame([0], $subscriber->subscribe([$topicQos0], 1));
        } finally {
            $subscriber->disconnect();
        }
    }

    /**
     * The messaging graph for the built-in Appwrite push provider: a provider, a topic (whose
     * name is the MQTT channel subscribers match on), a user with a push target identified by
     * the topic id, and a subscription. Returns the topic id (the publish handle) and name (the
     * subscribe handle) — they are distinct: publishers address topics by id, subscribers by name.
     *
     * @param  array<string, string>  $server server-key headers
     * @return array{id: string, name: string}
     */
    private function setupPushTopic(array $server, string $userId, string $name, int $qos = 1): array
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
            'qos' => $qos,
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

        return ['id' => $topicId, 'name' => $name];
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
     * An appwrite push provider and a push target for the user — the recipient graph a user-targeted
     * campaign resolves through. The target's identifier is a placeholder device handle, not the
     * topic: the broker fans out on users/<userId>, derived from the target's user.
     *
     * @param  array<string, string>  $server server-key headers
     */
    private function setupUserPushTarget(array $server, string $userId): void
    {
        $provider = $this->client->call(Client::METHOD_POST, '/messaging/providers/appwrite', $server, [
            'providerId' => ID::unique(),
            'name' => 'appwrite-user-push',
            'enabled' => true,
        ]);
        $this->assertEquals(201, $provider['headers']['status-code']);

        $target = $this->client->call(Client::METHOD_POST, '/users/' . $userId . '/targets', $server, [
            'targetId' => ID::unique(),
            'providerType' => 'push',
            'providerId' => $provider['body']['$id'],
            'identifier' => 'device-' . $userId,
        ]);
        $this->assertEquals(201, $target['headers']['status-code']);
    }

    /**
     * Publish a push campaign to a user and block until the worker marks it terminal. The appwrite
     * provider resolves the user's targets to the reserved users/<userId> topic and fans out there.
     *
     * @param  array<string, string>  $server
     * @param  array<string, mixed>  $data
     */
    private function publishToUser(array $server, string $userId, string $title, string $body, array $data = []): void
    {
        $push = $this->client->call(Client::METHOD_POST, '/messaging/messages/push', $server, [
            'messageId' => ID::unique(),
            'users' => [$userId],
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
     * Appwrite provider into the broker, and a live MQTT subscriber on the topic name
     * receives it. The subscriber is our own MqttSubscriber (no messaging
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
        ['id' => $topicId, 'name' => $topicName] = $this->setupPushTopic($server, $userId, 'appwrite-mqtt-flow');

        // Subscribe first (the fan-out only reaches connected subscribers), then publish,
        // then read the delivery that lands on the still-open socket.
        $subscriber = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $subscriber->connect($projectId, $jwt, 'e2e-flow-' . $userId, cleanStart: true));
        $subscriber->subscribe([$topicName]);

        try {
            $this->publishCampaign($server, $topicId, 'Match update', 'India needs 12 off 6', ['matchId' => '42']);
            $received = $subscriber->consume(limit: 1, timeout: 20.0);
        } finally {
            $subscriber->disconnect();
        }

        // Test for SUCCESS: the campaign reached the live subscriber through the broker, delivered
        // on the topic name (not the id the publisher addressed it by).
        $this->assertCount(1, $received, 'subscriber did not receive the campaign');
        $this->assertSame($topicName, $received[0]['topic']);

        $payload = \json_decode($received[0]['payload'], true);
        $this->assertEquals('Match update', $payload['notification']['title']);
        $this->assertEquals('India needs 12 off 6', $payload['notification']['body']);
        $this->assertEquals(['matchId' => '42'], $payload['data']);
    }

    /**
     * Wildcard subscriptions: a subscriber can subscribe to an MQTT topic pattern
     * (users/+/status) and receive publishes to any concrete topic whose name matches
     * (users/<id>/status), even though the publisher addressed the campaign by topic id.
     * The delivery is tagged with the concrete topic name the broker fanned out to.
     */
    public function testWildcardSubscriptionReceivesMatchingTopic(): void
    {
        $projectId = $this->getProject()['$id'];
        ['userId' => $userId, 'jwt' => $jwt] = $this->createUser();

        $server = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        // A hierarchical topic outside the reserved users/ namespace (which forbids wildcards).
        ['id' => $topicId, 'name' => $topicName] = $this->setupPushTopic($server, $userId, 'scores/' . $userId . '/live');

        $subscriber = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $subscriber->connect($projectId, $jwt, 'e2e-wild-' . $userId, cleanStart: true));
        // A single-level (+) wildcard filter, authorized at the connection level, matches the
        // concrete topic name and is granted (capped at QoS 1).
        $this->assertSame([1], $subscriber->subscribe(['scores/+/live'], Packet::QOS_1));

        try {
            $this->publishCampaign($server, $topicId, 'Status', 'online', ['s' => 'up']);
            $received = $subscriber->consume(limit: 1, timeout: 20.0);
        } finally {
            $subscriber->disconnect();
        }

        // Test for SUCCESS: the wildcard subscriber received the publish, tagged with the concrete
        // topic name (not the id the publisher addressed it by).
        $this->assertCount(1, $received, 'wildcard subscriber did not receive the matching publish');
        $this->assertSame($topicName, $received[0]['topic']);
        $payload = \json_decode($received[0]['payload'], true);
        $this->assertSame('online', $payload['notification']['body']);
    }

    /**
     * QoS 0 is fire-and-forget and must stay segregated from QoS 1: a subscription to a
     * QoS-0 topic is delivered at QoS 0, so the PUBLISH carries no packet id and the client
     * sends no PUBACK (the broker tracks nothing and advances no replay cursor). The client
     * requests QoS 1 but the topic caps the grant to 0.
     */
    public function testQos0DeliveryHasNoPuback(): void
    {
        $projectId = $this->getProject()['$id'];
        ['userId' => $userId, 'jwt' => $jwt] = $this->createUser();

        $server = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        ['id' => $topicId, 'name' => $topicName] = $this->setupPushTopic($server, $userId, 'appwrite-mqtt-qos0', qos: 0);

        $subscriber = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $subscriber->connect($projectId, $jwt, 'e2e-qos0-' . $userId, cleanStart: true));
        $this->assertSame([0], $subscriber->subscribe([$topicName], Packet::QOS_1), 'the QoS-0 topic must cap the grant to 0');

        try {
            $this->publishCampaign($server, $topicId, 'No ack', 'delivered at qos 0', ['k' => 'v']);
            $received = $subscriber->consume(limit: 1, timeout: 20.0);
        } finally {
            $subscriber->disconnect();
        }

        // Test for SUCCESS: delivered once, at QoS 0 (no packet id, so the client never PUBACKs).
        $this->assertCount(1, $received, 'qos 0 subscriber did not receive the campaign');
        $this->assertSame($topicName, $received[0]['topic']);
        $this->assertSame(0, $received[0]['qos'], 'delivery to a QoS-0 subscription must be QoS 0');
        $this->assertFalse($received[0]['dup'], 'a QoS-0 delivery is never marked DUP');
    }

    /**
     * MQTT over WebSocket: browser clients reach the broker through its WebSocket listener
     * (appwrite-mqtt:8083, exposed as wss://<host>:8084 on its own TLS port) instead of raw TCP. The same
     * enhanced-auth CONNECT and SUBSCRIBE exchange must work with each MQTT packet carried in
     * a WebSocket binary frame, exercising the broker's WebSocket transport, packet reassembly
     * and framed send in both directions.
     */
    public function testWebSocketConnectAndSubscribe(): void
    {
        $projectId = $this->getProject()['$id'];
        ['userId' => $userId, 'jwt' => $jwt] = $this->createUser();

        $server = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $topicName = $this->setupPushTopic($server, $userId, 'appwrite-mqtt-ws')['name'];

        $connackReason = null;
        $codes = [];

        run(function () use ($projectId, $jwt, $userId, $topicName, &$connackReason, &$codes) {
            $client = new WebSocketClient(self::BROKER_HOST, self::BROKER_WS_PORT);
            $client->set(['timeout' => 10]);
            $this->assertTrue($client->upgrade('/'), 'websocket upgrade failed');

            $properties = (new Properties())
                ->add(new Property(Property::AUTHENTICATION_METHOD, 'appwrite-jwt'))
                ->add(new Property(Property::AUTHENTICATION_DATA, $jwt))
                ->add(new Property(Property::USER, ['projectId' => $projectId]));

            $buffer = '';

            $client->push(V5::connect('e2e-ws-' . $userId, 60, true, $properties), WEBSOCKET_OPCODE_BINARY);
            $connack = $this->wsReceive($client, $buffer);
            $connackReason = \ord($connack->body[1] ?? "\x80");

            $client->push(V5::subscribe(1, [$topicName], 1), WEBSOCKET_OPCODE_BINARY);
            $suback = $this->wsReceive($client, $buffer);
            // SUBACK body: [packetId:2][properties][one reason code per filter].
            $offset = Properties::skip($suback->body, 2);
            for ($i = $offset; $i < \strlen($suback->body); $i++) {
                $codes[] = \ord($suback->body[$i]);
            }

            $client->close();
        });

        // Test for SUCCESS: the WebSocket carrier authenticated the CONNECT and granted the
        // subscription at the topic's QoS, proving browser clients can consume the broker.
        $this->assertSame(0, $connackReason, 'CONNACK over WebSocket was not success');
        $this->assertSame([1], $codes, 'SUBACK over WebSocket did not grant QoS 1');
    }

    /** Read WebSocket frames until one whole MQTT packet reassembles out of their payloads. */
    private function wsReceive(WebSocketClient $client, string &$buffer): Packet
    {
        while (true) {
            [$packets, $buffer] = Packet::frames($buffer);
            if ($packets !== []) {
                return Packet::parse($packets[0]);
            }

            $frame = $client->recv(10);
            $this->assertInstanceOf(Frame::class, $frame, 'websocket receive failed');
            $buffer .= (string) $frame->data;
        }
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
        ['id' => $topicId, 'name' => $topicName] = $this->setupPushTopic($server, $userId, 'appwrite-mqtt-replay');

        $clientId = 'e2e-replay-' . $userId;

        // 1) Persistent session seeds its cursor at the current tail; a new topic does not
        // replay, so it receives nothing.
        $seed = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $seed->connect($projectId, $jwt, $clientId, cleanStart: false));
        $seed->subscribe([$topicName]);
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
        $resume->subscribe([$topicName]);
        $received = $resume->consume(limit: 2, timeout: 10.0);
        $resume->disconnect();

        // Test for SUCCESS: both offline messages replayed, in sequence order.
        $this->assertCount(2, $received, 'offline messages were not replayed');
        foreach ($received as $message) {
            $this->assertSame($topicName, $message['topic']);
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
        $again->subscribe([$topicName]);
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
        ['id' => $topicId, 'name' => $topicName] = $this->setupPushTopic($server, $userId, 'appwrite-mqtt-gap');
        $clientId = 'e2e-gap-' . $userId;

        // Seed the persistent session, then publish three messages while offline.
        $seed = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $seed->connect($projectId, $jwt, $clientId, cleanStart: false));
        $seed->subscribe([$topicName]);
        $this->assertCount(0, $seed->consume(limit: 1, timeout: 2.0));
        $seed->disconnect();

        foreach (['first', 'second', 'third'] as $index => $body) {
            $this->publishCampaign($server, $topicId, 'Update ' . $index, $body, ['n' => (string) $index]);
        }

        // Replay all three, but ack only the first and third — leave the middle unacked.
        $resume = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $resume->connect($projectId, $jwt, $clientId, cleanStart: false));
        $resume->subscribe([$topicName]);
        $received = $resume->consume(limit: 3, timeout: 10.0, shouldAck: fn (int $i): bool => $i !== 1);
        $resume->disconnect();
        $this->assertCount(3, $received);

        // On reconnect the unacked middle message is re-delivered, not skipped.
        \usleep(1000000);
        $again = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $again->connect($projectId, $jwt, $clientId, cleanStart: false));
        $again->subscribe([$topicName]);
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
        ['id' => $topicId, 'name' => $topicName] = $this->setupPushTopic($server, $userId, 'appwrite-mqtt-depth');
        $clientId = 'e2e-depth-' . $userId;

        // Seed the persistent session at the current tail, then go offline.
        $seed = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $seed->connect($projectId, $jwt, $clientId, cleanStart: false));
        $seed->subscribe([$topicName]);
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
        $resume->subscribe([$topicName]);
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
        $again->subscribe([$topicName]);
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
        ['id' => $topicId, 'name' => $topicName] = $this->setupPushTopic($server, $userId, 'appwrite-mqtt-clean');
        $clientId = 'e2e-clean-' . $userId;

        // Establish a persistent session, then go offline.
        $seed = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $seed->connect($projectId, $jwt, $clientId, cleanStart: false));
        $seed->subscribe([$topicName]);
        $this->assertCount(0, $seed->consume(limit: 1, timeout: 2.0));
        $seed->disconnect();

        // A message accumulates while offline.
        $this->publishCampaign($server, $topicId, 'Update', 'while offline', ['n' => '0']);

        // Reconnect with cleanStart = true: the session is discarded, so nothing replays.
        $fresh = new MqttSubscriber(self::BROKER_HOST, self::BROKER_PORT);
        $this->assertSame(0, $fresh->connect($projectId, $jwt, $clientId, cleanStart: true));
        $fresh->subscribe([$topicName]);
        $this->assertCount(0, $fresh->consume(limit: 1, timeout: 3.0), 'clean-start session should not replay a backlog');
        $fresh->disconnect();
    }
}
