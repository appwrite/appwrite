<?php

namespace Appwrite\Databases;

use Appwrite\Utopia\Database\Attribute as AttributeDefinition;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Validator\BigInt;
use Utopia\Query\Schema\ColumnType;

final readonly class Counter
{
    public function __construct(private bool $integer)
    {
    }

    public static function of(Database $database, string $collection, string $attribute): self
    {
        $definition = $database->silent(fn () => $database->findCollection($collection));

        foreach ($definition?->attributes() ?? [] as $declared) {
            if ($declared->key === $attribute) {
                return new self(!$declared->array && $declared->isInteger());
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
                $columnType = \is_string($type) ? AttributeDefinition::columnType($type) : null;

                return new self(!$declared->getAttribute('array', false) && \in_array($columnType, [ColumnType::Integer, ColumnType::BigInteger], true));
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

    public function change(int|float|string $value): int|float|string
    {
        if (!$this->integer || !\is_float($value) || !$this->acceptsChange($value)) {
            return $value;
        }

        return self::integral($value);
    }

    /**
     * @throws \InvalidArgumentException when the change is not greater than 0
     * @throws LimitException
     * @throws NotFoundException
     */
    public function increase(Database $database, string $collection, string $id, string $attribute, int|float|string $value, int|float|string|null $max): Document
    {
        self::assertPositive($value);

        if ($this->rounds($value)) {
            return $this->round($database, $collection, $id, $attribute, (float) $value, $max, true);
        }

        return $database->increaseDocumentAttribute($collection, $id, $attribute, $this->change($value), $this->maximum($max));
    }

    /**
     * @throws \InvalidArgumentException when the change is not greater than 0
     * @throws LimitException
     * @throws NotFoundException
     */
    public function decrease(Database $database, string $collection, string $id, string $attribute, int|float|string $value, int|float|string|null $min): Document
    {
        self::assertPositive($value);

        if ($this->rounds($value)) {
            return $this->round($database, $collection, $id, $attribute, (float) $value, $min, false);
        }

        return $database->decreaseDocumentAttribute($collection, $id, $attribute, $this->change($value), $this->minimum($min));
    }

    /**
     * A change on an integer column as a whole number: a fractional one rounded half to even, as a fractional
     * counter change is rounded.
     */
    public function whole(int|float|string $value): int|float|string
    {
        if ($this->acceptsChange($value) || !\is_finite((float) $value)) {
            return $this->change($value);
        }

        return self::integral(\round((float) $value, 0, \PHP_ROUND_HALF_EVEN));
    }

    /**
     * A float change on an integer column keeps main's float result in the response.
     */
    private function rounds(int|float|string $value): bool
    {
        return $this->integer && (\is_float($value) || !$this->acceptsChange($value));
    }

    private static function assertPositive(int|float|string $value): void
    {
        if (!\is_numeric($value) || $value <= 0) {
            throw new \InvalidArgumentException('Value must be numeric and greater than 0');
        }
    }

    /**
     * A fractional change on an integer column stores the exact result rounded half to even, as the
     * SQL engines do on assignment, and returns the exact result. The row stays locked between the
     * read and the whole-number change so the rounding sees the value it changes.
     */
    private function round(Database $database, string $collection, string $id, string $attribute, float $value, int|float|string|null $bound, bool $increase): Document
    {
        return $database->withTransaction(function () use ($database, $collection, $id, $attribute, $value, $bound, $increase): Document {
            $current = $database->getAuthorization()->skip(fn () => $database->silent(fn () => $database->getDocument($collection, $id, forUpdate: true)));
            if ($current->isEmpty()) {
                throw new NotFoundException('Document not found');
            }

            $before = $current->getAttribute($attribute) ?? 0;
            $exact = $increase ? $before + $value : $before - $value;
            $whole = self::wholeChange(BigInt::toNative($before), $value, $increase);

            if (BigInt::compare($whole, 0) > 0) {
                $document = $increase
                    ? $database->increaseDocumentAttribute($collection, $id, $attribute, $whole)
                    : $database->decreaseDocumentAttribute($collection, $id, $attribute, $whole);
            } else {
                $database->increaseDocumentAttribute($collection, $id, $attribute, 1);
                $document = $database->decreaseDocumentAttribute($collection, $id, $attribute, 1);
            }

            if ($bound !== null && ($increase ? $exact > (float) $bound : $exact < (float) $bound)) {
                throw new LimitException('Attribute value exceeds ' . ($increase ? 'maximum' : 'minimum') . ' limit: ' . $bound);
            }

            return $document->setAttribute($attribute, $exact);
        });
    }

    /**
     * The whole-number change that stores $before plus or minus $value rounded half to even. $before never passes
     * through a float, which cannot hold every integer above 2^53; only the fraction of $value decides the rounding.
     */
    private static function wholeChange(int|string $before, float $value, bool $increase): int|string
    {
        $whole = self::integral(\floor($value));
        $fraction = $value - \floor($value);
        if ($fraction === 0.0) {
            return $whole;
        }

        if ($increase) {
            $base = BigInt::add($before, $whole);
            $up = $fraction > 0.5 || ($fraction === 0.5 && self::odd($base));

            return $up ? BigInt::add($whole, 1) : $whole;
        }

        $base = BigInt::subtract(BigInt::subtract($before, $whole), 1);
        $up = $fraction < 0.5 || ($fraction === 0.5 && self::odd($base));

        return $up ? $whole : BigInt::add($whole, 1);
    }

    private static function odd(int|string $value): bool
    {
        return (int) \substr((string) $value, -1) % 2 === 1;
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
