<?php

namespace Tests\Query\Fixture;

use Utopia\Query\Builder\Condition;
use Utopia\Query\Builder\JoinType;
use Utopia\Query\Hook\Filter;
use Utopia\Query\Hook\Join\Condition as JoinCondition;
use Utopia\Query\Hook\Join\Filter as JoinFilter;
use Utopia\Query\Hook\Join\Placement;

class PermissionFilter implements Filter, JoinFilter
{
    private const IDENTIFIER_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_.]*$/';

    /**
     * @param  list<string>  $roles
     * @param  \Closure(string): string  $permissionsTable
     * @param  list<string>|null  $columns
     * @param  Filter|null  $subqueryFilter
     */
    public function __construct(
        protected array $roles,
        protected \Closure $permissionsTable,
        protected string $type = 'read',
        protected ?array $columns = null,
        protected string $documentColumn = 'id',
        protected string $permDocumentColumn = 'document_id',
        protected string $permRoleColumn = 'role',
        protected string $permTypeColumn = 'type',
        protected string $permColumnColumn = 'column',
        protected ?Filter $subqueryFilter = null,
    ) {
        foreach ([$documentColumn, $permDocumentColumn, $permRoleColumn, $permTypeColumn, $permColumnColumn] as $col) {
            if (!\preg_match(self::IDENTIFIER_PATTERN, $col)) {
                throw new \InvalidArgumentException('Invalid column name: ' . $col);
            }
        }
    }

    public function filter(string $table): Condition
    {
        if (empty($this->roles)) {
            return new Condition('1 = 0');
        }

        /** @var string $permTable */
        $permTable = ($this->permissionsTable)($table);

        if (!\preg_match(self::IDENTIFIER_PATTERN, $permTable)) {
            throw new \InvalidArgumentException('Invalid permissions table name: ' . $permTable);
        }

        $rolePlaceholders = \implode(', ', \array_fill(0, \count($this->roles), '?'));

        $columnClause = '';
        $columnBindings = [];

        if ($this->columns !== null) {
            if (empty($this->columns)) {
                $columnClause = " AND {$this->permColumnColumn} IS NULL";
            } else {
                $colPlaceholders = \implode(', ', \array_fill(0, \count($this->columns), '?'));
                $columnClause = " AND ({$this->permColumnColumn} IS NULL OR {$this->permColumnColumn} IN ({$colPlaceholders}))";
                $columnBindings = $this->columns;
            }
        }

        $subFilterClause = '';
        $subFilterBindings = [];
        if ($this->subqueryFilter !== null) {
            $subCondition = $this->subqueryFilter->filter($permTable);
            $subFilterClause = ' AND ' . $subCondition->expression;
            $subFilterBindings = $subCondition->bindings;
        }

        return new Condition(
            "{$this->documentColumn} IN (SELECT DISTINCT {$this->permDocumentColumn} FROM {$permTable} WHERE {$this->permRoleColumn} IN ({$rolePlaceholders}) AND {$this->permTypeColumn} = ?{$columnClause}{$subFilterClause})",
            [...$this->roles, $this->type, ...$columnBindings, ...$subFilterBindings],
        );
    }

    public function filterJoin(string $table, JoinType $joinType): ?JoinCondition
    {
        $condition = $this->filter($table);

        $placement = match ($joinType) {
            JoinType::Left, JoinType::Right => Placement::On,
            default => Placement::Where,
        };

        return new JoinCondition($condition, $placement);
    }
}
