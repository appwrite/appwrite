<?php

namespace Utopia\Query\Builder\Feature;

use Utopia\Query\Builder\Statement;

interface UpsertSelect
{
    public function upsertSelect(): Statement;
}
