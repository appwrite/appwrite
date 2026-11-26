<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Transport;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;
use Utopia\NATS\Transport\SwooleTransport;

#[RequiresPhpExtension('swoole')]
final class SwooleTransportPollTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testPollReportsDataAndCloseWithoutConsumingOrBlocking(): void
    {
        $states = [];

        Coroutine\run(static function () use (&$states): void {
            $server = new Socket(AF_INET, SOCK_STREAM, 0);
            $server->bind('127.0.0.1', 0);
            $server->listen();
            $address = $server->getsockname();
            $port = \is_array($address) && \is_int($address['port'] ?? null) ? $address['port'] : 0;

            $transport = new SwooleTransport();
            $transport->connect('127.0.0.1', $port, 1.0);
            $peer = $server->accept(1.0);
            if (!$peer instanceof Socket) {
                return;
            }
            Coroutine::sleep(0.02);

            $started = microtime(true);
            $states['idle'] = $transport->poll();
            $states['immediate'] = microtime(true) - $started < 0.05;

            $peer->send("PING\r\nPONG\r\n");
            Coroutine::sleep(0.02);
            $states['data'] = $transport->poll();
            $states['line'] = $transport->readLine(1.0);
            $states['buffered'] = $transport->poll();
            $states['rest'] = $transport->readLine(1.0);

            $peer->close();
            Coroutine::sleep(0.02);
            $states['closed'] = $transport->poll();

            $transport->close();
            $server->close();
        });

        $this->assertSame([
            'idle' => false,
            'immediate' => true,
            'data' => true,
            'line' => "PING\r\n",
            'buffered' => true,
            'rest' => "PONG\r\n",
            'closed' => true,
        ], $states);
    }
}
