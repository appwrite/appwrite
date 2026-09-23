<?php

declare(strict_types=1);

namespace Utopia\WebSocket\Tests;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client as HttpClient;

use function Swoole\Coroutine\run;

use Swoole\Coroutine\Socket;
use Swoole\WebSocket\Server as NativeServer;
use Utopia\WebSocket\Client;

final class AdapterTest extends TestCase
{
    private function getWebsocket(string $host, int $port): Client
    {
        return new Client('ws://' . $host . ':' . $port, [
            'timeout' => 10,
        ]);
    }

    public function testSwoole(): void
    {
        $this->testServer('127.0.0.1', 18081);
    }

    public function testWorkerman(): void
    {
        $this->testServer('127.0.0.1', 18082);
    }

    public function testSwooleTimesOutStalledSends(): void
    {
        $this->testPendingSends(false);
    }

    public function testSwooleReleasesSendsAfterPeerClose(): void
    {
        $this->testPendingSends(true);
    }

    public function testSwooleRecoversFromBriefStall(): void
    {
        run(function (): void {
            $client = $this->getWebsocket('127.0.0.1', 18081);
            $http = new HttpClient('127.0.0.1', 18081);
            $http->set(['timeout' => 2]);

            try {
                $client->connect();
                $baseline = $this->getInfo($http);
                $messages = $this->createMessages();
                $this->sendBatch($client, $messages);
                $this->waitForBatch($http, $baseline['batches_sent'] + 1);

                // Resume reading before the send timeout. Every frame must arrive.
                foreach ($messages as $message) {
                    $this->assertSame($message, $client->receive());
                }
                $client->send('ping');
                $this->assertSame('pong', $client->receive());
            } finally {
                $client->close();
                $http->close();
            }
        });
    }

    private function testPendingSends(bool $closePeer): void
    {
        run(function () use ($closePeer): void {
            $slow = $this->connectSlowClient();
            $healthy = $this->getWebsocket('127.0.0.1', 18081);
            $replacement = null;
            $http = new HttpClient('127.0.0.1', 18081);
            $http->set(['timeout' => 2]);

            try {
                $healthy->connect();
                $baseline = $this->getInfo($http);
                $this->sendBatch($slow, $this->createMessages());
                $this->waitForBatch($http, $baseline['batches_sent'] + 1);

                if ($closePeer) {
                    // Close with unread frames and connect another client before
                    // the old sends time out. Its session must stay usable.
                    $slow->close();
                    $replacement = $this->getWebsocket('127.0.0.1', 18081);
                    $replacement->connect();
                    $replacement->send('ping');
                    $this->assertSame('pong', $replacement->receive());
                } else {
                    // Probe for a transport error without draining the output. A
                    // graceful close keeps the socket alive behind queued frames.
                    $ping = NativeServer::pack('ping', WEBSOCKET_OPCODE_TEXT, SWOOLE_WEBSOCKET_FLAG_FIN | SWOOLE_WEBSOCKET_FLAG_MASK);
                    $deadline = microtime(true) + 6;
                    do {
                        Coroutine::sleep(0.05);
                        if ($slow->sendAll($ping, 0.2) === false) {
                            $socketError = $slow->errCode;
                            break;
                        }
                        $socketError = $slow->getOption(SOL_SOCKET, SO_ERROR);
                    } while ($socketError === 0 && microtime(true) < $deadline);
                    $this->assertContains($socketError, [SOCKET_ECONNRESET, SOCKET_EPIPE], 'The stalled peer must observe disconnection');
                }

                // The regression is retained payload memory, not a particular
                // coroutine count. Allow 8 MiB for runtime/bookkeeping variation;
                // the burst contains roughly 19 MiB of distinct payloads alone.
                $budget = $baseline['memory_used'] + 8 * 1024 * 1024;
                $deadline = microtime(true) + 6;
                do {
                    Coroutine::sleep(0.05);
                    $info = $this->getInfo($http);
                } while ($info['memory_used'] > $budget && microtime(true) < $deadline);
                $this->assertLessThanOrEqual($budget, $info['memory_used'], 'Disconnected clients must release retained payloads');

                $healthy->send('ping');
                $this->assertSame('pong', $healthy->receive());
                if ($replacement instanceof \Utopia\WebSocket\Client) {
                    $replacement->send('ping');
                    $this->assertSame('pong', $replacement->receive());
                }
            } finally {
                $slow->close();
                $healthy->close();
                $replacement?->close();
                $http->close();
            }
        });
    }

