<?php

namespace Utopia\Query\Builder;

use Utopia\Query\Exception\ValidationException;

class JoinBuilder
{
    private const ALLOWED_OPERATORS = ['=', '!=', '<', '>', '<=', '>=', '<>'];

    /** @var list<JoinOn> */
    public private(set) array $ons = [];

    /** @var list<Condition> */
    public private(set) array $wheres = [];

    /**
     * Add an ON condition to the join.
     *
     * Note: $left and $right should be raw column identifiers (e.g. "users.id").
     * The parent builder's compileJoinWithBuilder already calls resolveAndWrap on these values.
     */
    public function on(string $left, string $right, string $operator = '='): static
    {
        if (!\preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $left)) {
            throw new ValidationException('Invalid column name: ' . $left);
        }

        if (!\preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $right)) {
            throw new ValidationException('Invalid column name: ' . $right);
        }

        if (!\in_array($operator, self::ALLOWED_OPERATORS, true)) {
            throw new ValidationException('Invalid join operator: ' . $operator);
        }

        $this->ons[] = new JoinOn($left, $operator, $right);

        return $this;
    }

    /**
     * @param  list<mixed>  $bindings
     */
    public function onRaw(string $expression, array $bindings = []): static
    {
        $this->wheres[] = new Condition($expression, $bindings);

        return $this;
    }

    /**
     * Add a WHERE condition to the join.
     *
     * Note: $column is used as-is in the SQL expression. The caller is responsible
     * for ensuring it is a safe, pre-validated column identifier.
     */
    public function where(string $column, string $operator, mixed $value): static
    {
        if (!\preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $column)) {
            throw new ValidationException('Invalid column name: ' . $column);
        }

        if (!\in_array($operator, self::ALLOWED_OPERATORS, true)) {
            throw new ValidationException('Invalid join operator: ' . $operator);
        }

        $this->wheres[] = new Condition($column . ' ' . $operator . ' ?', [$value]);

        return $this;
    }

    /**
     * @param  list<mixed>  $bindings
     */
    public function whereRaw(string $expression, array $bindings = []): static
    {
        $this->wheres[] = new Condition($expression, $bindings);

        return $this;
    }

}
