<?php

namespace Utopia\Query\Schema\Feature;

use Utopia\Query\Builder\Statement;

interface Databases
{
    public function createDatabase(string $name): Statement;

    public function dropDatabase(string $name): Statement;
}
