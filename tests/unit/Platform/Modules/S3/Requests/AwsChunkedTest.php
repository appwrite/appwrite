<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\S3\Requests;

use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Platform\Modules\S3\Requests\AwsChunked;
use PHPUnit\Framework\TestCase;

final class AwsChunkedTest extends TestCase
{
    public function testAppliesDetectsStreamingPayloadsAndChunkedEncoding(): void
    {
        $this->assertTrue(AwsChunked::applies('STREAMING-UNSIGNED-PAYLOAD-TRAILER', ''));
        $this->assertTrue(AwsChunked::applies('streaming-aws4-hmac-sha256-payload', ''));
        $this->assertTrue(AwsChunked::applies('', 'aws-chunked'));
        $this->assertTrue(AwsChunked::applies('', 'gzip, aws-chunked'));

        $this->assertFalse(AwsChunked::applies(\hash('sha256', 'body'), ''));
        $this->assertFalse(AwsChunked::applies('UNSIGNED-PAYLOAD', ''));
        $this->assertFalse(AwsChunked::applies('', 'gzip'));
    }

    public function testDecodesUnsignedTrailerFraming(): void
    {
        $payload = "6\r\nhello \r\n"
            . "5\r\nworld\r\n"
            . "0\r\n"
            . 'x-amz-checksum-crc32:' . \base64_encode(\hash('crc32b', 'hello world', true)) . "\r\n"
            . "\r\n";

        $this->assertSame('hello world', AwsChunked::decode($payload, 11));
    }

    public function testDecodesSignedChunkFraming(): void
    {
        $payload = "6;chunk-signature=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\r\nhello \r\n"
            . "5;chunk-signature=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb\r\nworld\r\n"
            . "0;chunk-signature=cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc\r\n\r\n";

        $this->assertSame('hello world', AwsChunked::decode($payload, 11));
    }

    public function testDecodesEmptyBody(): void
    {
        $this->assertSame('', AwsChunked::decode("0\r\n\r\n", 0));
        $this->assertSame('', AwsChunked::decode("0\r\n", null));
    }

    public function testDecodeIgnoresUnverifiedTrailerAlgorithms(): void
    {
        $payload = "3\r\nabc\r\n0\r\nx-amz-checksum-sha256:not-checked\r\n\r\n";

        $this->assertSame('abc', AwsChunked::decode($payload, 3));
    }

    public function testRejectsDeclaredLengthMismatch(): void
    {
        $this->expectException(AppwriteException::class);
        $this->expectExceptionMessage('x-amz-decoded-content-length');

        AwsChunked::decode("3\r\nabc\r\n0\r\n\r\n", 4);
    }

    public function testRejectsCrc32TrailerMismatch(): void
    {
        $this->expectException(AppwriteException::class);
        $this->expectExceptionMessage('CRC32');

        AwsChunked::decode("3\r\nabc\r\n0\r\n" . 'x-amz-checksum-crc32:' . \base64_encode(\hash('crc32b', 'abd', true)) . "\r\n\r\n", 3);
    }

    public function testRejectsTruncatedChunkData(): void
    {
        $this->expectException(AppwriteException::class);
        $this->expectExceptionMessage('truncated');

        AwsChunked::decode("6\r\nhel", null);
    }

    public function testRejectsMissingFinalChunk(): void
    {
        $this->expectException(AppwriteException::class);
        $this->expectExceptionMessage('zero-size chunk');

        AwsChunked::decode("3\r\nabc\r\n", null);
    }

    public function testRejectsNonHexadecimalChunkSize(): void
    {
        $this->expectException(AppwriteException::class);
        $this->expectExceptionMessage('hexadecimal');

        AwsChunked::decode("zz\r\nabc\r\n0\r\n\r\n", null);
    }

    public function testRejectsChunkDataWithoutCrlfTerminator(): void
    {
        $this->expectException(AppwriteException::class);
        $this->expectExceptionMessage('CRLF');

        AwsChunked::decode("3\r\nabcXX0\r\n\r\n", null);
    }
}
