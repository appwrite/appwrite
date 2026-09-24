<?php

namespace Utopia\Query\Schema\Column;

use Utopia\Query\Schema\Column;
use Utopia\Query\Schema\Forwarder;
use Utopia\Query\Schema\Table;

/**
 * @extends Column<Table\SQLite>
 */
class SQLite extends Column
{
    use Trait\AutoIncrement;
    use Trait\Collation;
    use Trait\Comment;
    use Trait\Unique;
    use Trait\Generated;
    use Trait\VirtualGenerated;
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
