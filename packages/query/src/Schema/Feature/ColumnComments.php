<?php

namespace Utopia\Query\Schema\Feature;

use Utopia\Query\Builder\Statement;

interface ColumnComments
{
    public function commentOnColumn(string $table, string $column, string $comment): Statement;
}
