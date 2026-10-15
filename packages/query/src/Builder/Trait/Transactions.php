<?php

namespace Utopia\Query\Builder\Trait;

use Utopia\Query\Builder\Statement;

trait Transactions
{
    #[\Override]
    public function begin(): Statement
    {
        return new Statement('BEGIN', [], executor: $this->executor);
    }

    #[\Override]
    public function commit(): Statement
    {
        return new Statement('COMMIT', [], executor: $this->executor);
    }

    #[\Override]
    public function rollback(): Statement
    {
        return new Statement('ROLLBACK', [], executor: $this->executor);
    }

    #[\Override]
    public function savepoint(string $name): Statement
    {
        return new Statement('SAVEPOINT ' . $this->quote($name), [], executor: $this->executor);
    }

    #[\Override]
    public function releaseSavepoint(string $name): Statement
    {
        return new Statement('RELEASE SAVEPOINT ' . $this->quote($name), [], executor: $this->executor);
    }

    #[\Override]
    public function rollbackToSavepoint(string $name): Statement
    {
        return new Statement('ROLLBACK TO SAVEPOINT ' . $this->quote($name), [], executor: $this->executor);
    }
}
