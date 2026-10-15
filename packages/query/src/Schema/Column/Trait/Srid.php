<?php

namespace Utopia\Query\Schema\Column\Trait;

/**
 * Overriding the spatial reference system of a geometry column.
 *
 * Only MySQL, MariaDB and PostgreSQL encode an SRID in the column type. SQLite
 * stores geometry as TEXT, ClickHouse as a Tuple and MongoDB as an object, none
 * of which carry one -- the factories still accept an $srid there so portable
 * code keeps working, but nothing is emitted, so the modifier is absent.
 */
trait Srid
{
    public function srid(int $srid): static
    {
        $this->srid = $srid;

        return $this;
    }
}
