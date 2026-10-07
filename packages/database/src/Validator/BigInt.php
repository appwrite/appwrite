<?php

namespace Utopia\Database\Validator;

use Utopia\Database\Database;
use Utopia\Validator;

class BigInt extends Validator
{
    public const SIGNED_MIN = '-9223372036854775808';
    public const SIGNED_MAX = '9223372036854775807';
    public const UNSIGNED_MAX = '18446744073709551615';

    public function __construct(
        private readonly bool $signed,
        private readonly bool $supportUnsigned64Bit = true
    ) {
    }

    public function getDescription(): string
    {
        if ($this->signed) {
            return 'Value must be a valid signed 64-bit integer between ' .
                self::formatIntegerString(self::SIGNED_MIN) .
                ' and ' . self::formatIntegerString(self::SIGNED_MAX);
        }

        $max = $this->supportUnsigned64Bit ? self::UNSIGNED_MAX : self::SIGNED_MAX;
        return 'Value must be a valid unsigned 64-bit integer between 0 and ' .
            self::formatIntegerString($max);
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return Database::VAR_BIGINT;
    }

    public function isValid(mixed $value): bool
    {
        if (\is_int($value)) {
            return $this->signed ? $value >= \PHP_INT_MIN && $value <= \PHP_INT_MAX : $value >= 0;
        }

        if (!\is_string($value)) {
            return false;
        }

        return self::fitsBigIntRange($value, $this->signed, $this->supportUnsigned64Bit);
    }

    public static function isIntegerString(string $value, bool $signed = true): bool
    {
        return \preg_match($signed ? '/^-?\d+$/' : '/^\d+$/', $value) === 1;
    }

    public static function fitsPhpInt(string $value, bool $signed = true): bool
    {
        if (!self::isIntegerString($value, $signed)) {
            return false;
        }

        $phpMax = (string)\PHP_INT_MAX;
        $phpMinAbs = \ltrim((string)\PHP_INT_MIN, '-');

        if ($signed && \str_starts_with($value, '-')) {
            $digits = self::normalizeUnsignedString(\substr($value, 1));
            return self::compareUnsignedStrings($digits, $phpMinAbs) <= 0;
        }

        $digits = self::normalizeUnsignedString($value);
        return self::compareUnsignedStrings($digits, $phpMax) <= 0;
    }

    public static function fitsBigIntRange(string $value, bool $signed, bool $supportUnsigned64Bit = true): bool
    {
        if (!self::isIntegerString($value, $signed)) {
            return false;
        }

        if ($signed) {
            if (\str_starts_with($value, '-')) {
                $digits = self::normalizeUnsignedString(\substr($value, 1));
                $minAbs = \ltrim(\str_replace('-', '', self::SIGNED_MIN), '0');
                return self::compareUnsignedStrings($digits, $minAbs) <= 0;
            }

            return self::compareUnsignedStrings($value, self::SIGNED_MAX) <= 0;
        }

        $max = $supportUnsigned64Bit ? self::UNSIGNED_MAX : self::SIGNED_MAX;
        return self::compareUnsignedStrings($value, $max) <= 0;
    }

    public static function normalizeUnsignedString(string $value): string
    {
        $value = \trim($value);
        $value = \ltrim($value, '0');
        return $value === '' ? '0' : $value;
    }

    public static function compareUnsignedStrings(string $a, string $b): int
    {
        $a = self::normalizeUnsignedString($a);
        $b = self::normalizeUnsignedString($b);

        $lenA = \strlen($a);
        $lenB = \strlen($b);
        if ($lenA < $lenB) {
            return -1;
        }
        if ($lenA > $lenB) {
            return 1;
        }
        if ($a === $b) {
            return 0;
        }

        return $a < $b ? -1 : 1;
    }

    public static function formatIntegerString(string $value): string
    {
        $negative = \str_starts_with($value, '-');
        if ($negative) {
            $value = \substr($value, 1);
        }

        $value = self::normalizeUnsignedString($value);
        $formatted = \preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $value) ?? $value;

        return $negative ? "-{$formatted}" : $formatted;
    }
}
