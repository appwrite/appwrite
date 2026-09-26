<?php

namespace Utopia\Query\AST;

use Utopia\Query\AST\Reference\Table;
use Utopia\Query\AST\Statement\Select;

interface Visitor
{
    // Visit an expression node. Return the same node to keep it, or a new node to replace it.
    public function visitExpression(Expression $expression): Expression;

    // Visit a table reference. Return the same or replacement.
    public function visitTableReference(Table $reference): Table;

    // Visit a Select. Return the same or replacement.
    public function visitSelect(Select $stmt): Select;
}
