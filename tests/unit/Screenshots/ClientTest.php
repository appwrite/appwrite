<?php

declare(strict_types=1);

namespace Tests\Unit\Screenshots;

use Appwrite\Screenshots\Client;
use Appwrite\Screenshots\Exception;
use Appwrite\Screenshots\RetryStrategy;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Tests\Unit\Screenshots\Fakes\Adapter;
use Utopia\Client\Decorator\Retry;
use Utopia\Psr7\Header;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

final class ClientTest extends TestCase
{
    private const int UNAVAILABLE = 503;

    private const string CAPACITY_EXCEEDED = 'Browser is at capacity. Retry after the Retry-After delay.';

    private const float JITTER = 0.5;

    private Adapter $adapter;

    private function client(ResponseInterface $response): Client
    {
        $this->adapter = new Adapter([$response]);

        return new Client($this->adapter);
    }

    public function testCreate(): void
    {
        $response = (new Response(200, body: new Stream('png-bytes')))
            ->withHeader(Header::CONTENT_TYPE, 'image/png');

        $screenshot = $this->client($response)->create(
            url: 'http://appwrite/',
            theme: 'dark',
            headers: ['x-appwrite-hostname' => 'example.com'],
            sleep: 500,
        );

        $this->assertSame('png-bytes', $screenshot);
        $this->assertSame('POST', $this->adapter->request->getMethod());
        $this->assertSame('screenshots', (string)$this->adapter->request->getUri());
        $this->assertSame([
            'url' => 'http://appwrite/',
            'theme' => 'dark',
            'headers' => ['x-appwrite-hostname' => 'example.com'],
            'sleep' => 500,
            'timeout' => 60000,
            'viewport' => ['width' => 1280, 'height' => 720],
            'deviceScaleFactor' => 1.5,
        ], \json_decode((string)$this->adapter->request->getBody(), true));
    }

    public function testCreateCapturesAboveViewportResolution(): void
    {
        $response = (new Response(200, body: new Stream('png-bytes')))
            ->withHeader(Header::CONTENT_TYPE, 'image/png');

        $this->client($response)->create('http://appwrite/', 'light');

        $body = \json_decode((string)$this->adapter->request->getBody(), true);

        // Left unset, the browser service defaults to a scale factor of 1, which
        // captures exactly one pixel per CSS pixel and renders soft previews.
        $this->assertGreaterThan(1, $body['deviceScaleFactor']);
        $this->assertSame(1280, $body['viewport']['width']);
        $this->assertSame(720, $body['viewport']['height']);
    }

    public function testCreateDoesNotBlockOnSubresources(): void
    {
        $response = (new Response(200, body: new Stream('png-bytes')))
            ->withHeader(Header::CONTENT_TYPE, 'image/png');

        $this->client($response)->create('http://appwrite/', 'light');

        // The browser service navigates with `domcontentloaded` unless told
        // otherwise. Waiting for `load` risks exhausting the request timeout on
        // sites with slow or hanging subresources, which yields no screenshot at
        // all rather than an early one.
        $this->assertArrayNotHasKey('waitUntil', \json_decode((string)$this->adapter->request->getBody(), true));
    }

    public function testCreateError(): void
    {
        $response = (new Response(400, body: new Stream('{"error":"Timeout exceeded"}')))
            ->withHeader(Header::CONTENT_TYPE, 'application/json');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Timeout exceeded');
        $this->expectExceptionCode(400);

        $this->client($response)->create('http://appwrite/', 'light');
    }

    public function testCreateErrorWithoutBody(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Screenshot failed with status 502');

        $this->client(new Response(502))->create('http://appwrite/', 'light');
    }

    public function testCreateUnexpectedContentType(): void
    {
        $response = (new Response(200, body: new Stream('<html>')))
            ->withHeader(Header::CONTENT_TYPE, 'text/html');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Expected an image response, got text/html');

        $this->client($response)->create('http://appwrite/', 'light');
    }

    public function testCreateRetriesUnavailableAfterJitteredRetryAfter(): void
    {
        $adapter = new Adapter([
            $this->unavailable('2'),
            (new Response(200, body: new Stream('png-bytes')))->withHeader(Header::CONTENT_TYPE, 'image/png'),
        ]);
        $sleeps = new \ArrayObject();

        $screenshot = $this->retrying($adapter, $sleeps)->create('http://appwrite/', 'light');

        $this->assertSame('png-bytes', $screenshot);
        $this->assertSame(2, $adapter->calls);
        $this->assertSame([2.5], $sleeps->getArrayCopy());
    }

    public function testCreateThrowsUnavailableOnceAttemptsAreSpent(): void
    {
        $adapter = new Adapter(\array_fill(0, 3, $this->unavailable('1')));
        $sleeps = new \ArrayObject();

        try {
            $this->retrying($adapter, $sleeps)->create('http://appwrite/', 'light');
            $this->fail('Expected the final 503 to surface as an exception.');
        } catch (Exception $exception) {
            $this->assertSame(self::UNAVAILABLE, $exception->getCode());
            $this->assertSame(self::CAPACITY_EXCEEDED, $exception->getMessage());
            $this->assertFalse($exception->isPermanent());
        }

        $this->assertSame(3, $adapter->calls);
        $this->assertSame([1.25, 1.25], $sleeps->getArrayCopy());
    }

    /**
     * @param \ArrayObject<int, float> $sleeps
     */
    private function retrying(Adapter $adapter, \ArrayObject $sleeps): Client
    {
        $strategy = new RetryStrategy(jitter: self::JITTER, randomizer: static fn (): float => 0.5);

        return new Client(new Retry($adapter, $strategy, static function (float $seconds) use ($sleeps): void {
            $sleeps[] = $seconds;
        }));
    }

    private function unavailable(string $retryAfter): ResponseInterface
    {
        return (new Response(self::UNAVAILABLE, body: new Stream(\json_encode(['error' => self::CAPACITY_EXCEEDED], JSON_THROW_ON_ERROR))))
            ->withHeader(Header::CONTENT_TYPE, 'application/json')
            ->withHeader(Header::RETRY_AFTER, $retryAfter);
    }
}
