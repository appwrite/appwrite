<?php

declare(strict_types=1);

namespace Utopia\Storage\Device;

use Psr\Http\Message\StreamInterface;
use Utopia\Psr7\Stream;
use Utopia\Storage\Device;
use Utopia\Storage\DeviceType;
use Utopia\Storage\Exception\NotFoundException;
use Utopia\Storage\Exception\PreconditionFailedException;
use Utopia\Storage\Exception\StorageException;
use Utopia\Storage\Exception\UploadException;
use Utopia\Storage\FileInfo;
use Utopia\Storage\FileList;

/**
 * @see \Utopia\Tests\Storage\Device\LocalTest
 *
 * @phpstan-import-type UploadMetadata from Device
 */
class Local extends Device
{
    private const int PIPE_CHUNK_SIZE = 524288; // 512 KB

    /**
     * Local constructor.
     */
    public function __construct(protected readonly string $root = '') {}

    public function getType(): DeviceType
    {
        return DeviceType::Local;
    }

    public function getRoot(): string
    {
        return $this->root;
    }

    public function getPath(string $filename): string
    {
        return $this->getAbsolutePath($this->getRoot() . DIRECTORY_SEPARATOR . $filename);
    }

    /**
     * @param  UploadMetadata  $metadata
     */
    public function prepare(string $path, string $contentType, int $chunks = 1, array &$metadata = []): void
    {
        $this->createDirectory(\dirname($path));
        $metadata['parts'] ??= [];
        $metadata['chunks'] ??= 0;
    }

    /**
     * @param  UploadMetadata  $metadata
     */
    protected function uploadChunk(StreamInterface $data, string $path, int $chunk, int $chunks, array &$metadata): int
    {
        $this->createDirectory(\dirname($path));
        $metadata['parts'] ??= [];
        $metadata['chunks'] ??= 0;

        if ($chunks === 1) {
            $this->writeAtomic($path, $data);

            $metadata['parts'][$chunk] = true;
            $metadata['chunks'] = 1;
            $metadata['whole'] = true;

            return 1;
        }

        $tmp = \dirname($path) . DIRECTORY_SEPARATOR . 'tmp_' . basename($path);
        $this->createDirectory($tmp);

        $chunkFilePath = $tmp . DIRECTORY_SEPARATOR . pathinfo($path, PATHINFO_FILENAME) . '.part.' . $chunk;

        // skip writing chunk if the chunk was re-uploaded
        if (! file_exists($chunkFilePath)) {
            $this->writeFile($chunkFilePath, $data);
        }

        $chunksReceived = $this->countChunks($tmp, $path);
        $metadata['parts'][$chunk] = true;
        $metadata['chunks'] = $chunksReceived;
        $metadata['whole'] = false;

        return $chunksReceived;
    }

    public function finalize(string $path, int $chunks = 1, array &$metadata = []): bool
    {
        $tmp = \dirname($path) . DIRECTORY_SEPARATOR . 'tmp_' . basename($path);

        // A single chunk was written as the whole file, unless the upload was
        // prepared without knowing the count: then it is one part to join. The
        // upload itself says which it was; without its metadata, a part left
        // behind by an abandoned upload is indistinguishable from this one's.
        $whole = $metadata['whole'] ?? ! file_exists($tmp . DIRECTORY_SEPARATOR . pathinfo($path, PATHINFO_FILENAME) . '.part.1');

        if ($chunks === 1 && $whole) {
            return file_exists($path);
        }

        for ($i = 1; $i <= $chunks; ++$i) {
            $part = $tmp . DIRECTORY_SEPARATOR . pathinfo($path, PATHINFO_FILENAME) . '.part.' . $i;
            if (file_exists($part)) {
                continue;
            }

            // The chunk directory goes once the parts are joined, so its absence
            // next to a file in place is a finalized upload. While it is still
            // there, the chunk is missing, whatever sits at the path.
            if (! is_dir($tmp) && file_exists($path)) {
                return true;
            }

            throw new UploadException('Missing chunk ' . $i);
        }

        $this->joinChunks($path, $chunks);

        return true;
    }

