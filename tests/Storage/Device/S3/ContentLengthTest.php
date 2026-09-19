<?php

declare(strict_types=1);

namespace Utopia\Tests\Storage\Device\S3;

use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Psr18\StreamingClientInterface;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Storage\Device\S3;

/**
 * Captures the outgoing PSR-7 request so header assertions can be made against
 * what S3::call() actually sends, without hitting the network.
 */
final class CapturingClient implements ClientInterface, StreamingClientInterface
{
    public ?RequestInterface $lastRequest = null;

    public function __construct(private readonly ResponseInterface $response) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->lastRequest = $request;

        return $this->response;
    }

    #[\Override]
    public function stream(RequestInterface $request, callable $sink): ResponseInterface
    {
        $this->lastRequest = $request;

        return $this->response;
    }
}

/**
 * Regression coverage for the HTTP 411 fix: every S3 request must carry an
 * explicit Content-Length so the transport never falls back to chunked
 * transfer encoding (which GCS and other S3-compatible services reject).
 */
final class ContentLengthTest extends TestCase
{
    /**
     * @return array{S3, CapturingClient}
     */
    private function device(ResponseInterface $response): array
    {
        $client = new CapturingClient($response);
        $device = new S3(
            root: 'bucket-root',
            accessKey: 'access-key',
            secretKey: 'secret-key',
            host: 'https://s3.example.com',
            region: 'us-east-1',
            client: $client,
        );

        return [$device, $client];
    }

    /** A streamed body must send its exact byte length, never chunked. */
    public function testStreamedWriteSendsExactContentLength(): void
    {
        [$device, $client] = $this->device(new Response(200)->withHeader('etag', '"abc"'));

        $payload = 'hello world'; // 11 bytes
        $device->write('file.txt', new Stream($payload), 'text/plain');

        $this->assertInstanceOf(RequestInterface::class, $client->lastRequest);
        $this->assertSame(
            (string) \strlen($payload),
            $client->lastRequest->getHeaderLine('content-length'),
        );
    }

    /** An empty-body POST (multipart initiation) must send Content-Length: 0. */
    public function testEmptyBodyMultipartPostSendsZeroContentLength(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><InitiateMultipartUploadResult><UploadId>test-upload-id</UploadId></InitiateMultipartUploadResult>';
        [$device, $client] = $this->device(new Response(200, body: new Stream($xml)));

        // A multi-chunk prepare() initiates a multipart upload, whose POST
        // carries no body — this is the empty-body request GCS rejected with 411.
        $metadata = [];
        $device->prepare('file.txt', 'text/plain', 2, $metadata);

        $this->assertSame('test-upload-id', $metadata['uploadId'] ?? null);
        $this->assertInstanceOf(RequestInterface::class, $client->lastRequest);
        $this->assertSame('0', $client->lastRequest->getHeaderLine('content-length'));
    }
}
