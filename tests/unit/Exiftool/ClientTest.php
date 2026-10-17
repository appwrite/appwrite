<?php

declare(strict_types=1);

namespace Tests\Unit\Exiftool;

use Appwrite\Exiftool\Client;
use Appwrite\Exiftool\Exception;
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

    public function testStrip(): void
    {
        $response = (new Response(200, body: new Stream('stripped-bytes')))
            ->withHeader('Content-Type', 'application/octet-stream');

        $stripped = $this->client($response)->strip('image-bytes');

        $this->assertSame('stripped-bytes', $stripped);
        $this->assertInstanceOf(RequestInterface::class, $this->request);
        $this->assertSame('POST', $this->request->getMethod());
        $this->assertSame('strip', (string) $this->request->getUri());
        $this->assertSame('application/octet-stream', $this->request->getHeaderLine('Content-Type'));
        $this->assertSame('image-bytes', (string) $this->request->getBody());
    }

    public function testStripUnsupportedFile(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('file format is not supported or the file is corrupt');
        $this->expectExceptionCode(422);

        $this->client(new Response(422, body: new Stream('{"error":"file format is not supported or the file is corrupt"}')))->strip('text');
    }

    public function testStripErrorWithoutBody(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Exiftool failed with status 502');

        $this->client(new Response(502, body: new Stream('<html>')))->strip('image');
    }

    public function testStripEmptyResponse(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Exiftool returned an empty file');

        $this->client(new Response(200, body: new Stream('')))->strip('image');
    }

    public function testStripWithoutHost(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Exiftool needs to be configured with _APP_EXIFTOOL_HOST to strip metadata');

        (new Client(null))->strip('image');
    }
}
