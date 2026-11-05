<?php

namespace Utopia\Query\Schema\Forwarder;

use Utopia\Query\Schema\Column;
use Utopia\Query\Schema\ForeignKey;
use Utopia\Query\Schema\Table;

/**
 * Forwarders that delegate MySQL-specific calls back to the parent Table.
 * Used by {@see Column\MySQL} and {@see ForeignKey\MySQL}.
 */
trait MySQL
{
    public function foreignKey(string $column): ForeignKey\MySQL
    {
        return $this->table->foreignKey($column);
    }

    public function addForeignKey(string $column): ForeignKey\MySQL
    {
        return $this->table->addForeignKey($column);
    }

    public function dropForeignKey(string $name): Table\MySQL
    {
        return $this->table->dropForeignKey($name);
    }

    public function partitionByRange(string $expression): Table\MySQL
    {
        return $this->table->partitionByRange($expression);
    }

    public function partitionByList(string $expression): Table\MySQL
    {
        return $this->table->partitionByList($expression);
    }

    public function partitionByHash(string $expression, ?int $partitions = null): Table\MySQL
    {
        return $this->table->partitionByHash($expression, $partitions);
    }

    /**
     * @param  string[]  $columns
     */
    public function fulltextIndex(array $columns, string $name = ''): Table\MySQL
    {
        return $this->table->fulltextIndex($columns, $name);
    }

    /**
     * @param  string[]  $columns
     */
    public function spatialIndex(array $columns, string $name = ''): Table\MySQL
    {
        return $this->table->spatialIndex($columns, $name);
    }

    public function renameColumn(string $from, string $to): Table\MySQL
    {
        return $this->table->renameColumn($from, $to);
    }

    public function dropColumn(string $name): Table\MySQL
    {
        return $this->table->dropColumn($name);
    }
    public function serial(string $name): Column\MySQL
    {
        return $this->table->serial($name);
    }

    public function bigSerial(string $name): Column\MySQL
    {
        return $this->table->bigSerial($name);
    }

    public function smallSerial(string $name): Column\MySQL
    {
        return $this->table->smallSerial($name);
    }
}
