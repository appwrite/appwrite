<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Builder\Feature\PostgreSQL;

interface VectorSearch
{
    /**
     * Order results by vector distance (nearest first).
     *
     * @param  array<float>  $vector  The query vector
     */
    public function orderByVectorDistance(string $attribute, array $vector, \Utopia\Query\Builder\VectorMetric $metric = \Utopia\Query\Builder\VectorMetric::Cosine): static;
}
