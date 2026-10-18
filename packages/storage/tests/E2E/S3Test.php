<?php

declare(strict_types=1);

namespace Utopia\Storage\Tests\E2E;

use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;
use Utopia\Psr7\Stream;
use Utopia\Storage\Acl;
use Utopia\Storage\Device\S3;
use Utopia\Storage\DeviceType;
use Utopia\Storage\Exception\PreconditionFailedException;
use Utopia\Storage\Exception\RemoteException;
use Utopia\Storage\FileInfo;

final class S3Test extends S3Base
{
    private string $accessKey;

    private string $bucket;

    private string $region;

    private string $secretKey;

    private ?string $pathHost = null;

    private string $pathBucket;

    private string $host;

    private function env(string $key, string $default): string
    {
        $value = $_SERVER[$key] ?? null;

        return \is_string($value) ? $value : $default;
    }

    private function configured(string $key): ?string
    {
        $value = $_SERVER[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }

    protected function init(): void
    {
        $this->root = '/root';
        $this->bucket = $this->env('S3_BUCKET', 'utopia-storage-test');
        $this->accessKey = $this->env('S3_ACCESS_KEY', 'minioadmin');
        $this->secretKey = $this->env('S3_SECRET', 'minioadmin');
        $this->region = $this->env('S3_REGION', 'us-east-1');
        $this->pathHost = $this->configured('S3_PATH_HOST');
        $this->pathBucket = $this->env('S3_PATH_BUCKET', $this->bucket);
        $this->host = $this->env('S3_HOST', "http://{$this->bucket}.localhost:9805");

        $this->object = new S3($this->root, $this->accessKey, $this->secretKey, $this->host, $this->region, Acl::Private, bucket: $this->bucket);
    }

    /**
     * The service endpoint without the bucket in it, shared with the virtual-hosted endpoint.
     */
    private function pathStyleHost(): string
    {
        $pathHost = $this->pathHost;
        if (($pathHost === null) !== ($this->configured('S3_HOST') === null)) {
            self::markTestSkipped('S3_HOST and S3_PATH_HOST must both be configured when overriding the local MinIO endpoints');
        }

        $this->assertSame($this->bucket, $this->pathBucket, 'S3_PATH_BUCKET and S3_BUCKET must identify the same bucket for cross-visibility');

        return rtrim($pathHost ?? 'http://localhost:9805', '/');
    }

    /**
     * A device built the way an application configured with a bare service endpoint builds one.
     */
    private function pathStyle(string $root): S3
    {
        return new S3("{$this->bucket}/{$root}", $this->accessKey, $this->secretKey, $this->pathStyleHost(), $this->region, bucket: $this->bucket);
    }

    private function virtualHosted(string $root): S3
    {
        return new S3($root, $this->accessKey, $this->secretKey, $this->host, $this->region, bucket: $this->bucket);
    }

    /**
     * Every path listed under the prefix, paging until each wanted path has been seen.
     *
     * @param  array<string>  $wanted
     * @return array<string>
     */
    private function listUntil(S3 $device, string $prefix, array $wanted): array
    {
        $paths = [];
        $cursor = null;
        do {
            $page = $device->listFiles($prefix, cursor: $cursor);
            array_push($paths, ...array_map(static fn (FileInfo $file): string => $file->path, $page->files));
            $cursor = $page->cursor;
        } while ($cursor !== null && array_diff($wanted, $paths) !== []);

        return $paths;
    }

    private function remove(S3 $device, string ...$paths): void
    {
        foreach ($paths as $path) {
            try {
                $device->delete($path);
            } catch (Throwable) {
            }
        }
    }

    protected function getAdapterType(): DeviceType
    {
        return $this->object->getType();
    }

    public function testStreamingSurvivesQueuedDeletionOfItsBackendPath(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('This concurrency drill requires Swoole.');
        }
        $path = $this->object->getPath('testing/stream-' . bin2hex(random_bytes(8)));
        $original = str_repeat('snapshot-', 4 * 1024 * 1024);
        $this->object->write($path, new Stream($original), 'application/octet-stream');
        $hash = hash_init('sha256');
        $received = 0;
        $queued = false;
        $deleted = false;
        $flags = \Swoole\Runtime::getHookFlags();
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        try {
            \Swoole\Coroutine\run(function () use ($path, $hash, &$received, &$queued, &$deleted): void {
                $this->object->stream($path, function (string $chunk) use ($path, $hash, &$received, &$queued, &$deleted): void {
                    $received += strlen($chunk);
                    hash_update($hash, $chunk);
                    if (!$queued) {
                        $queued = true;
                        \Swoole\Coroutine::create(function () use ($path, &$deleted): void {
                            $this->object->delete($path);
                            $deleted = true;
                        });
                        $this->assertLessThan(1024 * 1024, $received);
                    }
                });
            });
            $this->assertTrue($queued);
            $this->assertTrue($deleted);
            $this->assertSame(strlen($original), $received);
            $this->assertSame(hash('sha256', $original), hash_final($hash));
            $this->assertFalse($this->object->exists($path));
        } finally {
            \Swoole\Runtime::enableCoroutine($flags);
            $this->object->delete($path);
        }
    }

