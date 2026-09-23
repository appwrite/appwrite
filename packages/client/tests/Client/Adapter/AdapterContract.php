<?php

declare(strict_types=1);

namespace Utopia\Tests\Client\Adapter;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Utopia\Client\Adapter;
use Utopia\Client\Exception\ConnectionException;
use Utopia\Client\Exception\DnsException;
use Utopia\Client\Exception\InvalidResponseException;
use Utopia\Client\Exception\InvalidUriException;
use Utopia\Client\Exception\ProtocolException;
use Utopia\Client\Exception\ProxyException;
use Utopia\Client\Exception\TimeoutException;
use Utopia\Client\Exception\TlsException;
use Utopia\Client\Tls;
use Utopia\Psr7\ContentType;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request;
use Utopia\Psr7\Request\Multipart\Part;
use Utopia\Psr7\Stream;
use Utopia\Tests\Server\Http;
use ValueError;

abstract class AdapterContract extends TestCase
{
    /**
     * Payload size exercised by the request and response payload-size contracts.
     */
    private const int PAYLOAD_SIZE = 8 * 1024 * 1024;

    /**
     * @param array<string|int, mixed> $transportOptions
     */
    abstract protected function createAdapter(array $transportOptions = []): Adapter;

    abstract protected function runAdapter(callable $callback): void;

    abstract protected function requireAdapterAvailable(): void;

    /**
     * @return array<string|int, mixed>
     */
    abstract protected function invalidTransportOptions(): array;

    /**
     * @return array<string|int, mixed>
     */
    abstract protected function timeoutOptions(float $timeout, ?float $connectTimeout = null): array;

    /**
     * @return array<string|int, mixed>
     */
    abstract protected function proxyOptions(int $port): array;

    /**
     * Native constructor options that would enable or disable following
     * redirects if the helper were not authoritative over them.
     *
     * @return array<string|int, mixed>
     */
    abstract protected function followRedirectsTransportOptions(bool $enabled): array;

    protected function setUp(): void
    {
        $this->requireAdapterAvailable();
    }

    public function testItRequiresAbsoluteUris(): void
    {
        $requestFactory = new Request\Factory();
        $client = $this->createAdapter();

        $this->expectException(InvalidUriException::class);

        $this->send($client, $requestFactory->createRequest(Method::GET, '/relative'));
    }

    public function testItRejectsUnsupportedUriSchemes(): void
    {
        $requestFactory = new Request\Factory();
        $client = $this->createAdapter();

        $this->expectException(InvalidUriException::class);

        $this->send($client, $requestFactory->createRequest(Method::GET, 'ftp://example.com/resource'));
    }

    public function testItSendsRequests(): void
    {
        Http::serve(function (int $port): void {
            $requestFactory = new Request\Factory();
            $streamFactory = new Stream\Factory();
            $client = $this->createAdapter();
            $request = $requestFactory->createRequest(Method::POST, 'http://127.0.0.1:' . $port . '/echo')
                ->withHeader(Header::CONTENT_TYPE, ContentType::PLAIN_TEXT)
                ->withHeader('X-Custom', 'sent')
                ->withBody($streamFactory->createStream('hello'));

            $response = $this->send($client, $request);

            $this->assertSame(202, $response->getStatusCode());
            $this->assertSame('text/plain;charset=UTF-8', $response->getHeaderLine(Header::CONTENT_TYPE));
            $this->assertSame('POST:/echo:sent:hello', (string) $response->getBody());
        });
    }

