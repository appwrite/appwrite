<?php

declare(strict_types=1);

namespace Tests\E2E\Services\S3;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

final class S3CustomServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testHeadObject(): void
    {
        $bucketId = ID::unique();
        $key = 'filename.json';
        $body = '{"ok":true}';

        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => $bucketId,
            'name' => 'S3 HeadObject Bucket',
            'fileSecurity' => false,
            'encryption' => false,
            'compression' => 'none',
        ]);
        $this->assertSame(201, $bucket['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $put = $this->s3(
            Client::METHOD_PUT,
            '/s3/' . $bucketId . '/' . $key,
            $body,
        );
        $this->assertSame(200, $put['headers']['status-code']);
        $this->assertNotEmpty($put['headers']['etag'] ?? '');

        $get = $this->s3(
            Client::METHOD_GET,
            '/s3/' . $bucketId . '/' . $key,
        );
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame($body, $get['body']);
        $this->assertSame($put['headers']['etag'], $get['headers']['etag']);

        $head = $this->s3(
            Client::METHOD_HEAD,
            '/s3/' . $bucketId . '/' . $key,
            query: 'x-id=HeadObject',
        );
        $this->assertSame(200, $head['headers']['status-code']);
        $this->assertSame('', $head['body']);
        $this->assertSame($put['headers']['etag'], $head['headers']['etag']);
        $this->assertSame((string) \strlen($body), $head['headers']['content-length']);

        $rewritten = $this->s3(
            Client::METHOD_GET,
            '/s3/' . $bucketId . '/' . $key,
            query: 'x-id=HeadObject',
            signAs: Client::METHOD_HEAD,
        );
        $this->assertSame(200, $rewritten['headers']['status-code']);
        $this->assertSame('', $rewritten['body']);
        $this->assertSame($put['headers']['etag'], $rewritten['headers']['etag']);
        $this->assertSame((string) \strlen($body), $rewritten['headers']['content-length']);

        /**
         * Test for FAILURE
         */
        $missing = $this->s3(
            Client::METHOD_HEAD,
            '/s3/' . $bucketId . '/missing.json',
            query: 'x-id=HeadObject',
        );
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('', $missing['body']);

        $unauthorized = $this->client->call(
            Client::METHOD_HEAD,
            '/s3/' . $bucketId . '/' . $key . '?x-id=HeadObject',
            [
                'content-type' => 'text/plain',
                'host' => $this->s3Host(),
            ],
        );
        $this->assertSame(403, $unauthorized['headers']['status-code']);
        $this->assertSame('', $unauthorized['body']);
    }

    public function testHeadBucket(): void
    {
        $bucketId = ID::unique();

        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => $bucketId,
            'name' => 'S3 HeadBucket Bucket',
            'fileSecurity' => false,
            'encryption' => false,
            'compression' => 'none',
        ]);
        $this->assertSame(201, $bucket['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $head = $this->s3(
            Client::METHOD_HEAD,
            '/s3/' . $bucketId,
            query: 'x-id=HeadBucket',
        );
        $this->assertSame(200, $head['headers']['status-code']);
        $this->assertSame('', $head['body']);

        $rewritten = $this->s3(
            Client::METHOD_GET,
            '/s3/' . $bucketId,
            query: 'x-id=HeadBucket',
            signAs: Client::METHOD_HEAD,
        );
        $this->assertSame(200, $rewritten['headers']['status-code']);
        $this->assertSame('', $rewritten['body']);

        /**
         * Test for FAILURE
         */
        $missing = $this->s3(
            Client::METHOD_HEAD,
            '/s3/' . ID::unique(),
            query: 'x-id=HeadBucket',
        );
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('', $missing['body']);
    }

    private function s3(
        string $method,
        string $path,
        string $body = '',
        string $query = '',
        ?string $signAs = null,
    ): array {
        $secret = $this->getProject()['apiKey'];
        $date = \gmdate('Ymd\THis\Z');
        $shortDate = \substr($date, 0, 8);
        $region = 'us-east-1';
        $service = 's3';
        $host = $this->s3Host();
        $payloadHash = $body === '' ? 'UNSIGNED-PAYLOAD' : \hash('sha256', $body);
        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $date,
        ];
        if ($body !== '') {
            $headers['content-type'] = 'text/plain';
        }
        \ksort($headers);

        $signedHeaders = \implode(';', \array_keys($headers));
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . \trim($value) . "\n";
        }

        $canonicalUri = '/v1' . $path;
        $canonicalRequest = \implode("\n", [
            $signAs ?? $method,
            $canonicalUri,
            $query,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);
        $scope = "{$shortDate}/{$region}/{$service}/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$date}\n{$scope}\n" . \hash('sha256', $canonicalRequest);
        $signature = \hash_hmac('sha256', $stringToSign, $this->signingKey($secret, $shortDate, $region, $service));

        $headers['authorization'] = "AWS4-HMAC-SHA256 Credential={$this->getProject()['$id']}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";
        $headers['content-type'] ??= 'text/plain';

        return $this->client->call(
            $method,
            $query === '' ? $path : $path . '?' . $query,
            $headers,
            $body,
        );
    }

    private function s3Host(): string
    {
        return (string) \parse_url($this->client->getEndpoint(), PHP_URL_HOST);
    }

    private function signingKey(string $secret, string $date, string $region, string $service): string
    {
        $kDate = \hash_hmac('sha256', $date, 'AWS4' . $secret, true);
        $kRegion = \hash_hmac('sha256', $region, $kDate, true);
        $kService = \hash_hmac('sha256', $service, $kRegion, true);
        return \hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}