    public function testPathStyleEndpointSharesVirtualHostedBucket(): void
    {
        $path = new S3(
            root: '/',
            accessKey: $this->accessKey,
            secretKey: $this->secretKey,
            host: $this->pathStyleHost() . '/' . rawurlencode($this->pathBucket) . '/',
            region: $this->region,
            bucket: $this->pathBucket,
        );
        $prefix = 'endpoint-path/' . bin2hex(random_bytes(8));
        $pathObject = $prefix . '/path.txt';
        $virtualObject = $prefix . '/virtual.txt';
        $failure = null;

        try {
            $this->assertSame(md5('path-style'), $path->write($pathObject, new Stream('path-style'), 'text/plain'));
            $this->assertSame('path-style', (string) $this->object->read($pathObject));

            $this->assertSame(md5('virtual-hosted'), $this->object->write($virtualObject, new Stream('virtual-hosted'), 'text/plain'));
            $this->assertSame('virtual-hosted', (string) $path->read($virtualObject));
            $this->assertSame(
                [$pathObject, $virtualObject],
                array_map(static fn (FileInfo $file): string => $file->path, $path->listFiles($prefix)->files),
            );
        } catch (Throwable $error) {
            $failure = $error;

            throw $error;
        } finally {
            $cleanup = [];
            foreach ([[$path, $pathObject], [$this->object, $virtualObject]] as [$device, $object]) {
                try {
                    if (! $device->delete($object)) {
                        $cleanup[] = "Failed to delete {$object}";
                    }
                } catch (Throwable $error) {
                    $cleanup[] = "Failed to delete {$object}: {$error->getMessage()}";
                }
            }

            if (!$failure instanceof Throwable && $cleanup !== []) {
                self::fail(implode('; ', $cleanup));
            }
        }
    }

    public function testConditionalWritesHaveOneWinner(): void
    {
        $path = $this->object->getPath('conditional/' . bin2hex(random_bytes(8)) . '/lock');

        try {
            $first = $this->object->create($path, new Stream('first'), 'text/plain');
            $this->assertSame(md5('first'), $first);

            try {
                $this->object->create($path, new Stream('second'), 'text/plain');
                self::fail('A second create must be refused');
            } catch (PreconditionFailedException) {
                $this->assertSame('first', (string) $this->object->read($path));
            }

            $second = $this->object->replace($path, new Stream('second'), $first, 'text/plain');
            $this->assertSame(md5('second'), $second);

            try {
                $this->object->replace($path, new Stream('third'), $first, 'text/plain');
                self::fail('A replace naming a stale ETag must be refused');
            } catch (PreconditionFailedException) {
                $this->assertSame('second', (string) $this->object->read($path));
            }

            $info = $this->object->getFileInfo($path);
            $this->assertSame($second, $info->etag);
            $this->assertSame(6, $info->size);
            $this->assertInstanceOf(\DateTimeImmutable::class, $info->modifiedAt);

            $this->assertSame('sec', (string) $this->object->read($path, 0, 3, $second));

            $this->expectException(PreconditionFailedException::class);
            $this->object->read($path, 0, 3, $first);
        } finally {
            $this->object->delete($path);
        }
    }

