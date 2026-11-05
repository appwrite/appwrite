<?php

namespace Utopia\Query;

use Utopia\Query\Exception\ValidationException;

enum StringLiteral
{
    case Backslash;
    case BackslashUtf8;
    case Extended;
    case Standard;

    private const string QUOTE = "'";

    private const string BACKSLASH = '\\';

    private const string DOUBLED_QUOTE_PATTERN = "/''|'/";

    public function quote(string $value): string
    {
        if ($this->refusesNul() && \str_contains($value, "\0")) {
            throw new ValidationException('String literal contains a NUL byte');
        }

        $doubled = \str_replace(self::QUOTE, self::QUOTE . self::QUOTE, $value);

        if (!$this->escapesBackslash() || !\str_contains($value, self::BACKSLASH)) {
            return self::QUOTE . $doubled . self::QUOTE;
        }

        if ($this->refusesBackslashAfterHighByte() && \preg_match('/[\x80-\xFF]\\\\/', $value) === 1) {
            throw new ValidationException('String literal has a backslash after a byte of 0x80 or above, which a multibyte connection charset can read as part of the preceding character');
        }

        return $this->escapePrefix() . self::QUOTE . \str_replace(self::BACKSLASH, self::BACKSLASH . self::BACKSLASH, $doubled) . self::QUOTE;
    }

    public function unquote(string $literal): string
    {
        $prefix = $this->escapePrefix();
        $escaped = $this->escapesInPlainLiteral();

        if ($prefix !== '' && \strncasecmp($literal, $prefix . self::QUOTE, \strlen($prefix) + 1) === 0) {
            $literal = \substr($literal, \strlen($prefix));
            $escaped = true;
        }

        if (\strlen($literal) < 2 || !\str_starts_with($literal, self::QUOTE) || !\str_ends_with($literal, self::QUOTE)) {
            throw new ValidationException('Malformed string literal');
        }

        $body = \substr($literal, 1, -1);

        $value = \preg_replace_callback(
            $escaped ? $this->escapePattern() : self::DOUBLED_QUOTE_PATTERN,
            fn (array $match): string => $this->decode($match),
            $body,
        );

        if ($value === null) {
            throw new ValidationException('Malformed string literal');
        }

        if ($this->refusesNul() && \str_contains($value, "\0")) {
            throw new ValidationException('String literal contains a NUL byte');
        }

        return $value;
    }

    public function escapePrefix(): string
    {
        return match ($this) {
            self::Extended => 'E',
            self::Backslash, self::BackslashUtf8, self::Standard => '',
        };
    }

    public function escapesInPlainLiteral(): bool
    {
        return match ($this) {
            self::Backslash, self::BackslashUtf8 => true,
            self::Extended, self::Standard => false,
        };
    }

    private function escapesBackslash(): bool
    {
        return match ($this) {
            self::Backslash, self::BackslashUtf8, self::Extended => true,
            self::Standard => false,
        };
    }

    private function refusesBackslashAfterHighByte(): bool
    {
        return match ($this) {
            self::Backslash, self::Extended => true,
            self::BackslashUtf8, self::Standard => false,
        };
    }

    private function refusesNul(): bool
    {
        return match ($this) {
            self::Extended, self::Standard => true,
            self::Backslash, self::BackslashUtf8 => false,
        };
    }

    private function escapePattern(): string
    {
        return match ($this) {
            self::Backslash => "/\\\\(.)?|''|'/s",
            self::BackslashUtf8 => "/\\\\(x[0-9A-Fa-f]{2}|.)?|''|'/s",
            self::Extended => "/\\\\([0-7]{1,3}|x[0-9A-Fa-f]{1,2}|u[0-9A-Fa-f]{4}|U[0-9A-Fa-f]{8}|.)?|''|'/s",
            self::Standard => self::DOUBLED_QUOTE_PATTERN,
        };
    }

    /**
     * @param array<int|string, string> $match
     */
    private function decode(array $match): string
    {
        if ($match[0] === self::QUOTE . self::QUOTE) {
            return self::QUOTE;
        }

        $escape = $match[1] ?? '';

        if ($match[0] === self::QUOTE || $escape === '') {
            throw new ValidationException('Malformed string literal');
        }

        if (\strlen($escape) > 1) {
            return $this->decodeNumeric($escape);
        }

        return match ($this) {
            self::Backslash => match ($escape) {
                '0' => "\0",
                'b' => "\x08",
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'Z' => "\x1A",
                '%', '_' => self::BACKSLASH . $escape,
                default => $escape,
            },
            self::BackslashUtf8 => match ($escape) {
                '0' => "\0",
                'a' => "\x07",
                'b' => "\x08",
                'f' => "\f",
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'v' => "\v",
                default => $escape,
            },
            self::Extended => match (true) {
                $escape >= '0' && $escape <= '7' => $this->decodeNumeric($escape),
                $escape === 'b' => "\x08",
                $escape === 'f' => "\f",
                $escape === 'n' => "\n",
                $escape === 'r' => "\r",
                $escape === 't' => "\t",
                default => $escape,
            },
            self::Standard => self::BACKSLASH . $escape,
        };
    }

    private function decodeNumeric(string $escape): string
    {
        return match ($escape[0]) {
            'x' => \chr(((int) \hexdec(\substr($escape, 1))) & 0xFF),
            'u', 'U' => self::encodeUtf8((int) \hexdec(\substr($escape, 1))),
            default => \chr(((int) \octdec($escape)) & 0xFF),
        };
    }

    private static function encodeUtf8(int $codePoint): string
    {
        return match (true) {
            $codePoint < 0, $codePoint > 0x10FFFF, $codePoint >= 0xD800 && $codePoint <= 0xDFFF => throw new ValidationException('String literal has an invalid Unicode escape'),
            $codePoint < 0x80 => \chr($codePoint),
            $codePoint < 0x800 => \chr(0xC0 | ($codePoint >> 6)) . \chr(0x80 | ($codePoint & 0x3F)),
            $codePoint < 0x10000 => \chr(0xE0 | ($codePoint >> 12)) . \chr(0x80 | (($codePoint >> 6) & 0x3F)) . \chr(0x80 | ($codePoint & 0x3F)),
            default => \chr(0xF0 | ($codePoint >> 18)) . \chr(0x80 | (($codePoint >> 12) & 0x3F)) . \chr(0x80 | (($codePoint >> 6) & 0x3F)) . \chr(0x80 | ($codePoint & 0x3F)),
        };
    }
}
