<?php

namespace Utopia\Query\Schema\Table;

use Utopia\Query\Schema\Column;
use Utopia\Query\Schema\ColumnType;
use Utopia\Query\Schema\ForeignKey;
use Utopia\Query\Schema\Table;

/**
 * @extends Table<Column\PostgreSQL, ForeignKey\PostgreSQL>
 */
class PostgreSQL extends Table
{
    /** @use Trait\Serial<Column\PostgreSQL> */
    use Trait\Serial;
    use Trait\Checks;
    use Trait\ColumnAlterations;
    use Trait\CompositePrimary;
    /** @use Trait\ForeignKeys<ForeignKey\PostgreSQL> */
    use Trait\ForeignKeys;
    use Trait\FulltextSpatialIndex;
    use Trait\StandardPartitioning;

    #[\Override]
    protected function newColumn(string $name, ColumnType $type, ?int $length = null, ?int $precision = null, ?int $scale = null, ?int $srid = null, ?int $dimensions = null, bool $autoIncrement = false): Column\PostgreSQL
    {
        return new Column\PostgreSQL($this, $name, $type, $length, $precision, $scale, $srid, $dimensions, $autoIncrement);
    }

    #[\Override]
    protected function newForeignKey(string $column): ForeignKey\PostgreSQL
    {
        return new ForeignKey\PostgreSQL($this, $column);
    }

    public function vector(string $name, int $dimensions): Column\PostgreSQL
    {
        $col = $this->newColumn($name, ColumnType::Vector, dimensions: $dimensions);
        $this->columns[] = $col;

        return $col;
    }
}
