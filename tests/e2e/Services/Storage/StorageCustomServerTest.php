<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Storage;

use Appwrite\Extend\Exception;
use CURLFile;
use PHPUnit\Framework\Attributes\Group;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Compression\Compression;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
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
            'bucketId' => Id::unique(),
            'name' => 'Test Bucket',
            'fileSecurity' => true,
        ]);

        self::$cachedBucket[$cacheKey] = ['bucketId' => $bucket['body']['$id']];

        return self::$cachedBucket[$cacheKey];
    }

    public function testGetFilePreviewWithAutomaticGravity(): void
    {
        $bucketId = $this->setupBucket()['bucketId'];
        $source = \tempnam(\sys_get_temp_dir(), 'appwrite-autogravity-');
        if ($source === false) {
            $this->fail('Failed to create a temporary image');
        }
        $subject = new \Imagick(__DIR__ . '/../../../resources/disk-a/kitten-1.jpg');
        $subject->resizeImage(900, 0, \Imagick::FILTER_LANCZOS, 1);
        $canvas = new \Imagick();
        $canvas->newImage(2400, 1920, 'white', 'png');
        $canvas->compositeImage($subject, \Imagick::COMPOSITE_OVER, 1450, 0);
        $canvas->writeImage($source);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => Id::unique(),
            'file' => new CURLFile($source, 'image/png', 'autogravity.png'),
        ]);
        \unlink($source);

        $this->assertEquals(201, $file['headers']['status-code']);

        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());
        $params = [
            'width' => 120,
            'height' => 320,
            'gravity' => 'auto',
            'output' => 'png',
        ];
        $path = '/storage/buckets/' . $bucketId . '/files/' . $file['body']['$id'] . '/preview';

        $preview = $this->client->call(Client::METHOD_GET, $path, $headers, $params);

        if (System::getEnv('_APP_AUTOGRAVITY_HOST', '') === '') {
            $this->assertEquals(400, $preview['headers']['status-code']);
            $this->assertSame(Exception::GENERAL_ARGUMENT_INVALID, $preview['body']['type']);
            $this->assertSame(
                'Autogravity needs to be configured with _APP_AUTOGRAVITY_HOST to use automatic gravity',
                $preview['body']['message']
            );

            return;
        }

        $this->assertEquals(200, $preview['headers']['status-code']);
        $this->assertEquals('image/png', $preview['headers']['content-type']);
        $this->assertEquals('miss', $preview['headers']['x-appwrite-cache']);
        $this->assertNotEmpty($preview['body']);

        $image = new \Imagick();
        $image->readImageBlob($preview['body']);
        $this->assertSame(120, $image->getImageWidth());
        $this->assertSame(320, $image->getImageHeight());

        $center = $this->client->call(Client::METHOD_GET, $path, $headers, [...$params, 'gravity' => 'center']);
        $centerImage = new \Imagick();
        $centerImage->readImageBlob($center['body']);
        $this->assertNotSame($centerImage->getImageSignature(), $image->getImageSignature());

        $cached = [];
        $this->assertEventually(function () use (&$cached, $path, $headers, $params, $image): void {
            $cached = $this->client->call(Client::METHOD_GET, $path, $headers, $params);

            $this->assertSame('hit', $cached['headers']['x-appwrite-cache']);
            $cachedImage = new \Imagick();
            $cachedImage->readImageBlob($cached['body']);
            $this->assertSame($image->getImageSignature(), $cachedImage->getImageSignature());
        });

        $this->assertEquals(200, $cached['headers']['status-code']);
        $this->assertEquals('image/png', $cached['headers']['content-type']);
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
            'bucketId' => Id::unique(),
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
        $this->assertFalse($bucket['body']['stripMetadata']);
        $bucketId = $bucket['body']['$id'];

        /**
         * Test create with Custom ID
         */
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'bucketId' => Id::custom('bucket1'),
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
            'bucketId' => Id::unique(),
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
            'bucketId' => Id::custom('bucket1'),
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
            'bucketId' => Id::unique(),
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

    public function testUpdateBucketKeepsSettingsOnNull(): void
    {
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());

        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', $headers, [
            'bucketId' => Id::unique(),
            'name' => 'Test Bucket Null Update',
            'maximumFileSize' => 1000000,
            'compression' => Compression::GZIP,
            'encryption' => false,
            'stripMetadata' => true,
        ]);
        $this->assertSame(201, $bucket['headers']['status-code']);

        // An explicit null keeps the bucket's current value rather than resetting it to the param default
        $bucket = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucket['body']['$id'], $headers, [
            'name' => 'Test Bucket Null Update',
            'maximumFileSize' => null,
            'compression' => null,
            'encryption' => null,
            'stripMetadata' => null,
        ]);
        $this->assertSame(200, $bucket['headers']['status-code']);

        $bucket = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucket['body']['$id'], $headers);
        $this->assertSame(200, $bucket['headers']['status-code']);
        $this->assertSame(1000000, $bucket['body']['maximumFileSize']);
        $this->assertSame(Compression::GZIP, $bucket['body']['compression']);
        $this->assertFalse($bucket['body']['encryption']);
        $this->assertTrue($bucket['body']['stripMetadata']);

        $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucket['body']['$id'], $headers);
    }

    public function testUpdateBucketDescription(): void
    {
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());
        $keyword = ID::unique();
        $newKeyword = ID::unique();

        /**
         * Test for SUCCESS
         */
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', $headers, [
            'bucketId' => ID::unique(),
            'name' => 'Invoices',
            'description' => 'Customer invoices tagged ' . $keyword,
        ]);

        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertSame('Customer invoices tagged ' . $keyword, $bucket['body']['description']);

        $bucketId = $bucket['body']['$id'];

        $bucket = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId, $headers);

        $this->assertEquals(200, $bucket['headers']['status-code']);
        $this->assertSame('Customer invoices tagged ' . $keyword, $bucket['body']['description']);

        $buckets = $this->client->call(Client::METHOD_GET, '/storage/buckets', $headers, [
            'search' => $keyword,
        ]);

        $this->assertEquals(200, $buckets['headers']['status-code']);
        $this->assertCount(1, $buckets['body']['buckets']);
        $this->assertSame($bucketId, $buckets['body']['buckets'][0]['$id']);

        $bucket = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId, $headers, [
            'name' => 'Invoices',
            'description' => 'Receipts tagged ' . $newKeyword,
        ]);

        $this->assertEquals(200, $bucket['headers']['status-code']);
        $this->assertSame('Receipts tagged ' . $newKeyword, $bucket['body']['description']);

        $buckets = $this->client->call(Client::METHOD_GET, '/storage/buckets', $headers, [
            'search' => $newKeyword,
        ]);

        $this->assertEquals(200, $buckets['headers']['status-code']);
        $this->assertCount(1, $buckets['body']['buckets']);
        $this->assertSame($bucketId, $buckets['body']['buckets'][0]['$id']);

        $buckets = $this->client->call(Client::METHOD_GET, '/storage/buckets', $headers, [
            'search' => $keyword,
        ]);

        $this->assertEquals(200, $buckets['headers']['status-code']);
        $this->assertCount(0, $buckets['body']['buckets']);

        $bucket = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId, $headers, [
            'name' => 'Invoices',
        ]);

        $this->assertEquals(200, $bucket['headers']['status-code']);
        $this->assertSame('Receipts tagged ' . $newKeyword, $bucket['body']['description']);

        $bucket = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId, $headers, [
            'name' => 'Invoices',
            'description' => '',
        ]);

        $this->assertEquals(200, $bucket['headers']['status-code']);
        $this->assertSame('', $bucket['body']['description']);

        $plain = $this->client->call(Client::METHOD_POST, '/storage/buckets', $headers, [
            'bucketId' => ID::unique(),
            'name' => 'Plain',
        ]);

        $this->assertEquals(201, $plain['headers']['status-code']);
        $this->assertSame('', $plain['body']['description']);

        /**
         * Test for FAILURE
         */
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', $headers, [
            'bucketId' => ID::unique(),
            'name' => 'Too long',
            'description' => \str_repeat('a', 1025),
        ]);

        $this->assertEquals(400, $bucket['headers']['status-code']);
        $this->assertSame(Exception::GENERAL_ARGUMENT_INVALID, $bucket['body']['type']);

        $bucket = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId, $headers, [
            'name' => 'Invoices',
            'description' => \str_repeat('a', 1025),
        ]);

        $this->assertEquals(400, $bucket['headers']['status-code']);
        $this->assertSame(Exception::GENERAL_ARGUMENT_INVALID, $bucket['body']['type']);

        $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId, $headers);
        $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $plain['body']['$id'], $headers);
    }

    public function testDeleteBucket(): void
    {
        // Create a fresh bucket for deletion testing (not using cache since we delete it)
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'bucketId' => Id::unique(),
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

    public function testDeleteBucketKeepsBucketsSharingItsPrefix(): void
    {
        $headers = array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());

        $deletedId = Id::unique();
        $keptId = $deletedId . '2';
        $files = [];

        foreach ([$deletedId, $keptId] as $bucketId) {
            $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
                'content-type' => 'application/json',
            ], $headers), [
                'bucketId' => $bucketId,
                'name' => 'Prefix ' . $bucketId,
                'fileSecurity' => false,
            ]);
            $this->assertSame(201, $bucket['headers']['status-code']);

            $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
                'content-type' => 'multipart/form-data',
            ], $headers), [
                'fileId' => Id::unique(),
                'file' => new CURLFile('data://text/plain;base64,' . \base64_encode('in ' . $bucketId), 'text/plain', 'file.txt'),
            ]);
            $this->assertSame(201, $file['headers']['status-code']);

            $files[$bucketId] = $file['body']['$id'];
        }

        $uploads = getDevice(APP_STORAGE_UPLOADS . '/app-' . $this->getProject()['$id']);
        $stored = fn (string $bucketId): array => $uploads->listFiles($uploads->getRoot() . '/' . $bucketId . '/', 10)->files;

        $this->assertNotEmpty($stored($deletedId), 'The file is not visible under ' . $uploads->getRoot() . '/' . $deletedId);
        $this->assertNotEmpty($stored($keptId), 'The file is not visible under ' . $uploads->getRoot() . '/' . $keptId);

        $response = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $deletedId, array_merge([
            'content-type' => 'application/json',
        ], $headers));
        $this->assertSame(204, $response['headers']['status-code']);

        $this->assertEventually(function () use ($stored, $deletedId): void {
            $this->assertSame([], $stored($deletedId));
        }, 60000, 500);

        $this->assertNotEmpty($stored($keptId), 'Deleting bucket ' . $deletedId . ' also deleted the files of bucket ' . $keptId);

        $download = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $keptId . '/files/' . $files[$keptId] . '/download', $headers);
        $this->assertSame(200, $download['headers']['status-code']);
        $this->assertSame('in ' . $keptId, $download['body']);
    }

    /**
     * Regression for chunked uploads under the antivirus size limit.
     *
     * Requires ClamAV (compose profile `antivirus`) and
     * `_APP_STORAGE_ANTIVIRUS=enabled`. File must be >5MB (chunked) and
     * ≤20MB (`APP_LIMIT_ANTIVIRUS`) so the last chunk triggers a scan.
     */
    #[Group('antivirus')]
    public function testCreateBucketFileChunkedUploadWithAntivirus(): void
    {
        if (System::getEnv('_APP_STORAGE_ANTIVIRUS', 'disabled') === 'disabled') {
            $this->markTestSkipped('Antivirus is disabled.');
        }

        $health = $this->client->call(Client::METHOD_GET, '/health/anti-virus', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $health['headers']['status-code']);
        $this->assertEquals('pass', $health['body']['status'], 'ClamAV must be reachable when antivirus is enabled.');

        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'bucketId' => Id::unique(),
            'name' => 'Antivirus Chunked Upload Bucket',
            'fileSecurity' => true,
            'antivirus' => true,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
            ],
        ]);
        $this->assertEquals(201, $bucket['headers']['status-code']);

        $source = __DIR__ . '/../../../resources/functions/large/blue.mp4';
        $totalSize = \filesize($source);
        $this->assertGreaterThan(5 * 1024 * 1024, $totalSize, 'Fixture must be large enough to chunk.');
        $this->assertLessThanOrEqual(20_000_000, $totalSize, 'Fixture must stay under APP_LIMIT_ANTIVIRUS.');

        $chunkSize = 5 * 1024 * 1024;
        $handle = \fopen($source, 'rb');
        $this->assertNotFalse($handle);

        $fileId = 'unique()';
        $mimeType = \mime_content_type($source);
        $counter = 0;
        $id = '';
        $file = null;
        $headers = [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        while (!\feof($handle)) {
            $curlFile = new CURLFile(
                'data://' . $mimeType . ';base64,' . \base64_encode(\fread($handle, $chunkSize)),
                $mimeType,
                'blue.mp4'
            );
            $headers['content-range'] = 'bytes ' . ($counter * $chunkSize) . '-' . \min(((($counter * $chunkSize) + $chunkSize) - 1), $totalSize - 1) . '/' . $totalSize;
            if (!empty($id)) {
                $headers['x-appwrite-id'] = $id;
            }

            $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucket['body']['$id'] . '/files', \array_merge($headers, $this->getHeaders()), [
                'fileId' => $fileId,
                'file' => $curlFile,
                'permissions' => [
                    Permission::read(Role::any()),
                ],
            ]);
            $counter++;
            $id = $file['body']['$id'] ?? $id;
        }
        \fclose($handle);

        $this->assertEquals(201, $file['headers']['status-code']);
        $this->assertNotEmpty($file['body']['$id']);
        $this->assertEquals('blue.mp4', $file['body']['name']);
        $this->assertEquals($totalSize, $file['body']['sizeOriginal']);
        $this->assertEquals($file['body']['chunksTotal'], $file['body']['chunksUploaded']);
        $this->assertNotEmpty($file['body']['mimeType']);
    }

    public function testStripMetadataOnUpload(): void
    {
        /**
         * Test for SUCCESS
         */
        $photo = $this->createTaggedPhoto(64, 48);
        $text = \tempnam(\sys_get_temp_dir(), 'strip-text-');
        \file_put_contents($text, 'GPSLatitude 37.7749 is just text here');

        $stripBucket = $this->createBucketForStripping(true);
        $keepBucket = $this->createBucketForStripping(false);

        $file = $this->uploadFile($stripBucket, new CURLFile($photo, 'image/jpeg', 'photo.jpg'));
        $this->assertSame(201, $file['headers']['status-code']);
        $this->assertLessThan(\filesize($photo), $file['body']['sizeOriginal']);

        $download = $this->downloadFile($stripBucket, $file['body']['$id']);
        $this->assertSame($file['body']['sizeOriginal'], \strlen($download));
        $tags = $this->readTags($download);
        foreach (['GPSLatitude', 'GPSLongitude', 'Make', 'Model', 'DateTimeOriginal', 'XMP', 'IPTC'] as $tag) {
            $this->assertArrayNotHasKey($tag, $tags, "{$tag} was not removed");
        }
        $this->assertSame('6', $tags['Orientation'] ?? null);

        // The format comes from the content, so a renamed photo can't keep its location.
        $file = $this->uploadFile($stripBucket, new CURLFile($photo, 'image/png', 'disguised.png'));
        $this->assertSame(201, $file['headers']['status-code']);
        $this->assertArrayNotHasKey('GPSLatitude', $this->readTags($this->downloadFile($stripBucket, $file['body']['$id'])));

        $file = $this->uploadFile($keepBucket, new CURLFile($photo, 'image/jpeg', 'photo.jpg'));
        $this->assertSame(201, $file['headers']['status-code']);
        $download = $this->downloadFile($keepBucket, $file['body']['$id']);
        $this->assertSame(\file_get_contents($photo), $download);
        $this->assertArrayHasKey('GPSLatitude', $this->readTags($download));

        $file = $this->uploadFile($stripBucket, new CURLFile($text, 'text/plain', 'notes.txt'));
        $this->assertSame(201, $file['headers']['status-code']);
        $this->assertSame(\file_get_contents($text), $this->downloadFile($stripBucket, $file['body']['$id']));

        \unlink($photo);
        \unlink($text);

        /**
         * Test for FAILURE
         */
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());

        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', $headers, [
            'bucketId' => Id::unique(),
            'name' => 'Strip Metadata Invalid',
            'stripMetadata' => 'invalid',
        ]);
        $this->assertSame(400, $bucket['headers']['status-code']);
        $this->assertSame(Exception::GENERAL_ARGUMENT_INVALID, $bucket['body']['type']);

        $bucket = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $stripBucket, $headers, [
            'name' => 'Strip Metadata Invalid',
            'stripMetadata' => 'invalid',
        ]);
        $this->assertSame(400, $bucket['headers']['status-code']);
        $this->assertSame(Exception::GENERAL_ARGUMENT_INVALID, $bucket['body']['type']);

        $bucket = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $stripBucket, $headers);
        $this->assertSame(200, $bucket['headers']['status-code']);
        $this->assertTrue($bucket['body']['stripMetadata']);
    }

    public function testStripMetadataOnChunkedUpload(): void
    {
        /**
         * Test for SUCCESS
         */
        // Random noise compresses poorly, so this JPEG is large enough to upload in chunks.
        $photo = $this->createTaggedPhoto(1800, 1800, noise: true);
        $totalSize = \filesize($photo);
        $chunkSize = 5 * 1024 * 1024;
        $this->assertGreaterThan($chunkSize, $totalSize, 'Fixture must be large enough to chunk.');
        $this->assertLessThanOrEqual(APP_STORAGE_READ_BUFFER, $totalSize, 'Fixture must stay under the stripping size limit.');

        $bucketId = $this->createBucketForStripping(true);
        $handle = \fopen($photo, 'rb');
        $this->assertNotFalse($handle);

        $headers = [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];
        $fileId = Id::unique();
        $counter = 0;
        $id = '';
        $file = null;

        while (!\feof($handle)) {
            $chunk = \fread($handle, $chunkSize);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $start = $counter * $chunkSize;
            $headers['content-range'] = 'bytes ' . $start . '-' . ($start + \strlen($chunk) - 1) . '/' . $totalSize;
            if (!empty($id)) {
                $headers['x-appwrite-id'] = $id;
            }

            $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', \array_merge($headers, $this->getHeaders()), [
                'fileId' => $fileId,
                'file' => new CURLFile('data://image/jpeg;base64,' . \base64_encode($chunk), 'image/jpeg', 'large.jpg'),
            ]);
            $counter++;
            $id = $file['body']['$id'] ?? $id;
        }
        \fclose($handle);

        $this->assertSame(201, $file['headers']['status-code']);
        $this->assertGreaterThan(1, $file['body']['chunksTotal']);
        $this->assertLessThan($totalSize, $file['body']['sizeOriginal']);

        $download = $this->downloadFile($bucketId, $file['body']['$id']);
        $this->assertSame($file['body']['sizeOriginal'], \strlen($download));
        $this->assertArrayNotHasKey('GPSLatitude', $this->readTags($download));

        \unlink($photo);
    }

    private function createBucketForStripping(bool $stripMetadata): string
    {
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'bucketId' => Id::unique(),
            'name' => 'Strip Metadata ' . ($stripMetadata ? 'On' : 'Off'),
            'stripMetadata' => $stripMetadata,
        ]);
        $this->assertSame(201, $bucket['headers']['status-code']);
        $this->assertSame($stripMetadata, $bucket['body']['stripMetadata']);

        return $bucket['body']['$id'];
    }

    private function uploadFile(string $bucketId, CURLFile $file): array
    {
        return $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => Id::unique(),
            'file' => $file,
        ]);
    }

    private function downloadFile(string $bucketId, string $fileId): string
    {
        $download = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/download', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertSame(200, $download['headers']['status-code']);

        return $download['body'];
    }

    private function createTaggedPhoto(int $width, int $height, bool $noise = false): string
    {
        $path = \sys_get_temp_dir() . '/strip-photo-' . \uniqid() . '.jpg';

        $image = new \Imagick();
        $image->newImage($width, $height, new \ImagickPixel('skyblue'));
        if ($noise) {
            $image->addNoiseImage(\Imagick::NOISE_RANDOM);
            $image->setImageCompressionQuality(100);
        }
        $image->setImageFormat('jpeg');

        // tagged.jpg carries a location, camera, capture time, orientation, and XMP and IPTC creators.
        $tagged = new \Imagick(__DIR__ . '/../../../resources/disk-a/tagged.jpg');
        foreach ($tagged->getImageProfiles('*', true) as $name => $profile) {
            $image->profileImage($name, $profile);
        }
        // Imagick writes the orientation from its own setting, not from the copied EXIF.
        $image->setImageOrientation($tagged->getImageOrientation());
        $image->writeImage($path);

        return $path;
    }

    /**
     * @return array<string, string> EXIF tags by name, plus XMP and IPTC when those blocks are present
     */
    private function readTags(string $contents): array
    {
        $image = new \Imagick();
        $image->readImageBlob($contents);

        $tags = [];
        foreach ($image->getImageProperties('exif:*') as $name => $value) {
            $tags[\substr($name, \strlen('exif:'))] = $value;
        }
        foreach ($image->getImageProfiles('*', false) as $name) {
            $tags[\strtoupper($name)] = $name;
        }

        return $tags;
    }

    public function testChunkedUploadRejectsChangedTotal(): void
    {
        $bucketId = $this->setupBucket()['bucketId'];

        $upload = function (string $range, string $body, ?string $resumeId = null) use ($bucketId): array {
            $headers = [
                'content-type' => 'multipart/form-data',
                'x-appwrite-project' => $this->getProject()['$id'],
                'content-range' => $range,
            ];

            if ($resumeId !== null) {
                $headers['x-appwrite-id'] = $resumeId;
            }

            return $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge($headers, $this->getHeaders()), [
                'fileId' => $resumeId ?? Id::unique(),
                'file' => new CURLFile('data://text/plain;base64,' . base64_encode($body), 'text/plain', 'chunk.txt'),
                'permissions' => [
                    Permission::read(Role::any()),
                ],
            ]);
        };

        $chunkSize = 5 * 1024 * 1024;
        $totalSize = $chunkSize + 1024;

        $first = $upload('bytes 0-' . ($chunkSize - 1) . '/' . $totalSize, str_repeat('a', $chunkSize));
        $this->assertEquals(201, $first['headers']['status-code']);
        $fileId = $first['body']['$id'];

        /**
         * Test for FAILURE
         * The second chunk announces a different total than the first.
         */
        $second = $upload('bytes ' . $chunkSize . '-' . ($totalSize - 1) . '/' . ($totalSize + 1), str_repeat('b', 1024), $fileId);
        $this->assertEquals(400, $second['headers']['status-code']);
        $this->assertEquals('storage_invalid_content_range', $second['body']['type']);
    }

    public function testChunkedUploadRejectsShortChunk(): void
    {
        $bucketId = $this->setupBucket()['bucketId'];

        $upload = function (string $range, string $body, ?string $resumeId = null) use ($bucketId): array {
            $headers = [
                'content-type' => 'multipart/form-data',
                'x-appwrite-project' => $this->getProject()['$id'],
                'content-range' => $range,
            ];

            if ($resumeId !== null) {
                $headers['x-appwrite-id'] = $resumeId;
            }

            return $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge($headers, $this->getHeaders()), [
                'fileId' => $resumeId ?? Id::unique(),
                'file' => new CURLFile('data://text/plain;base64,' . base64_encode($body), 'text/plain', 'chunk.txt'),
                'permissions' => [
                    Permission::read(Role::any()),
                ],
            ]);
        };

        $chunkSize = 5 * 1024 * 1024;
        $totalSize = $chunkSize + 1024;

        $first = $upload('bytes 0-' . ($chunkSize - 1) . '/' . $totalSize, str_repeat('a', $chunkSize));
        $this->assertEquals(201, $first['headers']['status-code']);
        $fileId = $first['body']['$id'];

        /**
         * Test for FAILURE
         * The last chunk carries fewer bytes than its range announces.
         */
        $second = $upload('bytes ' . $chunkSize . '-' . ($totalSize - 1) . '/' . $totalSize, str_repeat('b', 10), $fileId);
        $this->assertEquals(400, $second['headers']['status-code']);
        $this->assertEquals('storage_invalid_content_range', $second['body']['type']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(404, $file['headers']['status-code']);
    }
}
