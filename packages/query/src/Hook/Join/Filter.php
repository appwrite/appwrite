<?php

namespace Utopia\Query\Hook\Join;

use Utopia\Query\Builder\JoinType;
use Utopia\Query\Hook;

interface Filter extends Hook
{
    public function filterJoin(string $table, JoinType $joinType): ?Condition;
}
