<?php

namespace Appwrite\Utopia\Database;

use Utopia\Database\Query;

/**
 * RuntimeQuery handles real-time query filtering for Appwrite's Realtime subscriptions.
 *
 * Queries are pre-compiled at subscription time for fast evaluation during message delivery.
 */
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
        Query::TYPE_OR,

        // Special: select("*") means "listen to all events"
        Query::TYPE_SELECT
    ];

    /**
     * Validates a select query - only select("*") is allowed in Realtime
     *
     * @param Query $query
     * @throws \InvalidArgumentException
     */
    public static function validateSelectQuery(Query $query): void
    {
        if ($query->getMethod() !== Query::TYPE_SELECT) {
            return;
        }

        $values = $query->getValues();
        $isSelectAll = count($values) === 1 && $values[0] === '*';

        if (!$isSelectAll) {
            throw new \InvalidArgumentException(
                'Only select("*") is allowed in Realtime queries. select("*") means "listen to all events".'
            );
        }
    }

    /**
     * Pre-compile queries into an optimized format for fast evaluation.
     * Call this once when subscription is created, store the result.
     *
     * @param array<Query> $queries
     * @return array Compiled query structure with 'type' key
     */
    public static function compile(array $queries): array
    {
        if (empty($queries)) {
            return ['type' => 'selectAll'];
        }

        // Check for select("*") upfront
        foreach ($queries as $query) {
            if ($query->getMethod() === Query::TYPE_SELECT) {
                $values = $query->getValues();
                if (count($values) === 1 && $values[0] === '*') {
                    return ['type' => 'selectAll'];
                }
            }
        }

        // Compile queries into flat structure
        $compiled = [
            'type' => 'filter',
            'conditions' => [],
            'attributes' => [],
        ];

        foreach ($queries as $query) {
            $condition = self::compileCondition($query);
            $compiled['conditions'][] = $condition;
            self::extractAttributes($condition, $compiled['attributes']);
        }

        $compiled['attributes'] = array_unique($compiled['attributes']);

        return $compiled;
    }

    /**
     * Compile a single query condition into an optimized array format.
     */
    private static function compileCondition(Query $query): array
    {
        $method = $query->getMethod();

        if ($method === Query::TYPE_AND) {
            return [
                'op' => 'AND',
                'conditions' => array_map([self::class, 'compileCondition'], $query->getValues()),
            ];
        }

        if ($method === Query::TYPE_OR) {
            return [
                'op' => 'OR',
                'conditions' => array_map([self::class, 'compileCondition'], $query->getValues()),
            ];
        }

        return [
            'op' => $method,
            'attr' => $query->getAttribute(),
            'values' => $query->getValues(),
        ];
    }

    /**
     * Extract all attribute names from a compiled condition tree.
     */
    private static function extractAttributes(array $condition, array &$attributes): void
    {
        if (isset($condition['attr'])) {
            $attributes[] = $condition['attr'];
        }
        if (isset($condition['conditions'])) {
            foreach ($condition['conditions'] as $sub) {
                self::extractAttributes($sub, $attributes);
            }
        }
    }

    /**
     * Fast filter using pre-compiled query structure.
     *
     * @param array $compiled Result from compile()
     * @param array $payload Event payload
     * @return array Empty array if no match, payload if match
     */
    public static function filter(array $compiled, array $payload): array
    {
        // Fast path for select("*") subscriptions
        if ($compiled['type'] === 'selectAll') {
            return $payload;
        }

        // Quick rejection: if payload is missing any required attribute, fail fast
        foreach ($compiled['attributes'] as $attr) {
            if (!isset($payload[$attr]) && !\array_key_exists($attr, $payload)) {
                return [];
            }
        }

        // Evaluate all conditions (AND logic at top level)
        foreach ($compiled['conditions'] as $condition) {
            if (!self::evaluateCondition($condition, $payload)) {
                return [];
            }
        }

        return $payload;
    }

    /**
     * Evaluate a single compiled condition against a payload.
     */
    private static function evaluateCondition(array $condition, array $payload): bool
    {
        $op = $condition['op'];

        // Handle AND/OR
        if ($op === 'AND') {
            foreach ($condition['conditions'] as $sub) {
                if (!self::evaluateCondition($sub, $payload)) {
                    return false;
                }
            }
            return true;
        }

        if ($op === 'OR') {
            foreach ($condition['conditions'] as $sub) {
                if (self::evaluateCondition($sub, $payload)) {
                    return true;
                }
            }
            return false;
        }

        // Leaf condition - direct comparison
        $attr = $condition['attr'];

        if (!\array_key_exists($attr, $payload)) {
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
