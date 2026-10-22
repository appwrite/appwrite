<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Storage;

use PHPUnit\Framework\Attributes\Group;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Id;

/**
 * Drives the S3-compatible gateway with AWS Signature V4 requests. The `s3`
 * group also runs these against MinIO, where the S3 storage device enforces
 * S3's own multipart rules on what the gateway sends it.
 */
final class S3CustomServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    private const PART_SIZE = 5 * 1024 * 1024;

    // Past APP_STORAGE_READ_BUFFER (20MB) so downloads stream, within the 30MB default bucket limit and Cloud's 50MB Free plan limit.
    private const STREAMED_FILE_SIZE = 25 * 1024 * 1024;

    #[Group('s3')]
    public function testCreateMultipartUpload(): void
    {
        $bucketId = $this->setupBucket();
        $key = 'multipart/' . Id::unique() . '.txt';
        // One part below the 5 MiB minimum S3 sets for every part but the last.
        $body = \str_repeat('appwrite', 128);

        /**
         * Test for SUCCESS
         */
        $uploadId = $this->createMultipartUpload($bucketId, $key);
        $etag = $this->uploadPart($bucketId, $key, $uploadId, 1, $body);

        $complete = $this->completeMultipartUpload($bucketId, $key, $uploadId, [1 => $etag]);
        $this->assertSame(200, $complete['headers']['status-code'], $complete['body']);
        $this->assertSame('"' . \md5(\hex2bin($etag)) . '-1"', (string) \simplexml_load_string($complete['body'])->ETag);

        $object = $this->call(Client::METHOD_GET, '/' . $bucketId . '/' . $key);
        $this->assertSame(200, $object['headers']['status-code']);
        $this->assertSame($body, $object['body']);

        /**
         * Test for FAILURE
         */
        // A completed upload is gone.
        $complete = $this->completeMultipartUpload($bucketId, $key, $uploadId, [1 => $etag]);
        $this->assertSame(400, $complete['headers']['status-code']);
        $this->assertSame('InvalidRequest', (string) \simplexml_load_string($complete['body'])->Code);

        $uploadId = $this->createMultipartUpload($bucketId, $key);
        $etag = $this->uploadPart($bucketId, $key, $uploadId, 1, $body);

        $complete = $this->completeMultipartUpload($bucketId, $key, $uploadId, [1 => \md5('other')]);
        $this->assertSame(400, $complete['headers']['status-code']);
        $this->assertSame('InvalidRequest', (string) \simplexml_load_string($complete['body'])->Code);

        $complete = $this->completeMultipartUpload($bucketId, $key, $uploadId, [2 => $etag]);
        $this->assertSame(400, $complete['headers']['status-code']);
        $this->assertSame('InvalidRequest', (string) \simplexml_load_string($complete['body'])->Code);
    }

    #[Group('s3')]
    public function testCreateMultipartUploadWithPartSubset(): void
    {
        $bucketId = $this->setupBucket();
        $key = 'multipart/' . Id::unique() . '.txt';
        $parts = [
            1 => \str_repeat('a', self::PART_SIZE),
            2 => \str_repeat('b', 1024),
            3 => \str_repeat('c', 1024),
        ];

        $uploadId = $this->createMultipartUpload($bucketId, $key);
        $etags = [];
        foreach ($parts as $partNumber => $part) {
            $etags[$partNumber] = $this->uploadPart($bucketId, $key, $uploadId, $partNumber, $part);
        }

        // S3 completes with the parts the request names, not every part uploaded.
        $complete = $this->completeMultipartUpload($bucketId, $key, $uploadId, [1 => $etags[1], 2 => $etags[2]]);
        $this->assertSame(200, $complete['headers']['status-code'], $complete['body']);
        $this->assertSame('"' . \md5(\hex2bin($etags[1]) . \hex2bin($etags[2])) . '-2"', (string) \simplexml_load_string($complete['body'])->ETag);

        $object = $this->call(Client::METHOD_GET, '/' . $bucketId . '/' . $key);
        $this->assertSame(200, $object['headers']['status-code']);
        $this->assertSame(self::PART_SIZE + 1024, \strlen($object['body']));
        $this->assertSame($parts[1] . $parts[2], $object['body']);
    }

    #[Group('s3')]
    public function testNativeReadDuringOverwrite(): void
    {
        $bucketId = $this->setupBucket();
        $key = 'native-stream.bin';
        $body = \str_repeat('a', self::STREAMED_FILE_SIZE);
        $replacement = 'replacement';

        foreach (['view', 'download'] as $endpoint) {
            $uploadId = $this->createMultipartUpload($bucketId, $key);
            $etags = [];
            for ($offset = 0; $offset < \strlen($body); $offset += self::PART_SIZE) {
                $partNumber = \count($etags) + 1;
                $etags[$partNumber] = $this->uploadPart($bucketId, $key, $uploadId, $partNumber, \substr($body, $offset, self::PART_SIZE));
            }
            $complete = $this->completeMultipartUpload($bucketId, $key, $uploadId, $etags);
            $this->assertSame(200, $complete['headers']['status-code'], $complete['body']);
            $files = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files', \array_merge([
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));
            $this->assertSame(200, $files['headers']['status-code']);
            $this->assertCount(1, $files['body']['files']);
            $fileId = $files['body']['files'][0]['$id'];
            $path = '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/' . $endpoint;

            $hash = \hash_init('sha256');
            $bytes = 0;
            $overwrite = null;
            $download = \curl_init($this->client->getEndpoint() . $path);
            \curl_setopt_array($download, [
                CURLOPT_HTTPHEADER => [
                    'X-Appwrite-Project: ' . $this->getProject()['$id'],
                    'X-Appwrite-Key: ' . $this->getProject()['apiKey'],
                    'Accept-Encoding: identity',
                ],
                CURLOPT_TIMEOUT => 60,
                CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use ($bucketId, $key, $replacement, $hash, &$bytes, &$overwrite): int {
                    \hash_update($hash, $chunk);
                    $bytes += \strlen($chunk);
                    if ($overwrite === null) {
                        // Stop consuming the response while the writer commits. A range-per-chunk
                        // reader loses its old path here; one backend GET retains the original bytes.
                        $overwrite = $this->call(Client::METHOD_PUT, '/' . $bucketId . '/' . $key, body: $replacement);
                    }
                    return \strlen($chunk);
                },
            ]);
            try {
                $result = \curl_exec($download);
                $error = \curl_error($download);
                $status = \curl_getinfo($download, CURLINFO_RESPONSE_CODE);
            } finally {
                \curl_close($download);
            }
            $this->assertNotNull($overwrite);
            $this->assertSame(200, $overwrite['headers']['status-code'], $overwrite['body']);
            $this->assertNotFalse($result, $error);
            $this->assertSame(200, $status);
            $this->assertSame(\strlen($body), $bytes);
            $this->assertSame(\hash('sha256', $body), \hash_final($hash));
            $next = $this->client->call(Client::METHOD_GET, $path, \array_merge([
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), decode: false);
            $this->assertSame(200, $next['headers']['status-code']);
            $this->assertSame($replacement, $next['body']);
        }
    }

    private function setupBucket(): string
    {
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'bucketId' => Id::unique(),
            'name' => 'S3 Bucket',
            'fileSecurity' => false,
            // The S3 gateway serves only buckets that store objects as is.
            'encryption' => false,
            'compression' => 'none',
        ]);
        $this->assertSame(201, $bucket['headers']['status-code'], \json_encode($bucket['body']));

        return $bucket['body']['$id'];
    }

    private function createMultipartUpload(string $bucketId, string $key): string
    {
        $upload = $this->call(Client::METHOD_POST, '/' . $bucketId . '/' . $key, ['uploads' => '']);
        $this->assertSame(200, $upload['headers']['status-code'], $upload['body']);

        return (string) \simplexml_load_string($upload['body'])->UploadId;
    }

    private function uploadPart(string $bucketId, string $key, string $uploadId, int $partNumber, string $body): string
    {
        $part = $this->call(Client::METHOD_PUT, '/' . $bucketId . '/' . $key, [
            'partNumber' => (string) $partNumber,
            'uploadId' => $uploadId,
        ], $body);
        $this->assertSame(200, $part['headers']['status-code'], $part['body']);
        $this->assertSame('"' . \md5($body) . '"', $part['headers']['etag']);

        return \md5($body);
    }

    /**
     * @param array<int, string> $etags ETags keyed by part number
     */
    private function completeMultipartUpload(string $bucketId, string $key, string $uploadId, array $etags): array
    {
        $body = '<CompleteMultipartUpload>';
        foreach ($etags as $partNumber => $etag) {
            $body .= '<Part><PartNumber>' . $partNumber . '</PartNumber><ETag>"' . $etag . '"</ETag></Part>';
        }
        $body .= '</CompleteMultipartUpload>';

        return $this->call(Client::METHOD_POST, '/' . $bucketId . '/' . $key, ['uploadId' => $uploadId], $body);
    }

    /**
     * Send an AWS Signature V4 request to the gateway. The access key is the
     * project ID and the secret is the project's API key secret.
     *
     * @param array<string, string> $query
     */
    private function call(string $method, string $path, array $query = [], string $body = ''): array
    {
        $endpoint = \parse_url($this->client->getEndpoint());
        $host = $endpoint['host'] . (isset($endpoint['port']) ? ':' . $endpoint['port'] : '');
        $uri = ($endpoint['path'] ?? '') . '/s3' . $path;

        \ksort($query);
        $canonicalQuery = \implode('&', \array_map(
            fn (string $name, string $value): string => \rawurlencode($name) . '=' . \rawurlencode($value),
            \array_keys($query),
            $query
        ));

        $date = \gmdate('Ymd\THis\Z');
        $day = \substr($date, 0, 8);
        $payloadHash = \hash('sha256', $body);
        $scope = $day . '/us-east-1/s3/aws4_request';
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
        $canonicalRequest = \implode("\n", [
            $method,
            $uri,
            $canonicalQuery,
            'host:' . $host,
            'x-amz-content-sha256:' . $payloadHash,
            'x-amz-date:' . $date,
            '',
            $signedHeaders,
            $payloadHash,
        ]);
        $stringToSign = "AWS4-HMAC-SHA256\n{$date}\n{$scope}\n" . \hash('sha256', $canonicalRequest);

        $signingKey = 'AWS4' . $this->getProject()['apiKey'];
        foreach ([$day, 'us-east-1', 's3', 'aws4_request'] as $segment) {
            $signingKey = \hash_hmac('sha256', $segment, $signingKey, true);
        }
        $signature = \hash_hmac('sha256', $stringToSign, $signingKey);

        return $this->client->call($method, '/s3' . $path . ($canonicalQuery === '' ? '' : '?' . $canonicalQuery), [
            // text/plain makes the client send the body as is.
            'content-type' => 'text/plain',
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $date,
            'authorization' => 'AWS4-HMAC-SHA256 Credential=' . $this->getProject()['$id'] . '/' . $scope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature,
        ], $body, decode: false);
    }
}
