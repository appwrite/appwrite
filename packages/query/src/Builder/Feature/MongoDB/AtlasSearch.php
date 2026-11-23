<?php

namespace Utopia\Query\Builder\Feature\MongoDB;

interface AtlasSearch
{
    /**
     * @param array<string, mixed> $searchDefinition
     */
    public function search(array $searchDefinition, ?string $index = null): static;

    /**
     * @param array<string, mixed> $searchDefinition
     */
    public function searchMeta(array $searchDefinition, ?string $index = null): static;

    /**
     * @param array<float> $queryVector
     * @param array<string, mixed>|null $filter
     */
    public function vectorSearch(string $path, array $queryVector, int $numCandidates, int $limit, ?string $index = null, ?array $filter = null): static;
}
