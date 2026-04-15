<?php

namespace Tests\E2E\Services\Presence;

use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;

trait PresenceBase
{
    private static array $presenceCache = [];
    private static array $presenceApiKeyCache = [];

    protected function getPresenceApiKey(): string
    {
        $projectId = $this->getProject()['$id'];

        if (!empty(self::$presenceApiKeyCache[$projectId])) {
            return self::$presenceApiKeyCache[$projectId];
        }

        self::$presenceApiKeyCache[$projectId] = $this->getNewKey([
            'users.read',
            'users.write',
        ]);

        return self::$presenceApiKeyCache[$projectId];
    }

    protected function setupPresence(array $overrides = []): array
    {
        $projectId = $this->getProject()['$id'];
        $cacheKey = $projectId;

        if (empty($overrides) && !empty(self::$presenceCache[$cacheKey])) {
            return self::$presenceCache[$cacheKey];
        }

        $payload = \array_merge([
            'userId' => $this->getUser()['$id'],
            'status' => 'online',
            'metadata' => [
                'device' => 'web',
                'setup' => true,
            ],
        ], $overrides);

        $response = $this->client->call(
            Client::METHOD_PUT,
            '/presences/' . ID::unique(),
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getPresenceApiKey(),
            ],
            $payload
        );
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertArrayHasKey('userId', $response['body']);
        $this->assertArrayHasKey('status', $response['body']);
        $this->assertArrayHasKey('metadata', $response['body']);

        $this->assertEquals($payload['userId'], $response['body']['userId']);

        if (empty($overrides)) {
            self::$presenceCache[$cacheKey] = $response['body'];
        }

