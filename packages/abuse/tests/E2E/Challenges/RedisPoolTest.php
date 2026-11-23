<?php

namespace Utopia\Abuse\Tests\E2E\Challenges;

use PHPUnit\Framework\TestCase;
use Throwable;
use Utopia\Abuse\Challenges\ProofOfWork\Nonce\Outcome;
use Utopia\Abuse\Challenges\ProofOfWork\Nonce\RedisPool;
use Utopia\Abuse\Tests\E2E\Services;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool;

final class RedisPoolTest extends TestCase
{
    /**
     * @return Pool<\Redis>
     */
    private function pool(int $port = Services::REDIS_PORT): Pool
    {
        return new Pool(new Stack(), 'abuse-pow-nonce', 1, function () use ($port): \Redis {
            $redis = new \Redis();
            $redis->connect(Services::HOST, $port, 0.5);

            return $redis;
        }, timeout: 0.0);
    }

    public function testFirstClaimIsFreshAndLaterClaimsAreReplays(): void
    {
        $nonces = new RedisPool($this->pool());
        $nonce = \bin2hex(\random_bytes(16));

        $this->assertSame(Outcome::Fresh, $nonces->consume('project1', $nonce, 60));
        $this->assertSame(Outcome::Consumed, $nonces->consume('project1', $nonce, 60));
    }

    public function testNoncesAreScopedPerProject(): void
    {
        $nonces = new RedisPool($this->pool());
        $nonce = \bin2hex(\random_bytes(16));

        $this->assertSame(Outcome::Fresh, $nonces->consume('project1', $nonce, 60));
        $this->assertSame(Outcome::Fresh, $nonces->consume('project2', $nonce, 60));
    }

    public function testPrefixSeparatesKeySpaces(): void
    {
        $nonce = \bin2hex(\random_bytes(16));

        $this->assertSame(Outcome::Fresh, new RedisPool($this->pool(), 'a:')->consume('project1', $nonce, 60));
        $this->assertSame(Outcome::Fresh, new RedisPool($this->pool(), 'b:')->consume('project1', $nonce, 60));
        $this->assertSame(Outcome::Consumed, new RedisPool($this->pool(), 'a:')->consume('project1', $nonce, 60));
    }

    public function testEmptyNonceIsAlwaysAReplay(): void
    {
        $this->assertSame(Outcome::Consumed, new RedisPool($this->pool())->consume('project1', '', 60));
    }

    public function testUnreachableRedisReportsUnavailable(): void
    {
        $errors = [];
        // Nothing listens on port 1, so every connection attempt fails.
        $nonces = new RedisPool($this->pool(1), onUnavailable: function (Throwable $error) use (&$errors): void {
            $errors[] = $error;
        });

        $this->assertSame(Outcome::Unavailable, $nonces->consume('project1', 'abc', 60));
        $this->assertCount(1, $errors);
    }
}
