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

    public function quote(string $value): string
    {
        if ($this->refusesNul() && \str_contains($value, "\0")) {
            throw new ValidationException('String literal contains a NUL byte');
        }

        $doubled = \str_replace(self::QUOTE, self::QUOTE . self::QUOTE, $value);

        if (!$this->escapesBackslash() || !\str_contains($value, self::BACKSLASH)) {
            return self::QUOTE . $doubled . self::QUOTE;
        }

        if ($this->refusesBackslashAfterHighByte() && self::hasBackslashAfterHighByte($value)) {
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

        $value = $this->decodeBody(\substr($literal, 1, -1), $escaped);

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

    private static function hasBackslashAfterHighByte(string $value): bool
    {
        $position = \strpos($value, self::BACKSLASH, 1);

        while ($position !== false) {
            if (\ord($value[$position - 1]) >= 0x80) {
                return true;
            }

            $position = \strpos($value, self::BACKSLASH, $position + 1);
        }

        return false;
    }

    private function decodeBody(string $body, bool $escaped): string
    {
        $value = '';
        $length = \strlen($body);
        $position = 0;

        while ($position < $length) {
            $character = $body[$position];

            if ($character === self::QUOTE) {
                if ($position + 1 >= $length || $body[$position + 1] !== self::QUOTE) {
                    throw new ValidationException('Malformed string literal');
                }

                $value .= self::QUOTE;
                $position += 2;

                continue;
            }

            if ($escaped && $character === self::BACKSLASH) {
                if ($position + 1 >= $length) {
                    throw new ValidationException('Malformed string literal');
                }

                $escape = $this->readEscape($body, $position + 1);
                $value .= $this->decode($escape);
                $position += 1 + \strlen($escape);

                continue;
            }

            $value .= $character;
            $position++;
        }

        return $value;
    }

    private function readEscape(string $body, int $position): string
    {
        $character = $body[$position];

        return match ($this) {
            self::BackslashUtf8 => $character === 'x'
                ? self::readHex($body, $position, 2, 2)
                : $character,
            self::Extended => match ($character) {
                '0', '1', '2', '3', '4', '5', '6', '7' => self::readOctal($body, $position),
                'x' => self::readHex($body, $position, 1, 2),
                'u' => self::readHex($body, $position, 4, 4),
                'U' => self::readHex($body, $position, 8, 8),
                default => $character,
            },
            self::Backslash, self::Standard => $character,
        };
    }

    private static function readOctal(string $body, int $position): string
    {
        $length = 1;

        while ($length < 3 && $position + $length < \strlen($body) && $body[$position + $length] >= '0' && $body[$position + $length] <= '7') {
            $length++;
        }

        return \substr($body, $position, $length);
    }

    private static function readHex(string $body, int $position, int $minimum, int $maximum): string
    {
        $length = 0;

        while ($length < $maximum && $position + 1 + $length < \strlen($body) && \ctype_xdigit($body[$position + 1 + $length])) {
            $length++;
        }

        if ($length < $minimum) {
            return $body[$position];
        }

        return \substr($body, $position, 1 + $length);
    }

    private function decode(string $escape): string
    {
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
