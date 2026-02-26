<?php

namespace Tests\E2E\Services\Realtime;

use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;
use Utopia\System\System;
use WebSocket\Client as WebSocketClient;
use WebSocket\ConnectionException;

trait RealtimeBase
{
    private function getWebsocket(
        array $channels = [],
        array $headers = [],
        ?string $projectId = null,
        ?array $queries = null
    ): WebSocketClient {
        if (is_null($projectId)) {
            $projectId = $this->getProject()['$id'];
        }

        $query = [
            "project" => $projectId,
            "channels" => $channels
        ];

        /**
         * Query param encoding rules:
         * - $queries === null  -> only send channels (no per-channel query params) for backward compatibility.
         * - $queries === []    -> explicit "select all" subscription: send Query::select(['*']) as a single group.
         * - non-empty $queries -> treat as a single subscription group for the first channel:
         *                        AND logic within the group; OR logic across multiple groups (if we ever add them).
         *
         * For now all E2E tests subscribe to a single channel, so we map queries to $channels[0].
         *
         * Slot-based format: channel[slot][]=query1&channel[slot][]=query2
         * We need to manually build the query string to ensure the [] format is used.
         */

        // Build base query string
        $queryParams = [
            "project" => $projectId,
            "channels" => $channels
        ];
        $queryString = http_build_query($queryParams);

        if ($queries !== null && !empty($channels)) {
            $channel = $channels[0];
            $slot = 0; // All tests use slot 0 for now

            if ($queries === []) {
                // Explicit select("*") group - single query in slot 0
                $queryValue = \Utopia\Database\Query::select(['*'])->toString();
                $queryString .= "&" . urlencode($channel) . "[" . $slot . "][]=" . urlencode($queryValue);
            } else {
                // Single subscription group for this channel - multiple queries in slot 0
                // Each query should be appended with [] format
                foreach ($queries as $queryValue) {
                    $queryString .= "&" . urlencode($channel) . "[" . $slot . "][]=" . urlencode($queryValue);
                }
            }
        }

        return new WebSocketClient(
            "ws://appwrite.test/v1/realtime?" . $queryString,
            [
                "headers" => $headers,
                "timeout" => 45,
            ]
        );
    }

    /**
     * Build WebSocket client with custom query parameters.
     * Useful for testing edge cases like project in header only, or project as Query array.
     *
     * @param array $queryParams Custom query parameters (e.g., ['channels' => ['project'], 'project' => [...]])
     * @param array $headers HTTP headers
     * @return WebSocketClient
     */
    private function getWebsocketWithCustomQuery(array $queryParams, array $headers = []): WebSocketClient
    {
        $queryString = http_build_query($queryParams);

        return new WebSocketClient(
            "ws://appwrite.test/v1/realtime?" . $queryString,
            [
                "headers" => $headers,
                "timeout" => 45,
            ]
        );
    }

    public function testConnection(): void
    {
        /**
         * Test for SUCCESS
         */
        $client = $this->getWebsocket(["rows"]);
        $this->assertNotEmpty($client->receive());
        $client->close();
    }

    public function testConnectionFailureMissingChannels(): void
    {
        $client = $this->getWebsocket([]);
        $payload = json_decode($client->receive(), true);

        $this->assertArrayHasKey("type", $payload);
        $this->assertArrayHasKey("data", $payload);
        $this->assertEquals("error", $payload["type"]);
        $this->assertEquals(1008, $payload["data"]["code"]);
        $this->assertEquals("Missing channels", $payload["data"]["message"]);
        \usleep(250000); // 250ms
        $this->expectException(ConnectionException::class); // Check if server disconnected client
        $client->close();
    }

    public function testConnectionFailureUnknownProject(): void
    {
        $client = $this->getWebsocket(projectId: '123');
        $payload = json_decode($client->receive(), true);

        $this->assertArrayHasKey("type", $payload);
        $this->assertArrayHasKey("data", $payload);
        $this->assertEquals("error", $payload["type"]);
        $this->assertEquals(1008, $payload["data"]["code"]);
        $this->assertEquals(
            "Missing or unknown project ID",
            $payload["data"]["message"]
        );
        \usleep(250000); // 250ms
        $this->expectException(ConnectionException::class); // Check if server disconnected client
        $client->close();
    }

    public function testConnectionRegionCheck(): void
    {
        /**
         * Test for SUCCESS
         * A project whose region matches the server region should connect successfully.
         */
        $client = $this->getWebsocket(['documents']);
        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('channels', $response['data']);
        $this->assertContains('documents', $response['data']['channels']);

        $client->close();
    }

    public function testConnectionFailureRegionMismatch(): void
    {
        $serverRegion = System::getEnv('_APP_REGION', 'default');

        if ($serverRegion === 'fra') {
            $this->markTestSkipped('Test requires server region to not be "fra"');
        }

        // Create a team for the mismatched-region project
        $team = $this->client->call(Client::METHOD_POST, '/teams', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
            'teamId' => ID::unique(),
            'name' => 'Region Mismatch Test Team',
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);
        $teamId = $team['body']['$id'];

        // Create a project in the 'fra' region (which won't match the server's region)
        $project = $this->client->call(Client::METHOD_POST, '/projects', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
            'projectId' => ID::unique(),
            'region' => 'fra',
            'name' => 'Region Mismatch Project',
            'teamId' => $teamId,
        ]);

        $this->assertEquals(201, $project['headers']['status-code']);
        $projectId = $project['body']['$id'];

        /**
         * Test for FAILURE
         * A project in a different region should be rejected with an access forbidden error.
         */
        $client = $this->getWebsocket(['documents'], [], $projectId);
        $payload = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $payload);
        $this->assertArrayHasKey('data', $payload);
        $this->assertEquals('error', $payload['type']);
        $this->assertEquals(401, $payload['data']['code']);
        $this->assertEquals(
            'Project is not accessible in this region. Please make sure you are using the correct endpoint',
            $payload['data']['message']
        );
        \usleep(250000); // 250ms
        $this->expectException(ConnectionException::class); // Check if server disconnected client
        $client->close();
    }
}
