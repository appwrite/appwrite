<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Socket;
use Swoole\Runtime;
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Exception\TimeoutException;
use Utopia\NATS\Protocol\Parser;
use Utopia\NATS\Tests\Support\Peer;
use Utopia\NATS\Tests\Support\Wait;
use Utopia\NATS\Transport\SwooleTransport;
use Utopia\NATS\Transport\TcpTransport;
use Utopia\NATS\Transport\TlsTransport;
use Utopia\NATS\Transport\Transport;

/**
 * Under SWOOLE_HOOK_ALL a stream read that times out can return '' instead of
 * false. Every read timeout must still hold there: each operation here runs in
 * its own coroutine and is closed down if it outlives LIMIT, so a read that
 * never times out fails the test instead of hanging it.
 */
#[RequiresPhpExtension('swoole')]
final class HookedTimeoutTest extends TestCase
{
    private const float LIMIT = 2.0;

    private const string CERTIFICATES = __DIR__ . '/fixtures/certs';

    private const string INFO = 'INFO {"server_id":"TEST","version":"2.10.0","proto":1,"headers":true,"max_payload":1048576}' . "\r\n";

    #[RunInSeparateProcess]
    public function testTcpReadAfterALineTimesOut(): void
    {
        $outcome = $this->hooked(function (): array {
            [$transport, $peer] = $this->tcp();
            fwrite($peer, "INFO {}\r\n");
            $transport->readLine(1.0);

            return $this->timed(static fn () => $transport->read(64, 0.1), $transport);
        });

        $this->assertTimedOutWithin(0.2, $outcome);
    }

    #[RunInSeparateProcess]
    public function testTlsReadAfterALineTimesOut(): void
    {
        $outcome = $this->hooked(function (): array {
            $peer = new Peer(certificates: self::CERTIFICATES);
            $transport = new TlsTransport(['cafile' => self::CERTIFICATES . '/ca.pem', 'peer_name' => 'localhost']);
            $transport->connect('127.0.0.1', $peer->port, 5.0);
            $peer->accept();
            $peer->send("INFO {}\r\n");
            $transport->readLine(1.0);

            try {
                return $this->timed(static fn () => $transport->read(64, 0.1), $transport);
            } finally {
                $peer->stop();
            }
        });

        $this->assertTimedOutWithin(0.2, $outcome);
    }

    #[RunInSeparateProcess]
    public function testSwooleTransportReadTimesOut(): void
    {
        $outcome = $this->hooked(function (): array {
            $server = new Socket(AF_INET, SOCK_STREAM, 0);
            $server->bind('127.0.0.1', 0);
            $server->listen();
            $address = $server->getsockname();
            $transport = new SwooleTransport();
            $transport->connect('127.0.0.1', \is_array($address) && \is_int($address['port'] ?? null) ? $address['port'] : 0, 1.0);
            $peer = $server->accept(1.0);
            $this->assertInstanceOf(Socket::class, $peer);

            return $this->timed(static fn () => $transport->read(64, 0.1), $transport);
        });

        $this->assertTimedOutWithin(0.2, $outcome);
    }

    #[RunInSeparateProcess]
    public function testParserTimesOutOnAPartialLine(): void
    {
        $outcome = $this->hooked(function (): array {
            [$transport, $peer] = $this->tcp();
            fwrite($peer, "INFO {}\r\nPI");
            $parser = new Parser($transport);
            $parser->next(1.0);

            return $this->timed(static fn () => $parser->next(0.1), $transport);
        });

        $this->assertTimedOutWithin(0.2, $outcome);
    }

    #[RunInSeparateProcess]
    public function testSettleWithAPartialLineReturnsWithinItsTimeout(): void
    {
        $outcome = $this->hooked(function (): array {
            [$connection, $transport, $send] = $this->connection();
            $send->push('PI');
            $this->assertTrue(Wait::until($transport->poll(...)), 'The partial line must reach the socket');

            try {
                return $this->timed(static fn () => $connection->settle(0.3), $transport);
            } finally {
                $send->close();
                $connection->close();
            }
        });

        $this->assertNotNull($outcome['elapsed'], 'settle() never returned');
        $this->assertNull($outcome['error']);
        $this->assertLessThan(0.4, $outcome['elapsed']);
    }

