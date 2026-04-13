<?php

namespace Tests\E2E\Services\Presence;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Console;
use Utopia\Database\Helpers\ID;

class PresenceExpiryTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testExpiredPresenceDeletedByMaintenance(): void
    {
        $projectId = $this->getProject()['$id'];
        $userId = $this->getUser()['$id'];
        $expiredAt = \gmdate('Y-m-d\TH:i:s.v\Z', \time() - 120);

        $createServer = $this->client->call(
            Client::METHOD_PUT,
            '/presences/' . ID::unique(),
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ],
            [
                'userId' => $userId,
                'status' => 'online',
                'metadata' => ['test' => 'presence-expiry'],
            ]
        );

        $this->assertEquals(200, $createServer['headers']['status-code']);
        $presenceIdServer = $createServer['body']['$id'];

        $expireServer = $this->client->call(
            Client::METHOD_PATCH,
            '/presences/' . $presenceIdServer,
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ],
            [
                'userId' => $userId,
                'expiry' => $expiredAt,
            ]
        );

        $this->assertEquals(200, $expireServer['headers']['status-code']);
        $this->assertEquals($expiredAt, $expireServer['body']['expiry']);

        $createClient = $this->client->call(
            Client::METHOD_PUT,
            '/presences/' . ID::unique(),
            [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'cookie' => 'a_session_' . $projectId . '=' . $this->getUser()['session'],
            ],
            [
                'status' => 'online',
                'metadata' => ['test' => 'presence-expiry-client'],
            ]
        );

        $this->assertEquals(200, $createClient['headers']['status-code']);
        $presenceIdClient = $createClient['body']['$id'];

        $expireClient = $this->client->call(
            Client::METHOD_PATCH,
            '/presences/' . $presenceIdClient,
            [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'cookie' => 'a_session_' . $projectId . '=' . $this->getUser()['session'],
            ],
            [
                'expiry' => $expiredAt,
            ]
        );

        $this->assertEquals(200, $expireClient['headers']['status-code']);
        $this->assertEquals($expiredAt, $expireClient['body']['expiry']);

        $stdout = '';
        $stderr = '';
        $code = Console::execute('docker exec appwrite maintenance --type=trigger', '', $stdout, $stderr);
        $this->assertSame(0, $code, "Maintenance command failed with code $code: $stderr ($stdout)");

        $this->assertEventually(function () use ($presenceIdServer, $presenceIdClient, $projectId) {
            $getServer = $this->client->call(
                Client::METHOD_GET,
                '/presences/' . $presenceIdServer,
                [
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $projectId,
                    'x-appwrite-key' => $this->getProject()['apiKey'],
                ]
            );

            $getClient = $this->client->call(
                Client::METHOD_GET,
                '/presences/' . $presenceIdClient,
                [
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $projectId,
                    'x-appwrite-key' => $this->getProject()['apiKey'],
                ]
            );

            $this->assertEquals(404, $getServer['headers']['status-code']);
            $this->assertEquals(404, $getClient['headers']['status-code']);
        });
    }
}
