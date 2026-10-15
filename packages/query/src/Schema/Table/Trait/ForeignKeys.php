<?php

namespace Utopia\Query\Schema\Table\Trait;

use Utopia\Query\Schema\ForeignKey;

/**
 * Foreign-key ALTER operations. Only dialects whose `ALTER TABLE` supports
 * adding and dropping FK constraints (MySQL, PostgreSQL) should mix this in.
 * SQLite must NOT use this trait — its ALTER TABLE rejects constraint changes.
 *
 * Composes {@see InlineForeignKey} so the using class also gets `foreignKey()`
 * for inline create-time declarations.
 *
 * @template TForeignKey of ForeignKey
 */
trait ForeignKeys
{
    /** @use InlineForeignKey<TForeignKey> */
    use InlineForeignKey;

    /**
     * Alias of {@see foreignKey()}, for symmetry with the other `add*`/`drop*`
     * alter helpers. Returns the same registered {@see ForeignKey}; calling
     * both methods for the same column registers the FK twice.
     *
     * @return TForeignKey
     */
    public function addForeignKey(string $column): ForeignKey
    {
        return $this->foreignKey($column);
    }

    public function dropForeignKey(string $name): static
    {
        $this->dropForeignKeys[] = $name;

        return $this;
    }
}
