<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;
use Utopia\Tests\Base;

final class MemoryTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        self::$cache = new Cache(new Memory());
    }

    public function testGetSize(): void
    {
        self::$cache->save('test:file33', 'file33');
        self::$cache->save('test:file34', 'file34');
        self::$cache->save('test:file35', 'file35');
        self::$cache->save('test:file36', 'file36');
        $this->assertSame(4, self::$cache->getSize());
    }
}
