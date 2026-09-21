<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Timer;
use Utopia\Queue\Adapter\Swoole;
use Utopia\Queue\Consumer;

final class ShutdownTest extends TestCase
{
    public function testSupervisorBoundsShutdownOfAnUnresponsiveWorker(): void
    {
        $adapter = new Swoole($this->createStub(Consumer::class), 1, shutdownTimeout: 0.1);
        $adapter->workerStart(static function (): void {
            Coroutine::sleep(10);
        });
        Timer::after(100, static fn(): \Utopia\Queue\Adapter\Swoole => $adapter->stop());
        $start = microtime(true);
        $adapter->start();
        $this->assertLessThan(3.0, microtime(true) - $start);
    }
}
