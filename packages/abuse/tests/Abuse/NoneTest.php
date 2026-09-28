<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit\None;

class NoneTest extends TestCase
{
    public function testNeverLimitsRequests(): void
    {
        $adapter = new None('none-key', 1, 60);
        $abuse = new Abuse($adapter);

        $this->assertSame(false, $abuse->check());
        $this->assertSame(false, $abuse->check());
        $this->assertSame(false, $abuse->check());
    }

    public function testReturnsNoLogsAndCleanupSucceeds(): void
    {
        $adapter = new None('none-key', 1, 60);

        $this->assertSame([], $adapter->getLogs());
        $this->assertSame(true, $adapter->cleanup(time()));
    }

    public function testResetIsNoop(): void
    {
        $adapter = new None('none-key', 1, 60);
        $abuse = new Abuse($adapter);

        $abuse->reset();

        $this->assertSame(false, $abuse->check());
    }
}
