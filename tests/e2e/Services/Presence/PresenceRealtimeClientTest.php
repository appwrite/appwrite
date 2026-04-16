<?php

namespace Tests\E2E\Services\Presence;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Tests\E2E\Services\Realtime\RealtimeBase;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use WebSocket\Client as WebSocketClient;
use WebSocket\TimeoutException;

class PresenceRealtimeClientTest extends Scope
{
    use ProjectCustom;
    use RealtimeBase;
    use SideClient;

    private function assertPresenceRealtimeEvent(
        array $event,
        string $presenceId,
        string $action,
        string $status,
        array $metadata = [],
        ?string $expectedUserId = null
    ): void {
        $expectedUserId ??= $this->getUser()['$id'];
        $this->assertSame('event', $event['type'] ?? null);
        $this->assertContains('presences', $event['data']['channels'] ?? []);
        $this->assertContains('presences.' . $presenceId, $event['data']['channels'] ?? []);
        $this->assertNotEmpty($event['data']['events'] ?? []);
        $this->assertContains('presences.' . $presenceId . '.' . $action, $event['data']['events'] ?? []);
        $this->assertNotEmpty($event['data']['timestamp'] ?? null);
        $this->assertArrayHasKey('subscriptions', $event['data'] ?? []);
        $this->assertNotEmpty($event['data']['subscriptions'] ?? []);
        $this->assertSame($presenceId, $event['data']['payload']['$id'] ?? null);
        $this->assertSame($status, $event['data']['payload']['status'] ?? null);
        $this->assertSame($metadata, $event['data']['payload']['metadata'] ?? []);
        $this->assertSame($expectedUserId, $event['data']['payload']['userId'] ?? null);
    }

    private function assertNoRealtimeEvent(WebSocketClient $client): void
    {
        try {
            $client->receive();
            $this->fail('Expected TimeoutException - event should not be received');
        } catch (TimeoutException $e) {
            $this->assertTrue(true);
        }
    }

    /**
     * Presence websocket contract: after sending a `type: presence` message,
     * the sender socket should receive:
     * 1) `type: response` (for the persistence write)
     * 2) `type: event` (for the realtime upsert)
     *
     * This keeps the tests strict about ordering and avoids leaving unread
     * realtime events in the socket buffer for later assertions.
     */
    private function assertPresenceResponseThenUpsertEvent(
        WebSocketClient $client,
        string $expectedStatus,
        array $expectedMetadata,
        ?string $expectedUserId = null
    ): string {
        $expectedUserId ??= $this->getUser()['$id'];

        $presenceId = null;
        $response = null;
        $event = null;

        // Ordering is not guaranteed because:
        // - response is sent directly via $server->send(...)
        // - event is emitted via pub/sub and may arrive earlier
        for ($attempts = 0; $attempts < 5; $attempts++) {
            $message = \json_decode($client->receive(), true);
            $type = $message['type'] ?? null;

            if ($type === 'response') {
                $response ??= $message;

                $this->assertSame('presence', $response['data']['to'] ?? null);
                $presenceId ??= $response['data']['presence']['$id'] ?? null;
                $this->assertNotEmpty($presenceId);
                $this->assertSame($expectedStatus, $response['data']['presence']['status'] ?? null);
                $this->assertSame($expectedMetadata, $response['data']['presence']['metadata'] ?? null);
            } elseif ($type === 'event') {
                $event ??= $message;
                $presenceId ??= $event['data']['payload']['$id'] ?? null;
                $this->assertNotEmpty($presenceId);

                $this->assertPresenceRealtimeEvent(
                    $event,
                    $presenceId,
                    'upsert',
                    $expectedStatus,
                    $expectedMetadata,
                    $expectedUserId
                );
            }

            if ($response !== null && $event !== null) {
                return $presenceId;
            }
        }

        $this->fail('Expected both realtime presence `response` and `event` messages');
        return '';
    }

