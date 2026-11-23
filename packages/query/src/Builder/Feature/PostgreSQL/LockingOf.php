<?php

namespace Utopia\Query\Builder\Feature\PostgreSQL;

interface LockingOf
{
    public function forUpdateOf(string $table): static;

    public function forShareOf(string $table): static;
}
