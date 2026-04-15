<?php

namespace Tests\E2E\Services\Presence;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Tests\E2E\Services\Realtime\RealtimeBase;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use WebSocket\Client as WebSocketClient;

class PresenceRealtimeClientTest extends Scope
{
    use ProjectCustom;
    use RealtimeBase;
    use SideClient;

    private function connectPresenceSocket(bool $authenticated = true, int $timeout = 2): WebSocketClient
    {
        $headers = [
            'origin' => 'http://localhost',
        ];

        if ($authenticated) {
            $headers['cookie'] = 'a_session_' . $this->getProject()['$id'] . '=' . $this->getUser()['session'];
        }

        $client = $this->getWebsocket(['presences'], $headers, timeout: $timeout);
        $response = \json_decode($client->receive(), true);

        $this->assertSame('connected', $response['type'] ?? null);

        return $client;
    }

    private function getServerHeaders(): array
    {
        return [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
    }

    private function getPresencePermissions(string $userId): array
    {
        $this->assertNotEmpty($userId);

        return [
            Permission::read(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ];
    }

    public function testPresenceMessageCreatesPresenceAndPersists(): void
    {
        $presenceId = ID::unique();
        $userId = $this->getUser()['$id'];
        $client = $this->connectPresenceSocket();

        $client->send(\json_encode([
            'type' => 'presence',
            'data' => [
                'presenceId' => $presenceId,
                'status' => 'online',
                'metadata' => [
                    'device' => 'web',
                ],
                'permissions' => $this->getPresencePermissions($userId),
            ],
        ]));

        $response = \json_decode($client->receive(), true);
        $this->assertSame('response', $response['type'] ?? null);
        $this->assertSame('presence', $response['data']['to'] ?? null);
        $this->assertSame($presenceId, $response['data']['presence']['$id'] ?? null);
        $this->assertSame($userId, $response['data']['presence']['userId'] ?? null);
        $this->assertSame('online', $response['data']['presence']['status'] ?? null);
        $this->assertSame(['device' => 'web'], $response['data']['presence']['metadata'] ?? null);

        $read = $this->client->call(
            Client::METHOD_GET,
            '/presences/' . $presenceId,
            $this->getServerHeaders()
        );

        $this->assertSame(200, $read['headers']['status-code']);
        $this->assertSame($presenceId, $read['body']['$id']);
        $this->assertSame($userId, $read['body']['userId']);
        $this->assertSame('online', $read['body']['status']);
        $this->assertSame(['device' => 'web'], $read['body']['metadata']);

        $client->close();
    }

    public function testPresenceMessageUpsertWithSamePresenceIdPersistsSingleUpdatedRecord(): void
    {
        $presenceId = ID::unique();
        $userId = $this->getUser()['$id'];
        $client = $this->connectPresenceSocket();

        $client->send(\json_encode([
            'type' => 'presence',
            'data' => [
                'presenceId' => $presenceId,
                'status' => 'away',
                'metadata' => [
                    'source' => 'first',
                ],
                'permissions' => $this->getPresencePermissions($userId),
            ],
        ]));
        $first = \json_decode($client->receive(), true);
        $this->assertSame('response', $first['type'] ?? null);
        $this->assertSame($presenceId, $first['data']['presence']['$id'] ?? null);
        $this->assertSame('away', $first['data']['presence']['status'] ?? null);

        $client->send(\json_encode([
            'type' => 'presence',
            'data' => [
                'presenceId' => $presenceId,
                'status' => 'busy',
                'metadata' => [
                    'source' => 'second',
                ],
                'permissions' => $this->getPresencePermissions($userId),
            ],
        ]));
        $second = \json_decode($client->receive(), true);
        $this->assertSame('response', $second['type'] ?? null);
        $this->assertSame($presenceId, $second['data']['presence']['$id'] ?? null);
        $this->assertSame('busy', $second['data']['presence']['status'] ?? null);
        $this->assertSame(['source' => 'second'], $second['data']['presence']['metadata'] ?? null);

        $list = $this->client->call(
            Client::METHOD_GET,
            '/presences',
            $this->getServerHeaders(),
            [
                'queries' => [
                    Query::equal('$id', [$presenceId])->toString(),
                    Query::equal('userId', [$userId])->toString(),
                ],
            ]
        );

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertSame(1, $list['body']['total']);
        $this->assertCount(1, $list['body']['presences']);
        $this->assertSame($presenceId, $list['body']['presences'][0]['$id']);
        $this->assertSame($userId, $list['body']['presences'][0]['userId']);
        $this->assertSame('busy', $list['body']['presences'][0]['status']);
        $this->assertSame(['source' => 'second'], $list['body']['presences'][0]['metadata']);

        $client->close();
    }

    public function testPresenceMessageValidationErrors(): void
    {
        $client = $this->connectPresenceSocket();

        $client->send(\json_encode([
            'type' => 'presence',
            'data' => [
                'metadata' => [
                    'device' => 'web',
                ],
            ],
        ]));
        $missingStatus = \json_decode($client->receive(), true);
        $this->assertSame('error', $missingStatus['type'] ?? null);
        $this->assertStringContainsString('status must be provided', $missingStatus['data']['message'] ?? '');

        $client->send(\json_encode([
            'type' => 'presence',
            'data' => [
                'status' => 'online',
                'permissions' => 'invalid',
            ],
        ]));
        $invalidPermissions = \json_decode($client->receive(), true);
        $this->assertSame('error', $invalidPermissions['type'] ?? null);
        $this->assertStringContainsString('permissions must be an array', $invalidPermissions['data']['message'] ?? '');

        $client->close();
    }

    public function testPresenceMessageRequiresAuthenticatedUser(): void
    {
        $client = $this->connectPresenceSocket(false);

        $client->send(\json_encode([
            'type' => 'presence',
            'data' => [
                'status' => 'online',
            ],
        ]));

        $response = \json_decode($client->receive(), true);
        $this->assertSame('error', $response['type'] ?? null);
        $this->assertSame(401, $response['data']['code'] ?? null);
        $this->assertSame('User must be authorized', $response['data']['message'] ?? null);

        $client->close();
    }
}
