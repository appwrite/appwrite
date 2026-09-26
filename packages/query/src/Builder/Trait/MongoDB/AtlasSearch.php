<?php

namespace Utopia\Query\Builder\Trait\MongoDB;

trait AtlasSearch
{
    /**
     * @param  array<string, mixed>  $searchDefinition
     */
    #[\Override]
    public function search(array $searchDefinition, ?string $index = null): static
    {
        $stage = $searchDefinition;
        if ($index !== null) {
            $stage['index'] = $index;
        }
        $this->searchStage = $stage;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $searchDefinition
     */
    #[\Override]
    public function searchMeta(array $searchDefinition, ?string $index = null): static
    {
        $stage = $searchDefinition;
        if ($index !== null) {
            $stage['index'] = $index;
        }
        $this->searchMetaStage = $stage;

        return $this;
    }

    /**
     * @param  array<float>  $queryVector
     * @param  array<string, mixed>|null  $filter
     */
    #[\Override]
    public function vectorSearch(string $path, array $queryVector, int $numCandidates, int $limit, ?string $index = null, ?array $filter = null): static
    {
        $stage = [
            'path' => $path,
            'queryVector' => $queryVector,
            'numCandidates' => $numCandidates,
            'limit' => $limit,
        ];
        if ($index !== null) {
            $stage['index'] = $index;
        }
        if ($filter !== null) {
            $stage['filter'] = $filter;
        }
        $this->vectorSearchStage = $stage;

        return $this;
    }
}
