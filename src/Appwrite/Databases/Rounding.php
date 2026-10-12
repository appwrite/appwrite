<?php

namespace Appwrite\Databases;

use Utopia\Database\Validator\BigInt;

/**
 * How an engine stores a fractional result in an integer column.
 */
enum Rounding
{
    case HalfEven;
    case HalfAwayFromZero;
    case TowardZero;

    /**
     * The whole number stored for $base + $fraction, where $fraction is in [0, 1). $base never passes through a
     * float, which cannot hold every integer above 2^53.
     */
    public function apply(int|string $base, float $fraction): int|string
    {
        if ($fraction === 0.0) {
            return $base;
        }

        $up = match ($this) {
            self::HalfEven => $fraction > 0.5 || ($fraction === 0.5 && self::odd($base)),
            self::HalfAwayFromZero => $fraction > 0.5 || ($fraction === 0.5 && BigInt::compare($base, 0) >= 0),
            self::TowardZero => BigInt::compare($base, 0) < 0,
        };

        return $up ? BigInt::add($base, 1) : $base;
    }

    /**
     * The whole number stored for a fractional result computed in floating point.
     */
    public function round(float $value): int|string
    {
        $floor = \floor($value);

        return $this->apply(BigInt::toNative(\sprintf('%.0f', $floor)), $value - $floor);
    }

    /**
     * The whole number stored for $numerator / $denominator, computed exactly; $denominator is positive.
     */
    public function ratio(int|string $numerator, int|string $denominator): int|string
    {
        $quotient = BigInt::divide($numerator, $denominator);
        $remainder = BigInt::modulo($numerator, $denominator);
        if (BigInt::compare($remainder, 0) === 0 || $this === self::TowardZero) {
            return $quotient;
        }

        $half = BigInt::compare(BigInt::multiply(\ltrim((string) $remainder, '-'), 2), $denominator);
        $away = $half > 0 || ($half === 0 && ($this === self::HalfAwayFromZero || self::odd($quotient)));
        if (!$away) {
            return $quotient;
        }

        return BigInt::compare($numerator, 0) < 0 ? BigInt::subtract($quotient, 1) : BigInt::add($quotient, 1);
    }

    private static function odd(int|string $value): bool
    {
        return (int) \substr((string) $value, -1) % 2 === 1;
    }
}
