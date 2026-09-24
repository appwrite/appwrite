<?php

namespace Tests\Query\Builder\Feature\MongoDB;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\MongoDB as Builder;

class AtlasSearchTest extends TestCase
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

    public function testSearchEmitsSearchStageWithIndex(): void
    {
        $result = (new Builder())
            ->from('articles')
            ->search(['text' => ['query' => 'hello', 'path' => 'body']], 'default')
            ->build();

        $this->assertBindingCount($result);
        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        /** @var array<string, mixed> $searchBody */
        $searchBody = $pipeline[0]['$search'];

        $this->assertSame('default', $searchBody['index']);
        $this->assertSame(['query' => 'hello', 'path' => 'body'], $searchBody['text']);
    }

    public function testSearchWithoutIndexOmitsIndexKey(): void
    {
        $result = (new Builder())
            ->from('articles')
            ->search(['text' => ['query' => 't', 'path' => 't']])
            ->build();

        $this->assertBindingCount($result);
        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        /** @var array<string, mixed> $searchBody */
        $searchBody = $pipeline[0]['$search'];

        $this->assertArrayNotHasKey('index', $searchBody);
    }

    public function testSearchIsFirstStageEvenAfterLaterFilter(): void
    {
        $result = (new Builder())
            ->from('articles')
            ->search(['text' => ['query' => 't', 'path' => 't']])
            ->build();

        $this->assertBindingCount($result);
        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $this->assertArrayHasKey('$search', $pipeline[0]);
    }

    public function testSearchMetaEmitsSearchMetaStage(): void
    {
        $result = (new Builder())
            ->from('articles')
            ->searchMeta(['facet' => []], 'default')
            ->build();

        $this->assertBindingCount($result);
        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $this->assertArrayHasKey('$searchMeta', $pipeline[0]);
    }

    public function testVectorSearchPopulatesAllFields(): void
    {
        $result = (new Builder())
            ->from('products')
            ->vectorSearch('embedding', [0.1, 0.2], 50, 5, 'vi', ['category' => 'x'])
            ->build();

        $this->assertBindingCount($result);
        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        /** @var array<string, mixed> $body */
        $body = $pipeline[0]['$vectorSearch'];

        $this->assertSame('embedding', $body['path']);
        $this->assertSame([0.1, 0.2], $body['queryVector']);
        $this->assertSame(50, $body['numCandidates']);
        $this->assertSame(5, $body['limit']);
        $this->assertSame('vi', $body['index']);
        $this->assertSame(['category' => 'x'], $body['filter']);
    }

    public function testVectorSearchNullableFilterOmitsFilterKey(): void
    {
        $result = (new Builder())
            ->from('products')
            ->vectorSearch('embedding', [0.1], 10, 1)
            ->build();

        $this->assertBindingCount($result);
        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        /** @var array<string, mixed> $body */
        $body = $pipeline[0]['$vectorSearch'];

        $this->assertArrayNotHasKey('filter', $body);
    }
}
