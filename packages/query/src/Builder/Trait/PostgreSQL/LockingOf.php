<?php

namespace Utopia\Query\Builder\Trait\PostgreSQL;

use Utopia\Query\Builder\LockMode;

trait LockingOf
{
    #[\Override]
    public function forUpdateOf(string $table): static
    {
        $this->lockMode = LockMode::ForUpdate;
        $this->lockOfTable = $table;

        return $this;
    }

    #[\Override]
    public function forShareOf(string $table): static
    {
        $this->lockMode = LockMode::ForShare;
        $this->lockOfTable = $table;

        return $this;
    }
}
