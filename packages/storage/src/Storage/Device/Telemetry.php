<?php

declare(strict_types=1);

namespace Utopia\Storage\Device;

use Psr\Http\Message\StreamInterface;
use Utopia\Storage\Device;
use Utopia\Storage\DeviceType;
use Utopia\Storage\FileInfo;
use Utopia\Storage\FileList;
use Utopia\Storage\UploadList;
use Utopia\Telemetry\Adapter;
use Utopia\Telemetry\Histogram;

/**
 * Decorator that records a `storage.operation` histogram around every call to the decorated device.
 *
 * @phpstan-import-type UploadMetadata from Device
 *
 * @see \Utopia\Tests\Storage\Device\TelemetryTest
 */
class Telemetry extends Device
{
    private readonly Histogram $telemetry;

    public function __construct(Adapter $telemetry, private readonly Device $device)
    {
        $this->telemetry = $telemetry->createHistogram(
            name: 'storage.operation',
            unit: 's',
            advisory: ['ExplicitBucketBoundaries' => [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10]],
        );
    }

    /**
     * The decorated device, for access to adapter-specific methods that are
     * not part of the base contract (for example `Local` partition metrics or
     * the `S3` object listing).
     */
    public function getDevice(): Device
    {
        return $this->device;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function measure(string $method, callable $operation): mixed
    {
        $start = microtime(true);
        try {
            return $operation();
        } finally {
            $this->telemetry->record(
                microtime(true) - $start,
                [
                    'storage' => $this->device->getType()->value,
                    'operation' => "device:$method",
                ],
            );
        }
    }

    public function getType(): DeviceType
    {
        return $this->device->getType();
    }

    public function getRoot(): string
    {
        return $this->device->getRoot();
    }

    public function getPath(string $filename): string
    {
        return $this->measure(__FUNCTION__, fn(): string => $this->device->getPath($filename));
    }

    /**
     * @param  UploadMetadata  $metadata
     */
    public function prepare(string $path, string $contentType, int $chunks = 1, array &$metadata = []): void
    {
        $this->measure(__FUNCTION__, function () use ($path, $contentType, $chunks, &$metadata): void {
            $this->device->prepare($path, $contentType, $chunks, $metadata);
        });
    }

    /**
     * @param  UploadMetadata  $metadata
     */
    /**
     * @param  UploadMetadata  $metadata
     */
    protected function uploadChunk(StreamInterface $data, string $path, int $chunk, int $chunks, array &$metadata): int
    {
        return $this->measure(__FUNCTION__, function () use ($data, $path, $chunk, $chunks, &$metadata): int {
            return $this->device->uploadChunk($data, $path, $chunk, $chunks, $metadata);
        });
    }

    /**
     * @param  UploadMetadata  $metadata
     */
    public function finalize(string $path, int $chunks = 1, array &$metadata = []): bool
    {
        return $this->measure(__FUNCTION__, function () use ($path, $chunks, &$metadata): bool {
            return $this->device->finalize($path, $chunks, $metadata);
        });
    }

    public function abort(string $path, string $uploadId = ''): bool
    {
        return $this->measure(__FUNCTION__, fn(): bool => $this->device->abort($path, $uploadId));
    }

    /**
     * @param  int<0, max>  $offset
     * @param  int<0, max>|null  $length
     */
    public function read(string $path, int $offset = 0, ?int $length = null, ?string $etag = null): StreamInterface
    {
        return $this->measure(__FUNCTION__, fn(): StreamInterface => $this->device->read($path, $offset, $length, $etag));
    }

    #[\Override]
    public function copy(string $source, string $target, ?Device $to = null, int $chunkSize = self::COPY_CHUNK_SIZE): bool
    {
        return $this->measure(__FUNCTION__, fn(): bool => $this->device->copy($source, $target, $to, $chunkSize));
    }

    public function write(string $path, StreamInterface $data, string $contentType): string
    {
        return $this->measure(__FUNCTION__, fn(): string => $this->device->write($path, $data, $contentType));
    }

    public function create(string $path, StreamInterface $data, string $contentType = ''): string
    {
        return $this->measure(__FUNCTION__, fn(): string => $this->device->create($path, $data, $contentType));
    }

    public function replace(string $path, StreamInterface $data, string $etag, string $contentType = ''): string
    {
        return $this->measure(__FUNCTION__, fn(): string => $this->device->replace($path, $data, $etag, $contentType));
    }

    public function delete(string $path, bool $recursive = false): bool
    {
        return $this->measure(__FUNCTION__, fn(): bool => $this->device->delete($path, $recursive));
    }

    public function deletePath(string $path): bool
    {
        return $this->measure(__FUNCTION__, fn(): bool => $this->device->deletePath($path));
    }

    public function exists(string $path): bool
    {
        return $this->measure(__FUNCTION__, fn(): bool => $this->device->exists($path));
    }

    /**
     * @param  int<1, max>  $max
     */
    public function listFiles(string $prefix = '', int $max = 1000, ?string $cursor = null): FileList
    {
        return $this->measure(__FUNCTION__, fn(): FileList => $this->device->listFiles($prefix, $max, $cursor));
    }

    /**
     * List the multipart uploads in progress on the decorated device.
     *
     * Only `S3` has uploads to list; wrapping anything else and asking for
     * them is a programming error rather than an empty page, which would read
     * as "nothing is being billed".
     *
     * @param  int<1, max>  $max
     *
     * @throws \BadMethodCallException When the decorated device has no multipart uploads
     */
    public function listUploads(string $prefix = '', int $max = 1000, ?string $cursor = null): UploadList
    {
        $device = $this->device;
        if (! $device instanceof S3) {
            throw new \BadMethodCallException($device::class . ' does not list multipart uploads');
        }

        return $this->measure(__FUNCTION__, fn(): UploadList => $device->listUploads($prefix, $max, $cursor));
    }

    public function getFileInfo(string $path): FileInfo
    {
        return $this->measure(__FUNCTION__, fn(): FileInfo => $this->device->getFileInfo($path));
    }

    public function getFileSize(string $path): int
    {
        return $this->measure(__FUNCTION__, fn(): int => $this->device->getFileSize($path));
    }

    public function getFileMimeType(string $path): string
    {
        return $this->measure(__FUNCTION__, fn(): string => $this->device->getFileMimeType($path));
    }

    public function getFileHash(string $path): string
    {
        return $this->measure(__FUNCTION__, fn(): string => $this->device->getFileHash($path));
    }
}
