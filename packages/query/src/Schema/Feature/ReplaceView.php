<?php

namespace Utopia\Query\Schema\Feature;

use Utopia\Query\Builder;
use Utopia\Query\Builder\Statement;

interface ReplaceView
{
    public function createOrReplaceView(string $name, Builder $query): Statement;
}
