<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Builder\Feature\PostgreSQL;

interface LockingOf
{
    public function forUpdateOf(string $table): static;
    public function forShareOf(string $table): static;
}
