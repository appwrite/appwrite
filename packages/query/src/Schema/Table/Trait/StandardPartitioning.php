<?php

namespace Utopia\Query\Schema\Table\Trait;

use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Schema\PartitionType;

trait StandardPartitioning
{
    public function partitionByRange(string $expression): static
    {
        $this->partitionType = PartitionType::Range;
        $this->partitionExpression = $expression;
        $this->partitionCount = null;

        return $this;
    }

    public function partitionByList(string $expression): static
    {
        $this->partitionType = PartitionType::List;
        $this->partitionExpression = $expression;
        $this->partitionCount = null;

        return $this;
    }

    /**
     * Partition by hash of the given expression.
     *
     * When $partitions is non-null, the DDL emits `PARTITIONS <count>`. Per
     * MySQL/MariaDB semantics, this only applies to HASH (and KEY/LINEAR HASH/
     * LINEAR KEY variants) partitioning.
     *
     * @throws ValidationException if $partitions is less than 1.
     */
    public function partitionByHash(string $expression, ?int $partitions = null): static
    {
        if ($partitions !== null && $partitions < 1) {
            throw new ValidationException('Partition count must be at least 1.');
        }

        $this->partitionType = PartitionType::Hash;
        $this->partitionExpression = $expression;
        $this->partitionCount = $partitions;

        return $this;
    }
}
