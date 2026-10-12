<?php

declare(strict_types=1);

namespace Tests\E2E\General;

use Appwrite\Extend\Exception;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

final class KeyOriginTest extends Scope
{
    use ProjectCustom;
    use SideClient;

    public function testOriginAndKeyAuth(): void
    {
        $projectId = $this->getProject()['$id'];
        $apiKey = $this->getProject()['apiKey'];
        $session = $this->getUser()['session'];
        $userId = $this->getUser()['$id'];

        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Key Origin Database',
        ]);
        $this->assertSame(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ], [
            'collectionId' => ID::unique(),
            'name' => 'Key Origin Collection',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($userId)),
            ],
        ]);
        $this->assertSame(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        $attribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ], [
            'key' => 'title',
            'size' => 128,
            'required' => true,
        ]);
        $this->assertSame(202, $attribute['headers']['status-code']);

        $this->assertEventually(function () use ($projectId, $apiKey, $databaseId, $collectionId) {
            $ready = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/title', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $apiKey,
            ]);
            $this->assertSame(200, $ready['headers']['status-code']);
            $this->assertSame('available', $ready['body']['status']);
        });

        $documentsUrl = '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents';
        $sessionCookie = 'a_session_' . $projectId . '=' . $session;

        /**
         * Test for SUCCESS
         */
        $allowed = $this->client->call(Client::METHOD_POST, $documentsUrl, [
            'content-type' => 'application/json',
            'origin' => 'http://localhost',
            'cookie' => $sessionCookie,
            'x-appwrite-project' => $projectId,
        ], [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'session-allowed-origin',
            ],
        ]);
        $this->assertSame(201, $allowed['headers']['status-code']);
        $allowedId = $allowed['body']['$id'];

        $server = $this->client->call(Client::METHOD_POST, $documentsUrl, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ], [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'server-api-key',
            ],
        ]);
        $this->assertSame(201, $server['headers']['status-code']);
        $serverId = $server['body']['$id'];

        /**
         * Test for FAILURE
         */
        $disallowed = $this->client->call(Client::METHOD_POST, $documentsUrl, [
            'content-type' => 'application/json',
            'origin' => 'https://evil.example.com',
            'cookie' => $sessionCookie,
            'x-appwrite-project' => $projectId,
        ], [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'session-disallowed-origin',
            ],
        ]);
        $this->assertSame(403, $disallowed['headers']['status-code']);
        $this->assertSame(Exception::GENERAL_UNKNOWN_ORIGIN, $disallowed['body']['type']);

        $junkKeys = [
            'ephemeral_x',
            'dynamic_totally_invalid',
            'not-a-real-key',
        ];

        foreach ($junkKeys as $junkKey) {
            $junkDocumentId = ID::unique();
            $junk = $this->client->call(Client::METHOD_POST, $documentsUrl, [
                'content-type' => 'application/json',
                'origin' => 'https://evil.example.com',
                'cookie' => $sessionCookie,
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $junkKey,
            ], [
                'documentId' => $junkDocumentId,
                'data' => [
                    'title' => 'session-junk-key-' . $junkKey,
                ],
            ]);
            $this->assertSame(403, $junk['headers']['status-code']);
            $this->assertSame(Exception::GENERAL_UNKNOWN_ORIGIN, $junk['body']['type']);

            $created = $this->client->call(Client::METHOD_GET, $documentsUrl . '/' . $junkDocumentId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $apiKey,
            ]);
            $this->assertSame(404, $created['headers']['status-code']);
        }

        $junkWithoutOrigin = $this->client->call(Client::METHOD_POST, $documentsUrl, [
            'content-type' => 'application/json',
            'cookie' => $sessionCookie,
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => 'not-a-real-key',
        ], [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'session-junk-key-no-origin',
            ],
        ]);
        $this->assertSame(401, $junkWithoutOrigin['headers']['status-code']);
        $this->assertSame(Exception::USER_UNAUTHORIZED, $junkWithoutOrigin['body']['type']);

        $list = $this->client->call(Client::METHOD_GET, $documentsUrl, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ]);
        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertSame(2, $list['body']['total']);

        $ids = \array_column($list['body']['documents'], '$id');
        $this->assertContains($allowedId, $ids);
        $this->assertContains($serverId, $ids);
    }
}
