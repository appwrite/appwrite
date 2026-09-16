<?php

declare(strict_types=1);

namespace Utopia\Tests\Psr7;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Utopia\Psr7\Request;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Psr7\Uri;

final class FactoriesTest extends TestCase
{
    public function testItCreatesPsrMessages(): void
    {
        $uriFactory = new Uri\Factory();
        $requestFactory = new Request\Factory($uriFactory);
        $responseFactory = new Response\Factory();
        $streamFactory = new Stream\Factory();

        $this->assertInstanceOf(UriFactoryInterface::class, $uriFactory);
        $this->assertInstanceOf(RequestFactoryInterface::class, $requestFactory);
        $this->assertInstanceOf(ResponseFactoryInterface::class, $responseFactory);
        $this->assertInstanceOf(StreamFactoryInterface::class, $streamFactory);

        $request = $requestFactory->createRequest('post', 'https://example.com/users?active=1')
            ->withHeader('Accept', ['application/json', 'text/plain'])
            ->withBody($streamFactory->createStream('body'));

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/users?active=1', $request->getRequestTarget());
        $this->assertSame('application/json, text/plain', $request->getHeaderLine('Accept'));
        $this->assertSame('body', (string) $request->getBody());

        $response = $responseFactory->createResponse(201, 'Created')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($streamFactory->createStream('{}'));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('Created', $response->getReasonPhrase());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('{}', (string) $response->getBody());
    }

    public function testItWrapsFilesAndResourcesWithoutCopyingThem(): void
    {
        $streamFactory = new Stream\Factory();
        $path = tempnam(sys_get_temp_dir(), 'utopia-stream-');
        $this->assertNotFalse($path);
        file_put_contents($path, 'on disk');

        try {
            $fromFile = $streamFactory->createStreamFromFile($path);
            $this->assertSame(7, $fromFile->getSize());
            $this->assertSame('on disk', (string) $fromFile);

            $resource = fopen('php://temp', 'r+');
            $this->assertIsResource($resource);
            fwrite($resource, 'in memory');
            $fromResource = $streamFactory->createStreamFromResource($resource);
            $this->assertSame('in memory', (string) $fromResource);
        } finally {
            unlink($path);
        }
    }

    public function testItRejectsFilesThatCannotBeOpened(): void
    {
        $this->expectException(\RuntimeException::class);

        new Stream\Factory()->createStreamFromFile('/nonexistent/utopia-missing-file');
    }
}
