<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class GraphQLStorageServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use GraphQLBase;

    public function testCreateBucket(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_BUCKET);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'bucketId' => 'actors',
                'name' => 'Actors',
                'permissions' => 'bucket',
                'read' => ['role:all'],
                'write' => ['role:all'],
            ]
        ];

        $bucket = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($bucket['body']['data']);
        $this->assertArrayNotHasKey('errors', $bucket['body']);
        $bucket = $bucket['body']['data']['bucketsCreate'];
        $this->assertEquals('Actors', $bucket['name']);

        return $bucket;
    }

    /**
     * @depends testCreateBucket
     */
    public function testCreateFile($bucket): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_FILE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'bucketId' => $bucket['id'],
                'fileId' => 'actor.json',
                'content' => '{"name":"John Doe","age":42}',
                'permissions' => 'file',
                'read' => ['role:all'],
                'write' => ['role:all'],
            ]
        ];

        $file = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($file['body']['data']);
        $this->assertArrayNotHasKey('errors', $file['body']);
        $file = $file['body']['data']['filesCreate'];
        $this->assertEquals('actor.json', $file['fileId']);

        return $file;
    }

    public function testGetBuckets(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_BUCKETS);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'projectId' => $projectId,
            ]
        ];

        $buckets = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($buckets['body']['data']);
        $this->assertArrayNotHasKey('errors', $buckets['body']);
        $buckets = $buckets['body']['data']['bucketsGet'];
        $this->assertIsArray($buckets);

        return $buckets;
    }

    /**
     * @depends testCreateBucket
     * @param $bucket
     * @return array
     * @throws \Exception
     */
    public function testGetBucket($bucket): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_BUCKET);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'bucketId' => $bucket['_id'],
            ]
        ];

        $bucket = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($bucket['body']['data']);
        $this->assertArrayNotHasKey('errors', $bucket['body']);
        $bucket = $bucket['body']['data']['storageGetBucket'];
        $this->assertEquals('Actors', $bucket['name']);

        return $bucket;
    }

    /**
     * @depends testCreateBucket
     * @param $bucket
     * @return array
     * @throws \Exception
     */
    public function testGetFiles($bucket): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_FILES);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'bucketId' => $bucket['_id'],
            ]
        ];

        $files = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($files['body']['data']);
        $this->assertArrayNotHasKey('errors', $files['body']);
        $files = $files['body']['data']['storageGetFiles'];
        $this->assertIsArray($files);

        return $files;
    }

    /**
     * @depends testCreateFile
     * @param $file
     * @return array
     * @throws \Exception
     */
    public function testGetFile($bucket, $file)
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_FILE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'bucketId' => $bucket['_id'],
                'fileId' => $file['fileId'],
            ]
        ];

        $file = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($file['body']['data']);
        $this->assertArrayNotHasKey('errors', $file['body']);
        $file = $file['body']['data']['storageGetFile'];
        $this->assertEquals('actor.json', $file['fileId']);

        return $file;
    }

    /**
     * @depends testCreateBucket
     * @param $bucket
     * @return array
     * @throws \Exception
     */
    public function testUpdateBucket($bucket): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_BUCKET);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'bucketId' => $bucket['_id'],
                'name' => 'Actors Updated',
                'permissions' => 'bucket',
                'read' => ['role:all'],
                'write' => ['role:all'],
            ]
        ];

        $bucket = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($bucket['body']['data']);
        $this->assertArrayNotHasKey('errors', $bucket['body']);
        $bucket = $bucket['body']['data']['storageUpdateBucket'];
        $this->assertEquals('Actors Updated', $bucket['name']);

        return $bucket;
    }

    /**
     * @depends testCreateBucket
     * @param $bucket
     * @return array
     * @throws \Exception
     */
    public function testDeleteBucket($bucket): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$DELETE_BUCKET);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'bucketId' => $bucket['_id'],
            ]
        ];

        $bucket = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($bucket['body']['data']);
        $this->assertArrayNotHasKey('errors', $bucket['body']);
        $bucket = $bucket['body']['data']['storageDeleteBucket'];
        $this->assertEquals('Actors Updated', $bucket['name']);

        return $bucket;
    }
}