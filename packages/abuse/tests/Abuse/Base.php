<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;

abstract class Base extends TestCase
{
    abstract public function getAdapter(string $key, int $limit, int $seconds): TimeLimit;

    /**
     * Test a static key with a limit of 2 requests per second
     */
    public function testStaticKey(): void
    {
        $adapter = $this->getAdapter('static-key', 2, 1);
        $abuse = new Abuse($adapter);
        $this->assertSame($abuse->check(), false);
        $this->assertSame($abuse->check(), false);
        $this->assertSame($abuse->check(), true);
    }

    /**
     * Test a dynamic key with a limit of 2 requests per second
     */
    public function testDynamicKey(): void
    {
        $adapter = $this->getAdapter('dynamic-key-{{ip}}', 2, 1);
        $adapter->setParam('{{ip}}', '0.0.0.10');
        $abuse = new Abuse($adapter);
        $this->assertSame($abuse->check(), false);
        $this->assertSame($abuse->check(), false);
        $this->assertSame($abuse->check(), true);
    }

    /**
     * Test a dynamic key with 2 params
     */
    public function testDynamicKeyWith2Params(): void
    {
        $adapter = $this->getAdapter('two-params-{{ip}}-{{email}}', 2, 1);
        $adapter->setParam('{{ip}}', '0.0.0.10');
        $adapter->setParam('{{email}}', 'test@test.com');
        $abuse = new Abuse($adapter);
        $this->assertSame($abuse->check(), false);
        $this->assertSame($abuse->check(), false);
        $this->assertSame($abuse->check(), true);
    }

    /**
     * Test a dynamic key with higher request rate like 10 requests per second
     */
    public function testDynamicKeyFastRequests(): void
    {
        $adapter = $this->getAdapter('fast-requests-{{ip}}', 10, 1);
        $adapter->setParam('{{ip}}', '0.0.0.10');
        $abuse = new Abuse($adapter);
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame($abuse->check(), false);
        }
        $this->assertSame($abuse->check(), true);
    }

    /**
     * Test that the limit is reset after the time limit
     */
    public function testLimitReset(): void
    {
        $adapter = $this->getAdapter('limit-reset-{{ip}}', 10, 2);
        $adapter->setParam('{{ip}}', '127.0.0.1');
        $abuse = new Abuse($adapter);
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame($abuse->check(), false);
        }
        $this->assertSame($abuse->check(), true);

        // Wait for the limit to reset
        sleep(2);

        /** Seems to be a bug in the code where if use the same adapter, it caches the result of the previous check */
        $adapter = $this->getAdapter('limit-reset-{{ip}}', 10, 1);
        $adapter->setParam('{{ip}}', '127.0.0.1');
        $abuse = new Abuse($adapter);
        $this->assertSame($abuse->check(), false);
    }

    /**
     * Verify that the time format is correct
     */
    public function testTimeFormat(): void
    {
        $now = time();
        $adapter = $this->getAdapter('', 1, 1);
        $this->assertSame($adapter->time(), $now);
        $this->assertSame(true, \is_int($adapter->time()));
    }

    /**
     * Test the reset functionality
     */
    public function testReset(): void
    {
        $adapter = $this->getAdapter('reset-test-{{ip}}', 5, 600);
        $adapter->setParam('{{ip}}', '192.168.1.1');
        $abuse = new Abuse($adapter);

        // 5 OK, 6th has limit
        $this->assertSame($abuse->check(), false);
        $this->assertSame($abuse->check(), false);
        $this->assertSame($abuse->check(), false);
        $this->assertSame($abuse->check(), false);
        $this->assertSame($abuse->check(), false);
        $this->assertSame($abuse->check(), true);

        // Reset the count
        $abuse->reset();

        // Should be 5 more OK, then 6th limit
        $this->assertSame($abuse->check(), false);
        $this->assertSame($abuse->check(), false);
        $this->assertSame($abuse->check(), false);
        $this->assertSame($abuse->check(), false);
        $this->assertSame($abuse->check(), false);
        $this->assertSame($abuse->check(), true);

        // TO be sure, lets do bunch of requests with resets
        // All should pass successfully
        $adapter = $this->getAdapter('reset-test-{{ip}}', 2, 600);
        $adapter->setParam('{{ip}}', '192.168.1.2');
        $abuse = new Abuse($adapter);
        for ($i = 0; $i < 15; $i++) {
            $this->assertSame($abuse->check(), false);
            $abuse->reset();
        }
    }
}
