<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Exception\MaxPayloadException;
use Utopia\NATS\Exception\ProtocolException;
use Utopia\NATS\Headers;
use Utopia\NATS\Tests\Unit\Support\FakeTransport;

/**
 * Protocol-level behaviour driven through an in-memory transport: header
 * negotiation/gating, the max-payload accounting, and tls_available parsing.
 */
final class ConnectionProtocolTest extends TestCase
{
    private function connect(FakeTransport $fake, array $extra = []): Connection
    {
        $args = array_merge([
            'servers' => 'nats://127.0.0.1:4222',
            'transportFactory' => fn(string $scheme): FakeTransport => $fake,
        ], $extra);

        return Connection::connect(new ConnectionOptions(...$args));
    }

    public function testConnectNegotiatesHeadersWhenSupported(): void
    {
        $fake = new FakeTransport(['headers' => true]);
        $conn = $this->connect($fake);

        $payload = $fake->connectPayload();
        $this->assertTrue($payload['headers']);
        $this->assertTrue($payload['no_responders']);

        $conn->close();
    }

    public function testConnectDisablesHeadersWhenNotSupported(): void
    {
        $fake = new FakeTransport(['headers' => false]);
        $conn = $this->connect($fake);

        $payload = $fake->connectPayload();
        $this->assertFalse($payload['headers']);
        $this->assertFalse($payload['no_responders']);

        $conn->close();
    }

    public function testPublishWithHeadersUsesHpub(): void
    {
        $fake = new FakeTransport(['headers' => true]);
        $conn = $this->connect($fake);

        $headers = new Headers();
        $headers->set('X-Key', 'value');
        $conn->publish('subj', 'hello', null, $headers);

        $this->assertStringContainsString('HPUB subj', $fake->written);

        $conn->close();
    }

    public function testPublishWithHeadersRejectedWhenServerLacksSupport(): void
    {
        $fake = new FakeTransport(['headers' => false]);
        $conn = $this->connect($fake);

        $headers = new Headers();
        $headers->set('X-Key', 'value');

        $this->expectException(ProtocolException::class);
        try {
            $conn->publish('subj', 'hello', null, $headers);
        } finally {
            $conn->close();
        }
    }

    public function testMaxPayloadIncludesHeaderBytes(): void
    {
        $fake = new FakeTransport(['headers' => true, 'max_payload' => 40]);
        $conn = $this->connect($fake);

        $headers = new Headers();
        $headers->set('X-Long-Header', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

        // Body alone (5 bytes) is well under 40, but header block + body exceeds it.
        $this->assertGreaterThan(40, \strlen($headers->toWire()) + 5);

        $this->expectException(MaxPayloadException::class);
        try {
            $conn->publish('subj', 'hello', null, $headers);
        } finally {
            $conn->close();
        }
    }

    public function testTlsAvailableIsParsed(): void
    {
        $fake = new FakeTransport(['tls_available' => true, 'tls_required' => false]);
        $conn = $this->connect($fake);

        $this->assertTrue($conn->getServerInfo()->tlsAvailable);
        $this->assertFalse($conn->getServerInfo()->tlsRequired);

        $conn->close();
    }
}
