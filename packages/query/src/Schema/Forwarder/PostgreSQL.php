<?php

namespace Utopia\Query\Schema\Forwarder;

use Utopia\Query\Schema\Column;
use Utopia\Query\Schema\ForeignKey;
use Utopia\Query\Schema\Table;

/**
 * Forwarders that delegate PostgreSQL-specific calls back to the parent Table.
 * Used by {@see Column\PostgreSQL} and {@see ForeignKey\PostgreSQL}.
 */
trait PostgreSQL
{
    public function foreignKey(string $column): ForeignKey\PostgreSQL
    {
        return $this->table->foreignKey($column);
    }

    public function addForeignKey(string $column): ForeignKey\PostgreSQL
    {
        return $this->table->addForeignKey($column);
    }

    public function dropForeignKey(string $name): Table\PostgreSQL
    {
        return $this->table->dropForeignKey($name);
    }

    public function partitionByRange(string $expression): Table\PostgreSQL
    {
        return $this->table->partitionByRange($expression);
    }

    public function partitionByList(string $expression): Table\PostgreSQL
    {
        return $this->table->partitionByList($expression);
    }

    public function partitionByHash(string $expression, ?int $partitions = null): Table\PostgreSQL
    {
        return $this->table->partitionByHash($expression, $partitions);
    }

    /**
     * @param  string[]  $columns
     */
    public function fulltextIndex(array $columns, string $name = ''): Table\PostgreSQL
    {
        return $this->table->fulltextIndex($columns, $name);
    }

    /**
     * @param  string[]  $columns
     */
    public function spatialIndex(array $columns, string $name = ''): Table\PostgreSQL
    {
        return $this->table->spatialIndex($columns, $name);
    }

    public function vector(string $name, int $dimensions): Column\PostgreSQL
    {
        return $this->table->vector($name, $dimensions);
    }

    public function renameColumn(string $from, string $to): Table\PostgreSQL
    {
        return $this->table->renameColumn($from, $to);
    }

    public function dropColumn(string $name): Table\PostgreSQL
    {
        return $this->table->dropColumn($name);
    }
    public function serial(string $name): Column\PostgreSQL
    {
        return $this->table->serial($name);
    }

    public function bigSerial(string $name): Column\PostgreSQL
    {
        return $this->table->bigSerial($name);
    }

    public function smallSerial(string $name): Column\PostgreSQL
    {
        return $this->table->smallSerial($name);
    }
}
