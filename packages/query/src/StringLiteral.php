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

        if ($this->refusesBackslashAfterHighByte() && \preg_match('/[\x80-\xFF]\\\\/', $value) === 1) {
            throw new ValidationException('String literal has a backslash after a byte of 0x80 or above, which a multibyte connection charset can read as part of the preceding character');
        }

        return $this->escapePrefix() . self::QUOTE . \str_replace(self::BACKSLASH, self::BACKSLASH . self::BACKSLASH, $doubled) . self::QUOTE;
    }

    private function escapePrefix(): string
    {
        return match ($this) {
            self::Extended => 'E',
            self::Backslash, self::BackslashUtf8, self::Standard => '',
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
}
