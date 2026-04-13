<?php

namespace Tests\E2E\Services\Realtime;

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

class RealtimeCustomClientQueryTestWithMessage extends Scope
{
    use ProjectCustom;
    use SideClient;
    use RealtimeQueryBase;

    protected function supportForCheckConnectionStatus(): bool
    {
        return false;
    }

    /**
     * Same signature as `RealtimeBase::getWebsocket()`, but:
     * - never sends queries in the URL (avoids URL length limits)
     * - once connected, sends channel/query data using a `type: "subscribe"` message
     */
    private function getWebsocket(
        array $channels = [],
        array $headers = [],
        ?string $projectId = null,
        ?array $queries = null,
        int $timeout = 2
    ): WebSocketClient {
        if ($projectId === null) {
            $projectId = $this->getProject()['$id'];
        }

        $queryString = \http_build_query([
            'project' => $projectId,
        ]);

        $client = new WebSocketClient(
            'ws://appwrite.test/v1/realtime?' . $queryString,
            [
                'headers' => $headers,
                'timeout' => $timeout,
            ]
        );
        $connected = \json_decode($client->receive(), true);
        $this->assertEquals('connected', $connected['type'] ?? null);

        if (empty($channels)) {
            return $client;
        }

        if ($queries === []) {
            $queries = [Query::select(['*'])->toString()];
        }

        $payload = [[
            'channels' => $channels,
        ]];

        if ($queries !== null) {
            $payload[0]['queries'] = $queries;
        }

        $existingSubscriptions = $connected['data']['subscriptions'] ?? [];
        if (!empty($existingSubscriptions)) {
            $payload[0]['subscriptionId'] = $existingSubscriptions[\array_key_first($existingSubscriptions)];
        }

        $client->send(\json_encode([
            'type' => 'subscribe',
            'data' => $payload,
        ]));

        $response = \json_decode($client->receive(), true);
        $this->assertEquals('response', $response['type'] ?? null);
        $this->assertEquals('subscribe', $response['data']['to'] ?? null);
        $this->assertTrue($response['data']['success'] ?? false);
        $this->assertArrayHasKey('subscriptions', $response['data']);
        $this->assertIsArray($response['data']['subscriptions']);

        return $client;
    }

    /**
     * Connects (URL has no per-channel queries), then sends a subscribe message with the given query strings.
     * Used to assert server rejects unsupported query methods the same way as URL-based subscriptions.
     *
     * @param  array<int, string>  $queryStrings
     * @return array<string, mixed>
     */
    private function receiveSubscribeMessageResponse(
        array $channels,
        array $headers,
        array $queryStrings
    ): array {
        $projectId = $this->getProject()['$id'];
        $queryString = \http_build_query([
            'project' => $projectId,
        ]);

        $client = new WebSocketClient(
            'ws://appwrite.test/v1/realtime?' . $queryString,
            [
                'headers' => $headers,
                'timeout' => 2,
            ]
        );
        $connected = \json_decode($client->receive(), true);
        $this->assertEquals('connected', $connected['type'] ?? null);

        $client->send(\json_encode([
            'type' => 'subscribe',
            'data' => [[
                'channels' => $channels,
                'queries' => $queryStrings,
            ]],
        ]));

        $response = \json_decode($client->receive(), true);
        $client->close();

        return $response;
    }

