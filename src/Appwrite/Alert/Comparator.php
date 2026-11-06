<?php

namespace Appwrite\Alert;

enum Comparator: string
{
    case GreaterThan = 'greaterThan';
    case GreaterThanEqual = 'greaterThanEqual';
    case LessThan = 'lessThan';
    case LessThanEqual = 'lessThanEqual';

    public function matches(float $value, float $threshold): bool
    {
        return match ($this) {
            self::GreaterThan => $value > $threshold,
            self::GreaterThanEqual => $value >= $threshold,
            self::LessThan => $value < $threshold,
            self::LessThanEqual => $value <= $threshold,
        };
    }

    public function symbol(): string
    {
        return match ($this) {
            self::GreaterThan => '>',
            self::GreaterThanEqual => '≥',
            self::LessThan => '<',
            self::LessThanEqual => '≤',
        };
    }

    /**
     * How the comparison reads in a sentence, as in "is above 5 GB".
     */
    public function phrase(): string
    {
        return match ($this) {
            self::GreaterThan => 'above',
            self::GreaterThanEqual => 'at or above',
            self::LessThan => 'below',
            self::LessThanEqual => 'at or below',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $comparator): string => $comparator->value, self::cases());
    }
}
