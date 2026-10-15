<?php

namespace Utopia\Query\Schema\Feature;

use Utopia\Query\Builder\Statement;

interface AnalyzeTable
{
    public function analyzeTable(string $table): Statement;
}
