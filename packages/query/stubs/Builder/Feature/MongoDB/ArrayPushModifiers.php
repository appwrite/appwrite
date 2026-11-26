<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Builder\Feature\MongoDB;

interface ArrayPushModifiers
{
    /**
     * @param array<mixed> $values
     * @param array<string, int>|null $sort
     */
    public function pushEach(string $field, array $values, ?int $position = null, ?int $slice = null, ?array $sort = null): static;
}