    public function testMultipartUploadReplacesAnExistingObject(): void
    {
        $path = $this->object->getPath('replaced/' . bin2hex(random_bytes(8)) . '.bin');
        $part = str_repeat('a', 5 * 1024 * 1024); // the smallest part S3 accepts, except for the last one

        try {
            $this->assertSame(md5('old'), $this->object->write($path, new Stream('old'), 'text/plain'));

            // The number of parts is not known up front: nothing finalizes until asked.
            $metadata = [];
            $this->object->upload(new Stream('tail'), $path, 'application/octet-stream', 2, 0, $metadata);
            $this->object->upload(new Stream($part), $path, 'application/octet-stream', 1, 0, $metadata);
            $this->assertSame('old', (string) $this->object->read($path), 'nothing changes before finalize');

            // MinIO only honours a prefix that is a whole key; Amazon S3 takes any prefix.
            $uploads = $this->object->listUploads($path);
            $this->assertCount(1, $uploads->uploads);
            $this->assertSame(ltrim($path, '/'), $uploads->uploads[0]->path);
            $this->assertSame($metadata['uploadId'] ?? null, $uploads->uploads[0]->uploadId);
            $this->assertNull($uploads->cursor);

            $this->assertTrue($this->object->finalize($path, 2, $metadata));
            $this->assertSame(\strlen($part) + 4, $this->object->getFileSize($path));
            $this->assertSame('tail', (string) $this->object->read($path, \strlen($part)));
            $this->assertTrue($this->object->finalize($path, 2, $metadata), 'finalizing again is not an error');
            $this->assertSame([], $this->object->listUploads($path)->uploads);
        } finally {
            $this->object->delete($path);
        }
    }

    public function testAbandonedUploadsAreListedAndAborted(): void
    {
        $path = $this->object->getPath('abandoned/' . bin2hex(random_bytes(8)) . '/a.bin');
        $metadata = [];

        $this->object->prepare($path, 'application/octet-stream', 2, $metadata);
        $uploadId = $metadata['uploadId'] ?? '';
        $this->assertNotSame('', $uploadId);

        $list = $this->object->listUploads($path);
        $this->assertCount(1, $list->uploads);
        $this->assertSame(ltrim($path, '/'), $list->uploads[0]->path);
        $this->assertSame($uploadId, $list->uploads[0]->uploadId);
        $this->assertInstanceOf(\DateTimeImmutable::class, $list->uploads[0]->initiatedAt);

        $this->assertTrue($this->object->abort($path, $uploadId));
        $this->assertSame([], $this->object->listUploads($path)->uploads);
    }

    public function testWritesWithoutAnAcl(): void
    {
        $device = new S3($this->root, $this->accessKey, $this->secretKey, $this->env('S3_HOST', "http://{$this->bucket}.localhost:9805"), $this->region, null, bucket: $this->bucket);
        $path = $device->getPath('no-acl/' . bin2hex(random_bytes(8)) . '.txt');

        try {
            $this->assertSame(md5('no acl'), $device->write($path, new Stream('no acl'), 'text/plain'));
            $this->assertSame('no acl', (string) $device->read($path));
        } finally {
            $device->delete($path);
        }
    }

    public function testPathStyleListsTheBucket(): void
    {
        $device = $this->pathStyle('listing/' . bin2hex(random_bytes(8)));
        $a = $device->getPath('dir/a.txt');
        $b = $device->getPath('dir/b.txt');

        try {
            $device->write($a, new Stream('a'), 'text/plain');
            $device->write($b, new Stream('b'), 'text/plain');

            $this->assertSame([$a, $b], array_map(static fn (FileInfo $file): string => $file->path, $device->listFiles($device->getPath('dir/'))->files));

            foreach (['', $this->bucket] as $prefix) {
                $paths = $this->listUntil($device, $prefix, [$a, $b]);
                $this->assertContains($a, $paths);
                $this->assertContains($b, $paths);
                foreach ($paths as $path) {
                    $this->assertStringStartsWith($this->bucket . '/', $path);
                }
            }
        } finally {
            $this->remove($device, $a, $b);
        }
    }