    /**
     * After getting a `response` for a presence message, the next interesting
     * realtime message should be the corresponding `event` for the same presence id.
     */
    private function receivePresenceEvent(
        WebSocketClient $client,
        string $presenceId,
        string $action,
        string $status,
        array $expectedMetadata,
        ?string $expectedUserId = null
    ): array {
        do {
            $message = \json_decode($client->receive(), true);
        } while (
            ($message['type'] ?? null) !== 'event'
            || ($message['data']['payload']['$id'] ?? null) !== $presenceId
        );

        $this->assertPresenceRealtimeEvent(
            $message,
            $presenceId,
            $action,
            $status,
            $expectedMetadata,
            $expectedUserId
        );

        return $message;
    }

    private function connectPresenceSocket(bool $authenticated = true, int $timeout = 2): WebSocketClient
    {
        $headers = [
            'origin' => 'http://localhost',
        ];

        if ($authenticated) {
            $headers['cookie'] = 'a_session_' . $this->getProject()['$id'] . '=' . $this->getUser()['session'];
        }

        $client = $this->getWebsocket(['presences'], $headers, timeout: $timeout);
        $response = \json_decode($client->receive(), true);

        $this->assertSame('connected', $response['type'] ?? null);

        return $client;
    }

    private function getServerHeaders(): array
    {
        return [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
    }

    private function getPresencePermissions(string $userId): array
    {
        $this->assertNotEmpty($userId);

        return [
            Permission::read(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ];
    }

    public function testPresenceMessageCreatesPresenceAndPersists(): void
    {
        $presenceId = ID::unique();
        $userId = $this->getUser()['$id'];
        $client = $this->connectPresenceSocket(true, 5);

        $client->send(\json_encode([
            'type' => 'presence',
            'data' => [
                'presenceId' => $presenceId,
                'status' => 'online',
                'metadata' => [
                    'device' => 'web',
                ],
                'permissions' => $this->getPresencePermissions($userId),
            ],
        ]));

        $this->assertPresenceResponseThenUpsertEvent(
            $client,
            'online',
            ['device' => 'web'],
            $userId
        );

        $read = $this->client->call(
            Client::METHOD_GET,
            '/presences/' . $presenceId,
            $this->getServerHeaders()
        );

        $this->assertSame(200, $read['headers']['status-code']);
        $this->assertSame($presenceId, $read['body']['$id']);
        $this->assertSame($userId, $read['body']['userId']);
        $this->assertSame('online', $read['body']['status']);
        $this->assertSame(['device' => 'web'], $read['body']['metadata']);

        $client->close();
    }

    public function testPresenceMessageUpsertWithSamePresenceIdPersistsSingleUpdatedRecord(): void
    {
        $presenceId = ID::unique();
        $userId = $this->getUser()['$id'];
        $client = $this->connectPresenceSocket(true, 5);

        $client->send(\json_encode([
            'type' => 'presence',
            'data' => [
                'presenceId' => $presenceId,
                'status' => 'away',
                'metadata' => [
                    'source' => 'first',
                ],
                'permissions' => $this->getPresencePermissions($userId),
            ],
        ]));
        $this->assertPresenceResponseThenUpsertEvent(
            $client,
            'away',
            ['source' => 'first'],
            $userId
        );

        $client->send(\json_encode([
            'type' => 'presence',
            'data' => [
                'presenceId' => $presenceId,
                'status' => 'busy',
                'metadata' => [
                    'source' => 'second',
                ],
                'permissions' => $this->getPresencePermissions($userId),
            ],
        ]));
        $this->assertPresenceResponseThenUpsertEvent(
            $client,
            'busy',
            ['source' => 'second'],
            $userId
        );

        $list = $this->client->call(
            Client::METHOD_GET,
            '/presences',
            $this->getServerHeaders(),
            [
                'queries' => [
                    Query::equal('$id', [$presenceId])->toString(),
                    Query::equal('userId', [$userId])->toString(),
                ],
            ]
        );

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertSame(1, $list['body']['total']);
        $this->assertCount(1, $list['body']['presences']);
        $this->assertSame($presenceId, $list['body']['presences'][0]['$id']);
        $this->assertSame($userId, $list['body']['presences'][0]['userId']);
        $this->assertSame('busy', $list['body']['presences'][0]['status']);
        $this->assertSame(['source' => 'second'], $list['body']['presences'][0]['metadata']);

        $client->close();
    }

    public function testPresenceMessageUpsertWithSameUserPersistsSingleRecord(): void
    {
        $firstPresenceId = ID::unique();
        $secondPresenceId = ID::unique();
        $userId = $this->getUser()['$id'];
        $client = $this->connectPresenceSocket(true, 5);

        $client->send(\json_encode([
            'type' => 'presence',
            'data' => [
                'presenceId' => $firstPresenceId,
                'status' => 'away',
                'metadata' => [
                    'source' => 'first-user-upsert',
                ],
                'permissions' => $this->getPresencePermissions($userId),
            ],
        ]));
        $this->assertPresenceResponseThenUpsertEvent(
            $client,
            'away',
            ['source' => 'first-user-upsert'],
            $userId
        );

        $client->send(\json_encode([
            'type' => 'presence',
            'data' => [
                'presenceId' => $secondPresenceId,
                'status' => 'busy',
                'metadata' => [
                    'source' => 'second-user-upsert',
                ],
                'permissions' => $this->getPresencePermissions($userId),
            ],
        ]));
        $this->assertPresenceResponseThenUpsertEvent(
            $client,
            'busy',
            ['source' => 'second-user-upsert'],
            $userId
        );

        $list = $this->client->call(
            Client::METHOD_GET,
            '/presences',
            $this->getServerHeaders(),
            [
                'queries' => [
                    Query::equal('userId', [$userId])->toString(),
                ],
            ]
        );

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertSame(1, $list['body']['total']);
        $this->assertCount(1, $list['body']['presences']);
        $this->assertSame($userId, $list['body']['presences'][0]['userId']);
        $this->assertSame('busy', $list['body']['presences'][0]['status']);
        $this->assertSame(['source' => 'second-user-upsert'], $list['body']['presences'][0]['metadata']);

        $client->close();
    }

    public function testPresenceMessageValidationErrors(): void
    {
        $client = $this->connectPresenceSocket();

        $client->send(\json_encode([
            'type' => 'presence',
            'data' => [
                'metadata' => [
                    'device' => 'web',
                ],
            ],
        ]));
        $missingStatus = \json_decode($client->receive(), true);
        $this->assertSame('error', $missingStatus['type'] ?? null);
        $this->assertStringContainsString('status must be provided', $missingStatus['data']['message'] ?? '');

        $client->send(\json_encode([
            'type' => 'presence',
            'data' => [
                'status' => 'online',
                'permissions' => 'invalid',
            ],
        ]));
        $invalidPermissions = \json_decode($client->receive(), true);
        $this->assertSame('error', $invalidPermissions['type'] ?? null);
        $this->assertStringContainsString('permissions must be an array', $invalidPermissions['data']['message'] ?? '');

        $client->close();
    }

    public function testPresenceMessageRequiresAuthenticatedUser(): void
    {
        $client = $this->connectPresenceSocket(false);

        $client->send(\json_encode([
            'type' => 'presence',
            'data' => [
                'status' => 'online',
            ],
        ]));

        $response = \json_decode($client->receive(), true);
        $this->assertSame('error', $response['type'] ?? null);
        $this->assertSame(401, $response['data']['code'] ?? null);
        $this->assertSame('User must be authorized', $response['data']['message'] ?? null);

        $client->close();
    }

    public function testChannelParsing(): void
    {
        $presenceId = ID::unique();
        $userId = $this->getUser()['$id'];
        $headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $this->getUser()['session'],
        ];

        $client = $this->getWebsocket(['presences', 'presences.' . $presenceId], $headers, timeout: 5);
        $connected = \json_decode($client->receive(), true);

        $this->assertSame('connected', $connected['type'] ?? null);
        $this->assertCount(2, $connected['data']['channels'] ?? []);
        $this->assertContains('presences', $connected['data']['channels']);
        $this->assertContains('presences.' . $presenceId, $connected['data']['channels']);

        $create = $this->client->call(
            Client::METHOD_PUT,
            '/presences/' . $presenceId,
            $this->getServerHeaders(),
            [
                'userId' => $userId,
                'status' => 'online',
                'metadata' => ['source' => 'channel-parsing-create'],
                'permissions' => $this->getPresencePermissions($userId),
            ]
        );
        $this->assertSame(200, $create['headers']['status-code']);

        $createEvent = \json_decode($client->receive(), true);
        $this->assertPresenceRealtimeEvent(
            $createEvent,
            $presenceId,
            'upsert',
            'online',
            ['source' => 'channel-parsing-create']
        );

        $update = $this->client->call(
            Client::METHOD_PATCH,
            '/presences/' . $presenceId,
            $this->getServerHeaders(),
            [
                'status' => 'away',
                'metadata' => ['source' => 'channel-parsing-update'],
            ]
        );
        $this->assertSame(200, $update['headers']['status-code']);

        $updateEvent = \json_decode($client->receive(), true);
        $this->assertPresenceRealtimeEvent(
            $updateEvent,
            $presenceId,
            'update',
            'away',
            ['source' => 'channel-parsing-update']
        );

        $delete = $this->client->call(
            Client::METHOD_DELETE,
            '/presences/' . $presenceId,
            $this->getServerHeaders()
        );
        $this->assertSame(204, $delete['headers']['status-code']);

        $deleteEvent = \json_decode($client->receive(), true);
        $this->assertPresenceRealtimeEvent(
            $deleteEvent,
            $presenceId,
            'delete',
            'away',
            ['source' => 'channel-parsing-update']
        );

        $client->close();
    }

    public function testPresenceMessageEmitsCreateAndDeleteEvents(): void
    {
        $presenceId = ID::unique();
        $userId = $this->getUser()['$id'];
        $headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $this->getUser()['session'],
        ];

        $listener = $this->getWebsocket(['presences', 'presences.' . $presenceId], $headers, timeout: 8);
        $connected = \json_decode($listener->receive(), true);
        $this->assertSame('connected', $connected['type'] ?? null);
        $this->assertCount(2, $connected['data']['channels'] ?? []);
        $this->assertContains('presences', $connected['data']['channels'] ?? []);
        $this->assertContains('presences.' . $presenceId, $connected['data']['channels'] ?? []);
        $this->assertCount(1, $connected['data']['subscriptions'] ?? []);
        $this->assertNotEmpty(array_values($connected['data']['subscriptions'] ?? []));

        $publisher = $this->connectPresenceSocket(true, timeout: 8);

        $publisher->send(\json_encode([
            'type' => 'presence',
            'data' => [
                'presenceId' => $presenceId,
                'status' => 'online',
                'metadata' => [
                    'source' => 'realtime-create-delete-events',
                ],
                'permissions' => $this->getPresencePermissions($userId),
            ],
        ]));

        $receivedPresenceId = $this->assertPresenceResponseThenUpsertEvent(
            $publisher,
            'online',
            ['source' => 'realtime-create-delete-events'],
            $userId
        );
        $this->assertSame($presenceId, $receivedPresenceId);

        $createEvent = \json_decode($listener->receive(), true);
        $this->assertPresenceRealtimeEvent(
            $createEvent,
            $presenceId,
            'upsert',
            'online',
            ['source' => 'realtime-create-delete-events']
        );

        $publisher->close();

        $deleteEvent = \json_decode($listener->receive(), true);
        $this->assertPresenceRealtimeEvent(
            $deleteEvent,
            $presenceId,
            'delete',
            'online',
            ['source' => 'realtime-create-delete-events']
        );

        $listener->close();
    }

