<?php

namespace Appwrite\Databases;

use Utopia\Database\Attribute;
use Utopia\Database\Database;
use Utopia\Database\Validator\BigInt;

/**
 * The attribute an increment or a decrement changes, and the bound and change value it is changed with.
 *
 * On an integer attribute a fractional bound admits exactly the integers its whole part towards the allowed side
 * admits, so the maximum is rounded down and the minimum up, and a whole-number change value is passed as the
 * integer it is. Bounds and change values of any other attribute are passed as they are.
 */
final readonly class Counter
{
    public function __construct(private bool $integer)
    {
    }

    public static function of(Database $database, string $collection, string $attribute): self
    {
        $definition = $database->silent(fn () => $database->getCollection($collection));

        foreach ($definition->attributes as $declared) {
            if ($declared->key === $attribute) {
                return new self(!$declared->array && Attribute::isIntegerType($declared->type));
            }
        }

        return new self(false);
    }

    public function maximum(int|float|string|null $max): int|float|string|null
    {
        return $this->bound($max, \floor(...));
    }

    public function minimum(int|float|string|null $min): int|float|string|null
    {
        return $this->bound($min, \ceil(...));
    }

    public function acceptsChange(int|float|string $value): bool
    {
        if (!$this->integer || \is_int($value)) {
            return true;
        }

        if (\is_string($value)) {
            return BigInt::isIntegerString($value);
        }

        return \is_finite($value) && \floor($value) === $value;
    }

    public function change(int|float|string $value): int|float|string
    {
        if (!$this->integer || !\is_float($value) || !$this->acceptsChange($value)) {
            return $value;
        }

        return self::integral($value);
    }

    /**
     * @param callable(float): float $round
     */
    private function bound(int|float|string|null $bound, callable $round): int|float|string|null
    {
        if (!$this->integer || $bound === null || \is_int($bound) || !\is_numeric($bound)) {
            return $bound;
        }

        if (\is_string($bound) && BigInt::isIntegerString($bound)) {
            return $bound;
        }

        $value = (float) $bound;
        if (!\is_finite($value)) {
            return $bound;
        }

        return self::integral($round($value));
    }

    private static function integral(float $whole): int|string
    {
        return BigInt::toNative(\sprintf('%.0f', $whole));
    }
}
