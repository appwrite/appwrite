<?php

namespace Utopia\Query\Builder\Feature\MongoDB;

interface ConditionalArrayUpdates
{
    /**
     * @param array<string, mixed> $condition
     */
    public function arrayFilter(string $identifier, array $condition): static;
}
