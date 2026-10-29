<?php

namespace Utopia\Abuse\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Abuse\Tests\TimeLimit\Memory;
use Utopia\Abuse\Tests\TimeLimit\Store;

final class TimeLimitTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        $this->store = new Store(1_700_000_017);
    }

    public function testRemainingCountsDown(): void
    {
        $adapter = new Memory('ip:{ip}', 3, 60, $this->store)->withParam('{ip}', '127.0.0.1');

        $this->assertSame(2, $adapter->check()->remaining);
        $this->assertSame(1, $adapter->check()->remaining);

        $result = $adapter->check();
        $this->assertFalse($result->limited);
        $this->assertSame(0, $result->remaining);

        $result = $adapter->check();
        $this->assertTrue($result->limited);
        $this->assertSame(0, $result->remaining);
        $this->assertSame(3, $result->limit);
    }

    public function testPeekDoesNotConsume(): void
    {
        $adapter = new Memory('ip:{ip}', 3, 60, $this->store)->withParam('{ip}', '127.0.0.1');

        $this->assertSame(2, $adapter->peek()->remaining);
        $this->assertSame(2, $adapter->peek()->remaining);
        $this->assertSame([], $this->store->counts);

        $adapter->check();

        $result = $adapter->peek();
        $this->assertFalse($result->limited);
        $this->assertSame(1, $result->remaining);
        $this->assertSame(1, $adapter->peek()->remaining);
    }

    public function testResetIsEndOfWindow(): void
    {
        $adapter = new Memory('ip:{ip}', 3, 60, $this->store);
        $expected = $this->store->now - ($this->store->now % 60) + 60;

        $this->assertSame($expected, $adapter->check()->reset);
        $this->assertSame($expected, $adapter->peek()->reset);
    }

    public function testZeroLimitIsUnlimitedWithoutStorage(): void
    {
        $adapter = new Memory('ip:{ip}', 0, 60, $this->store);
        $expected = $this->store->now - ($this->store->now % 60) + 60;

        for ($i = 0; $i < 5; $i++) {
            $result = $adapter->check();
            $this->assertFalse($result->limited);
            $this->assertSame(0, $result->limit);
            $this->assertSame(0, $result->remaining);
            $this->assertSame($expected, $result->reset);
        }

        $this->assertFalse($adapter->peek()->limited);
        $this->assertSame(0, $this->store->calls);
        $this->assertSame([], $this->store->counts);
    }

    public function testResetRestoresQuota(): void
    {
        $adapter = new Memory('ip:{ip}', 2, 60, $this->store);

        $adapter->check();
        $adapter->check();
        $this->assertTrue($adapter->check()->limited);

        $adapter->reset();

        $this->assertSame(1, $adapter->check()->remaining);
        $this->assertFalse($adapter->check()->limited);
        $this->assertTrue($adapter->check()->limited);
    }

    public function testSecondsMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('seconds must be greater than 0');

        new Memory('ip:{ip}', 1, 0, $this->store);
    }

    public function testInstanceFollowsTheClockAcrossWindows(): void
    {
        $adapter = new Memory('ip:{ip}', 1, 60, $this->store);

        $first = $adapter->check();
        $this->assertFalse($first->limited);
        $this->assertTrue($adapter->check()->limited);

        $this->store->now += 60;

        $second = $adapter->check();
        $this->assertFalse($second->limited);
        $this->assertSame($first->reset + 60, $second->reset);
        $this->assertTrue($adapter->check()->limited);
    }

    public function testWithParamsClonesCountIndependently(): void
    {
        $base = new Memory('ip:{ip}', 1, 60, $this->store);
        $a = $base->withParams(['{ip}' => 'a']);
        $b = $a->withParams(['{ip}' => 'b']);

        $this->assertSame('ip:a', $a->key());
        $this->assertSame('ip:b', $b->key());

        $this->assertFalse($a->check()->limited);
        $this->assertFalse($b->check()->limited);
        $this->assertTrue($a->check()->limited);
        $this->assertTrue($b->check()->limited);

        $this->assertSame('ip:a', $a->key());
        $this->assertSame('ip:{ip}', $base->key());
    }
}
