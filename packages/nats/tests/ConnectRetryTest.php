<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Exception\AuthenticationException;
use Utopia\NATS\Exception\ConnectionException;
use Utopia\NATS\Exception\TimeoutException;
use Utopia\NATS\Exception\TlsException;
use Utopia\NATS\Tests\Support\FakeTransport;

/**
 * connect() behind a load-balanced address. A server in lame-duck mode closes
 * its client listener while its pod stays Ready, so a new connection can land
 * on it and be refused; the next connection to the same address picks a
 * backend afresh. connect() makes another pass over the pool for failures a
 * fresh connection can fix, and only for those.
 */
final class ConnectRetryTest extends TestCase
{
    /**
     * What TcpTransport throws when nothing is listening on the backend the
     * connection was routed to.
     */
    private static function refusal(): ConnectionException
    {
        return new ConnectionException('Failed to connect to tcp://nats.cloud:4222: [111] Connection refused');
    }

    /** @param string|list<string> $servers */
    private static function options(
        \Closure $factory,
        int $attempts,
        float $wait = 0.0,
        string|array $servers = 'nats://nats.cloud:4222',
        float $connectTimeout = 2.0,
    ): ConnectionOptions {
        return new ConnectionOptions(
            servers: $servers,
            connectTimeout: $connectTimeout,
            noRandomize: true,
            transportFactory: $factory,
            connectAttempts: $attempts,
            connectRetryWait: $wait,
            connectRetryJitter: 0.0,
        );
    }

    public function testRefusedConnectIsRetriedUntilABackendAccepts(): void
    {
        $connects = 0;
        $accepted = new FakeTransport();
        $factory = static function () use (&$connects, $accepted): FakeTransport {
            if (++$connects <= 2) {
                $refused = new FakeTransport();
                $refused->refuse = self::refusal();

                return $refused;
            }

            return $accepted;
        };

        $conn = Connection::connect(self::options($factory, 3));

        $this->assertTrue($conn->isConnected());
        $this->assertSame(3, $connects, 'two refusals and the connect that got through');

        // The connection that came back is the one the third connect built.
        $conn->publish('jobs', 'payload');
        $this->assertStringContainsString("PUB jobs 7\r\npayload\r\n", $accepted->written);
    }

    public function testEveryPassWalksTheWholePool(): void
    {
        /** @var list<FakeTransport> $transports */
        $transports = [];
        $factory = static function () use (&$transports): FakeTransport {
            $transport = new FakeTransport();
            if (\count($transports) < 2) {
                $transport->refuse = self::refusal();
            }
            $transports[] = $transport;

            return $transport;
        };

        $conn = Connection::connect(self::options($factory, 2, servers: ['nats://a.cloud:4222', 'nats://b.cloud:4222']));

        // Pass one: a refused, b refused. Pass two starts over at a, which accepts.
        $this->assertTrue($conn->isConnected());
        $this->assertSame(
            ['a.cloud', 'b.cloud', 'a.cloud'],
            array_map(static fn (FakeTransport $transport): string => $transport->host, $transports),
        );
    }

    /**
     * @return \Iterator<string, array{int}>
     */
    public static function attemptsProvider(): \Iterator
    {
        yield 'single pass' => [1];
        yield 'default' => [3];
        yield 'five' => [5];
    }

    #[DataProvider('attemptsProvider')]
    public function testExhaustedAttemptsThrowConnectionException(int $attempts): void
    {
        $connects = 0;
        $factory = static function () use (&$connects): FakeTransport {
            $connects++;
            $transport = new FakeTransport();
            $transport->refuse = self::refusal();

            return $transport;
        };

        try {
            Connection::connect(self::options($factory, $attempts));
            $this->fail('connect() must give up once every attempt is refused');
        } catch (ConnectionException $error) {
            $this->assertSame('Failed to connect to any NATS server', $error->getMessage());
            $this->assertInstanceOf(ConnectionException::class, $error->getPrevious());
            $this->assertStringContainsString('Connection refused', $error->getPrevious()->getMessage());
        }

        $this->assertSame($attempts, $connects);
    }

