<?php

namespace Utopia\Query\Builder\Feature;

use Utopia\Query\Builder;
use Utopia\Query\Builder\Statement;

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

    public function insert(): Statement;

    public function insertDefaultValues(): Statement;

    /**
     * @param  list<string>  $columns
     */
    public function fromSelect(array $columns, Builder $source): static;

    public function insertSelect(): Statement;
}
