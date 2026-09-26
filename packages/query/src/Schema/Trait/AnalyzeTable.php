<?php

namespace Utopia\Query\Schema\Trait;

use Utopia\Query\Builder\Statement;

trait AnalyzeTable
{
    public function analyzeTable(string $table): Statement
    {
        return new Statement('ANALYZE TABLE ' . $this->quote($table), [], executor: $this->executor);
    }
}
