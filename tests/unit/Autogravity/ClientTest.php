<?php

declare(strict_types=1);

namespace Tests\Unit\Autogravity;

use Appwrite\Autogravity\Client;
use Appwrite\Autogravity\Exception;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

final class ClientTest extends TestCase
{
    private ?RequestInterface $request = null;

    private function client(ResponseInterface $response): Client
    {
        return new Client(new class ($response, $this) implements ClientInterface {
            public function __construct(private ResponseInterface $response, private ClientTest $test)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->test->setRequest($request);
                return $this->response;
            }
        });
    }

    public function setRequest(RequestInterface $request): void
    {
        $this->request = $request;
    }

    public function testAnalyze(): void
    {
        $response = (new Response(200, body: new Stream('{"gravity":{"x":0.7,"y":0.4},"confidence":0.9}')))
            ->withHeader('Content-Type', 'application/json');

        $gravity = $this->client($response)->analyze('image-bytes');

        $this->assertEqualsWithDelta(0.7, $gravity->x, PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(0.4, $gravity->y, PHP_FLOAT_EPSILON);
        $this->assertInstanceOf(RequestInterface::class, $this->request);
        $this->assertSame('POST', $this->request->getMethod());
        $this->assertSame('analyze', (string) $this->request->getUri());
        $this->assertSame('application/octet-stream', $this->request->getHeaderLine('Content-Type'));
        $this->assertSame('image-bytes', (string) $this->request->getBody());
    }

    public function testAnalyzeError(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('invalid image');
        $this->expectExceptionCode(400);

        $this->client(new Response(400, body: new Stream('{"error":"invalid image"}')))->analyze('invalid');
    }

    public function testAnalyzeInvalidResponse(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Autogravity returned an invalid gravity');

        $this->client(new Response(200, body: new Stream('{"gravity":{"x":2,"y":0.5}}')))->analyze('image');
    }

    public function testAnalyzeErrorWithoutBody(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Autogravity failed with status 502');

        $this->client(new Response(502, body: new Stream('<html>')))->analyze('image');
    }
}
