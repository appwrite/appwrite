<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Storage;

use Appwrite\Extend\Exception;
use CURLFile;
use PHPUnit\Framework\Attributes\Group;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;
use Utopia\System\System;

#[Group('storage-limits')]
final class StorageLimitsCustomServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    private function headers(string $contentType = 'application/json'): array
    {
        return \array_merge([
            'content-type' => $contentType,
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());
    }

    private function createBucket(int $maximumFileSize): array
    {
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', $this->headers(), [
            'bucketId' => ID::unique(),
            'name' => 'Upload limits',
            'maximumFileSize' => $maximumFileSize,
            'encryption' => false,
            'antivirus' => false,
        ]);

        $this->assertSame(201, $bucket['headers']['status-code']);

        return $bucket['body'];
    }

    public function testCreateBucketLimits(): void
    {
        // Test for SUCCESS: zero is persisted on both creation and update.
        $bucket = $this->createBucket(0);
        $this->assertSame(0, $bucket['maximumFileSize']);

        $path = '/storage/buckets/' . $bucket['$id'];
        $updated = $this->client->call(Client::METHOD_PUT, $path, $this->headers(), [
            'name' => 'Finite bucket',
            'maximumFileSize' => 16,
        ]);
        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame(16, $updated['body']['maximumFileSize']);

        $updated = $this->client->call(Client::METHOD_PUT, $path, $this->headers(), [
            'name' => 'Inherited bucket',
            'maximumFileSize' => 0,
        ]);
        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame(0, $updated['body']['maximumFileSize']);

        // Test for FAILURE: negative and fractional limits remain invalid.
        foreach ([-1, 0.5] as $invalid) {
            $created = $this->client->call(Client::METHOD_POST, '/storage/buckets', $this->headers(), [
                'bucketId' => ID::unique(),
                'name' => 'Invalid limit',
                'maximumFileSize' => $invalid,
            ]);
            $this->assertSame(400, $created['headers']['status-code']);
            $this->assertSame(Exception::GENERAL_ARGUMENT_INVALID, $created['body']['type']);

            $updated = $this->client->call(Client::METHOD_PUT, $path, $this->headers(), [
                'name' => 'Invalid limit',
                'maximumFileSize' => $invalid,
            ]);
            $this->assertSame(400, $updated['headers']['status-code']);
            $this->assertSame(Exception::GENERAL_ARGUMENT_INVALID, $updated['body']['type']);
        }

        $server = (int) System::getEnv('_APP_STORAGE_LIMIT', '0');
        if ($server > 0 && $server < PHP_INT_MAX) {
            $created = $this->client->call(Client::METHOD_POST, '/storage/buckets', $this->headers(), [
                'bucketId' => ID::unique(),
                'name' => 'Above server limit',
                'maximumFileSize' => $server + 1,
            ]);
            $this->assertSame(400, $created['headers']['status-code']);

            $updated = $this->client->call(Client::METHOD_PUT, $path, $this->headers(), [
                'name' => 'Above server limit',
                'maximumFileSize' => $server + 1,
            ]);
            $this->assertSame(400, $updated['headers']['status-code']);
        } elseif ($server === 0) {
            $this->assertSame(30000001, $this->createBucket(30000001)['maximumFileSize']);
        }
    }

    public function testCreateFileLimits(): void
    {
        $source = \tempnam(\sys_get_temp_dir(), 'upload-limit-');
        $this->assertNotFalse($source);
        \file_put_contents($source, \str_repeat('x', 16));

        try {
            $bucket = $this->createBucket(0);
            $upload = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucket['$id'] . '/files', $this->headers('multipart/form-data'), [
                'fileId' => ID::unique(),
                'file' => new CURLFile($source, 'application/octet-stream', 'file.bin'),
            ]);
            $this->assertSame(201, $upload['headers']['status-code']);
            $this->assertSame(16, $upload['body']['sizeOriginal']);

            // Test for FAILURE: a positive bucket maximum still applies.
            $limited = $this->createBucket(8);
            $upload = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $limited['$id'] . '/files', $this->headers('multipart/form-data'), [
                'fileId' => ID::unique(),
                'file' => new CURLFile($source, 'application/octet-stream', 'file.bin'),
            ]);
            $this->assertSame(400, $upload['headers']['status-code']);
            $this->assertSame(Exception::STORAGE_INVALID_FILE_SIZE, $upload['body']['type']);

            // Validate the entire advertised upload, not just this tiny chunk.
            $headers = \array_merge($this->headers('multipart/form-data'), ['content-range' => 'bytes 0-15/17']);
            $limited = $this->createBucket(16);
            $upload = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $limited['$id'] . '/files', $headers, [
                'fileId' => ID::unique(),
                'file' => new CURLFile($source, 'application/octet-stream', 'file.bin'),
            ]);
            $this->assertSame(400, $upload['headers']['status-code']);
            $this->assertSame(Exception::STORAGE_INVALID_FILE_SIZE, $upload['body']['type']);

            $server = (int) System::getEnv('_APP_STORAGE_LIMIT', '0');
            if ($server > 0 && $server < PHP_INT_MAX) {
                $headers['content-range'] = 'bytes 0-15/' . ($server + 1);
                $upload = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucket['$id'] . '/files', $headers, [
                    'fileId' => ID::unique(),
                    'file' => new CURLFile($source, 'application/octet-stream', 'file.bin'),
                ]);
                $this->assertSame(400, $upload['headers']['status-code']);
                $this->assertSame(Exception::STORAGE_INVALID_FILE_SIZE, $upload['body']['type']);
            }
        } finally {
            \unlink($source);
        }
    }

    public function testCreateFileUnlimitedChunks(): void
    {
        if ((int) System::getEnv('_APP_STORAGE_LIMIT', '0') !== 0) {
            $this->markTestSkipped('Requires a server with _APP_STORAGE_LIMIT=0.');
        }

        $bucket = $this->createBucket(0);
        $source = \tempnam(\sys_get_temp_dir(), 'unlimited-chunk-');
        $this->assertNotFalse($source);
        $size = 30000001;
        $chunkSize = 5 * 1024 * 1024;
        $fileId = ID::unique();

        try {
            for ($start = 0; $start < $size; $start += $chunkSize) {
                $length = \min($chunkSize, $size - $start);
                \file_put_contents($source, \str_repeat('x', $length));
                $headers = \array_merge($this->headers('multipart/form-data'), [
                    'content-range' => 'bytes ' . $start . '-' . ($start + $length - 1) . '/' . $size,
                ]);
                $upload = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucket['$id'] . '/files', $headers, [
                    'fileId' => $fileId,
                    'file' => new CURLFile($source, 'application/octet-stream', 'unlimited.bin'),
                ]);
                $this->assertSame(201, $upload['headers']['status-code']);
                $this->assertSame($size, $upload['body']['sizeOriginal']);
            }

            $this->assertSame($upload['body']['chunksTotal'], $upload['body']['chunksUploaded']);
            $file = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucket['$id'] . '/files/' . $fileId, $this->headers());
            $this->assertSame(200, $file['headers']['status-code']);
            $this->assertSame($size, $file['body']['sizeOriginal']);
            $download = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucket['$id'] . '/files/' . $fileId . '/download', \array_merge($this->headers(), ['range' => 'bytes=' . ($size - 1) . '-' . ($size - 1)]), decode: false);
            $this->assertSame(206, $download['headers']['status-code']);
            $this->assertSame('x', $download['body']);
        } finally {
            \unlink($source);
        }
    }

    private function callS3(string $method, string $bucketId, string $key, string $body, array $additionalHeaders = [], array $query = []): array
    {
        $project = $this->getProject();
        $date = \gmdate('Ymd\THis\Z');
        $day = \substr($date, 0, 8);
        $host = \parse_url($this->client->getEndpoint(), PHP_URL_HOST);
        $port = \parse_url($this->client->getEndpoint(), PHP_URL_PORT);
        if ($port !== null) {
            $host .= ':' . $port;
        }
        $headers = \array_merge([
            'host' => $host,
            'x-amz-content-sha256' => \hash('sha256', $body),
            'x-amz-date' => $date,
        ], $additionalHeaders);
        \ksort($headers);
        $signedHeaders = \implode(';', \array_keys($headers));
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . $value . "\n";
        }
        $path = '/s3/' . $bucketId . '/' . $key;
        \ksort($query);
        $queryString = \http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $canonical = \implode("\n", [$method, '/v1' . $path, $queryString, $canonicalHeaders, $signedHeaders, $headers['x-amz-content-sha256']]);
        $scope = $day . '/us-east-1/s3/aws4_request';
        $keyDate = \hash_hmac('sha256', $day, 'AWS4' . $project['apiKey'], true);
        $keyRegion = \hash_hmac('sha256', 'us-east-1', $keyDate, true);
        $keyService = \hash_hmac('sha256', 's3', $keyRegion, true);
        $keySigning = \hash_hmac('sha256', 'aws4_request', $keyService, true);
        $signature = \hash_hmac('sha256', "AWS4-HMAC-SHA256\n{$date}\n{$scope}\n" . \hash('sha256', $canonical), $keySigning);
        $headers['authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $project['$id'] . '/' . $scope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;
        $headers['content-type'] = 'text/plain';

        return $this->client->call($method, $path . ($queryString === '' ? '' : '?' . $queryString), $headers, $body, decode: false);
    }

    public function testCreateS3FileLimits(): void
    {
        // Test for SUCCESS: REST-created zero-limit buckets work over S3 too.
        $bucket = $this->createBucket(0);
        $source = $this->callS3(Client::METHOD_PUT, $bucket['$id'], 'source.bin', \str_repeat('x', 16));
        $this->assertSame(200, $source['headers']['status-code']);

        $copy = $this->callS3(Client::METHOD_PUT, $bucket['$id'], 'copy.bin', '', [
            'x-amz-copy-source' => '/' . $bucket['$id'] . '/source.bin',
        ]);
        $this->assertSame(200, $copy['headers']['status-code']);

        // Test for FAILURE: uploads and copies honor positive bucket limits.
        $limited = $this->createBucket(8);
        $upload = $this->callS3(Client::METHOD_PUT, $limited['$id'], 'large.bin', \str_repeat('x', 16));
        $this->assertSame(400, $upload['headers']['status-code']);
        $this->assertStringContainsString('File size not allowed', $upload['body']);

        $copy = $this->callS3(Client::METHOD_PUT, $limited['$id'], 'copy.bin', '', [
            'x-amz-copy-source' => '/' . $bucket['$id'] . '/source.bin',
        ]);
        $this->assertSame(400, $copy['headers']['status-code']);
        $this->assertStringContainsString('Copied file size not allowed', $copy['body']);

        // Run with a small positive server ceiling to test a whole S3 request.
        // Larger files use multipart S3 uploads, with limits checked at completion.
        $server = (int) System::getEnv('_APP_STORAGE_LIMIT', '0');
        if ($server > 0 && $server < 1024 * 1024) {
            $upload = $this->callS3(Client::METHOD_PUT, $bucket['$id'], 'above-server.bin', \str_repeat('x', $server + 1));
            $this->assertSame(400, $upload['headers']['status-code']);
            $this->assertStringContainsString('File size not allowed', $upload['body']);
        }
    }

    private function uploadS3Parts(string $bucketId, int $partSize): array
    {
        $key = ID::unique() . '.bin';
        $initiated = $this->callS3(Client::METHOD_POST, $bucketId, $key, '', query: ['uploads' => '']);
        $this->assertSame(200, $initiated['headers']['status-code']);
        $xml = \simplexml_load_string($initiated['body']);
        $this->assertNotFalse($xml);
        $uploadId = (string) $xml->UploadId;
        $this->assertNotSame('', $uploadId);

        $parts = '';
        for ($number = 1; $number <= 2; $number++) {
            $part = $this->callS3(Client::METHOD_PUT, $bucketId, $key, \str_repeat('x', $partSize), query: [
                'partNumber' => $number,
                'uploadId' => $uploadId,
            ]);
            $this->assertSame(200, $part['headers']['status-code']);
            $parts .= '<Part><PartNumber>' . $number . '</PartNumber><ETag>' . \trim($part['headers']['etag'], '"') . '</ETag></Part>';
        }

        return $this->callS3(Client::METHOD_POST, $bucketId, $key, '<CompleteMultipartUpload>' . $parts . '</CompleteMultipartUpload>', query: ['uploadId' => $uploadId]);
    }

    public function testCreateS3MultipartFileLimits(): void
    {
        // Test for SUCCESS: an inherited/unlimited bucket accepts completed parts.
        $bucket = $this->createBucket(0);
        $completed = $this->uploadS3Parts($bucket['$id'], 8);
        $this->assertSame(200, $completed['headers']['status-code']);

        // Test for FAILURE: each part fits, but their combined size exceeds the bucket.
        $limited = $this->createBucket(8);
        $completed = $this->uploadS3Parts($limited['$id'], 8);
        $this->assertSame(400, $completed['headers']['status-code']);
        $this->assertStringContainsString('File size not allowed', $completed['body']);

        $server = (int) System::getEnv('_APP_STORAGE_LIMIT', '0');
        if ($server > 0 && $server < 1024 * 1024) {
            $completed = $this->uploadS3Parts($bucket['$id'], \intdiv($server, 2) + 1);
            $this->assertSame(400, $completed['headers']['status-code']);
            $this->assertStringContainsString('File size not allowed', $completed['body']);
        }
    }
}
