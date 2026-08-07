<?php

declare(strict_types=1);

namespace Tests\Unit\Redis;

use Appwrite\Queue\Connection\Redis as QueueRedis;
use Appwrite\Redis\Auth;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Redis as CacheRedis;

final class ConnectionTest extends TestCase
{
    public function testAuthenticatedRedisConnectionsCanUseQueueAndCache(): void
    {
        $host = $this->env('_APP_REDIS_HOST', 'redis');
        $port = (int) $this->env('_APP_REDIS_PORT', '6379');
        $user = 'appwrite_test_'.\bin2hex(\random_bytes(4));
        $nopassUser = $user.'_nopass';
        $pass = 'redis-test-pass';
        $key = 'appwrite:test:redis:'.\bin2hex(\random_bytes(8));
        $admin = $this->adminRedis($host, $port);

        try {
            $this->createAclUser($admin, $user, $pass);
            $this->createNopassAclUser($admin, $nopassUser);

            $queue = new QueueRedis($host, $port, $user, $pass);
            $this->assertTrue($queue->set($key.':queue', 'queue-value', 10));
            $this->assertSame('queue-value', $queue->get($key.':queue'));

            $nopassQueue = new QueueRedis($host, $port, $nopassUser, '');
            $this->assertTrue($nopassQueue->set($key.':nopass', 'nopass-value', 10));
            $this->assertSame('nopass-value', $nopassQueue->get($key.':nopass'));

            $redis = new \Redis();
            $redis->connect($host, $port, 1.0);
            Auth::authenticate($redis, $user, $pass);

            $cache = new CacheRedis($redis);
            $this->assertSame('cache-value', $cache->save($key.':cache', 'cache-value'));
            $this->assertSame('cache-value', $cache->load($key.':cache', 60));

            $cache->purge($key.':cache');
            $queue->remove($key.':queue');
            $nopassQueue->remove($key.':nopass');
            $queue->close();
            $nopassQueue->close();
            $redis->close();
        } finally {
            $admin->rawCommand('ACL', 'DELUSER', $user, $nopassUser);
            $admin->close();
        }
    }

    private function adminRedis(string $host, int $port): \Redis
    {
        $redis = new \Redis();

        try {
            $redis->connect($host, $port, 1.0);
            Auth::authenticate($redis, $this->env('_APP_REDIS_USER', ''), $this->env('_APP_REDIS_PASS', ''));
            $redis->rawCommand('ACL', 'WHOAMI');
        } catch (\RedisException $e) {
            $this->markTestSkipped('Authenticated Redis integration test unavailable: '.$e->getMessage());
        }

        return $redis;
    }

    private function createAclUser(\Redis $redis, string $user, string $pass): void
    {
        try {
            $redis->rawCommand('ACL', 'SETUSER', $user, 'on', '>'.$pass, '~appwrite:test:*', '+@all');
        } catch (\RedisException $e) {
            $this->markTestSkipped('Redis ACL SETUSER unavailable: '.$e->getMessage());
        }
    }

    private function createNopassAclUser(\Redis $redis, string $user): void
    {
        try {
            $redis->rawCommand('ACL', 'SETUSER', $user, 'on', 'nopass', '~appwrite:test:*', '+@all');
        } catch (\RedisException $e) {
            $this->markTestSkipped('Redis ACL SETUSER unavailable: '.$e->getMessage());
        }
    }

    private function env(string $name, string $default): string
    {
        $value = \getenv($name);

        return \is_string($value) && $value !== '' ? $value : $default;
    }
}
