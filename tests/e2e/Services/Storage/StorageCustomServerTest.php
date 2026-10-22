<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Storage;

use Ahc\Jwt\JWT;
use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\System\System;

final class StorageCustomServerTest extends Scope
{
    use StorageBase;
    use ProjectCustom;
    use SideServer;

    /**
     * @var array Cached bucket data for tests
     */
    private static array $cachedBucket = [];

    /**
     * Helper method to set up a bucket for tests.
     * Uses static caching to avoid recreating resources.
     */
    protected function setupBucket(): array
    {
        $cacheKey = $this->getProject()['$id'];

        if (!empty(self::$cachedBucket[$cacheKey])) {
            return self::$cachedBucket[$cacheKey];
        }

        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket',
            'fileSecurity' => true,
        ]);

        self::$cachedBucket[$cacheKey] = ['bucketId' => $bucket['body']['$id']];

        return self::$cachedBucket[$cacheKey];
    }

    public function testCreateBucket(): void
    {
        /**
         * Test for SUCCESS
         */
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket',
            'fileSecurity' => true,
        ]);
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucket['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($bucket['body']['$createdAt']));
        $this->assertIsArray($bucket['body']['$permissions']);
        $this->assertIsArray($bucket['body']['allowedFileExtensions']);
        $this->assertEquals('Test Bucket', $bucket['body']['name']);
        $this->assertEquals(true, $bucket['body']['enabled']);
        $this->assertEquals(true, $bucket['body']['encryption']);
        $this->assertEquals(true, $bucket['body']['antivirus']);
        $bucketId = $bucket['body']['$id'];

        /**
         * Test create with Custom ID
         */
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'bucketId' => ID::custom('bucket1'),
            'name' => 'Test Bucket',
            'fileSecurity' => true,
        ]);
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertEquals('bucket1', $bucket['body']['$id']);

        /**
         * Test for FAILURE
         */
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'bucketId' => ID::unique(),
            'name' => '',
            'fileSecurity' => true,
        ]);
        $this->assertEquals(400, $bucket['headers']['status-code']);
    }

    public function testListBucket(): void
    {
        $data = $this->setupBucket();
        $id = $data['bucketId'] ?? '';

        // Create bucket1 for this test (may already exist from testCreateBucket in parallel runs)
        $bucket1Response = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'bucketId' => ID::custom('bucket1'),
            'name' => 'Test Bucket 1',
            'fileSecurity' => true,
        ]);
        // Accept both 201 (created) and 409 (already exists from parallel test)
        $this->assertContains($bucket1Response['headers']['status-code'], [201, 409]);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(
            Client::METHOD_GET,
            '/storage/buckets',
            array_merge(
                [
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ],
                $this->getHeaders()
            )
        );
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        // Find our bucket in the list (may not be first in parallel execution)
        $bucketIds = array_column($response['body']['buckets'], '$id');
        $this->assertContains($id, $bucketIds, 'Created bucket should exist in bucket list');
        // Find our bucket for name assertion
        $ourBucket = null;
        foreach ($response['body']['buckets'] as $bucket) {
            if ($bucket['$id'] === $id) {
                $ourBucket = $bucket;
                break;
            }
        }
        $this->assertNotNull($ourBucket);
        $this->assertEquals('Test Bucket', $ourBucket['name']);

        foreach ($response['body']['buckets'] as $bucket) {
            $this->assertArrayHasKey('totalSize', $bucket);
            $this->assertIsInt($bucket['totalSize']);

            /* always 0 because the stats worker runs hourly! */
            $this->assertGreaterThanOrEqual(0, $bucket['totalSize']);
        }

        $response = $this->client->call(Client::METHOD_GET, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::limit(1)->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['buckets']);

        $response = $this->client->call(Client::METHOD_GET, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::offset(1)->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        // With offset(1) and at least 2 buckets created, expect at least 1 result
        $this->assertGreaterThanOrEqual(1, count($response['body']['buckets']));

        $response = $this->client->call(Client::METHOD_GET, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('$id', ['bucket1'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['buckets']);

        $response = $this->client->call(Client::METHOD_GET, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('fileSecurity', [true])->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        // We created 2 buckets with fileSecurity=true (setupBucket + bucket1)
        $this->assertGreaterThanOrEqual(2, count($response['body']['buckets']));

        $response = $this->client->call(Client::METHOD_GET, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => $response['body']['buckets'][0]['$id']]))->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['buckets']);
        // In parallel execution, there may be more buckets after the cursor
        $this->assertGreaterThanOrEqual(1, count($response['body']['buckets']));
        // Find bucket1 by ID (may not be first in parallel execution)
        $bucketIds = array_column($response['body']['buckets'], '$id');
        $this->assertContains('bucket1', $bucketIds, 'bucket1 should exist in bucket list after cursor');
    }

    public function testGetBucket(): void
    {
        $data = $this->setupBucket();
        $id = $data['bucketId'] ?? '';
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(
            Client::METHOD_GET,
            '/storage/buckets/' . $id,
            array_merge(
                [
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ],
                $this->getHeaders()
            )
        );
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals($id, $response['body']['$id']);
        $this->assertEquals('Test Bucket', $response['body']['name']);
        $this->assertArrayHasKey('totalSize', $response['body']);

        /**
         * Test for FAILURE
         */

        $response = $this->client->call(
            Client::METHOD_GET,
            '/storage/buckets/empty',
            array_merge(
                [
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ],
                $this->getHeaders()
            )
        );
        $this->assertEquals(404, $response['headers']['status-code']);

        $response = $this->client->call(
            Client::METHOD_GET,
            '/storage/buckets/id-is-really-long-id-is-really-long-id-is-really-long-id-is-really-long',
            array_merge(
                [
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ],
                $this->getHeaders()
            )
        );
        // UID validator always limits to 36 chars regardless of adapter, so validation catches this and returns 400
        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testUpdateBucket(): void
    {
        $data = $this->setupBucket();
        $id = $data['bucketId'] ?? '';
        /**
         * Test for SUCCESS
         */
        $bucket = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket Updated',
            'enabled' => false,
            'fileSecurity' => true,
        ]);
        $this->assertEquals(200, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucket['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($bucket['body']['$createdAt']));
        $this->assertIsArray($bucket['body']['$permissions']);
        $this->assertIsArray($bucket['body']['allowedFileExtensions']);
        $this->assertEquals('Test Bucket Updated', $bucket['body']['name']);
        $this->assertEquals(false, $bucket['body']['enabled']);
        $this->assertEquals(true, $bucket['body']['encryption']);
        $this->assertEquals(true, $bucket['body']['antivirus']);
        $bucketId = $bucket['body']['$id'];
        /**
         * Test for FAILURE
         */
        $bucket = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => '',
            'enabled' => 'false',
        ]);
        $this->assertEquals(400, $bucket['headers']['status-code']);
    }

    public function testDeleteBucket(): void
    {
        // Create a fresh bucket for deletion testing (not using cache since we delete it)
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket Delete',
            'fileSecurity' => true,
        ]);
        $this->assertEquals(201, $bucket['headers']['status-code']);

        $id = $bucket['body']['$id'];
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(
            Client::METHOD_DELETE,
            '/storage/buckets/' . $id,
            array_merge(
                [
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ],
                $this->getHeaders()
            )
        );
        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        $response = $this->client->call(
            Client::METHOD_GET,
            '/storage/buckets/' . $id,
            array_merge(
                [
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ],
                $this->getHeaders()
            )
        );
        $this->assertEquals(404, $response['headers']['status-code']);
    }

    public function testFilePushContentDisposition(): void
    {
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'bucketId' => ID::unique(),
            'name' => 'Push Filename',
        ]);
        $this->assertSame(201, $bucket['headers']['status-code']);
        $bucketId = $bucket['body']['$id'];

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'café logo.png'),
        ]);
        $this->assertSame(201, $file['headers']['status-code']);
        $fileId = $file['body']['$id'];

        $encoder = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 900, 0);
        $push = fn (array $claims): array => $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/push', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'project' => $this->getProject()['$id'],
            'jwt' => $encoder->encode(\array_merge([
                'bucketId' => $bucketId,
                'fileId' => $fileId,
                'projectId' => $this->getProject()['$id'],
            ], $claims)),
        ]);

        /**
         * Test for SUCCESS
         */
        foreach ([[], ['disposition' => 'inline'], ['disposition' => 'other']] as $claims) {
            $response = $push($claims);
            $this->assertSame(200, $response['headers']['status-code']);
            $this->assertSame('inline; filename="caf_ logo.png"; filename*=UTF-8\'\'' . \rawurlencode('café logo.png'), $response['headers']['content-disposition']);
            $this->assertSame("script-src 'none';", $response['headers']['content-security-policy']);
        }

        $response = $push(['disposition' => 'attachment']);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('attachment; filename="caf_ logo.png"; filename*=UTF-8\'\'' . \rawurlencode('café logo.png'), $response['headers']['content-disposition']);

        /**
         * Test for FAILURE
         */
        $response = $push(['fileId' => 'other']);
        $this->assertSame(401, $response['headers']['status-code']);
    }
}
