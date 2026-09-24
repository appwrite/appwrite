<?php

declare(strict_types=1);

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Cache;

abstract class Base extends TestCase
{
    protected static Cache $cache;

    protected string $key = 'test-key-for-cache';

    protected string $data = 'test data string';

    /**
     * @var array<string>
     */
    protected array $dataArray = ['test', 'data', 'string'];

    /**
     * An empty scratch directory for the filesystem-backed adapters. It is
     * cleared first, so a previous run cannot leak keys into this one.
     */
    protected static function scratch(string $name): string
    {
        $path = sys_get_temp_dir() . '/utopia-cache/' . $name;
        self::deletePath($path);
        mkdir($path, 0777, true);

        return $path;
    }

    protected static function deletePath(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }

        if (is_file($path)) {
            unlink($path);

            return;
        }

        foreach (glob($path . '/*') ?: [] as $file) {
            self::deletePath($file);
        }

        rmdir($path);
    }

    /**
     * macOS and Windows fold filename case, so the filesystem-backed adapters
     * cannot tell 'color' from 'COLOR' there however the cache is configured.
     */
    protected static function foldsFilenameCase(string $path): bool
    {
        touch($path . '/case-probe');
        $folds = file_exists($path . '/CASE-PROBE');
        unlink($path . '/case-probe');

        return $folds;
    }

    /**
     * General tests
     * Can be overwritten in a specific adapter if required, such as None cache
     */
    public function testCacheSave(): void
    {
        // test $data array
        $result = self::$cache->save($this->key, $this->dataArray, $this->key);

        $this->assertEquals($this->dataArray, $result);

        // test $data string
        $result = self::$cache->save($this->key, $this->data, $this->key);

        $this->assertSame($this->data, $result);
    }

    public function testNotEmptyCacheKey(): void
    {
        $data = self::$cache->load($this->key, 60 * 60 * 24 * 30 * 3 /* 3 months */, $this->key);

        $this->assertEquals($this->data, $data);
    }

    public function testCachePurge(): void
    {
        $data = self::$cache->load($this->key, 60 * 60 * 24 * 30 * 3 /* 3 months */, $this->key);

        $this->assertEquals($this->data, $data);

        $result = self::$cache->purge($this->key);

        $this->assertEquals(true, $result);

        $data = self::$cache->load($this->key, 60 * 60 * 24 * 30 * 3 /* 3 months */, $this->key);

        $this->assertEquals(false, $data);
    }

    public function testCacheTouch(): void
    {
        $result = self::$cache->save('touch-key', 'touch data', 'touch-key');
        $this->assertSame('touch data', $result);

        sleep(3);

        $this->assertEquals(false, self::$cache->load('touch-key', 2, 'touch-key'));
        $this->assertEquals(true, self::$cache->touch('touch-key', 'touch-key'));
        $this->assertEquals('touch data', self::$cache->load('touch-key', 2, 'touch-key'));
        $this->assertEquals(false, self::$cache->touch('missing-touch-key', 'missing-touch-key'));

        self::$cache->purge('touch-key');
    }

    public function testCaseInsensitivity(): void
    {
        // Ensure case in-sensitivity first (configured in adapter's setUp)
        $data = self::$cache->save('planet', 'Earth', 'planet');
        $this->assertSame('Earth', $data);

        $data = self::$cache->load('planet', 60 * 60 * 24 * 30 * 3 /* 3 months */, 'planet');
        $this->assertEquals('Earth', $data);
        $data = self::$cache->load('PLANET', 60 * 60 * 24 * 30 * 3 /* 3 months */, 'PLANET');
        $this->assertEquals('Earth', $data);
        $data = self::$cache->load('PlAnEt', 60 * 60 * 24 * 30 * 3 /* 3 months */, 'PlAnEt');
        $this->assertEquals('Earth', $data);

        $result = self::$cache->purge('PLaNEt');
        $this->assertEquals(true, $result);

        $data = self::$cache->load('planet', 60 * 60 * 24 * 30 * 3 /* 3 months */, 'planet');
        $this->assertEquals(false, $data);
        $data = self::$cache->load('PLANET', 60 * 60 * 24 * 30 * 3 /* 3 months */, 'PLANET');
        $this->assertEquals(false, $data);
    }

    public function testCaseSensitivity(): void
    {
        self::$cache->setCaseSensitivity(true);

        $data = self::$cache->save('color', 'pink', 'color');
        $this->assertSame('pink', $data);
        $data = self::$cache->load('color', 60 * 60 * 24 * 30 * 3 /* 3 months */, 'color');
        $this->assertEquals('pink', $data);
        $data = self::$cache->load('COLOR', 60 * 60 * 24 * 30 * 3 /* 3 months */, 'COLOR');
        $this->assertEquals(false, $data);

        $result = self::$cache->purge('color');
        $this->assertEquals(true, $result);

        self::$cache->setCaseSensitivity(false);
    }

    public function testPing(): void
    {
        $this->assertEquals(true, self::$cache->ping());
    }

    public function testFlush(): void
    {
        $result1 = self::$cache->save('x', 'x', 'x');
        $result2 = self::$cache->save('y', 'y', 'y');

        $this->assertEquals($result1, self::$cache->load('x', 100, 'x'));
        $this->assertEquals($result2, self::$cache->load('y', 100, 'y'));

        // test $data string
        $result = self::$cache->flush();

        $this->assertEquals(true, $result);
        $this->assertEquals(false, self::$cache->load('x', 100, 'x'));
        $this->assertEquals(false, self::$cache->load('y', 100, 'y'));
    }
}
