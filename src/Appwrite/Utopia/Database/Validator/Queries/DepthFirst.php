<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Utopia\Database\Query;
use Utopia\Database\Validator\Queries\Base;

/**
 * Validates queries in the order database 7.4.1 did, so a refused query keeps main's message: one query at a
 * time, each after the queries it nests, and a method 7.4.1 did not know as an invalid method.
 */
class DepthFirst extends Base
{
    /**
     * The methods database 7.4.1 parsed.
     */
    private const array METHODS = [
        'equal',
        'notEqual',
        'lessThan',
        'lessThanEqual',
        'greaterThan',
        'greaterThanEqual',
        'contains',
        'containsAny',
        'notContains',
        'search',
        'notSearch',
        'isNull',
        'isNotNull',
        'between',
        'notBetween',
        'startsWith',
        'notStartsWith',
        'endsWith',
        'notEndsWith',
        'crosses',
        'notCrosses',
        'distanceEqual',
        'distanceNotEqual',
        'distanceGreaterThan',
        'distanceLessThan',
        'intersects',
        'notIntersects',
        'overlaps',
        'notOverlaps',
        'touches',
        'notTouches',
        'vectorDot',
        'vectorCosine',
        'vectorEuclidean',
        'exists',
        'notExists',
        'select',
        'orderDesc',
        'orderAsc',
        'orderRandom',
        'limit',
        'offset',
        'cursorAfter',
        'cursorBefore',
        'and',
        'or',
        'containsAll',
        'elemMatch',
    ];

    #[\Override]
    public function isValid(mixed $value): bool
    {
        if (\is_array($value)) {
            foreach ($value as $query) {
                if (!$this->isValidQuery($query)) {
                    return false;
                }
            }
        }

        return parent::isValid($value);
    }

    private function isValidQuery(mixed $query): bool
    {
        $method = self::method($query);

        if ($method !== null && !\in_array($method, self::METHODS, true)) {
            if (parent::isValid([$query])) {
                return true;
            }

            $this->message = 'Invalid query: Invalid query method: ' . $method;

            return false;
        }

        try {
            $parsed = $query instanceof Query ? $query : Query::parse($query);
        } catch (\Throwable) {
            return parent::isValid([$query]);
        }

        if ($parsed->isNested()) {
            foreach ($parsed->getValues() as $nested) {
                if (!$this->isValidQuery($nested)) {
                    return false;
                }
            }
        }

        return parent::isValid([$parsed]);
    }

    private static function method(mixed $query): ?string
    {
        if ($query instanceof Query) {
            return $query->getMethod()->value;
        }

        if (!\is_string($query)) {
            return null;
        }

        $decoded = \json_decode($query, true);
        $method = \is_array($decoded) ? ($decoded['method'] ?? null) : null;

        return \is_string($method) ? $method : null;
    }
}
