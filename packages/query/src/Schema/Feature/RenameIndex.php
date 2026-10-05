<?php

namespace Utopia\Query\Schema\Feature;

use Utopia\Query\Builder\Statement;

interface RenameIndex
{
    public function renameIndex(string $table, string $from, string $to): Statement;
}
