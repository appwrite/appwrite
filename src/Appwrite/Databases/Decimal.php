<?php

namespace Appwrite\Databases;

use Utopia\Database\Validator\BigInt;

final readonly class Decimal
{
    private const string WHITESPACE = " \t\n\r\v\f";

    private const string DECIMAL_PATTERN = '/^([+-]?)(?=\.?\d)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/';

    private const string SATURATED = BigInt::UNSIGNED_MAX . '0';

    private function __construct(
        private bool $negative,
        private string $whole,
        private bool $fractional,
    ) {
    }

    public static function parse(string $value): ?self
    {
        if (\preg_match(self::DECIMAL_PATTERN, \trim($value, self::WHITESPACE), $parts) !== 1) {
            return null;
        }

        [, $sign, $integer, $fraction, $exponent] = $parts + ['', '', '', '', ''];
        $digits = \ltrim($integer . $fraction, '0');
        if ($digits === '') {
            return new self(false, '0', false);
        }

        $negative = $sign === '-';
        $exponentLimit = \strlen($integer . $fraction) + \strlen(BigInt::UNSIGNED_MAX) + 1;
        $point = \strlen($digits) - \strlen($fraction) + self::exponent($exponent, $exponentLimit);
        if ($point > \strlen(BigInt::UNSIGNED_MAX)) {
            return new self($negative, self::SATURATED, false);
        }

        $whole = $point > 0 ? \str_pad(\substr($digits, 0, $point), $point, '0') : '0';
        $fractional = $point <= 0 || \ltrim(\substr($digits, $point), '0') !== '';

        return new self($negative, $whole, $fractional);
    }

    public function floor(): int|string
    {
        return $this->fractional && $this->negative
            ? BigInt::subtract($this->truncated(), 1)
            : BigInt::toNative($this->truncated());
    }

    public function ceil(): int|string
    {
        return $this->fractional && !$this->negative
            ? BigInt::add($this->truncated(), 1)
            : BigInt::toNative($this->truncated());
    }

    private function truncated(): string
    {
        return ($this->negative ? '-' : '') . $this->whole;
    }

    private static function exponent(string $exponent, int $limit): int
    {
        $digits = \ltrim($exponent, '+-0');
        $magnitude = \strlen($digits) > \strlen((string) $limit) ? $limit : \min((int) $digits, $limit);

        return \str_starts_with($exponent, '-') ? -$magnitude : $magnitude;
    }
}
