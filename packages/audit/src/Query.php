<?php

namespace Utopia\Audit;

use Utopia\Query\Method;
use Utopia\Query\Query as BaseQuery;

/**
 * Thin extension of `Utopia\Query\Query` so audit consumers share the
 * canonical query types — including `cursorAfter` / `cursorBefore` and
 * `parse()` validation — while keeping audit's lenient single-value factory
 * signatures (the base requires arrays / scalars only; audit accepts mixed
 * including `DateTime` for the `time` column).
 *
 * Also re-exposes the legacy `TYPE_*` string constants so existing call sites
 * keep working.
 */
class Query extends BaseQuery
{
    public const TYPE_EQUAL = Method::Equal->value;

    public const TYPE_NOT_EQUAL = Method::NotEqual->value;

    public const TYPE_LESSER = Method::LessThan->value;

    public const TYPE_LESSER_EQUAL = Method::LessThanEqual->value;

    public const TYPE_GREATER = Method::GreaterThan->value;

    public const TYPE_GREATER_EQUAL = Method::GreaterThanEqual->value;

    public const TYPE_BETWEEN = Method::Between->value;

    public const TYPE_NOT_BETWEEN = Method::NotBetween->value;

    public const TYPE_CONTAINS = Method::Contains->value;

    public const TYPE_NOT_CONTAINS = Method::NotContains->value;

    public const TYPE_IS_NULL = Method::IsNull->value;

    public const TYPE_IS_NOT_NULL = Method::IsNotNull->value;

    public const TYPE_STARTS_WITH = Method::StartsWith->value;

    public const TYPE_NOT_STARTS_WITH = Method::NotStartsWith->value;

    public const TYPE_ENDS_WITH = Method::EndsWith->value;

    public const TYPE_NOT_ENDS_WITH = Method::NotEndsWith->value;

    public const TYPE_REGEX = Method::Regex->value;

    public const TYPE_SELECT = Method::Select->value;

    public const TYPE_ORDER_DESC = Method::OrderDesc->value;

    public const TYPE_ORDER_ASC = Method::OrderAsc->value;

    public const TYPE_ORDER_RANDOM = Method::OrderRandom->value;

    public const TYPE_LIMIT = Method::Limit->value;

    public const TYPE_OFFSET = Method::Offset->value;

    public const TYPE_CURSOR_AFTER = Method::CursorAfter->value;

    public const TYPE_CURSOR_BEFORE = Method::CursorBefore->value;

    /**
     * @param  array<mixed>  $values
     */
    public function __construct(Method|string $method, string $attribute = '', array $values = [])
    {
        parent::__construct($method, $attribute, $values);
    }

    /**
     * Filter by equal condition.
     *
     * Accepts a single scalar/object/array value and stores it as the values
     * array. Matches the legacy audit signature.
     *
     * @param  mixed  $value  Single value or array of values
     */
    #[\Override]
    public static function equal(string $attribute, mixed $value): static
    {
        /** @var array<mixed> $values */
        $values = \is_array($value) ? $value : [$value];

        return new static(Method::Equal, $attribute, $values);
    }

    /**
     * Filter by less than condition.
     *
     * Accepts mixed (including `DateTime` for the `time` column); the
     * adapter handles type-specific formatting.
     */
    #[\Override]
    public static function lessThan(string $attribute, mixed $value): static
    {
        return new static(Method::LessThan, $attribute, [$value]);
    }

    /**
     * Filter by greater than condition.
     */
    #[\Override]
    public static function greaterThan(string $attribute, mixed $value): static
    {
        return new static(Method::GreaterThan, $attribute, [$value]);
    }

    /**
     * Filter by BETWEEN condition.
     */
    #[\Override]
    public static function between(string $attribute, mixed $start, mixed $end): static
    {
        return new static(Method::Between, $attribute, [$start, $end]);
    }
}
