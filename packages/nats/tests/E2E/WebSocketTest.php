<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Transport\WebSocketTransport;

/**
 * End-to-end test for the WebSocket transport against a ws-enabled NATS server
 * (tests/fixtures/nats-ws.conf). The transport is wired in via transportFactory.
 */
final class WebSocketTest extends TestCase
{
    private string $url;

    protected function setUp(): void
    {
        $this->url = getenv('NATS_WS_URL') ?: 'ws://127.0.0.1:14224';

        $host = parse_url($this->url, PHP_URL_HOST) ?: '127.0.0.1';
        $port = parse_url($this->url, PHP_URL_PORT) ?: 14224;
        $probe = @fsockopen($host, (int) $port, $errno, $errstr, 1.0);
        if ($probe === false) {
            $this->markTestSkipped("WebSocket server not reachable at {$this->url}");
        }
        fclose($probe);
    }

    public function testConnectAndRoundTripOverWebSocket(): void
    {
        $conn = Connection::connect(new ConnectionOptions(
            servers: $this->url,
            transportFactory: fn(string $scheme): WebSocketTransport => new WebSocketTransport(secure: $scheme === 'wss'),
        ));

        $this->assertTrue($conn->isConnected());

        $sub = $conn->subscribe('ws.echo');
        $conn->publish('ws.echo', 'hello-over-ws');
        $msg = $sub->nextMessage(2.0);

        $this->assertInstanceOf(\Utopia\NATS\Message::class, $msg);
        $this->assertSame('hello-over-ws', $msg->data);

        $conn->close();
    }

    public function testRequestReplyOverWebSocket(): void
    {
        $conn = Connection::connect(new ConnectionOptions(
            servers: $this->url,
            transportFactory: fn(string $scheme): WebSocketTransport => new WebSocketTransport(secure: $scheme === 'wss'),
        ));

        $conn->subscribe('ws.service', function ($msg) use ($conn): void {
            $conn->publish($msg->replyTo, 'pong:' . $msg->data);
        });

        $reply = $conn->request('ws.service', 'ping', 2.0);
        $this->assertSame('pong:ping', $reply->data);

        $conn->close();
    }
}
