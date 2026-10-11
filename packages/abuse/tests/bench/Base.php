<?php

namespace Utopia\Abuse\Tests\Bench;

use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\OutputMode;
use PhpBench\Attributes\OutputTimeUnit;
use Utopia\Abuse\Adapter\TimeLimit;

abstract class Base
{
    protected TimeLimit $adapter;

    abstract public function setUp(): void;

    #[BeforeMethods('setUp')]
    #[Iterations([20, 30, 50])]
    #[OutputMode('throughput')]
    #[OutputTimeUnit('millisecond')]
    public function benchTimelimit(): void
    {
        $octets = [];
        for ($i = 0; $i < 4; $i++) {
            $octets[] = \random_int(0, 255);
        }
        $this->adapter->withParams(['{{ip}}' => \implode('.', $octets)])->check();
    }
}
