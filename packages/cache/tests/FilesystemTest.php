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

    public function testAPurgeThatCannotRemoveTheFileThrowsInsteadOfReportingNothingToPurge(): void
    {
        if (\function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('Root removes files from a read-only directory.');
        }

        $path = self::scratch('locked-data');
        $cache = new Cache(new Filesystem($path));
        $cache->save('locked', 'value');
        chmod($path, 0555);

        $failure = null;
        try {
            $cache->purge('locked');
        } catch (\Exception $error) {
            $failure = $error;
        } finally {
            chmod($path, 0755);
        }

        $this->assertInstanceOf(\Exception::class, $failure, 'A purge that left the file in place must not read as a purge of a missing key');
        $this->assertStringContainsString("Can't purge", $failure->getMessage());

        $this->assertTrue($cache->purge('locked'));
        $this->assertFalse($cache->purge('locked'));
    }

    public function testAPurgeThatCannotReachTheDirectoryThrowsInsteadOfReportingNothingToPurge(): void
    {
        if (\function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('Root reads a directory without the execute bit.');
        }

        $path = self::scratch('unreachable-data');
        $cache = new Cache(new Filesystem($path));
        $cache->save('hidden', 'value');
        chmod($path, 0666);

        $failure = null;
        try {
            $cache->purge('hidden');
        } catch (\Exception $error) {
            $failure = $error;
        } finally {
            chmod($path, 0755);
        }

        $this->assertInstanceOf(\Exception::class, $failure, 'A purge that cannot see the file must not read as a purge of a missing key');
        $this->assertTrue($cache->purge('hidden'));
    }

    public function testPurgingFromADirectoryNotYetCreatedReportsNothingToPurge(): void
    {
        $path = self::scratch('uncreated-data');
        $cache = new Cache(new Filesystem($path));
        self::deletePath($path);

        $this->assertFalse($cache->purge('missing'));
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
}
