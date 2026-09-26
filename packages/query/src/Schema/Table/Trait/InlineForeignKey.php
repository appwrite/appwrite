<?php

namespace Utopia\Query\Schema\Table\Trait;

use Utopia\Query\Schema\ForeignKey;

/**
 * Inline foreign-key declaration. Suitable for any dialect that supports
 * `FOREIGN KEY (...)` in a CREATE TABLE column list — including SQLite,
 * which does not support adding/dropping FK constraints in ALTER TABLE.
 *
 * @template TForeignKey of ForeignKey
 */
trait InlineForeignKey
{
    /**
     * Declare a foreign key. The dialect compiler emits `FOREIGN KEY (...)`
     * inline in the column list when called during CREATE TABLE building.
     *
     * @return TForeignKey
     */
    public function foreignKey(string $column): ForeignKey
    {
        $fk = $this->newForeignKey($column);
        $this->foreignKeys[] = $fk;

        return $fk;
    }
}