    private function countChunks(string $tmp, string $path): int
    {
        $escaped = (fn(string $literal): string => str_replace(['\\', '*', '?', '[', ']', '{', '}'], ['\\\\', '\\*', '\\?', '\\[', '\\]', '\\{', '\\}'], $literal));
        $pattern = $escaped($tmp) . DIRECTORY_SEPARATOR . $escaped(pathinfo($path, PATHINFO_FILENAME)) . '.part.*';
        $files = glob($pattern);
        if ($files === false) {
            return 0;
        }

        $count = 0;
        foreach ($files as $file) {
            if (preg_match('/\.part\.\d+$/', $file)) {
                ++$count;
            }
        }

        return $count;
    }

    private function joinChunks(string $path, int $chunks): void
    {
        $tmp = \dirname($path) . DIRECTORY_SEPARATOR . 'tmp_' . basename($path);
        $tmpAssemble = tempnam(\dirname($path), 'tmp_assemble_' . basename($path) . '_');

        $dest = fopen($tmpAssemble, 'wb');
        if ($dest === false) {
            throw new StorageException('Failed to open temporary assembly file ' . $tmpAssemble);
        }

        $partsToUnlink = [];
        for ($i = 1; $i <= $chunks; ++$i) {
            $part = $tmp . DIRECTORY_SEPARATOR . pathinfo($path, PATHINFO_FILENAME) . '.part.' . $i;
            $src = @fopen($part, 'rb');
            if ($src === false) {
                fclose($dest);
                unlink($tmpAssemble);

                // The chunks go once joined: a file in place means another
                // request assembled it meanwhile, and there is nothing left to do.
                if (file_exists($path)) {
                    return;
                }

                throw new StorageException('Failed to open chunk ' . $part);
            }

            if (stream_copy_to_stream($src, $dest) === false) {
                fclose($src);
                fclose($dest);
                unlink($tmpAssemble);
                throw new StorageException('Failed to copy chunk ' . $part);
            }
            fclose($src);
            $partsToUnlink[] = $part;
        }

        fclose($dest);

        // tempnam() creates the file private to its owner; the assembled file
        // takes the permissions a plain write would have given it.
        @chmod($tmpAssemble, 0644 & ~umask());

        // rename() replaces a file in place, so the assembled file takes over from whatever was there.
        if (! rename($tmpAssemble, $path)) {
            unlink($tmpAssemble);
            throw new StorageException('Failed to finalize assembled file ' . $path);
        }

        foreach ($partsToUnlink as $part) {
            if (! unlink($part)) {
                trigger_error('Failed to remove chunk part ' . $part, E_USER_WARNING);
            }
        }

        if (! rmdir($tmp)) {
            trigger_error('Failed to remove temporary chunk directory ' . $tmp, E_USER_WARNING);
        }
    }

    /**
     * Abort Chunked Upload
     */
    public function abort(string $path, string $uploadId = ''): bool
    {
        if (file_exists($path)) {
            unlink($path);
        }

        $tmp = \dirname($path) . DIRECTORY_SEPARATOR . 'tmp_' . basename($path) . DIRECTORY_SEPARATOR;

        if (! file_exists(\dirname($tmp))) { // Checks if directory path to file exists
            throw new NotFoundException('File doesn\'t exist: ' . \dirname($path));
        }
        $files = $this->scanDirectory($tmp);

        foreach ($files as $file) {
            $this->delete($file, true);
        }

        return rmdir($tmp);
    }

