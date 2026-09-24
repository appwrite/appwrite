<?php

namespace Utopia\Query\Schema\Table;

use Utopia\Query\Schema\Column;
use Utopia\Query\Schema\ColumnType;
use Utopia\Query\Schema\ForeignKey;
use Utopia\Query\Schema\Table;
use Utopia\Query\Schema\Table\Trait\Checks;
use Utopia\Query\Schema\Table\Trait\ColumnAlterations;
use Utopia\Query\Schema\Table\Trait\CompositePrimary;
use Utopia\Query\Schema\Table\Trait\InlineForeignKey;
use Utopia\Query\Schema\Table\Trait\Serial;

/**
 * @extends Table<Column\SQLite, ForeignKey\SQLite>
 */
class SQLite extends Table
{
    /** @use Trait\Serial<Column\SQLite> */
    use Serial;
    use Checks;
    use ColumnAlterations;
    use CompositePrimary;
    /** @use Trait\InlineForeignKey<ForeignKey\SQLite> */
    use InlineForeignKey;

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
