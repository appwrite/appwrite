<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Presences;

use Appwrite\Tests\Async\Exceptions\Critical;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use WebSocket\Client as WebSocketClient;
use WebSocket\TimeoutException;

final class PresenceRealtimeClientTest extends Scope
{
    use ProjectCustom;
    use SideClient;

    private static array $presenceApiKeyCache = [];

    private function bootstrapIsolatedProject(): array
    {
        $project = $this->getProject(true);
        self::$project = $project;

        $user = $this->getUser(true);
        $headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $project['$id'] . '=' . $user['session'],
        ];

        return [$project, $user, $headers];
    }

    private function getServerHeaders(array $project): array
    {
        return [
            'content-type' => 'application/json',
            'x-appwrite-project' => $project['$id'],
            'x-appwrite-key' => $this->getPresenceApiKey($project),
        ];
    }

    private function getPresenceApiKey(array $project): string
    {
        $projectId = $project['$id'];

        if (!empty(self::$presenceApiKeyCache[$projectId])) {
            return self::$presenceApiKeyCache[$projectId];
        }

        // Realtime tests validate HTTP reads of presences; those endpoints require `presences.read`.
        self::$presenceApiKeyCache[$projectId] = $this->getNewKey([
            'presences.read',
            'presences.write',
        ]);

        return self::$presenceApiKeyCache[$projectId];
    }

    private function connectRealtimeAndSubscribe(
        array $project,
        array $headers,
        array $channels = [],
        int $timeout = 1
    ): WebSocketClient {
        $queryString = \http_build_query([
            'project' => $project['$id'],
        ]);

        $client = new WebSocketClient(
            'ws://appwrite.test/v1/realtime?' . $queryString,
            [
                'headers' => $headers,
                'timeout' => $timeout,
            ]
        );

        $connected = \json_decode($client->receive(), true);
        $this->assertSame('connected', $connected['type'] ?? null);

        if (empty($channels)) {
            return $client;
        }

        $client->send(\json_encode([
            'type' => 'subscribe',
            'data' => [[
                'channels' => $channels,
            ]],
        ]));

        $subscribeResponse = \json_decode($client->receive(), true);
        $this->assertSame('response', $subscribeResponse['type'] ?? null);
        $this->assertSame('subscribe', $subscribeResponse['data']['to'] ?? null);
        $this->assertTrue($subscribeResponse['data']['success'] ?? false);
        $this->assertNotEmpty($subscribeResponse['data']['subscriptions'] ?? []);

        return $client;
    }

    private function receiveUntil(
        WebSocketClient $client,
        callable $match,
        int $timeoutMs = 800,
        int $pollMs = 50
    ): array {
        $deadline = \microtime(true) + ($timeoutMs / 1000);
        $lastMessage = [];

        while (\microtime(true) < $deadline) {
            try {
                $message = \json_decode($client->receive(), true);
            } catch (TimeoutException) {
                \usleep($pollMs * 1000);
                continue;
            }

            if (!\is_array($message)) {
                continue;
            }

            $lastMessage = $message;
            if ($match($message)) {
                return $message;
            }
        }

        $this->fail('Timed out waiting for expected websocket frame. Last frame: ' . \json_encode($lastMessage));
    }

    private function assertQuietFor(WebSocketClient $client, callable $forbidden, int $timeoutMs = 150): void
    {
        $deadline = \microtime(true) + ($timeoutMs / 1000);
        while (\microtime(true) < $deadline) {
            try {
                $message = \json_decode($client->receive(), true);
            } catch (TimeoutException) {
                continue;
            }

            if (!\is_array($message)) {
                continue;
            }

            if ($forbidden($message)) {
                $this->fail('Received forbidden websocket frame: ' . \json_encode($message));
            }
        }
    }

    private function assertPresenceRealtimeEvent(
        array $event,
        string $presenceId,
        string $action,
        string $status,
        array $metadata,
        string $expectedUserId
    ): void {
        $this->assertSame('event', $event['type'] ?? null);
        $this->assertContains('presences', $event['data']['channels'] ?? []);
        $this->assertContains('presences.' . $presenceId, $event['data']['channels'] ?? []);
        $this->assertContains('presences.' . $presenceId . '.' . $action, $event['data']['events'] ?? []);
        $this->assertSame($presenceId, $event['data']['payload']['$id'] ?? null);
        $this->assertSame($status, $event['data']['payload']['status'] ?? null);
        $this->assertSame($metadata, $event['data']['payload']['metadata'] ?? []);
        $this->assertSame($expectedUserId, $event['data']['payload']['userId'] ?? null);
    }

    private function receivePresenceEvent(
        WebSocketClient $client,
        string $presenceId,
        string $action,
        string $status,
        array $metadata,
        string $expectedUserId,
        int $timeoutMs = 2500
    ): array {
        $event = $this->receiveUntil(
            $client,
            fn (array $message): bool => ($message['type'] ?? null) === 'event'
                && ($message['data']['payload']['$id'] ?? null) === $presenceId
                && \in_array('presences.' . $presenceId . '.' . $action, $message['data']['events'] ?? [], true),
            $timeoutMs
        );

        $this->assertPresenceRealtimeEvent($event, $presenceId, $action, $status, $metadata, $expectedUserId);
        return $event;
    }

    private function collectPresenceOutcome(
        WebSocketClient $client,
        string $presenceId,
        string $expectedStatus,
        array $expectedMetadata,
        string $expectedUserId
    ): void {
        $response = null;
        $event = null;

        $this->receiveUntil($client, function (array $message) use (
            &$response,
            &$event,
            $presenceId,
            $expectedStatus,
            $expectedMetadata,
            $expectedUserId
        ): bool {
            $type = $message['type'] ?? null;
            if ($type === 'response' && ($message['data']['to'] ?? null) === 'presence') {
                if (($message['data']['presence']['$id'] ?? null) !== $presenceId) {
                    return false;
                }
                $this->assertSame($expectedStatus, $message['data']['presence']['status'] ?? null);
                $this->assertSame($expectedMetadata, $message['data']['presence']['metadata'] ?? null);
                $response = $message;
            }

            if ($type === 'event' && ($message['data']['payload']['$id'] ?? null) === $presenceId) {
                if (!\in_array('presences.' . $presenceId . '.upsert', $message['data']['events'] ?? [], true)) {
                    return false;
                }
                $this->assertPresenceRealtimeEvent($message, $presenceId, 'upsert', $expectedStatus, $expectedMetadata, $expectedUserId);
                $event = $message;
            }

            return $response !== null && $event !== null;
        }, 2500);
    }

    private function receiveErrorMessage(WebSocketClient $client): array
    {
        $error = $this->receiveUntil(
            $client,
            fn (array $message): bool => ($message['type'] ?? null) === 'error',
            3000
        );
        $this->assertSame('error', $error['type'] ?? null);
        return $error;
    }

    private function sendPresenceMessage(
        WebSocketClient $client,
        string $presenceId,
        string $status,
        array $metadata,
        array $permissions
    ): void {
        $client->send(\json_encode([
            'type' => 'presence',
            'data' => [
                'presenceId' => $presenceId,
                'status' => $status,
                'metadata' => $metadata,
                'permissions' => $permissions,
            ],
        ]));
    }

    private function getPresencePermissions(string|Role $readRole): array
    {
        return [
            Permission::read($readRole),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ];
    }

    public function testPresenceUpsertSenderGetsResponseAndEvent(): void
    {
        [$project, $user, $headers] = $this->bootstrapIsolatedProject();
        $presenceId = ID::unique();
        $metadata = ['testRunId' => ID::unique(), 'case' => 'upsert-basic'];

        $publisher = $this->connectRealtimeAndSubscribe(
            $project,
            $headers,
            ['presences', 'presences.' . $presenceId],
            timeout: 2
        );

        try {
            $this->sendPresenceMessage(
                $publisher,
                $presenceId,
                'online',
                $metadata,
                $this->getPresencePermissions(Role::any())
            );

            $this->collectPresenceOutcome($publisher, $presenceId, 'online', $metadata, $user['$id']);

            $read = $this->client->call(
                Client::METHOD_GET,
                '/presences/' . $presenceId,
                $this->getServerHeaders($project)
            );

            $this->assertSame(200, $read['headers']['status-code']);
            $this->assertSame($presenceId, $read['body']['$id']);
            $this->assertSame($user['$id'], $read['body']['userId']);
            $this->assertSame('online', $read['body']['status']);
            $this->assertSame($metadata, $read['body']['metadata']);
        } finally {
            $publisher->close();
        }
    }

    public function testPresenceUpsertSameUserUpdatesSingleRecord(): void
    {
        [$project, $user, $headers] = $this->bootstrapIsolatedProject();
        $firstPresenceId = ID::unique();
        $secondPresenceId = ID::unique();
        $marker = ID::unique();

        $publisher = $this->connectRealtimeAndSubscribe(
            $project,
            $headers,
            ['presences', 'presences.' . $firstPresenceId, 'presences.' . $secondPresenceId],
            timeout: 2
        );

        try {
            $firstMetadata = ['testRunId' => $marker, 'step' => 'first'];
            $secondMetadata = ['testRunId' => $marker, 'step' => 'second'];

            $this->sendPresenceMessage(
                $publisher,
                $firstPresenceId,
                'away',
                $firstMetadata,
                $this->getPresencePermissions(Role::any())
            );
            $this->collectPresenceOutcome($publisher, $firstPresenceId, 'away', $firstMetadata, $user['$id']);

            $this->sendPresenceMessage(
                $publisher,
                $secondPresenceId,
                'busy',
                $secondMetadata,
                $this->getPresencePermissions(Role::any())
            );
            // The server keeps one row per user keyed by userInternalId and anchors $id to the
            // first claim, so the second upsert's response/event come back under $firstPresenceId.
            $this->collectPresenceOutcome($publisher, $firstPresenceId, 'busy', $secondMetadata, $user['$id']);

            $list = $this->client->call(
                Client::METHOD_GET,
                '/presences',
                $this->getServerHeaders($project),
                [
                    'queries' => [
                        Query::equal('userId', [$user['$id']])->toString(),
                    ],
                ]
            );

            $this->assertSame(200, $list['headers']['status-code']);
            $this->assertSame(1, $list['body']['total']);
            $this->assertSame($user['$id'], $list['body']['presences'][0]['userId']);
            $this->assertSame('busy', $list['body']['presences'][0]['status']);
            $this->assertSame($secondMetadata, $list['body']['presences'][0]['metadata']);
        } finally {
            $publisher->close();
        }
    }

    public function testPresenceValidationErrorsReturnErrorOnly(): void
    {
        [$project, , $headers] = $this->bootstrapIsolatedProject();
        $presenceId = ID::unique();
        $client = $this->connectRealtimeAndSubscribe($project, $headers, ['presences', 'presences.' . $presenceId], timeout: 2);

        try {
            $client->send(\json_encode([
                'type' => 'presence',
                'data' => [
                    'presenceId' => $presenceId,
                    'metadata' => [
                        'testRunId' => ID::unique(),
                    ],
                ],
            ]));
            $missingStatus = $this->receiveErrorMessage($client);
            $this->assertStringContainsString('Payload is not valid. Status is required', (string) ($missingStatus['data']['message'] ?? ''));
            $this->assertQuietFor(
                $client,
                fn (array $frame): bool => ($frame['type'] ?? null) === 'event'
                    && ($frame['data']['payload']['$id'] ?? null) === $presenceId
            );

            $client->send(\json_encode([
                'type' => 'presence',
                'data' => [
                    'presenceId' => $presenceId,
                    'status' => 'online',
                    'permissions' => 'invalid',
                ],
            ]));
            $invalidPermissions = $this->receiveErrorMessage($client);
            $this->assertStringContainsString('permissions: Permissions must be an array of strings', (string) ($invalidPermissions['data']['message'] ?? ''));
            $this->assertQuietFor(
                $client,
                fn (array $frame): bool => ($frame['type'] ?? null) === 'event'
                    && ($frame['data']['payload']['$id'] ?? null) === $presenceId
            );
        } finally {
            $client->close();
        }
    }

    public function testPresenceUnauthenticatedUserGetsAuthorizationError(): void
    {
        $project = $this->getProject(true);
        self::$project = $project;

        $presenceId = ID::unique();
        $client = $this->connectRealtimeAndSubscribe(
            $project,
            ['origin' => 'http://localhost'],
            ['presences', 'presences.' . $presenceId],
            timeout: 2
        );

        try {
            $client->send(\json_encode([
                'type' => 'presence',
                'data' => [
                    'presenceId' => $presenceId,
                    'status' => 'online',
                    'metadata' => ['testRunId' => ID::unique()],
                ],
            ]));

            $error = $this->receiveErrorMessage($client);
            $this->assertSame(401, $error['data']['code'] ?? null);
            $this->assertSame('User must be authorized', $error['data']['message'] ?? null);

            $this->assertQuietFor(
                $client,
                fn (array $frame): bool => ($frame['type'] ?? null) === 'event'
                    && ($frame['data']['payload']['$id'] ?? null) === $presenceId
            );
        } finally {
            $client->close();
        }
    }

    public function testChannelParsingChannelsAndEvents(): void
    {
        [$project, $user, $headers] = $this->bootstrapIsolatedProject();
        $presenceId = ID::unique();
        $listener = $this->connectRealtimeAndSubscribe(
            $project,
            $headers,
            ['presences', 'presences.' . $presenceId],
            timeout: 2
        );

        try {
            $createMetadata = ['testRunId' => ID::unique(), 'source' => 'channel-create'];
            $updateMetadata = ['testRunId' => $createMetadata['testRunId'], 'source' => 'channel-update'];

            $create = $this->client->call(
                Client::METHOD_PUT,
                '/presences/' . $presenceId,
                $this->getServerHeaders($project),
                [
                    'userId' => $user['$id'],
                    'status' => 'online',
                    'metadata' => $createMetadata,
                    'permissions' => $this->getPresencePermissions(Role::any()),
                ]
            );
            $this->assertSame(200, $create['headers']['status-code']);
            $this->receivePresenceEvent($listener, $presenceId, 'upsert', 'online', $createMetadata, $user['$id']);

            $update = $this->client->call(
                Client::METHOD_PATCH,
                '/presences/' . $presenceId,
                $this->getServerHeaders($project),
                [
                    'status' => 'away',
                    'metadata' => $updateMetadata,
                ]
            );
            $this->assertSame(200, $update['headers']['status-code']);
            $this->receivePresenceEvent($listener, $presenceId, 'update', 'away', $updateMetadata, $user['$id']);

            $delete = $this->client->call(
                Client::METHOD_DELETE,
                '/presences/' . $presenceId,
                $this->getServerHeaders($project)
            );
            $this->assertSame(204, $delete['headers']['status-code']);
            $this->receivePresenceEvent($listener, $presenceId, 'delete', 'away', $updateMetadata, $user['$id']);
        } finally {
            $listener->close();
        }
    }

    public function testPresencePermissionsReceiverRouting(): void
    {
        [$project, $user1, $user1Headers] = $this->bootstrapIsolatedProject();
        $user2 = $this->getUser(true);

        $user2Headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $project['$id'] . '=' . $user2['session'],
        ];

        $presenceIdAny = ID::unique();
        $presenceIdOwner = ID::unique();

        $channels = [
            'presences',
            'presences.' . $presenceIdAny,
            'presences.' . $presenceIdOwner,
        ];

        $publisher = $this->connectRealtimeAndSubscribe($project, $user1Headers, ['presences'], timeout: 1);
        $listener1 = $this->connectRealtimeAndSubscribe($project, $user1Headers, $channels, timeout: 1);
        $listener2 = $this->connectRealtimeAndSubscribe($project, $user2Headers, $channels, timeout: 1);

        try {
            $metadataAny = ['testRunId' => ID::unique(), 'visibility' => 'any'];
            $this->sendPresenceMessage(
                $publisher,
                $presenceIdAny,
                'online',
                $metadataAny,
                $this->getPresencePermissions(Role::any())
            );
            $this->collectPresenceOutcome($publisher, $presenceIdAny, 'online', $metadataAny, $user1['$id']);
            $this->receivePresenceEvent($listener1, $presenceIdAny, 'upsert', 'online', $metadataAny, $user1['$id']);
            $this->receivePresenceEvent($listener2, $presenceIdAny, 'upsert', 'online', $metadataAny, $user1['$id']);

            $metadataOwner = ['testRunId' => ID::unique(), 'visibility' => 'owner'];
            $this->sendPresenceMessage(
                $publisher,
                $presenceIdOwner,
                'busy',
                $metadataOwner,
                $this->getPresencePermissions(Role::user($user1['$id']))
            );
            // Same user, so the server reuses the original record's $id ($presenceIdAny);
            // only permissions/status/metadata change — which is what permission routing should filter on.
            $this->collectPresenceOutcome($publisher, $presenceIdAny, 'busy', $metadataOwner, $user1['$id']);
            $this->receivePresenceEvent($listener1, $presenceIdAny, 'upsert', 'busy', $metadataOwner, $user1['$id']);
            $this->assertQuietFor(
                $listener2,
                fn (array $frame): bool => ($frame['type'] ?? null) === 'event'
                    && ($frame['data']['payload']['$id'] ?? null) === $presenceIdAny
                    && ($frame['data']['payload']['metadata']['visibility'] ?? null) === 'owner'
            );
        } finally {
            $publisher->close();
            $listener1->close();
            $listener2->close();
        }
    }

    public function testPresenceCloseEmitsDeleteEvent(): void
    {
        [$project, $user, $headers] = $this->bootstrapIsolatedProject();
        $presenceId = ID::unique();
        $metadata = ['testRunId' => ID::unique(), 'source' => 'close-delete'];

        $publisher = $this->connectRealtimeAndSubscribe($project, $headers, ['presences', 'presences.' . $presenceId], timeout: 1);
        $listener = $this->connectRealtimeAndSubscribe($project, $headers, ['presences', 'presences.' . $presenceId], timeout: 1);

        try {
            $this->sendPresenceMessage(
                $publisher,
                $presenceId,
                'online',
                $metadata,
                $this->getPresencePermissions(Role::any())
            );
            $this->collectPresenceOutcome($publisher, $presenceId, 'online', $metadata, $user['$id']);
            $this->receivePresenceEvent($listener, $presenceId, 'upsert', 'online', $metadata, $user['$id']);

            $publisher->close();

            $this->receivePresenceEvent($listener, $presenceId, 'delete', 'online', $metadata, $user['$id'], timeoutMs: 3000);
        } finally {
            $listener->close();
        }
    }

    public function testHttpDeleteThenCloseDoesNotDuplicateDeleteEvent(): void
    {
        [$project, $user, $headers] = $this->bootstrapIsolatedProject();
        $presenceId = ID::unique();
        $metadata = ['testRunId' => ID::unique(), 'source' => 'http-delete-then-close'];

        $publisher = $this->connectRealtimeAndSubscribe($project, $headers, ['presences', 'presences.' . $presenceId], timeout: 1);
        $listener = $this->connectRealtimeAndSubscribe($project, $headers, ['presences', 'presences.' . $presenceId], timeout: 1);

        try {
            // Publish a presence over WebSocket so the realtime worker tracks it in
            // its in-memory connection map under the publisher connection.
            $this->sendPresenceMessage(
                $publisher,
                $presenceId,
                'online',
                $metadata,
                $this->getPresencePermissions(Role::any())
            );
            $this->collectPresenceOutcome($publisher, $presenceId, 'online', $metadata, $user['$id']);
            $this->receivePresenceEvent($listener, $presenceId, 'upsert', 'online', $metadata, $user['$id']);

            // HTTP DELETE removes the row from the DB and emits the delete event via pubsub.
            // The realtime worker is expected to strip the presence from the publisher's
            // in-memory connection state when it processes the pubsub message.
            $delete = $this->client->call(
                Client::METHOD_DELETE,
                '/presences/' . $presenceId,
                $this->getServerHeaders($project)
            );
            $this->assertSame(204, $delete['headers']['status-code']);

            // Synchronization point: wait for the listener to receive the legitimate
            // delete event before closing the publisher. Redis pubsub broadcasts to
            // every realtime worker simultaneously, so the listener's worker observing
            // the event implies the publisher's worker has also processed it (and run
            // the in-memory cleanup) by the time onClose fires.
            $deleteEvents = [];
            $deleteEvents[] = $this->receivePresenceEvent($listener, $presenceId, 'delete', 'online', $metadata, $user['$id']);

            $publisher->close();

            // Watch for any additional presences.{id}.delete frame. A second one would
            // be the regression: onClose re-firing the event for a presence already
            // removed via HTTP DELETE.
            $deadline = \microtime(true) + 2.0;

            $this->assertEventually(
                function () use ($listener, $presenceId, $deadline, &$deleteEvents): void {
                    try {
                        $raw = $listener->receive();
                        $frame = \json_decode($raw, true);
                        if (
                            \is_array($frame)
                            && ($frame['type'] ?? null) === 'event'
                            && ($frame['data']['payload']['$id'] ?? null) === $presenceId
                            && \in_array('presences.' . $presenceId . '.delete', $frame['data']['events'] ?? [], true)
                        ) {
                            $deleteEvents[] = $frame;
                            if (\count($deleteEvents) > 1) {
                                throw new Critical(
                                    'Duplicate presence delete event after HTTP DELETE + WebSocket close: '
                                    . \json_encode($frame)
                                );
                            }
                        }
                    } catch (TimeoutException) {
                        // No frame this poll; fall through to deadline check.
                    }

                    if (\microtime(true) < $deadline) {
                        // Throw a non-Critical exception so assertEventually retries.
                        throw new \RuntimeException('still watching for duplicate delete event');
                    }
                },
                timeoutMs: 3000,
                waitMs: 0
            );

            $this->assertCount(
                1,
                $deleteEvents,
                'Expected exactly one presences.' . $presenceId . '.delete event; got ' . \count($deleteEvents)
            );
            $this->assertPresenceRealtimeEvent($deleteEvents[0], $presenceId, 'delete', 'online', $metadata, $user['$id']);
        } finally {
            $listener->close();
        }
    }
}
