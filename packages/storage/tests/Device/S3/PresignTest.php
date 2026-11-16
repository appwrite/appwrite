<?php

declare(strict_types=1);

namespace Utopia\Storage\Tests\Device\S3;

use PHPUnit\Framework\TestCase;
use Utopia\Storage\Device\S3;

/**
 * Presigned URLs, checked against the worked example in AWS's SigV4
 * documentation ("Authenticating Requests: Using Query Parameters"), so the
 * signature is compared with one nobody here computed.
 */
final class PresignTest extends TestCase
{
    private const string ACCESS = 'AKIAIOSFODNN7EXAMPLE';

    private const string SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

    private const int NOW = 1369353600; // 20130524T000000Z

    public function testMatchesTheDocumentedExample(): void
    {
        $device = new S3('', self::ACCESS, self::SECRET, 'https://examplebucket.s3.amazonaws.com', 'us-east-1', bucket: 'examplebucket');

        $this->assertSame(
            'https://examplebucket.s3.amazonaws.com/test.txt'
            . '?X-Amz-Algorithm=AWS4-HMAC-SHA256'
            . '&X-Amz-Credential=AKIAIOSFODNN7EXAMPLE%2F20130524%2Fus-east-1%2Fs3%2Faws4_request'
            . '&X-Amz-Date=20130524T000000Z'
            . '&X-Amz-Expires=86400'
            . '&X-Amz-SignedHeaders=host'
            . '&X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404',
            $device->getPresignedUrl('test.txt', 86400, self::NOW),
        );
    }

    /**
     * Path-style endpoints carry the bucket in the path, which has to be both in
     * the URL and in what was signed.
     */
    public function testAPathStyleEndpointKeepsTheBucketInThePath(): void
    {
        $device = new S3('', self::ACCESS, self::SECRET, 'http://minio:9000/examplebucket', 'us-east-1', bucket: 'examplebucket');

        $url = $device->getPresignedUrl('storage/uploads/app-1/file.mp4', 60, self::NOW);

        $this->assertStringStartsWith('http://minio:9000/examplebucket/storage/uploads/app-1/file.mp4?', $url);
        $this->assertStringContainsString('X-Amz-Expires=60', $url);
        $this->assertMatchesRegularExpression('/&X-Amz-Signature=[0-9a-f]{64}$/', $url);
    }

    public function testAPathIsEncodedOnceAndItsSlashesKept(): void
    {
        $device = new S3('', self::ACCESS, self::SECRET, 'https://examplebucket.s3.amazonaws.com', 'us-east-1', bucket: 'examplebucket');

        $url = $device->getPresignedUrl('dir/a file+1.mp4', 60, self::NOW);

        $this->assertStringStartsWith('https://examplebucket.s3.amazonaws.com/dir/a%20file%2B1.mp4?', $url);
    }

    public function testTheSignatureDependsOnTheObjectAndTheLifetime(): void
    {
        $device = new S3('', self::ACCESS, self::SECRET, 'https://examplebucket.s3.amazonaws.com', 'us-east-1', bucket: 'examplebucket');

        $signature = static fn (string $url): string => substr($url, -64);

        $one = $device->getPresignedUrl('a.mp4', 60, self::NOW);
        $this->assertNotSame($signature($one), $signature($device->getPresignedUrl('b.mp4', 60, self::NOW)));
        $this->assertNotSame($signature($one), $signature($device->getPresignedUrl('a.mp4', 61, self::NOW)));
    }

    public function testALifetimeOutsideWhatSigV4AllowsIsRefused(): void
    {
        $device = new S3('', self::ACCESS, self::SECRET, 'https://examplebucket.s3.amazonaws.com', 'us-east-1', bucket: 'examplebucket');

        foreach ([0, -1, 604801] as $expires) {
            try {
                $device->getPresignedUrl('a.mp4', $expires);
                $this->fail($expires . ' seconds was accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertStringContainsString('X-Amz-Expires=604800', $device->getPresignedUrl('a.mp4', 604800));
    }
}
