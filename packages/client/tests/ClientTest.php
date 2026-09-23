<?php

declare(strict_types=1);

namespace Utopia\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client;
use Utopia\Client\Adapter;
use Utopia\Client\Tls;
use Utopia\Psr7\Request;
use Utopia\Psr7\Response;
use Utopia\Span\Span;
use Utopia\Span\Storage\Memory;
use ValueError;

final class ClientTest extends TestCase
{
    public function testItDecoratesConfigurableAdapters(): void
    {
        $request = new Request\Factory()->createRequest('GET', 'https://example.com');
        $adapter = new RecordingAdapter();
        $client = new Client($adapter);
        $configured = $client
            ->withTimeout(5.5)
            ->withConnectTimeout(1.25);

        $response = $configured->sendRequest($request);

        $this->assertSame('', $client->sendRequest($request)->getHeaderLine('X-Timeout'));
        $this->assertSame('5.5', $response->getHeaderLine('X-Timeout'));
        $this->assertSame('1.25', $response->getHeaderLine('X-Connect-Timeout'));
    }

    public function testItDecoratesTlsConfiguration(): void
    {
        $request = new Request\Factory()->createRequest('GET', 'https://example.com');
        $client = new Client(new RecordingAdapter());
        $configured = $client
            ->withSslVerification(false)
            ->withCustomCA('/etc/ssl/ca.pem')
            ->withCertificate('/etc/ssl/client.pem', '/etc/ssl/client.key', 'secret')
            ->withMinTlsVersion(Tls::V1_2);

        $response = $configured->sendRequest($request);

        $this->assertSame('', $client->sendRequest($request)->getHeaderLine('X-Tls-Verify'));
        $this->assertSame('off', $response->getHeaderLine('X-Tls-Verify'));
        $this->assertSame('/etc/ssl/ca.pem', $response->getHeaderLine('X-Tls-Ca'));
        $this->assertSame('/etc/ssl/client.pem:/etc/ssl/client.key:secret', $response->getHeaderLine('X-Tls-Cert'));
        $this->assertSame('V1_2', $response->getHeaderLine('X-Tls-Min-Version'));
    }

    public function testItDecoratesConnectionReuse(): void
    {
        $request = new Request\Factory()->createRequest('GET', 'https://example.com');
        $client = new Client(new RecordingAdapter());
        $configured = $client->withConnectionReuse();

        $this->assertSame('', $client->sendRequest($request)->getHeaderLine('X-Connection-Reuse'));
        $this->assertSame('on', $configured->sendRequest($request)->getHeaderLine('X-Connection-Reuse'));
        $this->assertSame('off', $client->withConnectionReuse(false)->sendRequest($request)->getHeaderLine('X-Connection-Reuse'));
    }

    public function testItDecoratesFollowRedirects(): void
    {
        $request = new Request\Factory()->createRequest('GET', 'https://example.com');
        $client = new Client(new RecordingAdapter());
        $configured = $client->withFollowRedirects();

        $this->assertSame('', $client->sendRequest($request)->getHeaderLine('X-Follow-Redirects'));
        $this->assertSame('on', $configured->sendRequest($request)->getHeaderLine('X-Follow-Redirects'));
        $this->assertSame('off', $client->withFollowRedirects(false)->sendRequest($request)->getHeaderLine('X-Follow-Redirects'));
    }

    public function testItRejectsInvalidTimeouts(): void
    {
        $client = new Client(new RecordingAdapter());

        $this->expectException(ValueError::class);

        $client->withTimeout(-1);
    }

    public function testItAppliesDefaultHeadersImmutablyWithoutOverridingRequestHeaders(): void
    {
        $requestFactory = new Request\Factory();
        $client = new Client(new RecordingAdapter());
        $configured = $client->withHeaders([
            'Accept' => 'application/json',
            'X-Trace' => ['one', 'two'],
        ]);

        $plain = $client->sendRequest(
            $requestFactory->createRequest('GET', 'https://example.com'),
        );
        $response = $configured->sendRequest(
            $requestFactory->createRequest('GET', 'https://example.com')
                ->withHeader('Accept', 'application/xml'),
        );

        $this->assertSame('', $plain->getHeaderLine('X-Request-Accept'));
        $this->assertSame('application/xml', $response->getHeaderLine('X-Request-Accept'));
        $this->assertSame('one, two', $response->getHeaderLine('X-Request-Trace'));
    }