    private function getWebsocketWithCustomQuery(array $queryParams, array $headers = [], int $timeout = 2): WebSocketClient
    {
        $queryString = \http_build_query($queryParams);

        return new WebSocketClient(
            'ws://appwrite.test/v1/realtime?' . $queryString,
            [
                'headers' => $headers,
                'timeout' => $timeout,
            ]
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $payloadEntries
     * @return array<string, mixed>
     */
    private function sendSubscribeMessage(WebSocketClient $client, array $payloadEntries): array
    {
        $client->send(\json_encode([
            'type' => 'subscribe',
            'data' => $payloadEntries,
        ]));
        $response = \json_decode($client->receive(), true);
        $this->assertEquals('response', $response['type'] ?? null);
        $this->assertEquals('subscribe', $response['data']['to'] ?? null);
        $this->assertTrue($response['data']['success'] ?? false);
        $this->assertArrayHasKey('subscriptions', $response['data']);
        $this->assertIsArray($response['data']['subscriptions']);

        return $response;
    }

    /**
     * subscriptionId: update with id from connected, create by omitting id, explicit new id,
     * duplicate id in one bulk (last wins), mixed bulk, idempotent repeat, empty queries → select-all.
     */
    public function testSubscribeMessageUpsertCreateAndEdgeCases(): void
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];
        $headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ];

        $queryString = \http_build_query([
            'project' => $projectId,
        ]);
        $client = new WebSocketClient(
            'ws://appwrite.test/v1/realtime?' . $queryString,
            [
                'headers' => $headers,
                'timeout' => 30,
            ]
        );
        $connected = \json_decode($client->receive(), true);
        $this->assertEquals('connected', $connected['type'] ?? null);
        $initialResponse = $this->sendSubscribeMessage($client, [[
            'channels' => ['documents'],
            'queries' => [Query::select(['*'])->toString()],
        ]]);
        $initialSubscriptionId = $initialResponse['data']['subscriptions'][0]['subscriptionId'] ?? '';
        $this->assertNotEmpty($initialSubscriptionId);

        $q1 = [Query::equal('status', ['q1'])->toString()];
        $r1 = $this->sendSubscribeMessage($client, [[
            'subscriptionId' => $initialSubscriptionId,
            'channels' => ['documents'],
            'queries' => $q1,
        ]]);
        $this->assertCount(1, $r1['data']['subscriptions']);
        $this->assertSame($initialSubscriptionId, $r1['data']['subscriptions'][0]['subscriptionId']);
        $this->assertSame($q1, $r1['data']['subscriptions'][0]['queries']);

        $q2 = [Query::equal('status', ['q2'])->toString()];
        $r2 = $this->sendSubscribeMessage($client, [[
            'subscriptionId' => $initialSubscriptionId,
            'channels' => ['documents'],
            'queries' => $q2,
        ]]);
        $this->assertSame($initialSubscriptionId, $r2['data']['subscriptions'][0]['subscriptionId']);
        $this->assertSame($q2, $r2['data']['subscriptions'][0]['queries']);

        $rOmit = $this->sendSubscribeMessage($client, [[
            'channels' => ['documents'],
            'queries' => [Query::equal('status', ['omitted-slot'])->toString()],
        ]]);
        $mintedId = $rOmit['data']['subscriptions'][0]['subscriptionId'];
        $this->assertNotSame($initialSubscriptionId, $mintedId);
        $this->assertNotEmpty($mintedId);

        $explicitNewId = ID::unique();
        $qExplicit = [Query::equal('status', ['explicit'])->toString()];
        $rExplicit = $this->sendSubscribeMessage($client, [[
            'subscriptionId' => $explicitNewId,
            'channels' => ['documents'],
            'queries' => $qExplicit,
        ]]);
        $this->assertSame($explicitNewId, $rExplicit['data']['subscriptions'][0]['subscriptionId']);
        $this->assertSame($qExplicit, $rExplicit['data']['subscriptions'][0]['queries']);

        $qFirst = [Query::equal('status', ['dup-a'])->toString()];
        $qSecond = [Query::equal('status', ['dup-b'])->toString()];
        $rDup = $this->sendSubscribeMessage($client, [
            [
                'subscriptionId' => $initialSubscriptionId,
                'channels' => ['documents'],
                'queries' => $qFirst,
            ],
            [
                'subscriptionId' => $initialSubscriptionId,
                'channels' => ['documents'],
                'queries' => $qSecond,
            ],
        ]);
        $this->assertCount(2, $rDup['data']['subscriptions']);
        $this->assertSame($initialSubscriptionId, $rDup['data']['subscriptions'][0]['subscriptionId']);
        $this->assertSame($initialSubscriptionId, $rDup['data']['subscriptions'][1]['subscriptionId']);
        $this->assertSame($qSecond, $rDup['data']['subscriptions'][1]['queries']);

