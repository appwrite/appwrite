<?php

namespace Tests\Query\Builder\Feature\MongoDB;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\MongoDB as Builder;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Query;

class FieldUpdatesTest extends TestCase
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

    public function testRenameEmitsRenameUpdateOperator(): void
    {
        $result = (new Builder())
            ->from('users')
            ->rename('old', 'new')
            ->filter([Query::equal('_id', ['x'])])
            ->update();

        $this->assertBindingCount($result);
        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];

        $this->assertArrayHasKey('$rename', $update);
        $this->assertSame(['old' => 'new'], $update['$rename']);
    }

    public function testMultiplyEmitsMulOperator(): void
    {
        $result = (new Builder())
            ->from('products')
            ->multiply('price', 1.1)
            ->filter([Query::equal('_id', ['x'])])
            ->update();

        $this->assertBindingCount($result);
        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];

        $this->assertSame(['price' => 1.1], $update['$mul']);
    }

    public function testPopFirstEmitsNegativeOneMarker(): void
    {
        $result = (new Builder())
            ->from('users')
            ->popFirst('tags')
            ->filter([Query::equal('_id', ['x'])])
            ->update();

        $this->assertBindingCount($result);
        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];

        $this->assertSame(['tags' => -1], $update['$pop']);
    }

    public function testPopLastEmitsPositiveOneMarker(): void
    {
        $result = (new Builder())
            ->from('users')
            ->popLast('tags')
            ->filter([Query::equal('_id', ['x'])])
            ->update();

        $this->assertBindingCount($result);
        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];

        $this->assertSame(['tags' => 1], $update['$pop']);
    }

    public function testPullAllBindsEachValueInOrder(): void
    {
        $result = (new Builder())
            ->from('users')
            ->pullAll('scores', [10, 20])
            ->filter([Query::equal('_id', ['x'])])
            ->update();

        $this->assertBindingCount($result);
        // Bindings: pullAll values (10, 20) then _id binding.
        $this->assertContains(10, $result->bindings);
        $this->assertContains(20, $result->bindings);
    }

    public function testUpdateMinEmitsMinOperator(): void
    {
        $result = (new Builder())
            ->from('users')
            ->updateMin('low_score', 50)
            ->filter([Query::equal('_id', ['x'])])
            ->update();

        $this->assertBindingCount($result);
        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];

        $this->assertArrayHasKey('$min', $update);
    }

    public function testCurrentDateWithTimestampTypeEmitsTimestampType(): void
    {
        $result = (new Builder())
            ->from('users')
            ->currentDate('modified', 'timestamp')
            ->filter([Query::equal('_id', ['x'])])
            ->update();

        $this->assertBindingCount($result);
        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];

        $this->assertSame(['modified' => ['$type' => 'timestamp']], $update['$currentDate']);
    }

    public function testRenameRejectsDollarPrefixedField(): void
    {
        $this->expectException(ValidationException::class);
        (new Builder())->from('users')->rename('$danger', 'ok');
    }
}
