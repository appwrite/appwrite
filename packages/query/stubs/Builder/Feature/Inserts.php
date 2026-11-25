<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Builder\Feature;

interface Inserts
{
    public function into(string $table): static;
    /**
     * @param  array<string, mixed>  $row
     */
    public function set(array $row): static;
    /**
     * @param  string[]  $keys
     * @param  string[]  $updateColumns
     */
    public function onConflict(array $keys, array $updateColumns): static;
    public function insert(): \Utopia\Query\Builder\Statement;
    public function insertDefaultValues(): \Utopia\Query\Builder\Statement;
    /**
     * @param  list<string>  $columns
     */
    public function fromSelect(array $columns, \Utopia\Query\Builder $source): static;
    public function insertSelect(): \Utopia\Query\Builder\Statement;
}
