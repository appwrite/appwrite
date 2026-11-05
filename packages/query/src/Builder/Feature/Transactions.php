<?php

namespace Utopia\Query\Builder\Feature;

use Utopia\Query\Builder\Statement;

interface Transactions
{
    public function begin(): Statement;

    public function commit(): Statement;

    public function rollback(): Statement;

    public function savepoint(string $name): Statement;

    public function releaseSavepoint(string $name): Statement;

    public function rollbackToSavepoint(string $name): Statement;
}
