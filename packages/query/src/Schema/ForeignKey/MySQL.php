<?php

namespace Utopia\Query\Schema\ForeignKey;

use Utopia\Query\Schema\Column;
use Utopia\Query\Schema\ForeignKey;
use Utopia\Query\Schema\Forwarder;
use Utopia\Query\Schema\Table;

/**
 * @extends ForeignKey<Column\MySQL, Table\MySQL>
 */
class MySQL extends ForeignKey
{
    use Forwarder\MySQL;

    /**
     * Declare a composite primary key on the parent table.
     *
     * @param  list<string>  $columns
     */
    public function primary(array $columns): Table\MySQL
    {
        return $this->table->primary($columns);
    }

    /**
     * Add a named table-level CHECK constraint to the parent table.
     */
    public function check(string $name, string $expression): Table\MySQL
    {
        return $this->table->check($name, $expression);
    }
}
