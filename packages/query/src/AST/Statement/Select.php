<?php

namespace Utopia\Query\AST\Statement;

use Utopia\Query\AST\Definition\Cte;
use Utopia\Query\AST\Definition\Window;
use Utopia\Query\AST\Expression;
use Utopia\Query\AST\JoinClause;
use Utopia\Query\AST\OrderByItem;
use Utopia\Query\AST\Reference\Table;
use Utopia\Query\AST\SubquerySource;

readonly class Select
{
    /**
     * @param Expression[] $columns
     * @param JoinClause[] $joins
     * @param Expression[] $groupBy
     * @param OrderByItem[] $orderBy
     * @param Cte[] $ctes
     * @param Window[] $windows
     */
    public function __construct(
        public array $columns = [],
        public Table|SubquerySource|null $from = null,
        public array $joins = [],
        public ?Expression $where = null,
        public array $groupBy = [],
        public ?Expression $having = null,
        public array $orderBy = [],
        public ?Expression $limit = null,
        public ?Expression $offset = null,
        public bool $distinct = false,
        public array $ctes = [],
        public array $windows = [],
    ) {
    }

    /**
     * Create a copy with modified properties.
     *
     * Uses false as default for nullable properties to distinguish
     * "not passed" from "explicitly set to null".
     *
     * @param Expression[]|null $columns
     * @param JoinClause[]|null $joins
     * @param Expression[]|null $groupBy
     * @param OrderByItem[]|null $orderBy
     * @param Cte[]|null $ctes
     * @param Window[]|null $windows
     */
    public function with(
        ?array $columns = null,
        Table|SubquerySource|null|false $from = false,
        ?array $joins = null,
        Expression|null|false $where = false,
        ?array $groupBy = null,
        Expression|null|false $having = false,
        ?array $orderBy = null,
        Expression|null|false $limit = false,
        Expression|null|false $offset = false,
        ?bool $distinct = null,
        ?array $ctes = null,
        ?array $windows = null,
    ): self {
        return new self(
            columns: $columns ?? $this->columns,
            from: $from === false ? $this->from : $from,
            joins: $joins ?? $this->joins,
            where: $where === false ? $this->where : $where,
            groupBy: $groupBy ?? $this->groupBy,
            having: $having === false ? $this->having : $having,
            orderBy: $orderBy ?? $this->orderBy,
            limit: $limit === false ? $this->limit : $limit,
            offset: $offset === false ? $this->offset : $offset,
            distinct: $distinct ?? $this->distinct,
            ctes: $ctes ?? $this->ctes,
            windows: $windows ?? $this->windows,
        );
    }
}
