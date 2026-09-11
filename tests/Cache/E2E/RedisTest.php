<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use PHPUnit\Framework\Attributes\Depends;
use Redis as Redis;
use RedisException;
use Utopia\Cache\Adapter\Redis as RedisAdapter;
use Utopia\Cache\Cache;
use Utopia\Tests\Base;
use Utopia\Tests\Scope\EmptyObjectFidelity;
use Utopia\Tests\Services;

final class RedisTest extends Base
{
    use EmptyObjectFidelity;

    public static function setUpBeforeClass(): void
    {
        $redis = new Redis();
        $redis->connect(Services::HOST, Services::REDIS_PORT);
        self::$cache = new Cache(new RedisAdapter($redis));
    }

    public function testGetSize(): void
    {
        self::$cache->flush();
        self::$cache->save('test:file33', 'file33', 'test:file33');
        self::$cache->save('test:file34', 'file34', 'test:file34');
        self::$cache->save('test:file35', 'file35', 'test:file35');
        $this->assertSame(3, self::$cache->getSize());
    }

    public function testLoadFieldsBatch(): void
    {
        $redis = new Redis();
        $redis->connect(Services::HOST, Services::REDIS_PORT);
        $cache = new Cache(new RedisAdapter($redis));
        $cache->setCaseSensitivity(true);

        $key = 'test:batch:' . uniqid();
        $cache->save($key, ['sequence' => 5], 'topicA');
        $cache->save($key, ['sequence' => 9], 'topicB');
        $cache->save($key, ['sequence' => 1], 'topicC');

        // HMGET a subset: present fields returned, missing omitted.
        $this->assertSame([
            'topicA' => ['sequence' => 5],
            'topicC' => ['sequence' => 1],
        ], $cache->loadMany($key, 3600, ['topicA', 'topicC', 'missing']));

        // No field list -> HGETALL every field.
        $this->assertEqualsCanonicalizing([
            'topicA' => ['sequence' => 5],
            'topicB' => ['sequence' => 9],
            'topicC' => ['sequence' => 1],
        ], $cache->loadMany($key, 3600));

        // A single-field read still returns the scalar value.
        $this->assertSame(['sequence' => 9], $cache->load($key, 3600, 'topicB'));
    }

    public function testSaveManyBatch(): void
    {
        $redis = new Redis();
        $redis->connect(Services::HOST, Services::REDIS_PORT);
        $cache = new Cache(new RedisAdapter($redis));
        $cache->setCaseSensitivity(true);

        $key = 'test:batchsave:' . uniqid();

        // One HMSET writes every field of the map; ttl arms the whole-key expiry.
        $written = $cache->saveMany($key, [
            'topicA' => ['sequence' => 5],
            'topicB' => ['sequence' => 9],
            'topicC' => ['sequence' => 1],
        ], 300);

        $this->assertSame(['topicA' => ['sequence' => 5], 'topicB' => ['sequence' => 9], 'topicC' => ['sequence' => 1]], $written);
        $this->assertEqualsCanonicalizing([
            'topicA' => ['sequence' => 5],
            'topicB' => ['sequence' => 9],
            'topicC' => ['sequence' => 1],
        ], $cache->loadMany($key, 3600));

        $ttl = $redis->ttl($key);
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(300, $ttl);

        // A second saveMany merges new fields and overwrites existing ones.
        $cache->saveMany($key, ['topicA' => ['sequence' => 50], 'topicD' => ['sequence' => 7]]);
        $this->assertSame(['sequence' => 50], $cache->load($key, 3600, 'topicA'));
        $this->assertSame(['sequence' => 7], $cache->load($key, 3600, 'topicD'));
        $this->assertSame(['sequence' => 9], $cache->load($key, 3600, 'topicB')); // untouched
    }

    public function testLoadManyExcludesExpired(): void
    {
        $redis = new Redis();
        $redis->connect(Services::HOST, Services::REDIS_PORT);
        $cache = new Cache(new RedisAdapter($redis));
        $cache->setCaseSensitivity(true);

        $key = 'test:batch:ttl:' . uniqid();
        $cache->save($key, 'fresh', 'topicA');

        // ttl 0 makes the envelope already stale, so the field drops out.
        $this->assertSame([], $cache->loadMany($key, 0, ['topicA']));
        $this->assertSame(['topicA' => 'fresh'], $cache->loadMany($key, 3600, ['topicA']));
    }

    public function testSaveWithTtlArmsKeyExpiry(): void
    {
        $redis = new Redis();
        $redis->connect(Services::HOST, Services::REDIS_PORT);
        $cache = new Cache(new RedisAdapter($redis));
        $cache->setCaseSensitivity(true);

        $key = 'test:expire:' . uniqid();

        // No ttl: the key persists with no expiry.
        $cache->save($key, 'a', 'topicA');
        $this->assertSame(-1, $redis->ttl($key));

        // ttl > 0 arms a key-level expiry covering the whole hash.
        $cache->save($key, 'b', 'topicB', 120);
        $ttl = $redis->ttl($key);
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(120, $ttl);
    }

    #[Depends('testGetSize')]
    public function testCacheReconnect(): void
    {
        $this->assertReconnects(persistent: false);
    }

    #[Depends('testCacheReconnect')]
    public function testCacheReconnectPersistent(): void
    {
        $this->assertReconnects(persistent: true);
    }

    /**
     * Restarts Redis underneath a live adapter and asserts it recovers.
     */
    private function assertReconnects(bool $persistent): void
    {
        $key = $persistent ? 'test:reconnect_persistent' : 'test:reconnect';

        $redis = new Redis();
        $persistent
            ? $redis->pconnect(Services::HOST, Services::REDIS_PORT)
            : $redis->connect(Services::HOST, Services::REDIS_PORT);
        self::$cache = new Cache(new RedisAdapter($redis)->setMaxRetries(3));

        self::$cache->save($key, 'reconnect', $key);

        Services::compose('stop', 'redis');
        sleep(1);

        try {
            self::$cache->load($key, 5);
            $this->fail('Redis connection should have failed');
        } catch (RedisException) {
        }

        Services::compose('up', '-d', '--wait', 'redis');

        $this->assertSame('reconnect', self::$cache->save($key, 'reconnect', $key));
        $this->assertEquals('reconnect', self::$cache->load($key, 5));
    }
}
