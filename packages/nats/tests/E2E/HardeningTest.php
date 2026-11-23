<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;

/**
 * Integration coverage for the hardening changes against a running NATS server:
 * deterministic drain, tls_available parsing, and dynamic token providers.
 */
final class HardeningTest extends TestCase
{
    private function getServerUrl(): string
    {
        return getenv('NATS_URL') ?: 'nats://127.0.0.1:4222';
    }

    public function testDrainDeliversPendingThenClosesDeterministically(): void
    {
        // A large drainTimeout: the pure-timeout drain would take this long, so a
        // fast completion proves the PING/PONG barrier is doing the work.
        $conn = Connection::connect(new ConnectionOptions(
            servers: $this->getServerUrl(),
            drainTimeout: 20.0,
        ));

        $subject = 'test.drain.' . uniqid();
        $received = 0;
        $conn->subscribe($subject, function () use (&$received): void {
            $received++;
        });

        $count = 25;
        for ($i = 0; $i < $count; $i++) {
            $conn->publish($subject, "msg-{$i}");
        }

        $start = microtime(true);
        $conn->drain();
        $elapsed = microtime(true) - $start;

        $this->assertSame($count, $received, 'all pending messages drained');
        $this->assertTrue($conn->isClosed());
        $this->assertLessThan(10.0, $elapsed, 'drain completed on the PONG barrier, not the timeout');
    }

    public function testServerInfoTlsAvailableParsed(): void
    {
        $conn = Connection::connect($this->getServerUrl());
        $info = $conn->getServerInfo();

        // Plaintext dev server: tls_available is parsed and reflects that TLS is
        // neither available nor required on this connection.
        $this->assertFalse($info->tlsAvailable);
        $this->assertFalse($info->tlsRequired);

        $conn->close();
    }

    public function testTokenProviderInvokedAtConnect(): void
    {
        $calls = 0;
        $conn = Connection::connect(new ConnectionOptions(
            servers: $this->getServerUrl(),
            tokenProvider: function () use (&$calls): string {
                $calls++;
                return 'dynamic-token';
            },
        ));

        $this->assertTrue($conn->isConnected());
        $this->assertSame(1, $calls, 'token provider resolved during the connect handshake');

        $conn->close();
    }
}
