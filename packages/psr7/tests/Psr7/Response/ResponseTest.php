<?php

declare(strict_types=1);

namespace Utopia\Tests\Psr7\Response;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

final class ResponseTest extends TestCase
{
    public function testItDecodesJsonResponses(): void
    {
        $response = new Response\Factory()
            ->createResponse()
            ->withBody(new Stream\Factory()->createStream('{"name":"Ada"}'));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(['name' => 'Ada'], $response->json());
    }

    public function testItReadsTheContentTypeWithoutParameters(): void
    {
        $response = new Response\Factory()
            ->createResponse()
            ->withHeader('Content-Type', 'Application/JSON; charset=utf-8');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('application/json', $response->contentType());
    }

    public function testItReadsAnEmptyContentTypeWhenAbsent(): void
    {
        $response = new Response\Factory()->createResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('', $response->contentType());
    }

    public function testItReadsTextResponses(): void
    {
        $response = new Response\Factory()
            ->createResponse()
            ->withBody(new Stream\Factory()->createStream('Hello, Ada'));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('Hello, Ada', $response->text());
    }

    public function testItDecodesXmlResponses(): void
    {
        $response = new Response\Factory()
            ->createResponse()
            ->withBody(new Stream\Factory()->createStream('<user><name>Ada</name></user>'));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('Ada', (string) $response->xml()->name);
    }

    public function testItRejectsInvalidXmlResponses(): void
    {
        $response = new Response\Factory()
            ->createResponse()
            ->withBody(new Stream\Factory()->createStream('<user><name>Ada</name>'));

        $this->assertInstanceOf(Response::class, $response);
        $this->expectException(InvalidArgumentException::class);

        $response->xml();
    }

    public function testItDecodesFormResponses(): void
    {
        $response = new Response\Factory()
            ->createResponse()
            ->withBody(new Stream\Factory()->createStream('email=ada%40example.com&name=Ada%20Lovelace'));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame([
            'email' => 'ada@example.com',
            'name' => 'Ada Lovelace',
        ], $response->form());
    }

    public function testItDecodesMultipartResponses(): void
    {
        $body = "--abc123\r\n"
            . "Content-Disposition: form-data; name=\"name\"\r\n"
            . "\r\n"
            . "Ada\r\n"
            . "--abc123\r\n"
            . "Content-Disposition: form-data; name=\"avatar\"; filename=\"ada.png\"\r\n"
            . "Content-Type: image/png\r\n"
            . "\r\n"
            . "image-bytes\r\n"
            . "--abc123--\r\n";

        $response = $this->multipartResponse($body, 'multipart/form-data; boundary=abc123');

        $parts = $response->multipart();

        $this->assertCount(2, $parts);
        $this->assertSame('name', $parts[0]->name());
        $this->assertNull($parts[0]->filename());
        $this->assertSame('Ada', $parts[0]->body());
        $this->assertSame('avatar', $parts[1]->name());
        $this->assertSame('ada.png', $parts[1]->filename());
        $this->assertSame('image/png', $parts[1]->contentType());
        $this->assertSame('image-bytes', $parts[1]->body());
    }

    public function testItDecodesMultipartResponsesWithQuotedBoundariesAndBodyCrLf(): void
    {
        $body = "--abc123\r\n"
            . "Content-Disposition: form-data; name=\"log\"\r\n"
            . "\r\n"
            . "line 1\r\nline 2\r\n"
            . "--abc123--\r\n";

        $parts = $this->multipartResponse($body, 'multipart/mixed; boundary="abc123"')->multipart();

        $this->assertCount(1, $parts);
        $this->assertSame('log', $parts[0]->name());
        $this->assertSame("line 1\r\nline 2", $parts[0]->body());
    }

    public function testItDecodesMultipartResponsesWithPreambleAndEpilogue(): void
    {
        $body = "ignored preamble\r\n"
            . "--abc123\r\n"
            . "Content-Disposition: form-data; name=\"name\"\r\n"
            . "\r\n"
            . "Ada\r\n"
            . "--abc123--\r\n"
            . 'ignored epilogue';

        $parts = $this->multipartResponse($body, 'multipart/form-data; boundary=abc123')->multipart();

        $this->assertCount(1, $parts);
        $this->assertSame('name', $parts[0]->name());
        $this->assertSame('Ada', $parts[0]->body());
    }

    public function testItDoesNotSplitMultipartBodiesOnBoundaryTextInsideContent(): void
    {
        $body = "--abc123\r\n"
            . "Content-Disposition: form-data; name=\"log\"\r\n"
            . "\r\n"
            . "line one has --abc123 inside it\r\n"
            . "line two\r\n"
            . "--abc123--\r\n";

        $parts = $this->multipartResponse($body, 'multipart/form-data; boundary=abc123')->multipart();

        $this->assertCount(1, $parts);
        $this->assertSame("line one has --abc123 inside it\r\nline two", $parts[0]->body());
    }

    public function testItDecodesMultipartResponsesWithRepeatedFieldNames(): void
    {
        $body = "--abc123\r\n"
            . "Content-Disposition: form-data; name=\"tag\"\r\n"
            . "\r\n"
            . "math\r\n"
            . "--abc123\r\n"
            . "Content-Disposition: form-data; name=\"tag\"\r\n"
            . "\r\n"
            . "history\r\n"
            . "--abc123--\r\n";

        $parts = $this->multipartResponse($body, 'multipart/form-data; boundary=abc123')->multipart();

        $this->assertCount(2, $parts);
        $this->assertSame('tag', $parts[0]->name());
        $this->assertSame('math', $parts[0]->body());
        $this->assertSame('tag', $parts[1]->name());
        $this->assertSame('history', $parts[1]->body());
    }

    public function testItDecodesMultipartResponsesWithUnquotedDispositionParametersAndDuplicateHeaders(): void
    {
        $body = "--abc123\r\n"
            . "Content-Disposition: form-data; name=file; filename=ada.txt\r\n"
            . "X-Trace: one\r\n"
            . "X-Trace: two\r\n"
            . "\r\n"
            . "contents\r\n"
            . "--abc123--\r\n";

        $parts = $this->multipartResponse($body, 'multipart/form-data; boundary=abc123')->multipart();

        $this->assertCount(1, $parts);
        $this->assertSame('file', $parts[0]->name());
        $this->assertSame('ada.txt', $parts[0]->filename());
        $this->assertSame(['one', 'two'], $parts[0]->header('x-trace'));
        $this->assertSame('one, two', $parts[0]->headerLine('X-Trace'));
    }

    public function testItRejectsMultipartResponsesWithoutBoundaries(): void
    {
        $response = new Response\Factory()
            ->createResponse()
            ->withHeader('Content-Type', 'multipart/mixed')
            ->withBody(new Stream\Factory()->createStream(''));

        $this->assertInstanceOf(Response::class, $response);
        $this->expectException(InvalidArgumentException::class);

        $response->multipart();
    }

    private function multipartResponse(string $body, string $contentType): Response
    {
        $response = new Response\Factory()
            ->createResponse()
            ->withHeader('Content-Type', $contentType)
            ->withBody(new Stream\Factory()->createStream($body));

        $this->assertInstanceOf(Response::class, $response);

        return $response;
    }
}
