<?php

namespace Utopia\Query\Hook\Filter;

use Utopia\Query\Builder\Condition;
use Utopia\Query\Builder\JoinType;
use Utopia\Query\Hook\Filter;
use Utopia\Query\Hook\Join\Condition as JoinCondition;
use Utopia\Query\Hook\Join\Filter as JoinFilter;
use Utopia\Query\Hook\Join\Placement;

class Tenant implements Filter, JoinFilter
{
    /**
     * @param  list<string>  $tenantIds
     */
    public function __construct(
        protected array $tenantIds,
        protected string $column = 'tenant_id',
    ) {
        if (!\preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $column)) {
            throw new \InvalidArgumentException('Invalid column name: ' . $column);
        }
    }

    public function filter(string $table): Condition
    {
        if (empty($this->tenantIds)) {
            return new Condition('1 = 0');
        }

        $placeholders = implode(', ', array_fill(0, count($this->tenantIds), '?'));

        return new Condition(
            "{$this->column} IN ({$placeholders})",
            $this->tenantIds,
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
