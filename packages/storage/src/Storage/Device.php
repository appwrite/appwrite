<?php

declare(strict_types=1);

namespace Utopia\Storage;

use Psr\Http\Message\StreamInterface;
use Utopia\Storage\Exception\NotFoundException;
use Utopia\Storage\Exception\PreconditionFailedException;
use Utopia\Storage\Exception\StorageException;
use Utopia\Storage\Exception\UploadException;

/**
 * @phpstan-type UploadMetadata array{parts?: array<int, bool|string>, chunks?: int, content_type?: string, uploadId?: string, whole?: bool}
 */
abstract class Device
{
    /**
     * Default max chunk size while copying a file from one device to another
     */
    public const COPY_CHUNK_SIZE = 20000000; // 20 MB

    /**
     * Get Type.
     *
     * Get storage device type
     */
    abstract public function getType(): DeviceType;

    /**
     * Get Root.
     *
     * Get storage device root path
     */
    abstract public function getRoot(): string;

    /**
     * Get Path.
     *
     * Each device hold a complex directory structure that is being build in this method.
     */
    abstract public function getPath(string $filename): string;

    /**
     * Prepare.
     *
     * Initialize adapter-specific upload state without transferring a chunk body.
     * Pass 0 chunks when their number is not known yet; `finalize()` then takes
     * the final count.
     *
     * @param  UploadMetadata  $metadata
     *
     * @throws StorageException
     */
    abstract public function prepare(string $path, string $contentType, int $chunks = 1, array &$metadata = []): void;

    /**
     * Upload Chunk.
     *
     * Store exactly one chunk of file contents without finalizing the full upload.
     * Returns the number of chunks received so far.
     *
     * @param  UploadMetadata  $metadata
     *
     * @throws StorageException
     */
    abstract protected function uploadChunk(StreamInterface $data, string $path, int $chunk, int $chunks, array &$metadata): int;

    /**
     * Finalize.
     *
     * Complete a prepared upload once all chunks are known to be present. The
     * assembled file replaces whatever was at the path before. Finalizing an
     * upload that was completed already is not an error.
     *
     * @param  UploadMetadata  $metadata
     *
     * @throws StorageException
     */
    abstract public function finalize(string $path, int $chunks = 1, array &$metadata = []): bool;

    /**
     * Upload.
     *
     * Upload one chunk of file contents to the desired destination, preparing the
     * upload on first contact and finalizing it once every chunk has arrived.
     * A whole file is the default single-chunk case. Pass 0 chunks when their
     * number is not known yet: nothing is finalized until `finalize()` is called
     * with the final count.
     * Returns the number of chunks received so far.
     *
     * @param  UploadMetadata  $metadata
     *
     * @throws StorageException
     */
    public function upload(StreamInterface $data, string $path, string $contentType, int $chunk = 1, int $chunks = 1, array &$metadata = []): int
    {
        $this->prepare($path, $contentType, $chunks, $metadata);
        $chunksReceived = $this->uploadChunk($data, $path, $chunk, $chunks, $metadata);

        if ($chunks > 1 && $chunks === $chunksReceived && ! $this->finalize($path, $chunks, $metadata)) {
            throw new UploadException('Failed to finalize upload ' . $path);
        }

        return $chunksReceived;
    }

    /**
     * Abort Chunked Upload
     */
    abstract public function abort(string $path, string $uploadId = ''): bool;

    /**
     * Read file or part of file by given path, offset and length.
     *
     * The returned stream is positioned at the start of the requested window.
     * A window that runs past the end of the file is cut short. With an ETag,
     * the read only happens while the file still carries it, so bytes from a
     * file replaced meanwhile are never mistaken for the one the caller knows.
     * S3 checks the ETag as part of the read; `Local` pins the file open and
     * hashes it, which costs a full pass over it before any byte is returned.
     *
     * @param  int<0, max>  $offset
     * @param  int<0, max>|null  $length
     * @param  string|null  $etag  Only read while the file has this ETag, as reported by `getFileInfo()`
     *
     * @throws NotFoundException When there is no file at the path
     * @throws PreconditionFailedException When the file's ETag is not the given one
     */
    abstract public function read(string $path, int $offset = 0, ?int $length = null, ?string $etag = null): StreamInterface;

    /**
     * Write file by given path, replacing whatever was there.
     *
     * The stream is consumed in full: seekable streams are rewound and sent
     * from the beginning on every adapter. Returns the ETag of the file
     * written, as `getFileInfo()` will report it.
     *
     * @throws StorageException
     */
    abstract public function write(string $path, StreamInterface $data, string $contentType): string;

