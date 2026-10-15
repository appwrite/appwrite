<?php

namespace Utopia\Query\Schema\Forwarder;

use Utopia\Query\Schema\Column;
use Utopia\Query\Schema\ForeignKey;
use Utopia\Query\Schema\Table;

/**
 * Forwarders that delegate SQLite-specific calls back to the parent Table.
 * Used by {@see Column\SQLite} and {@see ForeignKey\SQLite}.
 *
 * Note: SQLite ALTER TABLE does not support FK add/drop, so only the inline
 * `foreignKey()` (used at CREATE time) is forwarded — `addForeignKey()` and
 * `dropForeignKey()` are intentionally omitted.
 */
trait SQLite
{
    public function foreignKey(string $column): ForeignKey\SQLite
    {
        return $this->table->foreignKey($column);
    }
    public function renameColumn(string $from, string $to): Table\SQLite
    {
        return $this->table->renameColumn($from, $to);
    }

    public function dropColumn(string $name): Table\SQLite
    {
        return $this->table->dropColumn($name);
    }
    public function serial(string $name): Column\SQLite
    {
        return $this->table->serial($name);
    }

    public function bigSerial(string $name): Column\SQLite
    {
        return $this->table->bigSerial($name);
    }

    public function smallSerial(string $name): Column\SQLite
    {
        return $this->table->smallSerial($name);
    }
}