    public function testPresencePermission(): void
    {
        $presenceIdAny = ID::unique();
        $presenceIdUsers = ID::unique();
        $presenceIdOwner = ID::unique();

        $user1 = $this->getUser();
        $user1Id = $user1['$id'];
        $user2 = $this->getUser(true);
        $user3 = $this->getUser(true);
        $projectId = $this->getProject()['$id'];

        $user1Headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $user1['session'],
        ];

        $user2Headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $user2['session'],
        ];

        $user3Headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $user3['session'],
        ];

        $user1Listener = $this->getWebsocket(['presences', 'presences.' . $presenceIdAny, 'presences.' . $presenceIdUsers, 'presences.' . $presenceIdOwner], $user1Headers, timeout: 3);
        $user2Listener = $this->getWebsocket(['presences', 'presences.' . $presenceIdAny, 'presences.' . $presenceIdUsers, 'presences.' . $presenceIdOwner], $user2Headers, timeout: 3);
        $user3Listener = $this->getWebsocket(['presences', 'presences.' . $presenceIdAny, 'presences.' . $presenceIdUsers, 'presences.' . $presenceIdOwner], $user3Headers, timeout: 3);

        $this->assertSame('connected', (\json_decode($user1Listener->receive(), true))['type'] ?? null);
        $this->assertSame('connected', (\json_decode($user2Listener->receive(), true))['type'] ?? null);
        $this->assertSame('connected', (\json_decode($user3Listener->receive(), true))['type'] ?? null);

        $publisher = $this->getWebsocket(['presences'], $user1Headers, timeout: 5);
        $this->assertSame('connected', (\json_decode($publisher->receive(), true))['type'] ?? null);

        $publisher->send(\json_encode([
            'type' => 'presence',
            'data' => [
                'presenceId' => $presenceIdAny,
                'status' => 'online',
                'metadata' => [
                    'visibility' => 'any',
                ],
                'permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
            ],
        ]));

        $receivedPresenceId = $this->assertPresenceResponseThenUpsertEvent(
            $publisher,
            'online',
            ['visibility' => 'any'],
            $user1Id
        );
        $this->assertSame($presenceIdAny, $receivedPresenceId);

        $this->assertPresenceRealtimeEvent(
            \json_decode($user1Listener->receive(), true),
            $presenceIdAny,
            'upsert',
            'online',
            ['visibility' => 'any'],
            $user1Id
        );
        $this->assertPresenceRealtimeEvent(
            \json_decode($user2Listener->receive(), true),
            $presenceIdAny,
            'upsert',
            'online',
            ['visibility' => 'any'],
            $user1Id
        );
        $this->assertPresenceRealtimeEvent(
            \json_decode($user3Listener->receive(), true),
            $presenceIdAny,
            'upsert',
            'online',
            ['visibility' => 'any'],
            $user1Id
        );

        $publisher->send(\json_encode([
            'type' => 'presence',
            'data' => [
                'presenceId' => $presenceIdUsers,
                'status' => 'away',
                'metadata' => [
                    'visibility' => 'users',
                ],
                'permissions' => [
                    Permission::read(Role::users()),
                    Permission::update(Role::users()),
                    Permission::delete(Role::users()),
                ],
            ],
        ]));

        $receivedPresenceId = $this->assertPresenceResponseThenUpsertEvent(
            $publisher,
            'away',
            ['visibility' => 'users'],
            $user1Id
        );
        $this->assertSame($presenceIdUsers, $receivedPresenceId);

        $this->assertPresenceRealtimeEvent(
            \json_decode($user1Listener->receive(), true),
            $presenceIdUsers,
            'upsert',
            'away',
            ['visibility' => 'users'],
            $user1Id
        );
        $this->assertPresenceRealtimeEvent(
            \json_decode($user3Listener->receive(), true),
            $presenceIdUsers,
            'upsert',
            'away',
            ['visibility' => 'users'],
            $user1Id
        );
        $this->assertPresenceRealtimeEvent(
            \json_decode($user2Listener->receive(), true),
            $presenceIdUsers,
            'upsert',
            'away',
            ['visibility' => 'users'],
            $user1Id
        );

        $publisher->send(\json_encode([
            'type' => 'presence',
            'data' => [
                'presenceId' => $presenceIdOwner,
                'status' => 'busy',
                'metadata' => [
                    'visibility' => 'owner',
                ],
                'permissions' => [
                    Permission::read(Role::user($user1Id)),
                    Permission::update(Role::user($user1Id)),
                    Permission::delete(Role::user($user1Id)),
                ],
            ],
        ]));

        $receivedPresenceId = $this->assertPresenceResponseThenUpsertEvent(
            $publisher,
            'busy',
            ['visibility' => 'owner'],
            $user1Id
        );
        $this->assertSame($presenceIdOwner, $receivedPresenceId);

        $this->assertPresenceRealtimeEvent(
            \json_decode($user1Listener->receive(), true),
            $presenceIdOwner,
            'upsert',
            'busy',
            ['visibility' => 'owner'],
            $user1Id
        );
        $this->assertNoRealtimeEvent($user2Listener);
        $this->assertNoRealtimeEvent($user3Listener);

        $publisher->close();
        $user1Listener->close();
        $user2Listener->close();
        $user3Listener->close();
    }
}
