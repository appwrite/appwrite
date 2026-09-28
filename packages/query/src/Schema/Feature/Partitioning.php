<?php

namespace Utopia\Query\Schema\Feature;

use Utopia\Query\Schema\Table;

interface Partitioning
{
    public function compileCreatePartitioning(Table $table): string;
}
