<?php

namespace Utopia\Query\Schema\Feature;

use Utopia\Query\Builder\Statement;

interface DropPartition
{
    public function dropPartition(string $table, string $name): Statement;
}
