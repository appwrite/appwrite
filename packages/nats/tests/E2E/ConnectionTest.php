<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;

/**
 * Integration tests require a running NATS server at localhost:4222.
 *
 * Start one with: nats-server
 */
final class ConnectionTest extends TestCase
{
    private function getServerUrl(): string
    {
        return getenv('NATS_URL') ?: 'nats://127.0.0.1:4222';
    }

    public function testConnect(): void
    {
        $conn = Connection::connect($this->getServerUrl());
        $this->assertTrue($conn->isConnected());
        $this->assertFalse($conn->isClosed());

        $info = $conn->getServerInfo();
        $this->assertNotEmpty($info->serverId);
        $this->assertNotEmpty($info->version);

        $conn->close();
        $this->assertTrue($conn->isClosed());
        $this->assertFalse($conn->isConnected());
    }

    public function testConnectWithOptions(): void
    {
        $conn = Connection::connect(new ConnectionOptions(
            servers: $this->getServerUrl(),
            name: 'test-client',
            verbose: false,
        ));

        $this->assertTrue($conn->isConnected());
        $conn->close();
    }

    public function testFlush(): void
    {
        $conn = Connection::connect($this->getServerUrl());
        $conn->flush();
        $this->assertTrue($conn->isConnected());
        $conn->close();
    }
}
