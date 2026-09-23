<?php

declare(strict_types=1);

namespace Utopia\Tests\Client\Decorator\Retry;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Utopia\Client\Decorator\Retry\Backoff;
use Utopia\Client\Exception\InvalidUriException;
use Utopia\Client\Exception\NetworkException;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request;
use Utopia\Psr7\Response;

final class BackoffTest extends TestCase
{
    public function testItRetriesTransientTransportFailures(): void
    {
        $request = $this->request(Method::GET);

        $delay = $this->strategy()->delay($request, 1, null, new NetworkException($request, 'reset'));

        $this->assertEqualsWithDelta(0.1, $delay, PHP_FLOAT_EPSILON);
    }

    public function testItDoesNotRetryRequestExceptions(): void
    {
        $request = $this->request(Method::GET);

        $this->assertNull($this->strategy()->delay($request, 1, null, new InvalidUriException($request, 'bad')));
    }

    public function testItDoesNotRetryNonIdempotentMethods(): void
    {
        $request = $this->request(Method::POST);

        $this->assertNull($this->strategy()->delay($request, 1, null, new NetworkException($request, 'reset')));
    }

    public function testItDoesNotRetrySuccessfulResponses(): void
    {
        $request = $this->request(Method::GET);

        $this->assertNull($this->strategy()->delay($request, 1, new Response(200), null));
    }

    public function testItRetriesOverloadedStatusResponses(): void
    {
        $request = $this->request(Method::GET);

        $this->assertEqualsWithDelta(0.1, $this->strategy()->delay($request, 1, new Response(503), null), PHP_FLOAT_EPSILON);
    }

    public function testItStopsAtMaxAttempts(): void
    {
        $request = $this->request(Method::GET);

        $this->assertNull($this->strategy()->delay($request, 3, null, new NetworkException($request, 'reset')));
    }

    public function testItGrowsTheDelayExponentially(): void
    {
        $request = $this->request(Method::GET);
        $strategy = $this->strategy();
        $error = new NetworkException($request, 'reset');

        $this->assertEqualsWithDelta(0.1, $strategy->delay($request, 1, null, $error), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(0.2, $strategy->delay($request, 2, null, $error), PHP_FLOAT_EPSILON);
    }

    public function testItHonoursNumericRetryAfterCappedToMaxDelay(): void
    {
        $request = $this->request(Method::GET);
        $response = new Response(503)->withHeader('Retry-After', '999');

        $this->assertEqualsWithDelta(10.0, $this->strategy()->delay($request, 1, $response, null), PHP_FLOAT_EPSILON);
    }

    public function testItIgnoresNonNumericRetryAfter(): void
    {
        $request = $this->request(Method::GET);
        $response = new Response(503)->withHeader('Retry-After', 'Wed, 21 Oct 2025 07:28:00 GMT');

        $this->assertEqualsWithDelta(0.1, $this->strategy()->delay($request, 1, $response, null), PHP_FLOAT_EPSILON);
    }

    public function testItAppliesFullJitterWithinTheCeiling(): void
    {
        $request = $this->request(Method::GET);
        $strategy = new Backoff(); // real randomizer
        $error = new NetworkException($request, 'reset');

        for ($i = 0; $i < 50; $i++) {
            $delay = $strategy->delay($request, 1, null, $error);

            $this->assertNotNull($delay);
            $this->assertGreaterThanOrEqual(0.0, $delay);
            $this->assertLessThanOrEqual(0.1, $delay);
        }
    }

    private function strategy(): Backoff
    {
        return new Backoff(randomizer: static fn(): float => 1.0);
    }

    private function request(string $method): RequestInterface
    {
        return new Request\Factory()->createRequest($method, 'https://example.com/resource');
    }
}
