<?php

namespace Tests\E2E\Services\Realtime;

use WebSocket\ConnectionException;
use WebSocket\Client as WebSocketClient;

trait RealtimeBase
{
    private function getWebsocket($channels = [], $headers = [], $projectId = null): WebSocketClient
    {
        if (is_null($projectId)) {
            $projectId = $this->getProject()['$id'];
        }

        $headers = array_merge([
            'Origin' => 'appwrite.test'
        ], $headers);

        $query = [
            'project' => $projectId,
            'channels' => $channels
        ];

        return new WebSocketClient('ws://appwrite-traefik/v1/realtime?' . http_build_query($query), [
            'headers' => $headers,
            'timeout' => 30,
        ]);
    }

    public function testConnection(): void
    {
        /**
         * Test for SUCCESS
         */
        $client = $this->getWebsocket(['documents']);
        $this->assertNotEmpty($client->receive());
        $client->close();
    }

    public function testConnectionFailureMissingChannels(): void
    {
        $client = $this->getWebsocket();
        $payload = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $payload);
        $this->assertArrayHasKey('data', $payload);
        $this->assertEquals('error', $payload['type']);
        $this->assertEquals(1008, $payload['data']['code']);
        $this->assertEquals('Missing channels', $payload['data']['message']);
        \usleep(250000); // 250ms
        $this->expectException(ConnectionException::class); // Check if server disconnnected client
        $client->close();
    }

    public function testConnectionFailureUnknownProject(): void
    {
        $client = new WebSocketClient('ws://appwrite-traefik/v1/realtime?project=123', [
            'headers' => [
                'Origin' => 'appwrite.test'
            ]
        ]);
        $payload = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $payload);
        $this->assertArrayHasKey('data', $payload);
        $this->assertEquals('error', $payload['type']);
        $this->assertEquals(1008, $payload['data']['code']);
        $this->assertEquals('Missing or unknown project ID', $payload['data']['message']);
        \usleep(250000); // 250ms
        $this->expectException(ConnectionException::class); // Check if server disconnnected client
        $client->close();
    }
}
