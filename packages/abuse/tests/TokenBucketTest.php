<?php

namespace Utopia\Abuse\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Abuse\Tests\TokenBucket\Fixed;

final class TokenBucketTest extends TestCase
{
    public function testResetUsesFractionalBalance(): void
    {
        $adapter = new Fixed(tokens: 2, refillRate: 0.1, available: 1.5, time: 1000.0);

        $result = $adapter->check();
        $this->assertFalse($result->limited);
        $this->assertSame(0, $result->remaining);
        $this->assertSame(1015, $result->reset);

        $result = $adapter->peek();
        $this->assertFalse($result->limited);
        $this->assertSame(0, $result->remaining);
        $this->assertSame(1005, $result->reset);
    }

    public function testEmptyBucketIsLimited(): void
    {
        $result = new Fixed(tokens: 2, refillRate: 0.1, available: 0.5, time: 1000.0)->check();

        $this->assertTrue($result->limited);
        $this->assertSame(0, $result->remaining);
        $this->assertSame(1015, $result->reset);
    }
}
