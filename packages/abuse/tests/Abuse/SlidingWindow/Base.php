<?php

namespace Utopia\Tests\SlidingWindow;

use PHPUnit\Framework\TestCase;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\SlidingWindow;

abstract class Base extends TestCase
{
    /**
     * @param  string  $key
     * @param  int  $limit
     * @param  int  $windowSize
     * @param  int  $ttl
     * @return SlidingWindow
     */
    abstract public function getAdapter(string $key, int $limit, int $windowSize, int $ttl): SlidingWindow;

    /**
     * Test a static key with a limit of 2 requests per window
     */
    public function testStaticKey(): void
    {
        $adapter = $this->getAdapter('sw-static-key', 2, 1, 2);
        $abuse = new Abuse($adapter);
        $this->assertSame(false, $abuse->check());
        $this->assertSame(false, $abuse->check());
        $this->assertSame(true, $abuse->check());
    }

    /**
     * Test a dynamic key with a limit of 2 requests per window
     */
    public function testDynamicKey(): void
    {
        $adapter = $this->getAdapter('sw-dynamic-key-{{ip}}', 2, 1, 2);
        $adapter->setParam('{{ip}}', '0.0.0.10');
        $abuse = new Abuse($adapter);
        $this->assertSame(false, $abuse->check());
        $this->assertSame(false, $abuse->check());
        $this->assertSame(true, $abuse->check());
    }

    /**
     * Test a dynamic key with 2 params
     */
    public function testDynamicKeyWith2Params(): void
    {
        $adapter = $this->getAdapter('sw-two-params-{{ip}}-{{email}}', 2, 1, 2);
        $adapter->setParam('{{ip}}', '0.0.0.10');
        $adapter->setParam('{{email}}', 'test@test.com');
        $abuse = new Abuse($adapter);
        $this->assertSame(false, $abuse->check());
        $this->assertSame(false, $abuse->check());
        $this->assertSame(true, $abuse->check());
    }

    /**
     * Test a higher request rate like 10 requests per window
     */
    public function testFastRequests(): void
    {
        $adapter = $this->getAdapter('sw-fast-requests-{{ip}}', 10, 1, 2);
        $adapter->setParam('{{ip}}', '0.0.0.11');
        $abuse = new Abuse($adapter);
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(false, $abuse->check());
        }
        $this->assertSame(true, $abuse->check());
    }

    /**
     * Test that remaining reports the correct number of allowed requests
     */
    public function testRemaining(): void
    {
        $adapter = $this->getAdapter('sw-remaining-{{ip}}', 3, 60, 120);
        $adapter->setParam('{{ip}}', '0.0.0.12');
        $abuse = new Abuse($adapter);

        $this->assertSame(2, $adapter->remaining()); // nothing counted yet: limit - (0 + 1)
        $this->assertSame(false, $abuse->check());   // 1 used
        $this->assertSame(1, $adapter->remaining());
        $this->assertSame(false, $abuse->check());   // 2 used
        $this->assertSame(0, $adapter->remaining());
    }

    /**
     * Test that the window resets once both buckets expire
     */
    public function testWindowExpiry(): void
    {
        $adapter = $this->getAdapter('sw-window-expiry-{{ip}}', 3, 1, 2);
        $adapter->setParam('{{ip}}', '127.0.0.1');
        $abuse = new Abuse($adapter);
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(false, $abuse->check());
        }
        $this->assertSame(true, $abuse->check());

        // Wait for both the current and previous buckets (ttl = 2) to expire
        sleep(3);

        // A fresh adapter recomputes the window; the old buckets are gone
        $adapter = $this->getAdapter('sw-window-expiry-{{ip}}', 3, 1, 2);
        $adapter->setParam('{{ip}}', '127.0.0.1');
        $abuse = new Abuse($adapter);
        $this->assertSame(false, $abuse->check());
    }

    /**
     * Verify that time() returns the aligned window start as an int
     */
    public function testTimeFormat(): void
    {
        $windowSize = 1;
        $now = \time();
        $adapter = $this->getAdapter('sw-time', 1, $windowSize, 2);
        $this->assertSame((int)($now - ($now % $windowSize)), $adapter->time());
        $this->assertSame(true, \is_int($adapter->time()));
    }

    /**
     * Test the reset functionality clears both buckets
     */
    public function testReset(): void
    {
        $adapter = $this->getAdapter('sw-reset-test-{{ip}}', 5, 600, 1200);
        $adapter->setParam('{{ip}}', '192.168.1.1');
        $abuse = new Abuse($adapter);

        // 5 OK, 6th limited
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(false, $abuse->check());
        }
        $this->assertSame(true, $abuse->check());

        // Reset clears the counters
        $abuse->reset();

        // 5 more OK, then limited again
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(false, $abuse->check());
        }
        $this->assertSame(true, $abuse->check());
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
        $abuse = new Abuse($adapter);
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame(false, $abuse->check());
        }
    }
}
