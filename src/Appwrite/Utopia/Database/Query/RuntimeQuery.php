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
        if (!\array_key_exists($attribute, $payload)) {
            return false;
        }
        $payloadAttributeValue = $payload[$attribute];
        switch ($method) {
            case Query::TYPE_EQUAL:
                return self::anyMatch($values, fn ($value) => $payloadAttributeValue === $value);

            case Query::TYPE_NOT_EQUAL:
                return self::anyMatch($values, fn ($value) => $payloadAttributeValue !== $value);

            case Query::TYPE_LESSER:
                return self::anyMatch($values, fn ($value) => $payloadAttributeValue < $value);

            case Query::TYPE_LESSER_EQUAL:
                return self::anyMatch($values, fn ($value) => $payloadAttributeValue <= $value);

            case Query::TYPE_GREATER:
                return self::anyMatch($values, fn ($value) => $payloadAttributeValue > $value);

            case Query::TYPE_GREATER_EQUAL:
                return self::anyMatch($values, fn ($value) => $payloadAttributeValue >= $value);

            case Query::TYPE_IS_NULL:
                return $payloadAttributeValue === null;

            case Query::TYPE_IS_NOT_NULL:
                return $payloadAttributeValue !== null;

            case Query::TYPE_AND:
                foreach ($query->getValues() as $subquery) {
                    // if any evaluation gets to false then whole and is false
                    if (!self::evaluateFilter($subquery, $payload)) {
                        return false;
                    }
                    return true;
                }

                // no break
            case Query::TYPE_OR:
                foreach ($query->getValues() as $subquery) {
                    // if any evaluation gets to true then whole or is true
                    if (self::evaluateFilter($subquery, $payload)) {
                        return true;
                    }
                    return false;
                }

                // no break
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
