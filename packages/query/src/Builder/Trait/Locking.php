<?php

namespace Utopia\Query\Builder\Trait;

use Utopia\Query\Builder\LockMode;

trait Locking
{
    #[\Override]
    public function forUpdate(): static
    {
        $this->lockMode = LockMode::ForUpdate;

        return $this;
    }

    #[\Override]
    public function forShare(): static
    {
        $this->lockMode = LockMode::ForShare;

        return $this;
    }

    #[\Override]
    public function forUpdateSkipLocked(): static
    {
        $this->lockMode = LockMode::ForUpdateSkipLocked;

        return $this;
    }

    #[\Override]
    public function forUpdateNoWait(): static
    {
        $this->lockMode = LockMode::ForUpdateNoWait;

        return $this;
    }

    #[\Override]
    public function forShareSkipLocked(): static
    {
        $this->lockMode = LockMode::ForShareSkipLocked;

        return $this;
    }

    #[\Override]
    public function forShareNoWait(): static
    {
        $this->lockMode = LockMode::ForShareNoWait;

        return $this;
    }
}
