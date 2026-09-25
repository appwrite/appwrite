<?php

declare(strict_types=1);

namespace Tests\E2E\Services\S3;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class S3CustomServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    /**
     * S3 Init reflects any non-empty Origin and always sends Vary: Origin.
     * Cover that through the real /v1/s3 surface — unit tests only hit Cors::headers().
     */
    public function testCors(): void
    {
        $host = 'appwrite';
        $path = '/v1/s3';
        $secret = $this->getProject()['apiKey'];
        $projectId = $this->getProject()['$id'];
        $sitesOrigin = 'https://mosaic.appwrite.network';

        /**
         * Test for SUCCESS
         *
         * A Sites (or any) Origin is echoed on ListBuckets, with Vary: Origin.
         */
        $withOrigin = $this->signedGet($host, $path, $secret, $projectId, [
            'origin' => $sitesOrigin,
        ]);

        $this->assertEquals(200, $withOrigin['headers']['status-code']);
        $this->assertEquals($sitesOrigin, $withOrigin['headers']['access-control-allow-origin'] ?? null);
        $this->assertEquals('Origin', $withOrigin['headers']['vary'] ?? null);
        $this->assertNotEquals('', $withOrigin['headers']['access-control-allow-origin'] ?? null);

        /**
         * Test for SUCCESS
         *
         * No Origin means no Access-Control-Allow-Origin — never an empty value.
         */
        $withoutOrigin = $this->signedGet($host, $path, $secret, $projectId);

        $this->assertEquals(200, $withoutOrigin['headers']['status-code']);
        $this->assertNull($withoutOrigin['headers']['access-control-allow-origin'] ?? null);
        $this->assertEquals('Origin', $withoutOrigin['headers']['vary'] ?? null);
    }

    /**
     * @param array<string, string> $extraHeaders
     * @return array{headers: array<string, mixed>, body: mixed}
     */
    private function signedGet(string $host, string $path, string $secret, string $projectId, array $extraHeaders = []): array
    {
        $date = \gmdate('Ymd\THis\Z');
        $shortDate = \substr($date, 0, 8);
        $region = 'us-east-1';
        $payloadHash = 'UNSIGNED-PAYLOAD';
        $signed = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $date,
        ];
        \ksort($signed);

        $canonicalHeaders = '';
        foreach ($signed as $name => $value) {
            $canonicalHeaders .= $name . ':' . $value . "\n";
        }
        $signedHeaders = \implode(';', \array_keys($signed));
        $scope = "{$shortDate}/{$region}/s3/aws4_request";
        $canonicalRequest = \implode("\n", [
            'GET',
            $path,
            '',
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);
        $stringToSign = "AWS4-HMAC-SHA256\n{$date}\n{$scope}\n" . \hash('sha256', $canonicalRequest);
        $signature = \hash_hmac('sha256', $stringToSign, $this->signingKey($secret, $shortDate, $region, 's3'));

        $headers = \array_merge([
            'content-type' => 'application/xml',
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $date,
            'authorization' => "AWS4-HMAC-SHA256 Credential={$projectId}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}",
        ], $extraHeaders);

        // Endpoint already includes /v1; path for Client is the S3 suffix only.
        return $this->client->call(Client::METHOD_GET, '/s3', $headers);
    }

    private function signingKey(string $secret, string $date, string $region, string $service): string
    {
        $kDate = \hash_hmac('sha256', $date, 'AWS4' . $secret, true);
        $kRegion = \hash_hmac('sha256', $region, $kDate, true);
        $kService = \hash_hmac('sha256', $service, $kRegion, true);

        return \hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}
