<?php

namespace Utopia\Query\AST\Visitor;

use Utopia\Query\AST\Expression;
use Utopia\Query\AST\Expression\Binary;
use Utopia\Query\AST\Reference\Table;
use Utopia\Query\AST\Statement\Select;
use Utopia\Query\AST\Visitor;

class FilterInjector implements Visitor
{
    public function __construct(private readonly Expression $condition)
    {
    }

    #[\Override]
    public function visitExpression(Expression $expression): Expression
    {
        return $expression;
    }

    #[\Override]
    public function visitTableReference(Table $reference): Table
    {
        return $reference;
    }

    /**
     * Injects the condition into the SELECT's WHERE clause.
     *
     * When used with Walker, this applies to ALL Select nodes including subqueries
     * (bottom-up traversal). For top-level-only injection, call this method directly
     * on the outermost Select instead of using the Walker:
     *
     *     $injector = new FilterInjector($condition);
     *     $result = $injector->visitSelect($stmt);
     */
    #[\Override]
    public function visitSelect(Select $stmt): Select
    {
        if ($stmt->where === null) {
            return $stmt->with(where: $this->condition);
        }

        $combined = new Binary($stmt->where, 'AND', $this->condition);
        return $stmt->with(where: $combined);
    }
}
