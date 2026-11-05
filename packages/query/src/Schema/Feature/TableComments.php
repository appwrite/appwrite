<?php

namespace Utopia\Query\Schema\Feature;

use Utopia\Query\Builder\Statement;

interface TableComments
{
    public function commentOnTable(string $table, string $comment): Statement;
}
