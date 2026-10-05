<?php

namespace Appwrite\Platform\Modules\S3\Requests;

use Appwrite\Extend\Exception as AppwriteException;

/**
 * Decoder for aws-chunked request bodies (AWS SigV4 streaming payloads).
 *
 * Modern AWS SDKs upload objects as `Content-Encoding: aws-chunked` with
 * `x-amz-content-sha256: STREAMING-*`: the body arrives framed as
 * `<hex-size>[;chunk-signature=…]\r\n<bytes>\r\n` chunks, terminated by a
 * zero-size chunk and optional trailing headers. Storing the raw payload
 * would persist the framing bytes as object content.
 *
 * Signed streaming modes are rejected by the gateway until their chained
 * per-chunk signatures can be verified. Unsigned streaming payloads are
 * checked through the declared decoded length and CRC32 trailer checksum.
 */
class AwsChunked
{
    public static function applies(string $contentSha256, string $contentEncoding): bool
    {
        if (\str_starts_with(\strtoupper(\trim($contentSha256)), 'STREAMING-')) {
            return true;
        }

        foreach (\explode(',', $contentEncoding) as $encoding) {
            if (\strtolower(\trim($encoding)) === 'aws-chunked') {
                return true;
            }
        }

        return false;
    }

    public static function decode(string $payload, ?int $declaredLength = null): string
    {
        $decoded = '';
        $offset = 0;
        $length = \strlen($payload);

        while (true) {
            if ($offset >= $length) {
                throw self::malformed('Missing final zero-size chunk.');
            }

            $lineEnd = \strpos($payload, "\r\n", $offset);
            if ($lineEnd === false) {
                throw self::malformed('Chunk header is not CRLF-terminated.');
            }

            $sizeHex = \explode(';', \substr($payload, $offset, $lineEnd - $offset), 2)[0];
            if ($sizeHex === '' || \strlen($sizeHex) > 8 || \preg_match('/^[0-9a-fA-F]+$/', $sizeHex) !== 1) {
                throw self::malformed('Chunk size is not hexadecimal.');
            }

            $size = (int) \hexdec($sizeHex);
            $offset = $lineEnd + 2;

            if ($size === 0) {
                self::verifyTrailers(self::trailers($payload, $offset), $decoded);
                break;
            }

            if ($offset + $size > $length) {
                throw self::malformed('Chunk data is truncated.');
            }

            $decoded .= \substr($payload, $offset, $size);
            $offset += $size;

            if (\substr($payload, $offset, 2) !== "\r\n") {
                throw self::malformed('Chunk data is not CRLF-terminated.');
            }
            $offset += 2;
        }

        if ($declaredLength !== null && \strlen($decoded) !== $declaredLength) {
            throw self::malformed('Decoded length does not match x-amz-decoded-content-length.');
        }

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    private static function trailers(string $payload, int $offset): array
    {
        $trailers = [];
        $length = \strlen($payload);

        while ($offset < $length) {
            $lineEnd = \strpos($payload, "\r\n", $offset);
            $line = $lineEnd === false ? \substr($payload, $offset) : \substr($payload, $offset, $lineEnd - $offset);
            $offset = $lineEnd === false ? $length : $lineEnd + 2;

            if (\trim($line) === '') {
                continue;
            }

            [$name, $value] = \array_pad(\explode(':', $line, 2), 2, '');
            $trailers[\strtolower(\trim($name))] = \trim($value);
        }

        return $trailers;
    }

    /**
     * @param array<string, string> $trailers
     */
    private static function verifyTrailers(array $trailers, string $decoded): void
    {
        // CRC32 is the SDK default checksum algorithm; other algorithms
        // (crc32c, sha1, sha256, crc64nvme) are accepted but not verified.
        $crc32 = $trailers['x-amz-checksum-crc32'] ?? '';
        if ($crc32 !== '' && \base64_encode(\hash('crc32b', $decoded, true)) !== $crc32) {
            throw self::malformed('CRC32 trailer checksum does not match the decoded payload.');
        }
    }

    private static function malformed(string $reason): AppwriteException
    {
        return new AppwriteException(AppwriteException::GENERAL_ARGUMENT_INVALID, 'Malformed aws-chunked payload. ' . $reason);
    }
}
