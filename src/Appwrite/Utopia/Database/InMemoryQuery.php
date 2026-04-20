<?php

namespace Appwrite\Utopia\Database;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;

/**
 * Applies filter, order, and pagination queries to an in-memory array of Documents.
 *
 * Intended for list endpoints whose full dataset is materialized in memory (e.g. built
 * from config and project attributes rather than a database collection).
 */
class InMemoryQuery
{
    /**
     * Filter documents using AND-combined query filters.
     *
     * @param array<Document> $documents
     * @param array<Query> $filters
     * @return array<Document>
     */
    public static function filter(array $documents, array $filters): array
    {
        if (empty($filters)) {
            return \array_values($documents);
        }

        return \array_values(\array_filter($documents, function (Document $document) use ($filters) {
            foreach ($filters as $filter) {
                if (!self::matches($document, $filter)) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * Evaluate a single filter query against a document.
     */
    public static function matches(Document $document, Query $filter): bool
    {
        $attribute = $filter->getAttribute();
        $values = $filter->getValues();
        $actual = $document->getAttribute($attribute);
        $needle = (string) ($values[0] ?? '');

        return match ($filter->getMethod()) {
            Query::TYPE_EQUAL => \in_array($actual, $values, false),
            Query::TYPE_NOT_EQUAL => !\in_array($actual, $values, false),
            Query::TYPE_LESSER => self::compareScalar($actual, $values[0] ?? null) < 0,
            Query::TYPE_LESSER_EQUAL => self::compareScalar($actual, $values[0] ?? null) <= 0,
            Query::TYPE_GREATER => self::compareScalar($actual, $values[0] ?? null) > 0,
            Query::TYPE_GREATER_EQUAL => self::compareScalar($actual, $values[0] ?? null) >= 0,
            Query::TYPE_BETWEEN => self::compareScalar($actual, $values[0] ?? null) >= 0 && self::compareScalar($actual, $values[1] ?? null) <= 0,
            Query::TYPE_NOT_BETWEEN => self::compareScalar($actual, $values[0] ?? null) < 0 || self::compareScalar($actual, $values[1] ?? null) > 0,
            Query::TYPE_STARTS_WITH => \is_string($actual) && \str_starts_with($actual, $needle),
            Query::TYPE_NOT_STARTS_WITH => \is_string($actual) && !\str_starts_with($actual, $needle),
            Query::TYPE_ENDS_WITH => \is_string($actual) && \str_ends_with($actual, $needle),
            Query::TYPE_NOT_ENDS_WITH => \is_string($actual) && !\str_ends_with($actual, $needle),
            Query::TYPE_CONTAINS => self::containsValue($actual, $values),
            Query::TYPE_NOT_CONTAINS => !self::containsValue($actual, $values),
            Query::TYPE_SEARCH => \is_string($actual) && $needle !== '' && \stripos($actual, $needle) !== false,
            Query::TYPE_NOT_SEARCH => \is_string($actual) && ($needle === '' || \stripos($actual, $needle) === false),
            Query::TYPE_IS_NULL => $actual === null,
            Query::TYPE_IS_NOT_NULL => $actual !== null,
            default => throw new \InvalidArgumentException('Unsupported query method: ' . $filter->getMethod()),
        };
    }

    /**
     * Sort documents by one or more attributes.
     *
     * @param array<Document> $documents
     * @param array<string> $orderAttributes
     * @param array<string> $orderTypes
     * @return array<Document>
     */
    public static function order(array $documents, array $orderAttributes, array $orderTypes): array
    {
        if (empty($orderAttributes)) {
            return \array_values($documents);
        }

        $documents = \array_values($documents);

        \usort($documents, function (Document $a, Document $b) use ($orderAttributes, $orderTypes) {
            foreach ($orderAttributes as $index => $attribute) {
                $direction = \strtoupper($orderTypes[$index] ?? Database::ORDER_ASC);
                $cmp = self::compareScalar($a->getAttribute($attribute), $b->getAttribute($attribute));
                if ($cmp !== 0) {
                    return $direction === Database::ORDER_DESC ? -$cmp : $cmp;
                }
            }
            return 0;
        });

        return $documents;
    }

    /**
     * Apply limit and offset.
     *
     * @param array<Document> $documents
     * @return array<Document>
     */
    public static function paginate(array $documents, ?int $limit, ?int $offset): array
    {
        return \array_slice(\array_values($documents), $offset ?? 0, $limit);
    }

    /**
     * Compare two scalars in a way that handles null consistently (null sorts before any value).
     */
    private static function compareScalar(mixed $a, mixed $b): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null) {
            return -1;
        }
        if ($b === null) {
            return 1;
        }
        return $a <=> $b;
    }

    /**
     * Check if an attribute contains any of the given values. Handles both array attributes
     * (checks membership) and string attributes (checks substring).
     *
     * @param array<mixed> $values
     */
    private static function containsValue(mixed $actual, array $values): bool
    {
        if (\is_array($actual)) {
            foreach ($values as $value) {
                if (\in_array($value, $actual, false)) {
                    return true;
                }
            }
            return false;
        }

        if (\is_string($actual)) {
            foreach ($values as $value) {
                if (\str_contains($actual, (string) $value)) {
                    return true;
                }
            }
            return false;
        }

        return false;
    }
}
