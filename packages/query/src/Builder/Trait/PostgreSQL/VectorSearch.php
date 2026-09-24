<?php

namespace Utopia\Query\Builder\Trait\PostgreSQL;

use Utopia\Query\Builder\VectorMetric;

trait VectorSearch
{
    /**
     * @param  array<float>  $vector
     */
    #[\Override]
    public function orderByVectorDistance(string $attribute, array $vector, VectorMetric $metric = VectorMetric::Cosine): static
    {
        $this->vectorOrder = [
            'attribute' => $attribute,
            'vector' => $vector,
            'metric' => $metric,
        ];

        return $this;
    }
}
