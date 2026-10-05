<?php

namespace Utopia\Query\Builder\Feature;

interface TableSampling
{
    public function tablesample(float $percent, string $method = 'BERNOULLI'): static;
}
