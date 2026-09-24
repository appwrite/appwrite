<?php

namespace Utopia\Query\Schema\Feature;

use Utopia\Query\Builder\Statement;
use Utopia\Query\Schema\ParameterDirection;

interface Procedures
{
    /**
     * @param  list<array{0: ParameterDirection, 1: string, 2: string}>  $params
     */
    public function createProcedure(string $name, array $params, string $body): Statement;

    public function dropProcedure(string $name): Statement;
}
