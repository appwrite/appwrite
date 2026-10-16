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

    public function testFlushKeepsRootAndIsIdempotent(): void
    {
        $path = self::scratch('flush-root');

        try {
            $cache = new Cache(new Filesystem($path));

            $this->assertTrue($cache->flush());
            $this->assertDirectoryExists($path);
            $this->assertTrue($cache->ping());

            $cache->save('item', 'value');
            $this->assertSame('value', $cache->load('item', 60));

            $this->assertTrue($cache->flush());
            $this->assertDirectoryExists($path);
            $this->assertTrue($cache->ping());
            $this->assertFalse($cache->load('item', 60));

            $this->assertTrue($cache->flush());
            $this->assertDirectoryExists($path);
            $this->assertTrue($cache->ping());
        } finally {
            self::deletePath($path);
        }
    }

    public function testFlushReportsFailureWhenEntryCannotBeDeleted(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permission bits are required for this regression.');
        }

        // Root ignores directory write bits for unlink(); CI unit jobs run as root.
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            $this->markTestSkipped('Cannot simulate unlink failure while running as root.');
        }

        $path = self::scratch('flush-readonly');

        try {
            $cache = new Cache(new Filesystem($path));
            $cache->save('locked', 'value');

            $file = $path . DIRECTORY_SEPARATOR . 'locked';
            $this->assertTrue(\chmod($path, 0555));

            try {
                $this->assertFalse($cache->flush());
            } finally {
                \chmod($path, 0755);
            }

            $this->assertDirectoryExists($path);
            $this->assertFileExists($file);
        } finally {
            @\chmod($path, 0755);
            self::deletePath($path);
        }
    }
}
