<?php

declare(strict_types=1);

namespace Utopia\Tests\Client\Decorator;

use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client\Adapter;
use Utopia\Client\Decorator\Retry;
use Utopia\Client\Decorator\Retry\Backoff;
use Utopia\Client\Exception\InvalidUriException;
use Utopia\Client\Exception\NetworkException;
use Utopia\Client\Tls;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request;
use Utopia\Psr7\Response;

final class RetryTest extends TestCase
{
    public function testItRetriesTransientFailuresUntilSuccess(): void
    {
        $request = $this->request(Method::GET);
        $inner = new QueueAdapter([
            fn() => throw new NetworkException($request, 'reset'),
            fn() => throw new NetworkException($request, 'reset'),
            fn(): \Utopia\Psr7\Response => new Response(200),
        ]);
        $delays = [];

        $response = $this->retry($inner, $delays)->sendRequest($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(3, $inner->calls);
        $this->assertSame([0.1, 0.2], $delays);
    }

    public function testItStopsAndRethrowsAfterExhaustingAttempts(): void
    {
        $request = $this->request(Method::GET);
        $inner = new QueueAdapter(array_fill(0, 3, fn() => throw new NetworkException($request, 'reset')));
        $delays = [];

        try {
            $this->retry($inner, $delays)->sendRequest($request);
            $this->fail('Expected the final failure to be rethrown.');
        } catch (NetworkException) {
            $this->assertSame(3, $inner->calls);
            $this->assertSame([0.1, 0.2], $delays);
        }
    }

    public function testItDoesNotRetryRequestExceptions(): void
    {
        $request = $this->request(Method::GET);
        $inner = new QueueAdapter([fn() => throw new InvalidUriException($request, 'bad')]);
        $delays = [];

        $this->expectException(InvalidUriException::class);

        try {
            $this->retry($inner, $delays)->sendRequest($request);
        } finally {
            $this->assertSame(1, $inner->calls);
            $this->assertSame([], $delays);
        }
    }

    public function testItDoesNotRetryNonIdempotentMethods(): void
    {
        $request = $this->request(Method::POST);
        $inner = new QueueAdapter([fn() => throw new NetworkException($request, 'reset')]);
        $delays = [];

        $this->expectException(NetworkException::class);

        try {
            $this->retry($inner, $delays)->sendRequest($request);
        } finally {
            $this->assertSame(1, $inner->calls);
        }
    }

    public function testItRetriesOverloadedStatusResponses(): void
    {
        $request = $this->request(Method::GET);
        $inner = new QueueAdapter([
            fn(): \Utopia\Psr7\Response => new Response(503),
            fn(): \Utopia\Psr7\Response => new Response(200),
        ]);
        $delays = [];

        $response = $this->retry($inner, $delays)->sendRequest($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $inner->calls);
        $this->assertSame([0.1], $delays);
    }

    public function testItRetriesStreamsOnlyWhenNoBytesWereDelivered(): void
    {
        $request = $this->request(Method::GET);
        $inner = new QueueAdapter([
            function (callable $sink) use ($request): ResponseInterface {
                throw new NetworkException($request, 'reset'); // no bytes emitted
            },
            function (callable $sink): ResponseInterface {
                $sink('hello');

                return new Response(200);
            },
        ]);
        $delays = [];
        $received = '';

        $response = $this->retry($inner, $delays)->stream($request, function (string $chunk) use (&$received): void {
            $received .= $chunk;
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('hello', $received);
        $this->assertSame(2, $inner->calls);
    }

    public function testItDoesNotRetryStreamsAfterBytesWereDelivered(): void
    {
        $request = $this->request(Method::GET);
        $inner = new QueueAdapter([
            function (callable $sink) use ($request): ResponseInterface {
                $sink('partial');

                throw new NetworkException($request, 'reset');
            },
        ]);
        $delays = [];
        $received = '';

        try {
            $this->retry($inner, $delays)->stream($request, function (string $chunk) use (&$received): void {
                $received .= $chunk;
            });
            $this->fail('Expected the failure to be rethrown without retrying.');
        } catch (NetworkException) {
            $this->assertSame('partial', $received);
            $this->assertSame(1, $inner->calls);
        }
    }

    public function testItForwardsConfigurationToTheInnerAdapter(): void
    {
        $inner = new QueueAdapter([]);
        $retry = new Retry($inner);

        $configured = $retry
            ->withTimeout(5)
            ->withConnectTimeout(1)
            ->withSslVerification(false)
            ->withCustomCA('/etc/ssl/ca.pem')
            ->withCertificate('/etc/ssl/client.pem', '/etc/ssl/client.key')
            ->withMinTlsVersion(Tls::V1_2);

        $this->assertNotSame($retry, $configured);
        $this->assertInstanceOf(Retry::class, $configured);
    }

    /**
     * @param array<int, float> $delays
     */
    private function retry(Adapter $inner, array &$delays): Retry
    {
        return new Retry(
            $inner,
            new Backoff(randomizer: static fn(): float => 1.0),
            function (float $seconds) use (&$delays): void {
                $delays[] = $seconds;
            },
        );
    }

    private function request(string $method): RequestInterface
    {
        return new Request\Factory()->createRequest($method, 'https://example.com/resource');
    }
}

final class QueueAdapter implements Adapter
{
    public int $calls = 0;

    /**
     * @param array<int, callable(callable(string): void): ResponseInterface> $outcomes
     */
    public function __construct(private array $outcomes) {}

    public function withTimeout(float $seconds): static
    {
        return $this;
    }

    public function withConnectTimeout(float $seconds): static
    {
        return $this;
    }

    public function withSslVerification(bool $enabled = true): static
    {
        return $this;
    }

    public function withCustomCA(string $path): static
    {
        return $this;
    }

    public function withCertificate(string $certPath, string $keyPath, ?string $passphrase = null): static
    {
        return $this;
    }

    public function withMinTlsVersion(Tls $version): static
    {
        return $this;
    }

    public function withConnectionReuse(bool $enabled = true): static
    {
        return $this;
    }

    public function withFollowRedirects(bool $enabled = true): static
    {
        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->next(static function (string $chunk): void {});
    }

    public function stream(RequestInterface $request, callable $sink): ResponseInterface
    {
        return $this->next($sink);
    }

    /**
     * @param callable(string): void $sink
     */
    private function next(callable $sink): ResponseInterface
    {
        $outcome = $this->outcomes[$this->calls] ?? throw new LogicException('No more queued outcomes.');
        $this->calls++;

        return $outcome($sink);
    }
}
