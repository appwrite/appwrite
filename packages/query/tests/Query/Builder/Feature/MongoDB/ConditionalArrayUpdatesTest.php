<?php

namespace Tests\Query\Builder\Feature\MongoDB;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\MongoDB as Builder;
use Utopia\Query\Query;

class ConditionalArrayUpdatesTest extends TestCase
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

    public function testArrayFilterRegistersSingleFilterUnderArrayFiltersOption(): void
    {
        $result = (new Builder())
            ->from('students')
            ->set(['grades.$[elem].mean' => 0])
            ->arrayFilter('elem', ['elem.grade' => ['$gte' => 85]])
            ->filter([Query::equal('_id', ['abc'])])
            ->update();

        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('updateMany', $op['operation']);
        $this->assertArrayHasKey('options', $op);
        /** @var array<string, mixed> $options */
        $options = $op['options'];
        /** @var list<array<string, mixed>> $filters */
        $filters = $options['arrayFilters'];
        $this->assertCount(1, $filters);
        $this->assertSame(['$gte' => 85], $filters[0]['elem.grade']);
    }

    public function testArrayFilterPreservesInsertionOrderAcrossMultipleCalls(): void
    {
        $result = (new Builder())
            ->from('students')
            ->set(['grades.$[elem].adjusted' => true])
            ->arrayFilter('elem', ['elem.grade' => ['$gte' => 85]])
            ->arrayFilter('other', ['other.type' => 'test'])
            ->filter([Query::equal('_id', ['abc'])])
            ->update();

        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $options */
        $options = $op['options'];
        /** @var list<array<string, mixed>> $filters */
        $filters = $options['arrayFilters'];
        $this->assertCount(2, $filters);
        $this->assertArrayHasKey('elem.grade', $filters[0]);
        $this->assertArrayHasKey('other.type', $filters[1]);
    }

    public function testArrayFilterAcceptsComparisonOperators(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->set(['items.$[it].status' => 'shipped'])
            ->arrayFilter('it', ['it.price' => ['$lt' => 100, '$gte' => 10]])
            ->filter([Query::equal('_id', ['abc'])])
            ->update();

        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $options */
        $options = $op['options'];
        /** @var list<array<string, mixed>> $filters */
        $filters = $options['arrayFilters'];
        /** @var array<string, int> $priceFilter */
        $priceFilter = $filters[0]['it.price'];
        $this->assertSame(100, $priceFilter['$lt']);
        $this->assertSame(10, $priceFilter['$gte']);
    }

    public function testArrayFilterWithLogicalOperators(): void
    {
        $result = (new Builder())
            ->from('tasks')
            ->set(['items.$[it].done' => true])
            ->arrayFilter('it', [
                '$or' => [
                    ['it.priority' => 'high'],
                    ['it.deadline' => ['$lt' => 1000]],
                ],
            ])
            ->filter([Query::equal('_id', ['abc'])])
            ->update();

        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $options */
        $options = $op['options'];
        /** @var list<array<string, mixed>> $filters */
        $filters = $options['arrayFilters'];
        $this->assertArrayHasKey('$or', $filters[0]);
    }

    public function testWithoutArrayFilterNoOptionsAreEmitted(): void
    {
        $result = (new Builder())
            ->from('users')
            ->set(['name' => 'Alice'])
            ->filter([Query::equal('_id', ['abc'])])
            ->update();

        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertArrayNotHasKey('options', $op);
    }

    public function testChainableReturnsSameInstance(): void
    {
        $builder = new Builder();
        $returned = $builder->from('students')->arrayFilter('elem', ['elem.x' => 1]);

        $this->assertSame($builder, $returned);
    }
}
