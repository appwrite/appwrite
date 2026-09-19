<?php

declare(strict_types=1);

namespace Utopia\Tests\Storage\Device;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Utopia\Psr7\Stream;
use Utopia\Storage\Device\Local;
use Utopia\Storage\DeviceType;
use Utopia\Storage\Exception\NotFoundException;
use Utopia\Storage\Exception\PreconditionFailedException;
use Utopia\Storage\Exception\UploadException;
use Utopia\Storage\FileInfo;

final class LocalTest extends TestCase
{
    /**
     * @return resource
     */
    private function openStream(string $path)
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Failed to open ' . $path);
        }

        return $handle;
    }

    /**
     * @param  resource  $handle
     * @param  int<1, max>  $length
     */
    private function readBytes($handle, int $length): string
    {
        $contents = fread($handle, $length);
        if ($contents === false) {
            throw new \RuntimeException('Failed to read stream');
        }

        return $contents;
    }

    /**
     * A stream that hands over one chunk and then fails, standing in for a
     * full disk or a connection dropped mid-body.
     */
    private function failingStream(): StreamInterface
    {
        return new class implements StreamInterface {
            private bool $sent = false;

            public function read(int $length): string
            {
                if ($this->sent) {
                    throw new \DomainException('Stream failed');
                }

                $this->sent = true;

                return 'partial';
            }

            public function eof(): bool
            {
                return false;
            }

            public function isReadable(): bool
            {
                return true;
            }

            public function isSeekable(): bool
            {
                return false;
            }

            public function isWritable(): bool
            {
                return false;
            }

            public function __toString(): string
            {
                return '';
            }

            public function close(): void {}

            public function detach()
            {
                return null;
            }

            public function getSize(): ?int
            {
                return null;
            }

            public function tell(): int
            {
                return 0;
            }

            public function seek(int $offset, int $whence = SEEK_SET): void {}

            public function rewind(): void {}

            public function write(string $string): int
            {
                return 0;
            }

            public function getContents(): string
            {
                return '';
            }

            public function getMetadata(?string $key = null): mixed
            {
                return null;
            }
        };
    }

    private Local $object;

    protected function setUp(): void
    {
        $this->object = new Local(realpath(__DIR__ . '/../../resources/disk-a') ?: '');
    }

    protected function tearDown(): void {}

    public function testPaths(): void
    {
        $this->assertSame('/storage/functions', $this->object->getAbsolutePath('////storage/functions'));
        $this->assertSame('/storage/functions', $this->object->getAbsolutePath('storage/functions'));
        $this->assertSame('/storage/functions', $this->object->getAbsolutePath('/storage/functions'));
        $this->assertSame('/storage/functions', $this->object->getAbsolutePath('//storage///functions//'));
        $this->assertSame('/storage/functions', $this->object->getAbsolutePath('\\\\\storage\functions'));
        $this->assertSame('/storage/functions', $this->object->getAbsolutePath('..\\\\\//storage\\//functions'));
        $this->assertSame('/storage/functions', $this->object->getAbsolutePath('./..\\\\\//storage\\//functions'));
    }

    public function testType(): void
    {
        $this->assertSame(DeviceType::Local, $this->object->getType());
    }

    public function testRoot(): void
    {
        $this->assertSame($this->object->getRoot(), $this->object->getAbsolutePath(__DIR__ . '/../../resources/disk-a'));
    }

    public function testPath(): void
    {
        $this->assertSame($this->object->getPath('image.png'), $this->object->getAbsolutePath(__DIR__ . '/../../resources/disk-a') . '/image.png');
    }

    public function testWrite(): void
    {
        $this->assertSame(md5('Hello World'), $this->object->write($this->object->getPath('text.txt'), new Stream('Hello World')));
        $this->assertFileExists($this->object->getPath('text.txt'));
        $this->assertIsReadable($this->object->getPath('text.txt'));

        $this->object->delete($this->object->getPath('text.txt'));
    }

    public function testRead(): void
    {
        $this->assertSame(md5('Hello World'), $this->object->write($this->object->getPath('text-for-read.txt'), new Stream('Hello World')));
        $this->assertSame('Hello World', (string) $this->object->read($this->object->getPath('text-for-read.txt')));

        $this->object->delete($this->object->getPath('text-for-read.txt'));
    }

    public function testWriteRewindsPartiallyConsumedStream(): void
    {
        $stream = new Stream('Hello World');
        $stream->read(6); // consume a prefix — seekable streams are sent from the beginning

        $path = $this->object->getPath('text-for-rewind.txt');
        $this->assertSame(md5('Hello World'), $this->object->write($path, $stream));
        $this->assertSame('Hello World', (string) $this->object->read($path));

        $this->object->delete($path);
    }

    public function testReadNonExistentFile(): void
    {
        $this->expectException(NotFoundException::class);
        $this->object->read($this->object->getPath('non-existent-file.txt'));
    }

    public function testFileExists(): void
    {
        $this->assertSame(md5('Hello World'), $this->object->write($this->object->getPath('text-for-test-exists.txt'), new Stream('Hello World')));
        $this->assertEquals(true, $this->object->exists($this->object->getPath('text-for-test-exists.txt')));
        $this->assertEquals(false, $this->object->exists($this->object->getPath('text-for-test-doesnt-exist.txt')));

        $this->object->delete($this->object->getPath('text-for-test-exists.txt'));
    }

    public function testMove(): void
    {
        $this->assertSame(md5('Hello World'), $this->object->write($this->object->getPath('text-for-move.txt'), new Stream('Hello World')));
        $this->assertSame('Hello World', (string) $this->object->read($this->object->getPath('text-for-move.txt')));
        $this->assertEquals(true, $this->object->move($this->object->getPath('text-for-move.txt'), $this->object->getPath('text-for-move-new.txt')));
        $this->assertSame('Hello World', (string) $this->object->read($this->object->getPath('text-for-move-new.txt')));
        $this->assertFileDoesNotExist($this->object->getPath('text-for-move.txt'));
        $this->assertIsNotReadable($this->object->getPath('text-for-move.txt'));
        $this->assertFileExists($this->object->getPath('text-for-move-new.txt'));
        $this->assertIsReadable($this->object->getPath('text-for-move-new.txt'));

        $this->object->delete($this->object->getPath('text-for-move-new.txt'));
    }

    public function testCopyRejectsInvalidChunkSize(): void
    {
        $device = new Local(realpath(__DIR__ . '/../../resources/disk-b') ?: '');

        $this->expectExceptionMessage('Chunk size must be greater than zero');
        $this->object->copy($this->object->getPath('kitten-1.jpg'), $device->getPath('kitten-1.jpg'), $device, 0);
    }

    public function testListFiles(): void
    {
        $directory = $this->object->getPath('list-files');
        $this->object->write($directory . '/a.txt', new Stream('aa'));
        $this->object->write($directory . '/nested/b.txt', new Stream('bb'));
        $this->object->write($directory . '/.hidden', new Stream('hh'));

        $list = $this->object->listFiles($directory, 2);
        $this->assertCount(2, $list->files);
        $this->assertNotNull($list->cursor);

        $rest = $this->object->listFiles($directory, 10, $list->cursor);
        $this->assertCount(1, $rest->files);
        $this->assertNull($rest->cursor);

        $paths = array_map(fn(FileInfo $file): string => $file->path, array_merge($list->files, $rest->files));
        $this->assertContains($directory . '/a.txt', $paths);
        $this->assertContains($directory . '/nested/b.txt', $paths);
        $this->assertContains($directory . '/.hidden', $paths);
        $this->assertSame(2, $list->files[1]->size);

        $this->object->delete($directory, true);
    }

    public function testStatOnMissingFileThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->object->getFileSize($this->object->getPath('does-not-exist.txt'));
    }

    public function testMoveIdenticalName(): void
    {
        $file = '/kitten-1.jpg';
        $this->assertFalse($this->object->move($file, $file));
    }

    public function testDelete(): void
    {
        $this->assertSame(md5('Hello World'), $this->object->write($this->object->getPath('text-for-delete.txt'), new Stream('Hello World')));
        $this->assertSame('Hello World', (string) $this->object->read($this->object->getPath('text-for-delete.txt')));
        $this->assertEquals(true, $this->object->delete($this->object->getPath('text-for-delete.txt')));
        $this->assertFileDoesNotExist($this->object->getPath('text-for-delete.txt'));
        $this->assertIsNotReadable($this->object->getPath('text-for-delete.txt'));
    }

    public function testRecursiveDeleteRemovesHiddenFiles(): void
    {
        $directory = $this->object->getPath('delete-hidden');

        $this->assertTrue($this->object->createDirectory($directory));
        $this->assertSame(md5('secret'), $this->object->write($directory . DIRECTORY_SEPARATOR . '.hidden', new Stream('secret')));
        $this->assertSame(md5('visible'), $this->object->write($directory . DIRECTORY_SEPARATOR . 'visible', new Stream('visible')));

        $this->assertTrue($this->object->delete($directory, true));
        $this->assertFalse($this->object->exists($directory));
    }

    public function testFileSize(): void
    {
        $this->assertSame(599639, $this->object->getFileSize(__DIR__ . '/../../resources/disk-a/kitten-1.jpg'));
        $this->assertSame(131958, $this->object->getFileSize(__DIR__ . '/../../resources/disk-a/kitten-2.jpg'));
    }

    public function testFileMimeType(): void
    {
        $this->assertSame('image/jpeg', $this->object->getFileMimeType(__DIR__ . '/../../resources/disk-a/kitten-1.jpg'));
        $this->assertSame('image/jpeg', $this->object->getFileMimeType(__DIR__ . '/../../resources/disk-a/kitten-2.jpg'));
        $this->assertSame('image/png', $this->object->getFileMimeType(__DIR__ . '/../../resources/disk-b/kitten-1.png'));
        $this->assertSame('image/png', $this->object->getFileMimeType(__DIR__ . '/../../resources/disk-b/kitten-2.png'));
    }

    public function testFileHash(): void
    {
        $this->assertSame('7551f343143d2e24ab4aaf4624996b6a', $this->object->getFileHash(__DIR__ . '/../../resources/disk-a/kitten-1.jpg'));
        $this->assertSame('81702fdeef2e55b1a22617bce4951cb5', $this->object->getFileHash(__DIR__ . '/../../resources/disk-a/kitten-2.jpg'));
        $this->assertSame('03010f4f02980521a8fd6213b52ec313', $this->object->getFileHash(__DIR__ . '/../../resources/disk-b/kitten-1.png'));
        $this->assertSame('8a9ed992b77e4b62b10e3a5c8ed72062', $this->object->getFileHash(__DIR__ . '/../../resources/disk-b/kitten-2.png'));
    }

    public function testDirectoryCreate(): void
    {
        $directory = uniqid();
        $this->assertTrue($this->object->createDirectory(__DIR__ . "/$directory"));
        $this->assertTrue($this->object->exists(__DIR__ . "/$directory"));
    }

    public function testDirectorySize(): void
    {
        $this->assertGreaterThan(0, $this->object->getDirectorySize(__DIR__ . '/../../resources/disk-a/'));
        $this->assertGreaterThan(0, $this->object->getDirectorySize(__DIR__ . '/../../resources/disk-b/'));

        // Paths without a trailing separator must measure the same tree.
        $this->assertSame(
            $this->object->getDirectorySize(__DIR__ . '/../../resources/disk-a/'),
            $this->object->getDirectorySize(__DIR__ . '/../../resources/disk-a'),
        );

        // An empty path is an error, not the filesystem root.
        $this->assertSame(-1, $this->object->getDirectorySize(''));
    }

    /** Without a started multipart upload there is nothing to reclaim — aborting could delete a pre-existing destination. */
    public function testFailedCopyDoesNotAbortOrDeleteExistingDestination(): void
    {
        $source = $this->object->getPath(uniqid() . '.txt');
        $this->object->write($source, new Stream(str_repeat('a', 30)), 'text/plain');

        $device = new class (sys_get_temp_dir()) extends Local {
            public int $aborts = 0;

            #[\Override]
            public function upload(StreamInterface $data, string $path, string $contentType, int $chunk = 1, int $chunks = 1, array &$metadata = []): int
            {
                if ($chunk === 2) {
                    throw new UploadException('Injected chunk failure');
                }

                return parent::upload($data, $path, $contentType, $chunk, $chunks, $metadata);
            }

            #[\Override]
            public function abort(string $path, string $uploadId = ''): bool
            {
                ++$this->aborts;

                return parent::abort($path, $uploadId);
            }
        };

        $destination = $device->getPath(uniqid() . '-dest.txt');
        $device->write($destination, new Stream('pre-existing'), 'text/plain');

        try {
            $this->object->copy($source, $destination, $device, 10);
            self::fail('Expected the injected chunk failure to surface');
        } catch (UploadException $e) {
            $this->assertSame('Injected chunk failure', $e->getMessage());
        } finally {
            $this->object->delete($source);
        }

        $this->assertSame(0, $device->aborts);
        $this->assertSame('pre-existing', (string) $device->read($destination));
        $device->delete($destination);
    }

    public function testPartUpload(): string
    {
        $source = __DIR__ . '/../../resources/disk-a/large_file.mp4';
        $dest = $this->object->getPath('uploaded.mp4');
        $totalSize = $this->object->getFileSize($source);
        $chunkSize = 2097152;

        $chunks = (int) ceil($totalSize / $chunkSize);

        $chunk = 1;
        $start = 0;

        $handle = $this->openStream($source);
        while ($start < $totalSize) {
            $contents = $this->readBytes($handle, $chunkSize);
            $this->object->upload(new Stream($contents), $dest, '', $chunk, $chunks);
            $start += \strlen($contents);
            ++$chunk;
            fseek($handle, $start);
        }
        fclose($handle);
        $this->assertEquals(filesize($source), $this->object->getFileSize($dest));
        $this->assertEquals(md5_file($source), $this->object->getFileHash($dest));

        return $dest;
    }

    public function testUploadDoesNotFinalizeUntilLastChunk(): void
    {
        $dest = $this->object->getPath('chunked-phase-upload.txt');
        $metadata = [];

        $this->object->upload(new Stream('bbb'), $dest, '', 2, 3, $metadata);
        $this->assertFalse($this->object->exists($dest));
        $this->object->upload(new Stream('aaa'), $dest, '', 1, 3, $metadata);
        $this->assertFalse($this->object->exists($dest));

        $this->assertSame(3, $this->object->upload(new Stream('ccc'), $dest, '', 3, 3, $metadata));
        $this->assertSame('aaabbbccc', (string) $this->object->read($dest));
        // Finalizing an already assembled upload is idempotent.
        $this->assertTrue($this->object->finalize($dest, 3, $metadata));

        $this->object->delete($dest);
    }

    public function testFinalizeRequiresAllLocalChunks(): void
    {
        $dest = $this->object->getPath('chunked-phase-missing.txt');
        $metadata = [];

        $this->object->upload(new Stream('aaa'), $dest, '', 1, 2, $metadata);

        try {
            $this->object->finalize($dest, 2, $metadata);
            $this->fail('Expected missing chunk exception');
        } catch (UploadException $e) {
            $this->assertSame('Missing chunk 2', $e->getMessage());
        } finally {
            $this->object->abort($dest);
        }
    }

    public function testPartUploadRetry(): string
    {
        $source = __DIR__ . '/../../resources/disk-a/large_file.mp4';
        $dest = $this->object->getPath('uploaded2.mp4');
        $totalSize = filesize($source);
        // AWS S3 requires each part to be at least 5MB except for last part
        $chunkSize = 5 * 1024 * 1024;

        $chunks = (int) ceil($totalSize / $chunkSize);

        $chunk = 1;
        $start = 0;
        $handle = $this->openStream($source);
        while ($start < $totalSize) {
            $contents = $this->readBytes($handle, $chunkSize);
            $this->object->upload(new Stream($contents), $dest, '', $chunk, $chunks);
            $start += \strlen($contents);
            ++$chunk;
            break;
        }
        fclose($handle);

        $chunk = 1;
        $start = 0;
        // retry from first to make sure duplicate chunk re-upload works without issue
        $handle = $this->openStream($source);
        while ($start < $totalSize) {
            $contents = $this->readBytes($handle, $chunkSize);
            $this->object->upload(new Stream($contents), $dest, '', $chunk, $chunks);
            $start += \strlen($contents);
            ++$chunk;
            fseek($handle, $start);
        }
        fclose($handle);

        $this->assertEquals(filesize($source), $this->object->getFileSize($dest));
        $this->assertEquals(md5_file($source), $this->object->getFileHash($dest));

        return $dest;
    }

    public function testAbort(): void
    {
        $source = __DIR__ . '/../../resources/disk-a/large_file.mp4';
        $dest = $this->object->getPath('abcduploaded.mp4');
        $totalSize = $this->object->getFileSize($source);
        $chunkSize = 2097152;
        $chunks = (int) ceil($totalSize / $chunkSize);

        $chunk = 1;
        $start = 0;

        $handle = $this->openStream($source);
        while ($chunk < 3) { // only upload two chunks
            $contents = $this->readBytes($handle, $chunkSize);
            $this->object->upload(new Stream($contents), $dest, '', $chunk, $chunks);
            $start += \strlen($contents);
            ++$chunk;
            fseek($handle, $start);
        }
        fclose($handle);

        // using file name with same first four chars
        $source = __DIR__ . '/../../resources/disk-a/large_file.mp4';
        $dest1 = $this->object->getPath('abcduploaded2.mp4');
        $totalSize = $this->object->getFileSize($source);
        $chunkSize = 2097152;
        $chunks = (int) ceil($totalSize / $chunkSize);

        $chunk = 1;
        $start = 0;

        $handle = $this->openStream($source);
        while ($chunk < 3) { // only upload two chunks
            $contents = $this->readBytes($handle, $chunkSize);
            $this->object->upload(new Stream($contents), $dest1, '', $chunk, $chunks);
            $start += \strlen($contents);
            ++$chunk;
            fseek($handle, $start);
        }
        fclose($handle);

        $this->assertTrue($this->object->abort($dest));
        $this->assertTrue($this->object->abort($dest1));
    }

    #[Depends('testPartUpload')]
    public function testPartRead(string $path): void
    {
        $source = __DIR__ . '/../../resources/disk-a/large_file.mp4';
        $chunk = file_get_contents($source, false, null, 0, 500);
        $readChunk = (string) $this->object->read($path, 0, 500);
        $this->assertEquals($chunk, $readChunk);
    }

    public function testPartitionFreeSpace(): void
    {
        $this->assertGreaterThan(0, $this->object->getPartitionFreeSpace());
    }

    public function testPartitionTotalSpace(): void
    {
        $this->assertGreaterThan(0, $this->object->getPartitionTotalSpace());
    }

    #[Depends('testPartUpload')]
    public function testCopyLarge(string $path): void
    {
        // chunked file
        $device = new Local(realpath(__DIR__ . '/../../resources/disk-b') ?: '');
        $destination = $device->getPath('largefile.mp4');

        $this->assertTrue($this->object->copy($path, $destination, $device, 10000000)); // 10 mb chunks
        $this->assertTrue($device->exists($destination));
        $this->assertSame('video/mp4', $device->getFileMimeType($destination));

        $device->delete($destination);
        $this->object->delete($path);
    }

    public function testCopySmall(): void
    {
        $device = new Local(realpath(__DIR__ . '/../../resources/disk-b') ?: '');

        $path = $this->object->getPath('text-for-read.txt');
        $this->object->write($path, new Stream('Hello World'));

        $destination = $device->getPath('hello.txt');
        $this->assertTrue($this->object->copy($path, $destination, $device, 10000000)); // 10 mb chunks
        $this->assertTrue($device->exists($destination));
        $this->assertSame('Hello World', (string) $device->read($destination));

        $this->object->delete($path);
        $device->delete($destination);
    }

    public function testDeletePath(): void
    {
        // Test Single Object
        $path = $this->object->getPath('text-for-delete-path.txt');
        $path = str_ireplace($this->object->getRoot(), $this->object->getRoot() . DIRECTORY_SEPARATOR . 'bucket', $path);
        $this->assertSame(md5('Hello World'), $this->object->write($path, new Stream('Hello World'), 'text/plain'));
        $this->assertEquals(true, $this->object->exists($path));
        $this->assertEquals(true, $this->object->deletePath('bucket'));
        $this->assertEquals(false, $this->object->exists($path));

        // Test Multiple Objects
        $path = $this->object->getPath('text-for-delete-path1.txt');
        $path = str_ireplace($this->object->getRoot(), $this->object->getRoot() . DIRECTORY_SEPARATOR . 'bucket', $path);
        $this->assertSame(md5('Hello World'), $this->object->write($path, new Stream('Hello World'), 'text/plain'));
        $this->assertEquals(true, $this->object->exists($path));

        $path2 = $this->object->getPath('text-for-delete-path2.txt');
        $path2 = str_ireplace($this->object->getRoot(), $this->object->getRoot() . DIRECTORY_SEPARATOR . 'bucket', $path2);
        $this->assertSame(md5('Hello World'), $this->object->write($path2, new Stream('Hello World'), 'text/plain'));
        $this->assertEquals(true, $this->object->exists($path2));

        $path3 = $this->object->getPath('.hidden.txt');
        $path3 = str_ireplace($this->object->getRoot(), $this->object->getRoot() . DIRECTORY_SEPARATOR . 'bucket', $path3);
        $this->assertSame(md5('Hello World'), $this->object->write($path3, new Stream('Hello World'), 'text/plain'));
        $this->assertEquals(true, $this->object->exists($path3));

        $this->assertEquals(true, $this->object->deletePath('bucket/'));
        $this->assertEquals(false, $this->object->exists($path));
        $this->assertEquals(false, $this->object->exists($path2));
        $this->assertEquals(false, $this->object->exists($path3));
    }

    public function testListFilesEmptyAndGrowingDirectory(): void
    {
        $dir = $this->object->getPath('get-files-test');

        $this->assertTrue($this->object->createDirectory($dir));

        $this->assertCount(0, $this->object->listFiles($dir)->files);

        $this->object->write($dir . DIRECTORY_SEPARATOR . 'new-file.txt', new Stream('Hello World'));
        $this->object->write($dir . DIRECTORY_SEPARATOR . 'new-file-two.txt', new Stream('Hello World'));

        $this->assertCount(2, $this->object->listFiles($dir)->files);

        $this->assertTrue($this->object->deletePath('get-files-test'));
    }

    public function testNestedDeletePath(): void
    {
        $dir = $this->object->getPath('nested-delete-path-test');
        $dir2 = $dir . DIRECTORY_SEPARATOR . 'dir2';
        $dir3 = $dir2 . DIRECTORY_SEPARATOR . 'dir3';

        $this->assertTrue($this->object->createDirectory($dir));
        $this->object->write($dir . DIRECTORY_SEPARATOR . 'new-file.txt', new Stream('Hello World'));
        $this->assertTrue($this->object->createDirectory($dir2));
        $this->object->write($dir2 . DIRECTORY_SEPARATOR . 'new-file-2.txt', new Stream('Hello World'));
        $this->assertTrue($this->object->createDirectory($dir3));
        $this->object->write($dir3 . DIRECTORY_SEPARATOR . 'new-file-3.txt', new Stream('Hello World'));

        $this->assertTrue($this->object->deletePath('nested-delete-path-test'));
        $this->assertFalse($this->object->exists($dir));
    }

    // -------------------------------------------------------------------------
    // joinChunks tests
    // -------------------------------------------------------------------------

    /**
     * Create a self-contained Local storage instance in a fresh temp directory.
     * The caller is responsible for deleting the root after the test.
     */
    private function makeJoinTestStorage(): Local
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'utopia-join-test-' . uniqid();
        mkdir($dir, 0755, true);

        return new Local($dir);
    }

    public function testJoinChunksAssemblesContentCorrectly(): void
    {
        $storage = $this->makeJoinTestStorage();
        $dest = $storage->getRoot() . DIRECTORY_SEPARATOR . 'test.dat';

        $storage->upload(new Stream('AAAA'), $dest, 'application/octet-stream', 1, 3);
        $storage->upload(new Stream('BBBB'), $dest, 'application/octet-stream', 2, 3);
        $storage->upload(new Stream('CCCC'), $dest, 'application/octet-stream', 3, 3);

        $this->assertFileExists($dest);
        $this->assertSame('AAAABBBBCCCC', file_get_contents($dest));

        $storage->delete($storage->getRoot(), true);
    }

    public function testJoinChunksCleansUpTempFilesOnSuccess(): void
    {
        $storage = $this->makeJoinTestStorage();
        $dest = $storage->getRoot() . DIRECTORY_SEPARATOR . 'test.dat';
        $tmpDir = $storage->getRoot() . DIRECTORY_SEPARATOR . 'tmp_test.dat';
        $tmpAssemble = $storage->getRoot() . DIRECTORY_SEPARATOR . 'tmp_assemble_test.dat';

        $storage->upload(new Stream('AAAA'), $dest, 'application/octet-stream', 1, 3);
        $storage->upload(new Stream('BBBB'), $dest, 'application/octet-stream', 2, 3);
        $storage->upload(new Stream('CCCC'), $dest, 'application/octet-stream', 3, 3);

        $this->assertDirectoryDoesNotExist($tmpDir, 'Temp chunk directory should be removed after assembly');
        $this->assertFileDoesNotExist($tmpAssemble, 'Temp assembly file should be removed after rename');
        for ($i = 1; $i <= 3; ++$i) {
            $this->assertFileDoesNotExist(
                $tmpDir . DIRECTORY_SEPARATOR . 'test.part.' . $i,
                "Part file $i should be removed after assembly",
            );
        }

        $storage->delete($storage->getRoot(), true);
    }

    public function testJoinChunksMissingPartDoesNotFinalize(): void
    {
        $storage = $this->makeJoinTestStorage();
        $dest = $storage->getRoot() . DIRECTORY_SEPARATOR . 'test.dat';
        $tmpDir = $storage->getRoot() . DIRECTORY_SEPARATOR . 'tmp_test.dat';
        $tmpAssemble = $storage->getRoot() . DIRECTORY_SEPARATOR . 'tmp_assemble_test.dat';

        $storage->upload(new Stream('AAAA'), $dest, 'application/octet-stream', 1, 3);
        $storage->upload(new Stream('BBBB'), $dest, 'application/octet-stream', 2, 3);

        // Simulate a missing/corrupted chunk by deleting part 1 before the
        // final upload triggers assembly.
        unlink($tmpDir . DIRECTORY_SEPARATOR . 'test.part.1');

        // Uploading the final chunk should NOT throw or finalize,
        // because part 1 is missing and the part-file count is only 2.
        $storage->upload(new Stream('CCCC'), $dest, 'application/octet-stream', 3, 3);

        $this->assertFileDoesNotExist($dest, 'Final file must not be created when a chunk is missing');
        $this->assertFileDoesNotExist($tmpAssemble, 'Temp assembly file must not be created');
        // Surviving parts must remain so the upload can be retried.
        $this->assertFileExists(
            $tmpDir . DIRECTORY_SEPARATOR . 'test.part.2',
            'Part 2 must be preserved for retry',
        );
        $this->assertFileExists(
            $tmpDir . DIRECTORY_SEPARATOR . 'test.part.3',
            'Part 3 must be preserved for retry',
        );

        // Re-upload the missing chunk — assembly should now succeed.
        $storage->upload(new Stream('AAAA'), $dest, 'application/octet-stream', 1, 3);
        $this->assertFileExists($dest, 'Final file should be created after missing chunk is re-uploaded');
        $this->assertSame('AAAABBBBCCCC', file_get_contents($dest), 'Re-uploaded chunk must allow correct assembly');

        $storage->delete($storage->getRoot(), true);
    }

    public function testJoinChunksStaleAssemblyFileIsOverwritten(): void
    {
        $storage = $this->makeJoinTestStorage();
        $dest = $storage->getRoot() . DIRECTORY_SEPARATOR . 'test.dat';

        $storage->upload(new Stream('AAAA'), $dest, 'application/octet-stream', 1, 3);
        $storage->upload(new Stream('BBBB'), $dest, 'application/octet-stream', 2, 3);

        // Simulate a stale assembly file left by a previously crashed attempt.
        // With unique temp paths (tempnam), stale files at old hardcoded paths
        // are naturally bypassed rather than overwritten.
        $staleFile = $storage->getRoot() . DIRECTORY_SEPARATOR . 'tmp_assemble_test.dat';
        file_put_contents($staleFile, 'STALE_GARBAGE_DATA');

        $storage->upload(new Stream('CCCC'), $dest, 'application/octet-stream', 3, 3);

        $this->assertFileExists($dest);
        $this->assertSame('AAAABBBBCCCC', file_get_contents($dest), 'Stale assembly file must not corrupt output');

        $storage->delete($storage->getRoot(), true);
    }

    public function testOutOfOrderUpload(): void
    {
        $storage = $this->makeJoinTestStorage();
        $dest = $storage->getRoot() . DIRECTORY_SEPARATOR . 'out-of-order.dat';

        $storage->upload(new Stream('CCCC'), $dest, 'application/octet-stream', 3, 3);
        $this->assertFileDoesNotExist($dest, 'File should not be assembled after chunk 3');

        $storage->upload(new Stream('AAAA'), $dest, 'application/octet-stream', 1, 3);
        $this->assertFileDoesNotExist($dest, 'File should not be assembled after chunk 1');

        $storage->upload(new Stream('BBBB'), $dest, 'application/octet-stream', 2, 3);
        $this->assertFileExists($dest, 'File should be assembled after final chunk');
        $this->assertSame('AAAABBBBCCCC', file_get_contents($dest), 'Chunks must be assembled in correct order');

        $storage->delete($storage->getRoot(), true);
    }

    public function testOutOfOrderUploadWithRetry(): void
    {
        $storage = $this->makeJoinTestStorage();
        $dest = $storage->getRoot() . DIRECTORY_SEPARATOR . 'out-of-order-retry.dat';

        $storage->upload(new Stream('BBBB'), $dest, 'application/octet-stream', 2, 3);
        $storage->upload(new Stream('AAAA'), $dest, 'application/octet-stream', 1, 3);

        // Re-upload chunk 2 (duplicate) — should be silently ignored
        $storage->upload(new Stream('BBBB'), $dest, 'application/octet-stream', 2, 3);
        $this->assertFileDoesNotExist($dest, 'File should not be assembled after duplicate retry');

        $storage->upload(new Stream('CCCC'), $dest, 'application/octet-stream', 3, 3);
        $this->assertFileExists($dest, 'File should be assembled after final chunk');
        $this->assertSame('AAAABBBBCCCC', file_get_contents($dest), 'Duplicate retry must not corrupt final file');

        $storage->delete($storage->getRoot(), true);
    }

    public function testParallelChunkUpload(): void
    {
        $storage = $this->makeJoinTestStorage();
        $dest = $storage->getRoot() . DIRECTORY_SEPARATOR . 'parallel.dat';

        // Upload chunk 1 (creates temp directory)
        $storage->upload(new Stream('AAAA'), $dest, 'application/octet-stream', 1, 2);

        // Upload chunk 2 (assembles the file)
        $storage->upload(new Stream('BBBB'), $dest, 'application/octet-stream', 2, 2);

        // Verify file exists and is correct
        $this->assertFileExists($dest);
        $this->assertSame('AAAABBBB', file_get_contents($dest));

        // Simulate the race where another request already assembled the file
        // by calling joinChunks directly when the file already exists
        $reflection = new \ReflectionClass($storage);
        $method = $reflection->getMethod('joinChunks');

        try {
            $method->invoke($storage, $dest, 2);
        } catch (\Exception $e) {
            $this->fail('Duplicate assembly should not throw: ' . $e->getMessage());
        }

        $this->assertFileExists($dest, 'File should still exist after duplicate assembly attempt');
        $this->assertSame('AAAABBBB', file_get_contents($dest), 'File content must not be corrupted');

        $storage->delete($storage->getRoot(), true);
    }

    public function testFileInfoReportsSizeModificationAndHash(): void
    {
        $path = $this->object->getPath('info.txt');
        $this->object->write($path, new Stream('Hello World'), 'text/plain');

        $info = $this->object->getFileInfo($path);

        $this->assertSame($path, $info->path);
        $this->assertSame(11, $info->size);
        $this->assertSame(md5('Hello World'), $info->etag);
        $this->assertEqualsWithDelta(time(), $info->modifiedAt?->getTimestamp() ?? 0, 5);

        $this->object->delete($path);
    }

    public function testFileInfoOfAMissingFileThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->object->getFileInfo($this->object->getPath('missing.txt'));
    }

    public function testCreateWritesOnlyWhereNothingIs(): void
    {
        $path = $this->object->getPath('created.txt');

        $this->assertSame(md5('first'), $this->object->create($path, new Stream('first'), 'text/plain'));
        $this->assertSame('first', file_get_contents($path));

        try {
            $this->object->create($path, new Stream('second'), 'text/plain');
            self::fail('Expected precondition failure');
        } catch (PreconditionFailedException) {
            $this->assertSame('first', file_get_contents($path), 'the file is left alone');
        }

        $this->object->delete($path);
    }

    public function testReplaceWritesOverTheNamedVersionOnly(): void
    {
        $path = $this->object->getPath('replaced.txt');
        $etag = $this->object->create($path, new Stream('first'), 'text/plain');

        $this->assertSame(md5('second'), $this->object->replace($path, new Stream('second'), $etag, 'text/plain'));
        $this->assertSame('second', file_get_contents($path));

        try {
            $this->object->replace($path, new Stream('third'), $etag, 'text/plain');
            self::fail('Expected precondition failure');
        } catch (PreconditionFailedException) {
            $this->assertSame('second', file_get_contents($path), 'a stale ETag changes nothing');
        }

        $this->object->delete($path);

        $this->expectException(PreconditionFailedException::class);
        $this->object->replace($path, new Stream('third'), md5('second'), 'text/plain');
    }

    public function testReadWithEtagRefusesAReplacedFile(): void
    {
        $path = $this->object->getPath('conditional.txt');
        $etag = $this->object->create($path, new Stream('Hello World'), 'text/plain');

        $this->assertSame('World', (string) $this->object->read($path, 6, 5, $etag));

        $this->object->write($path, new Stream('Goodbye World'), 'text/plain');

        try {
            $this->expectException(PreconditionFailedException::class);
            $this->object->read($path, 6, 5, $etag);
        } finally {
            $this->object->delete($path);
        }
    }

    public function testASingleChunkOfAnUploadWithUnknownCountIsJoined(): void
    {
        $path = $this->object->getPath('single-chunk.txt');
        $this->object->write($path, new Stream('old contents'), 'text/plain');

        $metadata = [];
        $this->object->upload(new Stream('new contents'), $path, 'text/plain', 1, 0, $metadata);
        $this->assertSame('old contents', file_get_contents($path), 'a chunk of an upload with unknown count is not the file yet');

        $this->assertTrue($this->object->finalize($path, 1, $metadata));
        $this->assertSame('new contents', file_get_contents($path));
        $this->assertTrue($this->object->finalize($path, 1, $metadata), 'finalizing again is not an error');

        $this->object->delete($path);
    }

    public function testFinalizeReplacesAnExistingFile(): void
    {
        $path = $this->object->getPath('replaced-by-upload.txt');
        $this->object->write($path, new Stream('old contents'), 'text/plain');

        $metadata = [];
        $this->object->upload(new Stream('new '), $path, 'text/plain', 1, 0, $metadata);
        $this->object->upload(new Stream('contents'), $path, 'text/plain', 2, 0, $metadata);
        $this->assertSame('old contents', file_get_contents($path), 'nothing changes before finalize');

        $this->assertTrue($this->object->finalize($path, 2, $metadata));
        $this->assertSame('new contents', file_get_contents($path));
        $this->assertTrue($this->object->finalize($path, 2, $metadata), 'finalizing again is not an error');

        $this->object->delete($path);
    }

    public function testFinalizeWithAMissingChunkDoesNotClaimAnExistingFile(): void
    {
        $path = $this->object->getPath('incomplete.txt');
        $this->object->write($path, new Stream('old contents'), 'text/plain');

        $metadata = [];
        $this->object->upload(new Stream('new '), $path, 'text/plain', 1, 3, $metadata);
        $this->object->upload(new Stream('!'), $path, 'text/plain', 3, 3, $metadata);

        try {
            $this->object->finalize($path, 3, $metadata);
            self::fail('Expected the missing chunk to be reported');
        } catch (UploadException) {
            $this->assertSame('old contents', file_get_contents($path), 'a file in the way is not this upload');
        }

        $this->object->abort($path);
    }

    public function testAStalePartDoesNotHijackAWholeFileUpload(): void
    {
        $path = $this->object->getPath('hijacked.txt');

        $abandoned = [];
        $this->object->upload(new Stream('abandoned chunk'), $path, 'text/plain', 1, 0, $abandoned);

        $metadata = [];
        $this->object->upload(new Stream('whole file'), $path, 'text/plain', 1, 1, $metadata);

        $this->assertTrue($this->object->finalize($path, 1, $metadata));
        $this->assertSame('whole file', file_get_contents($path), 'another upload\'s leftovers are not this file');

        $this->object->abort($path);
    }

    public function testAFailedCreateGivesThePathBack(): void
    {
        $path = $this->object->getPath('failed-create.txt');

        try {
            $this->object->create($path, $this->failingStream(), 'text/plain');
            self::fail('Expected the write to fail');
        } catch (\DomainException) {
            $this->assertFileDoesNotExist($path, 'a path claimed but never written stays free');
        }

        $this->assertSame(md5('taken'), $this->object->create($path, new Stream('taken'), 'text/plain'));

        $this->object->delete($path);
    }

    public function testAFailedReplaceLeavesThePreviousVersionInPlace(): void
    {
        $path = $this->object->getPath('failed-replace.txt');
        $etag = $this->object->create($path, new Stream('first'), 'text/plain');

        try {
            $this->object->replace($path, $this->failingStream(), $etag, 'text/plain');
            self::fail('Expected the write to fail');
        } catch (\DomainException) {
            $this->assertSame('first', file_get_contents($path));
            $this->assertSame($etag, $this->object->getFileInfo($path)->etag);
        }

        $this->assertSame([], glob(\dirname($path) . DIRECTORY_SEPARATOR . 'tmp_write_*') ?: [], 'the half-written file is cleaned up');

        $this->object->delete($path);
    }

    public function testAReplacementDoesNotDisturbAReaderAlreadyStreaming(): void
    {
        $path = $this->object->getPath('atomic.txt');
        $etag = $this->object->create($path, new Stream('first version'), 'text/plain');

        $stream = $this->object->read($path, 0, null, $etag);
        $this->object->replace($path, new Stream('second'), $etag, 'text/plain');

        $this->assertSame('first version', (string) $stream, 'the reader keeps the file whose ETag it checked');
        $this->assertSame('second', file_get_contents($path));

        $this->object->delete($path);
    }

    public function testAReplacedFileKeepsReadablePermissions(): void
    {
        $path = $this->object->getPath('permissions.txt');
        $etag = $this->object->create($path, new Stream('first'), 'text/plain');
        $this->object->replace($path, new Stream('second'), $etag, 'text/plain');

        $this->assertSame(0644 & ~umask(), fileperms($path) & 0777);

        $this->object->delete($path);
    }
}
