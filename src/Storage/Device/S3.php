<?php

declare(strict_types=1);

namespace Utopia\Storage\Device;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\StreamInterface;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client as HttpClient;
use Utopia\Client\Decorator\Retry;
use Utopia\Psr18\StreamingClientInterface;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request;
use Utopia\Psr7\Stream;
use Utopia\Psr7\Uri;
use Utopia\Storage\Acl;
use Utopia\Storage\Device;
use Utopia\Storage\DeviceType;
use Utopia\Storage\Exception\NotFoundException;
use Utopia\Storage\Exception\PreconditionFailedException;
use Utopia\Storage\Exception\RemoteException;
use Utopia\Storage\Exception\StorageException;
use Utopia\Storage\Exception\TransportException;
use Utopia\Storage\Exception\UploadException;
use Utopia\Storage\FileInfo;
use Utopia\Storage\FileList;
use Utopia\Storage\UploadInfo;
use Utopia\Storage\UploadList;

/**
 * @see \Utopia\Tests\Storage\Device\S3Test
 *
 * @phpstan-import-type UploadMetadata from Device
 */
class S3 extends Device
{
    protected const MAX_PAGE_SIZE = 1000;

    private const int ERROR_BODY_BUFFER_SIZE = 16384;

    private const int PIPE_CHUNK_SIZE = 524288; // 512 KB

    private const int MAX_COPY_OBJECT_SIZE = 5368709120; // 5 GB — the CopyObject limit; larger objects copy server side part by part

    private readonly string $fqdn;

    private readonly string $host;

    private readonly string $path;

    private readonly ClientInterface&StreamingClientInterface $client;

    /**
     * S3 Constructor
     *
     * @param  Acl|null  $acl  Canned ACL applied to every object written, or null to write without one, which buckets that have ACLs disabled require
     * @param  (ClientInterface&StreamingClientInterface)|null  $client  PSR-18 client used for every request; defaults to `utopia-php/client` with the cURL adapter — no overall deadline (large transfers may take arbitrarily long), a stall watchdog that aborts once no bytes move for 60s, TCP keepalive, and transient-error retries via `S3\RetryStrategy`
     * @param  string|null  $bucket  Bucket name, required by the `x-amz-copy-source` header. When set, same-device `copy()` (and therefore `move()`) runs server side without moving bytes through PHP; when null it falls back to a streamed download and re-upload.
     */
    public function __construct(
        protected readonly string $root,
        private readonly string $accessKey,
        #[\SensitiveParameter]
        private readonly string $secretKey,
        string $host,
        protected readonly string $region,
        protected readonly ?Acl $acl = Acl::Private,
        (ClientInterface&StreamingClientInterface)|null $client = null,
        protected readonly ?string $bucket = null,
    ) {
        $endpoint = Uri::parse(rtrim(
            str_starts_with($host, 'http://') || str_starts_with($host, 'https://')
                ? $host
                : 'https://' . $host,
            '/',
        ));
        $authority = $endpoint->getHost();
        $port = $endpoint->getPort();
        if ($port !== null) {
            $authority .= ':' . $port;
        }
        $this->fqdn = $endpoint->getScheme() . '://' . $authority;
        $this->host = $authority;
        $this->path = rtrim($endpoint->getPath(), '/');

        $this->client = $client ?? new Retry(
            new HttpClient(new CurlAdapter(options: [
                \CURLOPT_TIMEOUT_MS => 0,
                \CURLOPT_LOW_SPEED_LIMIT => 1,
                \CURLOPT_LOW_SPEED_TIME => 60,
                \CURLOPT_TCP_KEEPALIVE => 1,
            ])),
            new S3\RetryStrategy(),
        );
    }

    public function getType(): DeviceType
    {
        return DeviceType::S3;
    }

    public function getRoot(): string
    {
        return $this->root;
    }

