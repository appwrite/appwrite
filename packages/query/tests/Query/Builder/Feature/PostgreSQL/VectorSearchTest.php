<?php

namespace Tests\Query\Builder\Feature\PostgreSQL;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\PostgreSQL as Builder;
use Utopia\Query\Builder\VectorMetric;

class VectorSearchTest extends TestCase
{
    use AssertsBindingCount;

    public function testOrderByVectorDistanceCosineUsesCosineOperator(): void
    {
        $result = (new Builder())
            ->from('items')
            ->orderByVectorDistance('embedding', [0.1, 0.2, 0.3], VectorMetric::Cosine)
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM "items" ORDER BY ("embedding" <=> ?::vector) ASC', $result->query);
    }

    public function testOrderByVectorDistanceEuclideanUsesL2Operator(): void
    {
        $result = (new Builder())
            ->from('items')
            ->orderByVectorDistance('embedding', [1.0, 2.0], VectorMetric::Euclidean)
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM "items" ORDER BY ("embedding" <-> ?::vector) ASC', $result->query);
    }

    public function testOrderByVectorDistanceDotUsesInnerProductOperator(): void
    {
        $result = (new Builder())
            ->from('items')
            ->orderByVectorDistance('embedding', [1.0, 2.0], VectorMetric::Dot)
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM "items" ORDER BY ("embedding" <#> ?::vector) ASC', $result->query);
    }

    public function testOrderByVectorDistanceSerializesVectorAsPgvectorLiteral(): void
    {
        $result = (new Builder())
            ->from('items')
            ->orderByVectorDistance('embedding', [0.1, 0.2, 0.3], VectorMetric::Cosine)
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('[0.1,0.2,0.3]', $result->bindings[0]);
    }

    public function testOrderByVectorDistanceEmptyVectorStillBindsValue(): void
    {
        $result = (new Builder())
            ->from('items')
            ->orderByVectorDistance('embedding', [], VectorMetric::Cosine)
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('[]', $result->bindings[0]);
    }

    public function testOrderByVectorDistanceQuotesAttributeIdentifier(): void
    {
        $result = (new Builder())
            ->from('items')
            ->orderByVectorDistance('embedding', [1.0], VectorMetric::Cosine)
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM "items" ORDER BY ("embedding" <=> ?::vector) ASC', $result->query);
    }
}
