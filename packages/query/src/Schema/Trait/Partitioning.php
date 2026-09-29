<?php

namespace Utopia\Query\Schema\Trait;

use Utopia\Query\Schema\Table;

trait Partitioning
{
    #[\Override]
    protected function compileCreateSuffix(Table $table): string
    {
        return $this->compileCreatePartitioning($table);
    }

    public function compileCreatePartitioning(Table $table): string
    {
        if ($table->partitionType === null) {
            return '';
        }

        $sql = 'PARTITION BY ' . $table->partitionType->value . '(' . $table->partitionExpression . ')';

        if ($table->partitionCount !== null) {
            $sql .= ' PARTITIONS ' . $table->partitionCount;
        }

        return $sql;
    }
}
