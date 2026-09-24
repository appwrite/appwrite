<?php

namespace Utopia\Query\Schema\Feature;

use Utopia\Query\Builder\Statement;

interface Types
{
    /**
     * @param  list<string>  $values
     */
    public function createType(string $name, array $values): Statement;

    public function dropType(string $name): Statement;
}
