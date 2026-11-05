<?php

declare(strict_types=1);

namespace Utopia\Client\Tests\Adapter\SwooleCoroutine;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Psr\Http\Message\StreamInterface;
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
     * An unrestricted client leaves the lookup to Swoole: this name exists only in the
     * resolver Swoole is configured with, so a client that resolved it any other way
     * would not find it.
     */
    #[RunInSeparateProcess]
    public function testItLeavesAHostnameForSwooleToResolve(): void
    {
        $this->assertSame('GET ', $this->exchangeByName('v4only.test', [1 => '127.0.0.1'], '127.0.0.1', Method::GET));
    }

    /**
     * Swoole opens an IPv4 socket for a hostname, so a name with only an AAAA record
     * needs the adapter to find its IPv6 address.
     */
    #[RunInSeparateProcess]
    public function testItConnectsToAHostnameWithOnlyAnIpv6Address(): void
    {
        $this->assertSame('GET ', $this->exchangeByName('v6only.test', [28 => '::1'], '::1', Method::GET));
    }

    /**
     * The second attempt sends what the first prepared, so a body that can be read
     * only once still arrives.
     */
    #[RunInSeparateProcess]
    public function testItSendsAOneShotBodyToAHostnameWithOnlyAnIpv6Address(): void
    {
        // A body that yields its bytes once, like a pipe or a socket
        $body = new class () implements StreamInterface {
            private string $remaining = 'payload';

            public function __toString(): string
            {
                return $this->getContents();
            }

            public function close(): void
            {
            }

            public function detach(): null
            {
                return null;
            }

            public function getSize(): ?int
            {
                return null;
            }

            public function tell(): int
            {
                throw new \RuntimeException('Not seekable.');
            }

            public function eof(): bool
            {
                return $this->remaining === '';
            }

            public function isSeekable(): bool
            {
                return false;
            }

            public function seek(int $offset, int $whence = SEEK_SET): void
            {
                throw new \RuntimeException('Not seekable.');
            }

            public function rewind(): void
            {
                throw new \RuntimeException('Not seekable.');
            }

            public function isWritable(): bool
            {
                return false;
            }

            public function write(string $string): int
            {
                throw new \RuntimeException('Not writable.');
            }

            public function isReadable(): bool
            {
                return true;
            }

            public function read(int $length): string
            {
                $chunk = \substr($this->remaining, 0, $length);
                $this->remaining = \substr($this->remaining, \strlen($chunk));

                return $chunk;
            }

            public function getContents(): string
            {
                return $this->read(\strlen($this->remaining));
            }

            public function getMetadata(?string $key = null)
            {
                return $key === null ? [] : null;
            }
        };

        $this->assertSame('POST payload', $this->exchangeByName('v6only.test', [28 => '::1'], '::1', Method::POST, $body));
    }

    /**
     * Sends one request to a hostname only a fake resolver knows, served by a listener
     * on a loopback address that answers with the method and body it received. Callers
     * run in a separate process, because the resolver Swoole asks is a process-wide setting.
     *
     * @param array<int, string> $records record type => address
     */
    private function exchangeByName(string $hostname, array $records, string $listen, string $method, ?StreamInterface $body = null): string
    {
        $ipv6 = \str_contains($listen, ':');

        if ($ipv6) {
            $probe = @\stream_socket_server('tcp://[::1]:0');
            if ($probe === false) {
                $this->markTestSkipped('IPv6 loopback is not available.');
            }

            \fclose($probe);
        }

        $received = '';

        $this->runAdapter(function () use ($hostname, $records, $listen, $ipv6, $method, $body, &$received): void {
            $resolver = new Coroutine\Socket(AF_INET, SOCK_DGRAM, 0);
            $resolver->bind('127.0.0.1', 0);

            Coroutine::create(static function () use ($resolver, $hostname, $records): void {
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

                    $unpacked = \unpack('n', \substr($query, $offset + 1, 2));
                    $type = \is_array($unpacked) && \is_int($unpacked[1] ?? null) ? $unpacked[1] : 0;

                    $known = \implode('.', $labels) === $hostname;
                    $address = $known && isset($records[$type]) ? (string) \inet_pton($records[$type]) : '';
                    // A compressed-name record: NOERROR for the known name, NXDOMAIN otherwise
                    $answer = $address === '' ? '' : "\xc0\x0c" . \pack('nnNn', $type, 1, 60, \strlen($address)) . $address;
                    $response = \substr($query, 0, 2)
                        . ($known ? "\x81\x80" : "\x81\x83")
                        . "\x00\x01" . \pack('n', $answer === '' ? 0 : 1) . "\x00\x00\x00\x00"
                        . \substr($query, 12, $offset + 5 - 12)
                        . $answer;

                    $resolver->sendto($peer['address'], $peer['port'], $response);
                }
            });

            $bound = $resolver->getsockname();
            Coroutine::set(['dns_server' => '127.0.0.1:' . (\is_array($bound) && \is_int($bound['port'] ?? null) ? $bound['port'] : 0)]);

            // Listening before the request is sent, so nothing waits for the accept loop
            $server = new Coroutine\Socket($ipv6 ? AF_INET6 : AF_INET, SOCK_STREAM, 0);
            $server->bind($listen, 0);
            $server->listen();

            Coroutine::create(static function () use ($server): void {
                $connection = $server->accept(5.0);
                if (!$connection instanceof Coroutine\Socket) {
                    return;
                }

                $request = '';
                do {
                    $chunk = $connection->recv(65536, 1.0);
                    $request .= \is_string($chunk) ? $chunk : '';
                    $parts = \explode("\r\n\r\n", $request, 2);
                    $head = $parts[0];
                    $sent = $parts[1] ?? null;
                    $expected = 0;
                    foreach (\explode("\r\n", $head) as $line) {
                        if (\stripos($line, 'content-length:') === 0) {
                            $expected = (int) \trim(\substr($line, 15));
                        }
                    }
                } while (\is_string($chunk) && $chunk !== '' && ($sent === null || \strlen($sent) < $expected));

                $echo = \strtok($head, ' ') . ' ' . $sent;
                $connection->sendAll("HTTP/1.1 200 OK\r\nContent-Length: " . \strlen($echo) . "\r\nConnection: close\r\n\r\n" . $echo);
                $connection->close();
            });

            $bound = $server->getsockname();
            $request = new Request\Factory()->createRequest($method, 'http://' . $hostname . ':' . (\is_array($bound) && \is_int($bound['port'] ?? null) ? $bound['port'] : 0) . '/');

            if ($body instanceof StreamInterface) {
                $request = $request->withHeader('Content-Length', '7')->withBody($body);
            }

            try {
                $response = $this->createAdapter($this->timeoutOptions(3.0))->sendRequest($request);
                $this->assertSame(200, $response->getStatusCode());
                $received = (string) $response->getBody();
            } finally {
                $server->close();
                $resolver->close();
            }
        });

        return $received;
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
