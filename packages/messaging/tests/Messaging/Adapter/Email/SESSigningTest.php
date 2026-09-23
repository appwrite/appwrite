<?php

declare(strict_types=1);

namespace Utopia\Tests\Adapter\Email;

use PHPUnit\Framework\TestCase;
use Utopia\Messaging\Adapter\Email\SES;

/**
 * Deterministic, network-free verification of the hand-rolled AWS Signature
 * Version 4 implementation against AWS's published "get-vanilla" test vector
 * from the aws-sig-v4-test-suite.
 *
 * Fixed inputs:
 *   Access key:  AKIDEXAMPLE
 *   Secret key:  wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY
 *   Region:      us-east-1
 *   Service:     service
 *   Timestamp:   20150830T123600Z
 *   Request:     GET / HTTP/1.1, Host: example.amazonaws.com, empty body
 *
 * Expected signature: 5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31
 *
 * @link https://docs.aws.amazon.com/IAM/latest/UserGuide/create-signed-request.html
 */
final class SESSigningTest extends TestCase
{
    private const string ACCESS_KEY = 'AKIDEXAMPLE';

    private const string SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

    private const string EXPECTED_SIGNATURE = '5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31';

    public function testSignatureMatchesAwsGetVanillaVector(): void
    {
        $signer = new SESSigningStub(self::ACCESS_KEY, self::SECRET_KEY, 'us-east-1');

        $authorization = $signer->callSign(
            method: 'GET',
            path: '/',
            payload: '',
            signedHeaders: [
                'host' => 'example.amazonaws.com',
                'x-amz-date' => '20150830T123600Z',
            ],
            amzDate: '20150830T123600Z',
        );

        $expected = 'AWS4-HMAC-SHA256 '
            . 'Credential=' . self::ACCESS_KEY . '/20150830/us-east-1/service/aws4_request, '
            . 'SignedHeaders=host;x-amz-date, '
            . 'Signature=' . self::EXPECTED_SIGNATURE;

        $this->assertSame($expected, $authorization);
    }

    public function testSignatureContainsExpectedHexSignature(): void
    {
        $signer = new SESSigningStub(self::ACCESS_KEY, self::SECRET_KEY, 'us-east-1');

        $authorization = $signer->callSign(
            method: 'GET',
            path: '/',
            payload: '',
            signedHeaders: [
                'host' => 'example.amazonaws.com',
                'x-amz-date' => '20150830T123600Z',
            ],
            amzDate: '20150830T123600Z',
        );

        $this->assertStringContainsString('Signature=' . self::EXPECTED_SIGNATURE, $authorization);
    }

    public function testHeadersAreSortedRegardlessOfInputOrder(): void
    {
        $signer = new SESSigningStub(self::ACCESS_KEY, self::SECRET_KEY, 'us-east-1');

        // Supply headers out of order; SignedHeaders must still be sorted.
        $authorization = $signer->callSign(
            method: 'GET',
            path: '/',
            payload: '',
            signedHeaders: [
                'x-amz-date' => '20150830T123600Z',
                'host' => 'example.amazonaws.com',
            ],
            amzDate: '20150830T123600Z',
        );

        $this->assertStringContainsString('SignedHeaders=host;x-amz-date', $authorization);
        $this->assertStringContainsString('Signature=' . self::EXPECTED_SIGNATURE, $authorization);
    }

    public function testDifferentPayloadProducesDifferentSignature(): void
    {
        $signer = new SESSigningStub(self::ACCESS_KEY, self::SECRET_KEY, 'us-east-1');

        $empty = $signer->callSign(
            method: 'GET',
            path: '/',
            payload: '',
            signedHeaders: [
                'host' => 'example.amazonaws.com',
                'x-amz-date' => '20150830T123600Z',
            ],
            amzDate: '20150830T123600Z',
        );

        $withBody = $signer->callSign(
            method: 'GET',
            path: '/',
            payload: '{"hello":"world"}',
            signedHeaders: [
                'host' => 'example.amazonaws.com',
                'x-amz-date' => '20150830T123600Z',
            ],
            amzDate: '20150830T123600Z',
        );

        $this->assertNotSame($empty, $withBody);
    }
}

/**
 * Exposes the protected sign() method and pins the SigV4 service name to the
 * AWS test-vector value ('service') so the implementation can be checked
 * against published vectors without hitting the network.
 */
class SESSigningStub extends SES
{
    public function __construct(string $accessKey, string $secretKey, string $region)
    {
        parent::__construct($accessKey, $secretKey, $region);
        $this->service = 'service';
    }

    /**
     * @param  array<string, string>  $signedHeaders
     */
    public function callSign(string $method, string $path, string $payload, array $signedHeaders, string $amzDate): string
    {
        return $this->sign($method, $path, $payload, $signedHeaders, $amzDate);
    }
}
