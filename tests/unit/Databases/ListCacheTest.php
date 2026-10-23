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

    private ListCache $listCache;

    protected function setUp(): void
    {
        $redis = new \Redis();
        $redis->connect(System::getEnv('_APP_REDIS_HOST', 'redis'), (int) System::getEnv('_APP_REDIS_PORT', '6379'));

        $password = System::getEnv('_APP_REDIS_PASS', '');
        if ($password !== '') {
            $redis->auth(\array_filter([System::getEnv('_APP_REDIS_USER', ''), $password]));
        }

        $this->outage = new Outage(new Redis($redis));
        $this->listCache = new ListCache(new Cache($this->outage), 'list-cache-test:' . \bin2hex(\random_bytes(8)));
    }

    protected function tearDown(): void
    {
        $this->outage->down = false;
    }

    public function testCachesUnderLease(): void
    {
        $this->listCache->save(self::FIELD, ['row'], $this->listCache->lease());

        $this->assertSame(['row'], $this->listCache->load(self::FIELD, self::TTL));
    }

    public function testFencedWriteRetiresCachedResponses(): void
    {
        $this->listCache->save(self::FIELD, ['row'], $this->listCache->lease());

        ListCache::fenced([$this->listCache], fn () => null);

        $this->assertFalse($this->listCache->load(self::FIELD, self::TTL));
    }

    public function testNothingIsSavedWhileFenced(): void
    {
        $token = $this->listCache->fence();

        $this->assertNull($this->listCache->lease());

        $this->listCache->release($token);

        $this->assertNotNull($this->listCache->lease());
    }

    public function testReadRacingAFencedWriteIsNotSaved(): void
    {
        $lease = $this->listCache->lease();

        ListCache::fenced([$this->listCache], fn () => null);
        $this->listCache->save(self::FIELD, ['row read before the write'], $lease);

        $this->assertFalse($this->listCache->load(self::FIELD, self::TTL));
    }

    public function testFenceThatCannotBeRegisteredRefusesTheWrite(): void
    {
        $this->outage->down = true;
        $written = false;

        try {
            ListCache::fenced([$this->listCache], function () use (&$written): void {
                $written = true;
            });
            $this->fail('A write that could not be fenced ran');
        } catch (Unavailable) {
        }

        $this->assertFalse($written, 'A write that could not be fenced ran');
    }

    public function testFailedReleaseKeepsTheWriteAndServesNothingCachedBeforeIt(): void
    {
        $this->listCache->save(self::FIELD, ['row read before the write'], $this->listCache->lease());

        $result = ListCache::fenced([$this->listCache], function (): string {
            $this->outage->down = true;

            return 'stored';
        });

        $this->assertSame('stored', $result, 'A stored write failed on its release');

        $this->outage->down = false;

        $this->assertFalse($this->listCache->load(self::FIELD, self::TTL), 'A response cached before the write was served after the cache recovered');
        $this->assertNull($this->listCache->lease(), 'A response was saved before the unreleased fence lapsed');
    }

    public function testFailedWriteReleasesItsFence(): void
    {
        try {
            ListCache::fenced([$this->listCache], function (): never {
                throw new \LogicException('Write failed');
            });
        } catch (\LogicException $error) {
            $this->assertSame('Write failed', $error->getMessage());
        }

        $this->assertNotNull($this->listCache->lease());
    }

    public function testFencesEveryCache(): void
    {
        $other = new ListCache(new Cache($this->outage), 'list-cache-test:' . \bin2hex(\random_bytes(8)));

        ListCache::fenced([$this->listCache, $other], function () use ($other): void {
            $this->assertNull($this->listCache->lease());
            $this->assertNull($other->lease());
        });

        $this->assertNotNull($this->listCache->lease());
        $this->assertNotNull($other->lease());
    }
}
