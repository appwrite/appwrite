<?php

namespace Utopia\Query\Builder\Feature\MongoDB;

interface ArrayPushModifiers
{
    /**
     * @param array<mixed> $values
     * @param array<string, int>|null $sort
     */
    public function pushEach(string $field, array $values, ?int $position = null, ?int $slice = null, ?array $sort = null): static;
}
