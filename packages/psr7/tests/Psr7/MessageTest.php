<?php

declare(strict_types=1);

namespace Utopia\Tests\Psr7;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Utopia\Psr7\Request;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Psr7\Uri;

final class MessageTest extends TestCase
{
    public function testHeadersAreImmutableCaseInsensitiveAndPreserveOriginalCase(): void
    {
        $response = new Response\Factory()->createResponse()
            ->withHeader('X-Test', 'one');

        $changed = $response
            ->withAddedHeader('x-test', 'two')
            ->withHeader('CONTENT-TYPE', ['text/plain', 'application/json']);

        $this->assertFalse($response->hasHeader('content-type'));
        $this->assertSame(['one'], $response->getHeader('x-test'));
        $this->assertSame(['one', 'two'], $changed->getHeader('X-TEST'));
        $this->assertSame('one, two', $changed->getHeaderLine('x-test'));
        $this->assertSame(['text/plain', 'application/json'], $changed->getHeader('content-type'));
        $this->assertArrayHasKey('X-Test', $changed->getHeaders());
        $this->assertArrayHasKey('CONTENT-TYPE', $changed->getHeaders());

        $removed = $changed->withoutHeader('x-TEST');

        $this->assertFalse($removed->hasHeader('X-Test'));
        $this->assertTrue($changed->hasHeader('X-Test'));
    }

    public function testInvalidHeadersAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Response\Factory()->createResponse()->withHeader("Bad\nHeader", 'value');
    }

    public function testRequestFactorySetsHostAndWithUriHonorsPreserveHostRules(): void
    {
        $requestFactory = new Request\Factory();
        $uriFactory = new Uri\Factory();

        $request = $requestFactory->createRequest('GET', 'https://example.com:8443/users?active=1');

        $this->assertSame('example.com:8443', $request->getHeaderLine('Host'));
        $this->assertSame('/users?active=1', $request->getRequestTarget());

        $changed = $request->withUri($uriFactory->createUri('https://api.example.com/orders'));

        $this->assertSame('api.example.com', $changed->getHeaderLine('Host'));
        $this->assertSame('example.com:8443', $request->getHeaderLine('Host'));

        $preserved = $request
            ->withHeader('Host', 'custom.example.com')
            ->withUri($uriFactory->createUri('https://api.example.com/orders'), true);

        $this->assertSame('custom.example.com', $preserved->getHeaderLine('Host'));

        $missingHost = $request
            ->withoutHeader('Host')
            ->withUri($uriFactory->createUri('https://api.example.com/orders'), true);

        $this->assertSame('api.example.com', $missingHost->getHeaderLine('Host'));
    }

    public function testUriAuthorityDefaultPortsAndStringOutput(): void
    {
        $uri = new Uri\Factory()->createUri('HTTPS://user:pass@example.com:443/a path?x=1#frag');

        $this->assertSame('https', $uri->getScheme());
        $this->assertSame('user:pass@example.com', $uri->getAuthority());
        $this->assertNull($uri->getPort());
        $this->assertSame('https://user:pass@example.com/a%20path?x=1#frag', (string) $uri);

        $withPort = $uri->withPort(8443);

        $this->assertSame('user:pass@example.com:8443', $withPort->getAuthority());
        $this->assertSame('https://user:pass@example.com:8443/a%20path?x=1#frag', (string) $withPort);
    }

    public function testStreamDetachAndCloseState(): void
    {
        $stream = new Stream('hello');
        $resource = $stream->detach();

        $this->assertSame('hello', stream_get_contents($resource));
        fclose($resource);
        $this->assertFalse($stream->isReadable());
        $this->assertFalse($stream->isWritable());
        $this->assertFalse($stream->isSeekable());

        $this->expectException(RuntimeException::class);

        $stream->getContents();
    }
}
