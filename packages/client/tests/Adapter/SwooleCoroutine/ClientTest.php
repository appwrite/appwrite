<?php

declare(strict_types=1);

namespace Utopia\Client\Tests\Adapter\SwooleCoroutine;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Swoole\Coroutine;
use Throwable;
use Utopia\Client\Adapter;
use Utopia\Client\Adapter\SwooleCoroutine\Client;
use Utopia\Client\Exception\AdapterPreconditionException;
use Utopia\Client\Exception\NetworkException;
use Utopia\Client\Exception\TlsException;
use Utopia\Client\Tests\Adapter\AdapterContract;
use Utopia\Client\Tests\Server\Http;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

final class ClientTest extends AdapterContract
{
    /**
     * @param array<string, mixed> $transportOptions
     */
    protected function newAdapter(array $transportOptions = []): Adapter
    {
        return new Client(new Response\Factory(), new Stream\Factory(), $transportOptions);
    }

    protected function runAdapter(callable $callback): void
    {
        $failure = null;
        Coroutine\run(static function () use ($callback, &$failure): void {
            try {
                $callback();
            } catch (Throwable $throwable) {
                $failure = $throwable;
            }
        });
        if ($failure instanceof Throwable) {
            throw $failure;
        }
    }

    /**
     * Swoole opens an IPv4 socket for a hostname, so a name with only an AAAA record
     * needs the adapter to find its IPv6 address. A separate process, because the
     * resolver Swoole asks is a process-wide setting.
     */
    #[RunInSeparateProcess]
    public function testItConnectsToAHostnameWithOnlyAnIpv6Address(): void
    {
        $status = null;
        $body = null;
        $queries = [];

        $this->runAdapter(function () use (&$status, &$body, &$queries): void {
            // A resolver that knows one name, and only its AAAA record
            $resolver = new Coroutine\Socket(AF_INET, SOCK_DGRAM, 0);
            $resolver->bind('127.0.0.1', 0);

            Coroutine::create(static function () use ($resolver, &$queries): void {
                while (true) {
                    $peer = null;
                    $query = $resolver->recvfrom($peer, 5.0);
                    if (!\is_string($query) || \strlen($query) < 17 || !\is_array($peer)) {
                        return;
                    }

                    $offset = 12;
                    $labels = [];
                    while (($length = \ord($query[$offset])) !== 0) {
                        $labels[] = \substr($query, $offset + 1, $length);
                        $offset += $length + 1;
                    }

                    $name = \implode('.', $labels);
                    $unpacked = \unpack('n', \substr($query, $offset + 1, 2));
                    $type = \is_array($unpacked) && \is_int($unpacked[1] ?? null) ? $unpacked[1] : 0;
                    $queries[] = $name . '/' . $type;

                    $known = $name === 'v6only.test';
                    // A compressed-name AAAA record for ::1
                    $answer = $known && $type === 28 ? "\xc0\x0c\x00\x1c\x00\x01\x00\x00\x00\x3c\x00\x10" . \inet_pton('::1') : '';
                    $response = \substr($query, 0, 2)
                        . ($known ? "\x81\x80" : "\x81\x83")
                        . "\x00\x01" . \pack('n', $answer === '' ? 0 : 1) . "\x00\x00\x00\x00"
                        . \substr($query, 12, $offset + 5 - 12)
                        . $answer;

                    $resolver->sendto($peer['address'], $peer['port'], $response);
                }
            });

            $bound = $resolver->getsockname();
            $port = \is_array($bound) && \is_int($bound['port'] ?? null) ? $bound['port'] : 0;
            Coroutine::set(['dns_server' => '127.0.0.1:' . $port]);

            $server = new Coroutine\Server('::1', 0, false, true);
            $server->handle(static function (Coroutine\Server\Connection $connection): void {
                $connection->recv(1.0);
                $connection->send("HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
                $connection->close();
            });
            Coroutine::create(static fn (): bool => $server->start());
            Coroutine::sleep(0.05);

            try {
                $response = $this->createAdapter()->sendRequest(
                    new Request\Factory()->createRequest(Method::GET, 'http://v6only.test:' . $server->port . '/'),
                );
                $status = $response->getStatusCode();
                $body = (string) $response->getBody();
            } finally {
                $server->shutdown();
                $resolver->close();
            }
        });

        $this->assertSame(200, $status);
        $this->assertSame('ok', $body);
        // The IPv6 lookup happens only after the name failed to resolve as IPv4
        $this->assertSame('v6only.test/1', $queries[0] ?? null);
        $this->assertContains('v6only.test/28', $queries);
    }

    public function testItReconnectsBeforePostingToAnAbruptlyClosedIdleTlsConnection(): void
    {
        $connections = Http::dropsFirstKeepAliveConnection(function (int $port): void {
            $client = $this->createAdapter()->withConnectionReuse()->withSslVerification(false);
            Coroutine\run(function () use ($client, $port): void {
                for ($i = 0; $i < 4; $i++) {
                    $body = 'event-' . $i;
                    $request = new Request\Factory()->createRequest(Method::POST, 'https://127.0.0.1:' . $port . '/')
                        ->withHeader('Content-Length', (string) \strlen($body))
                        ->withBody(new Stream\Factory()->createStream($body));
                    $response = $client->sendRequest($request);
                    $this->assertSame(200, $response->getStatusCode());
                    $this->assertSame($body, (string) $response->getBody());
                    Coroutine::sleep(0.05);
                }
            });
        }, tls: true);

        $this->assertSame(2, $connections);
    }

    public function testItDoesNotReplayAPostWhenThePeerDropsItsResponse(): void
    {
        $thrown = null;
        $connections = Http::dropsFirstKeepAliveConnection(function (int $port) use (&$thrown): void {
            $client = $this->createAdapter()->withConnectionReuse()->withSslVerification(false);
            Coroutine\run(function () use ($client, $port, &$thrown): void {
                $factory = new Request\Factory();
                $uri = 'https://127.0.0.1:' . $port . '/';
                $this->assertSame(200, $client->sendRequest($factory->createRequest(Method::GET, $uri))->getStatusCode());
                $request = $factory->createRequest(Method::POST, $uri)
                    ->withHeader('Content-Length', '5')
                    ->withBody(new Stream\Factory()->createStream('event'));
                try {
                    $client->sendRequest($request);
                } catch (Throwable $throwable) {
                    $thrown = $throwable;
                }
            });
        }, tls: true, dropResponse: true);

        $this->assertInstanceOf(NetworkException::class, $thrown);
        $this->assertSame(1, $connections);
    }

    public function testItRejectsATrustedCertificateForAnotherHostname(): void
    {
        Http::dropsFirstKeepAliveConnection(function (int $port, string $certificate): void {
            $client = $this->createAdapter(['ssl_host_name' => 'localhost'])->withCustomCA($certificate)->withConnectionReuse();
            $this->runAdapter(function () use ($client, $port): void {
                $factory = new Request\Factory();
                $matching = $factory->createRequest(Method::GET, 'https://localhost:' . $port . '/');
                $this->assertSame('ok', (string) $client->sendRequest($matching)->getBody());

                $request = $factory->createRequest(Method::GET, 'https://localhost.:' . $port . '/private')
                    ->withHeader('Host', 'localhost');
                $thrown = null;
                try {
                    $client->sendRequest($request);
                } catch (Throwable $throwable) {
                    $thrown = $throwable;
                }

                $this->assertInstanceOf(TlsException::class, $thrown);
                $this->assertSame('ok', (string) $client->sendRequest($matching)->getBody());
            });
        }, tls: true, peerName: 'localhost', receivedRequests: $requests);

        $this->assertCount(2, $requests);
        foreach ($requests as $request) {
            $this->assertStringStartsWith('GET / HTTP/1.1', $request);
        }
    }

    public function testItRejectsVerifiedIpLiterals(): void
    {
        Http::dropsFirstKeepAliveConnection(function (int $port): void {
            $client = $this->createAdapter()->withSslVerification();
            $this->runAdapter(function () use ($client, $port): void {
                foreach (['127.0.0.1', '[::1]'] as $host) {
                    $request = new Request\Factory()->createRequest(Method::GET, 'https://' . $host . ':' . $port . '/');
                    $thrown = null;
                    try {
                        $client->sendRequest($request);
                    } catch (Throwable $throwable) {
                        $thrown = $throwable;
                    }

                    $this->assertInstanceOf(TlsException::class, $thrown);
                }
            });
        }, tls: true, receivedRequests: $requests);

        $this->assertSame([], $requests);
    }

    public function testABufferedRequestAfterAStreamedOneOnAReusedConnectionGetsItsBody(): void
    {
        Http::serve(function (int $port): void {
            $client = $this->createAdapter()->withConnectionReuse();
            $streamed = '';
            $sinkCalls = 0;

            $this->runAdapter(function () use ($client, $port, &$streamed, &$sinkCalls): void {
                $response = $client->stream(
                    new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/stream'),
                    function (string $chunk) use (&$streamed, &$sinkCalls): void {
                        $streamed .= $chunk;
                        $sinkCalls++;
                    },
                );
                $this->assertSame(200, $response->getStatusCode());
                $this->assertSame("chunk0\nchunk1\nchunk2\nchunk3\nchunk4\n", $streamed);

                $callsAfterStream = $sinkCalls;

                // The write callback the stream installed must not swallow the next buffered body.
                $response = $client->sendRequest(new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/buffered'));
                $this->assertSame(202, $response->getStatusCode());
                $this->assertSame('GET:/buffered::', (string) $response->getBody());
                $this->assertSame($callsAfterStream, $sinkCalls, 'the stale sink received nothing');

                // And a stream after that still reaches its own sink.
                $again = '';
                $client->stream(
                    new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/stream'),
                    function (string $chunk) use (&$again): void {
                        $again .= $chunk;
                    },
                );
                $this->assertSame($streamed, $again);
            });
        });
    }

    public function testABufferedRequestIgnoresAWriteCallbackPassedInSettings(): void
    {
        Http::serve(function (int $port): void {
            $calls = 0;
            $client = $this->createAdapter(['write_func' => function () use (&$calls): void {
                $calls++;
            }]);

            $this->runAdapter(function () use ($client, $port, &$calls): void {
                $response = $client->sendRequest(new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/buffered'));
                $this->assertSame(202, $response->getStatusCode());
                $this->assertSame('GET:/buffered::', (string) $response->getBody());
                $this->assertSame(0, $calls, 'the supplied write callback received nothing');
            });
        });
    }

    public function testAReusedStreamConnectionReleasesItsSinkAfterTheStream(): void
    {
        Http::serve(function (int $port): void {
            $client = $this->createAdapter()->withConnectionReuse();
            $captured = null;

            $this->runAdapter(function () use ($client, $port, &$captured): void {
                $held = new \stdClass();
                $captured = \WeakReference::create($held);

                $response = $client->stream(
                    new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/stream'),
                    function (string $chunk) use ($held): void {
                        $held->last = $chunk;
                    },
                );
                $this->assertSame(200, $response->getStatusCode());
            });

            gc_collect_cycles();

            $this->assertInstanceOf(\WeakReference::class, $captured);
            $this->assertNotInstanceOf(\stdClass::class, $captured->get(), 'the sink outlived the stream on the kept-alive connection');
        });
    }

    public function testItRequiresCoroutineContext(): void
    {
        $client = $this->createAdapter();
        $request = new Request\Factory()->createRequest(Method::GET, 'https://example.com');

        $this->expectException(AdapterPreconditionException::class);

        $client->sendRequest($request);
    }

    protected function requireAdapterAvailable(): void
    {
        $this->assertTrue(\extension_loaded('swoole'), 'The swoole extension is required.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function invalidTransportOptions(): array
    {
        return [
            'timeout' => [],
        ];
    }

    /**
     * @return array<string, float>
     */
    protected function timeoutOptions(float $timeout, ?float $connectTimeout = null): array
    {
        $options = [
            'timeout' => $timeout,
        ];

        if ($connectTimeout !== null) {
            $options['connect_timeout'] = $connectTimeout;
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    protected function proxyOptions(int $port): array
    {
        return [
            'socks5_host' => '127.0.0.1',
            'socks5_port' => $port,
        ];
    }

    /**
     * @return array<string, bool>
     */
    protected function followRedirectsTransportOptions(bool $enabled): array
    {
        return [
            'follow_location' => $enabled,
        ];
    }
}
