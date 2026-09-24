<?php

namespace Tests\Query\API;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Method;
use Utopia\Query\Query;

class VectorTest extends TestCase
{
    public function testVectorDot(): void
    {
        $vector = [0.1, 0.2, 0.3];
        $query = Query::vectorDot('embedding', $vector);
        $this->assertSame(Method::VectorDot, $query->getMethod());
        $this->assertSame([$vector], $query->getValues());
    }

    public function testVectorCosine(): void
    {
        $vector = [0.1, 0.2, 0.3];
        $query = Query::vectorCosine('embedding', $vector);
        $this->assertSame(Method::VectorCosine, $query->getMethod());
    }

    public function testVectorEuclidean(): void
    {
        $vector = [0.1, 0.2, 0.3];
        $query = Query::vectorEuclidean('embedding', $vector);
        $this->assertSame(Method::VectorEuclidean, $query->getMethod());
    }
}
