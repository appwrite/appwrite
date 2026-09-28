<?php

namespace Utopia\Tests\TokenBucket;

use PHPUnit\Framework\TestCase;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TokenBucket;

abstract class Base extends TestCase
{
    /**
     * @param  string  $key
     * @param  int  $tokens
     * @param  float  $refillRate
     * @return TokenBucket
     */
    abstract public function getAdapter(string $key, int $tokens, float $refillRate): TokenBucket;

    /**
     * Test a static key with a capacity of 2 tokens
     */
    public function testStaticKey(): void
    {
        $adapter = $this->getAdapter('tb-static-key', 2, 0.001);
        $abuse = new Abuse($adapter);
        $this->assertSame(false, $abuse->check());
        $this->assertSame(false, $abuse->check());
        $this->assertSame(true, $abuse->check());
    }

    /**
     * Test a dynamic key with a capacity of 2 tokens
     */
    public function testDynamicKey(): void
    {
        $adapter = $this->getAdapter('tb-dynamic-key-{{ip}}', 2, 0.001);
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
        $adapter = $this->getAdapter('tb-two-params-{{ip}}-{{email}}', 2, 0.001);
        $adapter->setParam('{{ip}}', '0.0.0.10');
        $adapter->setParam('{{email}}', 'test@test.com');
        $abuse = new Abuse($adapter);
        $this->assertSame(false, $abuse->check());
        $this->assertSame(false, $abuse->check());
        $this->assertSame(true, $abuse->check());
    }

    /**
     * Test that a full bucket allows a burst up to its capacity
     */
    public function testBurst(): void
    {
        $adapter = $this->getAdapter('tb-burst-{{ip}}', 10, 0.001);
        $adapter->setParam('{{ip}}', '0.0.0.11');
        $abuse = new Abuse($adapter);
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(false, $abuse->check());
        }
        $this->assertSame(true, $abuse->check());
    }

    /**
     * Test that remaining reports the tokens still available
     */
    public function testRemaining(): void
    {
        $adapter = $this->getAdapter('tb-remaining-{{ip}}', 3, 0.001);
        $adapter->setParam('{{ip}}', '0.0.0.12');
        $abuse = new Abuse($adapter);

        $this->assertSame(2, $adapter->remaining()); // full bucket: limit - (0 + 1)
        $this->assertSame(false, $abuse->check());   // 1 consumed
        $this->assertSame(1, $adapter->remaining());
        $this->assertSame(false, $abuse->check());   // 2 consumed
        $this->assertSame(0, $adapter->remaining());
    }

    /**
     * Test that tokens refill over time
     */
    public function testRefill(): void
    {
        // 1 token/sec, capacity 1: consume it, then a refill lets one more through
        $adapter = $this->getAdapter('tb-refill-{{ip}}', 1, 1.0);
        $adapter->setParam('{{ip}}', '0.0.0.13');
        $abuse = new Abuse($adapter);

        $this->assertSame(false, $abuse->check()); // consume the only token
        $this->assertSame(true, $abuse->check());  // empty, throttled

        sleep(2); // refill ~2 tokens (capped at capacity 1)

        $this->assertSame(false, $abuse->check()); // refilled, allowed again
    }

    /**
     * Verify that time() returns the current time as an int
     */
    public function testTimeFormat(): void
    {
        $adapter = $this->getAdapter('tb-time', 1, 1.0);
        $this->assertSame(true, \is_int($adapter->time()));
    }

    /**
     * Test the reset functionality refills the bucket
     */
    public function testReset(): void
    {
        $adapter = $this->getAdapter('tb-reset-test-{{ip}}', 5, 0.001);
        $adapter->setParam('{{ip}}', '192.168.1.1');
        $abuse = new Abuse($adapter);

        // 5 OK, 6th limited
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(false, $abuse->check());
        }
        $this->assertSame(true, $abuse->check());

        // Reset refills the bucket
        $abuse->reset();

        // 5 more OK, then limited again
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(false, $abuse->check());
        }
        $this->assertSame(true, $abuse->check());
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
        $abuse = new Abuse($adapter);
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame(false, $abuse->check());
        }
    }
}
