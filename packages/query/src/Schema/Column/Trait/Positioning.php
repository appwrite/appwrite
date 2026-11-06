<?php

namespace Utopia\Query\Schema\Column\Trait;

/**
 * Positioning a column relative to another via AFTER, which only MySQL and
 * MariaDB accept in ALTER TABLE.
 *
 * PostgreSQL cannot control column order and MongoDB documents have none.
 * SQLite's ALTER TABLE ADD COLUMN has no AFTER clause -- emitting one is a
 * syntax error -- and ClickHouse supports it but the compiler does not emit it.
 */
trait Positioning
{
    public function after(string $column): static
    {
        $this->after = $column;

        return $this;
    }
}