    /**
     * -ERR replies a server sends to CONNECT that the next connection would
     * get back identically, so another pass would only repeat it.
     *
     * @return \Iterator<string, array{string, class-string<\Throwable>}>
     */
    public static function rejectionProvider(): \Iterator
    {
        yield 'authorization violation' => ["-ERR 'Authorization Violation'\r\n", AuthenticationException::class];
        yield 'authentication timeout' => ["-ERR 'Authentication Timeout'\r\n", AuthenticationException::class];
        yield 'tls required' => ["-ERR 'Secure Connection - TLS Required'\r\n", ConnectionException::class];
        yield 'connection ceiling' => ["-ERR 'Maximum Connections Exceeded'\r\n", ConnectionException::class];
    }

    /** @param class-string<\Throwable> $cause */
    #[DataProvider('rejectionProvider')]
    public function testServerRejectionIsNotRetried(string $reply, string $cause): void
    {
        $connects = 0;
        $factory = static function () use (&$connects, $reply): FakeTransport {
            $connects++;
            $transport = new FakeTransport();
            // A real server answers the CONNECT with the -ERR and closes, so
            // the PING behind it never gets its PONG.
            $transport->answerPings = false;
            $transport->onWrite = static function (string $data, FakeTransport $fake) use ($reply): void {
                if (str_starts_with($data, 'CONNECT ')) {
                    $fake->pushInbound($reply);
                }
            };

            return $transport;
        };

        try {
            Connection::connect(self::options($factory, 3));
            $this->fail('connect() must fail when the server rejects the CONNECT');
        } catch (ConnectionException $error) {
            $this->assertInstanceOf($cause, $error->getPrevious());
        }

        $this->assertSame(1, $connects);
    }

    public function testTlsHandshakeFailureIsNotRetried(): void
    {
        $connects = 0;
        $factory = static function () use (&$connects): FakeTransport {
            $connects++;
            $transport = new FakeTransport();
            $transport->refuse = new TlsException('TLS handshake with nats.cloud:4222 failed: certificate verify failed');

            return $transport;
        };

        try {
            Connection::connect(self::options($factory, 3));
            $this->fail('connect() must fail when the handshake does');
        } catch (ConnectionException $error) {
            $this->assertInstanceOf(TlsException::class, $error->getPrevious());
        }

        $this->assertSame(1, $connects);
    }

    public function testPassesArePausedBetween(): void
    {
        $connects = [];
        $factory = static function () use (&$connects): FakeTransport {
            $connects[] = hrtime(true) / 1e9;
            $transport = new FakeTransport();
            $transport->refuse = self::refusal();

            return $transport;
        };

        try {
            Connection::connect(self::options($factory, 3, wait: 0.05));
        } catch (ConnectionException) {
        }

        $this->assertCount(3, $connects);
        $this->assertGreaterThanOrEqual(0.05, $connects[1] - $connects[0]);
        $this->assertGreaterThanOrEqual(0.05, $connects[2] - $connects[1]);
    }

    public function testNoPassStartsOnceConnectTimeoutHasElapsed(): void
    {
        $connects = 0;
        $factory = static function () use (&$connects): FakeTransport {
            $connects++;
            // A backend that takes a while to fail, as a timing-out one does.
            usleep(20_000);
            $transport = new FakeTransport();
            $transport->refuse = new TimeoutException('Failed to connect to tcp://nats.cloud:4222: [110] Connection timed out');

            return $transport;
        };

        $started = hrtime(true) / 1e9;
        try {
            Connection::connect(self::options($factory, 1000, connectTimeout: 0.1));
            $this->fail('connect() must give up');
        } catch (ConnectionException) {
        }
        $elapsed = hrtime(true) / 1e9 - $started;

        // Timeouts are retried while inside the window, but the window, not
        // connectAttempts, is what ends it: about five 20ms passes, not 1000.
        $this->assertGreaterThanOrEqual(2, $connects);
        $this->assertLessThan(20, $connects);
        $this->assertLessThan(0.5, $elapsed);
    }
}