    public function testPathStyleListsUploads(): void
    {
        $device = $this->pathStyle('uploads/' . bin2hex(random_bytes(8)));
        $path = $device->getPath('a.bin');
        $metadata = [];
        $device->prepare($path, 'application/octet-stream', 2, $metadata);
        $uploadId = $metadata['uploadId'] ?? '';
        $this->assertNotSame('', $uploadId);

        try {
            $uploads = $device->listUploads($path)->uploads;
            $this->assertCount(1, $uploads);
            $this->assertSame($path, $uploads[0]->path);
            $this->assertSame($uploadId, $uploads[0]->uploadId);

            $found = false;
            $cursor = null;
            do {
                $page = $device->listUploads('', cursor: $cursor);
                foreach ($page->uploads as $upload) {
                    $this->assertStringStartsWith($this->bucket . '/', $upload->path);
                    $found = $found || ($upload->path === $path && $upload->uploadId === $uploadId);
                }
                $cursor = $page->cursor;
            } while (! $found && $cursor !== null);
            $this->assertTrue($found, 'the whole-bucket listing holds the upload');

            $this->assertTrue($device->abort($path, $uploadId));
            $this->assertSame([], $device->listUploads($path)->uploads);
        } finally {
            try {
                $device->abort($path, $uploadId);
            } catch (Throwable) {
            }
        }
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function addressing(): iterable
    {
        yield 'virtual-hosted' => [false];
        yield 'path-style' => [true];
    }

    #[DataProvider('addressing')]
    public function testDeletePathStaysInsideItsRoot(bool $pathStyle): void
    {
        $root = 'contained/' . bin2hex(random_bytes(8));
        $device = $pathStyle ? $this->pathStyle("{$root}/a") : $this->virtualHosted("{$this->bucket}/{$root}/a");
        $sibling = $pathStyle ? $this->pathStyle("{$root}/sibling") : $this->virtualHosted("{$this->bucket}/{$root}/sibling");
        $file = $device->getPath('f.txt');
        $neighbour = $sibling->getPath('f.txt');

        try {
            $device->write($file, new Stream('a'), 'text/plain');
            $sibling->write($neighbour, new Stream('sibling'), 'text/plain');

            // A bucket-addressed request carries the prefix literally, which some services refuse.
            $refusal = null;
            try {
                $device->deletePath('../sibling');
            } catch (\InvalidArgumentException|RemoteException $error) {
                $refusal = $error;
            }
            if ($pathStyle) {
                $this->assertInstanceOf(\InvalidArgumentException::class, $refusal, 'a path-style prefix climbing out of its root is refused before any request');
            }
            $this->assertTrue($sibling->exists($neighbour));

            $this->assertTrue($device->deletePath(''));
            $this->assertFalse($device->exists($file));
            $this->assertTrue($sibling->exists($neighbour));
        } finally {
            $this->remove($device, $file, $neighbour);
        }
    }

    public function testPathStyleCopyRunsServerSide(): void
    {
        $root = 'copied/' . bin2hex(random_bytes(8));
        $device = $this->pathStyle($root);
        $source = $device->getPath('source.txt');
        $target = $device->getPath('target.txt');

        try {
            $device->write($source, new Stream('payload'), 'text/plain');

            $this->assertTrue($device->copy($source, $target));
            $this->assertSame('payload', (string) $device->read($target));
            $this->assertSame('payload', (string) $this->object->read("{$root}/target.txt"));
        } finally {
            $this->remove($device, $source, $target);
        }
    }

    public function testVirtualHostedLegacyRootCopiesTheRealKey(): void
    {
        $root = 'legacy-copy/' . bin2hex(random_bytes(8));
        $device = $this->virtualHosted("{$this->bucket}/{$root}");
        $source = $device->getPath('source.txt');
        $target = $device->getPath('target.txt');

        try {
            $device->write($source, new Stream('payload'), 'text/plain');

            $this->assertTrue($device->copy($source, $target));
            $this->assertSame('payload', (string) $device->read($target));
            $this->assertSame('payload', (string) $this->pathStyle($root)->read("{$this->bucket}/{$this->bucket}/{$root}/target.txt"));
        } finally {
            $this->remove($device, $source, $target);
        }
    }

    public function testLegacyBucketInRootObjectsResolveUnderPathStyle(): void
    {
        $root = 'legacy/' . bin2hex(random_bytes(8));
        $legacy = new S3("{$this->bucket}/{$root}", $this->accessKey, $this->secretKey, $this->pathStyleHost(), $this->region);
        $device = $this->pathStyle($root);
        $file = $legacy->getPath('f.txt');
        $this->assertSame($file, $device->getPath('f.txt'));

        try {
            $etag = $legacy->write($file, new Stream('legacy'), 'text/plain');

            $this->assertSame('legacy', (string) $device->read($file));
            $this->assertSame($etag, $device->getFileInfo($file)->etag);
            $this->assertSame([$file], array_map(static fn (FileInfo $info): string => $info->path, $device->listFiles($device->getPath(''))->files));
            $this->assertSame('legacy', (string) $this->object->read("{$root}/f.txt"));

            $this->assertTrue($device->deletePath(''));
            $this->assertFalse($device->exists($file));
        } finally {
            $this->remove($legacy, $file);
        }
    }
}
