<?php

namespace Tests\Query\AST;

use Utopia\Query\AST\Expression;
use Utopia\Query\AST\Reference\Table;
use Utopia\Query\AST\Statement\Select;
use Utopia\Query\AST\Visitor;

class CollectingVisitor implements Visitor
{
    /** @var list<string> */
    public array $visited = [];

    public function visitExpression(Expression $expression): Expression
    {
        $class = \get_class($expression);
        $short = \substr($class, \strrpos($class, '\\') + 1);
        $this->visited[] = $short;

        return $expression;
    }

    public function visitTableReference(Table $reference): Table
    {
        return $reference;
    }

    public function visitSelect(Select $stmt): Select
    {
        return $stmt;
    }
}
