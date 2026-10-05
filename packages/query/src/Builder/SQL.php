<?php

namespace Utopia\Query\Builder;

use Utopia\Query\Builder as BaseBuilder;
use Utopia\Query\Builder\Feature\BitwiseAggregates;
use Utopia\Query\Builder\Feature\CrossJoins;
use Utopia\Query\Builder\Feature\Locking;
use Utopia\Query\Builder\Feature\RawSql;
use Utopia\Query\Builder\Feature\StatisticalAggregates;
use Utopia\Query\Builder\Feature\Transactions;
use Utopia\Query\Method;
use Utopia\Query\Query;
use Utopia\Query\QuotesIdentifiers;
use Utopia\Query\Schema\ColumnType;

abstract class SQL extends BaseBuilder implements Locking, Transactions, StatisticalAggregates, BitwiseAggregates, RawSql, CrossJoins
{
    use QuotesIdentifiers;
    use Trait\BitwiseAggregates;
    use Trait\Json;
    use Trait\CrossJoins;
    use Trait\Locking;
    use Trait\RawSql;
    use Trait\StatisticalAggregates;
    use Trait\Transactions;

    /** @var array<string, Condition> */
    protected array $jsonSets = [];

    abstract protected function compileConflictHeader(): string;

    abstract protected function compileConflictAssignment(string $wrapped): string;

    protected function compileConflictClause(): string
    {
        $updates = [];
        foreach ($this->conflictUpdateColumns as $col) {
            $wrapped = $this->resolveAndWrap($col);
            if (isset($this->conflictRawSets[$col])) {
                $updates[] = $wrapped . ' = ' . $this->conflictRawSets[$col];
                foreach ($this->conflictRawSetBindings[$col] ?? [] as $binding) {
                    $this->addBinding($binding);
                }
            } else {
                $updates[] = $wrapped . ' = ' . $this->compileConflictAssignment($wrapped);
            }
        }

        return $this->compileConflictHeader() . ' ' . \implode(', ', $updates);
    }

    #[\Override]
    public function compileFilter(Query $query): string
    {
        $method = $query->getMethod();
        $attribute = $this->resolveAndWrap($query->getAttribute());

        if ($method === Method::Search) {
            return $this->compileSearchExpr($attribute, $query->getValues(), false);
        }

        if ($method === Method::NotSearch) {
            return $this->compileSearchExpr($attribute, $query->getValues(), true);
        }

        if ($method->isSpatial()) {
            return $this->compileSpatialFilter($method, $attribute, $query);
        }

        $attrType = $query->getAttributeType();
        $isSpatialAttr = \in_array($attrType, [ColumnType::Point->value, ColumnType::Linestring->value, ColumnType::Polygon->value], true);
        if ($isSpatialAttr) {
            $spatialMethod = match ($method) {
                Method::Equal => Method::SpatialEquals,
                Method::NotEqual => Method::NotSpatialEquals,
                Method::Contains => Method::Covers,
                Method::NotContains => Method::NotCovers,
                default => null,
            };
            if ($spatialMethod !== null) {
                return $this->compileSpatialFilter($spatialMethod, $attribute, $query);
            }
        }

        if ($method->isJson()) {
            return $this->compileJsonFilter($method, $attribute, $query);
        }

        if ($query->onArray() && \in_array($method, [Method::Contains, Method::ContainsAny, Method::NotContains, Method::ContainsAll], true)) {
            return $this->compileArrayFilter($method, $attribute, $query);
        }

        return parent::compileFilter($query);
    }

    protected function compileArrayFilter(Method $method, string $attribute, Query $query): string
    {
        $values = $query->getValues();

        return match ($method) {
            Method::Contains,
            Method::ContainsAny => $this->compileJsonOverlapsExpr($attribute, [$values]),
            Method::NotContains => 'NOT ' . $this->compileJsonOverlapsExpr($attribute, [$values]),
            Method::ContainsAll => $this->compileJsonContainsExpr($attribute, [$values], false),
            default => parent::compileFilter($query),
        };
    }

    protected function compileSpatialFilter(Method $method, string $attribute, Query $query): string
    {
        $values = $query->getValues();

        return match ($method) {
            Method::DistanceLessThan,
            Method::DistanceGreaterThan,
            Method::DistanceEqual,
            Method::DistanceNotEqual => $this->compileSpatialDistance($method, $attribute, $values),
            Method::Intersects => $this->compileSpatialPredicate('ST_Intersects', $attribute, $values, false),
            Method::NotIntersects => $this->compileSpatialPredicate('ST_Intersects', $attribute, $values, true),
            Method::Crosses => $this->compileSpatialPredicate('ST_Crosses', $attribute, $values, false),
            Method::NotCrosses => $this->compileSpatialPredicate('ST_Crosses', $attribute, $values, true),
            Method::Overlaps => $this->compileSpatialPredicate('ST_Overlaps', $attribute, $values, false),
            Method::NotOverlaps => $this->compileSpatialPredicate('ST_Overlaps', $attribute, $values, true),
            Method::Touches => $this->compileSpatialPredicate('ST_Touches', $attribute, $values, false),
            Method::NotTouches => $this->compileSpatialPredicate('ST_Touches', $attribute, $values, true),
            Method::Covers => $this->compileSpatialCoversPredicate($attribute, $values, false),
            Method::NotCovers => $this->compileSpatialCoversPredicate($attribute, $values, true),
            Method::SpatialEquals => $this->compileSpatialPredicate('ST_Equals', $attribute, $values, false),
            Method::NotSpatialEquals => $this->compileSpatialPredicate('ST_Equals', $attribute, $values, true),
            default => parent::compileFilter($query),
        };
    }

    /**
     * @param  array<mixed>  $values
     */
    abstract protected function compileSpatialDistance(Method $method, string $attribute, array $values): string;

    /**
     * @param  array<mixed>  $values
     */
    abstract protected function compileSpatialPredicate(string $function, string $attribute, array $values, bool $not): string;

    /**
     * Compile covers/not-covers spatial predicate. MySQL uses ST_Contains, PostgreSQL uses ST_Covers.
     *
     * @param  array<mixed>  $values
     */
    abstract protected function compileSpatialCoversPredicate(string $attribute, array $values, bool $not): string;

    /**
     * @param  array<mixed>  $values
     */
    abstract protected function compileSearchExpr(string $attribute, array $values, bool $not): string;

    protected function compileJsonFilter(Method $method, string $attribute, Query $query): string
    {
        $values = $query->getValues();

        return match ($method) {
            Method::JsonContains => $this->compileJsonContainsExpr($attribute, $values, false),
            Method::JsonNotContains => $this->compileJsonContainsExpr($attribute, $values, true),
            Method::JsonOverlaps => $this->compileJsonOverlapsExpr($attribute, $values),
            Method::JsonPath => $this->compileJsonPathExpr($attribute, $values),
            default => parent::compileFilter($query),
        };
    }

    /**
     * @param  array<mixed>  $values
     */
    abstract protected function compileJsonContainsExpr(string $attribute, array $values, bool $not): string;

    /**
     * @param  array<mixed>  $values
     */
    abstract protected function compileJsonOverlapsExpr(string $attribute, array $values): string;

    /**
     * @param  array<mixed>  $values
     */
    abstract protected function compileJsonPathExpr(string $attribute, array $values): string;
}
