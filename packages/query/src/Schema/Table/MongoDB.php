<?php

namespace Utopia\Query\Schema\Table;

use Utopia\Query\Schema\Column;
use Utopia\Query\Schema\ColumnType;
use Utopia\Query\Schema\ForeignKey;
use Utopia\Query\Schema\Table;

/**
 * @extends Table<Column\MongoDB, ForeignKey>
 */
class MongoDB extends Table
{
    /** @use Trait\Serial<Column\MongoDB> */
    use Trait\Serial;
    #[\Override]
    protected function newColumn(string $name, ColumnType $type, ?int $length = null, ?int $precision = null, ?int $scale = null, ?int $srid = null, ?int $dimensions = null, bool $autoIncrement = false): Column\MongoDB
    {
        return new Column\MongoDB($this, $name, $type, $length, $precision, $scale, $srid, $dimensions, $autoIncrement);
    }

    public function vector(string $name): Column\MongoDB
    {
        $col = $this->newColumn($name, ColumnType::Vector);
        $this->columns[] = $col;

        return $col;
    }
}
