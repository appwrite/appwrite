<?php

namespace Utopia\Query\Schema\Trait;

use Utopia\Query\Builder\Statement;

trait Databases
{
    public function createDatabase(string $name): Statement
    {
        return new Statement('CREATE DATABASE ' . $this->quote($name), [], executor: $this->executor);
    }

    public function dropDatabase(string $name): Statement
    {
        return new Statement('DROP DATABASE ' . $this->quote($name), [], executor: $this->executor);
    }
}
