<?php

declare(strict_types=1);

namespace Utopia\Cache\Adapter;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Utopia\Cache\Adapter;
use Utopia\Psr7\Stream;
use Utopia\Storage\Device;
use Utopia\Storage\Exception\StorageException;

/**
 * Stores each entry as one object on a storage device, under the device's root,
 * holding exactly the cached bytes. Age is the object's last modified time.
 * Requires utopia-php/storage.
 */
class Storage implements Adapter
{
    private const string CONTENT_TYPE = 'application/octet-stream';

    /**
     * Takes a built device, so a caller decides its client and connection reuse.
     *
     * @throws InvalidArgumentException When the device has no root, which a flush would delete wholesale
     */
    public function __construct(protected readonly Device $device)
    {
        if (trim($device->getAbsolutePath($device->getRoot()), '/') === '') {
            throw new InvalidArgumentException('The cache device needs a root');
        }
    }

    /**
     * One conditional read, with no request before it: on S3 a GET that answers 200
     * with the bytes, 304 with no body if expired, or 404 if missing. A device
     * failure reads as a miss.
     *
     * @param  int  $ttl time in seconds
     * @param  string  $hash optional
     */
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        try {
            $modifiedSince = new DateTimeImmutable('@' . (time() - $ttl));

            return $this->device->read($this->getPath($key), modifiedSince: $modifiedSince)->getContents();
        } catch (StorageException | RuntimeException) {
            return false;
        }
    }

    /**
     * Writing resets the entry's age. On S3 this is one PUT. An array is stored as
     * its values joined, as Filesystem does, and one holding a non-scalar is refused.
     *
     * @param  array<int|string, mixed>|string  $data
     * @param  string  $hash optional
     * @param  int  $ttl time in seconds
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, array|string $data, string $hash = '', int $ttl = 0): bool|string|array
    {
        if (empty($data)) {
            return false;
        }

        $body = '';
        foreach (\is_array($data) ? $data : [$data] as $value) {
            if (! \is_scalar($value)) {
                return false;
            }

            $body .= (string) $value;
        }

        try {
            $this->device->write($this->getPath($key), new Stream($body), self::CONTENT_TYPE);

            return $data;
        } catch (StorageException | RuntimeException) {
            return false;
        }
    }

    /**
     * Objects have no writable modified time, so this rewrites the object: a HEAD, a
     * GET and a conditional PUT on S3. The condition stops it bringing back an entry
     * purged or replaced meanwhile, which returns false.
     *
     * @param  string  $hash optional
     */
    public function touch(string $key, string $hash = ''): bool
    {
        $path = $this->getPath($key);

        try {
            $etag = $this->device->getFileInfo($path)->etag;

            if ($etag === null) {
                return false;
            }

            $this->device->replace($path, $this->device->read($path, etag: $etag), $etag, self::CONTENT_TYPE);

            return true;
        } catch (StorageException | RuntimeException) {
            return false;
        }
    }

    /**
     * An entry has no fields to list. Makes no request.
     *
     * @return string[]
     */
    public function list(string $key): array
    {
        return [];
    }

    /**
     * One DELETE. S3 does not say whether the object existed, so purging a missing
     * key returns true there. A failure throws, since the entry may still be cached.
     *
     * @param  string  $hash optional
     *
     * @throws StorageException
     */
    public function purge(string $key, string $hash = ''): bool
    {
        return $this->device->delete($this->getPath($key));
    }

    /**
     * Walks everything under the root: on S3 a LIST and a DeleteObjects per 1000
     * entries. Not for a request path. A failure throws.
     *
     * @throws StorageException
     */
    public function flush(): bool
    {
        $root = $this->device->getRoot();

        return $this->device->delete($root, true) || ! $this->device->exists($root);
    }

    /**
     * One HEAD on the root, which shows the device answers and accepts the
     * credentials, not that it accepts writes. A local device always answers.
     */
    public function ping(): bool
    {
        try {
            $this->device->exists($this->device->getRoot());

            return true;
        } catch (StorageException) {
            return false;
        }
    }

    /**
     * Always 0: the size is only known by listing every object. Makes no request.
     */
    public function getSize(): int
    {
        return 0;
    }

    /**
     * The key becomes a single encoded path segment, so it cannot leave the root.
     */
    public function getPath(string $key): string
    {
        $name = rawurlencode($key);

        // These three survive encoding but do not name an object. rawurlencode() never emits a bare '%' or '%2E'.
        return $this->device->getPath(match ($name) {
            '' => '%',
            '.' => '%2E',
            '..' => '%2E%2E',
            default => $name,
        });
    }

    public function getName(?string $key = null): string
    {
        return 'storage';
    }
}
