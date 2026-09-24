<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use Override;
use Utopia\Cache\Adapter\Filesystem;
use Utopia\Cache\Cache;
use Utopia\Tests\Base;

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
}