    /**
     * Write a file where there is none yet.
     *
     * Exactly one of several callers racing to create the same path succeeds;
     * the others get a PreconditionFailedException. Returns the ETag of the
     * file written.
     *
     * @throws PreconditionFailedException When a file is already there
     */
    abstract public function create(string $path, StreamInterface $data, string $contentType = ''): string;

    /**
     * Write over the file that carries the given ETag, and no other.
     *
     * Exactly one of several callers racing to replace the same version
     * succeeds; the others get a PreconditionFailedException. Returns the ETag
     * of the file written.
     *
     * @param  string  $etag  The ETag the file must have, as reported by `getFileInfo()` or a previous write
     *
     * @throws PreconditionFailedException When the file is gone or has another ETag
     */
    abstract public function replace(string $path, StreamInterface $data, string $etag, string $contentType = ''): string;

    /**
     * Copy a file to another path, on this device or onto another one.
     *
     * Large files are piped chunk by chunk, so memory stays bounded regardless
     * of file size. A started multipart upload is aborted on failure.
     *
     * @throws StorageException
     */
    public function copy(string $source, string $target, ?Device $to = null, int $chunkSize = self::COPY_CHUNK_SIZE): bool
    {
        if ($chunkSize <= 0) {
            throw new \InvalidArgumentException('Chunk size must be greater than zero');
        }

        $to ??= $this;
        $size = $this->getFileSize($source);
        $contentType = $this->getFileMimeType($source);

        if ($size <= $chunkSize) {
            $to->write($target, $this->read($source), $contentType);

            return true;
        }

        $totalChunks = (int) ceil($size / $chunkSize);
        $metadata = ['content_type' => $contentType];
        try {
            for ($counter = 0; $counter < $totalChunks; ++$counter) {
                $data = $this->read($source, $counter * $chunkSize, $chunkSize);
                $to->upload($data, $target, $contentType, $counter + 1, $totalChunks, $metadata);
            }
        } catch (\Throwable $e) {
            // Best effort, and only once a multipart upload was actually started —
            // its unclaimed parts are billed until aborted. Aborting without one
            // could delete a pre-existing destination the copy never touched.
            $uploadId = $metadata['uploadId'] ?? null;
            if (\is_string($uploadId) && $uploadId !== '') {
                try {
                    $to->abort($target, $uploadId);
                } catch (\Throwable) {
                }
            }
            throw $e;
        }

        return true;
    }

    /**
     * Move file from given source to given path, return true on success and false on failure.
     */
    public function move(string $source, string $target): bool
    {
        if ($source === $target) {
            return false;
        }

        if ($this->copy($source, $target)) {
            return $this->delete($source);
        }

        return false;
    }

    /**
     * Delete file in given path return true on success and false on failure.
     */
    abstract public function delete(string $path, bool $recursive = false): bool;

    /**
     * Delete files in given path, path must be a directory. return true on success and false on failure.
     */
    abstract public function deletePath(string $path): bool;

    /**
     * Check if file exists
     */
    abstract public function exists(string $path): bool;

    /**
     * List files under the given prefix, one page at a time.
     *
     * @param  int<1, max>  $max
     */
    abstract public function listFiles(string $prefix = '', int $max = 1000, ?string $cursor = null): FileList;

    /**
     * Size, last modification and ETag of a file, in one request.
     *
     * @throws NotFoundException When there is no file at the path
     * @throws StorageException When the file cannot be looked at
     */
    abstract public function getFileInfo(string $path): FileInfo;

    /**
     * Returns given file path its size.
     */
    abstract public function getFileSize(string $path): int;

    /**
     * Returns given file path its mime type.
     */
    abstract public function getFileMimeType(string $path): string;

    /**
     * Returns given file path its MD5 hash value.
     */
    abstract public function getFileHash(string $path): string;

    /**
     * Get the absolute path by resolving strings like ../, .., //, /\ and so on.
     *
     * Works like the realpath function but works on files that does not exist
     *
     * Reference https://www.php.net/manual/en/function.realpath.php#84012
     */
    public function getAbsolutePath(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $parts = array_filter(explode(DIRECTORY_SEPARATOR, $path), fn(string $part): bool => $part !== '');

        $absolutes = [];
        foreach ($parts as $part) {
            if ($part == '.') {
                continue;
            }
            if ($part == '..') {
                array_pop($absolutes);
            } else {
                $absolutes[] = $part;
            }
        }

        return DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $absolutes);
    }
}
