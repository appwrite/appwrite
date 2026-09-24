<?php

namespace Utopia\Query\Schema\Forwarder;

use Utopia\Query\Schema\Column;

/**
 * Forwarders that delegate MongoDB-specific calls back to the parent Table.
 * Used by {@see Column\MongoDB}. (MongoDB has no ForeignKey type.)
 *
 */
trait MongoDB
{
    public function vector(string $name): Column\MongoDB
    {
        return $this->table->vector($name);
    }
    public function serial(string $name): Column\MongoDB
    {
        return $this->table->serial($name);
    }

    public function bigSerial(string $name): Column\MongoDB
    {
        return $this->table->bigSerial($name);
    }

    public function smallSerial(string $name): Column\MongoDB
    {
        return $this->table->smallSerial($name);
    }
}
