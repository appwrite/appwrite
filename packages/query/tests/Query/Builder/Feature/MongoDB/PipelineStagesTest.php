<?php

namespace Tests\Query\Builder\Feature\MongoDB;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\MongoDB as Builder;

class PipelineStagesTest extends TestCase
{
    use AssertsBindingCount;

    /**
     * @return array<string, mixed>
     */
    private function decode(string $query): array
    {
        /** @var array<string, mixed> $op */
        $op = \json_decode($query, true, flags: JSON_THROW_ON_ERROR);

        return $op;
    }

    /**
     * @param  list<array<string, mixed>>  $pipeline
     * @return array<string, mixed>|null
     */
    private function findStage(array $pipeline, string $name): ?array
    {
        foreach ($pipeline as $stage) {
            if (\array_key_exists($name, $stage)) {
                return $stage;
            }
        }

        return null;
    }

    public function testBucketEmitsBucketStageWithGroupByAndBoundaries(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->bucket('price', [0, 100, 200], 'Other', ['count' => ['$sum' => 1]])
            ->build();

        $this->assertBindingCount($result);
        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $stage = $this->findStage($pipeline, '$bucket');

        $this->assertNotNull($stage);
        /** @var array<string, mixed> $body */
        $body = $stage['$bucket'];
        $this->assertSame('$price', $body['groupBy']);
        $this->assertSame([0, 100, 200], $body['boundaries']);
        $this->assertSame('Other', $body['default']);
    }

    public function testBucketWithoutDefaultOrOutputOmitsKeys(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->bucket('amount', [0, 50])
            ->build();

        $this->assertBindingCount($result);
        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $stage = $this->findStage($pipeline, '$bucket');
        $this->assertNotNull($stage);
        /** @var array<string, mixed> $body */
        $body = $stage['$bucket'];

        $this->assertArrayNotHasKey('default', $body);
        $this->assertArrayNotHasKey('output', $body);
    }

    public function testBucketAutoEmitsBucketAutoWithBucketCount(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->bucketAuto('price', 5)
            ->build();

        $this->assertBindingCount($result);
        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $stage = $this->findStage($pipeline, '$bucketAuto');

        $this->assertNotNull($stage);
        /** @var array<string, mixed> $body */
        $body = $stage['$bucketAuto'];
        $this->assertSame(5, $body['buckets']);
    }

    public function testFacetEmitsFacetStageWithSubPipelines(): void
    {
        $facetA = (new Builder())->from('events');
        $facetB = (new Builder())->from('events');

        $result = (new Builder())
            ->from('events')
            ->facet(['a' => $facetA, 'b' => $facetB])
            ->build();

        $this->assertBindingCount($result);
        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $stage = $this->findStage($pipeline, '$facet');

        $this->assertNotNull($stage);
        /** @var array<string, mixed> $body */
        $body = $stage['$facet'];
        $this->assertArrayHasKey('a', $body);
        $this->assertArrayHasKey('b', $body);
    }

    public function testGraphLookupEmitsGraphLookupStage(): void
    {
        $result = (new Builder())
            ->from('users')
            ->graphLookup('users', '$manager', 'manager', '_id', 'chain')
            ->build();

        $this->assertBindingCount($result);
        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $stage = $this->findStage($pipeline, '$graphLookup');

        $this->assertNotNull($stage);
        /** @var array<string, mixed> $body */
        $body = $stage['$graphLookup'];
        $this->assertSame('users', $body['from']);
        $this->assertSame('manager', $body['connectFromField']);
    }

    public function testOutputToCollectionEmitsOutStage(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->outputToCollection('archive')
            ->build();

        $this->assertBindingCount($result);
        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $stage = $this->findStage($pipeline, '$out');

        $this->assertNotNull($stage);
    }

    public function testReplaceRootEmitsReplaceRootStage(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->replaceRoot('$user')
            ->build();

        $this->assertBindingCount($result);
        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $stage = $this->findStage($pipeline, '$replaceRoot');

        $this->assertNotNull($stage);
    }
}
