<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Storage;

use Ahc\Jwt\JWT;
use Appwrite\Extend\Exception;
use CURLFile;
use PHPUnit\Framework\Attributes\Group;
use Swoole\Coroutine;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Compression\Compression;
use Utopia\Console\Command;
use Utopia\Console\Console;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\DSN\DSN;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\S3;
use Utopia\System\System;

use function Swoole\Coroutine\run;

final class StorageCustomServerTest extends Scope
{
    use StorageBase;
    use ProjectCustom;
    use SideServer;

    private const string CACHE_OUTAGE_STATE = '/tmp/appwrite-response-cache-outage.json';

    /**
     * Seconds a request may take with the device down: a read and a copy, each a 3s
     * timeout retried once, and margin. A default client's 30s timeout can't fit in this.
     */
    private const int CACHE_OUTAGE_BUDGET = 15;

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

    public function testFilePreviewCacheStore(): void
    {
        $project = $this->getProject(true);
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $project['$id'],
            'x-appwrite-key' => $project['apiKey'],
        ];

        $connection = System::getEnv('_APP_CONNECTIONS_STORAGE_CACHE', '');
        $devices = $this->getCacheStores($project['$id']);
        $stored = fn (): int => \array_sum(\array_map(fn ($device) => \count($device->listFiles($device->getRoot())->files), $devices));

        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', $headers, [
            'bucketId' => Id::unique(),
            'name' => 'Cache store',
            'permissions' => [Permission::read(Role::any())],
        ]);
        $this->assertSame(201, $bucket['headers']['status-code']);

        $upload = function () use ($bucket, $headers): string {
            $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucket['body']['$id'] . '/files', ['content-type' => 'multipart/form-data'] + $headers, [
                'fileId' => Id::unique(),
                'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
            ]);
            $this->assertSame(201, $file['headers']['status-code']);

            return $file['body']['$id'];
        };
        $preview = fn (string $fileId): array => $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucket['body']['$id'] . '/files/' . $fileId . '/preview', $headers, ['width' => 77, 'output' => 'png']);

        $fileId = $upload();
        $this->assertSame('miss', $preview($fileId)['headers']['x-appwrite-cache']);
        $this->assertEventually(fn () => $this->assertSame('hit', $preview($fileId)['headers']['x-appwrite-cache']));

        // An emptied store refills, though the row is fresh and the signature unchanged.
        foreach ($devices as $device) {
            $device->delete($device->getRoot(), true);
        }
        $this->assertSame(0, $stored());
        $this->assertSame('miss', $preview($fileId)['headers']['x-appwrite-cache']);
        $this->assertEventually(fn () => $this->assertSame('hit', $preview($fileId)['headers']['x-appwrite-cache']));
        $this->assertSame(1, $stored());

        // At 100 percent the entry is on the device, not the filesystem.
        if ($connection !== '' && System::getEnv('_APP_STORAGE_CACHE_PERCENT', '0') === '100') {
            $this->assertCount(1, $devices[1]->listFiles($devices[1]->getRoot())->files);
        }

        // A purge clears where the API wrote.
        $delete = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucket['body']['$id'] . '/files/' . $fileId, $headers);
        $this->assertSame(204, $delete['headers']['status-code']);
        $this->assertEventually(fn () => $this->assertSame(0, $stored()), 30_000, 500);

        // So does deleting the project.
        $fileId = $upload();
        $this->assertSame('miss', $preview($fileId)['headers']['x-appwrite-cache']);
        $this->assertEventually(fn () => $this->assertSame('hit', $preview($fileId)['headers']['x-appwrite-cache']));
        $this->assertSame(1, $stored());

        $deleted = $this->client->call(Client::METHOD_DELETE, '/projects/' . $project['$id'], [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ]);
        $this->assertSame(204, $deleted['headers']['status-code']);
        $this->assertEventually(fn () => $this->assertSame(0, $stored()), 60_000, 500);
    }

    #[Group('responseCache')]
    public function testFilePreviewCacheSplitsKeysByPercent(): void
    {
        [$headers, $filesystem, $device] = $this->getCacheFixture();
        $percent = (int) System::getEnv('_APP_STORAGE_CACHE_PERCENT', '0');

        ['path' => $path, 'entries' => $entries] = $this->warmPreviewCache($headers, $filesystem, $device);
        $onDevice = $this->getCacheKeys($device);
        $onFilesystem = $this->getCacheKeys($filesystem);

        $this->assertCount(\count($entries), [...$onDevice, ...$onFilesystem]);
        foreach ($onDevice as $key) {
            $this->assertLessThan($percent, \crc32($key) % 100, $key . ' is on the device');
        }
        foreach ($onFilesystem as $key) {
            $this->assertGreaterThanOrEqual($percent, \crc32($key) % 100, $key . ' is on the filesystem');
        }
        if ($percent < 100) {
            $this->assertNotEmpty($onDevice);
            $this->assertNotEmpty($onFilesystem);
        }

        // Every key answers from the side it landed on, and stays there.
        foreach ($entries as $width => $entry) {
            $preview = $this->client->call(Client::METHOD_GET, $path, $headers, ['width' => $width, 'output' => 'png']);
            $this->assertSame('hit', $preview['headers']['x-appwrite-cache']);
            $this->assertSame($entry['image'], $this->getImageSignature($preview['body']));
        }
        $this->assertSame($onDevice, $this->getCacheKeys($device));
        $this->assertSame($onFilesystem, $this->getCacheKeys($filesystem));
    }

    #[Group('responseCache')]
    public function testFilePreviewCacheCopiesFilesystemEntryToDevice(): void
    {
        [$headers, $filesystem, $device] = $this->getCacheFixture();

        ['path' => $path, 'entries' => $entries] = $this->warmPreviewCache($headers, $filesystem, $device);
        $onDevice = $this->getCacheKeys($device);
        $this->assertNotEmpty($onDevice);

        // As if these keys were written before the percentage was raised over them.
        foreach ($onDevice as $key) {
            $this->copyCacheEntryToFilesystem($filesystem, $device, $key);
            $device->delete($device->getPath($key));
        }
        $this->assertSame([], $this->getCacheKeys($device));

        foreach ($entries as $width => $entry) {
            $preview = $this->client->call(Client::METHOD_GET, $path, $headers, ['width' => $width, 'output' => 'png']);
            $this->assertSame('hit', $preview['headers']['x-appwrite-cache'], 'served from the filesystem, not regenerated');
            $this->assertSame($entry['image'], $this->getImageSignature($preview['body']));
        }

        // Copied across with the same bytes, and the original kept so lowering the percentage finds it warm.
        $this->assertSame($onDevice, $this->getCacheKeys($device));
        $this->assertCount(\count($entries), $this->getCacheKeys($filesystem));
        foreach ($onDevice as $key) {
            $this->assertSame(\md5_file($filesystem->getPath($key)), \md5((string) $device->read($device->getPath($key))));
        }

        // The copy answers once the original is gone.
        foreach ($onDevice as $key) {
            $filesystem->delete($filesystem->getPath($key));
        }
        foreach ($entries as $width => $entry) {
            $preview = $this->client->call(Client::METHOD_GET, $path, $headers, ['width' => $width, 'output' => 'png']);
            $this->assertSame('hit', $preview['headers']['x-appwrite-cache']);
        }
    }

    #[Group('responseCache')]
    public function testFilePreviewCachePurgeClearsBothStores(): void
    {
        [$headers, $filesystem, $device] = $this->getCacheFixture();

        ['path' => $path] = $this->warmPreviewCache($headers, $filesystem, $device);
        foreach ($this->getCacheKeys($device) as $key) {
            $this->copyCacheEntryToFilesystem($filesystem, $device, $key);
        }
        $this->assertNotEmpty($this->getCacheKeys($device));
        $this->assertNotEmpty($this->getCacheKeys($filesystem));

        $delete = $this->client->call(Client::METHOD_DELETE, \dirname($path), $headers);
        $this->assertSame(204, $delete['headers']['status-code']);

        $this->assertEventually(function () use ($filesystem, $device): void {
            $this->assertSame([], $this->getCacheKeys($filesystem), 'the filesystem');
            $this->assertSame([], $this->getCacheKeys($device), 'the device');
        }, 30_000, 500);
    }

    #[Group('responseCache')]
    public function testProjectDeletionClearsBothCacheStores(): void
    {
        [$headers, $filesystem, $device] = $this->getCacheFixture();

        $this->warmPreviewCache($headers, $filesystem, $device);
        foreach ($this->getCacheKeys($device) as $key) {
            $this->copyCacheEntryToFilesystem($filesystem, $device, $key);
        }
        $this->assertNotEmpty($this->getCacheKeys($device));
        $this->assertNotEmpty($this->getCacheKeys($filesystem));

        $deleted = $this->client->call(Client::METHOD_DELETE, '/projects/' . $headers['x-appwrite-project'], [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ]);
        $this->assertSame(204, $deleted['headers']['status-code']);

        $this->assertEventually(function () use ($filesystem, $device): void {
            $this->assertSame([], $this->getCacheKeys($filesystem), 'the filesystem');
            $this->assertSame([], $this->getCacheKeys($device), 'the device');
        }, 60_000, 500);
    }

    /**
     * Leaves entries for the outage test to read after CI has taken the device down: the
     * test process can't, and once it's down they can no longer be written or found.
     */
    #[Group('responseCache')]
    public function testFilePreviewCachePrepareOutage(): void
    {
        [$headers, $filesystem, $device] = $this->getCacheFixture();

        ['path' => $path, 'entries' => $entries] = $this->warmPreviewCache($headers, $filesystem, $device);

        // One width with a copy on the filesystem for the outage to fall back to, one without.
        $widths = \array_keys(\array_filter($entries, fn (array $entry): bool => $entry['onDevice']));
        $this->assertGreaterThanOrEqual(2, \count($widths));
        [$copied, $uncopied] = $widths;
        $this->copyCacheEntryToFilesystem($filesystem, $device, $entries[$copied]['key']);

        $this->assertNotFalse(\file_put_contents(self::CACHE_OUTAGE_STATE, \json_encode([
            'headers' => $headers,
            'path' => $path,
            'entries' => $entries,
            'copied' => $copied,
            'uncopied' => $uncopied,
        ])));
    }

    /**
     * Runs twice in the Response Cache lane: with the device paused, so connections are
     * accepted and never answered, and with it stopped, so its name no longer resolves.
     */
    #[Group('responseCacheOutage')]
    public function testFilePreviewCacheSurvivesDeviceOutage(): void
    {
        $handle = \curl_init(System::getEnv('_APP_STORAGE_S3_ENDPOINT', ''));
        \curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
        if (System::getEnv('_APP_CONNECTIONS_STORAGE_CACHE', '') === '' || \curl_exec($handle) !== false) {
            $this->markTestSkipped('Needs the cache device to be down');
        }

        $state = \json_decode((string) \file_get_contents(self::CACHE_OUTAGE_STATE), true);
        ['headers' => $headers, 'path' => $path, 'entries' => $entries, 'copied' => $copied, 'uncopied' => $uncopied] = $state;
        $request = function (int $width) use ($headers, $path): array {
            $started = \microtime(true);
            $preview = $this->client->call(Client::METHOD_GET, $path, $headers, ['width' => $width, 'output' => 'png']);
            $this->assertSame(200, $preview['headers']['status-code']);
            $this->assertSame('image/png', $preview['headers']['content-type']);

            return [$preview, \microtime(true) - $started];
        };

        // On the filesystem side nothing waits on the device.
        $width = \array_key_first(\array_filter($entries, fn (array $entry): bool => ! $entry['onDevice']));
        [$preview, $elapsed] = $request($width);
        $this->assertSame('hit', $preview['headers']['x-appwrite-cache']);
        $this->assertSame($entries[$width]['image'], $this->getImageSignature($preview['body']));
        $this->assertLessThan(2, $elapsed);

        // On the device side a copy on the filesystem answers once the read has failed.
        [$preview, $elapsed] = $request($copied);
        $this->assertSame('hit', $preview['headers']['x-appwrite-cache']);
        $this->assertSame($entries[$copied]['image'], $this->getImageSignature($preview['body']));
        $this->assertLessThan(self::CACHE_OUTAGE_BUDGET, $elapsed);

        // With no copy the route regenerates.
        [$preview, $elapsed] = $request($uncopied);
        $this->assertSame('miss', $preview['headers']['x-appwrite-cache']);
        $this->assertLessThan(self::CACHE_OUTAGE_BUDGET, $elapsed);

        // Twice as many requests per worker as it has device connections: the rest get a miss from the
        // pool, not an error. They're all on one key, so each of them has the filesystem copy to answer with.
        $workers = \swoole_cpu_num() * (int) System::getEnv('_APP_WORKER_PER_CORE', '6');
        $started = \microtime(true);
        $previews = $this->client->callConcurrently(\array_fill(0, $workers * 8, [Client::METHOD_GET, $path, $headers, ['width' => $copied, 'output' => 'png']]));
        $elapsed = \microtime(true) - $started;
        foreach ($previews as $preview) {
            $this->assertSame(200, $preview['headers']['status-code']);
            $this->assertSame('hit', $preview['headers']['x-appwrite-cache']);
        }
        $this->assertLessThan(self::CACHE_OUTAGE_BUDGET, $elapsed);
    }

    /**
     * @return array<Device> The filesystem, then the device when one is configured
     */
    private function getCacheStores(string $projectId): array
    {
        $root = '/storage/cache/app-' . $projectId;
        $connection = System::getEnv('_APP_CONNECTIONS_STORAGE_CACHE', '');
        if ($connection === '') {
            return [new Local($root)];
        }

        $dsn = new DSN($connection);

        return [new Local($root), new S3(
            $dsn->getPath() . $root,
            $dsn->getUser() ?? '',
            $dsn->getPassword() ?? '',
            System::getEnv('_APP_STORAGE_S3_ENDPOINT', ''),
            $dsn->getParam('region'),
            bucket: $dsn->getPath(),
        )];
    }

    /**
     * @return array{0: array<string, string>, 1: Device, 2: Device} A new project's headers and its two stores
     */
    private function getCacheFixture(): array
    {
        if (System::getEnv('_APP_CONNECTIONS_STORAGE_CACHE', '') === '' || System::getEnv('_APP_STORAGE_CACHE_PERCENT', '0') === '0') {
            $this->markTestSkipped('Needs keys routed to a cache device');
        }

        $project = $this->getProject(true);
        [$filesystem, $device] = $this->getCacheStores($project['$id']);

        return [
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $project['$id'],
                'x-appwrite-key' => $project['apiKey'],
            ],
            $filesystem,
            $device,
        ];
    }

    /**
     * Caches a run of preview sizes, each its own key, noting where each one landed.
     *
     * @param array<string, string> $headers
     * @return array{path: string, entries: array<int, array{key: string, image: string, onDevice: bool}>}
     */
    private function warmPreviewCache(array $headers, Device $filesystem, Device $device): array
    {
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', $headers, [
            'bucketId' => Id::unique(),
            'name' => 'Cache stores',
            'permissions' => [Permission::read(Role::any())],
        ]);
        $this->assertSame(201, $bucket['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucket['body']['$id'] . '/files', ['content-type' => 'multipart/form-data'] + $headers, [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
        ]);
        $this->assertSame(201, $file['headers']['status-code']);

        $path = '/storage/buckets/' . $bucket['body']['$id'] . '/files/' . $file['body']['$id'] . '/preview';
        $entries = [];
        foreach (\range(20, 51) as $width) {
            $before = $this->getCacheKeys($device);
            $beforeFilesystem = $this->getCacheKeys($filesystem);

            $params = ['width' => $width, 'output' => 'png'];
            $first = $this->client->call(Client::METHOD_GET, $path, $headers, $params);
            $this->assertSame('miss', $first['headers']['x-appwrite-cache']);
            $this->assertEventually(fn () => $this->assertSame('hit', $this->client->call(Client::METHOD_GET, $path, $headers, $params)['headers']['x-appwrite-cache']));

            $added = \array_diff($this->getCacheKeys($device), $before);
            $addedFilesystem = \array_diff($this->getCacheKeys($filesystem), $beforeFilesystem);
            $this->assertCount(1, [...$added, ...$addedFilesystem]);
            $entries[$width] = [
                'key' => [...$added, ...$addedFilesystem][0],
                'image' => $this->getImageSignature($first['body']),
                'onDevice' => $added !== [],
            ];
        }

        return ['path' => $path, 'entries' => $entries];
    }

    /**
     * @return array<string> A store's keys in order: an entry's file name is its key
     */
    private function getCacheKeys(Device $store): array
    {
        $keys = \array_map(fn ($file): string => \basename($file->path), $store->listFiles($store->getRoot())->files);
        \sort($keys);

        return $keys;
    }

    /**
     * Compares pixels, since a render stamps the time into the file and a read that beats the cache write renders again.
     */
    private function getImageSignature(string $body): string
    {
        $image = new \Imagick();
        $image->readImageBlob($body);

        return $image->getImageSignature();
    }

    private function copyCacheEntryToFilesystem(Device $filesystem, Device $device, string $key): void
    {
        $filesystem->write($filesystem->getPath($key), $device->read($device->getPath($key)), 'application/octet-stream');
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

    public function testChunkedUploadRejectsRangePastLastChunk(): void
    {
        $bucketId = $this->setupBucket()['bucketId'];
        $fileId = Id::unique();
        $totalSize = 2 * 5 * 1024 * 1024;

        /**
         * Test for FAILURE
         * A range starting at the declared size names a third chunk of a two-chunk file.
         */
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'content-range' => 'bytes ' . $totalSize . '-' . $totalSize . '/' . $totalSize,
        ], $this->getHeaders()), [
            'fileId' => $fileId,
            'file' => new CURLFile('data://text/plain;base64,' . base64_encode('a'), 'text/plain', 'chunk.txt'),
        ]);
        $this->assertEquals(400, $file['headers']['status-code']);
        $this->assertEquals('storage_invalid_content_range', $file['body']['type']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(404, $file['headers']['status-code']);

        // With a partial last chunk, the declared size still maps onto that chunk's index.
        $fileId = Id::unique();
        $totalSize = 5 * 1024 * 1024 + 1024;
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'content-range' => 'bytes ' . $totalSize . '-' . $totalSize . '/' . $totalSize,
        ], $this->getHeaders()), [
            'fileId' => $fileId,
            'file' => new CURLFile('data://text/plain;base64,' . base64_encode('a'), 'text/plain', 'chunk.txt'),
        ]);
        $this->assertEquals(400, $file['headers']['status-code']);
        $this->assertEquals('storage_invalid_content_range', $file['body']['type']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(404, $file['headers']['status-code']);
    }

    public function testUpdateFileRejectsDisallowedExtension(): void
    {
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'bucketId' => Id::unique(),
            'name' => 'Extension Bucket',
            'allowedFileExtensions' => ['png'],
        ]);
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $bucketId = $bucket['body']['$id'];

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
        ]);
        $this->assertEquals(201, $file['headers']['status-code']);
        $fileId = $file['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'renamed.png',
        ]);
        $this->assertEquals(200, $file['headers']['status-code']);
        $this->assertEquals('renamed.png', $file['body']['name']);

        /**
         * Test for FAILURE
         */
        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'renamed.exe',
        ]);
        $this->assertEquals(400, $file['headers']['status-code']);
        $this->assertEquals('storage_file_type_unsupported', $file['body']['type']);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals('renamed.png', $file['body']['name']);
    }

    public function testIdleChunkedUploadDeletedByMaintenance(): void
    {
        $bucketId = $this->setupBucket()['bucketId'];
        $chunkSize = 5 * 1024 * 1024;
        $totalSize = $chunkSize + 1024;

        $upload = function (string $fileId) use ($bucketId, $chunkSize, $totalSize): array {
            return $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
                'content-type' => 'multipart/form-data',
                'x-appwrite-project' => $this->getProject()['$id'],
                'content-range' => 'bytes 0-' . ($chunkSize - 1) . '/' . $totalSize,
            ], $this->getHeaders()), [
                'fileId' => $fileId,
                'file' => new CURLFile('data://text/plain;base64,' . base64_encode(str_repeat('a', $chunkSize)), 'text/plain', 'chunk.txt'),
            ]);
        };

        $idleId = Id::unique();
        $activeId = Id::unique();
        foreach ([$idleId, $activeId] as $fileId) {
            $file = $upload($fileId);
            $this->assertEquals(201, $file['headers']['status-code']);
            $this->assertEquals(1, $file['body']['chunksUploaded']);
        }

        $this->withFactory(function ($factory) use ($bucketId, $idleId): void {
            $dbForProject = $factory->project($factory->platform()->getDocument('projects', $this->getProject()['$id']));
            $bucket = $dbForProject->getDocument('buckets', $bucketId);

            $dbForProject->withPreserveDates(true, fn () => $dbForProject->updateDocument('bucket_' . $bucket->getSequence(), $idleId, new Document([
                '$updatedAt' => DateTime::addSeconds(new \DateTime(), -APP_STORAGE_UPLOAD_RETENTION - 3600),
            ])));
        });

        $stdout = '';
        $stderr = '';
        $code = Console::execute((new Command('docker'))->argument('exec')->argument('appwrite')->argument('maintenance')->argument('--type=trigger'), '', $stdout, $stderr);
        $this->assertSame(0, $code, "Maintenance command failed with code $code: $stderr ($stdout)");

        $this->assertEventually(function () use ($bucketId, $idleId) {
            $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $idleId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));
            $this->assertEquals(404, $file['headers']['status-code']);
        }, 30000, 1000);

        $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $activeId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(200, $file['headers']['status-code']);
        $this->assertEquals(1, $file['body']['chunksUploaded']);

        // The aborted upload starts over from its first chunk under the same ID.
        $file = $upload($idleId);
        $this->assertEquals(201, $file['headers']['status-code']);
        $this->assertEquals(1, $file['body']['chunksUploaded']);
    }

    /**
     * @param callable(mixed): void $callback receives the server's database factory, untyped as an edition may register its own
     */
    private function withFactory(callable $callback): void
    {
        $run = function () use ($callback): void {
            global $register;
            $authorization = new Authorization();
            $factory = $register->get('databaseFactory')($register->get('pools'), $register->get('cache'), $authorization);

            $authorization->skip(fn () => $callback($factory));
        };

        if (Coroutine::getCid() >= 0) {
            $run();
        } else {
            run($run);
        }
    }

    public function testFilePushContentDisposition(): void
    {
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'bucketId' => Id::unique(),
            'name' => 'Push Filename',
        ]);
        $this->assertSame(201, $bucket['headers']['status-code']);
        $bucketId = $bucket['body']['$id'];

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/script.svg'), 'image/svg+xml', 'café logo.svg'),
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
            $this->assertSame('attachment; filename="caf_ logo.svg"; filename*=UTF-8\'\'' . \rawurlencode('café logo.svg'), $response['headers']['content-disposition']);
            $this->assertSame("script-src 'none';", $response['headers']['content-security-policy']);
        }

        /**
         * Test for FAILURE
         */
        $response = $push(['fileId' => 'other']);
        $this->assertSame(401, $response['headers']['status-code']);
    }
}