    #[RunInSeparateProcess]
    public function testProcessMessageOnAnIdleConnectionReturnsWithinItsTimeout(): void
    {
        $outcome = $this->hooked(function (): array {
            [$connection, $transport, $send] = $this->connection();

            try {
                return $this->timed(static fn () => $connection->processMessage(0.1), $transport);
            } finally {
                $send->close();
                $connection->close();
            }
        });

        $this->assertNotNull($outcome['elapsed'], 'processMessage() never returned');
        $this->assertNull($outcome['error']);
        $this->assertLessThan(0.3, $outcome['elapsed']);
    }

    /** @param array{elapsed: ?float, error: ?\Throwable} $outcome */
    private function assertTimedOutWithin(float $limit, array $outcome): void
    {
        $this->assertNotNull($outcome['elapsed'], 'The read never timed out');
        $this->assertInstanceOf(TimeoutException::class, $outcome['error']);
        $this->assertLessThan($limit, $outcome['elapsed']);
    }

    /**
     * @param \Closure(): array{elapsed: ?float, error: ?\Throwable} $test
     * @return array{elapsed: ?float, error: ?\Throwable}
     */
    private function hooked(\Closure $test): array
    {
        Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        $outcome = ['elapsed' => null, 'error' => null];
        Coroutine\run(static function () use ($test, &$outcome): void {
            $outcome = $test();
        });

        return $outcome;
    }

    /**
     * Run $operation in its own coroutine. One still running after LIMIT has its
     * transport closed, which ends any read loop, and reports no elapsed time.
     *
     * @return array{elapsed: ?float, error: ?\Throwable}
     */
    private function timed(\Closure $operation, Transport $transport): array
    {
        $channel = new Channel(1);
        Coroutine::create(static function () use ($operation, $channel): void {
            $started = hrtime(true);
            $error = null;
            try {
                $operation();
            } catch (\Throwable $caught) {
                $error = $caught;
            }
            $channel->push(['elapsed' => (hrtime(true) - $started) / 1e9, 'error' => $error]);
        });

        $outcome = $channel->pop(self::LIMIT);
        if (\is_array($outcome)) {
            /** @var array{elapsed: float, error: ?\Throwable} $outcome */
            return $outcome;
        }

        $transport->close();
        $channel->pop(self::LIMIT);

        return ['elapsed' => null, 'error' => null];
    }

    /** @return array{TcpTransport, resource} */
    private function tcp(): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($server);
        $address = (string) stream_socket_get_name($server, false);

        $transport = new TcpTransport();
        $transport->connect('127.0.0.1', (int) substr($address, (int) strrpos($address, ':') + 1), 1.0);
        $peer = stream_socket_accept($server, 1.0);
        $this->assertIsResource($peer);

        return [$transport, $peer];
    }

    /**
     * A connection to a minimal server that completes the handshake, then
     * writes whatever is pushed onto the returned channel.
     *
     * @return array{Connection, TcpTransport, Channel}
     */
    private function connection(): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($server);
        $address = (string) stream_socket_get_name($server, false);

        $send = new Channel(1);
        Coroutine::create(static function () use ($server, $send): void {
            $peer = stream_socket_accept($server, 1.0);
            if ($peer === false) {
                return;
            }
            fwrite($peer, self::INFO);
            while (($line = fgets($peer)) !== false && !str_starts_with($line, 'PING')) {
            }
            fwrite($peer, "PONG\r\n");
            $after = $send->pop();
            if (\is_string($after)) {
                fwrite($peer, $after);
            }
            while (!feof($peer)) {
                fread($peer, 65536);
            }
        });

        $transport = new TcpTransport();
        $connection = Connection::connect(new ConnectionOptions(
            servers: 'nats://' . $address,
            allowReconnect: false,
            transportFactory: static fn (): TcpTransport => $transport,
        ));

        return [$connection, $transport, $send];
    }
}
