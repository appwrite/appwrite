<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use Override;
use Utopia\Cache\Adapter\Filesystem;
use Utopia\Cache\Adapter\Pool;
use Utopia\Cache\Cache;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool as UtopiaPool;
use Utopia\Tests\Base;

final class PoolTest extends Base
{
    private static string $path;

    public static function setUpBeforeClass(): void
    {
        self::$path = self::scratch('pool');

        $pool = new UtopiaPool(new Stack(), 'test', 10, fn(): Filesystem => new Filesystem(self::$path), timeout: 0.0);

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

    public function testLeaseFallbackForNonLeasableAdapter(): void
    {
        // The pool holds Filesystem adapters, which are not Leasable. The Pool
        // adapter must degrade gracefully (no "Call to undefined method" fatal):
        // getGeneration() returns '0' and saveWithLease() falls back to save().
        $this->assertSame('0', self::$cache->getGeneration('lease:fallback'));
        $this->assertNotFalse(self::$cache->saveWithLease('lease:fallback', 'value', 'lease:fallback', '0'));
        $this->assertSame('value', self::$cache->load('lease:fallback', 60, 'lease:fallback'));
    }
}
