<?php

namespace Utopia\Query\Schema\Feature;

use Utopia\Query\Builder\Statement;

interface Sequences
{
    public function createSequence(string $name, int $start = 1, int $incrementBy = 1): Statement;

    public function dropSequence(string $name): Statement;

    public function nextVal(string $name): Statement;
}
