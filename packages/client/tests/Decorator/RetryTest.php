<?php

declare(strict_types=1);

namespace Utopia\Client\Tests\Decorator;

use Closure;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client\Adapter;
use Utopia\Client\Decorator\Retry;
use Utopia\Client\Decorator\Retry\Backoff;
use Utopia\Client\Decorator\Retry\Strategy;
use Utopia\Client\Exception\InvalidUriException;
use Utopia\Client\Exception\NetworkException;
use Utopia\Client\Tls;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request;
use Utopia\Psr7\Response;

final class RetryTest extends TestCase
{
    private const string ENVOY_RESET = 'upstream connect error or disconnect/reset before headers. reset reason: connection termination';

    public function testUnsupportedResponseStreamingDoesNotSpendRetryBudget(): void
    {
        $inner = new QueueAdapter([]);
        $strategy = new class () implements \Utopia\Client\Decorator\Retry\Strategy {
            public int $calls = 0;

            public function delay(RequestInterface $request, int $attempt, ?ResponseInterface $response, ?\Psr\Http\Client\ClientExceptionInterface $error): ?float
            {
                $this->calls++;
                return $attempt < 3 ? 0.01 : null;
            }
        };
        $delays = [];
        $retry = new Retry($inner, $strategy, static function (float $seconds) use (&$delays): void {
            $delays[] = $seconds;
        });
        try {
            $retry->stream($this->request(Method::GET), static function (string $chunk): void {
            }, static function (ResponseInterface $response): void {
            });
            $this->fail('Expected unsupported response streaming to be refused');
        } catch (\Utopia\Client\Exception\AdapterPreconditionException) {
        }

        $this->assertSame(0, $inner->calls);
        $this->assertSame(0, $strategy->calls);
        $this->assertSame([], $delays);
    }

    public function testItRetriesTransientFailuresUntilSuccess(): void
    {
        $request = $this->request(Method::GET);
        $inner = new QueueAdapter([
            fn (): never => throw new NetworkException($request, 'reset'),
            fn (): never => throw new NetworkException($request, 'reset'),
            fn (): \Utopia\Psr7\Response => new Response(200),
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
        $inner = new QueueAdapter(array_fill(0, 3, fn (): never => throw new NetworkException($request, 'reset')));
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
        $inner = new QueueAdapter([fn (): never => throw new InvalidUriException($request, 'bad')]);
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
        $inner = new QueueAdapter([fn (): never => throw new NetworkException($request, 'reset')]);
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
            fn (): \Utopia\Psr7\Response => new Response(503),
            fn (): \Utopia\Psr7\Response => new Response(200),
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

    public function testItRetriesAStreamedErrorResponseWhoseBodyWasHeld(): void
    {
        $inner = new ResponseStreamingQueueAdapter([
            $this->respond(503, self::ENVOY_RESET),
            $this->respond(200, 'hello'),
        ]);
        $delays = [];
        $received = '';

        $response = $this->retry($inner, $delays)->stream($this->request(Method::GET), function (string $chunk) use (&$received): void {
            $received .= $chunk;
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('hello', $received);
        $this->assertSame(2, $inner->calls);
        $this->assertSame([0.1], $delays);
    }

    public function testItRetriesAStreamedErrorResponseAndNotifiesTheCallerOncePerAttempt(): void
    {
        $inner = new ResponseStreamingQueueAdapter([
            $this->respond(503, self::ENVOY_RESET),
            $this->respond(200, 'hello'),
        ]);
        $delays = [];
        $received = '';
        $statuses = [];

        $response = $this->retry($inner, $delays)->stream(
            $this->request(Method::GET),
            function (string $chunk) use (&$received): void {
                $received .= $chunk;
            },
            function (ResponseInterface $response) use (&$statuses): void {
                $statuses[] = $response->getStatusCode();
            },
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('hello', $received);
        $this->assertSame([503, 200], $statuses);
        $this->assertSame(2, $inner->calls);
        $this->assertSame([0.1], $delays);
    }

    public function testItDeliversANonRetryableErrorBodyOnce(): void
    {
        $body = '<Error><Code>NoSuchKey</Code></Error>';
        $inner = new ResponseStreamingQueueAdapter([$this->respond(404, $body)]);
        $delays = [];
        $received = '';

        $response = $this->retry($inner, $delays)->stream($this->request(Method::GET), function (string $chunk) use (&$received): void {
            $received .= $chunk;
        });

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame($body, $received);
        $this->assertSame(1, $inner->calls);
        $this->assertSame([], $delays);
    }

    public function testItDeliversOnlyTheFinalErrorBodyWhenRetriesRunOut(): void
    {
        $inner = new ResponseStreamingQueueAdapter([
            $this->respond(503, 'a1'),
            $this->respond(503, 'a2'),
            $this->respond(503, 'a3'),
        ]);
        $delays = [];
        $received = '';

        $response = $this->retry($inner, $delays)->stream($this->request(Method::GET), function (string $chunk) use (&$received): void {
            $received .= $chunk;
        });

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('a3', $received);
        $this->assertSame(3, $inner->calls);
        $this->assertSame([0.1, 0.2], $delays);
    }

    public function testTheStrategySeesTheHeldErrorBody(): void
    {
        $body = '<Error><Code>InternalError</Code></Error>';
        $inner = new ResponseStreamingQueueAdapter([$this->respond(500, $body)]);
        $strategy = new class () implements Strategy {
            /**
             * @var array<int, array{status: int, body: string}>
             */
            public array $responses = [];

            public function delay(RequestInterface $request, int $attempt, ?ResponseInterface $response, ?ClientExceptionInterface $error): ?float
            {
                if ($response instanceof ResponseInterface) {
                    $this->responses[] = [
                        'status' => $response->getStatusCode(),
                        'body' => (string) $response->getBody(),
                    ];
                }

                return null;
            }
        };
        $received = '';

        $response = new Retry($inner, $strategy, static function (float $seconds): void {
        })->stream($this->request(Method::GET), function (string $chunk) use (&$received): void {
            $received .= $chunk;
        });

        $this->assertSame([['status' => 500, 'body' => $body]], $strategy->responses);
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame($body, $received);
        $this->assertSame(1, $inner->calls);
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
            new Backoff(randomizer: static fn (): float => 1.0),
            function (float $seconds) use (&$delays): void {
                $delays[] = $seconds;
            },
        );
    }

    /**
     * @return Closure(callable(string): void, (callable(ResponseInterface): void)|null): ResponseInterface
     */
    private function respond(int $status, string $body): Closure
    {
        return static function (callable $sink, ?callable $onResponse) use ($status, $body): ResponseInterface {
            if ($onResponse !== null) {
                $onResponse(new Response($status));
            }

            $sink($body);

            return new Response($status);
        };
    }

    private function request(string $method): RequestInterface
    {
        return new Request\Factory()->createRequest($method, 'https://example.com/resource');
    }
}
