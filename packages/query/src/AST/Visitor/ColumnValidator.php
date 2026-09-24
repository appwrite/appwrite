<?php

namespace Utopia\Query\AST\Visitor;

use Utopia\Query\AST\Expression;
use Utopia\Query\AST\Reference\Column;
use Utopia\Query\AST\Reference\Table;
use Utopia\Query\AST\Star;
use Utopia\Query\AST\Statement\Select;
use Utopia\Query\AST\Visitor;
use Utopia\Query\Exception;

class ColumnValidator implements Visitor
{
    /** @param string[] $allowedColumns */
    public function __construct(
        private readonly array $allowedColumns,
        private readonly bool $allowStar = false,
    ) {
    }

    #[\Override]
    public function visitExpression(Expression $expression): Expression
    {
        if ($expression instanceof Column) {
            if (!in_array($expression->name, $this->allowedColumns, true)) {
                throw new Exception("Column '{$expression->name}' is not in the allowed list");
            }
        } elseif ($expression instanceof Star && !$this->allowStar) {
            throw new Exception('Wildcard (*) selection is not allowed; list explicit columns or construct ColumnValidator with allowStar: true');
        }
        return $expression;
    }

    #[\Override]
    public function visitTableReference(Table $reference): Table
    {
        return $reference;
    }

    #[\Override]
    public function visitSelect(Select $stmt): Select
    {
        return $stmt;
    }
}
