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
     * Checks if a query is a select("*") query.
     *
     * @param Query $query
     * @return bool
     */
    public static function isSelectAll(Query $query): bool
    {
        if ($query->getMethod() !== Query::TYPE_SELECT) {
            return false;
        }

        return $query->getAttribute() === '*';
    }

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

        if (!self::isSelectAll($query)) {
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
                if ($query->getAttribute() === '*') {
                    return ['type' => 'selectAll'];
                }
            }
        }

        // Compile queries into flat structure
        $compiled = [
            'type' => 'filter',
            'conditions' => [],
            'attributes' => [],
            'hasOr' => false,
        ];

        foreach ($queries as $query) {
            $condition = self::compileCondition($query);
            $compiled['conditions'][] = $condition;
            self::extractAttributes($condition, $compiled['attributes'], $compiled['hasOr']);
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
     * Also tracks whether any OR conditions exist.
     */
    private static function extractAttributes(array $condition, array &$attributes, bool &$hasOr): void
    {
        if (isset($condition['op']) && $condition['op'] === 'OR') {
            $hasOr = true;
        }
        if (isset($condition['attr'])) {
            $attributes[] = $condition['attr'];
        }
        if (isset($condition['conditions'])) {
            foreach ($condition['conditions'] as $sub) {
                self::extractAttributes($sub, $attributes, $hasOr);
            }
        }
    }

    /**
     * Fast filter using pre-compiled query structure.
     *
     * @param array $compiled Result from compile()
     * @param array $payload Event payload
     * @return array|null Null if no match, payload if match
     */
    public static function filter(array $compiled, array $payload): ?array
    {
        // Fast path for select("*") subscriptions
        if ($compiled['type'] === 'selectAll') {
            return $payload;
        }

        // Quick rejection: if payload is missing any required attribute, fail fast
        // Skip this optimization when OR conditions exist (OR can match with partial attributes)
        if (empty($compiled['hasOr'])) {
            foreach ($compiled['attributes'] as $attr) {
                if (!isset($payload[$attr]) && !\array_key_exists($attr, $payload)) {
                    return null;
                }
            }
        }

        // Evaluate all conditions (AND logic at top level)
        foreach ($compiled['conditions'] as $condition) {
            if (!self::evaluateCondition($condition, $payload)) {
                return null;
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

        $value = $payload[$attr];
        $targets = $condition['values'];

        // Inlined comparisons - no closures, no method calls
        switch ($op) {
            case Query::TYPE_EQUAL:
                foreach ($targets as $target) {
                    if ($value === $target) {
                        return true;
                    }
                }
                return false;

            case Query::TYPE_NOT_EQUAL:
                foreach ($targets as $target) {
                    if ($value === $target) {
                        return false;
                    }
                }
                return true;

            case Query::TYPE_LESSER:
                foreach ($targets as $target) {
                    if ($value < $target) {
                        return true;
                    }
                }
                return false;

            case Query::TYPE_LESSER_EQUAL:
                foreach ($targets as $target) {
                    if ($value <= $target) {
                        return true;
                    }
                }
                return false;

            case Query::TYPE_GREATER:
                foreach ($targets as $target) {
                    if ($value > $target) {
                        return true;
                    }
                }
                return false;

            case Query::TYPE_GREATER_EQUAL:
                foreach ($targets as $target) {
                    if ($value >= $target) {
                        return true;
                    }
                }
                return false;

            case Query::TYPE_IS_NULL:
                return $value === null;

            case Query::TYPE_IS_NOT_NULL:
                return $value !== null;

            default:
                return false;
        }
    }
}
