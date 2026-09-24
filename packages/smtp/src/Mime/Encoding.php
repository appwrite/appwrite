<?php

declare(strict_types=1);

namespace Utopia\SMTP\Mime;

/**
 * Body encodings, chosen for the caller rather than configured by them:
 * quoted-printable for text and base64 for attachments. Header text is
 * Header's business.
 */
class Encoding
{
    /** Base64 wraps at 76 characters, which is 57 bytes of input. */
    public const CHUNK = 57;

    public static function isAscii(string $value): bool
    {
        return preg_match('/^[\x00-\x7F]*$/', $value) === 1;
    }

    public static function quotedPrintable(string $value): string
    {
        return quoted_printable_encode(self::lineEndings($value));
    }

    public static function base64(string $value): string
    {
        return chunk_split(base64_encode($value), 76, "\r\n");
    }

    /**
     * Every line ending, whatever it started as, becomes CRLF.
     */
    public static function lineEndings(string $value): string
    {
        return preg_replace('/\r\n|\r|\n/', "\r\n", $value) ?? $value;
    }

    public static function boundary(): string
    {
        return '=_' . bin2hex(random_bytes(16));
    }
}
