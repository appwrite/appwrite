<?php

namespace Tests\E2E\Services\Presences;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

class PresenceConsoleClientTest extends Scope
{
    use PresenceBase;
    use ProjectCustom;
    use SideConsole;

    public function testGetPresenceUsage(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/presences/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'range' => '32h',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/presences/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'range' => '24h',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('24h', $response['body']['range']);
        $this->assertCount(3, $response['body']);
        $this->assertIsNumeric($response['body']['usersOnlineTotal']);
        $this->assertIsArray($response['body']['presences']);
    }
}
