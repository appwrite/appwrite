<?php

namespace Tests\E2E\Services\Realtime;

use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideClient;

use WebSocket\Client as WebSocketClient;
use WebSocket\ConnectionException;

class RealtimeCustomClientTest extends Scope
{
    use RealtimeBase;
    use ProjectCustom;
    use SideClient;

    private function getWebsocket($channels = [], $headers = [])
    {
        $headers = array_merge(
            ['Origin' => 'http://appwrite.test'],
            $headers
        );
        $query = [
            'project' => $this->getProject()['$id'],
            'channels' => $channels
        ];
        return new WebSocketClient('ws://appwrite-traefik/v1/realtime?' . http_build_query($query), [
            'headers' => $headers,
            'timeout' => 5,
        ]);
    }

    public function testConnection()
    {
        /**
         * Test for SUCCESS
         */
        $client = $this->getWebsocket(['documents']);
        $this->assertEquals('{"documents":0}', $client->receive());
        $client->close();

        $client = $this->getWebsocket(['documents'], ['Origin' => 'http://appwrite.unknown']);
        $this->assertEquals('Invalid Origin. Register your new client (appwrite.unknown) as a new Web platform on your project console dashboard', $client->receive());
        $this->expectException(ConnectionException::class); // Check if server disconnnected client
        $client->close();

        $client = $this->getWebsocket();
        $this->assertEquals('Missing channels', $client->receive());
        $this->expectException(ConnectionException::class); // Check if server disconnnected client
        $client->close();

        $client = new WebSocketClient('ws://appwrite-traefik/v1/realtime', [
            'headers' => [
                'Origin' => 'appwrite.test'
            ]
        ]);
        $this->assertEquals('Missing or unknown project ID', $client->receive());
        $this->expectException(ConnectionException::class); // Check if server disconnnected client
        $client->close();
    }
}