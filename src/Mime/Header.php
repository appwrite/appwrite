<?php

declare(strict_types=1);

namespace Utopia\SMTP\Mime;

use Utopia\SMTP\Exception;

/**
 * A header field, from a name and a value to bytes a server will accept.
 *
 * Callers hand over the value they mean and get back a conforming line. What
 * that takes — encoded words for anything outside ASCII, splitting those words
 * so none exceeds its limit, and folding the result so no line runs long — is
 * this module's business and nobody else's.
 */
final readonly class Header
{
    /** RFC 5322 section 2.1.1: the length a line should keep to. */
    private const int SOFT = 78;

    /** RFC 5322 section 2.1.1: the length a line must not exceed. */
    private const int HARD = 998;

    /**
     * RFC 2047 section 2 caps an encoded word at 75 characters, delimiters
     * included. `=?UTF-8?B?` and `?=` spend 12 of them, and base64 turns three
     * bytes into four characters, so 45 bytes is the most that fits.
     */
    private const int PAYLOAD = 45;

    /**
     * One field, terminated. The value must already be free of line endings;
     * callers validate that when they accept it.
     */
    public static function line(string $name, string $value): string
    {
        if (! Encoding::isAscii($value)) {
            $value = self::encode($value);
        }

        return self::fold($name, "{$name}: {$value}") . "\r\n";
    }

    /**
     * A display name, for use inside a structured value such as an address.
     */
    public static function phrase(string $value): string
    {
        if (! Encoding::isAscii($value)) {
            return self::encode($value);
        }

        return '"' . addcslashes($value, '"\\') . '"';
    }

    /**
     * Non-ASCII text as RFC 2047 encoded words, split on character boundaries
     * so each word decodes on its own.
     */
    private static function encode(string $text): string
    {
        $words = [];
        $chunk = '';

        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if ($chunk !== '' && \strlen($chunk) + \strlen($character) > self::PAYLOAD) {
                $words[] = self::word($chunk);
                $chunk = '';
            }

            $chunk .= $character;
        }

        if ($chunk !== '') {
            $words[] = self::word($chunk);
        }

        // Folding between the words is what keeps the line short, and RFC 2047
        // drops the whitespace between adjacent encoded words when decoding.
        return implode(' ', $words);
    }

    private static function word(string $text): string
    {
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }

    /**
     * Break the line at spaces, which unfolding puts back exactly as they were.
     */
    private static function fold(string $name, string $line): string
    {
        $folded = '';
        $current = '';

        foreach (explode(' ', $line) as $word) {
            if (\strlen($word) > self::HARD) {
                throw new Exception("The {$name} header has an unbreakable run of " . \strlen($word) . ' octets');
            }

            $candidate = $current === '' ? $word : "{$current} {$word}";

            if ($current !== '' && \strlen($candidate) > self::SOFT) {
                $folded .= "{$current}\r\n ";
                $current = $word;

                continue;
            }

            $current = $candidate;
        }

        return $folded . $current;
    }
}
