<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\S3\Auth;

use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Platform\Modules\S3\Auth\SignatureV4;
use Appwrite\Utopia\Database\Documents\User;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Request as SwooleRequest;
use Utopia\Database\Document;
use Utopia\Http\Adapter\Swoole\Request;

final class SignatureV4Test extends TestCase
{
    public function testVerifiesValidSignatureForExistingProjectApiKey(): void
    {
        $secret = 'test-api-key-secret';
        $request = $this->signedRequest('GET', '/v1/s3/bucket/object.txt', '', $secret);

        $key = (new SignatureV4())->verify(
            $request,
            $this->project($secret, ['files.read']),
            new Document(),
            new User(),
            ['files.read']
        );

        $this->assertSame('project-test', $key->getProjectId());
        $this->assertContains('files.read', $key->getScopes());
    }

    public function testVerifiesUnsignedPayloadSignature(): void
    {
        $secret = 'test-api-key-secret';
        $request = $this->signedRequest('GET', '/v1/s3/bucket', '', $secret, 'UNSIGNED-PAYLOAD', 'delimiter=%2F&list-type=2&max-keys=100');

        $key = (new SignatureV4())->verify(
            $request,
            $this->project($secret, ['files.read']),
            new Document(),
            new User(),
            ['files.read']
        );

        $this->assertSame('project-test', $key->getProjectId());
    }

    public function testRejectsExpiredPresignedRequest(): void
    {
        $secret = 'test-api-key-secret';
        $request = $this->presignedRequestAt(
            'GET',
            '/v1/s3/bucket/object.txt',
            $secret,
            \gmdate('Ymd\THis\Z', \time() - 120),
            60,
        );

        $this->expectException(AppwriteException::class);
        $this->expectExceptionMessage('presigned URL has expired');

        (new SignatureV4())->verify(
            $request,
            $this->project($secret, ['files.read']),
            new Document(),
            new User(),
            ['files.read']
        );
    }
    public function testVerifiesOpenDalRangedGetSignature(): void
    {
        $secret = 'test-api-key-secret';
        $request = $this->signedRequest(
            'GET',
            '/v1/s3/test/ai_history_fundamentals_quiz_quizizz.xlsx',
            '',
            $secret,
            'UNSIGNED-PAYLOAD',
            additionalHeaders: ['range' => 'bytes=0-8218'],
        );

        $key = (new SignatureV4())->verify(
            $request,
            $this->project($secret, ['files.read']),
            new Document(),
            new User(),
            ['files.read']
        );

        $this->assertSame('project-test', $key->getProjectId());
    }

    public function testVerifiesOpenDalDeleteSignature(): void
    {
        $secret = 'test-api-key-secret';
        $request = $this->signedRequest(
            'DELETE',
            '/v1/s3/test/ai_history_fundamentals_quiz_quizizz.xlsx',
            '',
            $secret,
            'UNSIGNED-PAYLOAD',
        );

        $key = (new SignatureV4())->verify(
            $request,
            $this->project($secret, ['files.write']),
            new Document(),
            new User(),
            ['files.write']
        );

        $this->assertSame('project-test', $key->getProjectId());
    }

    public function testVerifiesSignatureWithOriginalAcceptEncodingPreservedByFastly(): void
    {
        $secret = 'test-api-key-secret';
        $request = $this->signedRequest(
            'GET',
            '/v1/s3',
            '',
            $secret,
            additionalHeaders: ['accept-encoding' => 'identity'],
        );
        $request->headerMap['accept-encoding'] = '';
        $request->headerMap['fastly-orig-accept-encoding'] = 'identity';

        $key = (new SignatureV4())->verify(
            $request,
            $this->project($secret, ['buckets.read']),
            new Document(),
            new User(),
            ['buckets.read']
        );

        $this->assertSame('project-test', $key->getProjectId());
        $this->assertContains('buckets.read', $key->getScopes());
    }

    public function testRejectsValidSignatureWhenApiKeyMissingRequiredScope(): void
    {
        $secret = 'test-api-key-secret';
        $request = $this->signedRequest('PUT', '/v1/s3/bucket/object.txt', 'body', $secret);

        $this->expectException(AppwriteException::class);
        $this->expectExceptionMessage('API key is missing required S3 storage scopes.');

        (new SignatureV4())->verify(
            $request,
            $this->project($secret, ['files.read']),
            new Document(),
            new User(),
            ['files.write']
        );
    }

    public function testRejectsInvalidSignature(): void
    {
        $secret = 'test-api-key-secret';
        $request = $this->signedRequest('GET', '/v1/s3/bucket/object.txt', '', $secret);
        $request->headerMap['authorization'] = \preg_replace('/Signature=[0-9a-f]+/', 'Signature=deadbeef', $request->headerMap['authorization']);

        $this->expectException(AppwriteException::class);
        $this->expectExceptionMessage('Signature does not match.');

        (new SignatureV4())->verify(
            $request,
            $this->project($secret, ['files.read']),
            new Document(),
            new User(),
            ['files.read']
        );
    }

    public function testRejectsPayloadThatDoesNotMatchSignedHash(): void
    {
        $secret = 'test-api-key-secret';
        $request = $this->signedRequest('PUT', '/v1/s3/bucket/object.txt', 'original', $secret);
        $request->body = 'tampered';

        $this->expectException(AppwriteException::class);
        $this->expectExceptionMessage('Payload hash does not match');

        (new SignatureV4())->verify(
            $request,
            $this->project($secret, ['files.write']),
            new Document(),
            new User(),
            ['files.write']
        );
    }

