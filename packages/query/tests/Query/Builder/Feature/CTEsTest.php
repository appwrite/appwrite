<?php

namespace Tests\Query\Builder\Feature;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\PostgreSQL as Builder;
use Utopia\Query\Query;

class CTEsTest extends TestCase
{
    use AssertsBindingCount;

    public function testWithEmitsNonRecursiveWithClause(): void
    {
        $cte = (new Builder())->from('orders');

        $result = (new Builder())
            ->with('recent', $cte)
            ->from('recent')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('WITH "recent" AS (SELECT * FROM "orders") SELECT * FROM "recent"', $result->query);
        $this->assertStringNotContainsString('RECURSIVE', $result->query);
    }

    public function testWithColumnListIsQuoted(): void
    {
        $cte = (new Builder())->from('orders');

        $result = (new Builder())
            ->with('projection', $cte, ['id', 'name'])
            ->from('projection')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('WITH "projection"("id", "name") AS (SELECT * FROM "orders") SELECT * FROM "projection"', $result->query);
    }

    public function testWithRecursiveEmitsRecursiveKeyword(): void
    {
        $sub = (new Builder())->from('categories');

        $result = (new Builder())
            ->withRecursive('tree', $sub)
            ->from('tree')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('WITH RECURSIVE "tree" AS (SELECT * FROM "categories") SELECT * FROM "tree"', $result->query);
    }

    public function testWithRecursiveSeedStepJoinsWithUnionAll(): void
    {
        $seed = (new Builder())
            ->from('categories')
            ->select(['id', 'parent_id', 'name'])
            ->filter([Query::isNull('parent_id')]);

        $step = (new Builder())
            ->from('categories')
            ->select(['categories.id', 'categories.parent_id', 'categories.name'])
            ->join('tree', 'categories.parent_id', 'tree.id');

        $result = (new Builder())
            ->withRecursiveSeedStep('tree', $seed, $step, ['id', 'parent_id', 'name'])
            ->from('tree')
            ->select(['id', 'name'])
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('WITH RECURSIVE "tree"("id", "parent_id", "name") AS (SELECT "id", "parent_id", "name" FROM "categories" WHERE "parent_id" IS NULL UNION ALL SELECT "categories"."id", "categories"."parent_id", "categories"."name" FROM "categories" JOIN "tree" ON "categories"."parent_id" = "tree"."id") SELECT "id", "name" FROM "tree"', $result->query);
    }

    public function testMultipleCtesAreCommaSeparated(): void
    {
        $cteA = (new Builder())
            ->from('users')
            ->filter([Query::equal('active', [true])]);
        $cteB = (new Builder())
            ->from('orders')
            ->filter([Query::greaterThan('total', 50)]);

        $result = (new Builder())
            ->with('active_users', $cteA)
            ->with('big_orders', $cteB)
            ->from('active_users')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('WITH "active_users" AS (SELECT * FROM "users" WHERE "active" IN (?)), "big_orders" AS (SELECT * FROM "orders" WHERE "total" > ?) SELECT * FROM "active_users"', $result->query);
    }

    public function testCteBindingsAppearBeforeOuterBindings(): void
    {
        $cte = (new Builder())
            ->from('orders')
            ->filter([Query::equal('status', ['shipped'])]);

        $result = (new Builder())
            ->with('shipped', $cte)
            ->from('shipped')
            ->filter([Query::equal('total', [100])])
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('shipped', $result->bindings[0]);
        $this->assertSame(100, $result->bindings[1]);
    }

    public function testWithRecursiveSeedStepMergesBindingsInSeedThenStepOrder(): void
    {
        $seed = (new Builder())
            ->from('categories')
            ->filter([Query::equal('kind', ['root'])]);
        $step = (new Builder())
            ->from('categories')
            ->filter([Query::equal('kind', ['child'])]);

        $result = (new Builder())
            ->withRecursiveSeedStep('tree', $seed, $step)
            ->from('tree')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame(['root', 'child'], $result->bindings);
    }

    public function testWithEmptyColumnsEmitsNoColumnList(): void
    {
        $cte = (new Builder())->from('orders');

        $result = (new Builder())
            ->with('recent', $cte, [])
            ->from('recent')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('WITH "recent" AS (SELECT * FROM "orders") SELECT * FROM "recent"', $result->query);
        $this->assertStringNotContainsString('"recent"(', $result->query);
    }

    public function testChainableReturnsSameInstance(): void
    {
        $builder = new Builder();
        $cte = (new Builder())->from('orders');
        $returned = $builder->with('x', $cte);

        $this->assertSame($builder, $returned);
    }
}
