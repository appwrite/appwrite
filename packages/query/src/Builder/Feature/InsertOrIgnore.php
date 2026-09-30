<?php

namespace Utopia\Query\Builder\Feature;

use Utopia\Query\Builder\Statement;

interface InsertOrIgnore
{
    public function insertOrIgnore(): Statement;
}
