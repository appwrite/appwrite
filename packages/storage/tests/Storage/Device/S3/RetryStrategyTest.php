<?php

declare(strict_types=1);

namespace Utopia\Tests\Storage\Device\S3;

use PHPUnit\Framework\TestCase;
use Utopia\Client\Exception\NetworkException;
use Utopia\Psr7\Request;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Psr7\Uri;
use Utopia\Storage\Device\S3\RetryStrategy;

final class RetryStrategyTest extends TestCase
{
    private function request(): Request
    {
        return new Request('PUT', Uri::parse('https://s3.example.com/root/file.txt'));
    }

    private function response(int $status, string $body = ''): Response
    {
        return new Response($status, body: new Stream($body));
    }

    public function testTransientXmlErrorIsRetried(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><Error><Code>SlowDown</Code><Message>Please reduce your request rate.</Message></Error>';
        $strategy = new RetryStrategy(delay: 0.5, randomizer: static fn(): float => 1.0);

        $this->assertEqualsWithDelta(0.5, $strategy->delay($this->request(), 1, $this->response(503, $body), null), PHP_FLOAT_EPSILON);
    }

    /** The backoff window doubles per attempt (full jitter draws uniformly inside it) and is capped by maxDelay. */
    public function testBackoffWindowGrowsExponentiallyWithJitter(): void
    {
        $strategy = new RetryStrategy(retries: 4, delay: 0.5, maxDelay: 1.5, randomizer: static fn(): float => 1.0);
        $response = $this->response(503);

        $this->assertEqualsWithDelta(0.5, $strategy->delay($this->request(), 1, $response, null), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(1.0, $strategy->delay($this->request(), 2, $response, null), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(1.5, $strategy->delay($this->request(), 3, $response, null), PHP_FLOAT_EPSILON);

        $jittered = new RetryStrategy(delay: 0.5, randomizer: static fn(): float => 0.5);
        $this->assertEqualsWithDelta(0.25, $jittered->delay($this->request(), 1, $response, null), PHP_FLOAT_EPSILON);
    }

    public function testNonTransientXmlErrorIsNotRetried(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><Error><Code>NoSuchKey</Code><Message>The specified key does not exist.</Message></Error>';

        $this->assertNull(new RetryStrategy()->delay($this->request(), 1, $this->response(404, $body), null));
    }

    public function testStatusFallbackIsTransient(): void
    {
        $strategy = new RetryStrategy();

        $this->assertNotNull($strategy->delay($this->request(), 1, $this->response(429), null));
        $this->assertNotNull($strategy->delay($this->request(), 1, $this->response(503), null));
    }

    /** XML error code takes precedence over HTTP status — 503 with non-transient XML must not be retried. */
    public function test503WithNonTransientXmlIsNotRetried(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><Error><Code>InternalError</Code><Message>Internal server error.</Message></Error>';

        $this->assertNull(new RetryStrategy()->delay($this->request(), 1, $this->response(503, $body), null));
    }

    public function testRetriesAreCapped(): void
    {
        $strategy = new RetryStrategy(retries: 2);
        $response = $this->response(503);

        $this->assertNotNull($strategy->delay($this->request(), 1, $response, null));
        $this->assertNotNull($strategy->delay($this->request(), 2, $response, null));
        $this->assertNull($strategy->delay($this->request(), 3, $response, null));
    }

    public function testTransportErrorsAreNotRetried(): void
    {
        $error = new NetworkException($this->request(), 'Connection reset');

        $this->assertNull(new RetryStrategy()->delay($this->request(), 1, null, $error));
    }

    public function testReadingTheBodyLeavesItReadable(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><Error><Code>SlowDown</Code></Error>';
        $response = $this->response(503, $body);

        $this->assertNotNull(new RetryStrategy()->delay($this->request(), 1, $response, null));
        $this->assertSame($body, (string) $response->getBody());
    }
}
