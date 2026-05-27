<?php

namespace Tests\E2E\Services\Presences;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

class PresenceConsoleClientTest extends Scope
{
    use PresenceBase;
    use ProjectCustom {
        getProject as getCustomProject;
    }
    use SideConsole {
        getHeaders as getAdminHeaders;
    }

    public function getProject(bool $fresh = false): array
    {
        return ['$id' => 'console'];
    }

    // `x-appwrite-mode: admin` is forbidden for the console project, so authenticate
    // as a console session user instead — `getUser()` signs them up against project=console.
    public function getHeaders(bool $devKey = true): array
    {
        return [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getUser()['session'],
        ];
    }

    public function testGetPresenceUsage(): void
    {
        // Usage requires admin scope, which the console project rejects — run against a regular project.
        $projectId = $this->getCustomProject()['$id'];

        $response = $this->client->call(Client::METHOD_GET, '/presences/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getAdminHeaders()), [
            'range' => '32h',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/presences/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getAdminHeaders()), [
            'range' => '24h',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('24h', $response['body']['range']);
        $this->assertCount(3, $response['body']);
        $this->assertIsNumeric($response['body']['usersOnlineTotal']);
        $this->assertIsArray($response['body']['presences']);
    }
}
