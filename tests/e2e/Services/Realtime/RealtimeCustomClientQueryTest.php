<?php

namespace Tests\E2E\Services\Realtime;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Tests\E2E\Services\Functions\FunctionsBase;
use Utopia\Database\Query;

class RealtimeCustomClientQueryTest extends Scope
{
    use FunctionsBase;
    use RealtimeBase;
    use ProjectCustom;
    use SideClient;
    use RealtimeQueryBase;

    protected function supportForCheckConnectionStatus(): bool
    {
        return true;
    }
    public function testInvalidQueryShouldNotSubscribe()
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        // Test 1: Simple invalid query method (contains is not allowed)
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::contains('status', ['active'])->toString(),
        ]);

        $response = json_decode($client->receive(), true);
        $this->assertEquals('error', $response['type']);
        $this->assertStringContainsString('not supported in Realtime queries', $response['data']['message']);
        $this->assertStringContainsString('contains', $response['data']['message']);

        // Test 2: Invalid query method in nested AND query
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::and([
                Query::equal('status', ['active']),
                Query::search('name', 'test') // search is not allowed
            ])->toString(),
        ]);

        $response = json_decode($client->receive(), true);
        $this->assertEquals('error', $response['type']);
        $this->assertStringContainsString('not supported in Realtime queries', $response['data']['message']);
        $this->assertStringContainsString('search', $response['data']['message']);

        // Test 3: Invalid query method in nested OR query
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::or([
                Query::equal('status', ['active']),
                Query::between('score', 0, 100) // between is not allowed
            ])->toString(),
        ]);

        $response = json_decode($client->receive(), true);
        $this->assertEquals('error', $response['type']);
        $this->assertStringContainsString('not supported in Realtime queries', $response['data']['message']);
        $this->assertStringContainsString('between', $response['data']['message']);

        // Test 4: Deeply nested invalid query (AND -> OR -> invalid)
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::and([
                Query::equal('status', ['active']),
                Query::or([
                    Query::greaterThan('score', 50),
                    Query::startsWith('name', 'test') // startsWith is not allowed
                ])
            ])->toString(),
        ]);

        $response = json_decode($client->receive(), true);
        $this->assertEquals('error', $response['type']);
        $this->assertStringContainsString('not supported in Realtime queries', $response['data']['message']);
        $this->assertStringContainsString('startsWith', $response['data']['message']);

        // Test 5: Multiple invalid 'queries' in nested structure
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::and([
                Query::contains('tags', ['important']), // contains is not allowed
                Query::or([
                    Query::endsWith('email', '@example.com'), // endsWith is not allowed
                    Query::equal('status', ['active'])
                ])
            ])->toString(),
        ]);

        $response = json_decode($client->receive(), true);
        $this->assertEquals('error', $response['type']);
        $this->assertStringContainsString('not supported in Realtime queries', $response['data']['message']);
        // Should catch the first invalid method encountered
        $this->assertTrue(
            str_contains($response['data']['message'], 'contains') ||
            str_contains($response['data']['message'], 'endsWith')
        );
    }

    public function testProjectChannelWithHeaderOnly()
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        // Test: project ID only in header, no project query param
        // This simulates a client that only uses x-appwrite-project header
        $client = $this->getWebsocketWithCustomQuery(
            [
                'channels' => ['project']
            ],
            [
                'origin' => 'http://localhost',
                'cookie' => 'a_session_' . $projectId . '=' . $session,
                'x-appwrite-project' => $projectId,
            ]
        );

        $response = $this->assertConnectionStatusIfSupported($client);
        if ($response !== null) {
            $this->assertContains('project', $response['data']['channels']);
            // Should have default select(['*']) subscription since no project query param
            $this->assertArrayHasKey('subscriptions', $response['data']);
            $this->assertIsArray($response['data']['subscriptions']);
            $this->assertNotEmpty($response['data']['subscriptions']);
        }

        $client->close();

        // Test: project channel with queries, project ID only in header
        $queryArray = [Query::select(['*'])->toString()];
        $clientWithQuery = $this->getWebsocketWithCustomQuery(
            [
                'channels' => ['project'],
                'project' => [
                    0 => [
                        0 => $queryArray[0]
                    ]
                ]
            ],
            [
                'origin' => 'http://localhost',
                'cookie' => 'a_session_' . $projectId . '=' . $session,
                'x-appwrite-project' => $projectId,
            ]
        );

        $response = $this->assertConnectionStatusIfSupported($clientWithQuery);
        if ($response !== null) {
            $this->assertContains('project', $response['data']['channels']);
            $this->assertArrayHasKey('subscriptions', $response['data']);
            $this->assertIsArray($response['data']['subscriptions']);
            $this->assertNotEmpty($response['data']['subscriptions']);
        }

        $clientWithQuery->close();
    }
}
