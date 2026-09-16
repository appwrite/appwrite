<?php

declare(strict_types=1);

namespace Utopia\Tests\Psr7\Request;

use JsonException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Utopia\Psr7\Request;
use Utopia\Psr7\Request\Multipart\Body as MultipartBody;
use Utopia\Psr7\Request\Multipart\Part as RequestPart;

final class FactoryTest extends TestCase
{
    public function testItCreatesJsonRequests(): void
    {
        $requestFactory = new Request\Factory();

        $request = $requestFactory->json('POST', 'https://example.com/users', [
            'name' => 'Ada',
        ]);

        $this->assertInstanceOf(RequestInterface::class, $request);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://example.com/users', (string) $request->getUri());
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('{"name":"Ada"}', (string) $request->getBody());
    }

    public function testItAllowsJsonHeaderOverrides(): void
    {
        $requestFactory = new Request\Factory();

        $request = $requestFactory->json('POST', 'https://example.com/users', ['name' => 'Ada'], [
            'Accept' => 'application/vnd.api+json',
            'Content-Type' => 'application/merge-patch+json',
        ]);

        $this->assertSame('application/vnd.api+json', $request->getHeaderLine('Accept'));
        $this->assertSame('application/merge-patch+json', $request->getHeaderLine('Content-Type'));
    }

    public function testItCreatesFormRequests(): void
    {
        $requestFactory = new Request\Factory();

        $request = $requestFactory->form('POST', 'https://example.com/users', [
            'email' => 'ada@example.com',
            'name' => 'Ada Lovelace',
        ]);

        $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        $this->assertSame('email=ada%40example.com&name=Ada%20Lovelace', (string) $request->getBody());
    }

    public function testItCreatesRawBodyRequests(): void
    {
        $requestFactory = new Request\Factory();

        $request = $requestFactory->body('PUT', 'https://example.com/archive', 'raw-bytes', 'application/octet-stream');

        $this->assertSame('application/octet-stream', $request->getHeaderLine('Content-Type'));
        $this->assertSame('raw-bytes', (string) $request->getBody());
    }

    public function testItCreatesTextRequests(): void
    {
        $requestFactory = new Request\Factory();

        $request = $requestFactory->text('POST', 'https://example.com/notes', 'Hello, Ada');

        $this->assertSame('text/plain', $request->getHeaderLine('Content-Type'));
        $this->assertSame('Hello, Ada', (string) $request->getBody());
    }

    public function testItCreatesXmlRequests(): void
    {
        $requestFactory = new Request\Factory();

        $request = $requestFactory->xml('POST', 'https://example.com/users', '<user><name>Ada</name></user>');

        $this->assertSame('application/xml', $request->getHeaderLine('Content-Type'));
        $this->assertSame('<user><name>Ada</name></user>', (string) $request->getBody());
    }

    public function testItCreatesQueryRequests(): void
    {
        $requestFactory = new Request\Factory();

        $request = $requestFactory->query('GET', 'https://example.com/users?active=1', [
            'page' => 2,
            'search' => 'Ada Lovelace',
        ]);

        $this->assertSame('https://example.com/users?active=1&page=2&search=Ada%20Lovelace', (string) $request->getUri());
    }

    public function testItCreatesMultipartRequests(): void
    {
        $requestFactory = new Request\Factory();

        $request = $requestFactory->multipart('POST', 'https://example.com/upload', [
            'name' => 'Ada',
            'avatar' => RequestPart::body('avatar', 'image-bytes', 'ada.png', 'image/png'),
        ]);

        $contentType = $request->getHeaderLine('Content-Type');
        $body = (string) $request->getBody();
        $boundary = $this->assertMultipartContentTypeMatchesBody($request);

        $this->assertStringStartsWith('multipart/form-data; boundary=utopia-', $contentType);
        $this->assertStringStartsWith('utopia-', $boundary);
        $this->assertStringContainsString('Content-Disposition: form-data; name="name"', $body);
        $this->assertStringContainsString("\r\n\r\nAda\r\n", $body);
        $this->assertStringContainsString('Content-Disposition: form-data; name="avatar"; filename="ada.png"', $body);
        $this->assertStringContainsString('Content-Type: image/png', $body);
        $this->assertStringContainsString("\r\n\r\nimage-bytes\r\n", $body);
    }

    public function testItTerminatesMultipartRequestsWithClosingBoundary(): void
    {
        $requestFactory = new Request\Factory();

        $request = $requestFactory->multipart('POST', 'https://example.com/upload', [
            'name' => 'Ada',
        ]);

        $boundary = substr($request->getHeaderLine('Content-Type'), \strlen('multipart/form-data; boundary='));

        $this->assertStringEndsWith('--' . $boundary . "--\r\n", (string) $request->getBody());
    }

