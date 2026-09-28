<?php

namespace Utopia\Tests\Bench;

use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\OutputMode;
use PhpBench\Attributes\OutputTimeUnit;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;

abstract class Base
{
    protected Abuse $abuse;
    protected TimeLimit $adapter;

    #[BeforeMethods('setUp')]
    #[Iterations([20, 30, 50])]
    #[OutputMode('throughput')]
    #[OutputTimeUnit('millisecond')]
    public function benchTimelimit(): void
    {
        $ip = '';
        for ($i = 0; $i < 4; $i++) {
            $sub = \rand(0, 255);
            $ip .= $sub . '.';
        };
        $ip = \rtrim($ip, '.');
        $this->adapter->setParam('{{ip}}', $ip);
        $this->abuse->check();
    }
}
