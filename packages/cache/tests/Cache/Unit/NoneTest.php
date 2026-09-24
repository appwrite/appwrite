<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\Attributes\Depends;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Tests\Base;

final class NoneTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        self::$cache = new Cache(new None());
    }

    public function testGetSize(): void
    {
        $this->assertSame(0, self::$cache->getSize());
    }

    public function testEmptyCacheKey(): void
    {
        self::$cache->purge($this->key);

        $data = self::$cache->load($this->key, 60 * 60 * 24 * 30 * 3 /* 3 months */);

        $this->assertEquals(false, $data);
    }

    #[\Override]
    public function testCacheSave(): void
    {
        $result = self::$cache->save($this->key, $this->data);

        $this->assertEquals(false, $result);
    }

    #[Depends('testCacheSave')]
    public function testCacheLoad(): void
    {
        $data = self::$cache->load($this->key, 60 * 60 * 24 * 30 * 3 /* 3 months */);

        $this->assertEquals(false, $data);
    }

    #[Depends('testCacheLoad')]
    #[\Override]
    public function testNotEmptyCacheKey(): void
    {
        $data = self::$cache->load($this->key, 60 * 60 * 24 * 30 * 3 /* 3 months */);

        $this->assertEquals(false, $data);
    }

    #[\Override]
    public function testCachePurge(): void
    {
        $result = self::$cache->purge($this->key);

        $this->assertEquals(true, $result);
    }

    #[\Override]
    public function testCacheTouch(): void
    {
        $this->assertEquals(false, self::$cache->touch($this->key));
    }

    #[\Override]
    public function testCaseInsensitivity(): void
    {
        $this->markTestSkipped('The None adapter stores nothing, so key casing cannot matter.');
    }

    #[\Override]
    public function testCaseSensitivity(): void
    {
        $this->markTestSkipped('The None adapter stores nothing, so key casing cannot matter.');
    }
}
