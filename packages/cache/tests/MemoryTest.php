<?php

declare(strict_types=1);

namespace Utopia\Cache\Tests;

use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;

final class MemoryTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        self::$cache = new Cache(new Memory());
    }

    public function testTouchInvalidatesThePreviousLease(): void
    {
        $cache = new Cache(new Memory());
        $cache->save('touch-version', 'original');
        $before = $cache->getLease('touch-version');
        $this->assertTrue($cache->touch('touch-version'));
        $this->assertNotSame($before, $cache->getLease('touch-version'));
        $this->assertFalse($cache->saveWithLease('touch-version', 'stale', '', $before));
        $this->assertSame('original', $cache->load('touch-version', 60));
    }

    public function testConditionalFillsRejectTheSameEmptyInputsAsOrdinarySaves(): void
    {
        $cache = new Cache(new Memory());
        foreach ([['', 'value'], ['0', 'value'], ['key', ''], ['key', '0'], ['key', []]] as [$key, $data]) {
            $lease = $cache->getLease($key);
            $this->assertFalse($cache->saveWithLease($key, $data, '', $lease));
            $this->assertFalse($cache->load($key, 60));
        }
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
