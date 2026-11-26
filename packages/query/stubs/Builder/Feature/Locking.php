<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

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
