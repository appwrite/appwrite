<?php

declare(strict_types=1);

namespace Utopia\Tests\Storage\Device;

use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Utopia\Client\Adapter;
use Utopia\Client\Decorator\Retry;
use Utopia\Client\Exception\NetworkException;
use Utopia\Client\Tls;
use Utopia\Psr7\Request;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Psr7\Uri;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Device\S3\Response as S3Response;
use Utopia\Storage\Device\S3\RetryStrategy;
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
    protected function call(string $method, string $uri, StreamInterface|string $data = '', array $parameters = [], array $headers = [], array $amzHeaders = [], bool $decode = true, ?callable $sink = null): S3Response
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
    public function __construct(private array $responses) {}

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

    public function withFollowRedirects(bool $enabled = true): static
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
            host: 'https://s3.example.com',
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
            ->withHeader('last-modified', 'Fri, 18 Sep 2026 08:41:25 GMT')]);

        $info = $this->device($client)->getFileInfo('/root/file.txt');

        $this->assertSame('HEAD', $client->requests[0]->getMethod());
        $this->assertSame('/root/file.txt', $info->path);
        $this->assertSame(11, $info->size);
        $this->assertSame('abc123', $info->etag, 'without the quotes, like every other ETag the library hands out');
        $this->assertSame('2026-09-18T08:41:25+00:00', $info->modifiedAt?->format(DATE_ATOM));
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
}