    public function testItAppliesAuthDefaultsWithoutOverridingRequestAuthorization(): void
    {
        $requestFactory = new Request\Factory();
        $client = new Client(new RecordingAdapter());

        $basic = $client
            ->withBasicAuth('ada', 'secret')
            ->sendRequest($requestFactory->createRequest('GET', 'https://example.com'));
        $bearer = $client
            ->withBearerAuth('token')
            ->sendRequest($requestFactory->createRequest('GET', 'https://example.com'));
        $override = $client
            ->withBearerAuth('token')
            ->sendRequest(
                $requestFactory->createRequest('GET', 'https://example.com')
                    ->withHeader('Authorization', 'Digest custom'),
            );

        $this->assertSame('Basic YWRhOnNlY3JldA==', $basic->getHeaderLine('X-Request-Authorization'));
        $this->assertSame('Bearer token', $bearer->getHeaderLine('X-Request-Authorization'));
        $this->assertSame('Digest custom', $override->getHeaderLine('X-Request-Authorization'));
    }

    public function testItAppliesBaseUriToRelativeRequests(): void
    {
        $requestFactory = new Request\Factory();
        $client = new Client(new RecordingAdapter())
            ->withBaseUri('https://api.example.com/v1');

        $relative = $client->sendRequest(
            $requestFactory->createRequest('GET', 'users?active=1'),
        );
        $absolutePath = $client->sendRequest(
            $requestFactory->createRequest('GET', '/status'),
        );
        $absoluteUri = $client->sendRequest(
            $requestFactory->createRequest('GET', 'https://other.example.com/users'),
        );

        $this->assertSame('https://api.example.com/v1/users?active=1', $relative->getHeaderLine('X-Request-Uri'));
        $this->assertSame('api.example.com', $relative->getHeaderLine('X-Request-Host'));
        $this->assertSame('https://api.example.com/status', $absolutePath->getHeaderLine('X-Request-Uri'));
        $this->assertSame('https://other.example.com/users', $absoluteUri->getHeaderLine('X-Request-Uri'));
    }

    public function testItRejectsRelativeBaseUris(): void
    {
        $client = new Client(new RecordingAdapter());

        $this->expectException(InvalidArgumentException::class);

        $client->withBaseUri('/api');
    }

    public function testItPropagatesTheActiveTraceWithoutOverridingAnInboundOne(): void
    {
        $requestFactory = new Request\Factory();
        $client = new Client(new RecordingAdapter())->withTracePropagation();

        Span::setStorage(new Memory());
        $span = Span::init('http.request');

        try {
            $propagated = $client->sendRequest(
                $requestFactory->createRequest('GET', 'https://example.com'),
            );
            $forwarded = $client->sendRequest(
                $requestFactory->createRequest('GET', 'https://example.com')
                    ->withHeader('traceparent', 'incoming'),
            );

            $this->assertSame($span->getTraceparent(), $propagated->getHeaderLine('X-Request-Traceparent'));
            $this->assertSame('incoming', $forwarded->getHeaderLine('X-Request-Traceparent'));
        } finally {
            $span->finish();
            Span::setStorage(null);
        }
    }

    public function testItDoesNotPropagateTracesByDefault(): void
    {
        $requestFactory = new Request\Factory();
        $client = new Client(new RecordingAdapter());

        Span::setStorage(new Memory());
        $span = Span::init('http.request');

        try {
            $response = $client->sendRequest(
                $requestFactory->createRequest('GET', 'https://example.com'),
            );

            $this->assertSame('', $response->getHeaderLine('X-Request-Traceparent'));
        } finally {
            $span->finish();
            Span::setStorage(null);
        }
    }

    public function testItLeavesRequestsUntouchedWithoutAnActiveSpan(): void
    {
        $requestFactory = new Request\Factory();
        $client = new Client(new RecordingAdapter())->withTracePropagation();

        $response = $client->sendRequest(
            $requestFactory->createRequest('GET', 'https://example.com'),
        );

        $this->assertSame('', $response->getHeaderLine('X-Request-Traceparent'));
    }

