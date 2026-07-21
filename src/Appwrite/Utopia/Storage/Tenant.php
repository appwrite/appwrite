<?php

namespace Appwrite\Utopia\Storage;

use Utopia\Storage\Device;
use Utopia\Storage\DeviceType;
use Utopia\Storage\FileList;

/**
 * Decorator that confines a shared device to one tenant's subtree.
 *
 * The decorated device is an immutable, coroutine-safe singleton per storage
 * backend; this wrapper prefixes every path with the tenant segment and
 * rejects any path that resolves outside it (including `../` traversal, which
 * object-store paths do not normalize on their own). Cross-device transfers
 * are scoped on each side by that side's own device.
 *
 * @phpstan-import-type UploadMetadata from Device
 */
class Tenant extends Device
{
    public function __construct(
        private readonly Device $device,
        private readonly string $tenant,
    ) {
    }

    /**
     * The tenant-scoped root on the decorated device.
     */
    public function getRoot(): string
    {
        return $this->device->getRoot() . DIRECTORY_SEPARATOR . $this->tenant;
    }

    public function getType(): DeviceType
    {
        return $this->device->getType();
    }

    public function getPath(string $filename): string
    {
        return $this->scoped($this->device->getPath($this->tenant . DIRECTORY_SEPARATOR . $filename));
    }

    /**
     * Normalize a path and reject it unless it stays inside the tenant root.
     */
    private function scoped(string $path): string
    {
        $root = $this->getAbsolutePath($this->getRoot());
        $normalized = $this->getAbsolutePath($path);

        if ($normalized !== $root && !\str_starts_with($normalized, $root . DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('Path "' . $path . '" is outside the tenant scope "' . $root . '"');
        }

        return $normalized;
    }

    /**
     * @param UploadMetadata $metadata
     */
    public function prepareUpload(string $path, string $contentType, int $chunks = 1, array &$metadata = []): void
    {
        $this->device->prepareUpload($this->scoped($path), $contentType, $chunks, $metadata);
    }

    /**
     * @param UploadMetadata $metadata
     */
    public function uploadChunk(string $data, string $path, int $chunk = 1, int $chunks = 1, array &$metadata = []): int
    {
        return $this->device->uploadChunk($data, $this->scoped($path), $chunk, $chunks, $metadata);
    }

    /**
     * @param UploadMetadata $metadata
     */
    public function finalizeUpload(string $path, int $chunks = 1, array &$metadata = []): bool
    {
        return $this->device->finalizeUpload($this->scoped($path), $chunks, $metadata);
    }

    public function abort(string $path, string $uploadId = ''): bool
    {
        return $this->device->abort($this->scoped($path), $uploadId);
    }

    public function read(string $path, int $offset = 0, ?int $length = null): string
    {
        return $this->device->read($this->scoped($path), $offset, $length);
    }

    public function transfer(string $path, string $destination, Device $device, int $chunkSize = self::TRANSFER_CHUNK_SIZE): bool
    {
        // The destination is validated by the target device when it writes.
        return $this->device->transfer($this->scoped($path), $destination, $device, $chunkSize);
    }

    public function write(string $path, string $data, string $contentType): bool
    {
        return $this->device->write($this->scoped($path), $data, $contentType);
    }

    public function delete(string $path, bool $recursive = false): bool
    {
        return $this->device->delete($this->scoped($path), $recursive);
    }

    public function deletePath(string $path): bool
    {
        // deletePath() is relative to the decorated device's root.
        $this->scoped($this->getRoot() . DIRECTORY_SEPARATOR . $path);

        return $this->device->deletePath($this->tenant . DIRECTORY_SEPARATOR . $path);
    }

    public function exists(string $path): bool
    {
        return $this->device->exists($this->scoped($path));
    }

    public function getFileSize(string $path): int
    {
        return $this->device->getFileSize($this->scoped($path));
    }

    public function getFileMimeType(string $path): string
    {
        return $this->device->getFileMimeType($this->scoped($path));
    }

    public function getFileHash(string $path): string
    {
        return $this->device->getFileHash($this->scoped($path));
    }

    public function listFiles(string $prefix = '', int $max = 1000, ?string $cursor = null): FileList
    {
        return $this->device->listFiles($this->scoped($prefix), $max, $cursor);
    }
}