    public function getPath(string $filename): string
    {
        return $this->getRoot() . DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * @param  UploadMetadata  $metadata
     */
    public function prepare(string $path, string $contentType, int $chunks = 1, array &$metadata = []): void
    {
        $metadata['parts'] ??= [];
        $metadata['chunks'] ??= 0;
        $metadata['content_type'] ??= $contentType;

        if ($chunks === 1 || isset($metadata['uploadId']) && ! \in_array($metadata['uploadId'], ['', '0', [], 0], true)) {
            return;
        }

        $metadata['uploadId'] = $this->createMultipartUpload($path, $contentType);
    }

    public function finalize(string $path, int $chunks = 1, array &$metadata = []): bool
    {
        // No multipart upload to complete: either a single chunk went up as a
        // whole object, or an earlier call completed the upload and the ID is
        // gone with it. The object in place is the answer to both; without one
        // there was never an upload here, and the caller is told so.
        if (empty($metadata['uploadId'])) {
            return $this->exists($path);
        }

        $metadata['parts'] ??= [];
        for ($i = 1; $i <= $chunks; ++$i) {
            if (! \array_key_exists($i, $metadata['parts'])) {
                throw new UploadException('Missing chunk ' . $i);
            }
        }

        try {
            $this->completeMultipartUpload($path, $metadata['uploadId'], $metadata['parts']);
        } catch (NotFoundException $e) {
            // The upload is gone: completed by an earlier call, in which case
            // the object is there, or aborted, in which case it is not.
            if ($this->exists($path)) {
                return true;
            }

            throw $e;
        }

        return true;
    }

    /**
     * @param  UploadMetadata  $metadata
     */
    protected function uploadChunk(StreamInterface $data, string $path, int $chunk, int $chunks, array &$metadata): int
    {
        $contentType = $metadata['content_type'] ?? '';

        if ($chunk === 1 && $chunks === 1) {
            $this->write($path, $data, $contentType);
            $metadata['parts'][$chunk] = true;
            $metadata['chunks'] = 1;

            return 1;
        }

        if (empty($metadata['uploadId'])) {
            throw new UploadException('Missing multipart upload ID');
        }

        $metadata['parts'] ??= [];
        $metadata['chunks'] ??= 0;

        $etag = $this->uploadPart($data, $path, $contentType, $chunk, $metadata['uploadId']);
        // skip incrementing if the chunk was re-uploaded
        if (! \array_key_exists($chunk, $metadata['parts'])) {
            ++$metadata['chunks'];
        }
        $metadata['parts'][$chunk] = $etag;

        return $metadata['chunks'];
    }

    /**
     * Start Multipart Upload
     *
     * Initiate a multipart upload and return an upload ID.
     *
     *
     * @throws StorageException
     */
    protected function createMultipartUpload(string $path, string $contentType): string
    {
        $uri = $path !== '' ? '/' . str_replace(['%2F', '%3F'], ['/', '?'], rawurlencode($path)) : '/';

        $response = $this->call(
            Method::POST,
            $uri,
            '',
            ['uploads' => ''],
            headers: ['content-type' => $contentType],
            amzHeaders: $this->aclHeaders(),
        );

        $uploadId = \is_array($response->body) ? ($response->body['UploadId'] ?? null) : null;
        if (! \is_string($uploadId)) {
            throw new RemoteException('Missing upload ID in S3 response');
        }

        return $uploadId;
    }

    /**
     * Upload Part
     *
     *
     * @throws StorageException
     */
    protected function uploadPart(StreamInterface $data, string $path, string $contentType, int $chunk, string $uploadId): string
    {
        $uri = $path !== '' ? '/' . str_replace(['%2F', '%3F'], ['/', '?'], rawurlencode($path)) : '/';

        // ACL header is not allowed in parts, only createMultipartUpload accepts this header.
        $response = $this->call(
            Method::PUT,
            $uri,
            $data,
            [
                'partNumber' => $chunk,
                'uploadId' => $uploadId,
            ],
            headers: ['content-type' => $contentType],
        );

        return $response->headers['etag'] ?? throw new RemoteException('Missing ETag in S3 response');
    }

    /**
     * Complete Multipart Upload
     *
     * @param  array<int, bool|string>  $parts
     *
     * @throws StorageException
     */
    protected function completeMultipartUpload(string $path, string $uploadId, array $parts): bool
    {
        $uri = $path !== '' ? '/' . str_replace(['%2F', '%3F'], ['/', '?'], rawurlencode($path)) : '/';

        ksort($parts, SORT_NUMERIC);

        $body = '<CompleteMultipartUpload>';
        foreach ($parts as $key => $etag) {
            if (! \is_string($etag)) {
                throw new UploadException('Missing ETag for part ' . $key);
            }
            $body .= "<Part><ETag>{$etag}</ETag><PartNumber>{$key}</PartNumber></Part>";
        }
        $body .= '</CompleteMultipartUpload>';

        $this->call(
            Method::POST,
            $uri,
            $body,
            ['uploadId' => $uploadId],
            headers: ['content-type' => 'application/xml'],
        );

        return true;
    }

    /**
     * Abort Chunked Upload
     *
     *
     * @throws StorageException
     */
    public function abort(string $path, string $uploadId = ''): bool
    {
        $uri = $path !== '' ? '/' . str_replace(['%2F', '%3F'], ['/', '?'], rawurlencode($path)) : '/';
        $this->call(Method::DELETE, $uri, '', ['uploadId' => $uploadId]);

        return true;
    }

    /**
     * Read file or part of file by given path, offset and length.
     *
     * The body streams into a temporary stream as it arrives, so memory stays
     * bounded regardless of object size. With an ETag the request carries
     * `If-Match`, which S3 checks against the object it serves in the same
     * step, so a read can never mix the caller's idea of the object with the
     * bytes of a replacement.
     *
     * @throws StorageException
     */
    public function read(string $path, int $offset = 0, ?int $length = null, ?string $etag = null): StreamInterface
    {
        $uri = ($path !== '') ? '/' . str_replace('%2F', '/', rawurlencode($path)) : '/';

        if ($length === 0) {
            return new Stream('');
        }

        $headers = [];
        if ($length !== null) {
            $end = $offset + $length - 1;
            $headers['range'] = "bytes=$offset-$end";
        } elseif ($offset > 0) {
            $headers['range'] = "bytes=$offset-";
        }

        if ($etag !== null) {
            $headers['if-match'] = $this->quote($etag);
        }

        $handle = fopen('php://temp', 'r+b');
        if ($handle === false) {
            throw new StorageException('Failed to allocate read buffer');
        }

        $this->call(Method::GET, $uri, headers: $headers, decode: false, sink: static function (string $chunk) use ($handle): void {
            $written = 0;
            $total = \strlen($chunk);
            while ($written < $total) {
                $bytes = fwrite($handle, substr($chunk, $written));
                if ($bytes === false || $bytes === 0) {
                    throw new StorageException('Failed to buffer S3 read response');
                }
                $written += $bytes;
            }
        });
        rewind($handle);

        return Stream::fromResource($handle);
    }

    /**
     * Write file by given path.
     *
     *
     * @throws StorageException
     */
    public function write(string $path, StreamInterface $data, string $contentType = ''): string
    {
        return $this->put($path, $data, $contentType);
    }

    /**
     * Write an object where there is none yet, with `If-None-Match: *`, which
     * S3 checks and honours in one step.
     */
    public function create(string $path, StreamInterface $data, string $contentType = ''): string
    {
        return $this->put($path, $data, $contentType, ['if-none-match' => '*']);
    }

    /**
     * Write over the object with the given ETag, with `If-Match`, which S3
     * checks and honours in one step.
     */
    public function replace(string $path, StreamInterface $data, string $etag, string $contentType = ''): string
    {
        return $this->put($path, $data, $contentType, ['if-match' => $this->quote($etag)]);
    }

    /**
     * Send a PutObject request and return the ETag of the object written.
     *
     * @param  array<string, string>  $headers  Conditions on the write
     *
     * @throws StorageException
     */
    private function put(string $path, StreamInterface $data, string $contentType, array $headers = []): string
    {
        $uri = $path !== '' ? '/' . str_replace(['%2F', '%3F'], ['/', '?'], rawurlencode($path)) : '/';

        $response = $this->call(
            Method::PUT,
            $uri,
            $data,
            headers: ['content-type' => $contentType] + $headers,
            amzHeaders: $this->aclHeaders(),
        );

        return $this->unquote($response->headers['etag'] ?? throw new RemoteException('Missing ETag in S3 response'));
    }

    /**
     * Delete file in given path, Return true on success and false on failure.
     *
     * @see http://php.net/manual/en/function.filesize.php
     *
     * @throws StorageException
     */
    public function delete(string $path, bool $recursive = false): bool
    {
        $uri = ($path !== '') ? '/' . str_replace('%2F', '/', rawurlencode($path)) : '/';

        $this->call(Method::DELETE, $uri);

        return true;
    }

    /**
     * Get list of objects in the given path.
     *
     * @return array<mixed>
     *
     * @throws StorageException
     */
    protected function listObjects(string $prefix = '', int $maxKeys = self::MAX_PAGE_SIZE, string $continuationToken = ''): array
    {
        if ($maxKeys > self::MAX_PAGE_SIZE) {
            throw new \InvalidArgumentException('Cannot list more than ' . self::MAX_PAGE_SIZE . ' objects');
        }

        $uri = '/';
        $prefix = ltrim($prefix, '/'); /** S3 specific requirement that prefix should never contain a leading slash */
        $parameters = [
            'list-type' => 2,
            'prefix' => $prefix,
            'max-keys' => $maxKeys,
        ];

        if ($continuationToken !== '' && $continuationToken !== '0') {
            $parameters['continuation-token'] = $continuationToken;
        }

        $response = $this->call(Method::GET, $uri, '', $parameters, headers: ['content-type' => 'text/plain']);

        if (! \is_array($response->body)) {
            throw new RemoteException('Unexpected S3 list response');
        }

        return $response->body;
    }

    /**
     * Copy a file to another path, on this device or onto another one.
     *
     * A same-device copy with a known bucket runs entirely server side —
     * `CopyObject` up to 5 GB, `UploadPartCopy` beyond — so no bytes move
     * through PHP. Everything else falls back to the streamed base copy.
     *
     * @throws StorageException
     */
    #[\Override]
    public function copy(string $source, string $target, ?Device $to = null, int $chunkSize = self::COPY_CHUNK_SIZE): bool
    {
        if ((!$to instanceof \Utopia\Storage\Device || $to === $this) && $this->bucket !== null) {
            return $this->copyObject($source, $target);
        }

        return parent::copy($source, $target, $to, $chunkSize);
    }

    /**
     * Copy an object server side within this bucket.
     *
     * @throws StorageException
     */
    private function copyObject(string $source, string $target): bool
    {
        $info = $this->getInfo($source);
        $size = (int) ($info['content-length'] ?? 0);

        $copySource = '/' . $this->bucket . '/' . ltrim(str_replace('%2F', '/', rawurlencode($source)), '/');
        $uri = $target !== '' ? '/' . str_replace(['%2F', '%3F'], ['/', '?'], rawurlencode($target)) : '/';

        if ($size <= self::MAX_COPY_OBJECT_SIZE) {
            $response = $this->call(Method::PUT, $uri, amzHeaders: [
                'x-amz-copy-source' => $copySource,
                'x-amz-metadata-directive' => 'COPY',
            ] + $this->aclHeaders());

            // S3 reports CopyObject failures in a 200 body, so success is the ETag.
            if (! \is_array($response->body) || ! isset($response->body['ETag'])) {
                throw new RemoteException('Unexpected S3 copy response');
            }

            return true;
        }

        $uploadId = $this->createMultipartUpload($target, $info['content-type'] ?? '');
        try {
            $parts = [];
            $totalParts = (int) ceil($size / self::MAX_COPY_OBJECT_SIZE);
            for ($part = 1; $part <= $totalParts; ++$part) {
                $start = ($part - 1) * self::MAX_COPY_OBJECT_SIZE;
                $end = min($size, $start + self::MAX_COPY_OBJECT_SIZE) - 1;
                $response = $this->call(
                    Method::PUT,
                    $uri,
                    parameters: ['partNumber' => $part, 'uploadId' => $uploadId],
                    amzHeaders: [
                        'x-amz-copy-source' => $copySource,
                        'x-amz-copy-source-range' => "bytes=$start-$end",
                    ],
                );

                $etag = \is_array($response->body) ? ($response->body['ETag'] ?? null) : null;
                if (! \is_string($etag)) {
                    throw new RemoteException('Missing ETag in S3 response');
                }
                $parts[$part] = $etag;
            }
            $this->completeMultipartUpload($target, $uploadId, $parts);
        } catch (\Throwable $e) {
            // Best effort — unclaimed multipart parts are billed until aborted.
            try {
                $this->abort($target, $uploadId);
            } catch (\Throwable) {
            }
            throw $e;
        }

        return true;
    }

    /**
     * Delete files in given path, path must be a directory. Return true on success and false on failure.
     *
     *
     * @throws StorageException
     */
    public function deletePath(string $path): bool
    {
        $path = $this->getRoot() . '/' . $path;

        $uri = '/';
        $continuationToken = '';
        do {
            $objects = $this->listObjects($path, continuationToken: $continuationToken);
            $token = $objects['NextContinuationToken'] ?? '';
            $continuationToken = \is_string($token) ? $token : '';

            // A single object is returned as one associative entry, multiple objects as a list of them.
            $contents = $objects['Contents'] ?? [];
            $entries = \is_array($contents) ? (isset($contents['Key']) ? [$contents] : $contents) : [];

            $keys = [];
            foreach ($entries as $object) {
                $key = \is_array($object) ? ($object['Key'] ?? null) : null;
                if (\is_string($key)) {
                    $keys[] = $key;
                }
            }

            if ($keys === []) {
                break;
            }

            $body = '<Delete xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
            foreach ($keys as $key) {
                $body .= "<Object><Key>{$key}</Key></Object>";
            }
            $body .= '<Quiet>true</Quiet>';
            $body .= '</Delete>';
            $this->call(Method::POST, $uri, $body, ['delete' => ''], headers: ['content-type' => 'application/xml']);
        } while ($continuationToken !== '');

        return true;
    }

    /**
     * Check if file exists
     */
    public function exists(string $path): bool
    {
        try {
            $this->getInfo($path);
        } catch (NotFoundException) {
            return false;
        }

        return true;
    }

    /**
     * Size, last modification and ETag of an object, from one HEAD request.
     */
    public function getFileInfo(string $path): FileInfo
    {
        $headers = $this->getInfo($path);
        $modified = isset($headers['last-modified']) ? strtotime($headers['last-modified']) : false;

        return new FileInfo(
            path: $path,
            size: (int) ($headers['content-length'] ?? 0),
            modifiedAt: $modified === false ? null : new \DateTimeImmutable('@' . $modified),
            etag: isset($headers['etag']) ? $this->unquote($headers['etag']) : null,
        );
    }

    /**
     * Returns given file path its size.
     *
     * @see http://php.net/manual/en/function.filesize.php
     */
    public function getFileSize(string $path): int
    {
        $response = $this->getInfo($path);

        return (int) ($response['content-length'] ?? 0);
    }

    /**
     * Returns given file path its mime type.
     *
     * @see http://php.net/manual/en/function.mime-content-type.php
     */
    public function getFileMimeType(string $path): string
    {
        $response = $this->getInfo($path);

        return $response['content-type'] ?? '';
    }

    /**
     * Returns given file path its MD5 hash value.
     *
     * @see http://php.net/manual/en/function.md5-file.php
     */
    public function getFileHash(string $path): string
    {
        return $this->unquote($this->getInfo($path)['etag'] ?? '');
    }

    /**
     * List objects under the given prefix, one page at a time.
     *
     * The cursor is the S3 continuation token.
     *
     * @throws StorageException
     */
    public function listFiles(string $prefix = '', int $max = self::MAX_PAGE_SIZE, ?string $cursor = null): FileList
    {
        $data = $this->listObjects($prefix, $max, $cursor ?? '');

        // A single object is returned as one associative entry, multiple objects as a list of them.
        $contents = $data['Contents'] ?? [];
        $entries = \is_array($contents) ? (isset($contents['Key']) ? [$contents] : $contents) : [];

        $files = [];
        foreach ($entries as $object) {
            if (! \is_array($object)) {
                continue;
            }
            if (! \is_string($object['Key'] ?? null)) {
                continue;
            }
            $size = $object['Size'] ?? null;
            $modified = $object['LastModified'] ?? null;
            $etag = $object['ETag'] ?? null;
            $files[] = new FileInfo(
                path: $object['Key'],
                size: is_numeric($size) ? (int) $size : 0,
                modifiedAt: \is_string($modified) ? new \DateTimeImmutable($modified) : null,
                etag: \is_string($etag) ? trim($etag, '"') : null,
            );
        }

        $token = $data['NextContinuationToken'] ?? null;

        return new FileList(
            files: $files,
            cursor: ($data['IsTruncated'] ?? null) === 'true' && \is_string($token) ? $token : null,
        );
    }

    /**
     * List the multipart uploads in progress under the given prefix, one page
     * at a time: those prepared but neither finalized nor aborted, whose parts
     * are billed until one or the other happens.
     *
     * The cursor is the pair of markers S3 pages by. Amazon S3 takes any
     * prefix; some compatible services, MinIO among them, only honour one that
     * is a whole key, and answer any other with an empty page.
     *
     * @param  int<1, max>  $max
     *
     * @throws StorageException
     */
    public function listUploads(string $prefix = '', int $max = self::MAX_PAGE_SIZE, ?string $cursor = null): UploadList
    {
        if ($max > self::MAX_PAGE_SIZE) {
            throw new \InvalidArgumentException('Cannot list more than ' . self::MAX_PAGE_SIZE . ' uploads');
        }

        $parameters = [
            'uploads' => '',
            'prefix' => ltrim($prefix, '/'),
            'max-uploads' => $max,
        ];

        if ($cursor !== null && $cursor !== '') {
            $markers = json_decode($cursor, true);
            if (! \is_array($markers) || ! \is_string($markers['key'] ?? null) || ! \is_string($markers['upload'] ?? null)) {
                throw new \InvalidArgumentException('Invalid cursor');
            }
            $parameters['key-marker'] = $markers['key'];
            $parameters['upload-id-marker'] = $markers['upload'];
        }

        $response = $this->call(Method::GET, '/', '', $parameters, headers: ['content-type' => 'text/plain']);

        if (! \is_array($response->body)) {
            throw new RemoteException('Unexpected S3 upload listing response');
        }

        // A single upload is returned as one associative entry, multiple uploads as a list of them.
        $contents = $response->body['Upload'] ?? [];
        $entries = \is_array($contents) ? (isset($contents['Key']) ? [$contents] : $contents) : [];

        $uploads = [];
        foreach ($entries as $entry) {
            if (! \is_array($entry)) {
                continue;
            }
            if (! \is_string($entry['Key'] ?? null)) {
                continue;
            }
            if (! \is_string($entry['UploadId'] ?? null)) {
                continue;
            }
            $initiated = $entry['Initiated'] ?? null;
            $uploads[] = new UploadInfo(
                path: $entry['Key'],
                uploadId: $entry['UploadId'],
                initiatedAt: \is_string($initiated) ? new \DateTimeImmutable($initiated) : null,
            );
        }

        $keyMarker = $response->body['NextKeyMarker'] ?? null;
        $uploadMarker = $response->body['NextUploadIdMarker'] ?? null;
        $truncated = ($response->body['IsTruncated'] ?? null) === 'true' && \is_string($keyMarker) && \is_string($uploadMarker);

        return new UploadList(
            uploads: $uploads,
            cursor: $truncated ? (string) json_encode(['key' => $keyMarker, 'upload' => $uploadMarker]) : null,
        );
    }

    /**
     * Get file info
     *
     * @return array<string, string>
     *
     * @throws StorageException
     */
    private function getInfo(string $path): array
    {
        $uri = $path !== '' ? '/' . str_replace('%2F', '/', rawurlencode($path)) : '/';
        $response = $this->call(Method::HEAD, $uri);

        return $response->headers;
    }

    /**
     * The ACL header for a write, or none when the device was told not to send one.
     *
     * @return array<string, string>
     */
    private function aclHeaders(): array
    {
        return $this->acl instanceof Acl ? ['x-amz-acl' => $this->acl->value] : [];
    }

    /**
     * An ETag as S3 reports it, in double quotes, from the bare form the library hands out.
     */
    private function quote(string $etag): string
    {
        return str_starts_with($etag, '"') ? $etag : '"' . $etag . '"';
    }

    /**
     * The bare form of an ETag, without the double quotes S3 puts around it.
     */
    private function unquote(string $etag): string
    {
        return trim($etag, '"');
    }

    /**
     * Generate the headers for AWS Signature V4
     *
     * @param  non-empty-string  $method
     * @param  array<string, int|string>  $parameters
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $amzHeaders
     */
    private function getSignatureV4(string $method, string $uri, array $parameters, array $headers, array $amzHeaders): string
    {
        $service = 's3';
        $region = $this->region;

        $algorithm = 'AWS4-HMAC-SHA256';
        $combinedHeaders = [];

        $amzDateStamp = substr($amzHeaders['x-amz-date'] ?? '', 0, 8);

        // CanonicalHeaders
        foreach ($headers as $k => $v) {
            $combinedHeaders[strtolower($k)] = trim($v);
        }

        foreach ($amzHeaders as $k => $v) {
            $combinedHeaders[strtolower($k)] = trim($v);
        }

        uksort($combinedHeaders, $this->sortMetaHeadersCmp(...));

        // Convert null query string parameters to strings and sort
        uksort($parameters, $this->sortMetaHeadersCmp(...));
        $queryString = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);

        // Payload
        $amzPayload = [$method];

        $qsPos = strpos($uri, '?');
        $amzPayload[] = ($qsPos === false ? $uri : substr($uri, 0, $qsPos));

        $amzPayload[] = $queryString;

        foreach ($combinedHeaders as $k => $v) { // add header as string to requests
            $amzPayload[] = $k . ':' . $v;
        }

        $amzPayload[] = ''; // add a blank entry so we end up with an extra line break
        $amzPayload[] = implode(';', array_keys($combinedHeaders)); // SignedHeaders
        $amzPayload[] = $amzHeaders['x-amz-content-sha256'] ?? ''; // payload hash

        $amzPayloadStr = implode("\n", $amzPayload); // request as string

        // CredentialScope
        $credentialScope = [$amzDateStamp, $region, $service, 'aws4_request'];

        // stringToSign
        $stringToSignStr = implode("\n", [
            $algorithm,
            $amzHeaders['x-amz-date'] ?? '',
            implode('/', $credentialScope),
            hash('sha256', $amzPayloadStr),
        ]);

        // Make Signature
        $kSecret = 'AWS4' . $this->secretKey;
        $kDate = hash_hmac('sha256', $amzDateStamp, $kSecret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

        $signature = hash_hmac('sha256', mb_convert_encoding($stringToSignStr, 'utf-8'), $kSigning);

        return $algorithm . ' ' . implode(',', [
            'Credential=' . $this->accessKey . '/' . implode('/', $credentialScope),
            'SignedHeaders=' . implode(';', array_keys($combinedHeaders)),
            'Signature=' . $signature,
        ]);
    }

    /**
     * Execute a signed S3 request and return its response.
     *
     * Headers are built per call — the instance holds no request state, so a
     * single device is safe to share across concurrent coroutines.
     *
     * When a $sink is given, the response body is delivered to it chunk by
     * chunk instead of being buffered; only its head is kept so an error
     * response can still be parsed.
     *
     * @param  non-empty-string  $method
     * @param  array<string, int|string>  $parameters
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $amzHeaders
     * @param  (callable(string): void)|null  $sink
     *
     * @throws StorageException
     */
    protected function call(string $method, string $uri, StreamInterface|string $data = '', array $parameters = [], array $headers = [], array $amzHeaders = [], bool $decode = true, ?callable $sink = null): S3\Response
    {
        $uri = $this->path . $this->getAbsolutePath($uri);
        $url = $this->fqdn . $uri . '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);

        if ($data instanceof StreamInterface) {
            [$md5, $sha256, $length] = $this->hashBody($data);
            $body = $data;
        } else {
            $md5 = base64_encode(md5($data, true));
            $sha256 = hash('sha256', $data);
            $length = \strlen($data);
            $body = new Stream($data);
        }

        $headers = array_filter($headers, fn(string $value): bool => $value !== '');
        $headers['host'] = $this->host;
        $headers['date'] = gmdate('D, d M Y H:i:s T');
        $headers['content-md5'] = $md5;
        // Send an explicit Content-Length (signed, alongside content-md5). Without
        // it the cURL transport streams the body with Transfer-Encoding: chunked —
        // or omits the header on an empty POST — which S3-compatible services such
        // as GCS reject with HTTP 411. The value is the full body size, so it also
        // matches what the transport sends for a size-known stream.
        $headers['content-length'] = (string) $length;

        $amzHeaders = array_filter($amzHeaders, fn(string $value): bool => $value !== '');
        $amzHeaders['x-amz-date'] = gmdate('Ymd\THis\Z');
        $amzHeaders['x-amz-content-sha256'] = $sha256;

        $request = new Request($method, Uri::parse($url), $body);
        foreach ([...$amzHeaders, ...$headers] as $header => $value) {
            $request = $request->withHeader($header, $value);
        }
        $request = $request
            ->withHeader('authorization', $this->getSignatureV4($method, $uri, $parameters, $headers, $amzHeaders))
            ->withHeader('user-agent', 'utopia-php/storage');

        try {
            if ($sink === null) {
                $response = $this->client->sendRequest($request);
                $responseBody = (string) $response->getBody();
            } else {
                $head = '';
                $tee = static function (string $chunk) use ($sink, &$head): void {
                    if (\strlen($head) < self::ERROR_BODY_BUFFER_SIZE) {
                        $head .= substr($chunk, 0, self::ERROR_BODY_BUFFER_SIZE - \strlen($head));
                    }
                    $sink($chunk);
                };
                $response = $this->client->stream($request, $tee);
                $responseBody = $head;
            }
        } catch (ClientExceptionInterface $e) {
            throw new TransportException($e->getMessage(), $e->getCode(), $e);
        }

        $code = $response->getStatusCode();

        $responseHeaders = [];
        foreach ($response->getHeaders() as $name => $values) {
            $responseHeaders[strtolower((string) $name)] = implode(', ', $values);
        }

        if ($code >= 400) {
            $this->parseAndThrowS3Error($responseBody, $code, $responseHeaders);
        }

        $contentType = $responseHeaders['content-type'] ?? '';
        $isXml = $contentType === 'application/xml' || (str_starts_with($responseBody, '<?xml') && $contentType !== 'image/svg+xml');

        return new S3\Response(
            code: $code,
            headers: $responseHeaders,
            body: $decode && $isXml ? $this->decodeXml($responseBody) : $responseBody,
        );
    }

    /**
     * Hash a request body for signing without materializing it as a string.
     *
     * SigV4 needs the payload digests before the first byte is sent, so the
     * stream is read once for hashing and rewound for the actual send — the
     * same approach the AWS SDK takes. This is why S3 requires seekable
     * streams. Hashing starts from the beginning, matching the transport:
     * the cURL adapter rewinds seekable bodies before sending, so the
     * signature must cover the full stream.
     *
     * @return array{string, string, int} Base64 MD5, hex SHA-256, and byte length of the full stream
     */
    private function hashBody(StreamInterface $body): array
    {
        if (! $body->isSeekable()) {
            throw new \InvalidArgumentException('S3 requires a seekable stream so the payload can be signed');
        }

        $body->rewind();
        $md5 = hash_init('md5');
        $sha256 = hash_init('sha256');
        $length = 0;
        while (! $body->eof()) {
            $chunk = $body->read(self::PIPE_CHUNK_SIZE);
            if ($chunk === '') {
                break;
            }
            $length += \strlen($chunk);
            hash_update($md5, $chunk);
            hash_update($sha256, $chunk);
        }
        $body->rewind();

        return [base64_encode(hash_final($md5, true)), hash_final($sha256), $length];
    }

    /**
     * Decode an XML response body into an associative array.
     *
     * @return array<mixed>
     *
     * @throws StorageException
     */
    private function decodeXml(string $body): array
    {
        $xml = @simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        $decoded = $xml === false ? null : $this->xmlToArray($xml);
        if (! \is_array($decoded)) {
            throw new RemoteException('Failed to decode S3 XML response');
        }

        return $decoded;
    }

    /**
     * Convert an XML element into nested arrays: repeated child names become
     * lists, text-only elements become strings and empty elements become
     * empty arrays.
     *
     * @return array<mixed>|string
     */
    private function xmlToArray(\SimpleXMLElement $element): array|string
    {
        $children = $element->children();
        if (\count($children) === 0) {
            $text = (string) $element;

            return $text === '' ? [] : $text;
        }

        $grouped = [];
        foreach ($children as $name => $child) {
            $grouped[$name][] = $this->xmlToArray($child);
        }

        $result = [];
        foreach ($grouped as $name => $values) {
            $result[$name] = \count($values) === 1 ? $values[0] : $values;
        }

        return $result;
    }

    /**
     * Parse an S3 error response and throw the matching exception.
     *
     * @param  string  $errorBody  The error response body
     * @param  int  $statusCode  The HTTP status code
     * @param  array<string, string>  $headers  The response headers, for the S3 request IDs provider support asks for
     *
     * @throws NotFoundException When the object does not exist (404, or a NoSuchKey error code)
     * @throws PreconditionFailedException When a condition on the request did not hold (412)
     * @throws RemoteException For every other error response
     */
    private function parseAndThrowS3Error(string $errorBody, int $statusCode, array $headers = []): never
    {
        $errorCode = null;
        $errorMessage = null;
        if (str_starts_with(ltrim($errorBody), '<?xml') || str_starts_with(ltrim($errorBody), '<Error')) {
            $xml = @simplexml_load_string($errorBody, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            if ($xml !== false) {
                $errorCode = (string) ($xml->Code ?? '') ?: null;
                $errorMessage = (string) ($xml->Message ?? '') ?: null;
            }
        }

        $requestIds = array_filter([
            'request-id' => $headers['x-amz-request-id'] ?? '',
            'id-2' => $headers['x-amz-id-2'] ?? '',
        ]);
        $suffix = $requestIds === [] ? '' : ' [' . implode(', ', array_map(
            fn(string $key, string $value): string => "$key: $value",
            array_keys($requestIds),
            $requestIds,
        )) . ']';

        // HEAD error responses carry no body, so the status code is the only signal.
        if ($statusCode === 404 || $errorCode === 'NoSuchKey') {
            throw new NotFoundException(($errorMessage ?? 'File not found') . $suffix, $statusCode);
        }

        if ($statusCode === 412 || $errorCode === 'PreconditionFailed') {
            throw new PreconditionFailedException(($errorMessage ?? 'Precondition failed') . $suffix, $statusCode);
        }

        throw new RemoteException(($errorMessage ?? ($errorBody !== '' ? $errorBody : 'S3 request failed')) . $suffix, $statusCode, $errorCode);
    }

    /**
     * Sort compare for meta headers
     *
     * @internal Used to sort x-amz meta headers
     *
     * @param  string  $a  String A
     * @param  string  $b  String B
     */
    protected function sortMetaHeadersCmp(string $a, string $b): int
    {
        $lenA = \strlen($a);
        $lenB = \strlen($b);
        $minLen = min($lenA, $lenB);
        $ncmp = strncmp($a, $b, $minLen);
        if ($lenA === $lenB) {
            return $ncmp;
        }

        if ($ncmp === 0) {
            return $lenA < $lenB ? -1 : 1;
        }

        return $ncmp;
    }
}
