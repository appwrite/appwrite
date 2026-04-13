<?php
namespace Tests\E2E\Services\Presence;

use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;

trait PresenceBase
{
    private static array $presenceCache = [];

    protected function setupPresence(array $overrides = []): array
    {
        $projectId = $this->getProject()['$id'];
        $cacheKey = $projectId . ':' . $this->getSide();

        if (empty($overrides) && !empty(self::$presenceCache[$cacheKey])) {
            return self::$presenceCache[$cacheKey];
        }

        $payload = \array_merge([
            'status' => 'online',
            'metadata' => [
                'device' => 'web',
                'side' => $this->getSide(),
            ],
        ], $overrides);

        if ($this->getSide() === 'server' && !isset($payload['userId'])) {
            $payload['userId'] = $this->getUser()['$id'];
        }

        $response = $this->client->call(
            Client::METHOD_PUT,
            '/presences/' . ID::unique(),
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], $this->getHeaders()),
            $payload
        );

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertArrayHasKey('userId', $response['body']);
        $this->assertArrayHasKey('status', $response['body']);
        $this->assertArrayHasKey('metadata', $response['body']);

        if ($this->getSide() === 'client') {
            $this->assertEquals($this->getUser()['$id'], $response['body']['userId']);
        } else {
            $this->assertEquals($payload['userId'], $response['body']['userId']);
        }

        if (empty($overrides)) {
            self::$presenceCache[$cacheKey] = $response['body'];
        }

        return $response['body'];
    }

    public function testUpsertAndGetPresence(): void
    {
        $presence = $this->setupPresence();

        $get = $this->client->call(
            Client::METHOD_GET,
            '/presences/' . $presence['$id'],
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders())
        );

        $this->assertEquals(200, $get['headers']['status-code']);
        $this->assertEquals($presence['$id'], $get['body']['$id']);
        $this->assertEquals($presence['userId'], $get['body']['userId']);
        $this->assertArrayHasKey('expiry', $get['body']);
    }

    public function testListPresences(): void
    {
        $presence = $this->setupPresence();

        $list = $this->client->call(
            Client::METHOD_GET,
            '/presences',
            \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
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
            ], $this->getHeaders()),
            $payload
        );

        $this->assertEquals(200, $update['headers']['status-code']);
        $this->assertEquals('busy', $update['body']['status']);
        $this->assertEquals(['source' => 'update'], $update['body']['metadata']);
    }

    public function testDeletePresence(): void
    {
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
            ], $this->getHeaders())
        );

        $this->assertEquals(204, $delete['headers']['status-code']);
    }

    public function testUpdateNotFound(): void
    {
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
            ], $this->getHeaders()),
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
            ], $this->getHeaders()),
            [
                'userId' => ID::unique(),
                'status' => 'online',
            ]
        );

        $this->assertEquals(401, $response['headers']['status-code']);
        $this->assertEquals('general_unauthorized_scope', $response['body']['type']);
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
            ], $this->getHeaders()),
            [
                'status' => 'online',
            ]
        );

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals('general_argument_invalid', $response['body']['type']);
    }
}