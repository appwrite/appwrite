<?php

namespace Appwrite\Databases;

use Utopia\Database\Validator\BigInt;

enum Rounding
{
    case Down;
    case Up;

    public function round(float $value): float
    {
        return match ($this) {
            self::Down => \floor($value),
            self::Up => \ceil($value),
        };
    }

    /**
     * @param string $truncated The integer a value with a dropped non-zero fraction was truncated towards zero to.
     */
    public function roundTruncated(string $truncated, bool $negative): int|string
    {
        return match ($this) {
            self::Down => $negative ? BigInt::subtract($truncated, 1) : BigInt::toNative($truncated),
            self::Up => $negative ? BigInt::toNative($truncated) : BigInt::add($truncated, 1),
        };
    }
}
