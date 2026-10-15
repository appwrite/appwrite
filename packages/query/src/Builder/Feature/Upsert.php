<?php

namespace Utopia\Query\Builder\Feature;

use Utopia\Query\Builder\Statement;

interface Upsert
{
    public function upsert(): Statement;
}
