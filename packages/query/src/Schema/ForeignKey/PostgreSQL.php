<?php

namespace Utopia\Query\Schema\ForeignKey;

use Utopia\Query\Schema\Column;
use Utopia\Query\Schema\ForeignKey;
use Utopia\Query\Schema\Forwarder;
use Utopia\Query\Schema\Table;

/**
 * @extends ForeignKey<Column\PostgreSQL, Table\PostgreSQL>
 */
class PostgreSQL extends ForeignKey
{
    use Forwarder\PostgreSQL;

    /**
     * @param  list<string>  $columns
     */
    public function primary(array $columns): Table\PostgreSQL
    {
        return $this->table->primary($columns);
    }

    public function check(string $name, string $expression): Table\PostgreSQL
    {
        return $this->table->check($name, $expression);
    }
}
