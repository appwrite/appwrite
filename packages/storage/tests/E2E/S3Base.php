<?php

declare(strict_types=1);

namespace Utopia\Tests\Storage\E2E;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use Utopia\Psr7\Stream;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\S3;
use Utopia\Storage\DeviceType;
use Utopia\Storage\Exception\NotFoundException;

abstract class S3Base extends TestCase
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

    abstract protected function init(): void;

    abstract protected function getAdapterType(): DeviceType;

    /**
     * @var S3
     */
    protected $object;

    /**
     * @var string
     */
    protected $root = '/root';

    protected function setUp(): void
    {
        $this->init();
        $this->uploadTestFiles();
    }

    private function uploadFile(string $source, string $path): void
    {
        $handle = $this->openStream($source);
        $this->object->upload(Stream::fromResource($handle), $path, mime_content_type($source) ?: '');
    }

    private function uploadTestFiles(): void
    {
        $this->uploadFile(__DIR__ . '/../resources/disk-a/kitten-1.jpg', $this->object->getPath('testing/kitten-1.jpg'));
        $this->uploadFile(__DIR__ . '/../resources/disk-a/kitten-2.jpg', $this->object->getPath('testing/kitten-2.jpg'));
        $this->uploadFile(__DIR__ . '/../resources/disk-b/kitten-1.png', $this->object->getPath('testing/kitten-1.png'));
        $this->uploadFile(__DIR__ . '/../resources/disk-b/kitten-2.png', $this->object->getPath('testing/kitten-2.png'));
    }

    private function removeTestFiles(): void
    {
        $this->object->delete($this->object->getPath('testing/kitten-1.jpg'));
        $this->object->delete($this->object->getPath('testing/kitten-2.jpg'));
        $this->object->delete($this->object->getPath('testing/kitten-1.png'));
        $this->object->delete($this->object->getPath('testing/kitten-2.png'));
    }

    protected function tearDown(): void
    {
        $this->removeTestFiles();
    }

    public function testListFiles(): void
    {
        $path = $this->object->getPath('testing/');
        $list = $this->object->listFiles($path);

        $this->assertCount(4, $list->files);
        $this->assertNull($list->cursor);

        $file = $list->files[0];
        $this->assertStringContainsString('testing/kitten', $file->path);
        $this->assertGreaterThan(0, $file->size);
        $this->assertInstanceOf(\DateTimeImmutable::class, $file->modifiedAt);
        $this->assertNotNull($file->etag);
    }

    public function testListFilesPagination(): void
    {
        $path = $this->object->getPath('testing/');

        $list = $this->object->listFiles($path, 3);
        $this->assertCount(3, $list->files);
        $this->assertNotNull($list->cursor);

        $list = $this->object->listFiles($path, 1000, $list->cursor);
        $this->assertCount(1, $list->files);
        $this->assertNull($list->cursor);
    }

    public function testListFilesSingleObject(): void
    {
        $path = $this->object->getPath('single/');
        $this->object->write($path . 'only.txt', new Stream('one'), 'text/plain');

        $list = $this->object->listFiles($path);
        $this->assertCount(1, $list->files);
        $this->assertSame(ltrim($path . 'only.txt', '/'), $list->files[0]->path);
        $this->assertNull($list->cursor);

        $this->object->delete($path . 'only.txt');
    }

    public function testType(): void
    {
        $this->assertEquals($this->getAdapterType(), $this->object->getType());
    }

    public function testRoot(): void
    {
        $this->assertEquals($this->root, $this->object->getRoot());
    }

    public function testPath(): void
    {
        $this->assertEquals($this->root . '/image.png', $this->object->getPath('image.png'));
    }

    public function testWrite(): void
    {
        $this->assertSame(md5('Hello World'), $this->object->write($this->object->getPath('text.txt'), new Stream('Hello World'), 'text/plain'));

        $this->object->delete($this->object->getPath('text.txt'));
    }

    public function testRead(): void
    {
        $this->assertSame(md5('Hello World'), $this->object->write($this->object->getPath('text-for-read.txt'), new Stream('Hello World'), 'text/plain'));
        $this->assertSame('Hello World', (string) $this->object->read($this->object->getPath('text-for-read.txt')));

        $this->object->delete($this->object->getPath('text-for-read.txt'));
    }

    public function testReadNonExistentFile(): void
    {
        $this->expectException(NotFoundException::class);
        $this->object->read($this->object->getPath('non-existent-file.txt'));
    }

    public function testFileExists(): void
    {
        $this->assertEquals(true, $this->object->exists($this->object->getPath('testing/kitten-1.jpg')));
        $this->assertEquals(false, $this->object->exists($this->object->getPath('testing/kitten-5.jpg')));
    }

    public function testMove(): void
    {
        $this->assertSame(md5('Hello World'), $this->object->write($this->object->getPath('text-for-move.txt'), new Stream('Hello World'), 'text/plain'));
        $this->assertEquals(true, $this->object->exists($this->object->getPath('text-for-move.txt')));
        $this->assertEquals(true, $this->object->move($this->object->getPath('text-for-move.txt'), $this->object->getPath('text-for-move-new.txt')));
        $this->assertSame('Hello World', (string) $this->object->read($this->object->getPath('text-for-move-new.txt')));
        $this->assertEquals(false, $this->object->exists($this->object->getPath('text-for-move.txt')));

        $this->object->delete($this->object->getPath('text-for-move-new.txt'));
    }

    public function testCopySameDevice(): void
    {
        $source = $this->object->getPath('testing/kitten-1.jpg');
        $target = $this->object->getPath('testing/kitten-copy.jpg');

        $this->assertTrue($this->object->copy($source, $target));
        $this->assertSame($this->object->getFileHash($source), $this->object->getFileHash($target));
        $this->assertSame($this->object->getFileSize($source), $this->object->getFileSize($target));

        $this->object->delete($target);
    }

    public function testMoveIdenticalName(): void
    {
        $file = '/kitten-1.jpg';
        $this->assertFalse($this->object->move($file, $file));
    }

    public function testDelete(): void
    {
        $this->assertSame(md5('Hello World'), $this->object->write($this->object->getPath('text-for-delete.txt'), new Stream('Hello World'), 'text/plain'));
        $this->assertEquals(true, $this->object->exists($this->object->getPath('text-for-delete.txt')));
        $this->assertEquals(true, $this->object->delete($this->object->getPath('text-for-delete.txt')));
    }

    public function testSvgUpload(): void
    {
        $this->uploadFile(__DIR__ . '/../resources/disk-b/appwrite.svg', $this->object->getPath('testing/appwrite.svg'));
        $this->assertEquals(file_get_contents(__DIR__ . '/../resources/disk-b/appwrite.svg'), (string) $this->object->read($this->object->getPath('testing/appwrite.svg')));
        $this->assertEquals(true, $this->object->exists($this->object->getPath('testing/appwrite.svg')));
        $this->assertEquals(true, $this->object->delete($this->object->getPath('testing/appwrite.svg')));
    }

    public function testXmlUpload(): void
    {
        $this->uploadFile(__DIR__ . '/../resources/disk-a/config.xml', $this->object->getPath('testing/config.xml'));
        $this->assertEquals(file_get_contents(__DIR__ . '/../resources/disk-a/config.xml'), (string) $this->object->read($this->object->getPath('testing/config.xml')));
        $this->assertEquals(true, $this->object->exists($this->object->getPath('testing/config.xml')));
        $this->assertEquals(true, $this->object->delete($this->object->getPath('testing/config.xml')));
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

        $this->assertEquals(true, $this->object->deletePath('bucket'));
        $this->assertEquals(false, $this->object->exists($path));
        $this->assertEquals(false, $this->object->exists($path2));
    }

    public function testFileSize(): void
    {
        $this->assertEquals(599639, $this->object->getFileSize($this->object->getPath('testing/kitten-1.jpg')));
        $this->assertEquals(131958, $this->object->getFileSize($this->object->getPath('testing/kitten-2.jpg')));
    }

    public function testFileMimeType(): void
    {
        $this->assertEquals('image/jpeg', $this->object->getFileMimeType($this->object->getPath('testing/kitten-1.jpg')));
        $this->assertEquals('image/jpeg', $this->object->getFileMimeType($this->object->getPath('testing/kitten-2.jpg')));
        $this->assertEquals('image/png', $this->object->getFileMimeType($this->object->getPath('testing/kitten-1.png')));
        $this->assertEquals('image/png', $this->object->getFileMimeType($this->object->getPath('testing/kitten-2.png')));
    }

    public function testFileHash(): void
    {
        $this->assertEquals('7551f343143d2e24ab4aaf4624996b6a', $this->object->getFileHash($this->object->getPath('testing/kitten-1.jpg')));
        $this->assertEquals('81702fdeef2e55b1a22617bce4951cb5', $this->object->getFileHash($this->object->getPath('testing/kitten-2.jpg')));
        $this->assertEquals('03010f4f02980521a8fd6213b52ec313', $this->object->getFileHash($this->object->getPath('testing/kitten-1.png')));
        $this->assertEquals('8a9ed992b77e4b62b10e3a5c8ed72062', $this->object->getFileHash($this->object->getPath('testing/kitten-2.png')));
    }

    public function testPartUpload(): string
    {
        $source = __DIR__ . '/../resources/disk-a/large_file.mp4';
        $dest = $this->object->getPath('uploaded.mp4');
        $totalSize = filesize($source);
        // AWS S3 requires each part to be at least 5MB except for last part
        $chunkSize = 5 * 1024 * 1024;

        $chunks = (int) ceil($totalSize / $chunkSize);

        $chunk = 1;
        $start = 0;

        $metadata = [
            'parts' => [],
            'chunks' => 0,
            'content_type' => mime_content_type($source) ?: '',
        ];
        $handle = $this->openStream($source);
        while ($start < $totalSize) {
            $contents = $this->readBytes($handle, $chunkSize);
            $this->object->upload(new Stream($contents), $dest, $metadata['content_type'] ?? '', $chunk, $chunks, $metadata);
            $start += \strlen($contents);
            ++$chunk;
            fseek($handle, $start);
        }
        fclose($handle);

        $this->assertEquals(filesize($source), $this->object->getFileSize($dest));

        // S3 doesnt provide a method to get a proper MD5-hash of a file created using multipart upload
        // https://stackoverflow.com/questions/8618218/amazon-s3-checksum
        // More info on how AWS calculates ETag for multipart upload here
        // https://savjee.be/2015/10/Verifying-Amazon-S3-multi-part-uploads-with-ETag-hash/
        // TODO
        // $this->assertEquals(\md5_file($source), $this->object->getFileHash($dest));
        // $this->object->delete($dest);
        return $dest;
    }

    public function testPartUploadRetry(): string
    {
        $source = __DIR__ . '/../resources/disk-a/large_file.mp4';
        $dest = $this->object->getPath('uploaded.mp4');
        $totalSize = filesize($source);
        // AWS S3 requires each part to be at least 5MB except for last part
        $chunkSize = 5 * 1024 * 1024;

        $chunks = (int) ceil($totalSize / $chunkSize);

        $chunk = 1;
        $start = 0;

        $metadata = [
            'parts' => [],
            'chunks' => 0,
            'content_type' => mime_content_type($source) ?: '',
        ];
        $handle = $this->openStream($source);
        while ($start < $totalSize) {
            $contents = $this->readBytes($handle, $chunkSize);
            $this->object->upload(new Stream($contents), $dest, $metadata['content_type'] ?? '', $chunk, $chunks, $metadata);
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
            $this->object->upload(new Stream($contents), $dest, $metadata['content_type'] ?? '', $chunk, $chunks, $metadata);
            $start += \strlen($contents);
            ++$chunk;
            fseek($handle, $start);
        }
        fclose($handle);

        $this->assertEquals(filesize($source), $this->object->getFileSize($dest));

        // S3 doesnt provide a method to get a proper MD5-hash of a file created using multipart upload
        // https://stackoverflow.com/questions/8618218/amazon-s3-checksum
        // More info on how AWS calculates ETag for multipart upload here
        // https://savjee.be/2015/10/Verifying-Amazon-S3-multi-part-uploads-with-ETag-hash/
        // TODO
        // $this->assertEquals(\md5_file($source), $this->object->getFileHash($dest));
        // $this->object->delete($dest);
        return $dest;
    }

    public function testOutOfOrderPartUpload(): string
    {
        $source = __DIR__ . '/../resources/disk-a/large_file.mp4';
        $dest = $this->object->getPath('uploaded-out-of-order.mp4');
        $totalSize = filesize($source);
        // AWS S3 requires each part to be at least 5MB except for last part
        $chunkSize = 5 * 1024 * 1024;

        $chunks = (int) ceil($totalSize / $chunkSize);

        // Read all chunk contents into memory so we can upload out of order
        $parts = [];
        $handle = $this->openStream($source);
        $chunkNum = 1;
        while ($chunkNum <= $chunks) {
            $parts[$chunkNum] = $this->readBytes($handle, $chunkSize);
            ++$chunkNum;
        }
        fclose($handle);

        $metadata = [
            'parts' => [],
            'chunks' => 0,
            'content_type' => mime_content_type($source) ?: '',
        ];

        // Upload chunks in reverse order
        for ($i = $chunks; $i >= 1; --$i) {
            $this->object->upload(new Stream($parts[$i]), $dest, $metadata['content_type'] ?? '', $i, $chunks, $metadata);
        }

        $this->assertEquals(filesize($source), $this->object->getFileSize($dest));

        // S3 doesnt provide a method to get a proper MD5-hash of a file created using multipart upload
        // TODO
        // $this->assertEquals(\md5_file($source), $this->object->getFileHash($dest));
        // $this->object->delete($dest);
        return $dest;
    }

    #[Depends('testPartUpload')]
    public function testPartRead(string $path): void
    {
        $source = __DIR__ . '/../resources/disk-a/large_file.mp4';
        $chunk = file_get_contents($source, false, null, 0, 500);
        $readChunk = (string) $this->object->read($path, 0, 500);
        $this->assertEquals($chunk, $readChunk);
    }

    #[Depends('testPartUpload')]
    public function testCopyLarge(string $path): void
    {
        // chunked file
        $device = new Local(__DIR__ . '/../resources/disk-a');
        $destination = $device->getPath('largefile.mp4');

        $this->assertTrue($this->object->copy($path, $destination, $device, 10000000)); // 10 mb chunks
        $this->assertTrue($device->exists($destination));
        $this->assertSame('video/mp4', $device->getFileMimeType($destination));

        $device->delete($destination);
        $this->object->delete($path);
    }

    public function testCopySmall(): void
    {
        $device = new Local(__DIR__ . '/../resources/disk-a');

        $path = $this->object->getPath('text-for-read.txt');
        $this->object->write($path, new Stream('Hello World'), 'text/plain');

        $destination = $device->getPath('hello.txt');
        $this->assertTrue($this->object->copy($path, $destination, $device, 10000000)); // 10 mb chunks
        $this->assertTrue($device->exists($destination));
        $this->assertSame('Hello World', (string) $device->read($destination));

        $this->object->delete($path);
        $device->delete($destination);
    }

    public function testCopyNonExistentFile(): void
    {
        $device = new Local(__DIR__ . '/../resources/disk-a');

        $path = $this->object->getPath('non-existent-file.txt');
        $destination = $device->getPath('hello.txt');

        $this->expectException(NotFoundException::class);
        $this->object->copy($path, $destination, $device);
    }
}
