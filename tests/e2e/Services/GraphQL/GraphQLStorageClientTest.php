<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

class GraphQLStorageClientTest extends Scope
{
    use ProjectCustom;
    use SideClient;
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
                'permission' => 'bucket',
                'read' => ['role:all'],
                'write' => ['role:all'],
            ]
        ];

        $bucket = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $gqlPayload);

        $this->assertIsArray($bucket['body']['data']);
        $this->assertArrayNotHasKey('errors', $bucket['body']);
        $bucket = $bucket['body']['data']['storageCreateBucket'];
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
                'bucketId' => $bucket['_id'],
                'fileId' => 'unique()',
                'file' => '{"name":"John Doe","age":42}',
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
        $file = $file['body']['data']['storageCreateFile'];
        $this->assertEquals('actor.json', $file['fileId']);

        return $file;
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
        $files = $files['body']['data']['storageListFiles'];
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
     * @depends testCreateFile
     * @param $file
     * @return array
     * @throws \Exception
     */
    public function testGetFilePreview($file)
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_FILE_PREVIEW);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'bucketId' => $file['bucketId'],
                'fileId' => $file['fileId'],
                'width' => 100,
                'height' => 100,
            ]
        ];

        $file = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($file['body']['data']);
        $this->assertArrayNotHasKey('errors', $file['body']);
        $file = $file['body']['data']['storageGetFilePreview'];
        $this->assertEquals('actor.json', $file['fileId']);

        return $file;
    }

    /**
     * @depends testCreateFile
     * @param $file
     * @return array
     * @throws \Exception
     */
    public function testGetFileDownload($file)
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_FILE_DOWNLOAD);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'bucketId' => $file['bucketId'],
                'fileId' => $file['fileId'],
            ]
        ];

        $file = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($file['body']['data']);
        $this->assertArrayNotHasKey('errors', $file['body']);
        $file = $file['body']['data']['storageGetFileDownload'];
        $this->assertEquals('actor.json', $file['fileId']);

        return $file;
    }

    /**
     * @depends testCreateFile
     * @param $file
     * @return array
     * @throws \Exception
     */
    public function testGetFileView($file): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_FILE_VIEW);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'bucketId' => $file['bucketId'],
                'fileId' => $file['fileId'],
            ]
        ];

        $file = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($file['body']['data']);
        $this->assertArrayNotHasKey('errors', $file['body']);
        $file = $file['body']['data']['storageGetFileView'];
        $this->assertEquals('actor.json', $file['fileId']);

        return $file;
    }

    /**
     * @depends testCreateFile
     * @param $file
     * @return array
     * @throws \Exception
     */
    public function testUpdateFile($file): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_FILE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'bucketId' => $file['bucketId'],
                'fileId' => $file['fileId'],
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
        $file = $file['body']['data']['storageUpdateFile'];
        $this->assertIsArray($file);

        return $file;
    }

    /**
     * @depends testCreateFile
     * @param $file
     * @return array
     * @throws \Exception
     */
    public function testDeleteFile($file): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$DELETE_FILE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'bucketId' => $file['bucketId'],
                'fileId' => $file['fileId'],
            ]
        ];

        $file = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertEquals(204, $file['headers']['status-code']);
    }
}
