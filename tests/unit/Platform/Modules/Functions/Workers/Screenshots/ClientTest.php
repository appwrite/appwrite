<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Functions\Workers\Screenshots;

use Appwrite\AppwriteException;
use Appwrite\Platform\Modules\Functions\Workers\Screenshots\Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
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

    public function testCaptureThrowsAppwriteErrorOnFailure(): void
    {
        $body = \json_encode([
            'message' => 'Browser failed',
            'code' => 503,
            'type' => 'general_server_error',
            'version' => '1.8.0',
        ], JSON_THROW_ON_ERROR);
        $client = new Client(
            new FakeClient(new Response(503, body: new Stream($body))),
            'http://browser/v1/screenshots',
        );

        try {
            $client->capture([]);
            $this->fail('Expected capture to throw');
        } catch (AppwriteException $error) {
            $this->assertSame('Browser failed', $error->getMessage());
            $this->assertSame(503, $error->getCode());
            $this->assertSame('general_server_error', $error->getType());
            $this->assertSame($body, $error->getResponse());
        }
    }

    public function testCapturePreservesNonJsonError(): void
    {
        $client = new Client(
            new FakeClient(new Response(502, body: new Stream('Bad Gateway'))),
            'http://browser/v1/screenshots',
        );

        try {
            $client->capture([]);
            $this->fail('Expected capture to throw');
        } catch (AppwriteException $error) {
            $this->assertSame('Bad Gateway', $error->getMessage());
            $this->assertSame(502, $error->getCode());
            $this->assertSame('', $error->getType());
            $this->assertSame('Bad Gateway', $error->getResponse());
        }
    }

    public function testCaptureFallsBackForMalformedAppwriteError(): void
    {
        $body = '{"message":[],"type":{}}';
        $client = new Client(
            new FakeClient(new Response(500, body: new Stream($body))),
            'http://browser/v1/screenshots',
        );

        try {
            $client->capture([]);
            $this->fail('Expected capture to throw');
        } catch (AppwriteException $error) {
            $this->assertSame($body, $error->getMessage());
            $this->assertSame(500, $error->getCode());
            $this->assertSame('', $error->getType());
            $this->assertSame($body, $error->getResponse());
        }
    }

    public function testCaptureNormalizesTransportError(): void
    {
        $client = new Client(new FailingClient(), 'http://browser/v1/screenshots');

        $this->expectException(AppwriteException::class);
        $this->expectExceptionMessage('Connection failed');

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

final class FailingClient implements ClientInterface
{
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw new TransportException('Connection failed');
    }
}

final class TransportException extends \Exception implements ClientExceptionInterface
{
}
