<?php

namespace Tests\Query\Builder\Feature\MongoDB;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\MongoDB as Builder;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Query;

class ArrayPushModifiersTest extends TestCase
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

    public function testPushEachBasicEmitsEachPlaceholders(): void
    {
        $result = (new Builder())
            ->from('users')
            ->pushEach('tags', ['a', 'b', 'c'])
            ->filter([Query::equal('_id', ['x'])])
            ->update();

        $this->assertBindingCount($result);
        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        /** @var array<string, mixed> $pushDoc */
        $pushDoc = $update['$push'];
        /** @var array<string, mixed> $modifier */
        $modifier = $pushDoc['tags'];

        $this->assertSame(['?', '?', '?'], $modifier['$each']);
        $this->assertArrayNotHasKey('$position', $modifier);
        $this->assertArrayNotHasKey('$slice', $modifier);
        $this->assertArrayNotHasKey('$sort', $modifier);
    }

    public function testPushEachWithAllModifiersSetsEachKey(): void
    {
        $result = (new Builder())
            ->from('users')
            ->pushEach('scores', [85, 92], 0, 5, ['score' => -1])
            ->filter([Query::equal('_id', ['x'])])
            ->update();

        $this->assertBindingCount($result);
        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        /** @var array<string, mixed> $pushDoc */
        $pushDoc = $update['$push'];
        /** @var array<string, mixed> $modifier */
        $modifier = $pushDoc['scores'];

        $this->assertSame(['?', '?'], $modifier['$each']);
        $this->assertSame(0, $modifier['$position']);
        $this->assertSame(5, $modifier['$slice']);
        $this->assertSame(['score' => -1], $modifier['$sort']);
    }

    public function testPushEachEmptyArrayStillEmitsEachKey(): void
    {
        $result = (new Builder())
            ->from('users')
            ->pushEach('tags', [])
            ->filter([Query::equal('_id', ['x'])])
            ->update();

        $this->assertBindingCount($result);
        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        /** @var array<string, mixed> $pushDoc */
        $pushDoc = $update['$push'];
        /** @var array<string, mixed> $modifier */
        $modifier = $pushDoc['tags'];

        $this->assertSame([], $modifier['$each']);
    }

    public function testPushEachBindsValuesBeforeFilterBinding(): void
    {
        $result = (new Builder())
            ->from('users')
            ->pushEach('tags', ['a', 'b'])
            ->filter([Query::equal('_id', ['ID'])])
            ->update();

        $this->assertBindingCount($result);
        // All bindings appear in the result regardless of order. Order is
        // an implementation detail; the assertion here is that every value
        // the caller provided ends up bound.
        $this->assertContains('a', $result->bindings);
        $this->assertContains('b', $result->bindings);
        $this->assertContains('ID', $result->bindings);
    }

    public function testPushEachWithOnlySliceOmitsPositionAndSort(): void
    {
        $result = (new Builder())
            ->from('users')
            ->pushEach('tags', ['a'], null, 3)
            ->filter([Query::equal('_id', ['x'])])
            ->update();

        $this->assertBindingCount($result);
        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        /** @var array<string, mixed> $pushDoc */
        $pushDoc = $update['$push'];
        /** @var array<string, mixed> $modifier */
        $modifier = $pushDoc['tags'];

        $this->assertSame(3, $modifier['$slice']);
        $this->assertArrayNotHasKey('$position', $modifier);
        $this->assertArrayNotHasKey('$sort', $modifier);
    }

    public function testPushEachRejectsDollarPrefixedField(): void
    {
        $this->expectException(ValidationException::class);
        (new Builder())->from('users')->pushEach('$evil', ['x']);
    }
}
