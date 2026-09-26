<?php

namespace Utopia\Query\Builder\Feature\MongoDB;

use Utopia\Query\Builder;

interface PipelineStages
{
    /**
     * @param array<int|float> $boundaries
     * @param array<string, array<string, mixed>> $output
     */
    public function bucket(string $groupBy, array $boundaries, ?string $defaultBucket = null, array $output = []): static;

    /**
     * @param array<string, array<string, mixed>> $output
     */
    public function bucketAuto(string $groupBy, int $buckets, array $output = []): static;

    /**
     * @param array<string, Builder> $facets
     */
    public function facet(array $facets): static;

    public function graphLookup(string $from, string $startWith, string $connectFromField, string $connectToField, string $as, ?int $maxDepth = null, ?string $depthField = null): static;

    /**
     * @param array<string>|null $on
     * @param array<mixed>|null $whenMatched
     * @param array<mixed>|null $whenNotMatched
     */
    public function mergeIntoCollection(string $collection, ?array $on = null, ?array $whenMatched = null, ?array $whenNotMatched = null): static;

    public function outputToCollection(string $collection, ?string $database = null): static;

    public function replaceRoot(string $newRootExpression): static;
}
