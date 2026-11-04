<?php

namespace Tests\Unit\Presences;

use Appwrite\Presences\BootGate;
use PHPUnit\Framework\TestCase;
use Swoole\Atomic;

final class BootGateTest extends TestCase
{
    public function testOpenGateDoesNotWait(): void
    {
        $gate = new BootGate(new Atomic(1), \microtime(true) + 5);

        $started = \microtime(true);
        $this->assertTrue($gate->wait());
        $this->assertLessThan(0.05, \microtime(true) - $started);
    }

    public function testClosedGateWaitsUntilTheDeadline(): void
    {
        $gate = new BootGate(new Atomic(0), \microtime(true) + 0.2);

        $started = \microtime(true);
        $this->assertFalse($gate->wait());
        $this->assertGreaterThanOrEqual(0.2, \microtime(true) - $started);
    }

    public function testOpeningLetsAWaitPass(): void
    {
        $opened = new Atomic(0);
        $gate = new BootGate($opened, \microtime(true) + 5);

        $this->assertFalse($gate->isOpen());
        $gate->open();

        $this->assertTrue($gate->isOpen());
        $this->assertTrue($gate->wait());
    }

    public function testReadingTheGateKeepsItOpen(): void
    {
        $gate = new BootGate(new Atomic(1), \microtime(true) + 5);

        $gate->wait();
        $gate->wait();

        $this->assertTrue($gate->isOpen());
    }
}
