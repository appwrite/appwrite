<?php

namespace Tests\E2E\Services\GraphQL;

use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

class StorageServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use Base;

    private static array $cachedBucket = [];
    private static array $cachedFile = [];

    protected function setupBucket(): array
    {
        $key = $this->getProject()['$id'];
        if (!empty(static::$cachedBucket[$key])) {
            return static::$cachedBucket[$key];
        }

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_BUCKET);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'bucketId' => ID::unique(),
                'name' => 'Actors',
                'fileSecurity' => false,
                'permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
            ]
        ];

        $bucket = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($bucket['body']['data']);
        $this->assertArrayNotHasKey('errors', $bucket['body']);
        $bucket = $bucket['body']['data']['storageCreateBucket'];
        $this->assertEquals('Actors', $bucket['name']);

        static::$cachedBucket[$key] = $bucket;
        return $bucket;
    }

    protected function setupFile(): array
    {
        $key = $this->getProject()['$id'];
        if (!empty(static::$cachedFile[$key])) {
            return static::$cachedFile[$key];
        }

        $bucket = $this->setupBucket();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_FILE);
        $gqlPayload = [
            'operations' => \json_encode([
                'query' => $query,
                'variables' => [
                    'bucketId' => $bucket['_id'],
                    'fileId' => ID::unique(),
                    'file' => null,
                    'fileSecurity' => true,
                    'permissions' => [
                        Permission::read(Role::any()),
                        Permission::update(Role::any()),
                        Permission::delete(Role::any()),
                    ],
                ]
            ]),
            'map' => \json_encode([
                'file' => ["variables.file"]
            ]),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
        ];

        $file = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($file['body']['data']);
        $this->assertArrayNotHasKey('errors', $file['body']);

        static::$cachedFile[$key] = $file['body']['data']['storageCreateFile'];
        return static::$cachedFile[$key];
    }

    public function testCreateBucket(): void
    {
        $bucket = $this->setupBucket();
        $this->assertEquals('Actors', $bucket['name']);
    }

    public function testCreateFile(): void
    {
        $file = $this->setupFile();
        $this->assertIsArray($file);
    }

    public function testGetBuckets(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_BUCKETS);
        $gqlPayload = [
            'query' => $query,
        ];

        $buckets = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($buckets['body']['data']);
        $this->assertArrayNotHasKey('errors', $buckets['body']);
        $buckets = $buckets['body']['data']['storageListBuckets'];
        $this->assertIsArray($buckets);

        if (!empty($buckets['buckets'])) {
            foreach ($buckets['buckets'] as $bucket) {
                $this->assertArrayHasKey('totalSize', $bucket);
                $this->assertIsInt($bucket['totalSize']);

                /* always 0 because the stats worker runs hourly! */
                $this->assertGreaterThanOrEqual(0, $bucket['totalSize']);
            }
        }

        return $buckets;
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function testGetBucket(): array
    {
        $bucket = $this->setupBucket();
        $this->setupFile(); // Ensure file exists

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_BUCKET);
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
        $this->assertArrayHasKey('totalSize', $bucket);

        return $bucket;
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function testGetFiles(): array
    {
        $bucket = $this->setupBucket();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_FILES);
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
     * @return array
     * @throws \Exception
     */
    public function testGetFile()
    {
        $bucket = $this->setupBucket();
        $file = $this->setupFile();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_FILE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'bucketId' => $bucket['_id'],
                'fileId' => $file['_id'],
            ]
        ];

        $file = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($file['body']['data']);
        $this->assertArrayNotHasKey('errors', $file['body']);

        return $file['body']['data']['storageGetFile'];
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function testGetFilePreview()
    {
        $file = $this->setupFile();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_FILE_PREVIEW);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'bucketId' => $file['bucketId'],
                'fileId' => $file['_id'],
                'width' => 100,
                'height' => 100,
            ]
        ];

        $file = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertEquals(46719, \strlen($file['body']));

        return $file;
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function testGetFileDownload()
    {
        $file = $this->setupFile();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_FILE_DOWNLOAD);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'bucketId' => $file['bucketId'],
                'fileId' => $file['_id'],
            ]
        ];

        $file = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertEquals(47218, \strlen($file['body']));
    }

    /**
     * @throws \Exception
     */
    public function testGetFileView(): void
    {
        $file = $this->setupFile();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_FILE_VIEW);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'bucketId' => $file['bucketId'],
                'fileId' => $file['_id'],
            ]
        ];

        $file = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertEquals(47218, \strlen($file['body']));
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function testUpdateBucket(): array
    {
        $bucket = $this->setupBucket();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::UPDATE_BUCKET);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'bucketId' => $bucket['_id'],
                'name' => 'Actors Updated',
                'fileSecurity' => false,
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
     * @return array
     * @throws \Exception
     */
    public function testUpdateFile(): array
    {
        $file = $this->setupFile();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::UPDATE_FILE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'bucketId' => $file['bucketId'],
                'fileId' => $file['_id'],
                'permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
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
     * @throws \Exception
     */
    public function testDeleteFile(): void
    {
        $file = $this->setupFile();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::DELETE_FILE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'bucketId' => $file['bucketId'],
                'fileId' => $file['_id'],
            ]
        ];

        $file = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsNotArray($file['body']);
        $this->assertEquals(204, $file['headers']['status-code']);

        // Clear cache after deletion
        $key = $this->getProject()['$id'];
        static::$cachedFile[$key] = [];
    }

    /**
     * @return void
     * @throws \Exception
     */
    public function testDeleteBucket(): void
    {
        $bucket = $this->setupBucket();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::DELETE_BUCKET);
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

        $this->assertIsNotArray($bucket['body']);
        $this->assertEquals(204, $bucket['headers']['status-code']);

        // Clear cache after deletion
        $key = $this->getProject()['$id'];
        static::$cachedBucket[$key] = [];
        static::$cachedFile[$key] = [];
    }
}
