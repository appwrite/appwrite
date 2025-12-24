<?php

namespace Appwrite\Utopia\Database\Query;

use Utopia\Database\Query;

class RuntimeQuery extends Query
{
    public const ALLOWED_QUERIES = [
        // Equality & comparison
        Query::TYPE_EQUAL,
        Query::TYPE_NOT_EQUAL,
        Query::TYPE_LESSER,
        Query::TYPE_LESSER_EQUAL,
        Query::TYPE_GREATER,
        Query::TYPE_GREATER_EQUAL,

        // Null checks
        Query::TYPE_IS_NULL,
        Query::TYPE_IS_NOT_NULL,

        // Recursive checks
        Query::TYPE_AND,
        Query::TYPE_OR
    ];

    /**
     * @param array<Query> $queries
     * @param array<string, mixed> $payload
     */
    public static function filter(array $queries, array $payload): array
    {
        if (empty($queries)) {
            return $payload;
        }
        foreach ($queries as $query) {
            if (self::evaluateFilter($query, $payload)) {
                return $payload;
            };
        }
        return [];
    }

    private static function evaluateFilter(Query $query, array $payload): bool
    {
        $attribute = $query->getAttribute();
        $method = $query->getMethod();
        $values = $query->getValues();

        // during 'and' and 'or' attribute will not be present
        if (in_array($method, [Query::TYPE_AND, Query::TYPE_OR])) {
            switch ($method) {
                case Query::TYPE_AND:
                    // All subqueries must evaluate to true
                    foreach ($query->getValues() as $subquery) {
                        if (!self::evaluateFilter($subquery, $payload)) {
                            return false;
                        }
                    }
                    return true;

                case Query::TYPE_OR:
                    // At least one subquery must evaluate to true
                    foreach ($query->getValues() as $subquery) {
                        if (self::evaluateFilter($subquery, $payload)) {
                            return true;
                        }
                    }
                    return false;

                default:
                    throw new \InvalidArgumentException(
                        "Unsupported query method: {$method}"
                    );
            }
        }

        $hasAttribute = \array_key_exists($attribute, $payload);
        if (!$hasAttribute) {
            return false;
        }

        // null can be a value as well
        $payloadAttributeValue = $payload[$attribute];
        switch ($method) {
            case Query::TYPE_EQUAL:
                return self::anyMatch($values, fn ($value) => $payloadAttributeValue === $value);

            case Query::TYPE_NOT_EQUAL:
                return !self::anyMatch($values, fn ($value) => $payloadAttributeValue === $value);

            case Query::TYPE_LESSER:
                return self::anyMatch($values, fn ($value) => $payloadAttributeValue < $value);

            case Query::TYPE_LESSER_EQUAL:
                return self::anyMatch($values, fn ($value) => $payloadAttributeValue <= $value);

            case Query::TYPE_GREATER:
                return self::anyMatch($values, fn ($value) => $payloadAttributeValue > $value);

            case Query::TYPE_GREATER_EQUAL:
                return self::anyMatch($values, fn ($value) => $payloadAttributeValue >= $value);

                // attribute must be present and should be explicitly null
            case Query::TYPE_IS_NULL:
                return $payloadAttributeValue === null;

            case Query::TYPE_IS_NOT_NULL:
                return $payloadAttributeValue !== null;

            default:
                throw new \InvalidArgumentException(
                    "Unsupported query method: {$method}"
                );
        }
    }

    private static function anyMatch(array $values, callable $fn): bool
    {
        foreach ($values as $value) {
            if ($fn($value)) {
                return true;
            }
        }
        return false;
    }
}
