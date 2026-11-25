<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Builder\Feature;

interface Transactions
{
    public function begin(): \Utopia\Query\Builder\Statement;
    public function commit(): \Utopia\Query\Builder\Statement;
    public function rollback(): \Utopia\Query\Builder\Statement;
    public function savepoint(string $name): \Utopia\Query\Builder\Statement;
    public function releaseSavepoint(string $name): \Utopia\Query\Builder\Statement;
    public function rollbackToSavepoint(string $name): \Utopia\Query\Builder\Statement;
}