    /**
     * Read file or part of file by given path, offset and length.
     *
     * A full read returns a stream over the file itself; a bounded window is
     * copied into a temporary stream so consumers can read to its end. The
     * ETag of a local file is its MD5 hash, so a conditional read hashes the
     * whole file first.
     *
     * @throws StorageException
     */
    public function read(string $path, int $offset = 0, ?int $length = null, ?string $etag = null): StreamInterface
    {
        if (! $this->exists($path)) {
            throw new NotFoundException('File not found');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new StorageException('Failed to read file ' . $path);
        }

        if ($etag !== null && $this->hashHandle($handle, $path) !== $etag) {
            fclose($handle);
            throw new PreconditionFailedException('File ' . $path . ' no longer has ETag ' . $etag);
        }

        if ($offset > 0 && fseek($handle, $offset) !== 0) {
            fclose($handle);
            throw new StorageException('Failed to seek file ' . $path);
        }

        if ($length === null) {
            return Stream::fromResource($handle);
        }

        $window = fopen('php://temp', 'r+b');
        if ($window === false || stream_copy_to_stream($handle, $window, $length) === false) {
            fclose($handle);
            throw new StorageException('Failed to read file ' . $path);
        }
        fclose($handle);
        rewind($window);

        return Stream::fromResource($window);
    }

    /**
     * Write file by given path. The ETag of a local file is its MD5 hash,
     * taken from the bytes as they are written.
     */
    public function write(string $path, StreamInterface $data, string $contentType = ''): string
    {
        // Checks if directory path to file exists
        if (! file_exists(\dirname($path)) && ! @mkdir(\dirname($path), 0755, true)) {
            throw new StorageException('Can\'t create directory ' . \dirname($path));
        }

        return $this->writeAtomic($path, $data);
    }

    /**
     * Write a file where there is none yet. Opening the file exclusively makes
     * the check and the creation one step.
     */
    public function create(string $path, StreamInterface $data, string $contentType = ''): string
    {
        if (! file_exists(\dirname($path)) && ! @mkdir(\dirname($path), 0755, true)) {
            throw new StorageException('Can\'t create directory ' . \dirname($path));
        }

        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            if (file_exists($path)) {
                throw new PreconditionFailedException('File ' . $path . ' already exists');
            }

            throw new StorageException('Can\'t write file ' . $path);
        }

