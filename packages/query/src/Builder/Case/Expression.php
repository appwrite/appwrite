<?php

namespace Utopia\Query\Builder\Case;

use Utopia\Query\Exception\ValidationException;

final class Expression
{
    /** @var list<WhenClause> */
    private array $whens = [];

    private bool $hasElse = false;

    private mixed $elseValue = null;

    private string $alias = '';

    /**
     * Add a WHEN <column> <operator> <value> THEN <then> clause.
     *
     * The column is quoted as an identifier per dialect. The operator is a
     * closed enum of the six comparison operators (Operator::Equal,
     * Operator::NotEqual, Operator::LessThan, Operator::LessThanEqual,
     * Operator::GreaterThan, Operator::GreaterThanEqual). $value and $then are
     * bound as parameters.
     */
    public function when(string $column, Operator $operator, mixed $value, mixed $then): static
    {
        $this->whens[] = new WhenClause(
            kind: Kind::Comparison,
            column: $column,
            operator: $operator,
            value: $value,
            then: $then,
        );

        return $this;
    }

    /**
     * Add a WHEN <column> IS NULL THEN <then> clause.
     */
    public function whenNull(string $column, mixed $then): static
    {
        $this->whens[] = new WhenClause(
            kind: Kind::Null,
            column: $column,
            operator: null,
            value: null,
            then: $then,
        );

        return $this;
    }

    /**
     * Add a WHEN <column> IS NOT NULL THEN <then> clause.
     */
    public function whenNotNull(string $column, mixed $then): static
    {
        $this->whens[] = new WhenClause(
            kind: Kind::NotNull,
            column: $column,
            operator: null,
            value: null,
            then: $then,
        );

        return $this;
    }

    /**
     * Add a WHEN <column> IN (?, ?, ...) THEN <then> clause.
     *
     * @param  list<mixed>  $values
     */
    public function whenIn(string $column, array $values, mixed $then): static
    {
        if ($values === []) {
            throw new ValidationException('whenIn() requires at least one value.');
        }

        $this->whens[] = new WhenClause(
            kind: Kind::In,
            column: $column,
            operator: null,
            value: null,
            then: $then,
            values: $values,
        );

        return $this;
    }

    /**
     * Escape hatch for complex predicates. Caller owns the SQL fragment; bindings
     * are bound as-is. The $then value is still bound as a parameter.
     *
     * @param  list<mixed>  $conditionBindings
     */
    public function whenRaw(string $condition, mixed $then, array $conditionBindings = []): static
    {
        $this->whens[] = new WhenClause(
            kind: Kind::Raw,
            column: null,
            operator: null,
            value: null,
            then: $then,
            rawCondition: $condition,
            rawBindings: $conditionBindings,
        );

        return $this;
    }

    /**
     * Set the ELSE value (bound as parameter).
     */
    public function else(mixed $value): static
    {
        $this->hasElse = true;
        $this->elseValue = $value;

        return $this;
    }

    /**
     * Set the alias (builder quotes it per dialect).
     */
    public function alias(string $alias): static
    {
        $this->alias = $alias;

        return $this;
    }

    /**
     * @return list<WhenClause>
     */
    public function getWhens(): array
    {
        return $this->whens;
    }

    public function hasElse(): bool
    {
        return $this->hasElse;
    }

    public function getElse(): mixed
    {
        return $this->elseValue;
    }

    public function getAlias(): string
    {
        return $this->alias;
    }
}
