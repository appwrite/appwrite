<?php

namespace Utopia\Query\Schema\Feature;

use Utopia\Query\Builder\Statement;

interface CreatePartition
{
    public function createPartition(string $parent, string $name, string $expression): Statement;
}
