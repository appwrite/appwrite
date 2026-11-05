<?php

namespace Utopia\Query\Builder\Trait\MongoDB;

use Utopia\Query\Exception\ValidationException;

trait PipelineStages
{
    /**
     * @param  array<int|float>  $boundaries
     * @param  array<string, mixed>  $output
     */
    #[\Override]
    public function bucket(string $groupBy, array $boundaries, ?string $defaultBucket = null, array $output = []): static
    {
        $stage = [
            'groupBy' => '$' . $groupBy,
            'boundaries' => $boundaries,
        ];
        if ($defaultBucket !== null) {
            $stage['default'] = $defaultBucket;
        }
        if (! empty($output)) {
            $stage['output'] = $output;
        }
        $this->bucketStage = $stage;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $output
     */
    #[\Override]
    public function bucketAuto(string $groupBy, int $buckets, array $output = []): static
    {
        $stage = [
            'groupBy' => '$' . $groupBy,
            'buckets' => $buckets,
        ];
        if (! empty($output)) {
            $stage['output'] = $output;
        }
        $this->bucketAutoStage = $stage;

        return $this;
    }

    /**
     * @param  array<string, \Utopia\Query\Builder>  $facets
     */
    #[\Override]
    public function facet(array $facets): static
    {
        $this->facetStages = [];
        foreach ($facets as $name => $builder) {
            $result = $builder->build();
            /** @var array<string, mixed>|null $subOp */
            $subOp = \json_decode($result->query, true);
            if ($subOp === null) {
                throw new ValidationException('Cannot parse facet query for MongoDB.');
            }
            $this->facetStages[$name] = [
                'pipeline' => $this->operationToPipeline($subOp),
                'bindings' => $result->bindings,
            ];
        }

        return $this;
    }

    #[\Override]
    public function graphLookup(string $from, string $startWith, string $connectFromField, string $connectToField, string $as, ?int $maxDepth = null, ?string $depthField = null): static
    {
        $stage = [
            'from' => $from,
            'startWith' => '$' . $startWith,
            'connectFromField' => $connectFromField,
            'connectToField' => $connectToField,
            'as' => $as,
        ];
        if ($maxDepth !== null) {
            $stage['maxDepth'] = $maxDepth;
        }
        if ($depthField !== null) {
            $stage['depthField'] = $depthField;
        }
        $this->graphLookupStage = $stage;

        return $this;
    }

    /**
     * @param  array<mixed>|null  $on
     * @param  array<mixed>|null  $whenMatched
     * @param  array<mixed>|null  $whenNotMatched
     */
    #[\Override]
    public function mergeIntoCollection(string $collection, ?array $on = null, ?array $whenMatched = null, ?array $whenNotMatched = null): static
    {
        $stage = ['into' => $collection];
        if ($on !== null) {
            $stage['on'] = $on;
        }
        if ($whenMatched !== null) {
            $stage['whenMatched'] = $whenMatched;
        }
        if ($whenNotMatched !== null) {
            $stage['whenNotMatched'] = $whenNotMatched;
        }
        $this->mergeStage = $stage;

        return $this;
    }

    #[\Override]
    public function outputToCollection(string $collection, ?string $database = null): static
    {
        if ($database !== null) {
            $this->outStage = ['db' => $database, 'coll' => $collection];
        } else {
            $this->outStage = ['coll' => $collection];
        }

        return $this;
    }

    #[\Override]
    public function replaceRoot(string $newRootExpression): static
    {
        $this->replaceRootExpr = $newRootExpression;

        return $this;
    }
}