    /** @return list<string> */
    private function createMessages(): array
    {
        $messages = [];
        for ($i = 0; $i < 300; $i++) {
            $messages[] = $i . ':' . str_repeat('x', 65536);
        }

        return $messages;
    }

    /** @param list<string> $messages */
    private function sendBatch(Client|Socket $client, array $messages): void
    {
        foreach ($messages as $message) {
            $this->sendMessage($client, 'buffer:' . $message);
        }
        $this->sendMessage($client, 'flush');
    }

    private function sendMessage(Client|Socket $client, string $message): void
    {
        if ($client instanceof Client) {
            $client->send($message);

            return;
        }

        $frame = NativeServer::pack($message, WEBSOCKET_OPCODE_TEXT, SWOOLE_WEBSOCKET_FLAG_FIN | SWOOLE_WEBSOCKET_FLAG_MASK);
        $this->assertSame(\strlen($frame), $client->sendAll($frame));
    }

    private function connectSlowClient(): Socket
    {
        $socket = new Socket(AF_INET, SOCK_STREAM, IPPROTO_IP);
        $this->assertTrue($socket->setOption(SOL_SOCKET, SO_RCVBUF, 1024));
        $this->assertTrue($socket->connect('127.0.0.1', 18081, 2));
        $request = "GET / HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\n"
            . "Connection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n";
        $this->assertSame(\strlen($request), $socket->sendAll($request));
        $headers = '';
        while (!str_contains($headers, "\r\n\r\n")) {
            $chunk = $socket->recv(4096, 2);
            $this->assertNotFalse($chunk);
            $this->assertNotSame('', $chunk);
            $headers .= $chunk;
        }
        $this->assertStringContainsString('101 Switching Protocols', $headers);

        return $socket;
    }

    private function waitForBatch(HttpClient $http, int $expected): void
    {
        $deadline = microtime(true) + 2;
        do {
            Coroutine::sleep(0.01);
            $info = $this->getInfo($http);
        } while ($info['batches_sent'] < $expected && microtime(true) < $deadline);

        $this->assertSame($expected, $info['batches_sent'], 'The fixture must finish submitting the burst');
    }

    /**
     * @return array{memory_used: int, batches_sent: int}
     */
    private function getInfo(HttpClient $http): array
    {
        $this->assertTrue($http->get('/info'));

        return json_decode($http->body, true, flags: JSON_THROW_ON_ERROR);
    }

    private function testServer(string $host, int $port): void
    {
        run(function () use ($host, $port): void {
            $client = $this->getWebsocket($host, $port);
            $client->connect();

            $client->send('ping');
            $this->assertSame('pong', $client->receive());
            $this->assertEquals(true, $client->isConnected());

            $clientA = $this->getWebsocket($host, $port);
            $clientA->connect();
            $clientB = $this->getWebsocket($host, $port);
            $clientB->connect();

            $clientA->send('ping');
            $this->assertSame('pong', $clientA->receive());
            $clientB->send('pong');
            $this->assertSame('ping', $clientB->receive());

            $clientA->send('broadcast');
            $this->assertSame('broadcast', $client->receive());
            $this->assertSame('broadcast', $clientA->receive());
            $this->assertSame('broadcast', $clientB->receive());

            $clientB->send('broadcast');
            $this->assertSame('broadcast', $client->receive());
            $this->assertSame('broadcast', $clientA->receive());
            $this->assertSame('broadcast', $clientB->receive());

            $clientA->close();
            $clientB->close();

            $client->send('disconnect');
            $this->assertSame('disconnect', $client->receive());

            $client->close();
            $this->assertFalse($client->isConnected());
        });
    }
}
