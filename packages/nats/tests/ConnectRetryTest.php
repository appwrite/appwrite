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

final class ConnectRetryTest extends TestCase
{
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

    public function testFailedAttemptsLeaveNoTransportOpen(): void
    {
        /** @var list<FakeTransport> $transports */
        $transports = [];
        $factory = static function () use (&$transports): FakeTransport {
            // Sends INFO, then never answers the PING.
            $transport = new FakeTransport();
            $transport->answerPings = false;
            $transports[] = $transport;

            return $transport;
        };

        try {
            Connection::connect(self::options($factory, 2, servers: ['nats://a.cloud:4222', 'nats://b.cloud:4222']));
            $this->fail('connect() must give up when no server answers the PING');
        } catch (ConnectionException $error) {
            $this->assertInstanceOf(TimeoutException::class, $error->getPrevious());
        }

        $this->assertCount(4, $transports, 'two servers, two passes');
        foreach ($transports as $index => $transport) {
            $this->assertFalse($transport->isConnected(), "transport {$index} was left open");
        }
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

    /**
     * @return \Iterator<string, array{float, float, int}>
     */
    public static function pauseOverrunsDeadlineProvider(): \Iterator
    {
        // The first pause already ends past the deadline.
        yield 'wait longer than the window' => [0.1, 1.0, 1];
        // The first pause fits, the second would end past the deadline.
        yield 'second wait overruns' => [0.25, 0.15, 2];
    }

    #[DataProvider('pauseOverrunsDeadlineProvider')]
    public function testNoPassStartsAfterAPauseThatOverrunsTheDeadline(float $connectTimeout, float $wait, int $passes): void
    {
        /** @var list<float> $starts */
        $starts = [];
        $factory = static function () use (&$starts): FakeTransport {
            $starts[] = hrtime(true) / 1e9;
            $transport = new FakeTransport();
            $transport->refuse = self::refusal();

            return $transport;
        };

        $called = hrtime(true) / 1e9;
        try {
            Connection::connect(self::options($factory, 1000, wait: $wait, connectTimeout: $connectTimeout));
            $this->fail('connect() must give up');
        } catch (ConnectionException $error) {
            $this->assertStringContainsString('Connection refused', $error->getPrevious()?->getMessage() ?? '');
        }
        $returned = hrtime(true) / 1e9;

        $this->assertCount($passes, $starts);
        foreach ($starts as $start) {
            $this->assertLessThan($connectTimeout, $start - $called, 'a pass started after connectTimeout');
        }
        $this->assertLessThan($connectTimeout, $returned - $called);
    }

    public function testNoPassStartsOnceConnectTimeoutHasElapsed(): void
    {
        $connects = 0;
        $factory = static function () use (&$connects): FakeTransport {
            $connects++;
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

        // The deadline ends it, not connectAttempts.
        $this->assertGreaterThanOrEqual(2, $connects);
        $this->assertLessThan(20, $connects);
        $this->assertLessThan(0.5, $elapsed);
    }
}
