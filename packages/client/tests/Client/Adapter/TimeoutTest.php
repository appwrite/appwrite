<?php

declare(strict_types=1);

namespace Utopia\Tests\Client\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Client\Adapter\Curl\Client as CurlClient;
use Utopia\Client\Adapter\SwooleCoroutine\Client as SwooleClient;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use ValueError;

final class TimeoutTest extends TestCase
{
    public function testCurlTimeoutsRejectInvalidValues(): void
    {
        $adapter = new CurlClient(new Response\Factory(), new Stream\Factory());

        $this->expectException(ValueError::class);

        $adapter->withTimeout(\INF);
    }

    public function testSwooleTimeoutsRejectInvalidValues(): void
    {
        $adapter = new SwooleClient(new Response\Factory(), new Stream\Factory());

        $this->expectException(ValueError::class);

        $adapter->withConnectTimeout(-0.001);
    }
}
