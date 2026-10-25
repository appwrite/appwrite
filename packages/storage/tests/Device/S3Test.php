<?php

declare(strict_types=1);

namespace Utopia\Storage\Tests\Device;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Utopia\Client\Adapter;
use Utopia\Client\Decorator\Retry;
use Utopia\Client\Destinations;
use Utopia\Client\Exception\NetworkException;
use Utopia\Client\Exception\TimeoutException;
use Utopia\Client\Redirect;
use Utopia\Client\Tls;
use Utopia\Psr7\Request;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Psr7\Uri;
use Utopia\Storage\Device\AWS;
use Utopia\Storage\Device\Backblaze;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Linode;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Device\S3\Response as S3Response;
use Utopia\Storage\Device\S3\RetryStrategy;
use Utopia\Storage\Device\Wasabi;
use Utopia\Storage\Exception\NotFoundException;
use Utopia\Storage\Exception\PreconditionFailedException;
use Utopia\Storage\Exception\RemoteException;
use Utopia\Storage\Exception\StorageException;
use Utopia\Storage\Exception\TransportException;
use Utopia\Storage\Exception\UploadException;

/**
 * Testable S3 subclass that exposes protected helpers.
 */
class TestableS3 extends S3
{
    /**
     * @var array<string>
     */
    public array $calls = [];

    public string $completedBody = '';

    /**
     * @var array<string, array<string, string>>
     */
    public array $headersByOperation = [];

    public ?int $failPart = null;

    public bool $objectExists = false;

    public string $infoContentLength = '1';

    /**
     * @var array<string, array<int, array<string, string>>>
     */
    public array $amzHeadersByOperation = [];

    #[\Override]
    protected function call(string $method, string $uri, StreamInterface|string $data = '', array $parameters = [], array $headers = [], array $amzHeaders = [], bool $decode = true, ?callable $sink = null, ?callable $onResponse = null): S3Response
    {
        $operation = match (true) {
            $method === 'HEAD' => 's3:info',
            $method === 'POST' && \array_key_exists('uploads', $parameters) => 's3:createMultipartUpload',
            $method === 'PUT' && isset($parameters['partNumber'], $amzHeaders['x-amz-copy-source']) => 's3:uploadPartCopy',
            $method === 'PUT' && isset($amzHeaders['x-amz-copy-source']) => 's3:copyObject',
            $method === 'PUT' && isset($parameters['partNumber']) => 's3:uploadPart',
            $method === 'POST' && isset($parameters['uploadId']) => 's3:completeMultipartUpload',
            $method === 'DELETE' && isset($parameters['uploadId']) => 's3:abort',
            $method === 'GET' && \array_key_exists('uploads', $parameters) => 's3:listUploads',
            $method === 'PUT' => 's3:write',
            default => 's3:' . strtolower($method),
        };
        $this->calls[] = $operation;
        $this->headersByOperation[$operation] = $headers;
        $this->amzHeadersByOperation[$operation][] = $amzHeaders;

        if ($operation === 's3:info') {
            if (! $this->objectExists) {
                throw new NotFoundException('Not found');
            }

            return new S3Response(code: 200, headers: ['content-length' => $this->infoContentLength], body: '');
        }

        if ($operation === 's3:copyObject') {
            return new S3Response(code: 200, headers: [], body: ['ETag' => '"etag-copy"']);
        }

        if ($operation === 's3:uploadPartCopy') {
            return new S3Response(code: 200, headers: [], body: ['ETag' => '"etag-' . $parameters['partNumber'] . '"']);
        }

        if ($operation === 's3:createMultipartUpload') {
            return new S3Response(code: 200, headers: [], body: ['UploadId' => 'upload-123']);
        }

        if ($operation === 's3:uploadPart') {
            if ($this->failPart === $parameters['partNumber']) {
                throw new RemoteException('Injected part failure');
            }

            return new S3Response(code: 200, headers: ['etag' => 'etag-' . $parameters['partNumber']], body: '');
        }

        if ($operation === 's3:completeMultipartUpload') {
            if ($this->completedBody !== '') {
                throw new NotFoundException('The specified multipart upload does not exist.', 404);
            }

            $this->completedBody = (string) $data;
            $this->objectExists = true;

            return new S3Response(code: 200, headers: [], body: '');
        }

        if ($operation === 's3:write') {
            $this->objectExists = true;

            return new S3Response(code: 200, headers: ['etag' => '"etag-write"'], body: '');
        }

        return new S3Response(code: 200, headers: [], body: '');
    }
}

/**
 * Client stub that replays scripted responses and records every request.
 * Implements the full utopia-php/client Adapter so the Retry decorator can wrap it.
 */
final class ScriptedClient implements Adapter
{
    /**
     * @var array<RequestInterface>
     */
    public array $requests = [];

    /**
     * @param  array<ResponseInterface|ClientExceptionInterface>  $responses
     */
    public function __construct(private array $responses)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $response = array_shift($this->responses);
        if ($response instanceof ClientExceptionInterface) {
            throw $response;
        }
        if (! $response instanceof ResponseInterface) {
            throw new \RuntimeException('No scripted response left for ' . $request->getMethod() . ' ' . $request->getUri());
        }

        return $response;
    }

    public function stream(RequestInterface $request, callable $sink): ResponseInterface
    {
        $response = $this->sendRequest($request);
        $sink((string) $response->getBody());

        return $response;
    }

    public function withDestinations(Destinations $destinations): static
    {
        return $this;
    }

    public function withTimeout(float $seconds): static
    {
        return $this;
    }

    public function withConnectTimeout(float $seconds): static
    {
        return $this;
    }

    public function withSslVerification(bool $enabled = true): static
    {
        return $this;
    }

    public function withCustomCA(string $path): static
    {
        return $this;
    }

    public function withCertificate(string $certPath, string $keyPath, ?string $passphrase = null): static
    {
        return $this;
    }

    public function withMinTlsVersion(Tls $version): static
    {
        return $this;
    }

    public function withConnectionReuse(bool $enabled = true): static
    {
        return $this;
    }

    public function withFollowRedirects(bool $enabled = true, int $maxHops = Redirect::MAX_HOPS): static
    {
        return $this;
    }
}

final class S3Test extends TestCase
{
    private TestableS3 $s3;

    protected function setUp(): void
    {
        $this->s3 = new TestableS3(
            root: '/root',
            accessKey: 'test-key',
            secretKey: 'test-secret',
            host: 'https://s3.example.com',
            region: 'us-east-1',
        );
    }

    public function testPrepareCreatesMultipartMetadata(): void
    {
        $metadata = [];

        $this->s3->prepare('/root/file.txt', 'text/plain', 2, $metadata);

        $this->assertSame('upload-123', $metadata['uploadId'] ?? null);
        $this->assertSame([], $metadata['parts'] ?? null);
        $this->assertSame(0, $metadata['chunks'] ?? null);
        $this->assertSame(['s3:createMultipartUpload'], $this->s3->calls);
    }

    public function testUploadRecordsPartWithoutCompleting(): void
    {
        $metadata = [];

        $chunks = $this->s3->upload(new Stream('aaa'), '/root/file.txt', 'text/plain', 1, 2, $metadata);

        $this->assertSame(1, $chunks);
        $this->assertSame('etag-1', ($metadata['parts'] ?? [])[1] ?? null);
        $this->assertNotContains('s3:completeMultipartUpload', $this->s3->calls);
    }