    public function testItStreamsThroughTheAdapterApplyingBaseUriAndHeaders(): void
    {
        $requestFactory = new Request\Factory();
        $received = '';
        $client = new Client(new RecordingAdapter())
            ->withBaseUri('https://api.example.com/v1')
            ->withHeaders(['Accept' => 'application/json']);

        $response = $client->stream(
            $requestFactory->createRequest('GET', 'users'),
            function (string $chunk) use (&$received): void {
                $received .= $chunk;
            },
        );

        $this->assertSame('chunk', $received);
        $this->assertSame('https://api.example.com/v1/users', $response->getHeaderLine('X-Request-Uri'));
        $this->assertSame('application/json', $response->getHeaderLine('X-Request-Accept'));
    }
}

final class RecordingAdapter implements Adapter
{
    public function __construct(
        private ?float $timeout = null,
        private ?float $connectTimeout = null,
        private ?bool $sslVerification = null,
        private ?string $customCA = null,
        private ?string $certificate = null,
        private ?Tls $minTlsVersion = null,
        private ?bool $connectionReuse = null,
        private ?bool $followRedirects = null,
    ) {}

    public function withTimeout(float $seconds): static
    {
        if ($seconds < 0.0 || !is_finite($seconds)) {
            throw new ValueError('Timeout must be a finite number greater than or equal to zero.');
        }

        $clone = clone $this;
        $clone->timeout = $seconds;

        return $clone;
    }

    public function withConnectTimeout(float $seconds): static
    {
        if ($seconds < 0.0 || !is_finite($seconds)) {
            throw new ValueError('Timeout must be a finite number greater than or equal to zero.');
        }

        $clone = clone $this;
        $clone->connectTimeout = $seconds;

        return $clone;
    }

    public function withSslVerification(bool $enabled = true): static
    {
        $clone = clone $this;
        $clone->sslVerification = $enabled;

        return $clone;
    }

    public function withCustomCA(string $path): static
    {
        $clone = clone $this;
        $clone->customCA = $path;

        return $clone;
    }

    public function withCertificate(string $certPath, string $keyPath, ?string $passphrase = null): static
    {
        $clone = clone $this;
        $clone->certificate = $certPath . ':' . $keyPath . ($passphrase === null ? '' : ':' . $passphrase);

        return $clone;
    }

    public function withMinTlsVersion(Tls $version): static
    {
        $clone = clone $this;
        $clone->minTlsVersion = $version;

        return $clone;
    }

    public function withConnectionReuse(bool $enabled = true): static
    {
        $clone = clone $this;
        $clone->connectionReuse = $enabled;

        return $clone;
    }

    public function withFollowRedirects(bool $enabled = true): static
    {
        $clone = clone $this;
        $clone->followRedirects = $enabled;

        return $clone;
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = new Response\Factory()
            ->createResponse()
            ->withHeader('X-Request-Uri', (string) $request->getUri())
            ->withHeader('X-Request-Host', $request->getHeaderLine('Host'))
            ->withHeader('X-Request-Accept', $request->getHeaderLine('Accept'))
            ->withHeader('X-Request-Authorization', $request->getHeaderLine('Authorization'))
            ->withHeader('X-Request-Trace', $request->getHeaderLine('X-Trace'))
            ->withHeader('X-Request-Traceparent', $request->getHeaderLine('traceparent'));

        if ($this->timeout !== null) {
            $response = $response->withHeader('X-Timeout', (string) $this->timeout);
        }

        if ($this->sslVerification !== null) {
            $response = $response->withHeader('X-Tls-Verify', $this->sslVerification ? 'on' : 'off');
        }

        if ($this->customCA !== null) {
            $response = $response->withHeader('X-Tls-Ca', $this->customCA);
        }

        if ($this->certificate !== null) {
            $response = $response->withHeader('X-Tls-Cert', $this->certificate);
        }

        if ($this->minTlsVersion instanceof \Utopia\Client\Tls) {
            $response = $response->withHeader('X-Tls-Min-Version', $this->minTlsVersion->name);
        }

        if ($this->connectionReuse !== null) {
            $response = $response->withHeader('X-Connection-Reuse', $this->connectionReuse ? 'on' : 'off');
        }

        if ($this->followRedirects !== null) {
            $response = $response->withHeader('X-Follow-Redirects', $this->followRedirects ? 'on' : 'off');
        }

        if ($this->connectTimeout !== null) {
            return $response->withHeader('X-Connect-Timeout', (string) $this->connectTimeout);
        }

        return $response;
    }

    /**
     * @param callable(string): void $sink
     *
     * @throws ClientExceptionInterface
     */
    public function stream(RequestInterface $request, callable $sink): ResponseInterface
    {
        $sink('chunk');

        return $this->sendRequest($request);
    }
}
