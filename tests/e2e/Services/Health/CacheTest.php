<?php

namespace Tests\E2E\Services\Health;

use Redis;
use Utopia\Cache\Adapter\Redis as RedisAdapter;
use Utopia\Cache\Cache;

class CacheTest extends HealthBase
{
    public function testCacheSuccess(): void
    {
        $response = $this->callGet('/health/cache');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']['statuses']);
        $this->assertIsInt($response['body']['statuses'][0]['ping']);
        $this->assertLessThan(100, $response['body']['statuses'][0]['ping']);
        $this->assertEquals('pass', $response['body']['statuses'][0]['status']);
    }

    public function testCacheReconnect(): void
    {
        $redis = new Redis();
        $redis->connect('redis', 6379);
        $cache = new Cache(
            (new RedisAdapter($redis))
                ->setMaxRetries(CACHE_RECONNECT_MAX_RETRIES)
                ->setRetryDelay(CACHE_RECONNECT_RETRY_DELAY)
        );

        $cache->save('test:reconnect', 'reconnect', 'test:reconnect');

        $stopCmd = 'docker ps -a --filter "name=appwrite-redis" --format "{{.Names}}" | xargs -r docker stop';
        exec($stopCmd . ' 2>&1', $output, $exitCode);
        $this->assertEquals(0, $exitCode, "Docker stop failed: $stopCmd\nOutput: " . implode("\n", $output));
        sleep(1);

        try {
            try {
                $cache->load('test:reconnect', 5);
                $this->fail('Redis connection should have failed');
            } catch (\RedisException $e) {
            }
        } finally {
            $output = [];
            $startCmd = 'docker ps -a --filter "name=appwrite-redis" --format "{{.Names}}" | xargs -r docker start';
            exec($startCmd . ' 2>&1', $output, $exitCode);
            $this->assertEquals(0, $exitCode, "Docker start failed: $startCmd\nOutput: " . implode("\n", $output));
        }

        $this->assertEventually(function () use ($cache) {
            $this->assertEquals('reconnect', $cache->save('test:reconnect', 'reconnect', 'test:reconnect'));
            $this->assertEquals('reconnect', $cache->load('test:reconnect', 5));
            return true;
        }, 10000, 1000);
    }

    /**
     * @depends testCacheReconnect
     */
    public function testCacheReconnectPersistent(): void
    {
        $redis = new Redis();
        $redis->pconnect('redis', 6379);
        $cache = new Cache(
            (new RedisAdapter($redis))
                ->setMaxRetries(CACHE_RECONNECT_MAX_RETRIES)
                ->setRetryDelay(CACHE_RECONNECT_RETRY_DELAY)
        );

        $cache->save('test:reconnect_persistent', 'reconnect_persistent', 'test:reconnect_persistent');

        $stopCmd = 'docker ps -a --filter "name=appwrite-redis" --format "{{.Names}}" | xargs -r docker stop';
        exec($stopCmd . ' 2>&1', $output, $exitCode);
        $this->assertEquals(0, $exitCode, "Docker stop failed: $stopCmd\nOutput: " . implode("\n", $output));
        sleep(1);

        try {
            try {
                $cache->load('test:reconnect_persistent', 5);
                $this->fail('Redis connection should have failed');
            } catch (\RedisException $e) {
            }
        } finally {
            $output = [];
            $startCmd = 'docker ps -a --filter "name=appwrite-redis" --format "{{.Names}}" | xargs -r docker start';
            exec($startCmd . ' 2>&1', $output, $exitCode);
            $this->assertEquals(0, $exitCode, "Docker start failed: $startCmd\nOutput: " . implode("\n", $output));
        }

        $this->assertEventually(function () use ($cache) {
            $this->assertEquals('reconnect_persistent', $cache->save('test:reconnect_persistent', 'reconnect_persistent', 'test:reconnect_persistent'));
            $this->assertEquals('reconnect_persistent', $cache->load('test:reconnect_persistent', 5));
            return true;
        }, 10000, 1000);
    }
}
