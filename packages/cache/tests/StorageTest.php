<?php

declare(strict_types=1);

namespace Utopia\Cache\Tests;

use Override;
use Psr\Http\Message\StreamInterface;
use Utopia\Cache\Adapter\Storage;
use Utopia\Cache\Cache;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Exception\TransportException;
use Utopia\Storage\FileInfo;

final class StorageTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        self::$cache = new Cache(new Storage(new Local(self::scratch('storage'))));
    }

    #[Override]
    public function testCaseSensitivity(): void
    {
        // A flush removes a local device's root until the next write, so probe beside it.
        if (self::foldsFilenameCase(self::scratch('storage-case'))) {
            $this->markTestSkipped('The host filesystem folds filename case.');
        }

        parent::testCaseSensitivity();
    }

    public function testBinaryDataIsStoredUnchanged(): void
    {
        $data = "\x89PNG\r\n\x1a\n\x00\xff\xfe";

        $this->assertSame($data, self::$cache->save('binary', $data));
        $this->assertSame($data, self::$cache->load('binary', 60));
    }

    public function testMissingKeyIsAMiss(): void
    {
        $this->assertFalse(self::$cache->load('missing', 60));
    }

    public function testEmptyDataIsNotSaved(): void
    {
        $this->assertFalse(self::$cache->save('empty', ''));
        $this->assertFalse(self::$cache->load('empty', 60));
    }

    public function testKeysCannotLeaveTheRoot(): void
    {
        $parent = self::scratch('storage-escape');
        $root = $parent . '/root';
        mkdir($root);
        file_put_contents($parent . '/outside', 'outside');
        $adapter = new Storage(new Local($root));

        foreach (['../outside', '..', '.', '', 'a/../../outside', '..\\outside', 'nested/key'] as $key) {
            $this->assertFalse($adapter->load($key, 60), $key);
            $this->assertSame('value', $adapter->save($key, 'value'), $key);
            $this->assertSame('value', $adapter->load($key, 60), $key);
            $this->assertTrue($adapter->touch($key), $key);
            $this->assertSame(\dirname($adapter->getPath($key)), $root, $key);
            $this->assertTrue($adapter->purge($key), $key);
        }

        $this->assertSame('outside', file_get_contents($parent . '/outside'));
        $this->assertSame(['.', '..', 'outside', 'root'], scandir($parent));
    }

    public function testFlushOnlyRemovesTheRoot(): void
    {
        $parent = self::scratch('storage-flush');
        file_put_contents($parent . '/outside', 'outside');
        $adapter = new Storage(new Local($parent . '/root'));

        $adapter->save('key', 'value');

        $this->assertTrue($adapter->flush());
        $this->assertTrue($adapter->flush());
        $this->assertFalse($adapter->load('key', 60));
        $this->assertFileExists($parent . '/outside');
        $this->assertSame('value', $adapter->save('key', 'value'));
    }

    public function testSizeAndListMakeNoRequest(): void
    {
        $adapter = new Storage($this->unavailable());

        $this->assertSame(0, $adapter->getSize());
        $this->assertSame([], $adapter->list('key'));
    }

    public function testOutageReadsAsAMiss(): void
    {
        $adapter = new Storage($this->unavailable());

        $this->assertFalse($adapter->load('key', 60));
        $this->assertFalse($adapter->save('key', 'value'));
        $this->assertFalse($adapter->touch('key'));
        $this->assertFalse($adapter->ping());
    }

    public function testOutageDuringThePayloadReadIsAMiss(): void
    {
        $path = self::scratch('storage-read');
        $device = new class ($path) extends Local {
            public function read(string $path, int $offset = 0, ?int $length = null, ?string $etag = null): StreamInterface
            {
                throw new TransportException('Connection timed out');
            }
        };
        $adapter = new Storage($device);
        $adapter->save('key', 'value');

        $this->assertFalse($adapter->load('key', 60));
    }

    public function testPurgeThatFailsThrows(): void
    {
        $this->expectException(TransportException::class);

        (new Storage($this->unavailable()))->purge('key');
    }

    private function unavailable(): Local
    {
        return new class (self::scratch('storage-unavailable')) extends Local {
            public function getFileInfo(string $path): FileInfo
            {
                throw new TransportException('Connection timed out');
            }

            public function write(string $path, StreamInterface $data, string $contentType = ''): string
            {
                throw new TransportException('Connection timed out');
            }

            public function delete(string $path, bool $recursive = false): bool
            {
                throw new TransportException('Connection timed out');
            }

            public function exists(string $path): bool
            {
                throw new TransportException('Connection timed out');
            }
        };
    }
}
