<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Functions\Workers\Screenshots;

use Appwrite\Platform\Modules\Functions\Workers\Screenshots\Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

final class ClientTest extends TestCase
{
    public function testCaptureSendsJsonRequestAndReturnsScreenshot(): void
    {
        $http = new FakeClient(new Response(body: new Stream('screenshot')));
        $client = new Client($http, 'http://browser/v1/screenshots');

        $screenshot = $client->capture([
            'url' => 'https://example.com',
            'theme' => 'dark',
        ]);

        $this->assertSame('screenshot', $screenshot);
        $this->assertNotNull($http->request);
        $this->assertSame('POST', $http->request->getMethod());
        $this->assertSame('http://browser/v1/screenshots', (string) $http->request->getUri());
        $this->assertSame('application/json', $http->request->getHeaderLine('content-type'));
        $this->assertSame(
            ['url' => 'https://example.com', 'theme' => 'dark'],
            \json_decode((string) $http->request->getBody(), true, flags: JSON_THROW_ON_ERROR)
        );
    }

    public function testCaptureThrowsResponseBodyOnFailure(): void
    {
        $client = new Client(
            new FakeClient(new Response(500, body: new Stream('browser failed'))),
            'http://browser/v1/screenshots',
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('browser failed');

        $client->capture([]);
    }
}

final class FakeClient implements ClientInterface
{
    public ?RequestInterface $request = null;

    public function __construct(private readonly ResponseInterface $response)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->request = $request;

        return $this->response;
    }
}