    public function testItCreatesMultipartRequestsWithRepeatedFieldNames(): void
    {
        $requestFactory = new Request\Factory();

        $request = $requestFactory->multipart('POST', 'https://example.com/upload', [
            RequestPart::field('tag', 'math'),
            RequestPart::field('tag', 'history'),
        ]);

        $body = (string) $request->getBody();

        $this->assertSame(2, substr_count($body, 'Content-Disposition: form-data; name="tag"'));
        $this->assertStringContainsString("\r\n\r\nmath\r\n", $body);
        $this->assertStringContainsString("\r\n\r\nhistory\r\n", $body);
    }

    public function testItCreatesMultipartRequestsWithCustomPartHeaders(): void
    {
        $requestFactory = new Request\Factory();

        $request = $requestFactory->multipart('POST', 'https://example.com/upload', [
            'payload' => RequestPart::body('payload', 'contents', headers: [
                'X-Part-ID' => '123',
            ]),
        ]);

        $this->assertStringContainsString('X-Part-ID: 123', (string) $request->getBody());
    }

    public function testItAddsMultipartBoundaryWhenCallerPassesContentTypeWithoutOne(): void
    {
        $requestFactory = new Request\Factory();

        $request = $requestFactory->multipart('POST', 'https://example.com/upload', [
            'name' => 'Ada',
        ], [
            'Content-Type' => 'multipart/form-data',
            'X-Request-Id' => 'abc',
        ]);

        $this->assertSame('abc', $request->getHeaderLine('X-Request-Id'));
        $this->assertMultipartContentTypeMatchesBody($request);
    }

    public function testItReplacesCallerSuppliedMultipartBoundaryWithTheGeneratedBodyBoundary(): void
    {
        $requestFactory = new Request\Factory();

        $request = $requestFactory->multipart('POST', 'https://example.com/upload', [
            'name' => 'Ada',
        ], [
            'Content-Type' => 'multipart/form-data; boundary=stale-boundary',
        ]);

        $boundary = $this->assertMultipartContentTypeMatchesBody($request);

        $this->assertNotSame('stale-boundary', $boundary);
        $this->assertStringNotContainsString('stale-boundary', $request->getHeaderLine('Content-Type'));
        $this->assertStringNotContainsString('--stale-boundary', (string) $request->getBody());
    }

    public function testItPreservesCallerContentTypeOnTypedBodies(): void
    {
        $requestFactory = new Request\Factory();

        $form = $requestFactory->form('POST', 'https://example.com/users', ['name' => 'Ada'], [
            'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
        ]);
        $text = $requestFactory->text('POST', 'https://example.com/notes', 'Hello', [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
        $xml = $requestFactory->xml('POST', 'https://example.com/users', '<user/>', [
            'Content-Type' => 'text/xml; charset=utf-8',
        ]);
        $body = $requestFactory->body('PUT', 'https://example.com/archive', 'raw', 'application/octet-stream', [
            'Content-Type' => 'application/custom',
        ]);

        $this->assertSame('application/x-www-form-urlencoded; charset=utf-8', $form->getHeaderLine('Content-Type'));
        $this->assertSame('text/plain; charset=utf-8', $text->getHeaderLine('Content-Type'));
        $this->assertSame('text/xml; charset=utf-8', $xml->getHeaderLine('Content-Type'));
        $this->assertSame('application/custom', $body->getHeaderLine('Content-Type'));
    }

    public function testItLeavesQueryRequestsWithoutContentTypeUnlessCallerSetsOne(): void
    {
        $requestFactory = new Request\Factory();

        $request = $requestFactory->query('GET', 'https://example.com/users', [
            'page' => 1,
        ]);
        $typed = $requestFactory->query('GET', 'https://example.com/users', [
            'page' => 1,
        ], [
            'Content-Type' => 'application/json',
        ]);

        $this->assertFalse($request->hasHeader('Content-Type'));
        $this->assertSame('application/json', $typed->getHeaderLine('Content-Type'));
    }

    public function testItEscapesMultipartDispositionQuotedStrings(): void
    {
        $requestFactory = new Request\Factory();

        $request = $requestFactory->multipart('POST', 'https://example.com/upload', [
            RequestPart::body('profile"name', 'contents', 'ada"notes\\.txt'),
        ]);

        $this->assertStringContainsString(
            'Content-Disposition: form-data; name="profile\"name"; filename="ada\"notes\\\\.txt"',
            (string) $request->getBody(),
        );
    }

    public function testItThrowsWhenJsonCannotBeEncoded(): void
    {
        $requestFactory = new Request\Factory();

        $this->expectException(JsonException::class);

        $requestFactory->json('POST', 'https://example.com/users', "\xB1\x31");
    }

    private function assertMultipartContentTypeMatchesBody(RequestInterface $request): string
    {
        $body = $request->getBody();
        $this->assertInstanceOf(MultipartBody::class, $body);

        $boundary = $body->boundary();
        $this->assertNotSame('', $boundary);
        $this->assertSame(
            'multipart/form-data; boundary=' . $boundary,
            $request->getHeaderLine('Content-Type'),
        );
        $this->assertStringContainsString('--' . $boundary . "\r\n", (string) $body);
        $this->assertStringEndsWith('--' . $boundary . "--\r\n", (string) $body);

        return $boundary;
    }
}
