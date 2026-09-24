<?php

namespace Tests\Query\Builder\Feature\PostgreSQL;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\PostgreSQL as Builder;
use Utopia\Query\Query;

class ReturningTest extends TestCase
{
    use AssertsBindingCount;

    public function testInsertReturningListQuotesColumns(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'John'])
            ->returning(['id', 'name'])
            ->insert();

        $this->assertBindingCount($result);
        $this->assertSame('INSERT INTO "users" ("name") VALUES (?) RETURNING "id", "name"', $result->query);
    }

    public function testReturningDefaultIsStarWildcard(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'John'])
            ->returning()
            ->insert();

        $this->assertBindingCount($result);
        $this->assertSame('INSERT INTO "users" ("name") VALUES (?) RETURNING *', $result->query);
    }

    public function testReturningEmptyArrayEmitsNoReturningClause(): void
    {
        // Passing an empty list means "no columns to return"; the builder
        // must not emit RETURNING at all rather than degenerate to "RETURNING *".
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'John'])
            ->returning([])
            ->insert();

        $this->assertBindingCount($result);
        $this->assertStringNotContainsString('RETURNING', $result->query);
    }

    public function testUpdateReturningEmitsReturningClause(): void
    {
        $result = (new Builder())
            ->from('users')
            ->set(['name' => 'Jane'])
            ->filter([Query::equal('id', [1])])
            ->returning(['id'])
            ->update();

        $this->assertBindingCount($result);
        $this->assertSame('UPDATE "users" SET "name" = ? WHERE "id" IN (?) RETURNING "id"', $result->query);
    }

    public function testDeleteReturningEmitsReturningClause(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('id', [1])])
            ->returning(['id'])
            ->delete();

        $this->assertBindingCount($result);
        $this->assertSame('DELETE FROM "users" WHERE "id" IN (?) RETURNING "id"', $result->query);
    }

    public function testReturningBindingsUnchanged(): void
    {
        $result = (new Builder())
            ->from('users')
            ->set(['name' => 'Jane'])
            ->filter([Query::equal('id', [42])])
            ->returning(['id', 'name'])
            ->update();

        $this->assertBindingCount($result);
        // RETURNING should not add bindings; only SET and WHERE contribute.
        $this->assertSame([0 => 'Jane', 1 => 42], $result->bindings);
    }
}
