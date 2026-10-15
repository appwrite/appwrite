<?php

namespace Utopia\Query\Hook;

use Utopia\Query\Builder\Condition;
use Utopia\Query\Hook;

interface Filter extends Hook
{
    public function filter(string $table): Condition;
}
