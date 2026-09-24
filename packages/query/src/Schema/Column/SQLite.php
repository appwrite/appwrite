<?php

namespace Utopia\Query\Schema\Column;

use Utopia\Query\Schema\Column;
use Utopia\Query\Schema\Column\Trait\AutoIncrement;
use Utopia\Query\Schema\Column\Trait\Collation;
use Utopia\Query\Schema\Column\Trait\Comment;
use Utopia\Query\Schema\Column\Trait\Generated;
use Utopia\Query\Schema\Column\Trait\Unique;
use Utopia\Query\Schema\Column\Trait\VirtualGenerated;
use Utopia\Query\Schema\Forwarder;
use Utopia\Query\Schema\Table;

/**
 * @extends Column<Table\SQLite>
 */
class SQLite extends Column
{
    use AutoIncrement;
    use Collation;
    use Comment;
    use Unique;
    use Generated;
    use VirtualGenerated;
    use Forwarder\SQLite;

    /**
     * @param  list<string>  $columns
     *
     * @phpstan-return ($columns is array{} ? static : Table\SQLite)
     */
    public function primary(array $columns = []): static|Table
    {
        if ($columns === []) {
            $this->isPrimary = true;

            return $this;
        }

        return $this->table->primary($columns);
    }

    /**
     * @phpstan-return ($expression is null ? static : Table\SQLite)
     */
    public function check(string $expressionOrName, ?string $expression = null): static|Table
    {
        if ($expression === null) {
            $this->checkExpression = $expressionOrName;

            return $this;
        }

        return $this->table->check($expressionOrName, $expression);
    }
}