    public function testSingleChunkUploadDoesNotFinalizeOrCheckExists(): void
    {
        $metadata = [];

        $this->assertSame(1, $this->s3->upload(new Stream('aaa'), '/root/file.txt', 'text/plain', 1, 1, $metadata));
        $this->assertSame(['s3:write'], $this->s3->calls);
        $this->assertSame([1 => true], $metadata['parts'] ?? null);
        $this->assertSame(1, $metadata['chunks'] ?? null);
    }

    public function testFinalizeRequiresAllS3Parts(): void
    {
        $metadata = [
            'uploadId' => 'upload-123',
            'parts' => [1 => 'etag-1'],
            'chunks' => 1,
        ];

        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('Missing chunk 2');
        $this->s3->finalize('/root/file.txt', 2, $metadata);
    }

    public function testFinalizeCompletesS3PartsInNumericOrder(): void
    {
        $metadata = [
            'uploadId' => 'upload-123',
            'parts' => [
                10 => 'etag-10',
                9 => 'etag-9',
                8 => 'etag-8',
                7 => 'etag-7',
                6 => 'etag-6',
                5 => 'etag-5',
                4 => 'etag-4',
                3 => 'etag-3',
                2 => 'etag-2',
                1 => 'etag-1',
            ],
            'chunks' => 10,
        ];

        $this->assertTrue($this->s3->finalize('/root/file.txt', 10, $metadata));

        $part1 = strpos($this->s3->completedBody, '<PartNumber>1</PartNumber>');
        $part2 = strpos($this->s3->completedBody, '<PartNumber>2</PartNumber>');
        $part10 = strpos($this->s3->completedBody, '<PartNumber>10</PartNumber>');

        $this->assertNotFalse($part1);
        $this->assertNotFalse($part2);
        $this->assertNotFalse($part10);
        $this->assertLessThan($part2, $part1);
        $this->assertLessThan($part10, $part2);
    }

    public function testFinalizeSendsCompleteBodyAsXml(): void
    {
        $metadata = [
            'uploadId' => 'upload-123',
            'parts' => [1 => 'etag-1', 2 => 'etag-2'],
            'chunks' => 2,
        ];

        $this->assertTrue($this->s3->finalize('/root/file.txt', 2, $metadata));
        $this->assertSame('application/xml', $this->s3->headersByOperation['s3:completeMultipartUpload']['content-type']);
    }

    private function device(ScriptedClient $client): S3
    {
        return new S3(
            root: '/root',
            accessKey: 'test-key',
            secretKey: 'test-secret',
            host: 'https://s3.example.com',
            region: 'us-east-1',
            client: new Retry($client, new RetryStrategy(delay: 0.0)),
        );
    }

    private function objectListing(string ...$keys): Response
    {
        $body = '<ListBucketResult><KeyCount>' . \count($keys) . '</KeyCount><IsTruncated>false</IsTruncated>';
        foreach ($keys as $key) {
            $body .= '<Contents><Key>' . htmlspecialchars($key, ENT_XML1) . '</Key><Size>1</Size></Contents>';
        }
        $body .= '</ListBucketResult>';

        return $this->xml($body);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function queryOf(RequestInterface $request): array
    {
        parse_str($request->getUri()->getQuery(), $parameters);

        return $parameters;
    }

    private function slowDown(): Response
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><Error><Code>SlowDown</Code><Message>Please reduce your request rate.</Message></Error>';

        return new Response(503, body: new Stream($body))->withHeader('content-type', 'application/xml');
    }

    public function testWriteSendsSignedRequest(): void
    {
        $client = new ScriptedClient([new Response(200)->withHeader('etag', '"b10a8db164e0754105b7a99be72e3fe5"')]);

        $this->assertSame('b10a8db164e0754105b7a99be72e3fe5', $this->device($client)->write('/root/file.txt', new Stream('Hello World'), 'text/plain'), 'the ETag comes back without its quotes');
        $this->assertCount(1, $client->requests);

        $request = $client->requests[0];
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame('s3.example.com', $request->getUri()->getHost());
        $this->assertSame('/root/file.txt', $request->getUri()->getPath());
        $this->assertSame('Hello World', (string) $request->getBody());
        $this->assertSame('text/plain', $request->getHeaderLine('content-type'));
        $this->assertSame('private', $request->getHeaderLine('x-amz-acl'));
        $this->assertSame(hash('sha256', 'Hello World'), $request->getHeaderLine('x-amz-content-sha256'));
        $this->assertSame(base64_encode(md5('Hello World', true)), $request->getHeaderLine('content-md5'));
        $this->assertStringStartsWith('AWS4-HMAC-SHA256 Credential=test-key/', $request->getHeaderLine('authorization'));
        $this->assertSame('utopia-php/storage', $request->getHeaderLine('user-agent'));
    }

    public function testEndpointPathIsExcludedFromHostHeader(): void
    {
        $client = new ScriptedClient([new Response(200)->withHeader('etag', '"abc"')]);
        $device = new S3(
            root: '/',
            accessKey: 'test-key',
            secretKey: 'test-secret',
            host: 'http://minio:9000/storage/',
            region: 'us-east-1',
            client: $client,
        );

        $this->assertSame('abc', $device->write('archive/file.json', new Stream('{}'), 'application/json'));

        $request = $client->requests[0];
        $this->assertSame('minio', $request->getUri()->getHost());
        $this->assertSame(9000, $request->getUri()->getPort());
        $this->assertSame('/storage/archive/file.json', $request->getUri()->getPath());
        $this->assertSame('minio:9000', $request->getHeaderLine('host'));
    }

    public function testTransientErrorIsRetriedUntilSuccess(): void
    {
        $client = new ScriptedClient([$this->slowDown(), $this->slowDown(), new Response(200)->withHeader('etag', '"abc"')]);

        $this->assertSame('abc', $this->device($client)->write('/root/file.txt', new Stream('Hello World'), 'text/plain'));
        $this->assertCount(3, $client->requests);
    }

    public function testTransientErrorRetriesAreExhausted(): void
    {
        $client = new ScriptedClient([$this->slowDown(), $this->slowDown(), $this->slowDown(), $this->slowDown()]);

        try {
            $this->device($client)->write('/root/file.txt', new Stream('Hello World'), 'text/plain');
            self::fail('Expected exception after exhausting retries');
        } catch (RemoteException $e) {
            $this->assertSame(503, $e->getCode());
            $this->assertSame('SlowDown', $e->errorCode);
        }

        // Initial attempt plus the default three retries.
        $this->assertCount(4, $client->requests);
    }

    private function internalIncident(): Response
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><Error><Code>InternalError</Code><Message>internal incident</Message></Error>';