        $rMixed = $this->sendSubscribeMessage($client, [
            [
                'subscriptionId' => $initialSubscriptionId,
                'channels' => ['documents'],
                'queries' => [Query::equal('status', ['mixed-update'])->toString()],
            ],
            [
                'channels' => ['documents'],
                'queries' => [Query::equal('status', ['mixed-new'])->toString()],
            ],
        ]);
        $this->assertCount(2, $rMixed['data']['subscriptions']);
        $this->assertSame($initialSubscriptionId, $rMixed['data']['subscriptions'][0]['subscriptionId']);
        $mixedSecondId = $rMixed['data']['subscriptions'][1]['subscriptionId'];
        $this->assertNotSame($initialSubscriptionId, $mixedSecondId);
        $this->assertNotEmpty($mixedSecondId);

        $rSame = $this->sendSubscribeMessage($client, [[
            'subscriptionId' => $initialSubscriptionId,
            'channels' => ['documents'],
            'queries' => [Query::equal('status', ['idempotent'])->toString()],
        ]]);
        $rSameAgain = $this->sendSubscribeMessage($client, [[
            'subscriptionId' => $initialSubscriptionId,
            'channels' => ['documents'],
            'queries' => [Query::equal('status', ['idempotent'])->toString()],
        ]]);
        $this->assertSame($rSame['data']['subscriptions'][0]['queries'], $rSameAgain['data']['subscriptions'][0]['queries']);

        $rEmpty = $this->sendSubscribeMessage($client, [[
            'subscriptionId' => $initialSubscriptionId,
            'channels' => ['documents'],
            'queries' => [],
        ]]);
        $this->assertCount(1, $rEmpty['data']['subscriptions']);
        $this->assertSame($initialSubscriptionId, $rEmpty['data']['subscriptions'][0]['subscriptionId']);

