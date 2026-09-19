<?php

declare(strict_types=1);

namespace Utopia\Tests\Client\Adapter\SwooleCoroutine;

use Swoole\Coroutine;
use Throwable;
use Utopia\Client\Adapter;
use Utopia\Client\Adapter\SwooleCoroutine\Client;
use Utopia\Client\Exception\AdapterPreconditionException;
use Utopia\Client\Exception\NetworkException;
use Utopia\Client\Exception\TlsException;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Tests\Client\Adapter\AdapterContract;
use Utopia\Tests\Server\Http;

final class ClientTest extends AdapterContract
{
    /**
     * @param array<string, mixed> $transportOptions
     */
    protected function createAdapter(array $transportOptions = []): Adapter
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
