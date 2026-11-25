<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Builder\Feature;

interface Deletes
{
    public function from(string $table, string $alias = ''): static;
    public function delete(): \Utopia\Query\Builder\Statement;
}
