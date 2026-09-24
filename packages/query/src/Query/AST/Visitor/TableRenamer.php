<?php

namespace Utopia\Query\AST\Visitor;

use Utopia\Query\AST\Expression;
use Utopia\Query\AST\Reference\Column;
use Utopia\Query\AST\Reference\Table;
use Utopia\Query\AST\Star;
use Utopia\Query\AST\Statement\Select;
use Utopia\Query\AST\Visitor;

class TableRenamer implements Visitor
{
    /** @param array<string, string> $renames map of old name to new name */
    public function __construct(private readonly array $renames)
    {
    }

    #[\Override]
    public function visitExpression(Expression $expression): Expression
    {
        if ($expression instanceof Column && $expression->table !== null) {
            $newTable = $this->renames[$expression->table] ?? null;
            if ($newTable !== null) {
                return new Column($expression->name, $newTable, $expression->schema);
            }
        }

        if ($expression instanceof Star && $expression->table !== null) {
            $newTable = $this->renames[$expression->table] ?? null;
            if ($newTable !== null) {
                return new Star($newTable, $expression->schema);
            }
        }

        return $expression;
    }

    #[\Override]
    public function visitTableReference(Table $reference): Table
    {
        $newName = $this->renames[$reference->name] ?? null;
        $newAlias = $reference->alias !== null ? ($this->renames[$reference->alias] ?? null) : null;

        if ($newName !== null || $newAlias !== null) {
            return new Table(
                $newName ?? $reference->name,
                $newAlias ?? $reference->alias,
                $reference->schema,
            );
        }

        return $reference;
    }

    #[\Override]
    public function visitSelect(Select $stmt): Select
    {
        return $stmt;
    }
}
