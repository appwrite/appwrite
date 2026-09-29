<?php

declare(strict_types=1);

namespace Utopia\Storage\Tests\Device\S3;

use PHPUnit\Framework\TestCase;
use Utopia\Client\Exception\ConnectionException;
use Utopia\Client\Exception\DnsException;
use Utopia\Client\Exception\TimeoutException;
use Utopia\Client\Exception\TlsException;
use Utopia\Psr7\Request;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Psr7\Uri;
use Utopia\Storage\Device\S3\RetryStrategy;

final class RetryStrategyTest extends TestCase
{
    private function request(string $method = 'PUT'): Request
    {
        return new Request($method, Uri::parse('https://s3.example.com/root/file.txt'));
    }

    private function response(int $status, string $body = ''): Response
    {
        return new Response($status, body: new Stream($body));
    }

    public function testTransientXmlErrorIsRetried(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><Error><Code>SlowDown</Code><Message>Please reduce your request rate.</Message></Error>';
        $strategy = new RetryStrategy(delay: 0.5, randomizer: static fn (): float => 1.0);

        $this->assertEqualsWithDelta(0.5, $strategy->delay($this->request(), 1, $this->response(503, $body), null), PHP_FLOAT_EPSILON);
    }

    /** The backoff window doubles per attempt (full jitter draws uniformly inside it) and is capped by maxDelay. */
    public function testBackoffWindowGrowsExponentiallyWithJitter(): void
    {
        $strategy = new RetryStrategy(retries: 4, delay: 0.5, maxDelay: 1.5, randomizer: static fn (): float => 1.0);
        $response = $this->response(503);

        $this->assertEqualsWithDelta(0.5, $strategy->delay($this->request(), 1, $response, null), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(1.0, $strategy->delay($this->request(), 2, $response, null), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(1.5, $strategy->delay($this->request(), 3, $response, null), PHP_FLOAT_EPSILON);

        $jittered = new RetryStrategy(delay: 0.5, randomizer: static fn (): float => 0.5);
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

        foreach ([429, 500, 502, 503, 504] as $status) {
            $this->assertNotNull($strategy->delay($this->request(), 1, $this->response($status), null), "HTTP {$status}");
        }
        $this->assertNull($strategy->delay($this->request(), 1, $this->response(501), null));
    }

    /** Backblaze B2 answers a failed part or completion with InternalError and the message "internal incident". */
    public function testInternalErrorIsRetried(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><Error><Code>InternalError</Code><Message>internal incident</Message></Error>';

        $this->assertNotNull(new RetryStrategy()->delay($this->request('POST'), 1, $this->response(500, $body), null));
    }

    /** XML error code takes precedence over HTTP status — 503 with non-transient XML must not be retried. */
    public function test503WithNonTransientXmlIsNotRetried(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><Error><Code>AccessDenied</Code><Message>Access denied.</Message></Error>';

        $this->assertNull(new RetryStrategy()->delay($this->request(), 1, $this->response(503, $body), null));
    }

    public function testRetryAfterIsHonouredWithinTheCeiling(): void
    {
        $strategy = new RetryStrategy(delay: 0.5, maxDelay: 5.0, randomizer: static fn (): float => 1.0);

        $this->assertEqualsWithDelta(2.0, $strategy->delay($this->request(), 1, $this->response(503)->withHeader('Retry-After', '2'), null), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(5.0, $strategy->delay($this->request(), 1, $this->response(503)->withHeader('Retry-After', '120'), null), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(0.5, $strategy->delay($this->request(), 1, $this->response(503)->withHeader('Retry-After', 'Wed, 21 Oct 2026 07:28:00 GMT'), null), PHP_FLOAT_EPSILON, 'an HTTP-date falls back to backoff');
    }

    public function testRetriesAreCapped(): void
    {
        $strategy = new RetryStrategy(retries: 2);
        $response = $this->response(503);

        $this->assertNotNull($strategy->delay($this->request(), 1, $response, null));
        $this->assertNotNull($strategy->delay($this->request(), 2, $response, null));
        $this->assertNull($strategy->delay($this->request(), 3, $response, null));
    }

    /** Nothing reached the service, so even a POST cannot have been applied. */
    public function testFailuresBeforeSendingAreRetriedForEveryMethod(): void
    {
        $strategy = new RetryStrategy();
        $post = $this->request('POST');

        $this->assertNotNull($strategy->delay($post, 1, null, new ConnectionException($post, 'Could not connect to server', \CURLE_COULDNT_CONNECT)));
        $this->assertNotNull($strategy->delay($post, 1, null, new DnsException($post, 'Could not resolve host', \CURLE_COULDNT_RESOLVE_HOST)));
    }

    /** A PUT to the same key or part number overwrites itself, so replaying one that may have landed is harmless. */
    public function testDroppedOrTimedOutPutIsRetried(): void
    {
        $strategy = new RetryStrategy();
        $put = $this->request('PUT');

        $this->assertNotNull($strategy->delay($put, 1, null, new ConnectionException($put, 'Connection reset by peer', \CURLE_RECV_ERROR)));
        $this->assertNotNull($strategy->delay($put, 1, null, new TimeoutException($put, 'Operation timed out', \CURLE_OPERATION_TIMEDOUT)));
    }

    /** A POST that may have landed could open a second multipart upload, so it is not replayed. */
    public function testDroppedOrTimedOutPostIsNotRetried(): void
    {
        $strategy = new RetryStrategy();
        $post = $this->request('POST');

        $this->assertNull($strategy->delay($post, 1, null, new ConnectionException($post, 'Connection reset by peer', \CURLE_RECV_ERROR)));
        $this->assertNull($strategy->delay($post, 1, null, new TimeoutException($post, 'Operation timed out', \CURLE_OPERATION_TIMEDOUT)));
    }

    public function testTlsFailureIsNotRetried(): void
    {
        $put = $this->request('PUT');

        $this->assertNull(new RetryStrategy()->delay($put, 1, null, new TlsException($put, 'SSL connect error', \CURLE_SSL_CONNECT_ERROR)));
    }

    public function testTransportRetriesAreCapped(): void
    {
        $put = $this->request('PUT');
        $error = new TimeoutException($put, 'Operation timed out', \CURLE_OPERATION_TIMEDOUT);
        $strategy = new RetryStrategy(retries: 1);

        $this->assertNotNull($strategy->delay($put, 1, null, $error));
        $this->assertNull($strategy->delay($put, 2, null, $error));
    }

    public function testReadingTheBodyLeavesItReadable(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><Error><Code>SlowDown</Code></Error>';
        $response = $this->response(503, $body);

        $this->assertNotNull(new RetryStrategy()->delay($this->request(), 1, $response, null));
        $this->assertSame($body, (string) $response->getBody());
    }
}
