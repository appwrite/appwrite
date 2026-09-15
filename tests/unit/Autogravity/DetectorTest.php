<?php

declare(strict_types=1);

namespace Tests\Unit\Autogravity;

use Appwrite\Autogravity\Client;
use Appwrite\Autogravity\Detector;
use Appwrite\Autogravity\Exception;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Span\Span;
use Utopia\Span\Storage\Memory as SpanMemory;

final class DetectorTest extends TestCase
{
    public function testRejectsDetectionWhenDisabled(): void
    {
        $detector = new Detector(null, new Cache(new Memory()));

        $this->assertFalse($detector->isEnabled());
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Autogravity needs to be configured with _APP_AUTOGRAVITY_HOST to use automatic gravity');

        $detector->get('source-image');
    }

    public function testCachesDetectionBySource(): void
    {
        $http = new CountingClient(new Response(
            200,
            body: new Stream('{"gravity":{"x":0.7,"y":0.4},"confidence":0.9}')
        ));
        $detector = new Detector(new Client($http), new Cache(new Memory()));

        $this->assertTrue($detector->isEnabled());

        $first = $detector->get('source-image');
        $second = $detector->get('source-image');

        $this->assertEqualsWithDelta(0.7, $first->x, PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(0.4, $first->y, PHP_FLOAT_EPSILON);
        $this->assertSame($first->getArrayCopy(), $second->getArrayCopy());
        $this->assertSame(1, $http->requests);
        $this->assertSame('source-image', $http->lastBody);
    }

    public function testDifferentSourcesAreDetectedSeparately(): void
    {
        $http = new CountingClient(new Response(
            200,
            body: new Stream('{"gravity":{"x":0.5,"y":0.5},"confidence":0.9}')
        ));
        $detector = new Detector(new Client($http), new Cache(new Memory()));

        $detector->get('first-source');
        $detector->get('second-source');

        $this->assertSame(2, $http->requests);
    }

    public function testRecordsCacheHitOnCurrentSpan(): void
    {
        Span::setStorage(new SpanMemory());
        $span = Span::init('test.autogravity');

        try {
            $http = new CountingClient(new Response(
                200,
                body: new Stream('{"gravity":{"x":0.7,"y":0.4},"confidence":0.9}')
            ));
            $detector = new Detector(new Client($http), new Cache(new Memory()));

            $detector->get('source-image');
            $this->assertFalse($span->get('autogravity.cache'));
            $this->assertSame(\strlen('source-image'), $span->get('autogravity.bytes'));

            $detector->get('source-image');
            $this->assertTrue($span->get('autogravity.cache'));
            $this->assertSame(1, $http->requests);
        } finally {
            Span::setStorage(null);
        }
    }

    public function testRecordsFailureOnCurrentSpan(): void
    {
        Span::setStorage(new SpanMemory());
        $span = Span::init('test.autogravity');

        try {
            $detector = new Detector(new Client(new CountingClient(
                new Response(413, body: new Stream('{"error":"payload too large"}'))
            )), new Cache(new Memory()));

            try {
                $detector->get('huge-image');
                $this->fail('Autogravity must surface payload errors');
            } catch (Exception $e) {
                $this->assertSame('payload too large', $e->getMessage());
                $this->assertSame(413, $e->getCode());
            }

            $this->assertFalse($span->get('autogravity.cache'));
            $this->assertSame(\strlen('huge-image'), $span->get('autogravity.bytes'));
            $this->assertSame('payload too large', $span->get('autogravity.error'));
            $this->assertSame(413, $span->get('autogravity.status'));
            $this->assertNotInstanceOf(\Throwable::class, $span->getError());
        } finally {
            Span::setStorage(null);
        }
    }
}

final class CountingClient implements ClientInterface
{
    public int $requests = 0;

    public string $lastBody = '';

    public function __construct(private readonly ResponseInterface $response)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests++;
        $this->lastBody = (string) $request->getBody();

        return $this->response;
    }
}
