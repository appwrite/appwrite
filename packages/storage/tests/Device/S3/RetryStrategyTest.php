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
    private function request(string $method = 'PUT', string $query = ''): Request
    {
        return new Request($method, Uri::parse('https://s3.example.com/root/file.txt' . ($query === '' ? '' : '?' . $query)));
    }

    private function internalError(): Response
    {
        return $this->response(500, '<?xml version="1.0" encoding="UTF-8"?><Error><Code>InternalError</Code><Message>internal incident</Message></Error>');
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

    public function testInternalErrorIsRetried(): void
    {
        $strategy = new RetryStrategy();

        $this->assertNotNull($strategy->delay($this->request('PUT', 'partNumber=2&uploadId=upload-1'), 1, $this->internalError(), null));
        $this->assertNotNull($strategy->delay($this->request('POST', 'uploadId=upload-1'), 1, $this->internalError(), null), 'completion');
    }

    public function testMaybeAppliedMultipartCreationIsNotRetried(): void
    {
        $strategy = new RetryStrategy();
        $create = $this->request('POST', 'uploads=');

        $this->assertNull($strategy->delay($create, 1, $this->internalError(), null));
        $this->assertNull($strategy->delay($create, 1, null, new TimeoutException($create, 'Operation timed out', \CURLE_OPERATION_TIMEDOUT)));
        $this->assertNotNull($strategy->delay($create, 1, $this->response(503), null), 'throttled, so never applied');
    }

    public function testMaybeAppliedBatchDeleteIsNotRetried(): void
    {
        // A replay could delete an object written under a listed key since the first attempt.
        $strategy = new RetryStrategy();
        $delete = $this->request('POST', 'delete=');

        $this->assertNull($strategy->delay($delete, 1, $this->internalError(), null));
        $this->assertNull($strategy->delay($delete, 1, null, new TimeoutException($delete, 'Operation timed out', \CURLE_OPERATION_TIMEDOUT)));
        $this->assertNotNull($strategy->delay($delete, 1, $this->response(503), null), 'throttled, so never applied');
    }

    public function testMaybeAppliedConditionalWriteIsNotRetried(): void
    {
        $strategy = new RetryStrategy();

        foreach (['If-None-Match' => '*', 'If-Match' => '"etag"'] as $header => $value) {
            $write = $this->request('PUT')->withHeader($header, $value);

            $this->assertNull($strategy->delay($write, 1, $this->internalError(), null), $header);
            $this->assertNull($strategy->delay($write, 1, null, new ConnectionException($write, 'Connection reset by peer', \CURLE_RECV_ERROR)), $header);
            $this->assertNotNull($strategy->delay($write, 1, $this->response(503), null), $header . ' throttled, so never applied');
        }

        $read = $this->request('GET')->withHeader('If-Match', '"etag"');
        $this->assertNotNull($strategy->delay($read, 1, $this->internalError(), null), 'a conditional read is harmless to repeat');
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

    public function testFailuresBeforeSendingAreRetriedForEveryRequest(): void
    {
        $strategy = new RetryStrategy();
        $create = $this->request('POST', 'uploads=');

        $this->assertNotNull($strategy->delay($create, 1, null, new ConnectionException($create, 'Could not connect to server', \CURLE_COULDNT_CONNECT)), 'cURL');
        $this->assertNotNull($strategy->delay($create, 1, null, new ConnectionException($create, 'Connection refused', \defined('SOCKET_ECONNREFUSED') ? \SOCKET_ECONNREFUSED : 111)), 'Swoole');
        $this->assertNotNull($strategy->delay($create, 1, null, new DnsException($create, 'Could not resolve host', \CURLE_COULDNT_RESOLVE_HOST)));
    }

    public function testDroppedOrTimedOutPutIsRetried(): void
    {
        $strategy = new RetryStrategy();
        $put = $this->request('PUT');

        $this->assertNotNull($strategy->delay($put, 1, null, new ConnectionException($put, 'Connection reset by peer', \CURLE_RECV_ERROR)));
        $this->assertNotNull($strategy->delay($put, 1, null, new TimeoutException($put, 'Operation timed out', \CURLE_OPERATION_TIMEDOUT)));
    }

    public function testTimedOutCompletionIsRetried(): void
    {
        // A completion that did land leaves the object in place, which finalize() accepts.
        $complete = $this->request('POST', 'uploadId=upload-1');

        $this->assertNotNull(new RetryStrategy()->delay($complete, 1, null, new TimeoutException($complete, 'Operation timed out', \CURLE_OPERATION_TIMEDOUT)));
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
