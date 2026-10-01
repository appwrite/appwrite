<?php

declare(strict_types=1);

namespace Utopia\Cache\Tests;

use Override;
use Utopia\Cache\Adapter\Filesystem;
use Utopia\Cache\Cache;

final class FilesystemTest extends Base
{
    private static string $path;

    public static function setUpBeforeClass(): void
    {
        self::$path = self::scratch('data');
        self::$cache = new Cache(new Filesystem(self::$path));
    }

    public function testGetSize(): void
    {
        self::$cache->save('test', 'test');
        $this->assertSame(4, self::$cache->getSize());
    }

    #[Override]
    public function testCaseSensitivity(): void
    {
        if (self::foldsFilenameCase(self::$path)) {
            $this->markTestSkipped('The host filesystem folds filename case.');
        }

        parent::testCaseSensitivity();
    }

    public function testStreamingLoad(): void
    {
        $path = self::scratch('stream-data');

        try {
            $cache = new Cache(new Filesystem($path, true));
            $cache->save('stream-test', 'stream data');

            $stream = $cache->load('stream-test', 60);

            $this->assertTrue(\is_resource($stream));
            $this->assertSame('stream data', stream_get_contents($stream));

            fclose($stream);
        } finally {
            self::deletePath($path);
        }
    }

    public function testStreamingLoadMissingKey(): void
    {
        $path = self::scratch('stream-missing-data');

        try {
            $cache = new Cache(new Filesystem($path, true));

            $this->assertEquals(false, $cache->load('missing-stream-test', 60));
        } finally {
            self::deletePath($path);
        }
    }

    public function testFlushOnEmptyCacheAndSubsequentPing(): void
    {
        $path = self::scratch('empty-cache');

        try {
            $adapter = new Filesystem($path);
            $cache = new Cache($adapter);

            $this->assertTrue($cache->flush());
            $this->assertTrue(is_dir($path));
            $this->assertTrue($cache->ping());
            $this->assertTrue($cache->flush());
        } finally {
            self::deletePath($path);
        }
    }

    public function testFlushClearsContentsAndPreservesRoot(): void
    {
        $path = self::scratch('populated-cache');

        try {
            $adapter = new Filesystem($path);
            $cache = new Cache($adapter);

            $this->assertSame('data1', $cache->save('item1', 'data1'));
            $this->assertSame('data2', $cache->save('nested/item2', 'data2'));

            $this->assertTrue($cache->flush());
            $this->assertTrue(is_dir($path));
            $this->assertTrue($cache->ping());
            $this->assertFalse($cache->load('item1', 60));
            $this->assertFalse($cache->load('nested/item2', 60));
            $this->assertTrue($cache->flush());
        } finally {
            self::deletePath($path);
        }
    }

    public function testFlushReportsFailureWhenEntryCannotBeDeleted(): void
    {
        $path = self::scratch('unwritable-cache');

        try {
            $adapter = new Filesystem($path);
            $cache = new Cache($adapter);

            $this->assertSame('data', $cache->save('item', 'data'));

            $isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
            $switched = false;
            $origEuid = null;

            chmod($path, 0555);

            if ($isRoot && function_exists('posix_seteuid')) {
                $origEuid = posix_geteuid();
                $switched = @posix_seteuid(1000);
            }

            try {
                $this->assertFalse($cache->flush());
            } finally {
                if ($switched && $origEuid !== null) {
                    posix_seteuid($origEuid);
                }
                chmod($path, 0777);
            }
        } finally {
            chmod($path, 0777);
            self::deletePath($path);
        }
    }
}
