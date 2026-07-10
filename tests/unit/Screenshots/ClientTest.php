<?php

declare(strict_types=1);

namespace Tests\Unit\Screenshots;

use Appwrite\Screenshots\Client;
use Appwrite\Screenshots\Exception;
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
        $test = $this;
        return new Client(new class ($response, $test) implements ClientInterface {
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

    public function testCreate(): void
    {
        $response = (new Response(200, body: new Stream('png-bytes')))
            ->withHeader('Content-Type', 'image/png');

        $screenshot = $this->client($response)->create(
            url: 'http://appwrite/',
            theme: 'dark',
            headers: ['x-appwrite-hostname' => 'example.com'],
            sleep: 500,
        );

        $this->assertSame('png-bytes', $screenshot);
        $this->assertSame('POST', $this->request->getMethod());
        $this->assertSame('screenshots', (string)$this->request->getUri());
        $this->assertEquals([
            'url' => 'http://appwrite/',
            'theme' => 'dark',
            'headers' => ['x-appwrite-hostname' => 'example.com'],
            'sleep' => 500,
        ], \json_decode((string)$this->request->getBody(), true));
    }

    public function testCreateError(): void
    {
        $response = (new Response(400, body: new Stream('{"error":"Timeout exceeded"}')))
            ->withHeader('Content-Type', 'application/json');

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
            ->withHeader('Content-Type', 'text/html');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Expected an image response, got text/html');

        $this->client($response)->create('http://appwrite/', 'light');
    }
}
