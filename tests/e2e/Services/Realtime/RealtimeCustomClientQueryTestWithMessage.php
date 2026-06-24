<?php

declare(strict_types=1);

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
use WebSocket\ConnectionException;
use WebSocket\TimeoutException;

final class RealtimeCustomClientQueryTestWithMessage extends Scope
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
     * @param  array<int, array<string, mixed>>  $payloadEntries
     * @return array<string, mixed>
     */
    private function sendUnsubscribeMessage(WebSocketClient $client, array $payloadEntries): array
    {
        $client->send(\json_encode([
            'type' => 'unsubscribe',
            'data' => $payloadEntries,
        ]));

        return \json_decode($client->receive(), true);
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

    /**
     * Update a subscription's queries/channels by reusing its subscriptionId.
     * Verifies the update takes effect on live event filtering (not just the response echo),
     * sibling subscriptions are untouched, unknown ids upsert as new, empty queries fall
     * back to select-all, and a removed id can be recreated by subscribing again.
     */
    public function testUpdateSubscriptionAndEdgeCases(): void
    {
        $user = $this->getUser();
        $userId = $user['$id'] ?? '';
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];
        $headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ];

        $queryString = \http_build_query(['project' => $projectId]);
        $client = new WebSocketClient(
            'ws://appwrite.test/v1/realtime?' . $queryString,
            [
                'headers' => $headers,
                'timeout' => 10,
            ]
        );
        $connected = \json_decode($client->receive(), true);
        $this->assertEquals('connected', $connected['type'] ?? null);

        $triggerAccountEvent = function () use ($projectId, $session): void {
            $this->client->call(Client::METHOD_PATCH, '/account/name', \array_merge([
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'cookie' => 'a_session_' . $projectId . '=' . $session,
            ]), ['name' => 'Update Sub Test ' . \uniqid()]);
        };

        // subA matches current user, subB never matches
        $created = $this->sendSubscribeMessage($client, [
            [
                'channels' => ['account'],
                'queries' => [Query::equal('$id', [$userId])->toString()],
            ],
            [
                'channels' => ['account'],
                'queries' => [Query::equal('$id', ['no-match-initial'])->toString()],
            ],
        ]);
        $subA = $created['data']['subscriptions'][0]['subscriptionId'];
        $subB = $created['data']['subscriptions'][1]['subscriptionId'];
        $this->assertNotSame($subA, $subB);

        $triggerAccountEvent();
        $event = \json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertSame([$subA], $event['data']['subscriptions']);

        // Swap: A -> non-matching, B -> matching. Same ids returned, server-side filter swaps.
        $swap = $this->sendSubscribeMessage($client, [
            [
                'subscriptionId' => $subA,
                'channels' => ['account'],
                'queries' => [Query::equal('$id', ['no-match-swapped'])->toString()],
            ],
            [
                'subscriptionId' => $subB,
                'channels' => ['account'],
                'queries' => [Query::equal('$id', [$userId])->toString()],
            ],
        ]);
        $this->assertSame($subA, $swap['data']['subscriptions'][0]['subscriptionId']);
        $this->assertSame($subB, $swap['data']['subscriptions'][1]['subscriptionId']);

        $triggerAccountEvent();
        $event = \json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertSame([$subB], $event['data']['subscriptions']);

        // Sibling isolation: updating only subA must leave subB's matching filter intact.
        $isolation = $this->sendSubscribeMessage($client, [[
            'subscriptionId' => $subA,
            'channels' => ['account'],
            'queries' => [Query::equal('$id', [$userId])->toString()],
        ]]);
        $this->assertSame($subA, $isolation['data']['subscriptions'][0]['subscriptionId']);

        $triggerAccountEvent();
        $event = \json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertEqualsCanonicalizing([$subA, $subB], $event['data']['subscriptions']);

        // Empty queries on update -> select-all; subA still matches every event on the channel.
        $empty = $this->sendSubscribeMessage($client, [[
            'subscriptionId' => $subA,
            'channels' => ['account'],
            'queries' => [],
        ]]);
        $this->assertSame($subA, $empty['data']['subscriptions'][0]['subscriptionId']);

        $triggerAccountEvent();
        $event = \json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertEqualsCanonicalizing([$subA, $subB], $event['data']['subscriptions']);

        // Unknown subscriptionId upserts as a new subscription.
        $ghostId = ID::unique();
        $ghost = $this->sendSubscribeMessage($client, [[
            'subscriptionId' => $ghostId,
            'channels' => ['account'],
            'queries' => [Query::equal('$id', [$userId])->toString()],
        ]]);
        $this->assertSame($ghostId, $ghost['data']['subscriptions'][0]['subscriptionId']);
        $this->assertNotSame($subA, $ghostId);
        $this->assertNotSame($subB, $ghostId);

        $triggerAccountEvent();
        $event = \json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertEqualsCanonicalizing([$subA, $subB, $ghostId], $event['data']['subscriptions']);

        // Update after unsubscribe: subscribing with the removed id recreates it.
        $unsub = $this->sendUnsubscribeMessage($client, [['subscriptionId' => $subA]]);
        $this->assertTrue($unsub['data']['subscriptions'][0]['removed']);

        $triggerAccountEvent();
        $event = \json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertEqualsCanonicalizing([$subB, $ghostId], $event['data']['subscriptions']);

        $recreated = $this->sendSubscribeMessage($client, [[
            'subscriptionId' => $subA,
            'channels' => ['account'],
            'queries' => [Query::equal('$id', [$userId])->toString()],
        ]]);
        $this->assertSame($subA, $recreated['data']['subscriptions'][0]['subscriptionId']);

        $triggerAccountEvent();
        $event = \json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertEqualsCanonicalizing([$subA, $subB, $ghostId], $event['data']['subscriptions']);

        $client->close();
    }

    public function testUnsubscribeRemovesOnlyMatchingSubscription(): void
    {
        $user = $this->getUser();
        $userId = $user['$id'] ?? '';
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];
        $headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ];

        $queryString = \http_build_query(['project' => $projectId]);
        $client = new WebSocketClient(
            'ws://appwrite.test/v1/realtime?' . $queryString,
            [
                'headers' => $headers,
                'timeout' => 10,
            ]
        );

        $connected = \json_decode($client->receive(), true);
        $this->assertEquals('connected', $connected['type'] ?? null);

        // Two subscriptions on the `account` channel, both matching the current user
        $r1 = $this->sendSubscribeMessage($client, [[
            'channels' => ['account'],
            'queries' => [Query::equal('$id', [$userId])->toString()],
        ]]);
        $subA = $r1['data']['subscriptions'][0]['subscriptionId'];

        $r2 = $this->sendSubscribeMessage($client, [[
            'channels' => ['account'],
            'queries' => [Query::select(['*'])->toString()],
        ]]);
        $subB = $r2['data']['subscriptions'][0]['subscriptionId'];

        $this->assertNotSame($subA, $subB);

        // Trigger an event -- both subscriptions should match
        $name = 'Unsubscribe Test ' . \uniqid();
        $this->client->call(Client::METHOD_PATCH, '/account/name', \array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ]), ['name' => $name]);

        $event = \json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertEqualsCanonicalizing([$subA, $subB], $event['data']['subscriptions']);

        // Unsubscribe subA only
        $unsubA = $this->sendUnsubscribeMessage($client, [['subscriptionId' => $subA]]);
        $this->assertEquals('response', $unsubA['type']);
        $this->assertEquals('unsubscribe', $unsubA['data']['to']);
        $this->assertTrue($unsubA['data']['success']);
        $this->assertCount(1, $unsubA['data']['subscriptions']);
        $this->assertSame($subA, $unsubA['data']['subscriptions'][0]['subscriptionId']);
        $this->assertTrue($unsubA['data']['subscriptions'][0]['removed']);

        // Trigger another event -- only subB should match now
        $name = 'Unsubscribe Test ' . \uniqid();
        $this->client->call(Client::METHOD_PATCH, '/account/name', \array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ]), ['name' => $name]);

        $event = \json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertSame([$subB], $event['data']['subscriptions']);

        // Idempotent: unsubscribing subA again reports removed=false
        $unsubAgain = $this->sendUnsubscribeMessage($client, [['subscriptionId' => $subA]]);
        $this->assertTrue($unsubAgain['data']['success']);
        $this->assertFalse($unsubAgain['data']['subscriptions'][0]['removed']);

        // Connection is still alive -- ping still works
        $client->send(\json_encode(['type' => 'ping']));
        $pong = \json_decode($client->receive(), true);
        $this->assertEquals('pong', $pong['type']);

        // Invalid payloads are rejected
        $errNonString = $this->sendUnsubscribeMessage($client, [['subscriptionId' => 123]]);
        $this->assertEquals('error', $errNonString['type']);
        $this->assertStringContainsString('subscriptionId', (string) $errNonString['data']['message']);

        $errEmpty = $this->sendUnsubscribeMessage($client, [['subscriptionId' => '']]);
        $this->assertEquals('error', $errEmpty['type']);

        $errMissing = $this->sendUnsubscribeMessage($client, [['channels' => ['foo']]]);
        $this->assertEquals('error', $errMissing['type']);

        $errNonList = $this->sendUnsubscribeMessage($client, ['subscriptionId' => $subB]);
        $this->assertEquals('error', $errNonList['type']);

        // A batch with a valid id followed by an invalid one must be rejected atomically:
        // the valid id must remain subscribed, not be quietly removed before validation fails.
        $partial = $this->sendUnsubscribeMessage($client, [
            ['subscriptionId' => $subB],
            ['subscriptionId' => 999],
        ]);
        $this->assertEquals('error', $partial['type']);

        $name = 'Partial Rejection Test ' . \uniqid();
        $this->client->call(Client::METHOD_PATCH, '/account/name', \array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ]), ['name' => $name]);

        $event = \json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertSame([$subB], $event['data']['subscriptions']);

        // Bulk unsubscribe: remaining subB plus a never-existed id -- response mirrors input order
        $bulk = $this->sendUnsubscribeMessage($client, [
            ['subscriptionId' => $subB],
            ['subscriptionId' => 'does-not-exist'],
        ]);
        $this->assertTrue($bulk['data']['success']);
        $this->assertCount(2, $bulk['data']['subscriptions']);
        $this->assertSame($subB, $bulk['data']['subscriptions'][0]['subscriptionId']);
        $this->assertTrue($bulk['data']['subscriptions'][0]['removed']);
        $this->assertSame('does-not-exist', $bulk['data']['subscriptions'][1]['subscriptionId']);
        $this->assertFalse($bulk['data']['subscriptions'][1]['removed']);

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
        $this->assertStringContainsString('not supported in Realtime queries', (string) $response['data']['message']);
        $this->assertStringContainsString('contains', (string) $response['data']['message']);

        // Test 2: Invalid query method in nested AND query
        $response = $this->receiveSubscribeMessageResponse(['documents'], $headers, [
            Query::and([
                Query::equal('status', ['active']),
                Query::search('name', 'test'),
            ])->toString(),
        ]);
        $this->assertEquals('error', $response['type']);
        $this->assertStringContainsString('not supported in Realtime queries', (string) $response['data']['message']);
        $this->assertStringContainsString('search', (string) $response['data']['message']);

        // Test 3: Invalid query method in nested OR query
        $response = $this->receiveSubscribeMessageResponse(['documents'], $headers, [
            Query::or([
                Query::equal('status', ['active']),
                Query::between('score', 0, 100),
            ])->toString(),
        ]);
        $this->assertEquals('error', $response['type']);
        $this->assertStringContainsString('not supported in Realtime queries', (string) $response['data']['message']);
        $this->assertStringContainsString('between', (string) $response['data']['message']);

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
        $this->assertStringContainsString('not supported in Realtime queries', (string) $response['data']['message']);
        $this->assertStringContainsString('startsWith', (string) $response['data']['message']);

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
        $this->assertStringContainsString('not supported in Realtime queries', (string) $response['data']['message']);
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
            $this->addToAssertionCount(1);
        }

        $client->close();
    }

    /**
     * Create a database + collection (Role::any) with a string attribute, BEFORE the
     * tail connection opens, so the setup events don't appear in the tail.
     *
     * @return array{databaseId:string, collectionId:string}
     */
    private function setupTailCollection(): array
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        $database = $this->client->call(Client::METHOD_POST, '/databases', $headers, [
            'databaseId' => ID::unique(),
            'name' => 'Tail DB',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', $headers, [
            'collectionId' => ID::unique(),
            'name' => 'Actors',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);
        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', $headers, [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $this->assertEventually(function () use ($databaseId, $collectionId, $headers) {
            $attribute = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/name', $headers);
            $this->assertEquals(200, $attribute['headers']['status-code']);
            $this->assertEquals('available', $attribute['body']['status']);
        }, 120000, 500);

        return ['databaseId' => $databaseId, 'collectionId' => $collectionId];
    }

    /**
     * Open a console connection (root session) in message mode and subscribe to a
     * `console.tail.<projectId>` channel with optional filter queries.
     *
     * @param array<int,string> $queries
     */
    private function openConsoleTail(string $targetProjectId, array $queries = [], string $subscriptionId = 'tail-1'): WebSocketClient
    {
        $queryString = \http_build_query(['project' => 'console']);
        $client = new WebSocketClient(
            'ws://appwrite.test/v1/realtime?' . $queryString,
            [
                'headers' => [
                    'origin' => 'http://localhost',
                    'cookie' => 'a_session_console=' . $this->getRoot()['session'],
                ],
                'timeout' => 10,
            ]
        );

        $connected = \json_decode($client->receive(), true);
        $this->assertEquals('connected', $connected['type'] ?? null);

        $sub = ['channels' => ['console.tail.' . $targetProjectId], 'subscriptionId' => $subscriptionId];
        if (!empty($queries)) {
            $sub['queries'] = $queries;
        }

        $client->send(\json_encode(['type' => 'subscribe', 'data' => [$sub]]));

        $response = \json_decode($client->receive(), true);
        $this->assertEquals('response', $response['type'] ?? null);
        $this->assertEquals('subscribe', $response['data']['to'] ?? null);
        $this->assertTrue($response['data']['success'] ?? false);

        return $client;
    }

    /**
     * Receive frames until a `console.tail` event arrives, returning its payload (an
     * array of compact frames). Skips the periodic `console.tail.stats` counter frames.
     *
     * @return array<int,array<string,mixed>>
     */
    private function receiveTailFrames(WebSocketClient $client, int $maxFrames = 5): array
    {
        for ($i = 0; $i < $maxFrames; $i++) {
            $message = \json_decode($client->receive(), true);
            $this->assertEquals('event', $message['type']);
            if (($message['data']['events'][0] ?? null) === 'console.tail') {
                return $message['data']['payload'];
            }
        }
        $this->fail('No console.tail event frame received.');
    }

    public function testConsoleTailReceivesCompactMetadata(): void
    {
        ['databaseId' => $databaseId, 'collectionId' => $collectionId] = $this->setupTailCollection();

        $projectId = $this->getProject()['$id'];
        $client = $this->openConsoleTail($projectId);

        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'documentId' => ID::unique(),
            'data' => ['name' => 'Spiderman'],
        ]);
        $this->assertEquals(201, $document['headers']['status-code']);
        $documentId = $document['body']['$id'];

        $frames = $this->receiveTailFrames($client);

        $match = null;
        foreach ($frames as $frame) {
            if (($frame['resourceId'] ?? null) === $documentId) {
                $match = $frame;
                break;
            }
        }

        $this->assertNotNull($match, 'document.create not present in tail: ' . \json_encode($frames));
        $this->assertSame('databases', $match['type']);
        $this->assertSame('create', $match['action']);
        $this->assertSame($databaseId, $match['databaseId']);
        $this->assertSame($collectionId, $match['collectionId']);
        // Compact: no full document body, and no scope ids from other resource types.
        $this->assertArrayNotHasKey('name', $match);
        $this->assertArrayNotHasKey('functionId', $match);
        $this->assertArrayNotHasKey('bucketId', $match);

        $client->close();
    }

    public function testConsoleTailServerSideFilter(): void
    {
        ['databaseId' => $databaseId, 'collectionId' => $collectionId] = $this->setupTailCollection();

        $projectId = $this->getProject()['$id'];
        // Only deletes should reach this tail.
        $client = $this->openConsoleTail($projectId, [Query::equal('action', ['delete'])->toString()]);

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        // create — must be filtered out (never reaches the token bucket).
        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', $headers, [
            'documentId' => ID::unique(),
            'data' => ['name' => 'Ironman'],
        ]);
        $this->assertEquals(201, $document['headers']['status-code']);
        $documentId = $document['body']['$id'];

        // delete — must pass the filter.
        $deleted = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, $headers);
        $this->assertEquals(204, $deleted['headers']['status-code']);

        $frames = $this->receiveTailFrames($client);

        foreach ($frames as $frame) {
            $this->assertSame('delete', $frame['action'], 'filter leaked a non-delete event: ' . \json_encode($frame));
        }
        $deleteFrames = \array_filter($frames, fn ($f) => ($f['resourceId'] ?? null) === $documentId);
        $this->assertNotEmpty($deleteFrames, 'expected the delete event in the tail');

        $client->close();
    }

    public function testConsoleTailUnauthorizedProjectIsRejected(): void
    {
        $queryString = \http_build_query(['project' => 'console']);
        $client = new WebSocketClient(
            'ws://appwrite.test/v1/realtime?' . $queryString,
            [
                'headers' => [
                    'origin' => 'http://localhost',
                    'cookie' => 'a_session_console=' . $this->getRoot()['session'],
                ],
                'timeout' => 10,
            ]
        );

        $connected = \json_decode($client->receive(), true);
        $this->assertEquals('connected', $connected['type'] ?? null);

        // A project the console user's team does not own (does not exist) → policy violation.
        $client->send(\json_encode([
            'type' => 'subscribe',
            'data' => [['channels' => ['console.tail.nonexistentproject'], 'subscriptionId' => 'tail-x']],
        ]));

        // A 1008 policy violation sends the error frame and then closes the connection.
        // Depending on flush/close timing the client may read the error frame, or the
        // socket may already be dead — both are valid rejections. What must NOT happen
        // is a successful `response` subscription.
        try {
            $response = \json_decode($client->receive(), true);
            $this->assertEquals('error', $response['type'] ?? null);
            $this->assertEquals(1008, $response['data']['code'] ?? null);
        } catch (ConnectionException) {
            $this->addToAssertionCount(1);
        }

        // close() can itself throw on an already-closed socket — best effort.
        try {
            $client->close();
        } catch (ConnectionException) {
            // already closed by the server
        }
    }

    public function testConsoleTailSeesEventsNotReadableByTheConsoleUser(): void
    {
        // The tail authorizes the *viewer* by team membership at subscribe time and then
        // forwards every project event regardless of the resource's read ACL. A document
        // readable only by some OTHER user (not `any`, not the console user) must still
        // appear in the console tail.
        $projectId = $this->getProject()['$id'];
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        $database = $this->client->call(Client::METHOD_POST, '/databases', $headers, [
            'databaseId' => ID::unique(),
            'name' => 'Tail ACL DB',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // documentSecurity ON and NO read(any) at the collection level, so the only read
        // grant comes from the document's own (restricted) permission.
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', $headers, [
            'collectionId' => ID::unique(),
            'name' => 'Secrets',
            'documentSecurity' => true,
            'permissions' => [Permission::create(Role::any())],
        ]);
        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', $headers, [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);
        $this->assertEventually(function () use ($databaseId, $collectionId, $headers) {
            $attribute = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/name', $headers);
            $this->assertEquals(200, $attribute['headers']['status-code']);
            $this->assertEquals('available', $attribute['body']['status']);
        }, 120000, 500);

        $client = $this->openConsoleTail($projectId);

        // Read restricted to a user that is NOT the console user → the console viewer
        // could never read this document directly, yet the tail must surface its event.
        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', $headers, [
            'documentId' => ID::unique(),
            'data' => ['name' => 'top secret'],
            'permissions' => [Permission::read(Role::user('not-the-console-user'))],
        ]);
        $this->assertEquals(201, $document['headers']['status-code']);
        $documentId = $document['body']['$id'];

        $frames = $this->receiveTailFrames($client);

        $match = null;
        foreach ($frames as $frame) {
            if (($frame['resourceId'] ?? null) === $documentId) {
                $match = $frame;
                break;
            }
        }

        $this->assertNotNull($match, 'restricted-read document.create not present in tail: ' . \json_encode($frames));
        $this->assertSame('databases', $match['type']);
        $this->assertSame('create', $match['action']);
        $this->assertArrayNotHasKey('name', $match); // still no document body

        $client->close();
    }

    public function testProjectConnectionCannotTail(): void
    {
        // A normal project websocket (not the console project) must not be able to tail,
        // even for a team member — only the console connection may. Expect a 1008 reject.
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        $queryString = \http_build_query(['project' => $projectId]); // NOT console
        $client = new WebSocketClient(
            'ws://appwrite.test/v1/realtime?' . $queryString,
            [
                'headers' => [
                    'origin' => 'http://localhost',
                    'cookie' => 'a_session_' . $projectId . '=' . $session,
                ],
                'timeout' => 10,
            ]
        );

        $connected = \json_decode($client->receive(), true);
        $this->assertEquals('connected', $connected['type'] ?? null);

        $client->send(\json_encode([
            'type' => 'subscribe',
            'data' => [['channels' => ['console.tail.' . $projectId], 'subscriptionId' => 'tail-proj']],
        ]));

        try {
            $response = \json_decode($client->receive(), true);
            $this->assertEquals('error', $response['type'] ?? null);
            $this->assertEquals(1008, $response['data']['code'] ?? null);
        } catch (ConnectionException) {
            $this->addToAssertionCount(1);
        }

        try {
            $client->close();
        } catch (ConnectionException) {
            // already closed by the server
        }
    }
}
