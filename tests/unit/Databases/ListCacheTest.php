<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use Appwrite\Databases\ListCache;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Redis;
use Utopia\Cache\Cache;
use Utopia\Cache\Exception\Unavailable;
use Utopia\System\System;

final class ListCacheTest extends TestCase
{
    private const string FIELD = 'documents';

    private const int TTL = 300;

    private Outage $outage;

    private Cache $cache;

    private \Redis $redis;

    private string $key;

    protected function setUp(): void
    {
        $redis = new \Redis();
        $redis->connect(System::getEnv('_APP_REDIS_HOST', 'redis'), (int) System::getEnv('_APP_REDIS_PORT', '6379'));

        $password = System::getEnv('_APP_REDIS_PASS', '');
        if ($password !== '') {
            $redis->auth(\array_filter([System::getEnv('_APP_REDIS_USER', ''), $password]));
        }

        $this->redis = $redis;
        $this->outage = new Outage(new Redis($redis));
        $this->cache = new Cache($this->outage);
        $this->key = 'list-cache-test:' . \bin2hex(\random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->outage->down = false;
    }

    public function testPurgesCachedResponsesAroundTheWrite(): void
    {
        $this->cache->save($this->key, ['row read before the write'], self::FIELD);

        $result = ListCache::purged([new ListCache($this->cache, $this->key)], function (): string {
            $this->cache->save($this->key, ['row read during the write'], self::FIELD);

            return 'stored';
        });

        $this->assertSame('stored', $result);
        $this->assertFalse($this->cache->load($this->key, self::TTL, self::FIELD));
    }

    public function testNamespacedListReadsStayWarmAndPermissionWritesInvalidateThem(): void
    {
        $cache = (new Cache(new Redis($this->redis)))->withNamespace('v2:');
        $listCache = new ListCache($cache, $this->key);
        $this->assertSame('stored', ListCache::purged([$listCache], fn (): string => 'stored'));
        $version = $listCache->getVersion();
        $listCache->save([self::FIELD => ['row']], $version);
        $this->assertSame(['row'], $listCache->load(self::FIELD, self::TTL, $version));

        $this->assertSame('updated', ListCache::purged([$listCache], fn (): string => 'updated'));
        $this->assertFalse($listCache->load(self::FIELD, self::TTL, $listCache->getVersion()));
        $listCache->save([self::FIELD => ['stale row']], $version);
        $this->assertFalse($listCache->load(self::FIELD, self::TTL, $listCache->getVersion()));
        $this->assertNotSame($version, $listCache->getVersion());
        $this->assertGreaterThan(0, $this->redis->ttl('v2:'.$this->key.':version'));
    }

    public function testVersionMarkerExpires(): void
    {
        ListCache::purged([new ListCache($this->cache, $this->key)], fn (): string => 'stored');

        $this->assertGreaterThan(0, $this->redis->ttl($this->key . ':version'), 'A purge must not leave a version key that never expires.');
    }

    public function testSavesReadWhileVersionIsUnchanged(): void
    {
        $listCache = new ListCache($this->cache, $this->key);
        $version = $listCache->getVersion();

        $listCache->save([self::FIELD => ['row']], $version);

        $this->assertSame(['row'], $listCache->load(self::FIELD, self::TTL, $version));
    }

    public function testReadRacingAPermissionWriteIsNotSaved(): void
    {
        $listCache = new ListCache($this->cache, $this->key);
        $version = $listCache->getVersion();

        ListCache::purged([$listCache], fn (): string => 'stored');
        $listCache->save([self::FIELD => ['row read before the write']], $version);

        $this->assertFalse($listCache->load(self::FIELD, self::TTL, $listCache->getVersion()), 'A list read before a permission write was cached after it');
    }

    public function testReadSavedAfterAPurgeOvertookItsVersionIsNotServed(): void
    {
        $cache = new Interleaved(new Redis($this->redis));
        $listCache = new ListCache($cache, $this->key);
        $version = $listCache->getVersion();

        // The permission write lands between the save's version comparison and its write of the rows.
        $cache->between(function () use ($listCache): void {
            ListCache::purged([$listCache], fn (): string => 'revoked');
        });
        $listCache->save([self::FIELD => ['row read before the revocation']], $version);

        $this->assertFalse(
            $listCache->load(self::FIELD, self::TTL, $listCache->getVersion()),
            'A list read taken before a permission write was served after the purge that followed it',
        );
    }

    public function testReadIsNotSavedWhenItsVersionCannotBeRead(): void
    {
        $listCache = new ListCache($this->cache, $this->key);

        $this->outage->down = true;
        $version = $listCache->getVersion();
        $this->outage->down = false;

        $listCache->save([self::FIELD => ['row']], $version);

        $this->assertNull($version);
        $this->assertFalse($listCache->load(self::FIELD, self::TTL, $listCache->getVersion()));
    }

    public function testPurgeFailureBeforeTheWriteRefusesIt(): void
    {
        $this->outage->down = true;
        $stored = false;

        try {
            ListCache::purged([new ListCache($this->cache, $this->key)], function () use (&$stored): void {
                $stored = true;
            });
            $this->fail('A write whose cached responses could not be purged was not refused');
        } catch (Unavailable) {
        }

        $this->assertFalse($stored, 'A write whose cached responses could not be purged was stored');
    }

    public function testPurgeFailureAfterTheWriteKeepsIt(): void
    {
        $stored = false;

        $result = ListCache::purged([new ListCache($this->cache, $this->key)], function () use (&$stored): string {
            $stored = true;
            $this->outage->down = true;

            return 'stored';
        });

        $this->assertSame('stored', $result, 'A stored write failed on the purge after it');
        $this->assertTrue($stored);
    }
}
