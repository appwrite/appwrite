<?php

namespace Utopia\Audit;

use Utopia\Query\Method;
use Utopia\Query\Query as BaseQuery;

/**
 * Audit Query
 *
 * Thin extension of `Utopia\Query\Query` so audit consumers share the
 * canonical query types — including `cursorAfter` / `cursorBefore` and
 * `parse()` validation — while keeping audit's lenient single-value factory
 * signatures (the base requires arrays / scalars only; audit accepts mixed
 * including `DateTime` for the `time` column).
 *
 * Also re-exposes the legacy `TYPE_*` string constants the audit adapter and
 * its tests have always used. The base library moved to a `Method` enum in
 * 0.3.x; the constants here map to the same string values (`equal`,
 * `lessThan`, etc.) so existing call sites keep working.
 */
class Query extends BaseQuery
{
    public const TYPE_EQUAL = 'equal';

    public const TYPE_NOT_EQUAL = 'notEqual';

    public const TYPE_LESSER = 'lessThan';

    public const TYPE_LESSER_EQUAL = 'lessThanEqual';

    public const TYPE_GREATER = 'greaterThan';

    public const TYPE_GREATER_EQUAL = 'greaterThanEqual';

    public const TYPE_BETWEEN = 'between';

    public const TYPE_NOT_BETWEEN = 'notBetween';

    public const TYPE_CONTAINS = 'contains';

    public const TYPE_NOT_CONTAINS = 'notContains';

    public const TYPE_IS_NULL = 'isNull';

    public const TYPE_IS_NOT_NULL = 'isNotNull';

    public const TYPE_STARTS_WITH = 'startsWith';

    public const TYPE_NOT_STARTS_WITH = 'notStartsWith';

    public const TYPE_ENDS_WITH = 'endsWith';

    public const TYPE_NOT_ENDS_WITH = 'notEndsWith';

    public const TYPE_REGEX = 'regex';

    public const TYPE_SELECT = 'select';

    public const TYPE_ORDER_DESC = 'orderDesc';

    public const TYPE_ORDER_ASC = 'orderAsc';

    public const TYPE_ORDER_RANDOM = 'orderRandom';

    public const TYPE_LIMIT = 'limit';

    public const TYPE_OFFSET = 'offset';

    public const TYPE_CURSOR_AFTER = 'cursorAfter';

    public const TYPE_CURSOR_BEFORE = 'cursorBefore';

    /**
     * Construct a query with a string method name (legacy `TYPE_*` constants)
     * or a `Method` enum case (new 0.3.x API).
     *
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
