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
