<?php

namespace Tests\E2E\Services\Realtime;

use Tests\E2E\Client;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use WebSocket\Client as WebSocketClient;

class PresenceIterativeTest extends PresenceBase
{
    protected function getNumberOfUsersToCreate(): int
    {
        return 100;
    }

    protected function getListPresenceURL(): string
    {
        return '/iterative/presence';
    }

    protected function getSeedPresenceDocumentsCount(): int
    {
        return 0;
    }

    private function extractPresenceRows(array $body): array
    {
        foreach (['presences', 'presence', 'documents', 'rows'] as $key) {
            $value = $body[$key] ?? null;
            if (\is_array($value)) {
                return $value;
            }
        }

        return [];
    }

    private function getPresenceCookieHeaders(string $projectId, array $user): array
    {
        return [
            'content-type' => 'application/json',
            'origin' => 'http://localhost',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $user['session'],
        ];
    }

    public function testPresenceApiCreateGetAndList(): void
    {
        $projectId = $this->getProject()['$id'];

        $owner = $this->getUser(true);
        $viewer = $this->getUser(true);
        $other = $this->getUser(true);

        $status = 'api-presence-test-' . \uniqid();
        $permissions = [Permission::read(Role::user($viewer['$id']))];

        // Create via HTTP
        $createHeaders = $this->getPresenceCookieHeaders($projectId, $owner);
        $payload = [
            'status' => $status,
            'permissions' => $permissions,
        ];

        $createResponse = $this->client->call(
            Client::METHOD_POST,
            '/iterative/presence',
            $createHeaders,
            $payload
        );

        $this->assertEquals(201, $createResponse['headers']['status-code'], 'Create presence failed.');

        $presenceId = $createResponse['body']['$id'] ?? null;
        $this->assertIsString($presenceId, 'Missing $id in create response body.');
        $this->assertEquals($status, $createResponse['body']['status'] ?? null);

        // GET presence as the same viewer
        $getResponse = $this->client->call(
            Client::METHOD_GET,
            '/presence:/' . $presenceId,
            $this->getPresenceCookieHeaders($projectId, $viewer)
        );

        $this->assertEquals(200, $getResponse['headers']['status-code']);
        $this->assertEquals($status, $getResponse['body']['status'] ?? null);

        // GET presence as an unrelated user should not succeed
        $otherGetResponse = $this->client->call(
            Client::METHOD_GET,
            '/presence:/' . $presenceId,
            $this->getPresenceCookieHeaders($projectId, $other)
        );

        $this->assertNotEquals(200, $otherGetResponse['headers']['status-code']);

        // List presence as viewer should include owner
        $listResponse = $this->client->call(
            Client::METHOD_GET,
            $this->getListPresenceURL(),
            $this->getPresenceCookieHeaders($projectId, $viewer)
        );
        $this->assertEquals(200, $listResponse['headers']['status-code']);

        $rows = $this->extractPresenceRows($listResponse['body']);
        $found = false;
        foreach ($rows as $row) {
            if (($row['userId'] ?? null) === $owner['$id']) {
                $found = true;
                $this->assertEquals($status, $row['status'] ?? null);
            }
        }

        $this->assertTrue($found, 'Viewer should see owner presence in list.');

        // List presence as other should not include owner
        $otherListResponse = $this->client->call(
            Client::METHOD_GET,
            $this->getListPresenceURL(),
            $this->getPresenceCookieHeaders($projectId, $other)
        );
        $this->assertEquals(200, $otherListResponse['headers']['status-code']);

        $otherRows = $this->extractPresenceRows($otherListResponse['body']);
        $otherFound = false;
        foreach ($otherRows as $row) {
            if (($row['userId'] ?? null) === $owner['$id']) {
                $otherFound = true;
                break;
            }
        }

        $this->assertFalse($otherFound, 'Other user should not see owner presence in list.');
    }

    public function testPresenceRealtimeCreateGetAndList(): void
    {
        $projectId = $this->getProject()['$id'];

        $owner = $this->getUser(true);
        $viewer = $this->getUser(true);
        $other = $this->getUser(true);

        $status = 'realtime-presence-test-' . \uniqid();
        $permissions = [Permission::read(Role::user($viewer['$id']))];

        $channels = ['account'];
        $queryString = http_build_query(['project' => $projectId, 'channels' => $channels]);

        $wsClient = new WebSocketClient(
            'ws://appwrite.test/v1/realtime?' . $queryString,
            [
                'headers' => [
                    'origin' => 'http://localhost',
                    'cookie' => 'a_session_' . $projectId . '=' . $owner['session'],
                ],
                'timeout' => 2,
            ]
        );

        // connected payload
        $connectedPayloadRaw = $wsClient->receive();
        $connectedPayload = \json_decode((string) $connectedPayloadRaw, true);
        $this->assertIsArray($connectedPayload, 'Expected websocket connected payload JSON.');
        $this->assertEquals('connected', $connectedPayload['type'] ?? null);

        // Send presence
        $wsClient->send(\json_encode([
            'type' => 'presence',
            'data' => [
                'session' => $owner['session'],
                'status' => $status,
                'permissions' => $permissions,
            ],
        ]));

        // presence ack payload
        $presenceAckRaw = $wsClient->receive();
        $presenceAck = \json_decode((string) $presenceAckRaw, true);
        $this->assertIsArray($presenceAck, 'Expected websocket presence ack JSON.');
        $this->assertEquals('presence', $presenceAck['type'] ?? null);

        $wsClient->close();

        // Allow realtime write to settle
        \usleep(100000);

        // List presence as viewer should include owner
        $listResponse = $this->client->call(
            Client::METHOD_GET,
            $this->getListPresenceURL(),
            $this->getPresenceCookieHeaders($projectId, $viewer)
        );
        $this->assertEquals(200, $listResponse['headers']['status-code']);

        $rows = $this->extractPresenceRows($listResponse['body']);
        $ownerRow = null;
        foreach ($rows as $row) {
            if (($row['userId'] ?? null) === $owner['$id']) {
                $ownerRow = $row;
                break;
            }
        }

        $this->assertIsArray($ownerRow, 'Expected viewer to see owner presence in list.');
        $this->assertEquals($status, $ownerRow['status'] ?? null);

        // GET presence as the same viewer
        $presenceId = $ownerRow['$id'] ?? $ownerRow['id'] ?? null;
        $this->assertIsString($presenceId, 'Missing presence id in list row.');

        $getResponse = $this->client->call(
            Client::METHOD_GET,
            '/presence:/' . $presenceId,
            $this->getPresenceCookieHeaders($projectId, $viewer)
        );

        $this->assertEquals(200, $getResponse['headers']['status-code']);
        $this->assertEquals($status, $getResponse['body']['status'] ?? null);

        // GET presence as an unrelated user should not succeed
        $otherGetResponse = $this->client->call(
            Client::METHOD_GET,
            '/presence:/' . $presenceId,
            $this->getPresenceCookieHeaders($projectId, $other)
        );

        $this->assertNotEquals(200, $otherGetResponse['headers']['status-code']);
    }
}