    public function testItReturnsClientAndServerErrorResponsesWithoutThrowing(): void
    {
        Http::serve(function (int $port): void {
            $requestFactory = new Request\Factory();
            $client = $this->createAdapter();

            $notFound = $this->send($client, $requestFactory->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/not-found'));
            $serverError = $this->send($client, $requestFactory->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/server-error'));

            $this->assertSame(404, $notFound->getStatusCode());
            $this->assertSame('missing', (string) $notFound->getBody());
            $this->assertSame(500, $serverError->getStatusCode());
            $this->assertSame('failed', (string) $serverError->getBody());
        });
    }

    public function testItDoesNotFollowRedirectsByDefault(): void
    {
        Http::serve(function (int $port): void {
            $requestFactory = new Request\Factory();
            $client = $this->createAdapter();

            $response = $this->send($client, $requestFactory->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/redirect'));

            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('/final', $response->getHeaderLine(Header::LOCATION));
            $this->assertSame('redirect', (string) $response->getBody());
        });
    }

    public function testItDoesNotFollowRedirectsWhenStreaming(): void
    {
        Http::serve(function (int $port): void {
            $request = new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/redirect');
            $client = $this->createAdapter();

            $received = '';

            $response = $this->sendStream($client, $request, function (string $chunk) use (&$received): void {
                $received .= $chunk;
            });

            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('/final', $response->getHeaderLine(Header::LOCATION));
            $this->assertSame('redirect', $received);
            $this->assertSame('', (string) $response->getBody());
        });
    }

    public function testItFollowsRedirectsWhenEnabled(): void
    {
        Http::serve(function (int $port): void {
            $requestFactory = new Request\Factory();
            $client = $this->createAdapter()->withFollowRedirects();

            $response = $this->send($client, $requestFactory->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/redirect'));

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('final', (string) $response->getBody());
            $this->assertSame('', $response->getHeaderLine(Header::LOCATION));
        });
    }

    public function testItFollowsRedirectsWhenStreaming(): void
    {
        Http::serve(function (int $port): void {
            $request = new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/redirect');
            $client = $this->createAdapter()->withFollowRedirects();

            $received = '';

            $response = $this->sendStream($client, $request, function (string $chunk) use (&$received): void {
                $received .= $chunk;
            });

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('final', $received);
            $this->assertSame('', (string) $response->getBody());
            $this->assertSame('', $response->getHeaderLine(Header::LOCATION));
        });
    }

    public function testConstructorFollowRedirectsOptionsDoNotOverrideTheDefault(): void
    {
        Http::serve(function (int $port): void {
            $client = $this->createAdapter($this->followRedirectsTransportOptions(true));
            $request = new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/redirect');

            $response = $this->send($client, $request);

            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('/final', $response->getHeaderLine(Header::LOCATION));
            $this->assertSame('redirect', (string) $response->getBody());
        });
    }

    public function testWithFollowRedirectsOverridesConstructorTransportOptions(): void
    {
        Http::serve(function (int $port): void {
            $client = $this->createAdapter($this->followRedirectsTransportOptions(false))->withFollowRedirects();
            $request = new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/redirect');

            $response = $this->send($client, $request);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('final', (string) $response->getBody());
        });
    }

    public function testItFollowsPathRelativeRedirects(): void
    {
        Http::serve(function (int $port): void {
            $requestFactory = new Request\Factory();
            $client = $this->createAdapter()->withFollowRedirects();
            $origin = 'http://127.0.0.1:' . $port;

            $parent = $this->send($client, $requestFactory->createRequest(Method::GET, $origin . '/nested/parent'));
            $dot = $this->send($client, $requestFactory->createRequest(Method::GET, $origin . '/nested/dot'));
            $plain = $this->send($client, $requestFactory->createRequest(Method::GET, $origin . '/nested/plain'));

            $this->assertSame(200, $parent->getStatusCode());
            $this->assertSame('final', (string) $parent->getBody());
            $this->assertSame(200, $dot->getStatusCode());
            $this->assertSame('nested-final', (string) $dot->getBody());
            $this->assertSame(200, $plain->getStatusCode());
            $this->assertSame('nested-final', (string) $plain->getBody());
        });
    }

    public function testItFollowsAbsoluteRedirectUris(): void
    {
        Http::serve(function (int $port): void {
            $client = $this->createAdapter()->withFollowRedirects();
            $request = new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/redirect-absolute');

            $response = $this->send($client, $request);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('final', (string) $response->getBody());
        });
    }

    public function testItAllowsFiftyRedirectHops(): void
    {
        Http::serve(function (int $port): void {
            $client = $this->createAdapter()->withFollowRedirects();
            $request = new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/hops/50');

            $response = $this->send($client, $request);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('hopped', (string) $response->getBody());
        });
    }

    public function testItRejectsFiftyOneRedirectHops(): void
    {
        Http::serve(function (int $port): void {
            $client = $this->createAdapter()->withFollowRedirects();
            $request = new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/hops/51');

            $this->expectException(ProtocolException::class);

            $this->send($client, $request);
        });
    }

    public function testItKeepsAuthorizationOnSameOriginRedirects(): void
    {
        Http::serve(function (int $port): void {
            $request = new Request\Factory()
                ->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/redirect-auth')
                ->withHeader(Header::AUTHORIZATION, 'Bearer secret')
                ->withHeader(Header::COOKIE, 'session=keep');
            $client = $this->createAdapter()->withFollowRedirects();

            $response = $this->send($client, $request);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('Bearer secret|session=keep', (string) $response->getBody());
        });
    }

    public function testItStripsAuthorizationOnCrossOriginRedirects(): void
    {
        Http::serve(function (int $port): void {
            $request = new Request\Factory()
                ->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/redirect-cross')
                ->withHeader(Header::AUTHORIZATION, 'Bearer secret')
                ->withHeader(Header::COOKIE, 'session=drop');
            $client = $this->createAdapter()->withFollowRedirects();

            $response = $this->send($client, $request);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('|', (string) $response->getBody());
        });
    }

    public function testItStreamsTheFinalHopAfterARedirect(): void
    {
        Http::serve(function (int $port): void {
            $request = new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/redirect-large');
            $client = $this->createAdapter()->withFollowRedirects();

            $expected = self::PAYLOAD_SIZE;
            $hash = hash_init('sha256');
            $read = 0;
            $baseline = memory_get_usage();
            $peak = 0;
            $chunks = 0;

            $response = $this->sendStream($client, $request, function (string $chunk) use ($hash, &$read, $baseline, &$peak, &$chunks): void {
                $chunks++;
                hash_update($hash, $chunk);
                $read += \strlen($chunk);
                $peak = max($peak, memory_get_usage() - $baseline);
            });

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame($expected, $read);
            $this->assertGreaterThan(1, $chunks, 'The final hop must be delivered in chunks, not as one assembled body.');
            $this->assertSame(hash('sha256', str_repeat('a', $expected)), hash_final($hash));
            $this->assertLessThan(2 * 1_048_576, $peak, 'Following a redirect must still stream the final body.');
            $this->assertSame('', (string) $response->getBody());
        });
    }

    public function testItStreamsPreservedMethodFinalHopAfterARedirect(): void
    {
        Http::serve(function (int $port): void {
            $request = new Request\Factory()->createRequest(Method::POST, 'http://127.0.0.1:' . $port . '/redirect-stream-preserve');
            $client = $this->createAdapter()->withFollowRedirects();
            $received = '';
            $chunks = 0;

            $response = $this->sendStream($client, $request, function (string $chunk) use (&$received, &$chunks): void {
                $received .= $chunk;
                $chunks++;
            });

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame("chunk0\nchunk1\nchunk2\nchunk3\nchunk4\n", $received);
            $this->assertGreaterThan(1, $chunks, 'Redirected non-GET responses must reach the sink incrementally.');
            $this->assertSame('', (string) $response->getBody());
        });
    }

    public function testItPreservesDuplicateMixedCaseHeadersAndBinaryBodies(): void
    {
        Http::serve(function (int $port): void {
            $requestFactory = new Request\Factory();
            $client = $this->createAdapter();

            $headers = $this->send($client, $requestFactory->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/headers'));
            $binary = $this->send($client, $requestFactory->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/binary'));

            $this->assertSame(204, $headers->getStatusCode());
            $this->assertSame(['one', 'two'], $headers->getHeader('x-trace'));
            $this->assertSame('Value', $headers->getHeaderLine('X-Mixed-Case'));
            $this->assertSame(ContentType::OCTET_STREAM, $binary->getHeaderLine(Header::CONTENT_TYPE));
            $this->assertSame("\x00\x01hello\xff", (string) $binary->getBody());
        });
    }

    public function testItSendsExplicitHostAndRepeatedRequestHeaders(): void
    {
        Http::serve(function (int $port): void {
            $request = new Request\Factory()
                ->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/request-headers')
                ->withHeader(Header::HOST, 'proxy.example.test')
                ->withHeader('X-Trace', ['one', 'two']);
            $client = $this->createAdapter();

            $response = $this->send($client, $request);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('proxy.example.test:one, two', (string) $response->getBody());
        });
    }

    public function testItSendsDefaultHostWithNonDefaultPorts(): void
    {
        Http::serve(function (int $port): void {
            $request = new Request\Factory()
                ->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/request-headers')
                ->withHeader('X-Trace', 'sent');
            $client = $this->createAdapter();

            $response = $this->send($client, $request);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('127.0.0.1:' . $port . ':sent', (string) $response->getBody());
        });
    }

    public function testItPreservesQueryStringsEmptyPathsAndStripsFragments(): void
    {
        Http::serve(function (int $port): void {
            $requestFactory = new Request\Factory();
            $client = $this->createAdapter();

            $query = $this->send($client, $requestFactory->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/request-target?x=1&y=two#fragment'));
            $emptyPath = $this->send($client, $requestFactory->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '?ping=1#fragment'));
            $encoded = $this->send($client, $requestFactory->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/space%20name?value=a%2Bb#fragment'));

            $this->assertSame('/request-target?x=1&y=two', (string) $query->getBody());
            $this->assertSame('/?ping=1', (string) $emptyPath->getBody());
            $this->assertSame('/space%20name?value=a%2Bb', (string) $encoded->getBody());
        });
    }

    public function testItPreservesMethodsWithEmptyBodies(): void
    {
        Http::serve(function (int $port): void {
            $requestFactory = new Request\Factory();
            $client = $this->createAdapter();

            $delete = $this->send($client, $requestFactory->createRequest(Method::DELETE, 'http://127.0.0.1:' . $port . '/method'));
            $patch = $this->send($client, $requestFactory->createRequest(Method::PATCH, 'http://127.0.0.1:' . $port . '/method'));
            $head = $this->send($client, $requestFactory->createRequest(Method::HEAD, 'http://127.0.0.1:' . $port . '/method'));

            $this->assertSame(Method::DELETE, $delete->getHeaderLine('X-Request-Method'));
            $this->assertSame(Method::DELETE, (string) $delete->getBody());
            $this->assertSame(Method::PATCH, $patch->getHeaderLine('X-Request-Method'));
            $this->assertSame(Method::PATCH, (string) $patch->getBody());
            $this->assertSame(Method::HEAD, $head->getHeaderLine('X-Request-Method'));
            $this->assertSame('', (string) $head->getBody());
        });
    }

    public function testItPreservesCustomMethodsAndRequestBodies(): void
    {
        Http::serve(function (int $port): void {
            $streamFactory = new Stream\Factory();
            $request = new Request\Factory()
                ->createRequest('PROPFIND', 'http://127.0.0.1:' . $port . '/echo')
                ->withHeader('X-Custom', 'sent')
                ->withBody($streamFactory->createStream('custom-body'));
            $client = $this->createAdapter();

            $response = $this->send($client, $request);

            $this->assertSame(202, $response->getStatusCode());
            $this->assertSame('PROPFIND:/echo:sent:custom-body', (string) $response->getBody());
        });
    }

    public function testItSendsBinaryRequestBodies(): void
    {
        Http::serve(function (int $port): void {
            $body = "\x00\x01hello\xff";
            $streamFactory = new Stream\Factory();
            $request = new Request\Factory()
                ->createRequest(Method::POST, 'http://127.0.0.1:' . $port . '/body-info')
                ->withBody($streamFactory->createStream($body));
            $client = $this->createAdapter();

            $response = $this->send($client, $request);

            $this->assertSame(\strlen($body) . ':' . hash('sha256', $body), (string) $response->getBody());
        });
    }

    public function testItPreservesCommaSeparatedAndZeroRequestHeaderValues(): void
    {
        Http::serve(function (int $port): void {
            $request = new Request\Factory()
                ->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/selected-headers')
                ->withHeader('X-Comma', 'one, two')
                ->withHeader('X-Zero', '0')
                ->withHeader('X-Mixed-Request', 'Value');
            $client = $this->createAdapter();

            $response = $this->send($client, $request);

            $this->assertSame('one, two:0:Value', (string) $response->getBody());
        });
    }

    public function testItUsesHttp11ProtocolVersion(): void
    {
        Http::serve(function (int $port): void {
            $request = new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/binary');
            $client = $this->createAdapter();

            $response = $this->send($client, $request);

            $this->assertSame('1.1', $response->getProtocolVersion());
        });
    }

    public function testItParsesFinalResponseMetadata(): void
    {
        Http::raw("HTTP/1.1 201 Created Thing\r\nX-Trace: final\r\nX-Colon: http://example.test/a:b\r\nContent-Length: 7\r\n\r\ncreated", function (int $port): void {
            $client = $this->createAdapter($this->timeoutOptions(1, 1));
            $request = new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/interim');

            $response = $this->send($client, $request);

            $this->assertSame(201, $response->getStatusCode());
            $this->assertSame('1.1', $response->getProtocolVersion());
            $this->assertSame('final', $response->getHeaderLine('X-Trace'));
            $this->assertSame('http://example.test/a:b', $response->getHeaderLine('X-Colon'));
            $this->assertSame('created', (string) $response->getBody());
        });
    }

    public function testItPreservesRepeatedSetCookieResponseHeaders(): void
    {
        Http::raw("HTTP/1.1 200 OK\r\nSet-Cookie: a=1; Path=/\r\nSet-Cookie: b=2; Path=/; HttpOnly\r\nContent-Length: 2\r\n\r\nok", function (int $port): void {
            $client = $this->createAdapter($this->timeoutOptions(1, 1));
            $request = new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/cookies');

            $response = $this->send($client, $request);

            $this->assertSame(['a=1; Path=/', 'b=2; Path=/; HttpOnly'], $response->getHeader('Set-Cookie'));
            $this->assertSame('ok', (string) $response->getBody());
        });
    }

    public function testItDecodesChunkedResponseBodies(): void
    {
        Http::raw("HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n6\r\n world\r\n0\r\n\r\n", function (int $port): void {
            $client = $this->createAdapter($this->timeoutOptions(1, 1));
            $request = new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/chunked');

            $response = $this->send($client, $request);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('hello world', (string) $response->getBody());
        });
    }

    public function testItReturnsEmptyBodiesForNoContentResponses(): void
    {
        Http::serve(function (int $port): void {
            $request = new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/headers');
            $client = $this->createAdapter();

            $response = $this->send($client, $request);

            $this->assertSame(204, $response->getStatusCode());
            $this->assertSame('', (string) $response->getBody());
            $this->assertSame(['one', 'two'], $response->getHeader('x-trace'));
        });
    }

    public function testItRoundTripsLargeResponseBodies(): void
    {
        Http::serve(function (int $port): void {
            $request = new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/large-response');
            $client = $this->createAdapter();

            $response = $this->send($client, $request);
            $body = (string) $response->getBody();

            $this->assertSame(262_144, \strlen($body));
            $this->assertSame(hash('sha256', str_repeat('abcd', 65_536)), hash('sha256', $body));
        });
    }

    public function testItRoundTripsLargeRequestBodies(): void
    {
        Http::serve(function (int $port): void {
            $body = str_repeat('wxyz', 65_536);
            $streamFactory = new Stream\Factory();
            $request = new Request\Factory()
                ->createRequest(Method::POST, 'http://127.0.0.1:' . $port . '/body-info')
                ->withBody($streamFactory->createStream($body));
            $client = $this->createAdapter();

            $response = $this->send($client, $request);

            $this->assertSame(\strlen($body) . ':' . hash('sha256', $body), (string) $response->getBody());
        });
    }

    public function testItSendsLargeRequestPayloads(): void
    {
        Http::serve(function (int $port): void {
            $body = str_repeat('a', self::PAYLOAD_SIZE);
            $streamFactory = new Stream\Factory();
            $request = new Request\Factory()
                ->createRequest(Method::POST, 'http://127.0.0.1:' . $port . '/body-info')
                ->withHeader(Header::CONTENT_TYPE, ContentType::OCTET_STREAM)
                ->withBody($streamFactory->createStream($body));
            $client = $this->createAdapter();

            $response = $this->send($client, $request);

            $this->assertSame(self::PAYLOAD_SIZE . ':' . hash('sha256', $body), (string) $response->getBody());
        });
    }

    public function testItUploadsMultipartFilesAndFields(): void
    {
        Http::serve(function (int $port): void {
            $contents = str_repeat('payload', 4096);
            $path = $this->writeTempFile($contents);

            try {
                $request = new Request\Factory()->multipart(Method::POST, 'http://127.0.0.1:' . $port . '/multipart', [
                    'name' => 'Ada',
                    'file' => Part::file('file', $path, 'data.bin', ContentType::OCTET_STREAM),
                ]);
                $client = $this->createAdapter();

                $response = $this->send($client, $request);

                $this->assertSame(200, $response->getStatusCode());
                $this->assertSame('Ada:' . \strlen($contents) . ':' . hash('sha256', $contents), (string) $response->getBody());
            } finally {
                unlink($path);
            }
        });
    }

    public function testTimeoutHelpersReturnConfiguredClones(): void
    {
        $client = $this->createAdapter();

        $this->assertNotSame($client, $client->withTimeout(1));
        $this->assertNotSame($client, $client->withConnectTimeout(1));
    }

    public function testTlsHelpersReturnConfiguredClones(): void
    {
        $client = $this->createAdapter();

        $this->assertNotSame($client, $client->withSslVerification(false));
        $this->assertNotSame($client, $client->withCustomCA('/etc/ssl/ca.pem'));
        $this->assertNotSame($client, $client->withCertificate('/etc/ssl/client.pem', '/etc/ssl/client.key'));
        $this->assertNotSame($client, $client->withCertificate('/etc/ssl/client.pem', '/etc/ssl/client.key', 'secret'));
        $this->assertNotSame($client, $client->withMinTlsVersion(Tls::V1_2));
    }

    public function testFollowRedirectsHelperReturnsConfiguredClones(): void
    {
        $client = $this->createAdapter();

        $this->assertNotSame($client, $client->withFollowRedirects());
        $this->assertNotSame($client, $client->withFollowRedirects(false));
    }

    public function testDefaultTimeoutsAllowReasonablySlowResponses(): void
    {
        Http::serve(function (int $port): void {
            $requestFactory = new Request\Factory();
            $client = $this->createAdapter();

            $response = $this->send($client, $requestFactory->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/slow'));

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('slow', (string) $response->getBody());
        });
    }

    public function testItRejectsInvalidResponseStatusCodes(): void
    {
        Http::raw("HTTP/1.1 999 Invalid\r\nContent-Length: 7\r\n\r\ninvalid", function (int $port): void {
            $client = $this->createAdapter($this->timeoutOptions(1, 1));
            $request = new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/invalid');

            $this->expectException(InvalidResponseException::class);

            $this->send($client, $request);
        });
    }

    public function testItThrowsProtocolExceptionsForMalformedResponses(): void
    {
        Http::raw("not an http response\r\n\r\n", function (int $port): void {
            $client = $this->createAdapter($this->timeoutOptions(1, 1));
            $request = new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/malformed');

            $this->expectException(ProtocolException::class);

            $this->send($client, $request);
        });
    }

    public function testItThrowsConnectionExceptionsWhenServerClosesBeforeResponse(): void
    {
        Http::raw('', function (int $port): void {
            $client = $this->createAdapter($this->timeoutOptions(1, 1));
            $request = new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/closed');

            $this->expectException(ConnectionException::class);

            $this->send($client, $request);
        });
    }

    public function testItThrowsConnectionExceptionsForPartialResponseHeaders(): void
    {
        Http::raw("HTTP/1.1 200 OK\r\nX-Partial: value", function (int $port): void {
            $client = $this->createAdapter($this->timeoutOptions(1, 1));
            $request = new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/partial-headers');

            $this->expectException(ConnectionException::class);

            $this->send($client, $request);
        });
    }

    public function testItThrowsProtocolExceptionsForTruncatedBodies(): void
    {
        Http::raw("HTTP/1.1 200 OK\r\nContent-Length: 10\r\n\r\nshort", function (int $port): void {
            $client = $this->createAdapter($this->timeoutOptions(1, 1));
            $request = new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/truncated-body');

            $this->expectException(ProtocolException::class);

            $this->send($client, $request);
        });
    }

    public function testItDoesNotReadABodyForHeadResponses(): void
    {
        // A HEAD response advertises the resource's Content-Length but carries
        // no body. The adapter must not try to read that body: on a closed
        // socket that surfaces as a truncated-body error, and on a kept-alive
        // socket it wedges until the timeout. Either way the request must come
        // back as a clean, empty-bodied 200.
        Http::raw("HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 4\r\n\r\n", function (int $port): void {
            $client = $this->createAdapter($this->timeoutOptions(1, 1));
            $request = new Request\Factory()->createRequest(Method::HEAD, 'http://127.0.0.1:' . $port . '/head');

            $response = $this->send($client, $request);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('4', $response->getHeaderLine('Content-Length'));
            $this->assertSame('', (string) $response->getBody());
        });
    }

    public function testItThrowsConnectionExceptionsForConnectionFailures(): void
    {
        Http::unbound(function (int $port): void {
            $requestFactory = new Request\Factory();
            $client = $this->createAdapter($this->timeoutOptions(0.1, 0.1));

            $this->expectException(ConnectionException::class);

            $this->send($client, $requestFactory->createRequest(Method::GET, 'http://127.0.0.1:' . $port));
        });
    }

    public function testItRecoversWhenAReusedConnectionIsDropped(): void
    {
        $statuses = [];

        $connections = Http::dropsFirstKeepAliveConnection(function (int $port) use (&$statuses): void {
            $client = $this->createAdapter()->withConnectionReuse();
            $requestFactory = new Request\Factory();
            $uri = 'http://127.0.0.1:' . $port . '/';

            // A reused connection only persists within a single coroutine run,
            // so every request shares one runAdapter call.
            $this->runAdapter(function () use ($client, $requestFactory, $uri, &$statuses): void {
                for ($i = 0; $i < 4; $i++) {
                    $statuses[] = $client->sendRequest($requestFactory->createRequest(Method::GET, $uri))->getStatusCode();
                }
            });
        });

        // The peer dropped the first keep-alive socket; a reusing client must
        // re-establish it and keep serving, not wedge on the dead connection.
        $this->assertSame([200, 200, 200, 200], $statuses);
        $this->assertSame(2, $connections);
    }

    public function testItThrowsDnsExceptionsForResolutionFailures(): void
    {
        $requestFactory = new Request\Factory();
        $client = $this->createAdapter($this->timeoutOptions(2, 1));

        $this->expectException(DnsException::class);

        $this->send($client, $requestFactory->createRequest(Method::GET, 'http://utopia-request.invalid'));
    }

    public function testItThrowsProxyExceptionsForProxyFailures(): void
    {
        Http::raw("\x04\x00", function (int $port): void {
            $requestFactory = new Request\Factory();
            $client = $this->createAdapter($this->proxyOptions($port) + $this->timeoutOptions(1, 1));

            $this->expectException(ProxyException::class);

            $this->send($client, $requestFactory->createRequest(Method::GET, 'http://example.com/'));
        });
    }

    public function testItThrowsTlsExceptionsForTlsFailures(): void
    {
        Http::serve(function (int $port): void {
            $requestFactory = new Request\Factory();
            $client = $this->createAdapter($this->timeoutOptions(1, 1));

            $this->expectException(TlsException::class);

            $this->send($client, $requestFactory->createRequest(Method::GET, 'https://127.0.0.1:' . $port . '/binary'));
        });
    }

    public function testItThrowsTimeoutExceptionsForTimedOutRequests(): void
    {
        Http::serve(function (int $port): void {
            $requestFactory = new Request\Factory();
            $client = $this->createAdapter($this->timeoutOptions(0.1));

            $this->expectException(TimeoutException::class);

            $this->send($client, $requestFactory->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/slow'));
        });
    }

    public function testItRejectsInvalidTimeoutValues(): void
    {
        $client = $this->createAdapter();

        $this->expectException(ValueError::class);

        $client->withTimeout(INF);
    }

    public function testItRejectsInvalidConnectTimeoutValues(): void
    {
        $client = $this->createAdapter();

        $this->expectException(ValueError::class);

        $client->withConnectTimeout(-0.001);
    }

    public function testItRejectsInvalidAdapterConfigurationOptions(): void
    {
        Http::serve(function (int $port): void {
            $client = $this->createAdapter($this->invalidTransportOptions());
            $request = new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/binary');

            $this->expectException(InvalidArgumentException::class);

            $this->send($client, $request);
        });
    }

    public function testItSendsZeroStringBodiesAsNonEmptyBodies(): void
    {
        Http::serve(function (int $port): void {
            $streamFactory = new Stream\Factory();
            $request = new Request\Factory()
                ->createRequest(Method::PUT, 'http://127.0.0.1:' . $port . '/echo')
                ->withHeader('X-Custom', 'sent')
                ->withBody($streamFactory->createStream('0'));
            $client = $this->createAdapter();

            $response = $this->send($client, $request);

            $this->assertSame(202, $response->getStatusCode());
            $this->assertSame('PUT:/echo:sent:0', (string) $response->getBody());
        });
    }

    public function testItAdvertisesCompressionAndTransparentlyDecodesResponses(): void
    {
        Http::serve(function (int $port): void {
            $request = new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/gzip');
            $client = $this->createAdapter();

            $response = $this->send($client, $request);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('gzip', $response->getHeaderLine('X-Accept-Encoding'));
            $this->assertSame(str_repeat('utopia ', 64), (string) $response->getBody());
            $this->assertFalse($response->hasHeader(Header::CONTENT_ENCODING));
            $this->assertFalse($response->hasHeader(Header::CONTENT_LENGTH));
        });
    }

    public function testItDecodesLargeCompressedResponses(): void
    {
        Http::serve(function (int $port): void {
            $request = new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/gzip?repeat=40000');
            $client = $this->createAdapter();

            $response = $this->send($client, $request);
            $body = (string) $response->getBody();

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(40_000 * 7, \strlen($body));
            $this->assertSame(hash('sha256', str_repeat('utopia ', 40_000)), hash('sha256', $body));
            $this->assertFalse($response->hasHeader(Header::CONTENT_ENCODING));
        });
    }

    public function testItDecodesCompressedBinaryResponsesByteForByte(): void
    {
        Http::serve(function (int $port): void {
            $request = new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/gzip?type=binary&repeat=16');
            $client = $this->createAdapter();

            $expected = '';
            for ($byte = 0; $byte < 256; $byte++) {
                $expected .= \chr($byte);
            }

            $expected = str_repeat($expected, 16);

            $response = $this->send($client, $request);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame($expected, (string) $response->getBody());
            $this->assertFalse($response->hasHeader(Header::CONTENT_ENCODING));
        });
    }

    public function testItLeavesUncompressedResponsesAndTheirContentLengthIntact(): void
    {
        Http::serve(function (int $port): void {
            // Advertised-to but answered in plaintext: body and Content-Length pass through.
            $request = new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/gzip?compress=0');
            $client = $this->createAdapter();

            $response = $this->send($client, $request);
            $body = (string) $response->getBody();

            $this->assertSame(str_repeat('utopia ', 64), $body);
            $this->assertStringContainsString('gzip', $response->getHeaderLine('X-Accept-Encoding'));
            $this->assertFalse($response->hasHeader(Header::CONTENT_ENCODING));
            $this->assertSame((string) \strlen($body), $response->getHeaderLine(Header::CONTENT_LENGTH));
        });
    }

    public function testItDeliversDecodedBodiesWhenStreaming(): void
    {
        Http::serve(function (int $port): void {
            $request = new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/gzip?repeat=20000');
            $client = $this->createAdapter();

            $received = '';

            $response = $this->sendStream($client, $request, function (string $chunk) use (&$received): void {
                $received .= $chunk;
            });

            // cURL decodes the stream; Swoole asks for identity. Either way the sink sees plaintext.
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(str_repeat('utopia ', 20_000), $received);
            $this->assertSame('', (string) $response->getBody());
        });
    }

    public function testItDecodesCompressedResponsesOverAReusedConnection(): void
    {
        $bodies = [];

        Http::serve(function (int $port) use (&$bodies): void {
            $client = $this->createAdapter()->withConnectionReuse();
            $requestFactory = new Request\Factory();
            $uri = 'http://127.0.0.1:' . $port . '/gzip';

            // A reused connection only persists within one coroutine run.
            $this->runAdapter(function () use ($client, $requestFactory, $uri, &$bodies): void {
                for ($i = 0; $i < 3; $i++) {
                    $bodies[] = (string) $client->sendRequest($requestFactory->createRequest(Method::GET, $uri))->getBody();
                }
            });
        });

        $this->assertSame(array_fill(0, 3, str_repeat('utopia ', 64)), $bodies);
    }

    public function testItDoesNotOverrideAnExplicitAcceptEncoding(): void
    {
        Http::serve(function (int $port): void {
            $request = new Request\Factory()
                ->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/gzip')
                ->withHeader(Header::ACCEPT_ENCODING, 'identity');
            $client = $this->createAdapter();

            $response = $this->send($client, $request);

            // The caller's identity is left untouched, so the server stays plaintext.
            $this->assertSame('identity', $response->getHeaderLine('X-Accept-Encoding'));
            $this->assertSame(str_repeat('utopia ', 64), (string) $response->getBody());
            $this->assertFalse($response->hasHeader(Header::CONTENT_ENCODING));
        });
    }

    public function testItStreamsResponseBodiesToASink(): void
    {
        Http::serve(function (int $port): void {
            $request = new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/stream');
            $client = $this->createAdapter();

            $received = '';

            $response = $this->sendStream($client, $request, function (string $chunk) use (&$received): void {
                $received .= $chunk;
            });

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame("chunk0\nchunk1\nchunk2\nchunk3\nchunk4\n", $received);
            $this->assertSame('', (string) $response->getBody());
        });
    }

    public function testItStreamsLargeResponsesWithBoundedMemory(): void
    {
        Http::serve(function (int $port): void {
            $request = new Request\Factory()->createRequest(Method::GET, 'http://127.0.0.1:' . $port . '/stream-large');
            $client = $this->createAdapter();

            $expected = self::PAYLOAD_SIZE;
            $hash = hash_init('sha256');
            $read = 0;
            $baseline = memory_get_usage();
            $peak = 0;

            $response = $this->sendStream($client, $request, function (string $chunk) use ($hash, &$read, $baseline, &$peak): void {
                hash_update($hash, $chunk);
                $read += \strlen($chunk);
                $peak = max($peak, memory_get_usage() - $baseline);
            });

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame($expected, $read);
            $this->assertSame(hash('sha256', str_repeat('a', $expected)), hash_final($hash));
            $this->assertLessThan(2 * 1_048_576, $peak, 'Streaming must not buffer the whole body.');
        });
    }

    protected function writeTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'utopia-upload-');

        if ($path === false) {
            self::fail('Unable to create a temporary upload file.');
        }

        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * Write a file of $size bytes without holding it in memory, so it can be
     * uploaded to prove streaming keeps memory bounded.
     */
    protected function writeLargeTempFile(int $size): string
    {
        $path = tempnam(sys_get_temp_dir(), 'utopia-upload-');

        if ($path === false) {
            self::fail('Unable to create a temporary upload file.');
        }

        $handle = fopen($path, 'wb');

        if (!\is_resource($handle)) {
            self::fail('Unable to open the temporary upload file.');
        }

        $chunk = str_repeat('a', 65_536);

        for ($written = 0; $written < $size; $written += \strlen($chunk)) {
            fwrite($handle, $chunk);
        }

        fclose($handle);

        return $path;
    }

    private function send(Adapter $client, RequestInterface $request): ResponseInterface
    {
        $response = null;
        $thrown = null;

        $this->runAdapter(function () use ($client, $request, &$response, &$thrown): void {
            try {
                $response = $client->sendRequest($request);
            } catch (Throwable $throwable) {
                $thrown = $throwable;
            }
        });

        if ($thrown instanceof Throwable) {
            throw $thrown;
        }

        if (!$response instanceof ResponseInterface) {
            self::fail('Adapter did not return a response.');
        }

        return $response;
    }

    /**
     * @param callable(string): void $sink
     */
    private function sendStream(Adapter $client, RequestInterface $request, callable $sink): ResponseInterface
    {
        $response = null;
        $thrown = null;

        $this->runAdapter(function () use ($client, $request, $sink, &$response, &$thrown): void {
            try {
                $response = $client->stream($request, $sink);
            } catch (Throwable $throwable) {
                $thrown = $throwable;
            }
        });

        if ($thrown instanceof Throwable) {
            throw $thrown;
        }

        if (!$response instanceof ResponseInterface) {
            self::fail('Adapter did not return a response.');
        }

        return $response;
    }
}
