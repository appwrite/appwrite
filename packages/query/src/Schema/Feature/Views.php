<?php

namespace Utopia\Query\Schema\Feature;

use Utopia\Query\Builder;
use Utopia\Query\Builder\Statement;

interface Views
{
    public function createView(string $name, Builder $query): Statement;

    public function dropView(string $name): Statement;
}