        $client->close();
    }

    public function testInvalidQueryShouldNotSubscribe(): void
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];
        $headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ];

        // Test 1: Simple invalid query method (contains is not allowed)
        $response = $this->receiveSubscribeMessageResponse(['documents'], $headers, [
            Query::contains('status', ['active'])->toString(),
        ]);
        $this->assertEquals('error', $response['type']);
        $this->assertStringContainsString('not supported in Realtime queries', $response['data']['message']);
        $this->assertStringContainsString('contains', $response['data']['message']);

        // Test 2: Invalid query method in nested AND query
        $response = $this->receiveSubscribeMessageResponse(['documents'], $headers, [
            Query::and([
                Query::equal('status', ['active']),
                Query::search('name', 'test'),
            ])->toString(),
        ]);
        $this->assertEquals('error', $response['type']);
        $this->assertStringContainsString('not supported in Realtime queries', $response['data']['message']);
        $this->assertStringContainsString('search', $response['data']['message']);

        // Test 3: Invalid query method in nested OR query
        $response = $this->receiveSubscribeMessageResponse(['documents'], $headers, [
            Query::or([
                Query::equal('status', ['active']),
                Query::between('score', 0, 100),
            ])->toString(),
        ]);
        $this->assertEquals('error', $response['type']);
        $this->assertStringContainsString('not supported in Realtime queries', $response['data']['message']);
        $this->assertStringContainsString('between', $response['data']['message']);

        // Test 4: Deeply nested invalid query (AND -> OR -> invalid)
        $response = $this->receiveSubscribeMessageResponse(['documents'], $headers, [
            Query::and([
                Query::equal('status', ['active']),
                Query::or([
                    Query::greaterThan('score', 50),
                    Query::startsWith('name', 'test'),
                ]),
            ])->toString(),
        ]);
        $this->assertEquals('error', $response['type']);
        $this->assertStringContainsString('not supported in Realtime queries', $response['data']['message']);
        $this->assertStringContainsString('startsWith', $response['data']['message']);

        // Test 5: Multiple invalid 'queries' in nested structure
        $response = $this->receiveSubscribeMessageResponse(['documents'], $headers, [
            Query::and([
                Query::contains('tags', ['important']),
                Query::or([
                    Query::endsWith('email', '@example.com'),
                    Query::equal('status', ['active']),
                ]),
            ])->toString(),
        ]);
        $this->assertEquals('error', $response['type']);
        $this->assertStringContainsString('not supported in Realtime queries', $response['data']['message']);
        $this->assertTrue(
            \str_contains($response['data']['message'], 'contains') ||
            \str_contains($response['data']['message'], 'endsWith')
        );
    }

    public function testProjectChannelWithHeaderOnly(): void
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        $client = $this->getWebsocketWithCustomQuery(
            [
                'project' => $projectId,
            ],
            [
                'origin' => 'http://localhost',
                'cookie' => 'a_session_' . $projectId . '=' . $session,
                'x-appwrite-project' => $projectId,
            ]
        );

        $response = \json_decode($client->receive(), true);
        $this->assertSame('connected', $response['type']);
        $subscribeResponse = $this->sendSubscribeMessage($client, [[
            'channels' => ['project'],
            'queries' => [Query::select(['*'])->toString()],
        ]]);
        $this->assertCount(1, $subscribeResponse['data']['subscriptions']);
        $this->assertSame(['project'], $subscribeResponse['data']['subscriptions'][0]['channels']);

        $client->close();

        $clientWithQuery = $this->getWebsocketWithCustomQuery(
            [
                'project' => $projectId,
            ],
            [
                'origin' => 'http://localhost',
                'cookie' => 'a_session_' . $projectId . '=' . $session,
                'x-appwrite-project' => $projectId,
            ]
        );

        $response = \json_decode($clientWithQuery->receive(), true);
        $this->assertSame('connected', $response['type']);
        $subscribeResponseWithQuery = $this->sendSubscribeMessage($clientWithQuery, [[
            'channels' => ['project'],
            'queries' => [Query::select(['*'])->toString()],
        ]]);
        $this->assertCount(1, $subscribeResponseWithQuery['data']['subscriptions']);
        $this->assertSame(['project'], $subscribeResponseWithQuery['data']['subscriptions'][0]['channels']);

        $clientWithQuery->close();
    }

    public function testQueryMessageFiltersEvents(): void
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $userId = $user['$id'] ?? '';
        $projectId = $this->getProject()['$id'];

        // Setup database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Query Message Test DB',
        ]);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Query Message Test Collection',
            'permissions' => [
                Permission::create(Role::user($userId)),
            ],
            'documentSecurity' => true,
        ]);
        $collectionId = $collection['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'key' => 'status',
            'size' => 256,
            'required' => false,
        ]);

        $this->assertEventually(function () use ($databaseId, $collectionId, $projectId) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/status', \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals('available', $response['body']['status']);
        }, 30000, 250);

        $targetDocumentId = ID::unique();
        $otherDocumentId = ID::unique();

        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::equal('$id', [$targetDocumentId])->toString(),
        ]);

        // Create matching document - should receive event
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => $targetDocumentId,
            'data' => [
                'status' => 'active',
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $event = \json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertEquals($targetDocumentId, $event['data']['payload']['$id']);

        // Create non-matching document - should NOT receive event
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => $otherDocumentId,
            'data' => [
                'status' => 'inactive',
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        try {
            $client->receive();
            $this->fail('Expected TimeoutException - event should be filtered by updated query');
        } catch (TimeoutException $e) {
            $this->assertTrue(true);
        }

        $client->close();
    }
}
