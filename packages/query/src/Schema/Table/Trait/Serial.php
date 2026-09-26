<?php

namespace Utopia\Query\Schema\Table\Trait;

use Utopia\Query\Schema\Column;
use Utopia\Query\Schema\ColumnType;

/**
 * Auto-incrementing SERIAL column factories.
 *
 * ClickHouse has no server-generated sequence type, so its Table does not
 * use this trait.
 *
 * @template TColumn of Column
 *
 * @phpstan-require-extends \Utopia\Query\Schema\Table
 */
trait Serial
{
    /**
     * Auto-incrementing integer column (PostgreSQL SERIAL; INT AUTO_INCREMENT
     * on MySQL; INTEGER on SQLite). Not exposed on ClickHouse.
     *
     * @return TColumn
     */
    public function serial(string $name): Column
    {
        $col = $this->newColumn($name, ColumnType::Serial, autoIncrement: true);
        $this->columns[] = $col;

        return $col;
    }

    /**
     * Auto-incrementing big integer column (PostgreSQL BIGSERIAL;
     * BIGINT AUTO_INCREMENT on MySQL; INTEGER on SQLite). Not exposed on
     * ClickHouse/MongoDB.
     *
     * @return TColumn
     */
    public function bigSerial(string $name): Column
    {
        $col = $this->newColumn($name, ColumnType::BigSerial, autoIncrement: true);
        $this->columns[] = $col;

        return $col;
    }

    /**
     * Auto-incrementing small integer column (PostgreSQL SMALLSERIAL;
     * SMALLINT AUTO_INCREMENT on MySQL; INTEGER on SQLite). Not exposed on
     * ClickHouse/MongoDB.
     *
     * @return TColumn
     */
    public function smallSerial(string $name): Column
    {
        $col = $this->newColumn($name, ColumnType::SmallSerial, autoIncrement: true);
        $this->columns[] = $col;

        return $col;
    }
}
