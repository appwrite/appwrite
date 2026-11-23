<?php

namespace Utopia\Query\Builder\Feature\PostgreSQL;

use Utopia\Query\Builder\VectorMetric;

interface VectorSearch
{
    /**
     * Order results by vector distance (nearest first).
     *
     * @param  array<float>  $vector  The query vector
     */
    public function orderByVectorDistance(string $attribute, array $vector, VectorMetric $metric = VectorMetric::Cosine): static;
}
