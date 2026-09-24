<?php

namespace Utopia\Query\Schema\Table;

use Utopia\Query\Schema\Column;
use Utopia\Query\Schema\ColumnType;
use Utopia\Query\Schema\ForeignKey;
use Utopia\Query\Schema\Table;
use Utopia\Query\Schema\Table\Trait\Checks;
use Utopia\Query\Schema\Table\Trait\ColumnAlterations;
use Utopia\Query\Schema\Table\Trait\CompositePrimary;
use Utopia\Query\Schema\Table\Trait\ForeignKeys;
use Utopia\Query\Schema\Table\Trait\FulltextSpatialIndex;
use Utopia\Query\Schema\Table\Trait\Serial;
use Utopia\Query\Schema\Table\Trait\StandardPartitioning;

/**
 * @extends Table<Column\MySQL, ForeignKey\MySQL>
 */
class MySQL extends Table
{
    /** @use Trait\Serial<Column\MySQL> */
    use Serial;
    use Checks;
    use ColumnAlterations;
    use CompositePrimary;
    /** @use Trait\ForeignKeys<ForeignKey\MySQL> */
    use ForeignKeys;
    use FulltextSpatialIndex;
    use StandardPartitioning;

    #[\Override]
    protected function newColumn(string $name, ColumnType $type, ?int $length = null, ?int $precision = null, ?int $scale = null, ?int $srid = null, ?int $dimensions = null, bool $autoIncrement = false): Column\MySQL
    {
        return new Column\MySQL($this, $name, $type, $length, $precision, $scale, $srid, $dimensions, $autoIncrement);
    }

    #[\Override]
    protected function newForeignKey(string $column): ForeignKey\MySQL
    {
        return new ForeignKey\MySQL($this, $column);
    }
}
