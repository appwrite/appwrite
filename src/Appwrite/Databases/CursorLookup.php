<?php

namespace Appwrite\Databases;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Method;
use Utopia\Query\Query as BaseQuery;

/**
 * Resolves the document a list cursor points at.
 *
 * The document itself is read with authorization skipped, so a caller can page past a document it cannot read.
 * An order on a joined attribute takes its value from the joined rows the caller can read, read the way listing
 * that collection directly reads them, so a page boundary never depends on a row the list hides. When several
 * such rows pair with the document, the page after it starts behind the last of them in the list's order and the
 * page before it ends ahead of the first.
 */
final readonly class CursorLookup
{
    private const array MIRRORED_OPERATORS = [
        '<' => '>',
        '<=' => '>=',
        '>' => '<',
        '>=' => '<=',
    ];

    public function __construct(
        private Database $database,
        private Authorization $authorization,
    ) {
    }

    /**
     * @param array<Query> $queries The list's queries, with joins resolved to their collections' tables.
     */
    public function resolve(string $collection, Query $cursor, array $queries): Document
    {
        $document = $this->authorization->skip(
            fn (): Document => $this->database->getDocument($collection, (string) $cursor->getValue())
        );

        if ($document->isEmpty()) {
            return $document;
        }

        $joins = $this->joins($queries);
        $orders = $this->orders($queries, $joins, $cursor->getMethod() === Method::CursorAfter);

        $rows = [];
        foreach ($this->required($joins, $orders) as $alias => $join) {
            $rows[$alias] = $this->row($join, $alias, $document, $rows, $queries, $orders[$alias] ?? []);
        }

        foreach ($orders as $alias => $aliasOrders) {
            foreach ($this->values($rows[$alias], $aliasOrders) as $attribute => $value) {
                $document->setAttribute($alias . '.' . $attribute, $value);
            }
        }

        return $document;
    }

    /**
     * @param array<Query> $queries
     * @return array<string, Query> The aliased joins, in the order the query declares them.
     */
    private function joins(array $queries): array
    {
        $joins = [];
        foreach ($queries as $query) {
            if ($query->getMethod()->isJoin() && $query->getJoinAlias() !== '') {
                $joins[$query->getJoinAlias()] = $query;
            }
        }

        return $joins;
    }

    /**
     * The list's orders on each join alias, as orders on the joined collection that put first the row the cursor
     * stands for: the last one in the list's order for a page after the cursor, the first one for a page before it.
     *
     * @param array<Query> $queries
     * @param array<string, Query> $joins
     * @return array<string, list<Query>>
     */
    private function orders(array $queries, array $joins, bool $after): array
    {
        $orders = [];
        foreach ($queries as $query) {
            $method = $query->getMethod();
            if ($method !== Method::OrderAsc && $method !== Method::OrderDesc) {
                continue;
            }

            [$alias, $attribute] = $this->reference($query->getAttribute(), Query::DEFAULT_ALIAS);
            if (!isset($joins[$alias])) {
                continue;
            }

            $orders[$alias][] = ($method === Method::OrderDesc) !== $after
                ? Query::orderDesc($attribute)
                : Query::orderAsc($attribute);
        }

        return $orders;
    }

    /**
     * The ordered joins and every join their conditions reach through, in declaration order.
     *
     * @param array<string, Query> $joins
     * @param array<string, list<Query>> $orders
     * @return array<string, Query>
     */
    private function required(array $joins, array $orders): array
    {
        $required = \array_fill_keys(\array_keys($orders), true);

        foreach (\array_reverse($joins, true) as $alias => $join) {
            if (!isset($required[$alias])) {
                continue;
            }

            foreach ($this->conditions($join) as [$left, , $right]) {
                foreach ([$this->reference($left, Query::DEFAULT_ALIAS)[0], $this->reference($right, $alias)[0]] as $referenced) {
                    if ($referenced !== $alias && isset($joins[$referenced])) {
                        $required[$referenced] = true;
                    }
                }
            }
        }

        return \array_intersect_key($joins, $required);
    }

    /**
     * The joined row the list pairs with the cursor document, read with the caller's permissions.
     *
     * @param array<string, Document> $rows The rows already resolved for earlier joins.
     * @param array<Query> $queries
     * @param list<Query> $orders
     */
    private function row(Query $join, string $alias, Document $document, array $rows, array $queries, array $orders): Document
    {
        $filters = [];
        foreach ($this->conditions($join) as [$left, $operator, $right]) {
            [$leftAlias, $leftAttribute] = $this->reference($left, Query::DEFAULT_ALIAS);
            [$rightAlias, $rightAttribute] = $this->reference($right, $alias);

            if ($rightAlias === $alias && $leftAlias !== $alias) {
                $filter = $this->comparison($rightAttribute, self::MIRRORED_OPERATORS[$operator] ?? $operator, $this->value($leftAlias, $leftAttribute, $document, $rows));
            } elseif ($leftAlias === $alias && $rightAlias !== $alias) {
                $filter = $this->comparison($leftAttribute, $operator, $this->value($rightAlias, $rightAttribute, $document, $rows));
            } else {
                continue;
            }

            if ($filter === null) {
                return new Document();
            }

            $filters[] = $filter;
        }

        foreach ([...$queries, ...$join->getJoinOnQueries()] as $query) {
            $filter = $this->filter($query, $alias);
            if ($filter !== null) {
                $filters[] = $filter;
            }
        }

        $found = $this->database->skipRelationships(fn (): array => $this->database->find(
            $join->getAttribute(),
            [...$filters, ...$orders, Query::limit(1)],
        ));

        return $found[0] ?? new Document();
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}> Each column condition as left column, operator, right column.
     */
    private function conditions(BaseQuery $join): array
    {
        if ($join->getMethod() === Method::CrossJoin) {
            return [];
        }

        $conditions = [];
        $clauses = $join->isNestedJoin()
            ? \array_filter($join->getJoinOnQueries(), static fn (BaseQuery $on): bool => $on->getMethod() === Method::On)
            : [$join];

        foreach ($clauses as $clause) {
            $values = $clause->getValues();
            if (\count($values) < 3 || !\is_string($values[0]) || !\is_string($values[1]) || !\is_string($values[2])) {
                continue;
            }

            $conditions[] = [$values[0], $values[1], $values[2]];
        }

        return $conditions;
    }

    /**
     * @return array{0: string, 1: string} The alias a column belongs to and its attribute.
     */
    private function reference(string $column, string $alias): array
    {
        $dot = \strpos($column, '.');
        if ($dot === false) {
            return [$alias, $column];
        }

        return [\substr($column, 0, $dot), \substr($column, $dot + 1)];
    }

    /**
     * @param array<string, Document> $rows
     */
    private function value(string $alias, string $attribute, Document $document, array $rows): mixed
    {
        $value = $alias === Query::DEFAULT_ALIAS
            ? $document->getAttribute($attribute)
            : ($rows[$alias] ?? new Document())->getAttribute($attribute);

        return $value instanceof Document ? $value->getId() : $value;
    }

    /**
     * A join condition as a filter on the joined collection, or null when no row can satisfy it.
     */
    private function comparison(string $attribute, string $operator, mixed $value): ?Query
    {
        if (!\is_scalar($value)) {
            return null;
        }

        return match ($operator) {
            '=' => Query::equal($attribute, [$value]),
            '!=', '<>' => Query::notEqual($attribute, $value),
            '<' => Query::lessThan($attribute, $value),
            '<=' => Query::lessThanEqual($attribute, $value),
            '>' => Query::greaterThan($attribute, $value),
            '>=' => Query::greaterThanEqual($attribute, $value),
            default => throw new QueryException('Invalid join operator: ' . $operator),
        };
    }

    /**
     * A filter on the joined collection when the list's filter constrains only this alias, else null.
     */
    private function filter(BaseQuery $query, string $alias): ?BaseQuery
    {
        $method = $query->getMethod();

        if ($method === Method::And || $method === Method::Or) {
            $children = [];
            foreach ($query->getValues() as $child) {
                $filter = $child instanceof BaseQuery ? $this->filter($child, $alias) : null;
                if ($filter === null) {
                    return null;
                }
                $children[] = $filter;
            }

            return $children === [] ? null : (clone $query)->setValues($children);
        }

        if (!$method->isFilter() && !$method->isSpatial() && !$method->isJson() && $method !== Method::ElemMatch && $method !== Method::ContainsAll) {
            return null;
        }

        [$owner, $attribute] = $this->reference($query->getAttribute(), Query::DEFAULT_ALIAS);
        if ($owner !== $alias) {
            return null;
        }

        return (clone $query)->setAttribute($attribute);
    }

    /**
     * The row's order values, which the library encodes as it does the cursor document's own attributes.
     *
     * @param list<Query> $orders
     * @return array<string, mixed>
     */
    private function values(Document $row, array $orders): array
    {
        if ($row->isEmpty()) {
            return [];
        }

        $result = [];
        foreach ($orders as $order) {
            $result[$order->getAttribute()] = $row->getAttribute($order->getAttribute());
        }

        return $result;
    }
}