        return $response['body'];
    }

    public function testUpsertAndGetPresence(): void
    {
        if ($this->getSide() === 'client') {
            $upsert = $this->client->call(
                Client::METHOD_PUT,
                '/presences/' . ID::unique(),
                \array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders(false)),
                [
                    'status' => 'online',
                    'metadata' => ['device' => 'web'],
                ]
            );

            $this->assertEquals(401, $upsert['headers']['status-code']);
            return;
        }

        $presence = $this->setupPresence();

        $get = $this->client->call(
            Client::METHOD_GET,
            '/presences/' . $presence['$id'],
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders(false))
        );

        $this->assertEquals(200, $get['headers']['status-code']);
        $this->assertEquals($presence['$id'], $get['body']['$id']);
        $this->assertEquals($presence['userId'], $get['body']['userId']);
        $this->assertArrayHasKey('expiry', $get['body']);
    }

    public function testListPresences(): void
    {
        if ($this->getSide() === 'client') {
            $list = $this->client->call(
                Client::METHOD_GET,
                '/presences',
                \array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders(false))
            );

            $this->assertEquals(401, $list['headers']['status-code']);
            return;
        }

        $presence = $this->setupPresence();

        $list = $this->client->call(
            Client::METHOD_GET,
            '/presences',
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders(false)),
            [
                'queries' => [
                    Query::equal('userId', [$presence['userId']])->toString(),
                ],
            ]
        );

        $this->assertEquals(200, $list['headers']['status-code']);
        $this->assertArrayHasKey('total', $list['body']);
        $this->assertArrayHasKey('presences', $list['body']);
        $this->assertIsArray($list['body']['presences']);
        $this->assertGreaterThanOrEqual(1, $list['body']['total']);
    }

    public function testUpdatePresenceSparseFields(): void
    {
        if ($this->getSide() === 'client') {
            $update = $this->client->call(
                Client::METHOD_PATCH,
                '/presences/' . ID::unique(),
                \array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders(false)),
                [
                    'status' => 'busy',
                    'metadata' => ['source' => 'update'],
                ]
            );

            $this->assertEquals(401, $update['headers']['status-code']);
            return;
        }

        $presence = $this->setupPresence([
            'status' => 'away',
            'metadata' => ['source' => 'setup'],
        ]);

        $payload = [
            'status' => 'busy',
            'metadata' => ['source' => 'update'],
        ];

        if ($this->getSide() === 'server') {
            $payload['userId'] = $presence['userId'];
        }

        $update = $this->client->call(
            Client::METHOD_PATCH,
            '/presences/' . $presence['$id'],
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders(false)),
            $payload
        );

        $this->assertEquals(200, $update['headers']['status-code']);
        $this->assertEquals('busy', $update['body']['status']);
        $this->assertEquals(['source' => 'update'], $update['body']['metadata']);
    }

    public function testDeletePresence(): void
    {
        if ($this->getSide() === 'client') {
            $delete = $this->client->call(
                Client::METHOD_DELETE,
                '/presences/' . ID::unique(),
                \array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders(false))
            );

            $this->assertEquals(401, $delete['headers']['status-code']);
            return;
        }

        $presence = $this->setupPresence([
            'status' => 'temp-delete',
            'metadata' => ['cleanup' => true],
        ]);

        $delete = $this->client->call(
            Client::METHOD_DELETE,
            '/presences/' . $presence['$id'],
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders(false))
        );

        $this->assertEquals(204, $delete['headers']['status-code']);
    }

    public function testUpdateNotFound(): void
    {
        if ($this->getSide() === 'client') {
            $response = $this->client->call(
                Client::METHOD_PATCH,
                '/presences/' . ID::unique(),
                \array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders(false)),
                [
                    'status' => 'ghost',
                ]
            );

            $this->assertEquals(401, $response['headers']['status-code']);
            return;
        }

        $payload = [
            'status' => 'ghost',
        ];

        if ($this->getSide() === 'server') {
            $payload['userId'] = $this->getUser()['$id'];
        }

        $response = $this->client->call(
            Client::METHOD_PATCH,
            '/presences/' . ID::unique(),
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders(false)),
            $payload
        );

        $this->assertEquals(404, $response['headers']['status-code']);
    }

    public function testClientCannotPassUserId(): void
    {
        if ($this->getSide() === 'server') {
            $this->expectNotToPerformAssertions();
            return;
        }

        $response = $this->client->call(
            Client::METHOD_PUT,
            '/presences/' . ID::unique(),
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders(false)),
            [
                'userId' => ID::unique(),
                'status' => 'online',
            ]
        );

        $this->assertEquals(401, $response['headers']['status-code']);
    }

    public function testServerRequiresUserId(): void
    {
        if ($this->getSide() === 'client') {
            $this->expectNotToPerformAssertions();
            return;
        }

        $response = $this->client->call(
            Client::METHOD_PUT,
            '/presences/' . ID::unique(),
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders(false)),
            [
                'status' => 'online',
            ]
        );

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testUpsertSameUserMaintainsSinglePresence(): void
    {
        if ($this->getSide() === 'client') {
            $this->expectNotToPerformAssertions();
            return;
        }

        $projectId = $this->getProject()['$id'];
        $userId = $this->getUser()['$id'];
        $headers = \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders(false));

        $firstUpsert = $this->client->call(
            Client::METHOD_PUT,
            '/presences/' . ID::unique(),
            $headers,
            [
                'userId' => $userId,
                'status' => 'online',
                'metadata' => ['source' => 'first-upsert'],
            ]
        );
        $this->assertEquals(200, $firstUpsert['headers']['status-code']);

        $secondUpsert = $this->client->call(
            Client::METHOD_PUT,
            '/presences/' . ID::unique(),
            $headers,
            [
                'userId' => $userId,
                'status' => 'away',
                'metadata' => ['source' => 'second-upsert'],
            ]
        );
        $this->assertEquals(200, $secondUpsert['headers']['status-code']);

        $this->assertEquals('away', $secondUpsert['body']['status']);
        $this->assertEquals(['source' => 'second-upsert'], $secondUpsert['body']['metadata']);

        $list = $this->client->call(
            Client::METHOD_GET,
            '/presences',
            $headers,
            [
                'queries' => [
                    Query::equal('userId', [$userId])->toString(),
                ],
            ]
        );

        $this->assertEquals(200, $list['headers']['status-code']);
        $this->assertEquals(1, $list['body']['total']);
        $this->assertCount(1, $list['body']['presences']);
        $this->assertEquals($userId, $list['body']['presences'][0]['userId']);
        $this->assertEquals('away', $list['body']['presences'][0]['status']);
    }
}
