<?php

declare(strict_types=1);

namespace Utopia\Cache\Tests;

use Override;
use Utopia\Cache\Adapter\Filesystem;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Adapter\Pool;
use Utopia\Cache\Cache;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool as UtopiaPool;

final class PoolTest extends Base
{
    private static string $path;

    public static function setUpBeforeClass(): void
    {
        self::$path = self::scratch('pool');

        $pool = new UtopiaPool(new Stack(), 'test', 10, fn (): Filesystem => new Filesystem(self::$path), timeout: 0.0);

        self::$cache = new Cache(new Pool($pool));
    }

    /** This pool holds Filesystem adapters, which store strings, so an empty array is refused like an empty string. */
    #[Override]
    public function testEmptyArrayIsStored(): void
    {
        $this->assertFalse(self::$cache->save('empty-array', []));
        $this->assertFalse(self::$cache->load('empty-array', 60));
    }

    #[Override]
    public function testEmptyArrayIsKeptByTouch(): void
    {
        $this->assertFalse(self::$cache->save('empty-array-touch', []));
        $this->assertFalse(self::$cache->touch('empty-array-touch'));
    }

    public function testEmptyArrayIsStoredThroughAPoolOfMemory(): void
    {
        $pool = new UtopiaPool(new Stack(), 'memory', 1, fn (): Memory => new Memory(), timeout: 0.0);
        $cache = new Cache(new Pool($pool));

        $this->assertSame([], $cache->save('empty-array', []));
        $this->assertSame([], $cache->load('empty-array', 60));
        $this->assertSame([], $cache->saveWithLease('empty-array-lease', [], '', $cache->getLease('empty-array-lease')));
        $this->assertSame([], $cache->load('empty-array-lease', 60));
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

    public function testUnsupportedAdaptersCannotPerformConditionalFills(): void
    {
        $this->assertFalse(self::$cache->supportsVersioning());
        $this->assertSame('', self::$cache->getLease('lease:fallback'));
        $this->assertFalse(self::$cache->saveWithLease('lease:fallback', 'value', '', ''));
        $this->assertFalse(self::$cache->load('lease:fallback', 60));
    }
}
