<?php

namespace Utopia\Abuse\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\Abuse\Adapter\TimeLimit;

abstract class Base extends TestCase
{
    abstract public function getAdapter(string $key, int $limit, int $seconds): TimeLimit;

    /**
     * Test a static key with a limit of 2 requests per second
     */
    public function testStaticKey(): void
    {
        $adapter = $this->getAdapter('static-key', 2, 1);
        $this->waitForWindowStart(1);
        $this->assertFalse($adapter->check()->limited);
        $this->assertFalse($adapter->check()->limited);
        $this->assertTrue($adapter->check()->limited);
    }

    /**
     * Test a dynamic key with a limit of 2 requests per second
     */
    public function testDynamicKey(): void
    {
        $adapter = $this->getAdapter('dynamic-key-{{ip}}', 2, 1)
            ->withParams(['{{ip}}' => '0.0.0.10']);
        $this->waitForWindowStart(1);
        $this->assertFalse($adapter->check()->limited);
        $this->assertFalse($adapter->check()->limited);
        $this->assertTrue($adapter->check()->limited);
    }

    /**
     * Test a dynamic key with 2 params
     */
    public function testDynamicKeyWith2Params(): void
    {
        $adapter = $this->getAdapter('two-params-{{ip}}-{{email}}', 2, 1)
            ->withParams(['{{ip}}' => '0.0.0.10', '{{email}}' => 'test@test.com']);
        $this->waitForWindowStart(1);
        $this->assertFalse($adapter->check()->limited);
        $this->assertFalse($adapter->check()->limited);
        $this->assertTrue($adapter->check()->limited);
    }

    /**
     * Test a dynamic key with higher request rate like 10 requests per second
     */
    public function testDynamicKeyFastRequests(): void
    {
        $adapter = $this->getAdapter('fast-requests-{{ip}}', 10, 1)
            ->withParams(['{{ip}}' => '0.0.0.10']);
        $this->waitForWindowStart(1);
        for ($i = 0; $i < 10; $i++) {
            $this->assertFalse($adapter->check()->limited);
        }
        $this->assertTrue($adapter->check()->limited);
    }

    /**
     * Test that the limit is reset after the time limit
     */
    public function testLimitReset(): void
    {
        $adapter = $this->getAdapter('limit-reset-{{ip}}', 10, 2)
            ->withParams(['{{ip}}' => '127.0.0.1']);
        $now = $this->waitForWindowStart(2);
        for ($i = 0; $i < 10; $i++) {
            $this->assertFalse($adapter->check()->limited);
        }
        $this->assertTrue($adapter->check()->limited);

        $this->waitUntil($now + 2);

        $this->assertFalse($adapter->check()->limited);
    }

    /**
     * Test that one instance is allowed again once its window has passed
     */
    public function testNewWindowAllowsSameInstance(): void
    {
        $adapter = $this->getAdapter('new-window-' . \uniqid(), 1, 1);
        $now = $this->waitForWindowStart(1);
        $this->assertFalse($adapter->check()->limited);
        $this->assertTrue($adapter->check()->limited);

        $this->waitUntil($now + 1);

        $this->assertFalse($adapter->check()->limited);
    }

    /**
     * Test the remaining count reported by each check
     */
    public function testRemaining(): void
    {
        $adapter = $this->getAdapter('remaining-' . \uniqid(), 3, 3600);
        $this->waitForWindowStart(1);

        $result = $adapter->check();
        $this->assertFalse($result->limited);
        $this->assertSame(3, $result->limit);
        $this->assertSame(2, $result->remaining);

        $result = $adapter->check();
        $this->assertFalse($result->limited);
        $this->assertSame(1, $result->remaining);

        $result = $adapter->check();
        $this->assertFalse($result->limited);
        $this->assertSame(0, $result->remaining);

        $result = $adapter->check();
        $this->assertTrue($result->limited);
        $this->assertSame(3, $result->limit);
        $this->assertSame(0, $result->remaining);
    }

    /**
     * Test that peeking reports the state without recording a hit
     */
    public function testPeekDoesNotConsume(): void
    {
        $adapter = $this->getAdapter('peek-' . \uniqid(), 2, 3600);
        $this->waitForWindowStart(1);

        for ($i = 0; $i < 3; $i++) {
            $result = $adapter->peek();
            $this->assertFalse($result->limited);
            $this->assertSame(2, $result->limit);
            $this->assertSame(1, $result->remaining);
        }

        $this->assertSame(1, $adapter->check()->remaining);

        $result = $adapter->peek();
        $this->assertFalse($result->limited);
        $this->assertSame(0, $result->remaining);

        $this->assertFalse($adapter->check()->limited);

        $result = $adapter->peek();
        $this->assertTrue($result->limited);
        $this->assertSame(0, $result->remaining);

        $this->assertTrue($adapter->check()->limited);
    }

    /**
     * Test that the reset time is the end of the current window
     */
    public function testResetTime(): void
    {
        $adapter = $this->getAdapter('reset-time-' . \uniqid(), 5, 3600);
        $this->waitForWindowStart(1);

        $result = $adapter->check();
        $now = \time();
        $this->assertSame($now - $now % 3600 + 3600, $result->reset);

        $result = $adapter->peek();
        $now = \time();
        $this->assertSame($now - $now % 3600 + 3600, $result->reset);
    }

    /**
     * Test that deriving an adapter with new params leaves the original untouched
     */
    public function testWithParamsKeepsOriginalKey(): void
    {
        $prefix = 'param-reuse-' . \uniqid() . '-';
        $base = $this->getAdapter($prefix . '{{ip}}', 2, 3600);
        $first = $base->withParams(['{{ip}}' => '10.0.0.1']);
        $this->waitForWindowStart(1);

        $this->assertFalse($first->check()->limited);
        $this->assertFalse($first->check()->limited);

        $second = $first->withParams(['{{ip}}' => '10.0.0.2']);

        $this->assertSame(\str_replace('{{ip}}', '10.0.0.1', $base->key()), $first->key());
        $this->assertSame(\str_replace('{{ip}}', '10.0.0.2', $base->key()), $second->key());

        $this->assertFalse($second->check()->limited);
        $this->assertFalse($second->check()->limited);
        $this->assertTrue($second->check()->limited);

        $this->assertTrue($first->check()->limited);
    }

    /**
     * Test the reset functionality
     */
    public function testReset(): void
    {
        $adapter = $this->getAdapter('reset-test-{{ip}}', 5, 600)
            ->withParams(['{{ip}}' => \uniqid()]);
        $this->waitForWindowStart(1);

        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($adapter->check()->limited);
        }
        $this->assertTrue($adapter->check()->limited);

        $adapter->reset();

        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($adapter->check()->limited);
        }
        $this->assertTrue($adapter->check()->limited);

        $adapter = $this->getAdapter('reset-test-{{ip}}', 2, 600)
            ->withParams(['{{ip}}' => \uniqid()]);
        for ($i = 0; $i < 15; $i++) {
            $this->assertFalse($adapter->check()->limited);
            $adapter->reset();
        }
    }

    private function waitForWindowStart(int $seconds): int
    {
        $now = \time();
        $this->waitUntil($now - $now % $seconds + $seconds);

        return \time();
    }

    private function waitUntil(int $timestamp): void
    {
        while (\time() < $timestamp) {
            \usleep(1000);
        }
    }
}
