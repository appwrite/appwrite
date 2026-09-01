<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Messaging;

use PHPUnit\Framework\Attributes\Group;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;
use Utopia\Messaging\Adapter\Push\Appwrite as AppwritePush;
use Utopia\Messaging\Messages\Push;

/**
 * End-to-end tests for the MQTT push broker (src/Utopia/Mqtt), driven by the
 * utopia-php/messaging Appwrite Push adapter. Exercises the real broker container
 * over TCP: enhanced-auth CONNECT against the project/user graph, QoS 1
 * publish/ack, fan-out to a subscriber via consume(), and subscribe-side ACL.
 *
 * Blocked accounts are refused at CONNECT (CONNACK 0x87); the subscribe authorizer
 * re-checks as defense in depth. (Topic-existence authorization is still pending.)
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

    private function newAdapter(string $projectId, string $credential): AppwritePush
    {
        return new AppwritePush(
            endpoint: self::BROKER_HOST . ':' . self::BROKER_PORT,
            projectId: $projectId,
            credential: $credential,
            authMethod: 'appwrite-jwt',
            tls: false,
        );
    }

    public function testAdapterPublishesToBroker(): void
    {
        $projectId = $this->getProject()['$id'];
        $jwt = $this->createUser()['jwt'];

        // Test for SUCCESS: publishing is a server privilege (not ACL-gated).
        $response = $this->newAdapter($projectId, $jwt)->send(new Push(
            to: ['device-1', 'device-2'],
            title: 'Hi',
            body: 'Hello',
        ));

        $this->assertEquals('push', $response['type']);
        $this->assertEquals(2, $response['deliveredTo']);
        foreach ($response['results'] as $result) {
            $this->assertEquals('success', $result['status']);
            $this->assertEquals('', $result['error']);
        }
    }

    public function testUnauthorizedConnectRejected(): void
    {
        $projectId = $this->getProject()['$id'];

        // Test for FAILURE: a bogus credential yields CONNACK 0x87 and a closed socket,
        // which the adapter surfaces as a thrown error out of send().
        $adapter = $this->newAdapter($projectId, 'not.a.valid.jwt');

        $this->expectException(\Throwable::class);
        $adapter->send(new Push(
            to: ['device-1'],
            title: 'Hi',
            body: 'Hello',
        ));
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

        // Test for FAILURE: a blocked account is refused at CONNECT (CONNACK 0x87),
        // which the adapter surfaces as a thrown error out of send().
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/reject/i');
        $this->newAdapter($projectId, $jwt)->send(new Push(
            to: ['device-1'],
            title: 'Hi',
            body: 'Hello',
        ));
    }

    public function testPublishConsumedBySubscriber(): void
    {
        $projectId = $this->getProject()['$id'];
        ['userId' => $userId, 'jwt' => $jwt] = $this->createUser();

        // The connection subscribes to its own user scope (ACL-allowed) and the server
        // publishes to that same topic.
        $topic = 'appwrite/push/' . $userId;

        // Publish from a separate OS process so it runs while consume() blocks. The
        // broker only fans out to already-connected subscribers, so the publisher waits.
        // Resolve the autoloader from the running one rather than a fixed relative depth,
        // so it works both standalone (repo vendor/) and vendored inside another project
        // (e.g. cloud's vendor/appwrite/server-ce, whose own vendor/ is not installed).
        $autoload = \dirname((new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2) . '/autoload.php';
        $publisher = \tempnam(\sys_get_temp_dir(), 'mqtt-pub-') . '.php';
        \file_put_contents($publisher, <<<'PHP'
            <?php
            [$_, $autoload, $endpoint, $projectId, $jwt, $userId] = $argv;
            require $autoload;
            usleep(1500000);
            $adapter = new Utopia\Messaging\Adapter\Push\Appwrite($endpoint, $projectId, $jwt, 'appwrite-jwt', false);
            try {
                $adapter->send(new Utopia\Messaging\Messages\Push(to: [$userId], title: 'Ping', body: 'Pong', data: ['k' => 'v']));
            } catch (\Throwable $error) {
                \fwrite(STDERR, $error->getMessage());
            }
            PHP);

        $process = \proc_open(
            [PHP_BINARY, $publisher, $autoload, self::BROKER_HOST . ':' . self::BROKER_PORT, $projectId, $jwt, $userId],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
            $pipes,
        );
        $this->assertNotFalse($process, 'could not start publisher process');

        try {
            $received = [];
            $handled = $this->newAdapter($projectId, $jwt)->consume(
                [$topic],
                function (array $message) use (&$received): void {
                    $received = $message;
                },
                limit: 1,
                timeout: 10.0,
            );

            // Test for SUCCESS: the broker delivered the publish to the subscribed consumer.
            $this->assertSame(1, $handled, 'consumer did not receive the publish');
            $this->assertSame($topic, $received['topic']);

            $decoded = \json_decode($received['payload'], true);
            $this->assertEquals('Ping', $decoded['notification']['title']);
            $this->assertEquals('Pong', $decoded['notification']['body']);
            $this->assertEquals(['k' => 'v'], $decoded['data']);
        } finally {
            \proc_close($process);
            @\unlink($publisher);
        }
    }
}
