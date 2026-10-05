<?php

namespace Utopia\Query\Schema\Forwarder;

use Utopia\Query\Schema\ClickHouse\Engine;
use Utopia\Query\Schema\Column;
use Utopia\Query\Schema\ColumnType;
use Utopia\Query\Schema\Table;

/**
 * Forwarders that delegate ClickHouse-specific calls back to the parent Table.
 * Used by {@see Column\ClickHouse}. (ClickHouse has no ForeignKey type.)
 *
 */
trait ClickHouse
{
    public function vector(string $name): Column\ClickHouse
    {
        return $this->table->vector($name);
    }

    public function fixedString(string $name, int $length): Column\ClickHouse
    {
        return $this->table->fixedString($name, $length);
    }

    public function array(string $name, ColumnType $element): Column\ClickHouse
    {
        return $this->table->array($name, $element);
    }

    /**
     * @param  list<ColumnType>  $elements
     */
    public function tuple(string $name, array $elements): Column\ClickHouse
    {
        return $this->table->tuple($name, $elements);
    }

    public function engine(Engine $engine, string ...$args): Table\ClickHouse
    {
        return $this->table->engine($engine, ...$args);
    }

    /**
     * @param  list<string>  $columns
     */
    public function orderBy(array $columns): Table\ClickHouse
    {
        return $this->table->orderBy($columns);
    }

    public function orderByRaw(string $expression): Table\ClickHouse
    {
        return $this->table->orderByRaw($expression);
    }

    /**
     * @param  array<string, string|int|float|bool>  $settings
     */
    public function settings(array $settings): Table\ClickHouse
    {
        return $this->table->settings($settings);
    }

    public function partitionBy(string $expression): Table\ClickHouse
    {
        return $this->table->partitionBy($expression);
    }

    public function sampleBy(string $expression): Table\ClickHouse
    {
        return $this->table->sampleBy($expression);
    }
    public function renameColumn(string $from, string $to): Table\ClickHouse
    {
        return $this->table->renameColumn($from, $to);
    }

    public function dropColumn(string $name): Table\ClickHouse
    {
        return $this->table->dropColumn($name);
    }
}