    public function testRejectsExpiredSignatureTimestamp(): void
    {
        $secret = 'test-api-key-secret';
        $date = \gmdate('Ymd\THis\Z', \time() - 901);
        $request = $this->signedRequest('GET', '/v1/s3/bucket/object.txt', '', $secret, date: $date);

        $this->expectException(AppwriteException::class);
        $this->expectExceptionMessage('AWS Signature V4 timestamp is outside the allowed time window.');

        (new SignatureV4())->verify(
            $request,
            $this->project($secret, ['files.read']),
            new Document(),
            new User(),
            ['files.read']
        );
    }

    public function testRejectsFutureSignatureTimestamp(): void
    {
        $secret = 'test-api-key-secret';
        $date = \gmdate('Ymd\THis\Z', \time() + 901);
        $request = $this->signedRequest('GET', '/v1/s3/bucket/object.txt', '', $secret, date: $date);

        $this->expectException(AppwriteException::class);
        $this->expectExceptionMessage('AWS Signature V4 timestamp is outside the allowed time window.');

        (new SignatureV4())->verify(
            $request,
            $this->project($secret, ['files.read']),
            new Document(),
            new User(),
            ['files.read']
        );
    }

    private function project(string $secret, array $scopes): Document
    {
        return new Document([
            '$id' => 'project-test',
            'keys' => [
                [
                    '$id' => 'key-test',
                    'name' => 'S3 Test Key',
                    'secret' => $secret,
                    'scopes' => $scopes,
                    'expire' => null,
                ],
            ],
        ]);
    }

    private function presignedRequestAt(string $method, string $uri, string $secret, string $date, int $expires): TestRequest
    {
        $shortDate = \substr($date, 0, 8);
        $scope = "{$shortDate}/us-east-1/s3/aws4_request";
        $parameters = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => 'project-test/' . $scope,
            'X-Amz-Date' => $date,
            'X-Amz-Expires' => (string) $expires,
            'X-Amz-SignedHeaders' => 'host',
        ];
        $encoded = [];
        foreach ($parameters as $name => $value) {
            $encoded[] = \rawurlencode($name) . '=' . \rawurlencode($value);
        }
        \sort($encoded, SORT_STRING);
        $canonicalQuery = \implode('&', $encoded);
        $canonicalRequest = \implode("\n", [
            $method,
            $uri,
            $canonicalQuery,
            "host:cloud.appwrite.test\n",
            'host',
            'UNSIGNED-PAYLOAD',
        ]);
        $stringToSign = "AWS4-HMAC-SHA256\n{$date}\n{$scope}\n" . \hash('sha256', $canonicalRequest);
        $signature = \hash_hmac('sha256', $stringToSign, $this->signingKey($secret, $shortDate, 'us-east-1', 's3'));
        $query = $canonicalQuery . '&X-Amz-Signature=' . $signature;

        return new TestRequest($method, $uri . '?' . $query, ['host' => 'cloud.appwrite.test'], '', $query);
    }

    private function signedRequest(string $method, string $uri, string $body, string $secret, ?string $payloadHash = null, string $query = '', ?string $date = null, array $additionalHeaders = []): TestRequest
    {
        $date ??= \gmdate('Ymd\THis\Z');
        $shortDate = \substr($date, 0, 8);
        $region = 'us-east-1';
        $service = 's3';
        $headers = \array_merge([
            'host' => 'cloud.appwrite.test',
            'x-amz-content-sha256' => $payloadHash ?? \hash('sha256', $body),
            'x-amz-date' => $date,
        ], $additionalHeaders);
        \ksort($headers);

        $signedHeaders = \implode(';', \array_keys($headers));
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . \preg_replace('/\s+/', ' ', \trim($value)) . "\n";
        }
        $canonicalRequest = \implode("\n", [
            $method,
            $uri,
            $query,
            $canonicalHeaders,
            $signedHeaders,
            $headers['x-amz-content-sha256'],
        ]);

        $scope = "{$shortDate}/{$region}/{$service}/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$date}\n{$scope}\n" . \hash('sha256', $canonicalRequest);
        $signature = \hash_hmac('sha256', $stringToSign, $this->signingKey($secret, $shortDate, $region, $service));

        $headers['authorization'] = "AWS4-HMAC-SHA256 Credential=project-test/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        return new TestRequest($method, $query === '' ? $uri : $uri . '?' . $query, $headers, $body, $query);
    }

    private function signingKey(string $secret, string $date, string $region, string $service): string
    {
        $kDate = \hash_hmac('sha256', $date, 'AWS4' . $secret, true);
        $kRegion = \hash_hmac('sha256', $region, $kDate, true);
        $kService = \hash_hmac('sha256', $service, $kRegion, true);
        return \hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}

final class TestRequest extends Request
{
    /**
     * @param array<string, string|null> $headerMap
     */
    public function __construct(
        private readonly string $method,
        public readonly string $uri,
        public array $headerMap,
        public string $body,
        public readonly string $query = '',
    ) {
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getURI(): string
    {
        return $this->uri;
    }

    public function getHeaderLine(string $key, string $default = ''): string
    {
        return $this->headerMap[\strtolower($key)] ?? $default;
    }

    public function hasHeader(string $key): bool
    {
        return \array_key_exists(\strtolower($key), $this->headerMap);
    }

    public function getRawPayload(): string
    {
        return $this->body;
    }

    public function getServer(string $key, ?string $default = null): ?string
    {
        return $key === 'query_string' ? $this->query : $default;
    }

    public function getSwooleRequest(): SwooleRequest
    {
        throw new \LogicException('Not used by this test.');
    }
}
