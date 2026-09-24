<?php

namespace Utopia\Query\Builder\Feature;

interface Locking
{
    public function forUpdate(): static;

    public function forShare(): static;

    public function forUpdateSkipLocked(): static;

    public function forUpdateNoWait(): static;

    public function forShareSkipLocked(): static;

    public function forShareNoWait(): static;
}
