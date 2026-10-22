<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Storage;

use Appwrite\Event\Event;
use CURLFile;
use PHPUnit\Framework\Attributes\Group;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Id;
use Utopia\System\System;

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
    public function testGetObjectContentHeaders(): void
    {
        $bucketId = $this->setupBucket();

        foreach ([
            ['source' => 'script.svg', 'mimeType' => 'image/svg+xml', 'name' => Id::unique() . '.svg'],
            ['source' => 'page.html', 'mimeType' => 'text/html', 'name' => Id::unique() . '.html'],
        ] as $case) {
            $this->uploadFile($bucketId, $case['source'], $case['mimeType'], $case['name']);
            $path = '/' . $bucketId . '/' . $case['name'];

            /**
             * Test for SUCCESS
             */
            $object = $this->call(Client::METHOD_GET, $path);
            $this->assertSame(200, $object['headers']['status-code'], $case['name']);
            $this->assertSame($case['mimeType'], $object['headers']['content-type'], $case['name']);
            $this->assertSame("script-src 'none';", $object['headers']['content-security-policy'], $case['name']);
            $this->assertSame('nosniff', $object['headers']['x-content-type-options'], $case['name']);
            $this->assertStringStartsWith('attachment;', $object['headers']['content-disposition'], $case['name']);
            $this->assertSame(\file_get_contents(\realpath(__DIR__ . '/../../../resources/' . $case['source'])), $object['body'], $case['name']);

            $cached = $this->call(Client::METHOD_GET, $path, headers: [
                'if-none-match' => $object['headers']['etag'],
            ]);
            $this->assertSame(304, $cached['headers']['status-code'], $case['name']);
            $this->assertSame("script-src 'none';", $cached['headers']['content-security-policy'], $case['name']);
            $this->assertSame('nosniff', $cached['headers']['x-content-type-options'], $case['name']);
            $this->assertStringStartsWith('attachment;', $cached['headers']['content-disposition'], $case['name']);
            $this->assertSame($object['headers']['etag'], $cached['headers']['etag'], $case['name']);

            $range = $this->call(Client::METHOD_GET, $path, headers: ['range' => 'bytes=0-10']);
            $this->assertSame(206, $range['headers']['status-code'], $case['name']);
            $this->assertSame("script-src 'none';", $range['headers']['content-security-policy'], $case['name']);
            $this->assertSame('nosniff', $range['headers']['x-content-type-options'], $case['name']);
            $this->assertStringStartsWith('attachment;', $range['headers']['content-disposition'], $case['name']);
            $this->assertArrayHasKey('etag', $range['headers'], $case['name']);

            $head = $this->call(Client::METHOD_HEAD, $path);
            $this->assertSame(200, $head['headers']['status-code'], $case['name']);
            $this->assertSame("script-src 'none';", $head['headers']['content-security-policy'], $case['name']);
            $this->assertSame('nosniff', $head['headers']['x-content-type-options'], $case['name']);
            $this->assertStringStartsWith('attachment;', $head['headers']['content-disposition'], $case['name']);

            $override = $this->call(Client::METHOD_GET, $path, [
                'response-content-type' => 'text/html',
                'response-content-disposition' => 'inline',
            ]);
            $this->assertSame(200, $override['headers']['status-code'], $case['name']);
            $this->assertSame("script-src 'none';", $override['headers']['content-security-policy'], $case['name']);
            $this->assertSame('nosniff', $override['headers']['x-content-type-options'], $case['name']);
            $this->assertStringStartsWith('attachment;', $override['headers']['content-disposition'], $case['name']);
        }

        $svgName = Id::unique() . '.svg';
        $this->uploadFile($bucketId, 'script.svg', 'image/svg+xml', $svgName);
        $copied = Id::unique() . '.svg';
        $copy = $this->call(Client::METHOD_PUT, '/' . $bucketId . '/' . $copied, headers: [
            'x-amz-copy-source' => $bucketId . '/' . $svgName,
            'x-amz-metadata-directive' => 'REPLACE',
            'content-disposition' => 'inline',
        ]);
        $this->assertSame(200, $copy['headers']['status-code'], $copy['body']);

        $copiedObject = $this->call(Client::METHOD_GET, '/' . $bucketId . '/' . $copied);
        $this->assertSame(200, $copiedObject['headers']['status-code']);
        $this->assertStringStartsWith('attachment;', $copiedObject['headers']['content-disposition']);

        $pngName = Id::unique() . '.png';
        $this->uploadFile($bucketId, 'logo.png', 'image/png', $pngName);
        $png = $this->call(Client::METHOD_GET, '/' . $bucketId . '/' . $pngName, [
            'response-content-disposition' => 'inline',
        ]);
        $this->assertSame(200, $png['headers']['status-code']);
        $this->assertSame('image/png', $png['headers']['content-type']);
        $this->assertSame("script-src 'none';", $png['headers']['content-security-policy']);
        $this->assertSame('nosniff', $png['headers']['x-content-type-options']);
        $this->assertStringStartsWith('inline', $png['headers']['content-disposition']);

        $pngOverride = $this->call(Client::METHOD_GET, '/' . $bucketId . '/' . $pngName, [
            'response-content-type' => 'application/xhtml+xml',
            'response-content-disposition' => 'inline',
        ]);
        $this->assertSame(200, $pngOverride['headers']['status-code']);
        $this->assertSame("script-src 'none';", $pngOverride['headers']['content-security-policy']);
        $this->assertSame('nosniff', $pngOverride['headers']['x-content-type-options']);
        $this->assertStringStartsWith('attachment;', $pngOverride['headers']['content-disposition']);

        $quotedKey = Id::unique() . '.svg';
        $quotedName = 'café;"name.svg';
        $uploaded = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', \array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => Id::unique(),
            'file' => new CURLFile(\realpath(__DIR__ . '/../../../resources/script.svg'), 'image/svg+xml', $quotedKey),
        ]);
        $this->assertSame(201, $uploaded['headers']['status-code'], \json_encode($uploaded['body']));

        $renamed = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId . '/files/' . $uploaded['body']['$id'], \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => $quotedName,
        ]);
        $this->assertSame(200, $renamed['headers']['status-code']);
        $this->assertSame($quotedName, $renamed['body']['name']);

        $quoted = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $uploaded['body']['$id'] . '/view', \array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertSame(200, $quoted['headers']['status-code']);
        $disposition = (string) $quoted['headers']['content-disposition'];
        $this->assertStringStartsWith('attachment;', $disposition);
        $this->assertStringContainsString('filename="caféname.svg"', $disposition);
        $this->assertStringContainsString("filename*=UTF-8''" . \rawurlencode($quotedName), $disposition);
    }

    #[Group('s3')]
    public function testUploadPartLimits(): void
    {
        $bucketId = $this->setupBucket(['maximumFileSize' => 1024]);
        $key = 'limits/' . Id::unique() . '.txt';
        $uploadId = $this->createMultipartUpload($bucketId, $key);
        $part = \str_repeat('a', 600);

        /**
         * Test for SUCCESS
         */
        $this->uploadPart($bucketId, $key, $uploadId, 1, $part);
        // Replacing a part does not count its earlier size twice.
        $etag = $this->uploadPart($bucketId, $key, $uploadId, 1, $part);

        /**
         * Test for FAILURE
         */
        $tooLarge = $this->call(Client::METHOD_PUT, '/' . $bucketId . '/' . $key, ['partNumber' => '1', 'uploadId' => $uploadId], \str_repeat('a', 1025));
        $this->assertSame(400, $tooLarge['headers']['status-code'], $tooLarge['body']);

        $cumulative = $this->call(Client::METHOD_PUT, '/' . $bucketId . '/' . $key, ['partNumber' => '2', 'uploadId' => $uploadId], $part);
        $this->assertSame(400, $cumulative['headers']['status-code'], $cumulative['body']);

        foreach (['0', '10001'] as $partNumber) {
            $outOfRange = $this->call(Client::METHOD_PUT, '/' . $bucketId . '/' . $key, ['partNumber' => $partNumber, 'uploadId' => $uploadId], 'a');
            $this->assertSame(400, $outOfRange['headers']['status-code'], $partNumber);
        }

        $complete = $this->completeMultipartUpload($bucketId, $key, $uploadId, [1 => $etag]);
        $this->assertSame(200, $complete['headers']['status-code'], $complete['body']);

        $object = $this->call(Client::METHOD_GET, '/' . $bucketId . '/' . $key);
        $this->assertSame(200, $object['headers']['status-code']);
        $this->assertSame($part, $object['body']);
    }

    public function testListObjectsWithDuplicateNames(): void
    {
        $bucketId = $this->setupBucket();
        $name = Id::unique() . '.png';
        $other = Id::unique() . '.png';
        $this->uploadFile($bucketId, 'logo.png', 'image/png', $name);
        $this->uploadFile($bucketId, 'logo.png', 'image/png', $name);
        $this->uploadFile($bucketId, 'logo.png', 'image/png', $other);

        foreach ([['list-type' => '2'], []] as $query) {
            $list = $this->call(Client::METHOD_GET, '/' . $bucketId, $query);
            $this->assertSame(200, $list['headers']['status-code'], $list['body']);

            $keys = [];
            foreach (\simplexml_load_string($list['body'])->Contents as $contents) {
                $keys[] = (string) $contents->Key;
            }
            \sort($keys);
            $expected = [$name, $other];
            \sort($expected);
            $this->assertSame($expected, $keys);
        }
    }

    public function testWriteAntivirusBucket(): void
    {
        $bucketId = $this->setupBucket(['antivirus' => true]);
        $key = Id::unique() . '.txt';
        $scanned = System::getEnv('_APP_STORAGE_ANTIVIRUS', 'disabled') === 'enabled'
            && \strtolower(System::getEnv('_APP_STORAGE_DEVICE', 'local')) === 'local';

        $put = $this->call(Client::METHOD_PUT, '/' . $bucketId . '/' . $key, body: 'appwrite');
        $upload = $this->call(Client::METHOD_POST, '/' . $bucketId . '/' . $key, ['uploads' => '']);

        if ($scanned) {
            /**
             * Test for FAILURE
             */
            $this->assertSame(400, $put['headers']['status-code'], $put['body']);
            $this->assertSame(400, $upload['headers']['status-code'], $upload['body']);

            $object = $this->call(Client::METHOD_GET, '/' . $bucketId . '/' . $key);
            $this->assertSame(404, $object['headers']['status-code']);
            return;
        }

        /**
         * Test for SUCCESS
         */
        $this->assertSame(200, $put['headers']['status-code'], $put['body']);
        $this->assertSame(200, $upload['headers']['status-code'], $upload['body']);
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

    /**
     * Breaks the deletes queue for the whole stack, so it runs in the queue lane where no other test's jobs are in flight.
     */
    #[Group('queueRetry')]
    public function testOverwriteWithoutDeletesQueue(): void
    {
        $bucketId = $this->setupBucket();
        $key = 'outage/' . Id::unique() . '.txt';
        $uploads = getDevice(APP_STORAGE_UPLOADS . '/app-' . $this->getProject()['$id']);
        $stored = fn (): array => \array_map(
            fn ($file): string => $file->path,
            $uploads->listFiles($uploads->getRoot() . '/' . $bucketId . '/', 10)->files
        );

        $put = $this->call(Client::METHOD_PUT, '/' . $bucketId . '/' . $key, body: 'original');
        $this->assertSame(200, $put['headers']['status-code'], $put['body']);
        $this->assertCount(1, $stored());

        $redis = new \Redis();
        $redis->connect(System::getEnv('_APP_REDIS_HOST', 'redis'), (int) System::getEnv('_APP_REDIS_PORT', '6379'));
        $password = System::getEnv('_APP_REDIS_PASS', '');
        if ($password !== '') {
            $user = System::getEnv('_APP_REDIS_USER', '');
            $redis->auth($user !== '' ? [$user, $password] : $password);
        }
        $queue = 'utopia-queue.queue.' . System::getEnv('_APP_DELETE_QUEUE_NAME', Event::DELETE_QUEUE_NAME);
        $backup = $queue . '.outage.' . Id::unique();
        $moved = $redis->exists($queue) && $redis->rename($queue, $backup);
        // A string at the list key fails every push to the queue with WRONGTYPE.
        $redis->set($queue, 'outage');

        try {
            /**
             * Test for SUCCESS
             */
            $put = $this->call(Client::METHOD_PUT, '/' . $bucketId . '/' . $key, body: 'replacement');
            $this->assertSame(200, $put['headers']['status-code'], $put['body']);
            $this->assertCount(1, $stored(), 'PutObject left the replaced version in storage: ' . \implode(', ', $stored()));
            $object = $this->call(Client::METHOD_GET, '/' . $bucketId . '/' . $key);
            $this->assertSame('replacement', $object['body']);

            $uploadId = $this->createMultipartUpload($bucketId, $key);
            $etag = $this->uploadPart($bucketId, $key, $uploadId, 1, 'multipart');
            $complete = $this->completeMultipartUpload($bucketId, $key, $uploadId, [1 => $etag]);
            $this->assertSame(200, $complete['headers']['status-code'], $complete['body']);
            $this->assertCount(1, $stored(), 'CompleteMultipartUpload left the replaced version in storage: ' . \implode(', ', $stored()));
            $object = $this->call(Client::METHOD_GET, '/' . $bucketId . '/' . $key);
            $this->assertSame('multipart', $object['body']);
        } finally {
            $redis->del($queue);
            if ($moved) {
                $redis->rename($backup, $queue);
            }
            $redis->close();
        }
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function setupBucket(array $attributes = []): string
    {
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), \array_merge([
            'bucketId' => Id::unique(),
            'name' => 'S3 Bucket',
            'fileSecurity' => false,
            // The S3 gateway writes only to buckets that store objects as is and are not scanned.
            'encryption' => false,
            'compression' => 'none',
            'antivirus' => false,
        ], $attributes));
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

    private function uploadFile(string $bucketId, string $source, string $mimeType, string $name): void
    {
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', \array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => Id::unique(),
            'file' => new CURLFile(\realpath(__DIR__ . '/../../../resources/' . $source), $mimeType, $name),
        ]);
        $this->assertSame(201, $file['headers']['status-code'], \json_encode($file['body']));
        $this->assertSame($mimeType, $file['body']['mimeType']);
    }

    /**
     * Send an AWS Signature V4 request to the gateway. The access key is the
     * project ID and the secret is the project's API key secret.
     *
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    private function call(string $method, string $path, array $query = [], string $body = '', array $headers = []): array
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

        return $this->client->call($method, '/s3' . $path . ($canonicalQuery === '' ? '' : '?' . $canonicalQuery), \array_merge([
            // text/plain makes the client send the body as is.
            'content-type' => 'text/plain',
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $date,
            'authorization' => 'AWS4-HMAC-SHA256 Credential=' . $this->getProject()['$id'] . '/' . $scope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature,
        ], $headers), $body, decode: false);
    }
}
