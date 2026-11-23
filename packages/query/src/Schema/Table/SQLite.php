<?php

namespace Utopia\Query\Schema\Table;

use Utopia\Query\Schema\Column;
use Utopia\Query\Schema\ColumnType;
use Utopia\Query\Schema\ForeignKey;
use Utopia\Query\Schema\Table;

/**
 * @extends Table<Column\SQLite, ForeignKey\SQLite>
 */
class SQLite extends Table
{
    /** @use Trait\Serial<Column\SQLite> */
    use Trait\Serial;
    use Trait\Checks;
    use Trait\ColumnAlterations;
    use Trait\CompositePrimary;
    /** @use Trait\InlineForeignKey<ForeignKey\SQLite> */
    use Trait\InlineForeignKey;

    #[\Override]
    protected function newColumn(string $name, ColumnType $type, ?int $length = null, ?int $precision = null, ?int $scale = null, ?int $srid = null, ?int $dimensions = null, bool $autoIncrement = false): Column\SQLite
    {
        return new Column\SQLite($this, $name, $type, $length, $precision, $scale, $srid, $dimensions, $autoIncrement);
    }

    #[\Override]
    protected function newForeignKey(string $column): ForeignKey\SQLite
    {
        return new ForeignKey\SQLite($this, $column);
    }
}