        // The path is claimed the moment the handle opens: a body that fails
        // must give it back, or the path can never be created again.
        try {
            return $this->pipe($handle, $path, $data);
        } catch (\Throwable $e) {
            @unlink($path);

            throw $e;
        }
    }

    /**
     * Write over the file with the given ETag. The check and the write are two
     * steps, so a replacement landing between them goes unnoticed and the last
     * writer wins; a local disk offers nothing better without a lock file. The
     * write itself is atomic, so no reader ever sees the two mixed.
     */
    public function replace(string $path, StreamInterface $data, string $etag, string $contentType = ''): string
    {
        if (! $this->exists($path)) {
            throw new PreconditionFailedException('File ' . $path . ' is gone');
        }

        if ($this->getFileHash($path) !== $etag) {
            throw new PreconditionFailedException('File ' . $path . ' no longer has ETag ' . $etag);
        }

        return $this->writeAtomic($path, $data);
    }

    /**
     * Pipe a stream into a sibling temporary file and rename it over the path.
     *
     * rename() is atomic, so a reader sees either the whole old file or the
     * whole new one, and a write that fails part way leaves the old one alone.
     *
     * @return string MD5 hash of the bytes written
     *
     * @throws StorageException
     */
    private function writeAtomic(string $path, StreamInterface $data): string
    {
        $tmp = tempnam(\dirname($path), 'tmp_write_' . basename($path) . '_');
        if ($tmp === false) {
            throw new StorageException('Can\'t write file ' . $path);
        }

        try {
            $hash = $this->writeFile($tmp, $data);
            @chmod($tmp, 0644 & ~umask());

            if (! rename($tmp, $path)) {
                throw new StorageException('Can\'t write file ' . $path);
            }
        } catch (\Throwable $e) {
            @unlink($tmp);

            throw $e;
        }

        return $hash;
    }

    /**
     * MD5 the open file and leave it positioned at the start again.
     *
     * @param  resource  $handle
     *
     * @throws StorageException
     */
    private function hashHandle($handle, string $path): string
    {
        $context = hash_init('md5');
        hash_update_stream($context, $handle);

        if (! rewind($handle)) {
            throw new StorageException('Failed to read file ' . $path);
        }

        return hash_final($context);
    }

    /**
     * Pipe a stream into a file, chunk by chunk.
     *
     * @return string MD5 hash of the bytes written
     *
     * @throws StorageException
     */
    private function writeFile(string $path, StreamInterface $data): string
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new StorageException('Can\'t write file ' . $path);
        }

        return $this->pipe($handle, $path, $data);
    }

    /**
     * Pipe a stream into an open file, chunk by chunk, and close it.
     *
     * @param  resource  $handle
     * @return string MD5 hash of the bytes written, the file's ETag
     *
     * @throws StorageException
     */
    private function pipe($handle, string $path, StreamInterface $data): string
    {
        if ($data->isSeekable()) {
            $data->rewind();
        }

        $hash = hash_init('md5');

        try {
            while (! $data->eof()) {
                $chunk = $data->read(self::PIPE_CHUNK_SIZE);
                $written = 0;
                $length = \strlen($chunk);
                if ($length === 0) {
                    break;
                }
                hash_update($hash, $chunk);
                while ($written < $length) {
                    $bytes = fwrite($handle, substr($chunk, $written));
                    if ($bytes === false || $bytes === 0) {
                        throw new StorageException('Can\'t write file ' . $path);
                    }
                    $written += $bytes;
                }
            }
        } finally {
            fclose($handle);
        }

        return hash_final($hash);
    }

    /**
     * Move file from given source to given path, Return true on success and false on failure.
     *
     * @throws StorageException
     */
    #[\Override]
    public function move(string $source, string $target): bool
    {
        if ($source === $target) {
            return false;
        }

        // Checks if directory path to file exists
        if (! file_exists(\dirname($target)) && ! @mkdir(\dirname($target), 0755, true)) {
            throw new StorageException('Can\'t create directory ' . \dirname($target));
        }

        return rename($source, $target);
    }

    /**
     * Delete file in given path, Return true on success and false on failure.
     */
    public function delete(string $path, bool $recursive = false): bool
    {
        if (is_dir($path) && $recursive) {
            $entries = scandir($path);

            if ($entries === false) {
                return false;
            }

            foreach ($entries as $entry) {
                if ($entry === '.') {
                    continue;
                }
                if ($entry === '..') {
                    continue;
                }
                if (! $this->delete($path . DIRECTORY_SEPARATOR . $entry, true)) {
                    return false;
                }
            }

            return rmdir($path);
        }

        if (is_file($path) || is_link($path)) {
            return unlink($path);
        }

        return false;
    }

    /**
     * Delete files in given path, path must be a directory. Return true on success and false on failure.
     */
    public function deletePath(string $path): bool
    {
        $path = realpath($this->getRoot() . DIRECTORY_SEPARATOR . $path);

        if ($path === false || ! is_dir($path)) {
            return false;
        }

        $files = $this->scanDirectory($path);

        foreach ($files as $file) {
            if (is_dir($file)) {
                $this->deletePath(substr_replace($file, '', 0, \strlen($this->getRoot() . DIRECTORY_SEPARATOR)));
            } else {
                $this->delete($file, true);
            }
        }

        return rmdir($path);
    }

    /**
     * Check if file exists
     */
    public function exists(string $path): bool
    {
        return file_exists($path);
    }

    /**
     * Size, last modification and MD5 hash of a file. The hash reads the whole file.
     */
    public function getFileInfo(string $path): FileInfo
    {
        if (! $this->exists($path)) {
            throw new NotFoundException('File not found: ' . $path);
        }

        $modified = filemtime($path);

        return new FileInfo(
            path: $path,
            size: $this->getFileSize($path),
            modifiedAt: $modified === false ? null : new \DateTimeImmutable('@' . $modified),
            etag: $this->getFileHash($path),
        );
    }

    /**
     * Returns given file path its size.
     *
     * @see http://php.net/manual/en/function.filesize.php
     */
    public function getFileSize(string $path): int
    {
        $size = $this->exists($path) ? filesize($path) : false;
        if ($size === false) {
            throw $this->exists($path) ? new StorageException('Failed to get size of file ' . $path) : new NotFoundException('File not found: ' . $path);
        }

        return $size;
    }

    /**
     * Returns given file path its mime type.
     *
     * @see http://php.net/manual/en/function.mime-content-type.php
     */
    public function getFileMimeType(string $path): string
    {
        $mimeType = $this->exists($path) ? mime_content_type($path) : false;
        if ($mimeType === false) {
            throw $this->exists($path) ? new StorageException('Failed to get mime type of file ' . $path) : new NotFoundException('File not found: ' . $path);
        }

        return $mimeType;
    }

    /**
     * Returns given file path its MD5 hash value.
     *
     * @see http://php.net/manual/en/function.md5-file.php
     */
    public function getFileHash(string $path): string
    {
        $hash = $this->exists($path) ? md5_file($path) : false;
        if ($hash === false) {
            throw $this->exists($path) ? new StorageException('Failed to hash file ' . $path) : new NotFoundException('File not found: ' . $path);
        }

        return $hash;
    }

    /**
     * Create a directory at the specified path.
     *
     * Returns true on success or if the directory already exists and false on error
     */
    public function createDirectory(string $path): bool
    {
        if (file_exists($path)) {
            return true;
        }

        return @mkdir($path, 0755, true);
    }

    /**
     * Get directory size in bytes.
     *
     * Return -1 on error
     *
     * Based on http://www.jonasjohn.de/snippets/php/dir-size.htm
     */
    public function getDirectorySize(string $path): int
    {
        if ($path === '') {
            return -1;
        }

        $size = 0;
        $path = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        $directory = opendir($path);

        if (! $directory) {
            return -1;
        }

        while (($file = readdir($directory)) !== false) {
            // Skip file pointers
            if ($file[0] === '.') {
                continue;
            }

            // Go recursive down, or add the file size
            if (is_dir($path . $file)) {
                $size += $this->getDirectorySize($path . $file . DIRECTORY_SEPARATOR);
            } else {
                $size += filesize($path . $file);
            }
        }

        closedir($directory);

        return $size;
    }

    /**
     * Get Partition Free Space.
     *
     * disk_free_space — Returns available space on filesystem or disk partition
     */
    public function getPartitionFreeSpace(): float
    {
        return disk_free_space($this->getRoot()) ?: 0.0;
    }

    /**
     * Get Partition Total Space.
     *
     * disk_total_space — Returns the total size of a filesystem or disk partition
     */
    public function getPartitionTotalSpace(): float
    {
        return disk_total_space($this->getRoot()) ?: 0.0;
    }

    /**
     * List all files under the given directory, recursively, sorted by path.
     *
     * The cursor is a numeric offset into the sorted listing.
     */
    public function listFiles(string $prefix = '', int $max = 1000, ?string $cursor = null): FileList
    {
        $paths = [];
        $pending = [rtrim($prefix, DIRECTORY_SEPARATOR)];
        while ($pending !== []) {
            $directory = array_pop($pending);
            foreach ($this->scanDirectory($directory) as $entry) {
                if (is_dir($entry)) {
                    $pending[] = $entry;
                } else {
                    $paths[] = $entry;
                }
            }
        }
        sort($paths);

        $offset = is_numeric($cursor) ? (int) $cursor : 0;
        $page = \array_slice($paths, $offset, $max);

        $files = [];
        foreach ($page as $path) {
            $modified = filemtime($path);
            $files[] = new FileInfo(
                path: $path,
                size: filesize($path) ?: 0,
                modifiedAt: $modified === false ? null : new \DateTimeImmutable('@' . $modified),
            );
        }

        return new FileList(
            files: $files,
            cursor: $offset + \count($page) < \count($paths) ? (string) ($offset + \count($page)) : null,
        );
    }

    /**
     * Get all files and directories directly inside a directory, hidden entries included.
     *
     * @return string[]
     */
    private function scanDirectory(string $dir): array
    {
        $dir = rtrim($dir, DIRECTORY_SEPARATOR);
        $files = glob($dir . DIRECTORY_SEPARATOR . '*') ?: [];

        /**
         * Hidden files
         */
        foreach (glob($dir . DIRECTORY_SEPARATOR . '.[!.]*') ?: [] as $file) {
            $files[] = $file;
        }

        return $files;
    }
}
