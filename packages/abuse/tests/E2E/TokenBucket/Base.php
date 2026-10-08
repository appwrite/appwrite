<?php

namespace Utopia\Abuse\Tests\E2E\TokenBucket;

use PHPUnit\Framework\TestCase;
use Utopia\Abuse\Adapter\TokenBucket;

abstract class Base extends TestCase
{
    abstract public function getAdapter(string $key, int $tokens, float $refillRate): TokenBucket;

    /**
     * Test a static key with a capacity of 2 tokens
     */
    public function testStaticKey(): void
    {
        $adapter = $this->getAdapter('tb-static-key', 2, 0.001);
        $this->assertSame(false, $adapter->check()->limited);
        $this->assertSame(false, $adapter->check()->limited);
        $this->assertSame(true, $adapter->check()->limited);
    }

    /**
     * Test a dynamic key with a capacity of 2 tokens
     */
    public function testDynamicKey(): void
    {
        $adapter = $this->getAdapter('tb-dynamic-key-{{ip}}', 2, 0.001)
            ->withParams(['{{ip}}' => '0.0.0.10']);
        $this->assertSame(false, $adapter->check()->limited);
        $this->assertSame(false, $adapter->check()->limited);
        $this->assertSame(true, $adapter->check()->limited);
    }

    /**
     * Test a dynamic key with 2 params
     */
    public function testDynamicKeyWith2Params(): void
    {
        $adapter = $this->getAdapter('tb-two-params-{{ip}}-{{email}}', 2, 0.001)
            ->withParams(['{{ip}}' => '0.0.0.10', '{{email}}' => 'test@test.com']);
        $this->assertSame(false, $adapter->check()->limited);
        $this->assertSame(false, $adapter->check()->limited);
        $this->assertSame(true, $adapter->check()->limited);
    }

    /**
     * Test that a full bucket allows a burst up to its capacity
     */
    public function testBurst(): void
    {
        $adapter = $this->getAdapter('tb-burst-{{ip}}', 10, 0.001)
            ->withParams(['{{ip}}' => '0.0.0.11']);
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(false, $adapter->check()->limited);
        }
        $this->assertSame(true, $adapter->check()->limited);
    }

    /**
     * Test that remaining reports the tokens still available
     */
    public function testRemaining(): void
    {
        $adapter = $this->getAdapter('tb-remaining-{{ip}}', 3, 0.001)
            ->withParams(['{{ip}}' => '0.0.0.12']);
        $adapter->reset();

        $this->assertSame(2, $adapter->peek()->remaining);
        $this->assertSame(2, $adapter->peek()->remaining);
        $this->assertSame(2, $adapter->check()->remaining);
        $this->assertSame(1, $adapter->check()->remaining);
        $this->assertSame(0, $adapter->check()->remaining);

        $result = $adapter->check();
        $this->assertSame(true, $result->limited);
        $this->assertSame(3, $result->limit);
        $this->assertSame(0, $result->remaining);
    }

    /**
     * Test that the same instance is allowed again once its bucket refills
     */
    public function testRefill(): void
    {
        $adapter = $this->getAdapter('tb-refill-{{ip}}', 1, 1.0)
            ->withParams(['{{ip}}' => '0.0.0.13']);
        $adapter->reset();

        $now = $this->freshSecond();
        $first = $adapter->check();
        $this->assertSame(false, $first->limited);
        $this->assertSame(true, $adapter->check()->limited);

        $this->waitUntil($now + 2);

        $second = $adapter->check();
        $this->assertSame(false, $second->limited);
        $this->assertGreaterThan($first->reset, $second->reset);
    }

    /**
     * Test that reset reports when the bucket is full again
     */
    public function testResetTime(): void
    {
        $tokens = 2;
        $refillRate = 1.0;
        $adapter = $this->getAdapter('tb-reset-time', $tokens, $refillRate);
        $adapter->reset();

        $before = \time();
        $result = $adapter->check();
        $after = \time();

        $this->assertGreaterThanOrEqual($before, $result->reset);
        $this->assertLessThanOrEqual($after + (int) \ceil($tokens / $refillRate) + 1, $result->reset);
    }

    /**
     * Test that clones from withParams count independently and leave the original untouched
     */
    public function testParamReuse(): void
    {
        $base = $this->getAdapter('tb-reuse-{ip}', 1, 0.001);
        $a = $base->withParams(['{ip}' => 'a']);
        $key = $a->key();
        $b = $a->withParams(['{ip}' => 'b']);
        $a->reset();
        $b->reset();

        $this->assertSame($key, $a->key());
        $this->assertNotSame($a->key(), $b->key());

        $this->assertSame(false, $a->check()->limited);
        $this->assertSame(true, $a->check()->limited);
        $this->assertSame(false, $b->check()->limited);
        $this->assertSame(true, $b->check()->limited);
    }

    /**
     * Test the reset functionality refills the bucket
     */
    public function testReset(): void
    {
        $adapter = $this->getAdapter('tb-reset-test-{{ip}}', 5, 0.001)
            ->withParams(['{{ip}}' => '192.168.1.1']);

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(false, $adapter->check()->limited);
        }
        $this->assertSame(true, $adapter->check()->limited);

        $adapter->reset();

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(false, $adapter->check()->limited);
        }
        $this->assertSame(true, $adapter->check()->limited);
    }

    /**
     * Test that a non-positive refill rate is rejected
     */
    public function testRefillRateGuard(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->getAdapter('tb-guard', 1, 0.0);
    }

    /**
     * Test that limit 0 means unlimited
     */
    public function testUnlimited(): void
    {
        $adapter = $this->getAdapter('tb-unlimited', 0, 1.0);
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame(false, $adapter->check()->limited);
        }
    }

    private function freshSecond(): int
    {
        $start = \time();
        while (($now = \time()) === $start) {
            \usleep(1000);
        }

        return $now;
    }

    private function waitUntil(int $timestamp): void
    {
        while (\time() < $timestamp) {
            \usleep(10000);
        }
    }
}
