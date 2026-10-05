<?php

namespace Appwrite\Databases;

use Appwrite\Extend\Exception;
use Utopia\Database\Attribute;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\BigInt;

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

    public static function from(Document $collection, string $attribute): self
    {
        /** @var array<Document> $attributes */
        $attributes = $collection->getAttribute('attributes', []);
        foreach ($attributes as $declared) {
            if ($declared->getAttribute('key') === $attribute) {
                $type = $declared->getAttribute('type', '');

                return new self(!$declared->getAttribute('array', false) && \is_string($type) && Attribute::isIntegerType($type));
            }
        }

        return new self(false);
    }

    public function maximum(int|float|string|null $max): int|float|string|null
    {
        if (!$this->integer) {
            return $max;
        }

        return match (true) {
            \is_string($max) => self::decimal($max)?->floor() ?? $max,
            \is_float($max) && \is_finite($max) => self::integral(\floor($max)),
            default => $max,
        };
    }

    public function minimum(int|float|string|null $min): int|float|string|null
    {
        if (!$this->integer) {
            return $min;
        }

        return match (true) {
            \is_string($min) => self::decimal($min)?->ceil() ?? $min,
            \is_float($min) && \is_finite($min) => self::integral(\ceil($min)),
            default => $min,
        };
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

    /**
     * @throws Exception
     */
    public function assertChange(int|float|string $value, string $action, string $kind, string $attribute): void
    {
        if (!$this->acceptsChange($value)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Value must be a whole number to ' . $action . ' the integer ' . $kind . ' "' . $attribute . '".');
        }
    }

    public function change(int|float|string $value): int|float|string
    {
        if (!$this->integer || !\is_float($value) || !$this->acceptsChange($value)) {
            return $value;
        }

        return self::integral($value);
    }

    private static function decimal(string $bound): ?Decimal
    {
        return BigInt::isIntegerString($bound) ? null : Decimal::parse($bound);
    }

    private static function integral(float $whole): int|string
    {
        return BigInt::toNative(\sprintf('%.0f', $whole));
    }
}
