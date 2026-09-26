<?php

declare(strict_types=1);

namespace Utopia\Tests;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Utopia\Http\Adapter\Swoole\Server;
use Utopia\Telemetry\Adapter\Test as TestTelemetry;

/**
 * The Swoole adapter must only register gauges the calling process can fill.
 * A registered gauge whose callback observes nothing is still exported, as a
 * metric with zero data points, and Prometheus 3.13 and later rejects the
 * entire OTLP request over one of those.
 */
#[RequiresPhpExtension('swoole')]
final class SwooleServerTelemetryTest extends TestCase
{
    /**
     * Server-wide stats are master-tracked and reported by worker 0 alone. This
     * test process is not a worker (`getWorkerId()` is -1), so none of them may
     * be registered here.
     */
    public function testServerWideGaugesAreNotRegisteredOutsideWorkerZero(): void
    {
        $telemetry = new TestTelemetry();
        $server = new Server('127.0.0.1', '0');

        $server->setTelemetry($telemetry);

        $registered = array_keys($telemetry->observableGauges);

        foreach (['swoole.connection.count', 'swoole.request.count', 'swoole.worker.count', 'swoole.reactor.threads'] as $serverWide) {
            $this->assertNotContains($serverWide, $registered);
        }

        // Per-worker and runtime gauges are still registered: every process can
        // report its own coroutine, timer and memory state.
        $this->assertContains('swoole.coroutine.count', $registered);
        $this->assertContains('swoole.memory.usage_bytes', $registered);

        // Re-registering the same adapter is a no-op, so the worker-id gate is
        // not re-evaluated into a different answer. Only one Swoole\Http\Server
        // may exist per process, so this shares the server above.
        $server->setTelemetry($telemetry);

        $this->assertSame($registered, array_keys($telemetry->observableGauges));
    }
}
