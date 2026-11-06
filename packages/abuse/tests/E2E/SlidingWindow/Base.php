<?php

namespace Utopia\Abuse\Tests\E2E\SlidingWindow;

use PHPUnit\Framework\TestCase;
use Utopia\Abuse\Adapter\SlidingWindow;

abstract class Base extends TestCase
{
    abstract public function getAdapter(string $key, int $limit, int $windowSize, int $ttl): SlidingWindow;

    /**
     * Test a static key with a limit of 2 requests per window
     */
    public function testStaticKey(): void
    {
        $adapter = $this->getAdapter('sw-static-key', 2, 1, 2);
        $this->assertSame(false, $adapter->check()->limited);
        $this->assertSame(false, $adapter->check()->limited);
        $this->assertSame(true, $adapter->check()->limited);
    }

    /**
     * Test a dynamic key with a limit of 2 requests per window
     */
    public function testDynamicKey(): void
    {
        $adapter = $this->getAdapter('sw-dynamic-key-{{ip}}', 2, 1, 2)
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
        $adapter = $this->getAdapter('sw-two-params-{{ip}}-{{email}}', 2, 1, 2)
            ->withParams(['{{ip}}' => '0.0.0.10', '{{email}}' => 'test@test.com']);
        $this->assertSame(false, $adapter->check()->limited);
        $this->assertSame(false, $adapter->check()->limited);
        $this->assertSame(true, $adapter->check()->limited);
    }

    /**
     * Test a higher request rate like 10 requests per window
     */
    public function testFastRequests(): void
    {
        $adapter = $this->getAdapter('sw-fast-requests-{{ip}}', 10, 1, 2)
            ->withParams(['{{ip}}' => '0.0.0.11']);
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(false, $adapter->check()->limited);
        }
        $this->assertSame(true, $adapter->check()->limited);
    }

    /**
     * Test that remaining reports the correct number of allowed requests
     */
    public function testRemaining(): void
    {
        $adapter = $this->getAdapter('sw-remaining-{{ip}}', 3, 3600, 7200)
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
     * Test that the same instance moves to a new window once the old buckets age out
     */
    public function testWindowExpiry(): void
    {
        $adapter = $this->getAdapter('sw-window-expiry-{{ip}}', 1, 1, 2)
            ->withParams(['{{ip}}' => '127.0.0.1']);

        $now = $this->freshSecond();
        $first = $adapter->check();
        $this->assertSame(false, $first->limited);
        $this->assertSame($now + 1, $first->reset);
        $this->assertSame(true, $adapter->check()->limited);

        $this->waitUntil($now + 2);

        $second = $adapter->check();
        $this->assertSame(false, $second->limited);
        $this->assertGreaterThan($first->reset, $second->reset);
    }

    /**
     * Test that reset reports the end of the current window
     */
    public function testResetTime(): void
    {
        $windowSize = 3600;
        $adapter = $this->getAdapter('sw-reset-time', 1, $windowSize, $windowSize * 2);

        $this->freshSecond();
        $peek = $adapter->peek();
        $check = $adapter->check();
        $now = \time();
        $expected = $now - ($now % $windowSize) + $windowSize;

        $this->assertSame($expected, $peek->reset);
        $this->assertSame($expected, $check->reset);
    }

    /**
     * Test that clones from withParams count independently and leave the original untouched
     */
    public function testParamReuse(): void
    {
        $base = $this->getAdapter('sw-reuse-{ip}', 1, 3600, 7200);
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
     * Test the reset functionality clears both buckets
     */
    public function testReset(): void
    {
        $adapter = $this->getAdapter('sw-reset-test-{{ip}}', 5, 600, 1200)
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
     * Test that a ttl smaller than the window size is rejected
     */
    public function testTtlGuard(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->getAdapter('sw-guard', 1, 10, 5);
    }

    /**
     * Test that limit 0 means unlimited
     */
    public function testUnlimited(): void
    {
        $adapter = $this->getAdapter('sw-unlimited', 0, 1, 2);
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
