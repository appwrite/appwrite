<?php

namespace Tests\E2E\Services\Realtime;

use WebSocket\Client as WebSocketClient;
use WebSocket\ConnectionException;

trait RealtimeBase
{
    private function getWebsocket(
        array $channels = [],
        array $headers = [],
        string $projectId = null
    ): WebSocketClient {
        if (is_null($projectId)) {
            $projectId = $this->getProject()['$id'];
        }

        $query = [
            "project" => $projectId,
            "channels" => $channels,
        ];

        return new WebSocketClient(
            "ws://appwrite.test/v1/realtime?" . http_build_query($query),
            [
                "headers" => $headers,
                "timeout" => 30,
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
}
