<?php

namespace Utopia\Query\Builder;

enum VectorMetric: string
{
    case Cosine = 'cosine';
    case Euclidean = 'euclidean';
    case Dot = 'dot';

    public function toOperator(): string
    {
        return match ($this) {
            self::Cosine => '<=>',
            self::Euclidean => '<->',
            self::Dot => '<#>',
        };
    }
}
