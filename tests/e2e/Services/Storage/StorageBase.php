<?php

namespace Tests\E2E\Services\Storage;

use Appwrite\Extend\Exception;
use CURLFile;
use PHPUnit\Framework\Attributes\Group;
use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Datetime as DatetimeValidator;

trait StorageBase
{
    /**
     * @var array Cached bucket and file data for tests
     */
    private static array $cachedBucketFile = [];

    /**
     * @var array Cached zstd compression bucket data for tests
     */
    private static array $cachedZstdBucket = [];

    /**
     * Helper method to set up bucket and file data for tests.
     * Uses static caching to avoid recreating resources.
     */
    protected function setupBucketFile(): array
    {
        $cacheKey = $this->getProject()['$id'];

        if (!empty(self::$cachedBucketFile[$cacheKey])) {
            return self::$cachedBucketFile[$cacheKey];
        }

        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket',
            'fileSecurity' => true,
            'maximumFileSize' => 2000000, //2MB
            'allowedFileExtensions' => ['jpg', 'png', 'jfif', 'webp'],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $bucketId = $bucket['body']['$id'];

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        // Create large file bucket
        $bucket2 = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket 2',
            'fileSecurity' => true,
            'permissions' => [
                Permission::create(Role::any()),
            ],
        ]);

        // Chunked Upload for large file
        $source = __DIR__ . "/../../../resources/disk-a/large-file.mp4";
        $totalSize = \filesize($source);
        $chunkSize = 5 * 1024 * 1024;
        $handle = @fopen($source, "rb");
        $fileId = 'unique()';
        $mimeType = mime_content_type($source);
        $counter = 0;
        $size = filesize($source);
        $headers = [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id']
        ];
        $id = '';
        $largeFile = null;
        while (!feof($handle)) {
            $curlFile = new \CURLFile('data://' . $mimeType . ';base64,' . base64_encode(@fread($handle, $chunkSize)), $mimeType, 'large-file.mp4');
            $headers['content-range'] = 'bytes ' . ($counter * $chunkSize) . '-' . min(((($counter * $chunkSize) + $chunkSize) - 1), $size - 1) . '/' . $size;
            if (!empty($id)) {
                $headers['x-appwrite-id'] = $id;
            }
            $largeFile = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucket2['body']['$id'] . '/files', array_merge($headers, $this->getHeaders()), [
                'fileId' => $fileId,
                'file' => $curlFile,
                'permissions' => [
                    Permission::read(Role::any())
                ],
            ]);
            $counter++;
            $id = $largeFile['body']['$id'];
        }
        @fclose($handle);

        // Upload webp file
        $webpFile = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/image.webp'), 'image/webp', 'image.webp'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        self::$cachedBucketFile[$cacheKey] = [
            'bucketId' => $bucketId,
            'fileId' => $file['body']['$id'],
            'largeFileId' => $largeFile['body']['$id'] ?? '',
            'largeBucketId' => $bucket2['body']['$id'],
            'webpFileId' => $webpFile['body']['$id']
        ];

        return self::$cachedBucketFile[$cacheKey];
    }

    /**
     * Helper method to set up zstd compression bucket for tests.
     * Uses static caching to avoid recreating resources.
     */
    protected function setupZstdCompressionBucket(): array
    {
        $cacheKey = $this->getProject()['$id'];

        if (!empty(self::$cachedZstdBucket[$cacheKey])) {
            return self::$cachedZstdBucket[$cacheKey];
        }

        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket',
            'fileSecurity' => true,
            'maximumFileSize' => 2000000, //2MB
            'allowedFileExtensions' => ["jpg", "png"],
            'compression' => 'zstd',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        self::$cachedZstdBucket[$cacheKey] = ['bucketId' => $bucket['body']['$id']];

        return self::$cachedZstdBucket[$cacheKey];
    }

    #[Group('fileTokens')]
    public function testCreateBucketFile(): void
    {
        /**
         * Test for SUCCESS
         */
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket',
            'fileSecurity' => true,
            'maximumFileSize' => 2000000, //2MB
            'allowedFileExtensions' => ['jpg', 'png', 'jfif', 'webp'],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucket['body']['$id']);

        $bucketId = $bucket['body']['$id'];

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);
        $this->assertEquals(201, $file['headers']['status-code']);
        $this->assertNotEmpty($file['body']['$id']);
        $dateValidator = new DatetimeValidator();
        $this->assertEquals(true, $dateValidator->isValid($file['body']['$createdAt']));
        $this->assertEquals('logo.png', $file['body']['name']);
        $this->assertEquals('image/png', $file['body']['mimeType']);
        $this->assertEquals(47218, $file['body']['sizeOriginal']);
        $this->assertTrue(md5_file(realpath(__DIR__ . '/../../../resources/logo.png')) == $file['body']['signature']);
        /**
         * Test for Large File above 20MB
         * This should also validate the test for when Bucket encryption
         * is disabled as we are using same test
         */
        $bucket2 = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket 2',
            'fileSecurity' => true,
            'permissions' => [
                Permission::create(Role::any()),
            ],
        ]);
        $this->assertEquals(201, $bucket2['headers']['status-code']);
        $this->assertNotEmpty($bucket2['body']['$id']);

        /**
         * Chunked Upload
         */

        $source = __DIR__ . "/../../../resources/disk-a/large-file.mp4";
        $totalSize = \filesize($source);
        $chunkSize = 5 * 1024 * 1024;
        $handle = @fopen($source, "rb");
        $fileId = 'unique()';
        $mimeType = mime_content_type($source);
        $counter = 0;
        $size = filesize($source);
        $headers = [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id']
        ];
        $id = '';
        $largeFile = null;
        while (!feof($handle)) {
            $curlFile = new \CURLFile('data://' . $mimeType . ';base64,' . base64_encode(@fread($handle, $chunkSize)), $mimeType, 'large-file.mp4');
            $headers['content-range'] = 'bytes ' . ($counter * $chunkSize) . '-' . min(((($counter * $chunkSize) + $chunkSize) - 1), $size - 1) . '/' . $size;
            if (!empty($id)) {
                $headers['x-appwrite-id'] = $id;
            }
            $largeFile = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucket2['body']['$id'] . '/files', array_merge($headers, $this->getHeaders()), [
                'fileId' => $fileId,
                'file' => $curlFile,
                'permissions' => [
                    Permission::read(Role::any())
                ],
            ]);
            $counter++;
            $id = $largeFile['body']['$id'];
        }
        @fclose($handle);

        $this->assertEquals(201, $largeFile['headers']['status-code']);
        $this->assertNotEmpty($largeFile['body']['$id']);
        $this->assertEquals(true, $dateValidator->isValid($largeFile['body']['$createdAt']));
        $this->assertEquals('large-file.mp4', $largeFile['body']['name']);
        $this->assertEquals('video/mp4', $largeFile['body']['mimeType']);
        $this->assertEquals($totalSize, $largeFile['body']['sizeOriginal']);
        $this->assertEquals(md5_file(realpath(__DIR__ . '/../../../resources/disk-a/large-file.mp4')), $largeFile['body']['signature']); // should validate that the file is not encrypted

        /**
         * Failure
         * Test for Chunk above 10MB
         */
        $source = __DIR__ . "/../../../resources/disk-a/large-file.mp4";
        $totalSize = \filesize($source);
        $chunkSize = 12 * 1024 * 1024;
        $handle = @fopen($source, "rb");
        $fileId = 'unique()';
        $mimeType = mime_content_type($source);
        $counter = 0;
        $size = filesize($source);
        $headers = [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id']
        ];
        $id = '';
        $curlFile = new \CURLFile('data://' . $mimeType . ';base64,' . base64_encode(@fread($handle, $chunkSize)), $mimeType, 'large-file.mp4');
        $headers['content-range'] = 'bytes ' . ($counter * $chunkSize) . '-' . min(((($counter * $chunkSize) + $chunkSize) - 1), $size - 1) . '/' . $size;
        $res = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucket2['body']['$id'] . '/files', $this->getHeaders(), [
            'fileId' => $fileId,
            'file' => $curlFile,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);
        @fclose($handle);

        $this->assertEquals(413, $res['headers']['status-code']);


        /**
         * Test for FAILURE unknown Bucket
         */

        $res = $this->client->call(Client::METHOD_POST, '/storage/buckets/empty/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);
        $this->assertEquals(404, $res['headers']['status-code']);

        /**
         * Test for FAILURE file above bucket's file size limit
         */

        $res = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/disk-b/kitten-1.png'), 'image/png', 'kitten-1.png'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $this->assertEquals(400, $res['headers']['status-code']);
        $this->assertEquals('File size not allowed', $res['body']['message']);

        /**
         * Test for FAILURE unsupported bucket file extension
         */

        $res = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/disk-a/kitten-3.gif'), 'image/gif', 'kitten-3.gif'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $this->assertEquals(400, $res['headers']['status-code']);
        $this->assertEquals('File extension not allowed', $res['body']['message']);

        /**
         * Test for FAILURE create bucket with too high limit (bigger then _APP_STORAGE_LIMIT)
         */
        $failedBucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket 2',
            'fileSecurity' => true,
            'maximumFileSize' => 6000000001,
            'allowedFileExtensions' => ["jpg", "png"],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $this->assertEquals(400, $failedBucket['headers']['status-code']);

        /**
         * Test for FAILURE set x-appwrite-id to unique()
         */
        $source = realpath(__DIR__ . '/../../../resources/logo.png');
        $totalSize = \filesize($source);
        $res = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'content-range' => 'bytes 0-' . $size . '/' . $size,
            'x-appwrite-id' => 'unique()',
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new CURLFile($source, 'image/png', 'logo.png'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $this->assertEquals(400, $res['headers']['status-code']);
        $this->assertEquals(Exception::STORAGE_INVALID_APPWRITE_ID, $res['body']['type']);

        /**
         * Test for SUCCESS - Upload and view webp image
         */
        $webpFile = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/image.webp'), 'image/webp', 'image.webp'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);
        $this->assertEquals(201, $webpFile['headers']['status-code']);
        $this->assertNotEmpty($webpFile['body']['$id']);
        $this->assertEquals('image.webp', $webpFile['body']['name']);
        $this->assertEquals('image/webp', $webpFile['body']['mimeType']);

        $webpFileId = $webpFile['body']['$id'];

        // View webp file
        $webpView = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $webpFileId . '/view', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $webpView['headers']['status-code']);
        $this->assertEquals('image/webp', $webpView['headers']['content-type']);
        $this->assertNotEmpty($webpView['body']);
    }

    public function testCreateBucketFileZstdCompression(): void
    {
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket',
            'fileSecurity' => true,
            'maximumFileSize' => 2000000, //2MB
            'allowedFileExtensions' => ["jpg", "png"],
            'compression' => 'zstd',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucket['body']['$id']);
        $this->assertEquals('zstd', $bucket['body']['compression']);

        $bucketId = $bucket['body']['$id'];

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);
        $this->assertEquals(201, $file['headers']['status-code']);
        $this->assertNotEmpty($file['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($file['body']['$createdAt']));
        $this->assertEquals('logo.png', $file['body']['name']);
        $this->assertEquals('image/png', $file['body']['mimeType']);
        $this->assertEquals(47218, $file['body']['sizeOriginal']);
        $this->assertTrue(md5_file(realpath(__DIR__ . '/../../../resources/logo.png')) == $file['body']['signature']);
    }

    public function testCreateBucketFileNoCollidingId(): void
    {
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket',
            'maximumFileSize' => 2000000, //2MB
            'allowedFileExtensions' => ["jpg", "png"],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucket['body']['$id']);

        $bucketId = $bucket['body']['$id'];

        $fileId = ID::unique();

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => $fileId,
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
        ]);

        $this->assertEquals(201, $file['headers']['status-code']);
        $this->assertEquals($fileId, $file['body']['$id']);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => $fileId,
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/file.png'), 'image/png', 'file.png'),
        ]);

        $this->assertEquals(409, $file['headers']['status-code']);
    }

    public function testListBucketFiles(): void
    {
        $data = $this->setupBucketFile();

        /**
         * Test for SUCCESS
         */
        $files = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $data['bucketId'] . '/files', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(200, $files['headers']['status-code']);
        $this->assertGreaterThan(0, $files['body']['total']);
        $this->assertGreaterThan(0, count($files['body']['files']));

        /**
         * Test for SUCCESS with total=false
         */
        $filesWithIncludeTotalFalse = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $data['bucketId'] . '/files', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'total' => false
        ]);

        $this->assertEquals(200, $filesWithIncludeTotalFalse['headers']['status-code']);
        $this->assertIsArray($filesWithIncludeTotalFalse['body']);
        $this->assertIsArray($filesWithIncludeTotalFalse['body']['files']);
        $this->assertIsInt($filesWithIncludeTotalFalse['body']['total']);
        $this->assertEquals(0, $filesWithIncludeTotalFalse['body']['total']);
        $this->assertGreaterThan(0, count($filesWithIncludeTotalFalse['body']['files']));

        $files = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $data['bucketId'] . '/files', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::limit(1)->toString(),
            ],
        ]);
        $this->assertEquals(200, $files['headers']['status-code']);
        $this->assertEquals(1, count($files['body']['files']));

        $files = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $data['bucketId'] . '/files', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::offset(1)->toString(),
            ],
        ]);
        $this->assertEquals(200, $files['headers']['status-code']);
        $this->assertEquals(1, count($files['body']['files']));

        $files = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $data['bucketId'] . '/files', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('mimeType', ['image/png'])->toString(),
            ],
        ]);
        $this->assertEquals(200, $files['headers']['status-code']);
        $this->assertEquals(1, count($files['body']['files']));

        $files = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $data['bucketId'] . '/files', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('mimeType', ['image/jpeg'])->toString(),
            ],
        ]);
        $this->assertEquals(200, $files['headers']['status-code']);
        $this->assertEquals(0, count($files['body']['files']));

        /**
         * Test for FAILURE unknown Bucket
         */

        $files = $this->client->call(Client::METHOD_GET, '/storage/buckets/empty/files', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(404, $files['headers']['status-code']);
    }

    public function testGetBucketFile(): void
    {
        $data = $this->setupBucketFile();
        $bucketId = $data['bucketId'];
        /**
         * Test for SUCCESS
         */
        $file1 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $file1['headers']['status-code']);
        $this->assertNotEmpty($file1['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($file1['body']['$createdAt']));
        $this->assertEquals('logo.png', $file1['body']['name']);
        $this->assertEquals('image/png', $file1['body']['mimeType']);
        $this->assertEquals(47218, $file1['body']['sizeOriginal']);
        $this->assertIsArray($file1['body']['$permissions']);
        $this->assertCount(3, $file1['body']['$permissions']);

        $file2 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $data['fileId'] . '/preview', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $file2['headers']['status-code']);
        $this->assertEquals('image/png', $file2['headers']['content-type']);
        $this->assertNotEmpty($file2['body']);

        // upload JXL file for preview
        $fileJfif = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/disk-a/preview-test.jfif'), 'image/jxl', 'preview-test.jfif'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);
        $this->assertEquals(201, $fileJfif['headers']['status-code']);
        $this->assertNotEmpty($fileJfif['body']['$id']);

        // TEST preview JXL
        $preview = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileJfif['body']['$id'] . '/preview', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $preview['headers']['status-code']);
        $this->assertEquals('image/jpeg', $preview['headers']['content-type']);
        $this->assertNotEmpty($preview['body']);

        //new image preview features
        $file3 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $data['fileId'] . '/preview', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'width' => 300,
            'height' => 100,
            'borderRadius' => '50',
            'opacity' => '0.5',
            'output' => 'png',
            'rotation' => '45',
        ]);

        $this->assertEquals(200, $file3['headers']['status-code']);
        $this->assertEquals('image/png', $file3['headers']['content-type']);
        $this->assertNotEmpty($file3['body']);

        $image = new \Imagick();
        $image->readImageBlob($file3['body']);
        $original = new \Imagick(__DIR__ . '/../../../resources/logo-after.png');

        $this->assertEquals($image->getImageWidth(), $original->getImageWidth());
        $this->assertEquals($image->getImageHeight(), $original->getImageHeight());
        $this->assertEquals('PNG', $image->getImageFormat());

        $file4 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $data['fileId'] . '/preview', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'width' => 200,
            'height' => 80,
            'borderWidth' => '5',
            'borderColor' => 'ff0000',
            'output' => 'jpg',
        ]);

        $this->assertEquals(200, $file4['headers']['status-code']);
        $this->assertEquals('image/jpeg', $file4['headers']['content-type']);
        $this->assertNotEmpty($file4['body']);

        $image = new \Imagick();
        $image->readImageBlob($file4['body']);
        $original = new \Imagick(__DIR__ . '/../../../resources/logo-after.jpg');

        $this->assertEquals($image->getImageWidth(), $original->getImageWidth());
        $this->assertEquals($image->getImageHeight(), $original->getImageHeight());
        $this->assertEquals('JPEG', $image->getImageFormat());

        $file5 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $data['fileId'] . '/download', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $file5['headers']['status-code']);
        $this->assertEquals('attachment; filename="logo.png"', $file5['headers']['content-disposition']);
        $this->assertEquals('image/png', $file5['headers']['content-type']);
        $this->assertNotEmpty($file5['body']);

        // Test ranged download
        $file51 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $data['fileId'] . '/download', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'Range' => 'bytes=0-99',
        ], $this->getHeaders()));

        $path = __DIR__ . '/../../../resources/logo.png';
        $originalChunk = \file_get_contents($path, false, null, 0, 100);

        $this->assertEquals(206, $file51['headers']['status-code']);
        $this->assertEquals('attachment; filename="logo.png"', $file51['headers']['content-disposition']);
        $this->assertEquals('image/png', $file51['headers']['content-type']);
        $this->assertNotEmpty($file51['body']);
        $this->assertEquals($originalChunk, $file51['body']);

        // Test ranged download - with invalid range
        $file52 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $data['fileId'] . '/download', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'Range' => 'bytes=0-',
        ], $this->getHeaders()));

        $this->assertEquals(206, $file52['headers']['status-code']);

        // Test ranged download - with invalid range
        $file53 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $data['fileId'] . '/download', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'Range' => 'bytes=988',
        ], $this->getHeaders()));

        $this->assertEquals(416, $file53['headers']['status-code']);

        // Test ranged download - with invalid range
        $file54 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $data['fileId'] . '/download', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'Range' => 'bytes=-988',
        ], $this->getHeaders()));

        $this->assertEquals(416, $file54['headers']['status-code']);

        $file6 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $data['fileId'] . '/view', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $file6['headers']['status-code']);
        $this->assertEquals('image/png', $file6['headers']['content-type']);
        $this->assertNotEmpty($file6['body']);

        // Test for negative angle values in fileGetPreview
        $file7 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $data['fileId'] . '/preview', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'width' => 300,
            'height' => 100,
            'borderRadius' => '50',
            'opacity' => '0.5',
            'output' => 'png',
            'rotation' => '-315',
        ]);

        $this->assertEquals(200, $file7['headers']['status-code']);
        $this->assertEquals('image/png', $file7['headers']['content-type']);
        $this->assertNotEmpty($file7['body']);

        $image = new \Imagick();
        $image->readImageBlob($file7['body']);
        $original = new \Imagick(__DIR__ . '/../../../resources/logo-after.png');

        $this->assertEquals($image->getImageWidth(), $original->getImageWidth());
        $this->assertEquals($image->getImageHeight(), $original->getImageHeight());
        $this->assertEquals('PNG', $image->getImageFormat());

        /**
         * Test large files decompress successfully
         */
        $file7 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $data['largeBucketId'] . '/files/' . $data['largeFileId'] . '/download', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $fileData = $file7['body'];
        $this->assertEquals(200, $file7['headers']['status-code']);
        $this->assertEquals('attachment; filename="large-file.mp4"', $file7['headers']['content-disposition']);
        $this->assertEquals('video/mp4', $file7['headers']['content-type']);
        $this->assertNotEmpty($fileData);
        $this->assertEquals(md5_file(realpath(__DIR__ . '/../../../resources/disk-a/large-file.mp4')), md5($fileData)); // validate the file is downloaded correctly

        /**
         * Test for FAILURE unknown Bucket
         */

        $file8 = $this->client->call(Client::METHOD_GET, '/storage/buckets/empty/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'limit' => 2
        ]);

        $this->assertEquals(404, $file8['headers']['status-code']);
    }

    public function testFilePreviewCache(): void
    {
        $data = $this->setupBucketFile();
        $bucketId = $data['bucketId'];

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::custom('testcache'),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);
        $this->assertEquals(201, $file['headers']['status-code']);
        $this->assertNotEmpty($file['body']['$id']);

        $fileId = $file['body']['$id'];

        //get image preview
        $file3 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'width' => 300,
            'height' => 100,
            'borderRadius' => '50',
            'opacity' => '0.5',
            'output' => 'png',
            'rotation' => '45',
        ]);

        $this->assertEquals(200, $file3['headers']['status-code']);
        $this->assertEquals('image/png', $file3['headers']['content-type']);
        $this->assertNotEmpty($file3['body']);

        $imageBefore = new \Imagick();
        $imageBefore->readImageBlob($file3['body']);

        $file = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $data['bucketId'] . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $file['headers']['status-code']);
        $this->assertEmpty($file['body']);

        $this->assertEventually(function () use ($data, $fileId) {
            $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $data['bucketId'] . '/files/' . $fileId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));
            $this->assertEquals(404, $file['headers']['status-code']);
        }, 10_000, 500);

        //upload again using the same ID
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::custom('testcache'),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/disk-b/kitten-2.png'), 'image/png', 'logo.png'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);
        $this->assertEquals(201, $file['headers']['status-code']);
        $this->assertNotEmpty($file['body']['$id']);

        //get image preview after
        $file3 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'width' => 300,
            'height' => 100,
            'borderRadius' => '50',
            'opacity' => '0.5',
            'output' => 'png',
            'rotation' => '45',
        ]);

        $this->assertEquals(200, $file3['headers']['status-code']);
        $this->assertEquals('image/png', $file3['headers']['content-type']);
        $this->assertNotEmpty($file3['body']);

        $imageAfter = new \Imagick();
        $imageAfter->readImageBlob($file3['body']);

        $this->assertNotEquals($imageBefore->getImageBlob(), $imageAfter->getImageBlob());
    }

    public function testFilePreviewCacheControlOnCacheHit(): void
    {
        $data = $this->setupBucketFile();
        $bucketId = $data['bucketId'];
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);
        $this->assertEquals(201, $file['headers']['status-code']);
        $this->assertNotEmpty($file['body']['$id']);

        $fileId = $file['body']['$id'];
        $params = [
            'width' => 123,
            'height' => 45,
            'output' => 'png',
            'quality' => 80,
        ];
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());

        $preview = $this->client->call(
            Client::METHOD_GET,
            '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview',
            $headers,
            $params
        );

        $this->assertEquals(200, $preview['headers']['status-code']);
        $this->assertEquals('image/png', $preview['headers']['content-type']);
        $this->assertEquals('private, max-age=2592000', $preview['headers']['cache-control']);
        $this->assertEquals('miss', $preview['headers']['x-appwrite-cache']);
        $this->assertNotEmpty($preview['body']);

        $cachedPreview = [];
        $this->assertEventually(function () use (&$cachedPreview, $bucketId, $fileId, $headers, $params) {
            $cachedPreview = $this->client->call(
                Client::METHOD_GET,
                '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview',
                $headers,
                $params
            );

            $this->assertEquals('hit', $cachedPreview['headers']['x-appwrite-cache']);
        });

        $this->assertEquals(200, $cachedPreview['headers']['status-code']);
        $this->assertEquals('image/png', $cachedPreview['headers']['content-type']);
        $this->assertStringStartsWith('private, max-age=', $cachedPreview['headers']['cache-control']);
        $this->assertEquals($preview['body'], $cachedPreview['body']);
    }

    public function testFilePreviewZstdCompression(): void
    {
        $data = $this->setupZstdCompressionBucket();
        $bucketId = $data['bucketId'];

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);
        $this->assertEquals(201, $file['headers']['status-code']);
        $this->assertNotEmpty($file['body']['$id']);

        $fileId = $file['body']['$id'];

        //get image preview
        $file3 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'width' => 300,
            'height' => 100,
            'borderRadius' => '50',
            'opacity' => '0.5',
            'output' => 'png',
            'rotation' => '45',
        ]);

        $this->assertEquals(200, $file3['headers']['status-code']);
        $this->assertEquals('image/png', $file3['headers']['content-type']);
        $this->assertNotEmpty($file3['body']);
    }

    public function testUpdateBucketFile(): void
    {
        $data = $this->setupBucketFile();

        /**
         * Test for SUCCESS
         */
        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $data['bucketId'] . '/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'logo_updated.png',
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);
        $this->assertNotEmpty($file['body']['$id']);
        $dateValidator = new DatetimeValidator();
        $this->assertEquals(true, $dateValidator->isValid($file['body']['$createdAt']));
        $this->assertEquals('logo_updated.png', $file['body']['name']);
        $this->assertEquals('image/png', $file['body']['mimeType']);
        $this->assertEquals(47218, $file['body']['sizeOriginal']);
        //$this->assertEquals(54944, $file['body']['sizeActual']);
        //$this->assertEquals('gzip', $file['body']['algorithm']);
        //$this->assertEquals('1', $file['body']['fileOpenSSLVersion']);
        //$this->assertEquals('aes-128-gcm', $file['body']['fileOpenSSLCipher']);
        //$this->assertNotEmpty($file['body']['fileOpenSSLTag']);
        //$this->assertNotEmpty($file['body']['fileOpenSSLIV']);
        $this->assertIsArray($file['body']['$permissions']);
        $this->assertCount(3, $file['body']['$permissions']);

        /**
         * Test for FAILURE unknown Bucket
         */

        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/empty/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $this->assertEquals(404, $file['headers']['status-code']);
    }

    public function testFilePreviewAvifPublic(): void
    {
        $data = $this->setupBucketFile();
        $bucketId = $data['bucketId'];
        $fileId = $data['fileId'];
        $projectId = $this->getProject()['$id'];

        // Matches the customer's URL pattern: no headers, project + output in query string only
        $preview = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview', [
            'content-type' => 'application/json',
        ], [
            'project' => $projectId,
            'width' => 1080,
            'quality' => 40,
            'output' => 'avif',
        ]);

        $this->assertEquals(200, $preview['headers']['status-code']);
        $this->assertEquals('image/avif', $preview['headers']['content-type']);
        $this->assertNotEmpty($preview['body']);
    }

    public function testFilePreview(): void
    {
        $data = $this->setupBucketFile();
        $bucketId = $data['bucketId'];
        $fileId = $data['fileId'];

        // Preview PNG as webp
        $preview = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'width' => 300,
            'height' => 300,
            'output' => 'webp',
        ]);

        $this->assertEquals(200, $preview['headers']['status-code']);
        $this->assertEquals('image/webp', $preview['headers']['content-type']);
        $this->assertNotEmpty($preview['body']);

        // Preview PNG as avif
        $avifPreview = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'width' => 1080,
            'quality' => 40,
            'output' => 'avif',
        ]);

        $this->assertEquals(200, $avifPreview['headers']['status-code']);
        $this->assertEquals('image/avif', $avifPreview['headers']['content-type']);
        $this->assertNotEmpty($avifPreview['body']);

        // Preview JPEG as avif
        $jpegFile = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/disk-a/kitten-1.jpg'), 'image/jpeg', 'kitten-1.jpg'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $jpegFile['headers']['status-code']);

        $avifFromJpeg = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $jpegFile['body']['$id'] . '/preview', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'width' => 1080,
            'quality' => 40,
            'output' => 'avif',
        ]);

        $this->assertEquals(200, $avifFromJpeg['headers']['status-code']);
        $this->assertEquals('image/avif', $avifFromJpeg['headers']['content-type']);
        $this->assertNotEmpty($avifFromJpeg['body']);
    }

    public function testDeletePartiallyUploadedFile(): void
    {
        // Create a bucket for this test
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket Partial Upload',
            'fileSecurity' => true,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $bucket['headers']['status-code']);
        $bucketId = $bucket['body']['$id'];

        // Simulate a partial (cancelled) chunked upload by sending only the first chunk
        $source = __DIR__ . "/../../../resources/disk-a/large-file.mp4";
        $totalSize = \filesize($source);
        $chunkSize = 5 * 1024 * 1024; // 5MB chunks
        $mimeType = mime_content_type($source);

        $handle = fopen($source, "rb");
        $this->assertNotFalse($handle, "Could not open test resource: $source");
        $chunkData = fread($handle, $chunkSize);
        fclose($handle);

        $curlFile = new \CURLFile(
            'data://' . $mimeType . ';base64,' . base64_encode($chunkData),
            $mimeType,
            'large-file.mp4'
        );

        // Send only the first chunk (bytes 0 to chunkSize-1 of totalSize)
        $end = min($chunkSize - 1, $totalSize - 1);
        $partialFile = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'content-range' => 'bytes 0-' . $end . '/' . $totalSize,
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => $curlFile,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $partialFile['headers']['status-code']);
        $fileId = $partialFile['body']['$id'];

        // Confirm the file is in a pending state (chunksTotal > chunksUploaded)
        $this->assertGreaterThan(
            $partialFile['body']['chunksUploaded'],
            $partialFile['body']['chunksTotal'],
            'File should be partially uploaded (pending)'
        );

        // Delete the partially-uploaded (pending) file — this should succeed
        $deleteResponse = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $deleteResponse['headers']['status-code']);
        $this->assertEmpty($deleteResponse['body']);

        // Confirm the file is gone
        $getResponse = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $getResponse['headers']['status-code']);

        // Clean up the test bucket
        $deleteBucketResponse = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(204, $deleteBucketResponse['headers']['status-code']);
    }

    public function testCreateBucketFileOutOfOrder(): void
    {
        // Create a bucket for this test
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket Out of Order Upload',
            'fileSecurity' => true,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $bucket['headers']['status-code']);
        $bucketId = $bucket['body']['$id'];

        // Prepare a file that spans at least 3 chunks
        $source = __DIR__ . "/../../../resources/disk-a/large-file.mp4";
        $totalSize = \filesize($source);
        $chunkSize = 5 * 1024 * 1024; // 5MB chunks
        $mimeType = mime_content_type($source);
        $chunksTotal = (int) ceil($totalSize / $chunkSize);

        // Read all chunks into memory
        $handle = fopen($source, "rb");
        $this->assertNotFalse($handle, "Could not open test resource: $source");
        $chunks = [];
        for ($i = 0; $i < $chunksTotal; $i++) {
            $start = $i * $chunkSize;
            $end = min($start + $chunkSize, $totalSize);
            $length = $end - $start;
            $data = fread($handle, $length);
            $chunks[] = [
                'data' => $data,
                'start' => $start,
                'end' => $end - 1,
                'index' => $i,
            ];
        }
        fclose($handle);

        // We need at least 3 chunks for a meaningful out-of-order test
        $this->assertGreaterThanOrEqual(3, count($chunks), 'Test file must span at least 3 chunks');

        // Upload chunks in out-of-order sequence: last chunk first, then first, then middle
        $uploadOrder = [count($chunks) - 1, 0, 1]; // last, first, second (for 3+ chunks)
        $fileId = ID::unique();
        $id = '';
        $uploadedFile = null;

        foreach ($uploadOrder as $chunkIndex) {
            $chunk = $chunks[$chunkIndex];
            $curlFile = new \CURLFile(
                'data://' . $mimeType . ';base64,' . base64_encode($chunk['data']),
                $mimeType,
                'large-file.mp4'
            );

            $headers = [
                'content-type' => 'multipart/form-data',
                'x-appwrite-project' => $this->getProject()['$id'],
                'content-range' => 'bytes ' . $chunk['start'] . '-' . $chunk['end'] . '/' . $totalSize,
            ];

            if (!empty($id)) {
                $headers['x-appwrite-id'] = $id;
            }

            $uploadedFile = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge($headers, $this->getHeaders()), [
                'fileId' => $fileId,
                'file' => $curlFile,
                'permissions' => [
                    Permission::read(Role::any()),
                ],
            ]);

            $this->assertEquals(201, $uploadedFile['headers']['status-code']);
            $id = $uploadedFile['body']['$id'];
        }

        // Upload remaining chunks in any order to complete the file
        $remainingChunks = [];
        for ($i = 2; $i < count($chunks) - 1; $i++) {
            $remainingChunks[] = $i;
        }
        // Shuffle remaining chunks for extra randomness
        shuffle($remainingChunks);

        foreach ($remainingChunks as $chunkIndex) {
            $chunk = $chunks[$chunkIndex];
            $curlFile = new \CURLFile(
                'data://' . $mimeType . ';base64,' . base64_encode($chunk['data']),
                $mimeType,
                'large-file.mp4'
            );

            $headers = [
                'content-type' => 'multipart/form-data',
                'x-appwrite-project' => $this->getProject()['$id'],
                'content-range' => 'bytes ' . $chunk['start'] . '-' . $chunk['end'] . '/' . $totalSize,
                'x-appwrite-id' => $id,
            ];

            $uploadedFile = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge($headers, $this->getHeaders()), [
                'fileId' => $fileId,
                'file' => $curlFile,
                'permissions' => [
                    Permission::read(Role::any()),
                ],
            ]);

            $this->assertEquals(201, $uploadedFile['headers']['status-code']);
        }

        // Verify the final upload response indicates completion
        $this->assertEquals($chunksTotal, $uploadedFile['body']['chunksTotal']);
        $this->assertEquals($chunksTotal, $uploadedFile['body']['chunksUploaded']);

        // Verify the file can be downloaded and matches the original
        $download = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $id . '/download', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $download['headers']['status-code']);
        $this->assertEquals($totalSize, strlen($download['body']));
        $this->assertEquals(md5_file($source), md5($download['body']));

        // Clean up
        $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
    }

    public function testCreateBucketFileParallelChunksLargeFile(): void
    {
        $totalSize = 20 * 1024 * 1024;
        $chunkSize = 5 * 1024 * 1024;
        $chunksTotal = (int) ceil($totalSize / $chunkSize);

        $this->assertGreaterThanOrEqual(4, $chunksTotal, 'Test file must span at least 4 chunks');

        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket Parallel Chunk Upload',
            'fileSecurity' => true,
            'maximumFileSize' => $totalSize,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $bucket['headers']['status-code']);

        $bucketId = $bucket['body']['$id'];
        $fileId = ID::unique();
        $tmpDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'appwrite-parallel-upload-' . $fileId;
        $source = $tmpDirectory . DIRECTORY_SEPARATOR . 'large-parallel-upload.bin';

        mkdir($tmpDirectory);

        try {
            $handle = fopen($source, 'wb');
            $this->assertNotFalse($handle, 'Could not create test file');

            $remaining = $totalSize;
            $block = str_repeat(hash('sha256', $fileId, binary: true), 1024);
            while ($remaining > 0) {
                $bytes = substr($block, 0, min(strlen($block), $remaining));
                fwrite($handle, $bytes);
                $remaining -= strlen($bytes);
            }
            fclose($handle);

            $requests = [];

            $sourceHandle = fopen($source, 'rb');
            $this->assertNotFalse($sourceHandle, 'Could not open test file');

            for ($i = 0; $i < $chunksTotal; $i++) {
                $start = $i * $chunkSize;
                $end = min($start + $chunkSize, $totalSize) - 1;
                $length = $end - $start + 1;
                $chunkPath = $tmpDirectory . DIRECTORY_SEPARATOR . 'chunk-' . $i . '.part';

                fseek($sourceHandle, $start);
                file_put_contents($chunkPath, fread($sourceHandle, $length));

                $requests[] = [
                    'headers' => [
                        'x-appwrite-project' => $this->getProject()['$id'],
                        'x-appwrite-key' => $this->getProject()['apiKey'],
                        'content-range' => 'bytes ' . $start . '-' . $end . '/' . $totalSize,
                    ],
                    'chunkPath' => $chunkPath,
                ];
            }
            fclose($sourceHandle);

            $responses = [];
            $endpoint = parse_url($this->client->getEndpoint());
            $scheme = $endpoint['scheme'] ?? 'http';
            $host = $endpoint['host'] ?? 'appwrite';
            $port = $endpoint['port'] ?? ($scheme === 'https' ? 443 : 80);
            $basePath = rtrim($endpoint['path'] ?? '', '/');

            \Swoole\Coroutine\run(function () use ($basePath, $bucketId, $fileId, $host, $port, $requests, $scheme, &$responses): void {
                $wg = new \Swoole\Coroutine\WaitGroup();

                foreach ($requests as $index => $request) {
                    $wg->add();
                    \Swoole\Coroutine::create(function () use ($basePath, $bucketId, $fileId, $host, $index, $port, $request, &$responses, $scheme, $wg): void {
                        try {
                            for ($attempt = 0; $attempt < 3; $attempt++) {
                                $client = new \Swoole\Coroutine\Http\Client($host, (int) $port, $scheme === 'https');
                                $client->set([
                                    'timeout' => 300,
                                    'ssl_verify_peer' => false,
                                    'ssl_verify_host' => false,
                                ]);
                                $client->setHeaders($request['headers']);
                                $client->setMethod(Client::METHOD_POST);
                                $client->setData([
                                    'fileId' => $fileId,
                                    'permissions[0]' => Permission::read(Role::any()),
                                    'permissions[1]' => Permission::delete(Role::any()),
                                ]);
                                $client->addFile($request['chunkPath'], 'file', 'application/octet-stream', 'large-parallel-upload.bin');
                                $client->execute($basePath . '/storage/buckets/' . $bucketId . '/files');

                                $responses[$index] = [
                                    'body' => $client->body,
                                    'error' => $client->errMsg,
                                    'headers' => $client->headers ?? [],
                                    'statusCode' => $client->statusCode,
                                ];

                                $client->close();

                                if ($responses[$index]['statusCode'] !== 429) {
                                    break;
                                }

                                $retryAfter = (float) ($responses[$index]['headers']['retry-after'] ?? 0.1);
                                \Swoole\Coroutine::sleep(max($retryAfter, 0.1));
                            }
                        } finally {
                            $wg->done();
                        }
                    });
                }

                $wg->wait();
            });

            ksort($responses);

            foreach ($responses as $response) {
                $this->assertSame('', $response['error']);
                $this->assertContains($response['statusCode'], [200, 201], (string) $response['body']);
            }

            $uploadedFile = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));

            $this->assertEquals(200, $uploadedFile['headers']['status-code']);
            $this->assertEquals($chunksTotal, $uploadedFile['body']['chunksTotal']);
            $this->assertEquals($chunksTotal, $uploadedFile['body']['chunksUploaded']);

            $download = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/download', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));

            $this->assertEquals(200, $download['headers']['status-code']);
            $this->assertEquals($totalSize, strlen($download['body']));
            $this->assertEquals(hash_file('sha256', $source), hash('sha256', $download['body']));
        } finally {
            if (isset($bucketId)) {
                $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'x-appwrite-key' => $this->getProject()['apiKey'],
                ]));

                $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId, [
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'x-appwrite-key' => $this->getProject()['apiKey'],
                ]);
            }

            foreach (glob($tmpDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                unlink($file);
            }

            if (is_dir($tmpDirectory)) {
                rmdir($tmpDirectory);
            }
        }
    }

    public function testDeleteBucketFile(): void
    {
        // Create a fresh file just for deletion testing (not using cache since we delete it)
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket Delete',
            'fileSecurity' => true,
            'maximumFileSize' => 2000000,
            'allowedFileExtensions' => ['jpg', 'png'],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $bucketId = $bucket['body']['$id'];

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $file['headers']['status-code']);

        // First update the file (to test that delete works after update)
        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId . '/files/' . $file['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'logo_updated.png',
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $this->assertEquals(200, $file['headers']['status-code']);

        $data = ['bucketId' => $bucketId, 'fileId' => $file['body']['$id']];
        /**
         * Test for SUCCESS
         */
        $file = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $data['bucketId'] . '/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $file['headers']['status-code']);
        $this->assertEmpty($file['body']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $data['bucketId'] . '/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $file['headers']['status-code']);
    }

    public function testBucketTotalSize(): void
    {
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket Size',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $bucket['headers']['status-code']);
        $bucketId = $bucket['body']['$id'];

        // bucket should have totalSize = 0 (no files)
        $emptyBucket = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $emptyBucket['headers']['status-code']);
        $this->assertArrayHasKey('totalSize', $emptyBucket['body']);
        $this->assertEquals(0, $emptyBucket['body']['totalSize']);

        // upload first file
        $file1 = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
        ]);

        $this->assertEquals(201, $file1['headers']['status-code']);

        // upload second file
        $file2 = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/image.webp'), 'image/webp', 'image.webp'),
        ]);

        $this->assertEquals(201, $file2['headers']['status-code']);

        $bucket = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $bucket['headers']['status-code']);
        $this->assertArrayHasKey('totalSize', $bucket['body']);
        $this->assertIsInt($bucket['body']['totalSize']);

        /* will always be 0 in tests because the worker runs hourly! */
        $this->assertGreaterThanOrEqual(0, $bucket['body']['totalSize']);
    }
}