        return new Response(500, body: new Stream($body))->withHeader('content-type', 'application/xml');
    }

    public function testMultipartUploadSurvivesInternalErrors(): void
    {
        $client = new ScriptedClient([
            new Response(200, body: new Stream('<?xml version="1.0" encoding="UTF-8"?><InitiateMultipartUploadResult><UploadId>upload-1</UploadId></InitiateMultipartUploadResult>'))->withHeader('content-type', 'application/xml'),
            new Response(200)->withHeader('etag', '"etag-1"'),
            $this->internalIncident(),
            new Response(200)->withHeader('etag', '"etag-2"'),
            $this->internalIncident(),
            new Response(200, body: new Stream('<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUploadResult><ETag>"etag-final"</ETag></CompleteMultipartUploadResult>'))->withHeader('content-type', 'application/xml'),
        ]);
        $device = $this->device($client);

        $metadata = [];
        $device->prepare('/root/archive.tar.gz', 'application/gzip', 2, $metadata);
        $device->upload(new Stream('first'), '/root/archive.tar.gz', 'application/gzip', 1, 2, $metadata);

        // The last chunk completes the upload.
        $this->assertSame(2, $device->upload(new Stream('second'), '/root/archive.tar.gz', 'application/gzip', 2, 2, $metadata));
        $this->assertCount(6, $client->requests);
        $this->assertSame('second', (string) $client->requests[3]->getBody());
    }

    public function testConditionalWriteIsNotReplayedAfterAnInternalError(): void
    {
        // The first attempt may have written the object, so a replay would fail its own If-None-Match.
        $client = new ScriptedClient([$this->internalIncident()]);

        try {
            $this->device($client)->create('/root/file.txt', new Stream('Hello World'), 'text/plain');
            self::fail('Expected the internal error to surface');
        } catch (RemoteException $e) {
            $this->assertSame('InternalError', $e->errorCode);
        }

        $this->assertCount(1, $client->requests);
    }

    public function testTimedOutPartIsReplayed(): void
    {
        $client = new ScriptedClient([
            new TimeoutException(new Request('PUT', Uri::parse('https://s3.example.com/root/file.txt')), 'Operation timed out', \CURLE_OPERATION_TIMEDOUT),
            new Response(200)->withHeader('etag', '"abc"'),
        ]);

        $this->assertSame('abc', $this->device($client)->write('/root/file.txt', new Stream('Hello World'), 'text/plain'));
        $this->assertCount(2, $client->requests);
        $this->assertSame('Hello World', (string) $client->requests[1]->getBody());
    }

    public function testTimedOutMultipartCreationIsNotReplayed(): void
    {
        $client = new ScriptedClient([
            new TimeoutException(new Request('POST', Uri::parse('https://s3.example.com/root/file.txt')), 'Operation timed out', \CURLE_OPERATION_TIMEDOUT),
        ]);

        $metadata = [];
        try {
            $this->device($client)->prepare('/root/file.txt', 'text/plain', 2, $metadata);
            self::fail('Expected the timeout to surface');
        } catch (TransportException) {
        }

        $this->assertCount(1, $client->requests);
        $this->assertArrayNotHasKey('uploadId', $metadata);
    }

    public function testWrappedLegacyClientRetainsBufferedStreamingFallback(): void
    {
        $legacy = new ScriptedClient([new Response(200, body: new Stream('original'))]);
        $client = new Retry(new \Utopia\Client\Client($legacy));
        $device = new S3('/root', 'key', 'secret', 'https://s3.example.com', 'us-east-1', client: $client);
        $body = '';
        $device->stream('/root/object', static function (string $chunk) use (&$body): void {
            $body .= $chunk;
        });
        $this->assertSame('original', $body);
        $this->assertCount(1, $legacy->requests);
    }

    public function testNoSuchKeyBecomesNotFoundException(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><Error><Code>NoSuchKey</Code><Message>The specified key does not exist.</Message></Error>';
        $client = new ScriptedClient([new Response(404, body: new Stream($body))]);

        $this->expectException(NotFoundException::class);
        $this->device($client)->read('/root/missing.txt');
    }

    public function testHeadNotFoundHasNoBodyButThrowsNotFound(): void
    {
        // HEAD error responses carry no body, so the 404 status is the only signal.
        $client = new ScriptedClient([new Response(404)]);

        $this->expectException(NotFoundException::class);
        $this->device($client)->getFileSize('/root/missing.txt');
    }

    public function testTransportFailureIsWrapped(): void
    {
        $psrError = new NetworkException(new Request('PUT', Uri::parse('https://s3.example.com/root')), 'Connection reset');
        $client = new ScriptedClient([$psrError]);

        try {
            $this->device($client)->write('/root/file.txt', new Stream('Hello World'), 'text/plain');
            self::fail('Expected transport exception');
        } catch (TransportException $e) {
            $this->assertSame($psrError, $e->getPrevious());
        }
    }

    public function testEveryFailureIsCatchableAsStorageException(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><Error><Code>AccessDenied</Code><Message>Access denied.</Message></Error>';
        $client = new ScriptedClient([new Response(403, body: new Stream($body))]);

        try {
            $this->device($client)->read('/root/file.txt');
            self::fail('Expected storage exception');
        } catch (StorageException $e) {
            $this->assertInstanceOf(RemoteException::class, $e);
            $this->assertSame('Access denied.', $e->getMessage());
            $this->assertSame(403, $e->getCode());
        }
    }

    private function bucketDevice(): TestableS3
    {
        $s3 = new TestableS3(
            root: '/root',
            accessKey: 'test-key',
            secretKey: 'test-secret',
            host: 'https://my-bucket.s3.example.com',
            region: 'us-east-1',
            bucket: 'my-bucket',
        );
        $s3->objectExists = true;

        return $s3;
    }

    public function testCopySameDeviceRunsServerSide(): void
    {
        $s3 = $this->bucketDevice();

        $this->assertTrue($s3->copy('/root/a.jpg', '/root/b.jpg'));
        $this->assertSame(['s3:info', 's3:copyObject'], $s3->calls);

        $amzHeaders = $s3->amzHeadersByOperation['s3:copyObject'][0];
        $this->assertSame('/my-bucket/root/a.jpg', $amzHeaders['x-amz-copy-source']);
        $this->assertSame('COPY', $amzHeaders['x-amz-metadata-directive']);
    }

    public function testCopyLargeObjectUsesMultipartServerSideCopy(): void
    {
        $s3 = $this->bucketDevice();
        $s3->infoContentLength = (string) (6 * 1024 * 1024 * 1024); // 6 GB — above the CopyObject limit

        $this->assertTrue($s3->copy('/root/a.bin', '/root/b.bin'));
        $this->assertSame([
            's3:info',
            's3:createMultipartUpload',
            's3:uploadPartCopy',
            's3:uploadPartCopy',
            's3:completeMultipartUpload',
        ], $s3->calls);

        $ranges = array_column($s3->amzHeadersByOperation['s3:uploadPartCopy'], 'x-amz-copy-source-range');
        $this->assertSame(['bytes=0-5368709119', 'bytes=5368709120-6442450943'], $ranges);
        $this->assertStringContainsString('etag-2', $s3->completedBody);
    }

    /**
     * Nothing tells a copy whose replayed completion finds the upload gone apart from
     * one that was aborted, so it is reported as failed: repeating a copy is safe,
     * while a false success would let move() delete its source.
     */
    public function testLargeCopyWithAnUnprovenCompletionFails(): void
    {
        $xml = static fn (string $body): Response => new Response(200, body: new Stream('<?xml version="1.0" encoding="UTF-8"?>' . $body))->withHeader('content-type', 'application/xml');
        $client = new ScriptedClient([
            new Response(200)->withHeader('content-length', '6442450944'),
            $xml('<InitiateMultipartUploadResult><UploadId>upload-1</UploadId></InitiateMultipartUploadResult>'),
            $xml('<CopyPartResult><ETag>"etag-1"</ETag></CopyPartResult>'),
            $xml('<CopyPartResult><ETag>"etag-2"</ETag></CopyPartResult>'),
            new TimeoutException(new Request('POST', Uri::parse('https://s3.example.com/root/b.bin')), 'Operation timed out', \CURLE_OPERATION_TIMEDOUT),
            new Response(404, body: new Stream('<?xml version="1.0" encoding="UTF-8"?><Error><Code>NoSuchUpload</Code><Message>The specified upload does not exist.</Message></Error>')),
            new Response(204),
        ]);
        $device = new S3(
            root: '/root',
            accessKey: 'test-key',
            secretKey: 'test-secret',
            host: 'https://my-bucket.s3.example.com',
            region: 'us-east-1',
            client: new Retry($client, new RetryStrategy(delay: 0.0)),
            bucket: 'my-bucket',
        );

        $this->expectException(NotFoundException::class);
        $device->copy('/root/a.bin', '/root/b.bin');
    }

    public function testCopyWithoutBucketFallsBackToStreaming(): void
    {
        $this->s3->objectExists = true;

        $this->assertTrue($this->s3->copy('/root/a.jpg', '/root/b.jpg'));
        $this->assertNotContains('s3:copyObject', $this->s3->calls);
        $this->assertContains('s3:get', $this->s3->calls);
        $this->assertContains('s3:write', $this->s3->calls);
    }

    public function testZeroLengthReadReturnsEmptyStreamWithoutARequest(): void
    {
        $client = new ScriptedClient([]);

        $this->assertSame('', (string) $this->device($client)->read('/root/file.txt', 5, 0));
        $this->assertCount(0, $client->requests);
    }

    public function testExistsReturnsFalseOnlyForNotFound(): void
    {
        $client = new ScriptedClient([new Response(404)]);

        $this->assertFalse($this->device($client)->exists('/root/missing.txt'));
    }

    public function testExistsPropagatesTransientErrors(): void
    {
        // One initial attempt plus the default three retries, all throttled.
        $client = new ScriptedClient([$this->slowDown(), $this->slowDown(), $this->slowDown(), $this->slowDown()]);

        $this->expectException(RemoteException::class);
        $this->device($client)->exists('/root/file.txt');
    }

    public function testErrorMessageIncludesAmzRequestIds(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><Error><Code>AccessDenied</Code><Message>Access denied.</Message></Error>';
        $response = new Response(403, body: new Stream($body))
            ->withHeader('x-amz-request-id', 'REQ123')
            ->withHeader('x-amz-id-2', 'HOST456');
        $client = new ScriptedClient([$response]);

        try {
            $this->device($client)->read('/root/file.txt');
            self::fail('Expected remote exception');
        } catch (RemoteException $e) {
            $this->assertStringContainsString('Access denied.', $e->getMessage());
            $this->assertStringContainsString('request-id: REQ123', $e->getMessage());
            $this->assertStringContainsString('id-2: HOST456', $e->getMessage());
        }
    }

    public function testCopyAbortsMultipartUploadOnFailure(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'utopia-storage-' . uniqid();
        mkdir($dir);
        $sourcePath = $dir . DIRECTORY_SEPARATOR . 'src.bin';
        file_put_contents($sourcePath, str_repeat('a', 30));

        $this->s3->failPart = 2;

        try {
            new Local($dir)->copy($sourcePath, '/root/dest.bin', $this->s3, 10);
            self::fail('Expected the injected part failure to surface');
        } catch (RemoteException $e) {
            $this->assertSame('Injected part failure', $e->getMessage());
        } finally {
            unlink($sourcePath);
            rmdir($dir);
        }

        $this->assertContains('s3:createMultipartUpload', $this->s3->calls);
        $this->assertContains('s3:abort', $this->s3->calls);
        $this->assertNotContains('s3:completeMultipartUpload', $this->s3->calls);
    }

    public function testXmlListingIsDecodedIntoTypedFiles(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult><KeyCount>2</KeyCount><IsTruncated>true</IsTruncated><MaxKeys>1000</MaxKeys><NextContinuationToken>next-token</NextContinuationToken>'
            . '<Contents><Key>root/a.txt</Key><Size>11</Size><LastModified>2026-01-02T03:04:05.000Z</LastModified><ETag>&quot;abc123&quot;</ETag></Contents>'
            . '<Contents><Key>root/b.txt</Key><Size>22</Size><LastModified>2026-01-02T03:04:06.000Z</LastModified><ETag>&quot;def456&quot;</ETag></Contents>'
            . '</ListBucketResult>';
        $client = new ScriptedClient([new Response(200, body: new Stream($body))->withHeader('content-type', 'application/xml')]);

        $list = $this->device($client)->listFiles('/root/testing');

        $this->assertCount(2, $list->files);
        $this->assertSame('root/a.txt', $list->files[0]->path);
        $this->assertSame(11, $list->files[0]->size);
        $this->assertSame('abc123', $list->files[0]->etag);
        $this->assertSame('2026-01-02', $list->files[0]->modifiedAt?->format('Y-m-d'));
        $this->assertSame('next-token', $list->cursor);
    }

    /** A lone element decodes as one associative entry rather than a list — the consumer must handle both. */
    public function testXmlListingWithSingleObjectIsDecoded(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult><KeyCount>1</KeyCount><IsTruncated>false</IsTruncated>'
            . '<Contents><Key>root/a.txt</Key><Size>11</Size><LastModified>2026-01-02T03:04:05.000Z</LastModified><ETag>&quot;abc123&quot;</ETag></Contents>'
            . '</ListBucketResult>';
        $client = new ScriptedClient([new Response(200, body: new Stream($body))->withHeader('content-type', 'application/xml')]);

        $list = $this->device($client)->listFiles('/root/testing');

        $this->assertCount(1, $list->files);
        $this->assertSame('root/a.txt', $list->files[0]->path);
        $this->assertSame(11, $list->files[0]->size);
        $this->assertNull($list->cursor);
    }

    public function testRecursiveDeleteFollowsTheContinuationToken(): void
    {
        $page = '<ListBucketResult><KeyCount>1</KeyCount><IsTruncated>true</IsTruncated><NextContinuationToken>page-2</NextContinuationToken>'
            . '<Contents><Key>root/a/1.txt</Key><Size>1</Size></Contents></ListBucketResult>';
        $client = new ScriptedClient([
            new Response(204),
            $this->xml($page),
            new Response(200),
            $this->objectListing('root/a/2.txt'),
            new Response(200),
        ]);

        $this->assertTrue($this->device($client)->delete('/root/a', true));

        $this->assertCount(5, $client->requests);
        [, $first, $firstBatch, $second, $secondBatch] = $client->requests;
        $this->assertSame('GET', $first->getMethod());
        $this->assertArrayNotHasKey('continuation-token', $this->queryOf($first));
        $this->assertSame('GET', $second->getMethod());
        $this->assertSame('page-2', $this->queryOf($second)['continuation-token'] ?? null);
        $this->assertSame('root/a/', $this->queryOf($second)['prefix'] ?? null);

        foreach ([[$firstBatch, ['root/a/1.txt']], [$secondBatch, ['root/a/2.txt']]] as [$batch, $expected]) {
            $this->assertSame('POST', $batch->getMethod());
            $xml = simplexml_load_string((string) $batch->getBody());
            $this->assertNotFalse($xml);
            $keys = [];
            foreach ($xml->Object as $object) {
                $keys[] = (string) $object->Key;
            }
            $this->assertSame($expected, $keys, 'each batch deletes the keys of its own page');
        }
    }

    public function testAnEmptyTruncatedPageDoesNotEndTheDelete(): void
    {
        // S3 may answer a page with no keys and still hand back a token for the next one.
        $empty = '<ListBucketResult><KeyCount>0</KeyCount><IsTruncated>true</IsTruncated><NextContinuationToken>page-2</NextContinuationToken></ListBucketResult>';
        $client = new ScriptedClient([
            $this->xml($empty),
            $this->objectListing('root/a/1.txt'),
            new Response(200),
        ]);

        $this->assertTrue($this->device($client)->deletePath('a'));

        $this->assertCount(3, $client->requests);
        [, $second, $batch] = $client->requests;
        $this->assertSame('GET', $second->getMethod());
        $this->assertSame('page-2', $this->queryOf($second)['continuation-token'] ?? null);
        $this->assertSame('POST', $batch->getMethod());
        $xml = simplexml_load_string((string) $batch->getBody());
        $this->assertNotFalse($xml);
        $this->assertSame('root/a/1.txt', (string) $xml->Object->Key);
    }

    /**
     * @return \Iterator<string, array{list<string>, string}>
     */
    public static function unfollowableListings(): \Iterator
    {
        // A 200 that is not a ListObjectsV2 result, such as a bucket listing from a misrouted request.
        yield 'not a ListObjectsV2 result' => [
            ['<ListAllMyBucketsResult><Owner><ID>owner</ID></Owner><Buckets></Buckets></ListAllMyBucketsResult>'],
            'Unexpected S3 list response for prefix root/a/',
        ];
        // A ListObjects V1 answer carries NextMarker, never NextContinuationToken.
        yield 'truncated without a continuation token' => [
            ['<ListBucketResult><IsTruncated>true</IsTruncated><NextMarker>root/a/1.txt</NextMarker></ListBucketResult>'],
            'Truncated S3 list response without a continuation token for prefix root/a/',
        ];
        yield 'the same continuation token twice' => [
            array_fill(0, 2, '<ListBucketResult><KeyCount>0</KeyCount><IsTruncated>true</IsTruncated><NextContinuationToken>page-2</NextContinuationToken></ListBucketResult>'),
            'S3 list response repeated the continuation token for prefix root/a/',
        ];
    }

    /**
     * @param  list<string>  $pages
     */
    #[DataProvider('unfollowableListings')]
    public function testAListingThatCannotBeFollowedIsAnError(array $pages, string $message): void
    {
        $client = new ScriptedClient(array_map($this->xml(...), $pages));

        try {
            $this->device($client)->deletePath('a');
            self::fail('A listing that cannot be followed must not report the directory as deleted');
        } catch (RemoteException $error) {
            $this->assertSame($message, $error->getMessage());
        }

        $this->assertSame(array_fill(0, \count($pages), 'GET'), array_map(fn (RequestInterface $request): string => $request->getMethod(), $client->requests), 'nothing is deleted');
    }

    /**
     * @return \Iterator<string, array{string, string, ?string}>
     */
    public static function failedBatchDeletes(): \Iterator
    {
        yield 'two keys' => [
            '<DeleteResult><Error><Key>root/a/1.txt</Key><Code>AccessDenied</Code><Message>Access Denied</Message></Error>'
            . '<Error><Key>root/a/2.txt</Key><Code>InternalError</Code><Message>Internal Error</Message></Error></DeleteResult>',
            'Failed to delete 2 objects under root/a/: root/a/1.txt (AccessDenied: Access Denied), root/a/2.txt (InternalError: Internal Error)',
            'AccessDenied',
        ];
        yield 'an entry without a key' => [
            '<DeleteResult><Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error></DeleteResult>',
            'Failed to delete 1 objects under root/a/: ? (AccessDenied: Access Denied)',
            'AccessDenied',
        ];
        yield 'an error without entries' => ['<DeleteResult><Error/></DeleteResult>', 'Failed to delete 2 objects under root/a/', null];
        yield 'the whole batch' => ['<Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>', 'Failed to delete 2 objects under root/a/', 'AccessDenied'];
    }

    #[DataProvider('failedBatchDeletes')]
    public function testKeysTheServiceFailedToDeleteAreAnError(string $body, string $message, ?string $code): void
    {
        $client = new ScriptedClient([
            $this->xml('<ListBucketResult><KeyCount>2</KeyCount><IsTruncated>true</IsTruncated><NextContinuationToken>page-2</NextContinuationToken>'
                . '<Contents><Key>root/a/1.txt</Key><Size>1</Size></Contents><Contents><Key>root/a/2.txt</Key><Size>1</Size></Contents></ListBucketResult>'),
            $this->xml($body),
            $this->objectListing(),
        ]);

        try {
            $this->device($client)->deletePath('a');
            self::fail('Keys the service could not delete must not be reported as deleted');
        } catch (RemoteException $error) {
            $this->assertSame($message, $error->getMessage());
            $this->assertSame($code, $error->errorCode);
        }

        $this->assertCount(2, $client->requests, 'no further page is listed once a batch failed');
    }

    public function testFinalizeCompletesOverAnExistingObject(): void
    {
        // The upload replaces whatever is at the path; an object already there is no reason to skip completion.
        $this->s3->objectExists = true;
        $metadata = [];
        $this->s3->prepare('/root/file.txt', 'text/plain', 2, $metadata);
        $this->s3->upload(new Stream('a'), '/root/file.txt', 'text/plain', 1, 0, $metadata);
        $this->s3->upload(new Stream('b'), '/root/file.txt', 'text/plain', 2, 0, $metadata);

        $this->assertTrue($this->s3->finalize('/root/file.txt', 2, $metadata));
        $this->assertContains('s3:completeMultipartUpload', $this->s3->calls);
    }

    public function testFinalizeTwiceIsNotAnError(): void
    {
        $metadata = [];
        $this->s3->prepare('/root/file.txt', 'text/plain', 2, $metadata);
        $this->s3->upload(new Stream('a'), '/root/file.txt', 'text/plain', 1, 0, $metadata);
        $this->s3->upload(new Stream('b'), '/root/file.txt', 'text/plain', 2, 0, $metadata);

        $this->assertTrue($this->s3->finalize('/root/file.txt', 2, $metadata));
        $this->assertTrue($this->s3->finalize('/root/file.txt', 2, $metadata), 'the upload is gone but the object is there');
        $this->assertCount(2, array_keys($this->s3->calls, 's3:completeMultipartUpload', true));
    }

    public function testFinalizeOfAnAbortedUploadFails(): void
    {
        $metadata = [];
        $this->s3->prepare('/root/file.txt', 'text/plain', 2, $metadata);
        $this->s3->upload(new Stream('a'), '/root/file.txt', 'text/plain', 1, 0, $metadata);
        $this->s3->upload(new Stream('b'), '/root/file.txt', 'text/plain', 2, 0, $metadata);
        $this->s3->completedBody = 'aborted elsewhere'; // makes the next completion answer NoSuchUpload

        $this->expectException(NotFoundException::class);
        $this->s3->finalize('/root/file.txt', 2, $metadata);
    }

    public function testASingleChunkOfAnUploadWithUnknownCountIsCompleted(): void
    {
        $this->s3->objectExists = true; // the object being replaced
        $metadata = [];
        $this->s3->upload(new Stream('a'), '/root/file.txt', 'text/plain', 1, 0, $metadata);

        $this->assertNotContains('s3:completeMultipartUpload', $this->s3->calls);
        $this->assertTrue($this->s3->finalize('/root/file.txt', 1, $metadata));
        $this->assertContains('s3:completeMultipartUpload', $this->s3->calls, 'one part is still a part, not a whole object written already');
    }

    public function testUnknownChunkCountNeverFinalizesOnItsOwn(): void
    {
        $metadata = [];
        $this->s3->upload(new Stream('a'), '/root/file.txt', 'text/plain', 1, 0, $metadata);
        $this->s3->upload(new Stream('b'), '/root/file.txt', 'text/plain', 2, 0, $metadata);
        $this->s3->upload(new Stream('c'), '/root/file.txt', 'text/plain', 3, 0, $metadata);

        $this->assertContains('s3:createMultipartUpload', $this->s3->calls);
        $this->assertNotContains('s3:completeMultipartUpload', $this->s3->calls);
        $this->assertTrue($this->s3->finalize('/root/file.txt', 3, $metadata));
        $this->assertContains('s3:completeMultipartUpload', $this->s3->calls);
    }

    public function testFinalizeOfACompletedUploadWithoutItsIdIsNotAnError(): void
    {
        $this->s3->objectExists = true;
        $metadata = ['parts' => [1 => 'etag-1', 2 => 'etag-2'], 'chunks' => 2];

        $this->assertTrue($this->s3->finalize('/root/file.txt', 2, $metadata), 'the object is there, so the upload was completed');
        $this->assertNotContains('s3:completeMultipartUpload', $this->s3->calls);
    }

    public function testFinalizeOfAnUploadThatNeverExistedFails(): void
    {
        $metadata = ['parts' => [1 => 'etag-1', 2 => 'etag-2'], 'chunks' => 2];

        $this->assertFalse($this->s3->finalize('/root/file.txt', 2, $metadata));
    }

    public function testWritesCarryNoAclWhenNoneIsConfigured(): void
    {
        $client = new ScriptedClient([new Response(200)->withHeader('etag', '"abc"')]);
        $device = new S3(
            root: '/root',
            accessKey: 'test-key',
            secretKey: 'test-secret',
            host: 'https://s3.example.com',
            region: 'us-east-1',
            acl: null,
            client: $client,
        );

        $this->assertSame('abc', $device->write('/root/file.txt', new Stream('Hello World'), 'text/plain'));
        $this->assertFalse($client->requests[0]->hasHeader('x-amz-acl'));
    }

    public function testMultipartUploadCarriesNoAclWhenNoneIsConfigured(): void
    {
        $s3 = new TestableS3(
            root: '/root',
            accessKey: 'test-key',
            secretKey: 'test-secret',
            host: 'https://s3.example.com',
            region: 'us-east-1',
            acl: null,
        );
        $metadata = [];
        $s3->prepare('/root/file.txt', 'text/plain', 2, $metadata);

        $this->assertSame([[]], $s3->amzHeadersByOperation['s3:createMultipartUpload']);
    }

    public function testCreateWritesOnlyWhereNothingIsAndReturnsTheEtag(): void
    {
        $client = new ScriptedClient([new Response(200)->withHeader('etag', '"abc123"')]);

        $this->assertSame('abc123', $this->device($client)->create('/root/lock', new Stream('token'), 'text/plain'));

        $request = $client->requests[0];
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame('*', $request->getHeaderLine('if-none-match'));
        $this->assertFalse($request->hasHeader('if-match'));
        $this->assertSame('token', (string) $request->getBody());
        $this->assertStringContainsString('if-none-match', $request->getHeaderLine('authorization'), 'the condition is signed');
    }

    public function testCreateOverAnExistingObjectIsRefused(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><Error><Code>PreconditionFailed</Code><Message>At least one of the pre-conditions you specified did not hold</Message></Error>';
        $client = new ScriptedClient([new Response(412, body: new Stream($body))]);

        try {
            $this->device($client)->create('/root/lock', new Stream('token'), 'text/plain');
            self::fail('Expected precondition failure');
        } catch (PreconditionFailedException $e) {
            $this->assertSame(412, $e->getCode());
            $this->assertStringStartsWith('At least one of the pre-conditions', $e->getMessage());
        }
    }

    public function testReplaceNamesTheEtagItWritesOver(): void
    {
        $client = new ScriptedClient([new Response(200)->withHeader('etag', '"new"')]);

        $this->assertSame('new', $this->device($client)->replace('/root/lock', new Stream('token'), 'old', 'text/plain'));

        $request = $client->requests[0];
        $this->assertSame('"old"', $request->getHeaderLine('if-match'), 'the ETag goes out quoted, as S3 reports it');
        $this->assertFalse($request->hasHeader('if-none-match'));
    }

    public function testReplaceOfAnotherVersionIsRefused(): void
    {
        $client = new ScriptedClient([new Response(412)]);

        $this->expectException(PreconditionFailedException::class);
        $this->device($client)->replace('/root/lock', new Stream('token'), 'old', 'text/plain');
    }

    public function testReadWithEtagSendsIfMatch(): void
    {
        $client = new ScriptedClient([new Response(206, body: new Stream('Hello'))->withHeader('etag', '"abc"')->withHeader('content-range', 'bytes 0-4/11')]);

        $this->assertSame('Hello', (string) $this->device($client)->read('/root/file.txt', 0, 5, 'abc'));

        $request = $client->requests[0];
        $this->assertSame('bytes=0-4', $request->getHeaderLine('range'));
        $this->assertSame('"abc"', $request->getHeaderLine('if-match'));
    }

    public function testReadOfAReplacedObjectIsRefused(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><Error><Code>PreconditionFailed</Code><Message>At least one of the pre-conditions you specified did not hold</Message></Error>';
        $client = new ScriptedClient([new Response(412, body: new Stream($body))]);

        $this->expectException(PreconditionFailedException::class);
        $this->device($client)->read('/root/file.txt', 0, 5, 'abc');
    }

    public function testGetFileInfoReadsTheHeaders(): void
    {
        $client = new ScriptedClient([new Response(200)
            ->withHeader('etag', '"abc123"')
            ->withHeader('content-length', '11')
            ->withHeader('content-type', 'text/plain')
            ->withHeader('last-modified', 'Fri, 18 Sep 2026 08:41:25 GMT')]);

        $info = $this->device($client)->getFileInfo('/root/file.txt');

        $this->assertSame('HEAD', $client->requests[0]->getMethod());
        $this->assertSame('/root/file.txt', $info->path);
        $this->assertSame('text/plain', $info->mimeType);
        $this->assertSame(11, $info->size);
        $this->assertSame('abc123', $info->etag, 'without the quotes, like every other ETag the library hands out');
        $this->assertSame('2026-09-18T08:41:25+00:00', $info->modifiedAt?->format(DATE_ATOM));
    }

    #[DataProvider('fileInfoContentTypes')]
    public function testFileInfoNormalizesOptionalContentType(?string $header, ?string $expected): void
    {
        $response = new Response(200);
        if ($header !== null) {
            $response = $response->withHeader('content-type', $header);
        }
        $client = new ScriptedClient([$response]);
        $info = $this->device($client)->getFileInfo('/root/file.bin');
        $this->assertSame($expected, $info->mimeType);
        $this->assertCount(1, $client->requests);
        $this->assertSame('HEAD', $client->requests[0]->getMethod());
    }

    /** @return array<string, array{?string, ?string}> */
    public static function fileInfoContentTypes(): array
    {
        return [
            'missing' => [null, null],
            'empty' => ['', null],
            'parameters' => ['application/octet-stream; charset=utf-8', 'application/octet-stream'],
            'whitespace' => [' text/plain ; charset=utf-8', 'text/plain'],
        ];
    }

    public function testGetFileInfoOfAMissingObjectThrowsNotFound(): void
    {
        $client = new ScriptedClient([new Response(404)]);

        $this->expectException(NotFoundException::class);
        $this->device($client)->getFileInfo('/root/missing.txt');
    }

    public function testAWriteWithoutAnEtagInTheResponseIsAnError(): void
    {
        $client = new ScriptedClient([new Response(200)]);

        $this->expectException(RemoteException::class);
        $this->expectExceptionMessage('Missing ETag');
        $this->device($client)->write('/root/file.txt', new Stream('Hello World'), 'text/plain');
    }

    public function testUploadListingIsDecodedIntoTypedUploads(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><ListMultipartUploadsResult><Bucket>my-bucket</Bucket><Prefix>root/</Prefix><IsTruncated>true</IsTruncated><NextKeyMarker>root/b.mp4</NextKeyMarker><NextUploadIdMarker>upload-2</NextUploadIdMarker>'
            . '<Upload><Key>root/a.mp4</Key><UploadId>upload-1</UploadId><Initiated>2026-01-02T03:04:05.000Z</Initiated></Upload>'
            . '<Upload><Key>root/b.mp4</Key><UploadId>upload-2</UploadId><Initiated>2026-01-02T03:04:06.000Z</Initiated></Upload>'
            . '</ListMultipartUploadsResult>';
        $client = new ScriptedClient([new Response(200, body: new Stream($body))->withHeader('content-type', 'application/xml')]);

        $list = $this->device($client)->listUploads('/root/', 2);

        $this->assertSame('/', $client->requests[0]->getUri()->getPath());
        parse_str($client->requests[0]->getUri()->getQuery(), $query);
        $this->assertSame(['uploads' => '', 'prefix' => 'root/', 'max-uploads' => '2'], $query);

        $this->assertCount(2, $list->uploads);
        $this->assertSame('root/a.mp4', $list->uploads[0]->path);
        $this->assertSame('upload-1', $list->uploads[0]->uploadId);
        $this->assertSame('2026-01-02', $list->uploads[0]->initiatedAt?->format('Y-m-d'));
        $this->assertNotNull($list->cursor);

        // The cursor carries both markers back.
        $client = new ScriptedClient([new Response(200, body: new Stream('<ListMultipartUploadsResult><IsTruncated>false</IsTruncated></ListMultipartUploadsResult>'))->withHeader('content-type', 'application/xml')]);
        $list = $this->device($client)->listUploads('/root/', 2, $list->cursor);
        parse_str($client->requests[0]->getUri()->getQuery(), $query);
        $this->assertSame('root/b.mp4', $query['key-marker'] ?? null);
        $this->assertSame('upload-2', $query['upload-id-marker'] ?? null);
        $this->assertSame([], $list->uploads);
        $this->assertNull($list->cursor);
    }

    /** A lone element decodes as one associative entry rather than a list — the consumer must handle both. */
    public function testUploadListingWithSingleUploadIsDecoded(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><ListMultipartUploadsResult><IsTruncated>false</IsTruncated>'
            . '<Upload><Key>root/a.mp4</Key><UploadId>upload-1</UploadId></Upload>'
            . '</ListMultipartUploadsResult>';
        $client = new ScriptedClient([new Response(200, body: new Stream($body))->withHeader('content-type', 'application/xml')]);

        $list = $this->device($client)->listUploads('/root/');

        $this->assertCount(1, $list->uploads);
        $this->assertSame('upload-1', $list->uploads[0]->uploadId);
        $this->assertNotInstanceOf(\DateTimeImmutable::class, $list->uploads[0]->initiatedAt);
        $this->assertNull($list->cursor);
    }

    public function testUploadListingRejectsAForeignCursor(): void
    {
        $client = new ScriptedClient([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->device($client)->listUploads('/root/', 10, 'next-token');
    }

    private function xml(string $body): Response
    {
        return new Response(200, body: new Stream('<?xml version="1.0" encoding="UTF-8"?>' . $body))->withHeader('content-type', 'application/xml');
    }

    /**
     * @return iterable<string, array{\Closure(ScriptedClient): S3, string}>
     */
    public static function virtualHostedSubclasses(): iterable
    {
        yield 'AWS' => [static fn (ScriptedClient $client): S3 => new AWS('/root', 'key', 'secret', 'my-bucket', AWS::US_EAST_1, client: $client), 'my-bucket.s3.us-east-1.amazonaws.com'];
        yield 'AWS China' => [static fn (ScriptedClient $client): S3 => new AWS('/root', 'key', 'secret', 'my-bucket', AWS::CN_NORTH_1, client: $client), 'my-bucket.s3.cn-north-1.amazonaws.cn'];
        yield 'DOSpaces' => [static fn (ScriptedClient $client): S3 => new DOSpaces('/root', 'key', 'secret', 'my-bucket', DOSpaces::NYC3, client: $client), 'my-bucket.nyc3.digitaloceanspaces.com'];
        yield 'Backblaze' => [static fn (ScriptedClient $client): S3 => new Backblaze('/root', 'key', 'secret', 'my-bucket', Backblaze::US_WEST_004, client: $client), 'my-bucket.s3.us-west-004.backblazeb2.com'];
        yield 'Linode' => [static fn (ScriptedClient $client): S3 => new Linode('/root', 'key', 'secret', 'my-bucket', Linode::EU_CENTRAL_1, client: $client), 'my-bucket.eu-central-1.linodeobjects.com'];
        yield 'Wasabi' => [static fn (ScriptedClient $client): S3 => new Wasabi('/root', 'key', 'secret', 'my-bucket', Wasabi::EU_CENTRAL_1, client: $client), 'my-bucket.s3.eu-central-1.wasabisys.com'];
    }

    /**
     * @param  \Closure(ScriptedClient): S3  $build
     */
    #[DataProvider('virtualHostedSubclasses')]
    public function testVirtualHostedSubclassesKeepTheirRequestUris(\Closure $build, string $host): void
    {
        $client = new ScriptedClient([
            new Response(200)->withHeader('etag', '"abc"'),
            $this->xml('<ListBucketResult><IsTruncated>false</IsTruncated></ListBucketResult>'),
            $this->xml('<ListBucketResult><IsTruncated>false</IsTruncated><Contents><Key>root/x/a</Key></Contents></ListBucketResult>'),
            new Response(200),
            new Response(200)->withHeader('content-length', '1'),
            $this->xml('<CopyObjectResult><ETag>"abc"</ETag></CopyObjectResult>'),
        ]);
        $device = $build($client);

        $device->write('/root/a', new Stream('a'));
        $this->assertSame([], $device->listFiles('/root/')->files);
        $this->assertTrue($device->deletePath('x'));
        $this->assertTrue($device->copy('/root/a', '/root/b'));

        $requests = array_map(static fn (RequestInterface $request): string => $request->getMethod() . ' ' . $request->getUri()->getPath(), $client->requests);
        $this->assertSame(['PUT /root/a', 'GET /', 'GET /', 'POST /', 'HEAD /root/a', 'PUT /root/b'], $requests);
        foreach ($client->requests as $request) {
            $this->assertSame($host, $request->getUri()->getHost());
        }

        parse_str($client->requests[1]->getUri()->getQuery(), $query);
        $this->assertSame('root/', $query['prefix'] ?? null);
        parse_str($client->requests[2]->getUri()->getQuery(), $query);
        $this->assertSame('root/x/', $query['prefix'] ?? null);
        $this->assertSame('delete=', $client->requests[3]->getUri()->getQuery());
        $this->assertStringContainsString('<Key>root/x/a</Key>', (string) $client->requests[3]->getBody());
        $this->assertSame('/my-bucket/root/a', $client->requests[5]->getHeaderLine('x-amz-copy-source'));
    }

    /**
     * @return iterable<string, array{string, string, bool|null, string, string}>
     */
    public static function endpoints(): iterable
    {
        yield 'AWS-style bucket subdomain' => ['https://my-bucket.s3.us-east-1.amazonaws.com', 'my-bucket', null, '/', 'my-bucket/root/x'];
        yield 'dotted bucket subdomain' => ['https://my.dotted.bucket.s3.example.com', 'my.dotted.bucket', null, '/', 'my.dotted.bucket/root/x'];
        yield 'uppercase bucket subdomain' => ['https://MY-BUCKET.S3.EXAMPLE.COM', 'my-bucket', null, '/', 'my-bucket/root/x'];
        yield 'host named after the bucket' => ['https://assets.example.com', 'assets.example.com', null, '/', 'assets.example.com/root/x'];
        yield 'single-label host named like the bucket' => ['http://minio:9000', 'minio', null, '/minio', 'root/x'];
        yield 'service host with a port' => ['http://storage.internal:9000', 'files', null, '/files', 'root/x'];
        yield 'scheme-less service host' => ['storage.example.com', 'files', null, '/files', 'root/x'];
        yield 'service host behind a base path' => ['https://storage.example.com/s3/', 'files', null, '/s3/files', 'root/x'];
        yield 'base path ending in the bucket' => ['https://storage.example.com/s3/files/', 'files', null, '/s3/files/', 'files/root/x'];
        yield 'endpoint path naming a mixed-case bucket' => ['http://minio:9000/MyBucket/', 'MyBucket', null, '/MyBucket/', 'MyBucket/root/x'];
        yield 'endpoint path differing from the bucket in case' => ['http://minio:9000/mybucket/', 'MyBucket', null, '/mybucket/MyBucket', 'root/x'];
        yield 'explicitly bucket-addressed' => ['https://storage.example.com', 'files', false, '/', 'files/root/x'];
        yield 'explicitly path-style' => ['https://b.example.com', 'b', true, '/b', 'root/x'];
    }

    #[DataProvider('endpoints')]
    public function testAddressingFollowsTheEndpoint(string $host, string $bucket, ?bool $pathStyle, string $uri, string $prefix): void
    {
        $client = new ScriptedClient([$this->xml('<ListBucketResult><IsTruncated>false</IsTruncated></ListBucketResult>')]);
        $options = ['client' => $client, 'bucket' => $bucket] + ($pathStyle === null ? [] : ['pathStyle' => $pathStyle]);
        $device = new S3("{$bucket}/root", 'key', 'secret', $host, 'us-east-1', ...$options);

        $device->listFiles("{$bucket}/root/x");

        $this->assertCount(1, $client->requests);
        $this->assertSame($uri, $client->requests[0]->getUri()->getPath());
        parse_str($client->requests[0]->getUri()->getQuery(), $query);
        $this->assertSame($prefix, $query['prefix'] ?? null);
    }

    /**
     * @return iterable<string, array{bool, string, string}>
     */
    public static function repeatedSlashes(): iterable
    {
        yield 'virtual-hosted' => [false, '/', 'files/root/x/'];
        yield 'path-style' => [true, '/files', 'root/x/'];
    }

    #[DataProvider('repeatedSlashes')]
    public function testBucketRequestsCollapseRepeatedSlashes(bool $pathStyle, string $uri, string $prefix): void
    {
        $client = new ScriptedClient([$this->xml('<ListBucketResult><IsTruncated>false</IsTruncated></ListBucketResult>')]);
        $device = new S3('files/root', 'key', 'secret', 'https://storage.example.com', 'us-east-1', client: $client, bucket: 'files', pathStyle: $pathStyle);

        $device->listFiles('//files//root///x//');

        $this->assertCount(1, $client->requests);
        $this->assertSame($uri, $client->requests[0]->getUri()->getPath());
        parse_str($client->requests[0]->getUri()->getQuery(), $query);
        $this->assertSame($prefix, $query['prefix'] ?? null);
    }

    /**
     * @return iterable<string, array{string|null, bool|null}>
     */
    public static function unaddressable(): iterable
    {
        yield 'path-style without a bucket' => [null, true];
        yield 'path-style with an empty bucket' => ['', true];
        yield 'bucket holding a slash' => ['my/bucket', null];
    }

    /**
     * The refusal is the outcome: a device that cannot address its bucket is never built, so there is no state to inspect.
     */
    #[DataProvider('unaddressable')]
    public function testAddressingRefusesWhatItCannotAddress(?string $bucket, ?bool $pathStyle): void
    {
        $options = ['bucket' => $bucket] + ($pathStyle === null ? [] : ['pathStyle' => $pathStyle]);

        $this->expectException(\InvalidArgumentException::class);
        new S3('root', 'key', 'secret', 'https://storage.example.com', 'us-east-1', ...$options);
    }

    public function testDeletePathReportsObjectsTheServiceKept(): void
    {
        $client = new ScriptedClient([
            $this->xml('<ListBucketResult><IsTruncated>false</IsTruncated><Contents><Key>root/x/a</Key></Contents><Contents><Key>root/x/b</Key></Contents></ListBucketResult>'),
            $this->xml('<DeleteResult><Error><Key>root/x/b</Key><Code>AccessDenied</Code><Message>Access Denied</Message></Error></DeleteResult>'),
        ]);

        try {
            $this->device($client)->deletePath('x');
            self::fail('A partial delete must not report success');
        } catch (RemoteException $e) {
            $this->assertSame('AccessDenied', $e->errorCode);
            $this->assertStringContainsString('root/x/b', $e->getMessage());
        }

        $this->assertCount(2, $client->requests);
    }
}
