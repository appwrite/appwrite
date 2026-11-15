<?php

declare(strict_types=1);

namespace Utopia\Cache\Tests;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Server;
use Swoole\Coroutine\Server\Connection;
use Utopia\Cache\Adapter\Redis\ConnectionException;
use Utopia\Cache\Adapter\Redis\Multiplexing as RedisMultiplexing;

use function Swoole\Coroutine\run;

/**
 * The AUTH/SELECT handshake is part of connecting, so it is bounded by the
 * connect timeout, not by the per-call read deadline.
 *
 * A worker that has just started and takes a burst of jobs builds its cache
 * adapter while its event loop is busy, so the handshake reply is read a few
 * hundred milliseconds late. Held to the 0.25s read deadline, the constructor
 * threw "Timed out waiting for Redis response" against a healthy server.
 *
 * The server here is an in-process stand-in that answers each handshake
 * command only after a delay, which reproduces that late read without Docker.
 */
#[RequiresPhpExtension('swoole')]
final class MultiplexingHandshakeTest extends TestCase
{
    /**
     * @param  callable(int): void  $client  receives the port the stand-in listens on
     * @return list<string> the commands the stand-in received, in order
     */
    private function withSlowServer(float $replyDelay, callable $client): array
    {
        $received = [];
        $error = null;

        run(function () use ($replyDelay, $client, &$received, &$error): void {
            $server = new Server('127.0.0.1', 0);
            $port = $server->port;

            $server->handle(function (Connection $connection) use ($replyDelay, &$received): void {
                $buffer = '';
                while (true) {
                    $chunk = $connection->recv(2.0);
                    if ($chunk === '' || $chunk === false) {
                        return;
                    }
                    $buffer .= $chunk;

                    // Each handshake command arrives as one RESP array; answer per array.
                    while (preg_match('/^\*(\d+)\r\n/', $buffer, $m) === 1) {
                        $parts = [];
                        $offset = \strlen($m[0]);
                        for ($i = 0; $i < (int) $m[1]; $i++) {
                            if (preg_match('/\G\$(\d+)\r\n/', $buffer, $len, 0, $offset) !== 1) {
                                continue 3;
                            }
                            $offset += \strlen($len[0]);
                            if (\strlen($buffer) < $offset + (int) $len[1] + 2) {
                                continue 3;
                            }
                            $parts[] = substr($buffer, $offset, (int) $len[1]);
                            $offset += (int) $len[1] + 2;
                        }
                        $buffer = substr($buffer, $offset);
                        $received[] = strtoupper($parts[0] ?? '');

                        Coroutine::sleep($replyDelay);
                        $connection->send("+OK\r\n");
                    }
                }
            });

            Coroutine::create(fn () => $server->start());

            try {
                $client($port);
            } catch (\Throwable $th) {
                $error = $th;
            } finally {
                $server->shutdown();
            }
        });

        if ($error instanceof \Throwable) {
            throw $error;
        }

        return $received;
    }

    public function testAHandshakeReplySlowerThanTheReadDeadlineStillConnects(): void
    {
        $adapter = null;

        $received = $this->withSlowServer(0.4, function (int $port) use (&$adapter): void {
            $adapter = new RedisMultiplexing(
                '127.0.0.1',
                $port,
                timeout: 1.0,
                readTimeout: 0.25,
                auth: 'secret',
                dbIndex: 2,
            );
            $adapter->disconnect();
        });

        $this->assertInstanceOf(RedisMultiplexing::class, $adapter, 'The adapter must be built: the server answered within the connect timeout');
        $this->assertSame(['AUTH', 'SELECT'], $received, 'Both handshake commands must have been sent and answered');
    }

    public function testAHandshakeThatOutlastsTheConnectTimeoutStillFails(): void
    {
        $failure = null;

        $this->withSlowServer(0.6, function (int $port) use (&$failure): void {
            try {
                new RedisMultiplexing('127.0.0.1', $port, timeout: 0.3, readTimeout: 0.25, auth: 'secret');
            } catch (ConnectionException $exception) {
                $failure = $exception;
            }
        });

        $this->assertInstanceOf(ConnectionException::class, $failure, 'A server that does not answer within the connect timeout is still a failed connection');
    }
}
