<?php

namespace Utopia\Query\Builder\Feature;

use Utopia\Query\Builder\Statement;

interface Deletes
{
    public function from(string $table, string $alias = ''): static;

    public function delete(): Statement;
}
