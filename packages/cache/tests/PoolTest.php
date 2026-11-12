<?php

declare(strict_types=1);

namespace Utopia\Cache\Tests;

use Override;
use Utopia\Cache\Adapter\Filesystem;
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
