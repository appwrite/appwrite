<?php

namespace Tests\Query\Builder\Feature\PostgreSQL;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\PostgreSQL as Builder;
use Utopia\Query\Query;

class MergeTest extends TestCase
{
    use AssertsBindingCount;

    public function testMergeHappyPathEmitsMergeIntoUsingOnClauses(): void
    {
        $source = (new Builder())->from('staging')->select(['id', 'name']);

        $result = (new Builder())
            ->mergeInto('users')
            ->using($source, 'src')
            ->on('users.id = src.id')
            ->whenMatched('UPDATE SET name = src.name')
            ->whenNotMatched('INSERT (id, name) VALUES (src.id, src.name)')
            ->executeMerge();

        $this->assertBindingCount($result);
        $this->assertSame('MERGE INTO "users" USING (SELECT "id", "name" FROM "staging") AS "src" ON users.id = src.id WHEN MATCHED THEN UPDATE SET name = src.name WHEN NOT MATCHED THEN INSERT (id, name) VALUES (src.id, src.name)', $result->query);
    }

    public function testMergeQuotesTargetIdentifierForPostgreSQL(): void
    {
        $source = (new Builder())->from('staging');

        $result = (new Builder())
            ->mergeInto('order_lines')
            ->using($source, 'src')
            ->on('order_lines.id = src.id')
            ->whenMatched('UPDATE SET qty = src.qty')
            ->executeMerge();

        $this->assertBindingCount($result);
        $this->assertSame('MERGE INTO "order_lines" USING (SELECT * FROM "staging") AS "src" ON order_lines.id = src.id WHEN MATCHED THEN UPDATE SET qty = src.qty', $result->query);
    }

    public function testMergePreservesSourceFilterBindingsFirst(): void
    {
        $source = (new Builder())
            ->from('staging')
            ->filter([Query::equal('status', ['pending'])]);

        $result = (new Builder())
            ->mergeInto('users')
            ->using($source, 'src')
            ->on('users.id = src.id')
            ->whenMatched('UPDATE SET name = src.name')
            ->executeMerge();

        $this->assertBindingCount($result);
        // The source subquery's binding must come before any later merge
        // clause bindings.
        $this->assertSame('pending', $result->bindings[0]);
    }

    public function testMergeOnClauseBindingsAppendAfterSource(): void
    {
        $source = (new Builder())
            ->from('staging')
            ->filter([Query::equal('status', ['pending'])]);

        $result = (new Builder())
            ->mergeInto('users')
            ->using($source, 'src')
            ->on('users.id = src.id AND src.region = ?', 'US')
            ->whenMatched('UPDATE SET name = src.name')
            ->executeMerge();

        $this->assertBindingCount($result);
        // Source binding first, then ON-clause binding.
        $this->assertSame(['pending', 'US'], $result->bindings);
    }

    public function testMergeWithOnlyWhenMatchedStillBuilds(): void
    {
        $source = (new Builder())->from('staging');

        $result = (new Builder())
            ->mergeInto('users')
            ->using($source, 'src')
            ->on('users.id = src.id')
            ->whenMatched('UPDATE SET name = src.name')
            ->executeMerge();

        $this->assertBindingCount($result);
        $this->assertSame('MERGE INTO "users" USING (SELECT * FROM "staging") AS "src" ON users.id = src.id WHEN MATCHED THEN UPDATE SET name = src.name', $result->query);
        $this->assertStringNotContainsString('WHEN NOT MATCHED', $result->query);
    }
}
