<?php

namespace Utopia\Query\Schema\Column;

use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Schema\Column;
use Utopia\Query\Schema\Column\Trait\AutoIncrement;
use Utopia\Query\Schema\Column\Trait\Collation;
use Utopia\Query\Schema\Column\Trait\Dimensions;
use Utopia\Query\Schema\Column\Trait\Generated;
use Utopia\Query\Schema\Column\Trait\Srid;
use Utopia\Query\Schema\Column\Trait\Unique;
use Utopia\Query\Schema\Forwarder;
use Utopia\Query\Schema\Table;

/**
 * @extends Column<Table\PostgreSQL>
 */
class PostgreSQL extends Column
{
    use Srid;
    use Dimensions;
    use AutoIncrement;
    use Collation;
    use Unique;
    use Generated;
    use Forwarder\PostgreSQL;

    /**
     * @param  list<string>  $columns
     *
     * @phpstan-return ($columns is array{} ? static : Table\PostgreSQL)
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
     * @phpstan-return ($expression is null ? static : Table\PostgreSQL)
     */
    public function check(string $expressionOrName, ?string $expression = null): static|Table
    {
        if ($expression === null) {
            $this->checkExpression = $expressionOrName;

            return $this;
        }

        return $this->table->check($expressionOrName, $expression);
    }
    /**
     * Reference a user-defined type (e.g. a PostgreSQL enum type created via CREATE TYPE).
     *
     * @throws ValidationException if $name is not a valid identifier.
     */
    public function userType(string $name): static
    {
        if (! \preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new ValidationException('Invalid user-defined type name: ' . $name);
        }

        $this->userTypeName = $name;

        return $this;
    }
}
