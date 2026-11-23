<?php

declare(strict_types=1);

namespace Utopia\Cdn\Tests\Aws;

use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Aws\Signature;
use Utopia\Psr7\Request\Factory as RequestFactory;

final class SignatureTest extends TestCase
{
    private const string ACCESS_KEY_ID = 'AKIDEXAMPLE';

    private const string SECRET_ACCESS_KEY = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

    public function testMatchesTheAwsGetVanillaVector(): void
    {
        // The get-vanilla case of the AWS SigV4 test suite.
        $request = new RequestFactory()->createRequest('GET', 'https://example.amazonaws.com/');

        $signed = new Signature(self::ACCESS_KEY_ID, self::SECRET_ACCESS_KEY)
            ->sign($request, 'service', 'us-east-1', new \DateTimeImmutable('2015-08-30T12:36:00Z'));

        $this->assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
            $signed->getHeaderLine('Authorization'),
        );
        $this->assertSame('20150830T123600Z', $signed->getHeaderLine('X-Amz-Date'));
    }

    public function testMatchesBotocoreForASignedXmlPostWithASessionToken(): void
    {
        // Expected value produced by botocore's SigV4Auth for the same request, credentials and time.
        $request = new RequestFactory()->xml(
            'POST',
            'https://cloudfront.amazonaws.com/2020-05-31/distribution-tenant/dt_123/invalidation?b=2&a=1%201',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<InvalidationBatch/>",
        );

        $signed = new Signature(self::ACCESS_KEY_ID, self::SECRET_ACCESS_KEY, 'session-token')
            ->sign($request, 'cloudfront', 'us-east-1', new \DateTimeImmutable('2026-10-23T16:56:24Z'));

        $this->assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20261023/us-east-1/cloudfront/aws4_request, SignedHeaders=content-type;host;x-amz-date;x-amz-security-token, Signature=b5d99c9f739659c70adb3f22074f4c2829c138c416adf7ff6722d544bbe45a37',
            $signed->getHeaderLine('Authorization'),
        );
        $this->assertSame('session-token', $signed->getHeaderLine('X-Amz-Security-Token'));
    }

    public function testSigningTimeIsConvertedToUtc(): void
    {
        $request = new RequestFactory()->createRequest('GET', 'https://example.amazonaws.com/');

        $signed = new Signature(self::ACCESS_KEY_ID, self::SECRET_ACCESS_KEY)
            ->sign($request, 'service', 'us-east-1', new \DateTimeImmutable('2015-08-30T14:36:00+02:00'));

        $this->assertSame('20150830T123600Z', $signed->getHeaderLine('X-Amz-Date'));
        $this->assertStringEndsWith('Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31', $signed->getHeaderLine('Authorization'));
    }

    public function testResigningReplacesTheAuthorizationHeader(): void
    {
        $signature = new Signature(self::ACCESS_KEY_ID, self::SECRET_ACCESS_KEY);
        $time = new \DateTimeImmutable('2015-08-30T12:36:00Z');
        $request = new RequestFactory()->createRequest('GET', 'https://example.amazonaws.com/');

        $once = $signature->sign($request, 'service', 'us-east-1', $time);
        $twice = $signature->sign($once, 'service', 'us-east-1', $time);

        $this->assertCount(1, $twice->getHeader('Authorization'));
        $this->assertSame($once->getHeaderLine('Authorization'), $twice->getHeaderLine('Authorization'));
    }
}
