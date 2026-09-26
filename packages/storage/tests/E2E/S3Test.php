<?php

declare(strict_types=1);

namespace Utopia\Tests\Storage\E2E;

use Throwable;
use Utopia\Psr7\Stream;
use Utopia\Storage\Acl;
use Utopia\Storage\Device\S3;
use Utopia\Storage\DeviceType;
use Utopia\Storage\Exception\PreconditionFailedException;
use Utopia\Storage\FileInfo;

final class S3Test extends S3Base
{
    private string $accessKey;

    private string $bucket;

    private string $region;

    private string $secretKey;

    private ?string $pathHost = null;

    private string $pathBucket;

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
        $host = $this->env('S3_HOST', "http://{$this->bucket}.localhost:9805");

        $this->object = new S3($this->root, $this->accessKey, $this->secretKey, $host, $this->region, Acl::Private, bucket: $this->bucket);
    }

    protected function getAdapterType(): DeviceType
    {
        return $this->object->getType();
    }

    public function testPathStyleEndpointSharesVirtualHostedBucket(): void
    {
        $pathHost = $this->pathHost;
        $virtualHost = $this->configured('S3_HOST');
        if (($pathHost === null) !== ($virtualHost === null)) {
            self::markTestSkipped('S3_HOST and S3_PATH_HOST must both be configured when overriding the local MinIO endpoints');
        }

        if ($pathHost === null) {
            $pathHost = 'http://localhost:9805';
        }

        $this->assertSame($this->bucket, $this->pathBucket, 'S3_PATH_BUCKET and S3_BUCKET must identify the same bucket for cross-visibility');

        $path = new S3(
            root: '/',
            accessKey: $this->accessKey,
            secretKey: $this->secretKey,
            host: rtrim($pathHost, '/') . '/' . rawurlencode($this->pathBucket) . '/',
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
                array_map(static fn(FileInfo $file): string => $file->path, $path->listFiles($prefix)->files),
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
}
