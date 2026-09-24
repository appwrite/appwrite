<?php

namespace Tests\Query\Builder;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\Case\Expression as CaseExpression;
use Utopia\Query\Builder\Case\Operator;
use Utopia\Query\Builder\Condition;
use Utopia\Query\Builder\Feature\Aggregates;
use Utopia\Query\Builder\Feature\ConditionalAggregates;
use Utopia\Query\Builder\Feature\CTEs;
use Utopia\Query\Builder\Feature\Cube;
use Utopia\Query\Builder\Feature\Deletes;
use Utopia\Query\Builder\Feature\FullOuterJoins;
use Utopia\Query\Builder\Feature\Hints;
use Utopia\Query\Builder\Feature\Hooks;
use Utopia\Query\Builder\Feature\Inserts;
use Utopia\Query\Builder\Feature\Joins;
use Utopia\Query\Builder\Feature\Json;
use Utopia\Query\Builder\Feature\LateralJoins;
use Utopia\Query\Builder\Feature\Locking;
use Utopia\Query\Builder\Feature\PostgreSQL\Merge;
use Utopia\Query\Builder\Feature\PostgreSQL\VectorSearch;
use Utopia\Query\Builder\Feature\Rollup;
use Utopia\Query\Builder\Feature\Selects;
use Utopia\Query\Builder\Feature\Sequences;
use Utopia\Query\Builder\Feature\Spatial;
use Utopia\Query\Builder\Feature\TableSampling;
use Utopia\Query\Builder\Feature\Totals;
use Utopia\Query\Builder\Feature\Transactions;
use Utopia\Query\Builder\Feature\Unions;
use Utopia\Query\Builder\Feature\Updates;
use Utopia\Query\Builder\Feature\Upsert;
use Utopia\Query\Builder\Feature\Windows;
use Utopia\Query\Builder\JoinBuilder;
use Utopia\Query\Builder\JoinType;
use Utopia\Query\Builder\PostgreSQL as Builder;
use Utopia\Query\Builder\VectorMetric;
use Utopia\Query\Compiler;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Hook\Filter;
use Utopia\Query\Query;
use Utopia\Query\Schema\ColumnType;

class PostgreSQLTest extends TestCase
{
    use AssertsBindingCount;
    public function testImplementsCompiler(): void
    {
        $this->assertInstanceOf(Compiler::class, new Builder());
    }

    public function testImplementsRollupAndCube(): void
    {
        $builder = new Builder();

        $this->assertInstanceOf(Rollup::class, $builder);
        $this->assertInstanceOf(Cube::class, $builder);
    }

    public function testDoesNotImplementTotals(): void
    {
        $this->assertArrayNotHasKey(Totals::class, \class_implements(new Builder()));
    }

    public function testImplementsSelects(): void
    {
        $this->assertInstanceOf(Selects::class, new Builder());
    }

    public function testImplementsAggregates(): void
    {
        $this->assertInstanceOf(Aggregates::class, new Builder());
    }

    public function testImplementsJoins(): void
    {
        $this->assertInstanceOf(Joins::class, new Builder());
    }

    public function testImplementsUnions(): void
    {
        $this->assertInstanceOf(Unions::class, new Builder());
    }

    public function testImplementsCTEs(): void
    {
        $this->assertInstanceOf(CTEs::class, new Builder());
    }

    public function testImplementsInserts(): void
    {
        $this->assertInstanceOf(Inserts::class, new Builder());
    }

    public function testImplementsUpdates(): void
    {
        $this->assertInstanceOf(Updates::class, new Builder());
    }

    public function testImplementsDeletes(): void
    {
        $this->assertInstanceOf(Deletes::class, new Builder());
    }

    public function testImplementsHooks(): void
    {
        $this->assertInstanceOf(Hooks::class, new Builder());
    }

    public function testImplementsTransactions(): void
    {
        $this->assertInstanceOf(Transactions::class, new Builder());
    }

    public function testImplementsLocking(): void
    {
        $this->assertInstanceOf(Locking::class, new Builder());
    }

    public function testImplementsUpsert(): void
    {
        $this->assertInstanceOf(Upsert::class, new Builder());
    }

    public function testSelectWrapsWithDoubleQuotes(): void
    {
        $result = (new Builder())
            ->from('t')
            ->select(['a', 'b', 'c'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT "a", "b", "c" FROM "t"', $result->query);
    }

    public function testFromWrapsWithDoubleQuotes(): void
    {
        $result = (new Builder())
            ->from('my_table')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "my_table"', $result->query);
    }

    public function testFilterWrapsWithDoubleQuotes(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('col', [1])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "col" IN (?)', $result->query);
    }

    public function testSortWrapsWithDoubleQuotes(): void
    {
        $result = (new Builder())
            ->from('t')
            ->sortAsc('name')
            ->sortDesc('age')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" ORDER BY "name" ASC, "age" DESC', $result->query);
    }

    public function testJoinWrapsWithDoubleQuotes(): void
    {
        $result = (new Builder())
            ->from('users')
            ->join('orders', 'users.id', 'orders.uid')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM "users" JOIN "orders" ON "users"."id" = "orders"."uid"',
            $result->query
        );
    }

    public function testLeftJoinWrapsWithDoubleQuotes(): void
    {
        $result = (new Builder())
            ->from('users')
            ->leftJoin('profiles', 'users.id', 'profiles.uid')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM "users" LEFT JOIN "profiles" ON "users"."id" = "profiles"."uid"',
            $result->query
        );
    }

    public function testRightJoinWrapsWithDoubleQuotes(): void
    {
        $result = (new Builder())
            ->from('users')
            ->rightJoin('orders', 'users.id', 'orders.uid')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM "users" RIGHT JOIN "orders" ON "users"."id" = "orders"."uid"',
            $result->query
        );
    }

    public function testCrossJoinWrapsWithDoubleQuotes(): void
    {
        $result = (new Builder())
            ->from('a')
            ->crossJoin('b')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "a" CROSS JOIN "b"', $result->query);
    }

    public function testAggregationWrapsWithDoubleQuotes(): void
    {
        $result = (new Builder())
            ->from('t')
            ->sum('price', 'total')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT SUM("price") AS "total" FROM "t"', $result->query);
    }

    public function testGroupByWrapsWithDoubleQuotes(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count('*', 'cnt')
            ->groupBy(['status', 'country'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT COUNT(*) AS "cnt" FROM "t" GROUP BY "status", "country"',
            $result->query
        );
    }

    public function testHavingWrapsWithDoubleQuotes(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count('*', 'cnt')
            ->groupBy(['status'])
            ->having([Query::greaterThan('cnt', 5)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS "cnt" FROM "t" GROUP BY "status" HAVING COUNT(*) > ?', $result->query);
    }

    public function testDistinctWrapsWithDoubleQuotes(): void
    {
        $result = (new Builder())
            ->from('t')
            ->distinct()
            ->select(['status'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT DISTINCT "status" FROM "t"', $result->query);
    }

    public function testIsNullWrapsWithDoubleQuotes(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::isNull('deleted')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "deleted" IS NULL', $result->query);
    }

    public function testRandomUsesRandomFunction(): void
    {
        $result = (new Builder())
            ->from('t')
            ->sortRandom()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" ORDER BY RANDOM()', $result->query);
    }

    public function testRegexUsesTildeOperator(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::regex('slug', '^test')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "slug" ~ ?', $result->query);
        $this->assertSame(['^test'], $result->bindings);
    }

    public function testSearchUsesToTsvector(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::search('body', 'hello')])
            ->build();
        $this->assertBindingCount($result);

        $expected = "SELECT * FROM \"t\" WHERE to_tsvector(regexp_replace(\"body\", '[^\\w]+', ' ', 'g')) @@ websearch_to_tsquery(?)";
        $this->assertSame($expected, $result->query);
        $this->assertSame(['hello'], $result->bindings);
    }

    public function testNotSearchUsesToTsvector(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notSearch('body', 'spam')])
            ->build();
        $this->assertBindingCount($result);

        $expected = "SELECT * FROM \"t\" WHERE NOT (to_tsvector(regexp_replace(\"body\", '[^\\w]+', ' ', 'g')) @@ websearch_to_tsquery(?))";
        $this->assertSame($expected, $result->query);
        $this->assertSame(['spam'], $result->bindings);
    }

    public function testUpsertUsesOnConflict(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'])
            ->onConflict(['id'], ['name', 'email'])
            ->upsert();
        $this->assertBindingCount($result);

        $this->assertSame(
            'INSERT INTO "users" ("id", "name", "email") VALUES (?, ?, ?) ON CONFLICT ("id") DO UPDATE SET "name" = EXCLUDED."name", "email" = EXCLUDED."email"',
            $result->query
        );
        $this->assertSame([1, 'Alice', 'alice@example.com'], $result->bindings);
    }

    public function testOffsetWithoutLimitEmitsOffset(): void
    {
        $result = (new Builder())
            ->from('t')
            ->offset(10)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" OFFSET ?', $result->query);
        $this->assertSame([10], $result->bindings);
    }

    public function testOffsetWithLimitEmitsBoth(): void
    {
        $result = (new Builder())
            ->from('t')
            ->limit(25)
            ->offset(10)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" LIMIT ? OFFSET ?', $result->query);
        $this->assertSame([25, 10], $result->bindings);
    }

    public function testConditionProviderWithDoubleQuotes(): void
    {
        $hook = new class () implements Filter {
            public function filter(string $table): Condition
            {
                return new Condition('raw_condition = 1', []);
            }
        };

        $result = (new Builder())
            ->from('t')
            ->addHook($hook)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE raw_condition = 1', $result->query);
    }

    public function testInsertWrapsWithDoubleQuotes(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'Alice', 'age' => 30])
            ->insert();
        $this->assertBindingCount($result);

        $this->assertSame(
            'INSERT INTO "users" ("name", "age") VALUES (?, ?)',
            $result->query
        );
        $this->assertSame(['Alice', 30], $result->bindings);
    }

    public function testUpdateWrapsWithDoubleQuotes(): void
    {
        $result = (new Builder())
            ->from('users')
            ->set(['name' => 'Bob'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame(
            'UPDATE "users" SET "name" = ? WHERE "id" IN (?)',
            $result->query
        );
        $this->assertSame(['Bob', 1], $result->bindings);
    }

    public function testDeleteWrapsWithDoubleQuotes(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('id', [1])])
            ->delete();
        $this->assertBindingCount($result);

        $this->assertSame(
            'DELETE FROM "users" WHERE "id" IN (?)',
            $result->query
        );
        $this->assertSame([1], $result->bindings);
    }

    public function testSavepointWrapsWithDoubleQuotes(): void
    {
        $result = (new Builder())->savepoint('sp1');

        $this->assertSame('SAVEPOINT "sp1"', $result->query);
    }

    public function testForUpdateWithDoubleQuotes(): void
    {
        $result = (new Builder())
            ->from('t')
            ->forUpdate()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" FOR UPDATE', $result->query);
    }
    //  Spatial feature interface

    public function testImplementsSpatial(): void
    {
        $this->assertInstanceOf(Spatial::class, new Builder());
    }

    public function testFilterDistanceMeters(): void
    {
        $result = (new Builder())
            ->from('locations')
            ->filterDistance('coords', [40.7128, -74.0060], '<', 5000.0, true)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "locations" WHERE ST_Distance(("coords"::geography), ST_SetSRID(ST_GeomFromText(?), 4326)::geography) < ?', $result->query);
        $this->assertSame('POINT(40.7128 -74.006)', $result->bindings[0]);
        $this->assertSame(5000.0, $result->bindings[1]);
    }

    public function testFilterIntersectsPoint(): void
    {
        $result = (new Builder())
            ->from('zones')
            ->filterIntersects('area', [1.0, 2.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "zones" WHERE ST_Intersects("area", ST_GeomFromText(?, 4326))', $result->query);
    }

    public function testFilterCovers(): void
    {
        $result = (new Builder())
            ->from('zones')
            ->filterCovers('area', [1.0, 2.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "zones" WHERE ST_Covers("area", ST_GeomFromText(?, 4326))', $result->query);
    }

    public function testFilterCrosses(): void
    {
        $result = (new Builder())
            ->from('roads')
            ->filterCrosses('path', [[0, 0], [1, 1]])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "roads" WHERE ST_Crosses("path", ST_GeomFromText(?, 4326))', $result->query);
    }
    //  VectorSearch feature interface

    public function testImplementsVectorSearch(): void
    {
        $this->assertInstanceOf(VectorSearch::class, new Builder());
    }

    public function testOrderByVectorDistanceCosine(): void
    {
        $result = (new Builder())
            ->from('embeddings')
            ->orderByVectorDistance('embedding', [0.1, 0.2, 0.3], VectorMetric::Cosine)
            ->limit(10)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "embeddings" ORDER BY ("embedding" <=> ?::vector) ASC LIMIT ?', $result->query);
        $this->assertSame('[0.1,0.2,0.3]', $result->bindings[0]);
    }

    public function testOrderByVectorDistanceEuclidean(): void
    {
        $result = (new Builder())
            ->from('embeddings')
            ->orderByVectorDistance('embedding', [1.0, 2.0], VectorMetric::Euclidean)
            ->limit(5)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "embeddings" ORDER BY ("embedding" <-> ?::vector) ASC LIMIT ?', $result->query);
    }

    public function testOrderByVectorDistanceDot(): void
    {
        $result = (new Builder())
            ->from('embeddings')
            ->orderByVectorDistance('embedding', [1.0, 2.0], VectorMetric::Dot)
            ->limit(5)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "embeddings" ORDER BY ("embedding" <#> ?::vector) ASC LIMIT ?', $result->query);
    }

    public function testVectorFilterCosine(): void
    {
        $result = (new Builder())
            ->from('embeddings')
            ->filter([Query::vectorCosine('embedding', [0.1, 0.2])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "embeddings" WHERE ("embedding" <=> ?::vector)', $result->query);
    }

    public function testVectorFilterEuclidean(): void
    {
        $result = (new Builder())
            ->from('embeddings')
            ->filter([Query::vectorEuclidean('embedding', [0.1, 0.2])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "embeddings" WHERE ("embedding" <-> ?::vector)', $result->query);
    }

    public function testVectorFilterDot(): void
    {
        $result = (new Builder())
            ->from('embeddings')
            ->filter([Query::vectorDot('embedding', [0.1, 0.2])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "embeddings" WHERE ("embedding" <#> ?::vector)', $result->query);
    }
    //  JSON feature interface

    public function testImplementsJson(): void
    {
        $this->assertInstanceOf(Json::class, new Builder());
    }

    public function testFilterJsonContains(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->filterJsonContains('tags', 'php')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "docs" WHERE "tags" @> ?::jsonb', $result->query);
    }

    public function testFilterJsonNotContains(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->filterJsonNotContains('tags', 'old')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "docs" WHERE NOT ("tags" @> ?::jsonb)', $result->query);
    }

    public function testFilterJsonOverlaps(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->filterJsonOverlaps('tags', ['php', 'go'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "docs" WHERE ("tags" @> ?::jsonb OR "tags" @> ?::jsonb)', $result->query);
    }

    public function testFilterJsonPath(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filterJsonPath('metadata', 'level', '>', 5)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "users" WHERE "metadata"->>\'level\' > ?', $result->query);
        $this->assertSame(5, $result->bindings[0]);
    }

    public function testSetJsonAppend(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->setJsonAppend('tags', ['new'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE "docs" SET "tags" = COALESCE("tags", \'[]\'::jsonb) || ?::jsonb WHERE "id" IN (?)', $result->query);
    }

    public function testSetJsonPrepend(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->setJsonPrepend('tags', ['first'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE "docs" SET "tags" = ?::jsonb || COALESCE("tags", \'[]\'::jsonb) WHERE "id" IN (?)', $result->query);
    }

    public function testSetJsonInsert(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->setJsonInsert('tags', 0, 'inserted')
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE "docs" SET "tags" = jsonb_insert("tags", \'{0}\', ?::jsonb) WHERE "id" IN (?)', $result->query);
    }

    public function testSetJsonPath(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->setJsonPath('data', '$.name', 'NewValue')
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame(
            'UPDATE "docs" SET "data" = jsonb_set("data", ?, to_jsonb(?::text)::jsonb, true) WHERE "id" IN (?)',
            $result->query
        );
        $this->assertSame('{name}', $result->bindings[0]);
        $this->assertSame('NewValue', $result->bindings[1]);
        $this->assertSame(1, $result->bindings[2]);
    }

    public function testSetJsonPathNested(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->setJsonPath('data', '$.profile.name', 'Alice')
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE "docs" SET "data" = jsonb_set("data", ?, to_jsonb(?::text)::jsonb, true) WHERE "id" IN (?)', $result->query);
        $this->assertSame('{profile,name}', $result->bindings[0]);
        $this->assertSame('Alice', $result->bindings[1]);
    }

    public function testSetJsonPathRejectsInvalidPath(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->from('docs')
            ->setJsonPath('data', 'name', 'NewValue');
    }
    //  Window functions

    public function testImplementsWindows(): void
    {
        $this->assertInstanceOf(Windows::class, new Builder());
    }

    public function testSelectWindowRowNumber(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->selectWindow('ROW_NUMBER()', 'rn', ['customer_id'], ['created_at'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT ROW_NUMBER() OVER (PARTITION BY "customer_id" ORDER BY "created_at" ASC) AS "rn" FROM "orders"', $result->query);
    }

    public function testSelectWindowRankDesc(): void
    {
        $result = (new Builder())
            ->from('scores')
            ->selectWindow('RANK()', 'rank', null, ['-score'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT RANK() OVER (ORDER BY "score" DESC) AS "rank" FROM "scores"', $result->query);
    }
    //  CASE integration

    public function testSelectCaseExpression(): void
    {
        $case = (new CaseExpression())
            ->when('status', Operator::Equal, 'active', 'Active')
            ->else('Other')
            ->alias('label');

        $result = (new Builder())
            ->from('users')
            ->select(['id'])
            ->selectCase($case)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT "id", CASE WHEN "status" = ? THEN ? ELSE ? END AS "label" FROM "users"', $result->query);
        $this->assertSame(['active', 'Active', 'Other'], $result->bindings);
    }
    //  Does NOT implement Hints

    public function testDoesNotImplementHints(): void
    {
        $builder = new Builder();
        $this->assertNotInstanceOf(Hints::class, $builder); // @phpstan-ignore method.alreadyNarrowedType
    }
    //  Reset clears new state

    public function testResetClearsVectorOrder(): void
    {
        $builder = (new Builder())
            ->from('embeddings')
            ->orderByVectorDistance('embedding', [0.1], VectorMetric::Cosine);

        $builder->reset();

        $result = $builder->from('embeddings')->build();
        $this->assertBindingCount($result);
        $this->assertStringNotContainsString('<=>', $result->query);
    }

    public function testFilterNotIntersectsPoint(): void
    {
        $result = (new Builder())
            ->from('zones')
            ->filterNotIntersects('zone', [1.0, 2.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "zones" WHERE NOT ST_Intersects("zone", ST_GeomFromText(?, 4326))', $result->query);
        $this->assertSame('POINT(1 2)', $result->bindings[0]);
    }

    public function testFilterNotCrossesLinestring(): void
    {
        $result = (new Builder())
            ->from('roads')
            ->filterNotCrosses('path', [[0, 0], [1, 1]])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "roads" WHERE NOT ST_Crosses("path", ST_GeomFromText(?, 4326))', $result->query);
        /** @var string $binding */
        $binding = $result->bindings[0];
        $this->assertSame('LINESTRING(0 0, 1 1)', $binding);
    }

    public function testFilterOverlapsPolygon(): void
    {
        $result = (new Builder())
            ->from('maps')
            ->filterOverlaps('area', [[[0, 0], [1, 0], [1, 1], [0, 0]]])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "maps" WHERE ST_Overlaps("area", ST_GeomFromText(?, 4326))', $result->query);
        /** @var string $binding */
        $binding = $result->bindings[0];
        $this->assertSame('POLYGON((0 0, 1 0, 1 1, 0 0))', $binding);
    }

    public function testFilterNotOverlaps(): void
    {
        $result = (new Builder())
            ->from('maps')
            ->filterNotOverlaps('area', [1.0, 2.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "maps" WHERE NOT ST_Overlaps("area", ST_GeomFromText(?, 4326))', $result->query);
    }

    public function testFilterTouches(): void
    {
        $result = (new Builder())
            ->from('zones')
            ->filterTouches('zone', [5.0, 10.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "zones" WHERE ST_Touches("zone", ST_GeomFromText(?, 4326))', $result->query);
    }

    public function testFilterNotTouches(): void
    {
        $result = (new Builder())
            ->from('zones')
            ->filterNotTouches('zone', [5.0, 10.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "zones" WHERE NOT ST_Touches("zone", ST_GeomFromText(?, 4326))', $result->query);
    }

    public function testFilterCoversUsesSTCovers(): void
    {
        $result = (new Builder())
            ->from('regions')
            ->filterCovers('region', [1.0, 2.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "regions" WHERE ST_Covers("region", ST_GeomFromText(?, 4326))', $result->query);
        $this->assertStringNotContainsString('ST_Contains', $result->query);
    }

    public function testFilterNotCovers(): void
    {
        $result = (new Builder())
            ->from('regions')
            ->filterNotCovers('region', [1.0, 2.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "regions" WHERE NOT ST_Covers("region", ST_GeomFromText(?, 4326))', $result->query);
    }

    public function testFilterSpatialEquals(): void
    {
        $result = (new Builder())
            ->from('geoms')
            ->filterSpatialEquals('geom', [3.0, 4.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "geoms" WHERE ST_Equals("geom", ST_GeomFromText(?, 4326))', $result->query);
    }

    public function testFilterNotSpatialEquals(): void
    {
        $result = (new Builder())
            ->from('geoms')
            ->filterNotSpatialEquals('geom', [3.0, 4.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "geoms" WHERE NOT ST_Equals("geom", ST_GeomFromText(?, 4326))', $result->query);
    }

    public function testFilterDistanceGreaterThan(): void
    {
        $result = (new Builder())
            ->from('locations')
            ->filterDistance('loc', [1.0, 2.0], '>', 500.0)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "locations" WHERE ST_Distance("loc", ST_GeomFromText(?, 4326)) > ?', $result->query);
        $this->assertSame('POINT(1 2)', $result->bindings[0]);
        $this->assertSame(500.0, $result->bindings[1]);
    }

    public function testFilterDistanceWithoutMeters(): void
    {
        $result = (new Builder())
            ->from('locations')
            ->filterDistance('loc', [1.0, 2.0], '<', 50.0, false)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "locations" WHERE ST_Distance("loc", ST_GeomFromText(?, 4326)) < ?', $result->query);
        $this->assertSame('POINT(1 2)', $result->bindings[0]);
        $this->assertSame(50.0, $result->bindings[1]);
    }

    public function testVectorOrderWithExistingOrderBy(): void
    {
        $result = (new Builder())
            ->from('items')
            ->sortAsc('name')
            ->orderByVectorDistance('embedding', [0.1], VectorMetric::Cosine)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "items" ORDER BY ("embedding" <=> ?::vector) ASC, "name" ASC', $result->query);
        $pos_vector = strpos($result->query, '<=>');
        $pos_name = strpos($result->query, '"name"');
        $this->assertNotFalse($pos_vector);
        $this->assertNotFalse($pos_name);
        $this->assertLessThan($pos_name, $pos_vector);
    }

    public function testVectorOrderWithLimit(): void
    {
        $result = (new Builder())
            ->from('items')
            ->orderByVectorDistance('emb', [0.1, 0.2], VectorMetric::Cosine)
            ->limit(10)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "items" ORDER BY ("emb" <=> ?::vector) ASC LIMIT ?', $result->query);
        $pos_order = strpos($result->query, 'ORDER BY');
        $pos_limit = strpos($result->query, 'LIMIT');
        $this->assertNotFalse($pos_order);
        $this->assertNotFalse($pos_limit);
        $this->assertLessThan($pos_limit, $pos_order);

        // Vector JSON binding comes before limit value binding
        $vectorIdx = array_search('[0.1,0.2]', $result->bindings, true);
        $limitIdx = array_search(10, $result->bindings, true);
        $this->assertNotFalse($vectorIdx);
        $this->assertNotFalse($limitIdx);
        $this->assertLessThan($limitIdx, $vectorIdx);
    }

    public function testVectorOrderDefaultMetric(): void
    {
        $result = (new Builder())
            ->from('items')
            ->orderByVectorDistance('emb', [0.5])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "items" ORDER BY ("emb" <=> ?::vector) ASC', $result->query);
    }

    public function testVectorFilterCosineBindings(): void
    {
        $result = (new Builder())
            ->from('embeddings')
            ->filter([Query::vectorCosine('embedding', [0.1, 0.2])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "embeddings" WHERE ("embedding" <=> ?::vector)', $result->query);
        $this->assertSame(json_encode([0.1, 0.2]), $result->bindings[0]);
    }

    public function testVectorFilterEuclideanBindings(): void
    {
        $result = (new Builder())
            ->from('embeddings')
            ->filter([Query::vectorEuclidean('embedding', [0.1])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "embeddings" WHERE ("embedding" <-> ?::vector)', $result->query);
        $this->assertSame(json_encode([0.1]), $result->bindings[0]);
    }

    public function testFilterJsonNotContainsAdmin(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->filterJsonNotContains('meta', 'admin')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "docs" WHERE NOT ("meta" @> ?::jsonb)', $result->query);
    }

    public function testFilterJsonOverlapsArray(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->filterJsonOverlaps('tags', ['php', 'js'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "docs" WHERE ("tags" @> ?::jsonb OR "tags" @> ?::jsonb)', $result->query);
    }

    public function testFilterJsonPathComparison(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filterJsonPath('data', 'age', '>=', 21)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "users" WHERE "data"->>\'age\' >= ?', $result->query);
        $this->assertSame(21, $result->bindings[0]);
    }

    public function testFilterJsonPathEquality(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filterJsonPath('meta', 'status', '=', 'active')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "users" WHERE "meta"->>\'status\' = ?', $result->query);
        $this->assertSame('active', $result->bindings[0]);
    }

    public function testSetJsonRemove(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->setJsonRemove('tags', 'old')
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE "docs" SET "tags" = "tags" - ? WHERE "id" IN (?)', $result->query);
        $this->assertContains(json_encode('old'), $result->bindings);
    }

    public function testSetJsonIntersect(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->setJsonIntersect('tags', ['a', 'b'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE "docs" SET "tags" = (SELECT jsonb_agg(elem) FROM jsonb_array_elements("tags") AS elem WHERE elem <@ ?::jsonb) WHERE "id" IN (?)', $result->query);
    }

    public function testSetJsonDiff(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->setJsonDiff('tags', ['x'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE "docs" SET "tags" = (SELECT COALESCE(jsonb_agg(elem), \'[]\'::jsonb) FROM jsonb_array_elements("tags") AS elem WHERE NOT elem <@ ?::jsonb) WHERE "id" IN (?)', $result->query);
    }

    public function testSetJsonUnique(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->setJsonUnique('tags')
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE "docs" SET "tags" = (SELECT jsonb_agg(DISTINCT elem) FROM jsonb_array_elements("tags") AS elem) WHERE "id" IN (?)', $result->query);
    }

    public function testSetJsonAppendBindings(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->setJsonAppend('tags', ['new'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE "docs" SET "tags" = COALESCE("tags", \'[]\'::jsonb) || ?::jsonb WHERE "id" IN (?)', $result->query);
        $this->assertContains(json_encode(['new']), $result->bindings);
    }

    public function testSetJsonPrependPutsNewArrayFirst(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->setJsonPrepend('items', ['first'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE "docs" SET "items" = ?::jsonb || COALESCE("items", \'[]\'::jsonb) WHERE "id" IN (?)', $result->query);
    }

    public function testMultipleCTEs(): void
    {
        $a = (new Builder())->from('x')->filter([Query::equal('status', ['active'])]);
        $b = (new Builder())->from('y')->filter([Query::equal('type', ['premium'])]);

        $result = (new Builder())
            ->with('a', $a)
            ->with('b', $b)
            ->from('a')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('WITH "a" AS (SELECT * FROM "x" WHERE "status" IN (?)), "b" AS (SELECT * FROM "y" WHERE "type" IN (?)) SELECT * FROM "a"', $result->query);
    }

    public function testCTEWithRecursive(): void
    {
        $sub = (new Builder())->from('categories');

        $result = (new Builder())
            ->withRecursive('tree', $sub)
            ->from('tree')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('WITH RECURSIVE "tree" AS (SELECT * FROM "categories") SELECT * FROM "tree"', $result->query);
    }

    public function testCTEBindingOrder(): void
    {
        $cteQuery = (new Builder())->from('orders')->filter([Query::equal('status', ['shipped'])]);

        $result = (new Builder())
            ->with('shipped', $cteQuery)
            ->from('shipped')
            ->filter([Query::equal('total', [100])])
            ->build();
        $this->assertBindingCount($result);

        // CTE bindings come first
        $this->assertSame('shipped', $result->bindings[0]);
        $this->assertSame(100, $result->bindings[1]);
    }

    public function testInsertSelectWithFilter(): void
    {
        $source = (new Builder())
            ->from('orders')
            ->select(['customer_id', 'total'])
            ->filter([Query::greaterThan('total', 100)]);

        $result = (new Builder())
            ->into('big_orders')
            ->fromSelect(['customer_id', 'total'], $source)
            ->insertSelect();

        $this->assertSame('INSERT INTO "big_orders" ("customer_id", "total") SELECT "customer_id", "total" FROM "orders" WHERE "total" > ?', $result->query);
        $this->assertContains(100, $result->bindings);
    }

    public function testInsertSelectThrowsWithoutSource(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->into('target')
            ->insertSelect();
    }

    public function testUnionAll(): void
    {
        $other = (new Builder())->from('b');

        $result = (new Builder())
            ->from('a')
            ->unionAll($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM "a") UNION ALL (SELECT * FROM "b")', $result->query);
    }

    public function testIntersect(): void
    {
        $other = (new Builder())->from('b');

        $result = (new Builder())
            ->from('a')
            ->intersect($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM "a") INTERSECT (SELECT * FROM "b")', $result->query);
    }

    public function testExcept(): void
    {
        $other = (new Builder())->from('b');

        $result = (new Builder())
            ->from('a')
            ->except($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM "a") EXCEPT (SELECT * FROM "b")', $result->query);
    }

    public function testUnionWithBindingsOrder(): void
    {
        $other = (new Builder())->from('b')->filter([Query::equal('type', ['beta'])]);

        $result = (new Builder())
            ->from('a')
            ->filter([Query::equal('type', ['alpha'])])
            ->union($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('alpha', $result->bindings[0]);
        $this->assertSame('beta', $result->bindings[1]);
    }

    public function testPage(): void
    {
        $result = (new Builder())
            ->from('items')
            ->page(3, 10)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "items" LIMIT ? OFFSET ?', $result->query);
        $this->assertSame(10, $result->bindings[0]);
        $this->assertSame(20, $result->bindings[1]);
    }

    public function testOffsetWithoutLimitEmitsOffsetPostgres(): void
    {
        $result = (new Builder())
            ->from('items')
            ->offset(5)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "items" OFFSET ?', $result->query);
        $this->assertSame([5], $result->bindings);
    }

    public function testCursorAfter(): void
    {
        $result = (new Builder())
            ->from('items')
            ->sortAsc('id')
            ->cursorAfter(5)
            ->limit(10)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "items" WHERE "_cursor" > ? ORDER BY "id" ASC LIMIT ?', $result->query);
        $this->assertContains(5, $result->bindings);
        $this->assertContains(10, $result->bindings);
    }

    public function testCursorBefore(): void
    {
        $result = (new Builder())
            ->from('items')
            ->sortAsc('id')
            ->cursorBefore(5)
            ->limit(10)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "items" WHERE "_cursor" < ? ORDER BY "id" ASC LIMIT ?', $result->query);
        $this->assertContains(5, $result->bindings);
    }

    public function testSelectWindowWithPartitionOnly(): void
    {
        $result = (new Builder())
            ->from('employees')
            ->selectWindow('SUM("salary")', 'dept_total', ['dept'], null)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT SUM("salary") OVER (PARTITION BY "dept") AS "dept_total" FROM "employees"', $result->query);
    }

    public function testSelectWindowNoPartitionNoOrder(): void
    {
        $result = (new Builder())
            ->from('employees')
            ->selectWindow('COUNT(*)', 'total', null, null)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) OVER () AS "total" FROM "employees"', $result->query);
    }

    public function testMultipleWindowFunctions(): void
    {
        $result = (new Builder())
            ->from('scores')
            ->selectWindow('ROW_NUMBER()', 'rn', null, ['id'])
            ->selectWindow('RANK()', 'rnk', null, ['-score'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT ROW_NUMBER() OVER (ORDER BY "id" ASC) AS "rn", RANK() OVER (ORDER BY "score" DESC) AS "rnk" FROM "scores"', $result->query);
    }

    public function testWindowFunctionWithDescOrder(): void
    {
        $result = (new Builder())
            ->from('scores')
            ->selectWindow('RANK()', 'rnk', null, ['-score'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT RANK() OVER (ORDER BY "score" DESC) AS "rnk" FROM "scores"', $result->query);
    }

    public function testCaseMultipleWhens(): void
    {
        $case = (new CaseExpression())
            ->when('status', Operator::Equal, 'active', 'Active')
            ->when('status', Operator::Equal, 'pending', 'Pending')
            ->when('status', Operator::Equal, 'closed', 'Closed')
            ->alias('label');

        $result = (new Builder())
            ->from('tickets')
            ->selectCase($case)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT CASE WHEN "status" = ? THEN ? WHEN "status" = ? THEN ? WHEN "status" = ? THEN ? END AS "label" FROM "tickets"', $result->query);
        $this->assertSame(['active', 'Active', 'pending', 'Pending', 'closed', 'Closed'], $result->bindings);
    }

    public function testCaseWithoutElse(): void
    {
        $case = (new CaseExpression())
            ->when('active', Operator::Equal, 1, 'Yes')
            ->alias('lbl');

        $result = (new Builder())
            ->from('users')
            ->selectCase($case)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT CASE WHEN "active" = ? THEN ? END AS "lbl" FROM "users"', $result->query);
        $this->assertStringNotContainsString('ELSE', $result->query);
    }

    public function testSetCaseInUpdate(): void
    {
        $case = (new CaseExpression())
            ->when('age', Operator::GreaterThanEqual, 18, 'adult')
            ->else('minor');

        $result = (new Builder())
            ->from('users')
            ->setCase('category', $case)
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE "users" SET "category" = CASE WHEN "age" >= ? THEN ? ELSE ? END WHERE "id" IN (?)', $result->query);
        $this->assertSame([18, 'adult', 'minor', 1], $result->bindings);
    }

    public function testToRawSqlWithStrings(): void
    {
        $raw = (new Builder())
            ->from('users')
            ->filter([Query::equal('name', ['Alice'])])
            ->toRawSql();

        $this->assertSame('SELECT * FROM "users" WHERE "name" IN (\'Alice\')', $raw);
        $this->assertStringNotContainsString('?', $raw);
    }

    public function testToRawSqlEscapesSingleQuotes(): void
    {
        $raw = (new Builder())
            ->from('users')
            ->filter([Query::equal('name', ["O'Brien"])])
            ->toRawSql();

        $this->assertSame('SELECT * FROM "users" WHERE "name" IN (\'O\'\'Brien\')', $raw);
    }

    public function testBuildWithoutTableThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())->build();
    }

    public function testInsertWithoutRowsThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())->into('users')->insert();
    }

    public function testUpdateWithoutAssignmentsThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())->from('users')->filter([Query::equal('id', [1])])->update();
    }

    public function testUpsertWithoutConflictKeysThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->into('users')
            ->set(['id' => 1, 'name' => 'Alice'])
            ->upsert();
    }

    public function testBatchInsertMultipleRows(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'Alice', 'age' => 30])
            ->set(['name' => 'Bob', 'age' => 25])
            ->insert();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT INTO "users" ("name", "age") VALUES (?, ?), (?, ?)', $result->query);
        $this->assertSame(['Alice', 30, 'Bob', 25], $result->bindings);
    }

    public function testBatchInsertMismatchedColumnsThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->into('users')
            ->set(['name' => 'Alice', 'age' => 30])
            ->set(['name' => 'Bob', 'email' => 'bob@test.com'])
            ->insert();
    }

    public function testRegexUsesTildeWithCaretPattern(): void
    {
        $result = (new Builder())
            ->from('items')
            ->filter([Query::regex('s', '^t')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "items" WHERE "s" ~ ?', $result->query);
        $this->assertSame(['^t'], $result->bindings);
    }

    public function testSearchUsesToTsvectorWithMultipleWords(): void
    {
        $result = (new Builder())
            ->from('articles')
            ->filter([Query::search('body', 'hello world')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame("SELECT * FROM \"articles\" WHERE to_tsvector(regexp_replace(\"body\", '[^\\w]+', ' ', 'g')) @@ websearch_to_tsquery(?)", $result->query);
        $this->assertSame(['hello or world'], $result->bindings);
    }

    public function testUpsertUsesOnConflictDoUpdateSet(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['id' => 1, 'name' => 'Alice'])
            ->onConflict(['id'], ['name'])
            ->upsert();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT INTO "users" ("id", "name") VALUES (?, ?) ON CONFLICT ("id") DO UPDATE SET "name" = EXCLUDED."name"', $result->query);
    }

    public function testUpsertConflictUpdateColumnNotInRowThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->into('users')
            ->set(['id' => 1, 'name' => 'Alice'])
            ->onConflict(['id'], ['nonexistent'])
            ->upsert();
    }

    public function testForUpdateLocking(): void
    {
        $result = (new Builder())
            ->from('accounts')
            ->forUpdate()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "accounts" FOR UPDATE', $result->query);
    }

    public function testForShareLocking(): void
    {
        $result = (new Builder())
            ->from('accounts')
            ->forShare()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "accounts" FOR SHARE', $result->query);
    }

    public function testBeginCommitRollback(): void
    {
        $builder = new Builder();

        $begin = $builder->begin();
        $this->assertSame('BEGIN', $begin->query);

        $commit = $builder->commit();
        $this->assertSame('COMMIT', $commit->query);

        $rollback = $builder->rollback();
        $this->assertSame('ROLLBACK', $rollback->query);
    }

    public function testSavepointDoubleQuotes(): void
    {
        $result = (new Builder())->savepoint('sp1');

        $this->assertSame('SAVEPOINT "sp1"', $result->query);
    }

    public function testReleaseSavepointDoubleQuotes(): void
    {
        $result = (new Builder())->releaseSavepoint('sp1');

        $this->assertSame('RELEASE SAVEPOINT "sp1"', $result->query);
    }

    public function testGroupByWithHaving(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'cnt')
            ->groupBy(['customer_id'])
            ->having([Query::greaterThan('cnt', 5)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS "cnt" FROM "orders" GROUP BY "customer_id" HAVING COUNT(*) > ?', $result->query);
        $this->assertContains(5, $result->bindings);
    }

    public function testGroupByMultipleColumns(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->count('*', 'cnt')
            ->groupBy(['a', 'b'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS "cnt" FROM "sales" GROUP BY "a", "b"', $result->query);
    }

    public function testWhenTrue(): void
    {
        $result = (new Builder())
            ->from('items')
            ->when(true, fn (Builder $b) => $b->limit(5))
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "items" LIMIT ?', $result->query);
        $this->assertContains(5, $result->bindings);
    }

    public function testWhenFalse(): void
    {
        $result = (new Builder())
            ->from('items')
            ->when(false, fn (Builder $b) => $b->limit(5))
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString('LIMIT', $result->query);
    }

    public function testResetClearsCTEs(): void
    {
        $sub = (new Builder())->from('orders');

        $builder = (new Builder())
            ->with('cte', $sub)
            ->from('cte');

        $builder->reset();

        $result = $builder->from('items')->build();
        $this->assertBindingCount($result);
        $this->assertStringNotContainsString('WITH', $result->query);
    }

    public function testResetClearsJsonSets(): void
    {
        $builder = (new Builder())
            ->from('docs')
            ->setJsonAppend('tags', ['new']);

        $builder->reset();

        $result = $builder
            ->from('docs')
            ->set(['name' => 'test'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString('jsonb', $result->query);
    }

    public function testEqualEmptyArrayReturnsFalse(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('x', [])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE 1 = 0', $result->query);
    }

    public function testEqualWithNullOnly(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('x', [null])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "x" IS NULL', $result->query);
    }

    public function testEqualWithNullAndValues(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('x', [1, null])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE ("x" IN (?) OR "x" IS NULL)', $result->query);
        $this->assertContains(1, $result->bindings);
    }

    public function testNotEqualWithNullAndValues(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notEqual('x', [1, null])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE ("x" != ? AND "x" IS NOT NULL)', $result->query);
    }

    public function testAndWithTwoFilters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::and([Query::greaterThan('age', 18), Query::lessThan('age', 65)])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE ("age" > ? AND "age" < ?)', $result->query);
    }

    public function testOrWithTwoFilters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::or([Query::equal('role', ['admin']), Query::equal('role', ['editor'])])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE ("role" IN (?) OR "role" IN (?))', $result->query);
    }

    public function testEmptyAndReturnsTrue(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::and([])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE 1 = 1', $result->query);
    }

    public function testEmptyOrReturnsFalse(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::or([])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE 1 = 0', $result->query);
    }

    public function testBetweenFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::between('age', 18, 65)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "age" BETWEEN ? AND ?', $result->query);
        $this->assertSame([18, 65], $result->bindings);
    }

    public function testNotBetweenFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notBetween('score', 0, 50)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "score" NOT BETWEEN ? AND ?', $result->query);
        $this->assertSame([0, 50], $result->bindings);
    }

    public function testExistsSingleAttribute(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::exists(['name'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE ("name" IS NOT NULL)', $result->query);
    }

    public function testExistsMultipleAttributes(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::exists(['name', 'email'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE ("name" IS NOT NULL AND "email" IS NOT NULL)', $result->query);
    }

    public function testNotExistsSingleAttribute(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notExists(['name'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE ("name" IS NULL)', $result->query);
    }

    public function testRawFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::raw('score > ?', [10])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE score > ?', $result->query);
        $this->assertContains(10, $result->bindings);
    }

    public function testRawFilterEmpty(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::raw('')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE 1 = 1', $result->query);
    }

    public function testStartsWithEscapesPercent(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::startsWith('val', '100%')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "val" ILIKE ?', $result->query);
        $this->assertSame(['100\%%'], $result->bindings);
    }

    public function testEndsWithEscapesUnderscore(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::endsWith('val', 'a_b')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "val" ILIKE ?', $result->query);
        $this->assertSame(['%a\_b'], $result->bindings);
    }

    public function testContainsEscapesBackslash(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::containsString('path', ['a\\b'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "path" ILIKE ?', $result->query);
        $this->assertSame(['%a\\\\b%'], $result->bindings);
    }

    public function testContainsMultipleUsesOr(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::containsString('bio', ['foo', 'bar'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE ("bio" ILIKE ? OR "bio" ILIKE ?)', $result->query);
    }

    public function testContainsAllUsesAnd(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::containsAll('bio', ['foo', 'bar'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE ("bio" ILIKE ? AND "bio" ILIKE ?)', $result->query);
    }

    public function testNotContainsMultipleUsesAnd(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notContains('bio', ['foo', 'bar'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE ("bio" NOT ILIKE ? AND "bio" NOT ILIKE ?)', $result->query);
    }

    public function testDottedIdentifier(): void
    {
        $result = (new Builder())
            ->from('t')
            ->select(['users.name'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT "users"."name" FROM "t"', $result->query);
    }

    public function testMultipleOrderBy(): void
    {
        $result = (new Builder())
            ->from('t')
            ->sortAsc('name')
            ->sortDesc('age')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" ORDER BY "name" ASC, "age" DESC', $result->query);
    }

    public function testDistinctWithSelect(): void
    {
        $result = (new Builder())
            ->from('t')
            ->distinct()
            ->select(['name'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT DISTINCT "name" FROM "t"', $result->query);
    }

    public function testSumWithAlias(): void
    {
        $result = (new Builder())
            ->from('t')
            ->sum('amount', 'total')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT SUM("amount") AS "total" FROM "t"', $result->query);
    }

    public function testMultipleAggregates(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count('*', 'cnt')
            ->sum('amount', 'total')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS "cnt", SUM("amount") AS "total" FROM "t"', $result->query);
    }

    public function testCountWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) FROM "t"', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testRightJoin(): void
    {
        $result = (new Builder())
            ->from('a')
            ->rightJoin('b', 'a.id', 'b.a_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "a" RIGHT JOIN "b" ON "a"."id" = "b"."a_id"', $result->query);
    }

    public function testCrossJoin(): void
    {
        $result = (new Builder())
            ->from('a')
            ->crossJoin('b')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "a" CROSS JOIN "b"', $result->query);
        $this->assertStringNotContainsString(' ON ', $result->query);
    }

    public function testJoinInvalidOperatorThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->from('a')
            ->join('b', 'a.id', 'b.a_id', 'INVALID')
            ->build();
    }

    public function testIsNullFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::isNull('deleted_at')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "deleted_at" IS NULL', $result->query);
    }

    public function testIsNotNullFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::isNotNull('name')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "name" IS NOT NULL', $result->query);
    }

    public function testLessThan(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::lessThan('age', 30)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "age" < ?', $result->query);
        $this->assertSame([30], $result->bindings);
    }

    public function testLessThanEqual(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::lessThanEqual('age', 30)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "age" <= ?', $result->query);
        $this->assertSame([30], $result->bindings);
    }

    public function testGreaterThan(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::greaterThan('score', 50)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "score" > ?', $result->query);
        $this->assertSame([50], $result->bindings);
    }

    public function testGreaterThanEqual(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::greaterThanEqual('score', 50)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "score" >= ?', $result->query);
        $this->assertSame([50], $result->bindings);
    }

    public function testDeleteWithOrderAndLimit(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('status', ['old'])])
            ->sortAsc('id')
            ->limit(100)
            ->delete();
        $this->assertBindingCount($result);

        $this->assertSame('DELETE FROM "t" WHERE "status" IN (?) ORDER BY "id" ASC LIMIT ?', $result->query);
    }

    public function testUpdateWithOrderAndLimit(): void
    {
        $result = (new Builder())
            ->from('t')
            ->set(['status' => 'archived'])
            ->filter([Query::equal('active', [false])])
            ->sortAsc('id')
            ->limit(50)
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE "t" SET "status" = ? WHERE "active" IN (?) ORDER BY "id" ASC LIMIT ?', $result->query);
    }

    public function testVectorOrderBindingOrderWithFiltersAndLimit(): void
    {
        $result = (new Builder())
            ->from('items')
            ->filter([Query::equal('status', ['active'])])
            ->orderByVectorDistance('embedding', [0.1, 0.2], VectorMetric::Cosine)
            ->limit(10)
            ->build();
        $this->assertBindingCount($result);

        // Bindings should be: filter bindings, then vector json, then limit value
        $this->assertSame('active', $result->bindings[0]);
        $vectorJson = '[0.1,0.2]';
        $vectorIdx = array_search($vectorJson, $result->bindings, true);
        $limitIdx = array_search(10, $result->bindings, true);
        $this->assertNotFalse($vectorIdx);
        $this->assertNotFalse($limitIdx);
        $this->assertLessThan($limitIdx, $vectorIdx);
    }

    // Feature 7: insertOrIgnore (PostgreSQL)

    public function testInsertOrIgnore(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'John', 'email' => 'john@example.com'])
            ->insertOrIgnore();

        $this->assertSame(
            'INSERT INTO "users" ("name", "email") VALUES (?, ?) ON CONFLICT DO NOTHING',
            $result->query
        );
        $this->assertSame(['John', 'john@example.com'], $result->bindings);
    }

    // Feature 8: RETURNING clause

    public function testInsertReturning(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'John'])
            ->returning(['id', 'name'])
            ->insert();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT INTO "users" ("name") VALUES (?) RETURNING "id", "name"', $result->query);
    }

    public function testInsertReturningAll(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'John'])
            ->returning()
            ->insert();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT INTO "users" ("name") VALUES (?) RETURNING *', $result->query);
    }

    public function testUpdateReturning(): void
    {
        $result = (new Builder())
            ->from('users')
            ->set(['name' => 'Jane'])
            ->filter([Query::equal('id', [1])])
            ->returning(['id', 'name'])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE "users" SET "name" = ? WHERE "id" IN (?) RETURNING "id", "name"', $result->query);
    }

    public function testDeleteReturning(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('id', [1])])
            ->returning(['id'])
            ->delete();
        $this->assertBindingCount($result);

        $this->assertSame('DELETE FROM "users" WHERE "id" IN (?) RETURNING "id"', $result->query);
    }

    public function testUpsertReturning(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['id' => 1, 'name' => 'John', 'email' => 'john@example.com'])
            ->onConflict(['id'], ['name', 'email'])
            ->returning(['id'])
            ->upsert();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT INTO "users" ("id", "name", "email") VALUES (?, ?, ?) ON CONFLICT ("id") DO UPDATE SET "name" = EXCLUDED."name", "email" = EXCLUDED."email" RETURNING "id"', $result->query);
    }

    public function testInsertOrIgnoreReturning(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'John'])
            ->returning(['id'])
            ->insertOrIgnore();

        $this->assertSame('INSERT INTO "users" ("name") VALUES (?) ON CONFLICT DO NOTHING RETURNING "id"', $result->query);
    }

    // Feature 10: LockingOf (PostgreSQL only)

    public function testForUpdateOf(): void
    {
        $result = (new Builder())
            ->from('users')
            ->forUpdateOf('users')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "users" FOR UPDATE OF "users"', $result->query);
    }

    public function testForShareOf(): void
    {
        $result = (new Builder())
            ->from('users')
            ->forShareOf('users')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "users" FOR SHARE OF "users"', $result->query);
    }

    // Feature 1: Table Aliases (PostgreSQL quotes)

    public function testTableAliasPostgreSQL(): void
    {
        $result = (new Builder())
            ->from('users', 'u')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "users" AS "u"', $result->query);
    }

    public function testJoinAliasPostgreSQL(): void
    {
        $result = (new Builder())
            ->from('users', 'u')
            ->join('orders', 'u.id', 'o.user_id', '=', 'o')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "users" AS "u" JOIN "orders" AS "o" ON "u"."id" = "o"."user_id"', $result->query);
    }

    // Feature 2: Subqueries (PostgreSQL)

    public function testFromSubPostgreSQL(): void
    {
        $sub = (new Builder())->from('orders')->select(['user_id'])->groupBy(['user_id']);
        $result = (new Builder())
            ->fromSub($sub, 'sub')
            ->select(['user_id'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT "user_id" FROM (SELECT "user_id" FROM "orders" GROUP BY "user_id") AS "sub"',
            $result->query
        );
    }

    // Feature 4: countDistinct (PostgreSQL)

    public function testCountDistinctPostgreSQL(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->countDistinct('user_id', 'unique_users')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT COUNT(DISTINCT "user_id") AS "unique_users" FROM "orders"',
            $result->query
        );
    }

    // Feature 9: EXPLAIN (PostgreSQL)

    public function testExplainPostgreSQL(): void
    {
        $result = (new Builder())
            ->from('users')
            ->explain();

        $this->assertStringStartsWith('EXPLAIN SELECT', $result->query);
    }

    public function testExplainAnalyzePostgreSQL(): void
    {
        $result = (new Builder())
            ->from('users')
            ->explain(true);

        $this->assertStringStartsWith('EXPLAIN (ANALYZE) SELECT', $result->query);
    }

    // Feature 10: Locking Variants (PostgreSQL)

    public function testForUpdateSkipLockedPostgreSQL(): void
    {
        $result = (new Builder())
            ->from('users')
            ->forUpdateSkipLocked()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "users" FOR UPDATE SKIP LOCKED', $result->query);
    }

    public function testForUpdateNoWaitPostgreSQL(): void
    {
        $result = (new Builder())
            ->from('users')
            ->forUpdateNoWait()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "users" FOR UPDATE NOWAIT', $result->query);
    }

    // Subquery bindings (PostgreSQL)

    public function testSubqueryBindingOrderPostgreSQL(): void
    {
        $sub = (new Builder())->from('orders')
            ->select(['user_id'])
            ->filter([Query::equal('status', ['completed'])]);

        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('role', ['admin'])])
            ->filterWhereIn('id', $sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['admin', 'completed'], $result->bindings);
    }

    public function testFilterNotExistsPostgreSQL(): void
    {
        $sub = (new Builder())->from('bans')->select(['id']);

        $result = (new Builder())
            ->from('users')
            ->filterNotExists($sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "users" WHERE NOT EXISTS (SELECT "id" FROM "bans")', $result->query);
    }

    // Raw clauses (PostgreSQL)

    public function testOrderByRawPostgreSQL(): void
    {
        $result = (new Builder())
            ->from('users')
            ->orderByRaw('NULLS LAST')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "users" ORDER BY NULLS LAST', $result->query);
    }

    public function testGroupByRawPostgreSQL(): void
    {
        $result = (new Builder())
            ->from('events')
            ->count('*', 'cnt')
            ->groupByRaw('date_trunc(?, "created_at")', ['month'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS "cnt" FROM "events" GROUP BY date_trunc(?, "created_at")', $result->query);
        $this->assertSame(['month'], $result->bindings);
    }

    public function testHavingRawPostgreSQL(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'cnt')
            ->groupBy(['user_id'])
            ->havingRaw('SUM("amount") > ?', [1000])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS "cnt" FROM "orders" GROUP BY "user_id" HAVING SUM("amount") > ?', $result->query);
    }

    public function testWhereRawAppendsFragmentAndBindings(): void
    {
        $result = (new Builder())
            ->from('users')
            ->whereRaw('a = ?', [1])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "users" WHERE a = ?', $result->query);
        $this->assertSame([1], $result->bindings);
    }

    public function testWhereRawCombinesWithFilter(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('b', [2])])
            ->whereRaw('a = ?', [1])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "users" WHERE "b" IN (?) AND a = ?', $result->query);
        $this->assertContains(1, $result->bindings);
        $this->assertContains(2, $result->bindings);
    }

    // JoinWhere (PostgreSQL)

    public function testJoinWherePostgreSQL(): void
    {
        $result = (new Builder())
            ->from('users')
            ->joinWhere('orders', function (JoinBuilder $join): void {
                $join->on('users.id', 'orders.user_id')
                     ->where('orders.amount', '>', 100);
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "users" JOIN "orders" ON "users"."id" = "orders"."user_id" AND orders.amount > ?', $result->query);
        $this->assertSame([100], $result->bindings);
    }

    // Insert or ignore (PostgreSQL)

    public function testInsertOrIgnorePostgreSQL(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'John'])
            ->insertOrIgnore();

        $this->assertSame('INSERT INTO "users" ("name") VALUES (?) ON CONFLICT DO NOTHING', $result->query);
    }

    // RETURNING with specific columns

    public function testReturningSpecificColumns(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'John'])
            ->returning(['id', 'created_at'])
            ->insert();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT INTO "users" ("name") VALUES (?) RETURNING "id", "created_at"', $result->query);
    }

    // Locking OF combined

    public function testForUpdateOfWithFilter(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('id', [1])])
            ->forUpdateOf('users')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "users" WHERE "id" IN (?) FOR UPDATE OF "users"', $result->query);
    }

    // PostgreSQL rename uses ALTER TABLE

    public function testFromSubClearsTablePostgreSQL(): void
    {
        $sub = (new Builder())->from('orders')->select(['id']);

        $result = (new Builder())
            ->fromSub($sub, 'sub')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM (SELECT "id" FROM "orders") AS "sub"', $result->query);
    }

    // countDistinct without alias

    public function testCountDistinctWithoutAliasPostgreSQL(): void
    {
        $result = (new Builder())
            ->from('users')
            ->countDistinct('email')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(DISTINCT "email") FROM "users"', $result->query);
    }

    // Multiple EXISTS subqueries

    public function testMultipleExistsSubqueries(): void
    {
        $sub1 = (new Builder())->from('orders')->select(['id']);
        $sub2 = (new Builder())->from('payments')->select(['id']);

        $result = (new Builder())
            ->from('users')
            ->filterExists($sub1)
            ->filterNotExists($sub2)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "users" WHERE EXISTS (SELECT "id" FROM "orders") AND NOT EXISTS (SELECT "id" FROM "payments")', $result->query);
    }

    // Left join alias PostgreSQL

    public function testLeftJoinAliasPostgreSQL(): void
    {
        $result = (new Builder())
            ->from('users', 'u')
            ->leftJoin('orders', 'u.id', 'o.user_id', '=', 'o')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "users" AS "u" LEFT JOIN "orders" AS "o" ON "u"."id" = "o"."user_id"', $result->query);
    }

    // Cross join alias PostgreSQL

    public function testCrossJoinAliasPostgreSQL(): void
    {
        $result = (new Builder())
            ->from('users')
            ->crossJoin('roles', 'r')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "users" CROSS JOIN "roles" AS "r"', $result->query);
    }

    // ForShare locking variants

    public function testForShareSkipLockedPostgreSQL(): void
    {
        $result = (new Builder())
            ->from('users')
            ->forShareSkipLocked()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "users" FOR SHARE SKIP LOCKED', $result->query);
    }

    public function testForShareNoWaitPostgreSQL(): void
    {
        $result = (new Builder())
            ->from('users')
            ->forShareNoWait()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "users" FOR SHARE NOWAIT', $result->query);
    }

    // Reset clears new properties (PostgreSQL)

    public function testResetPostgreSQL(): void
    {
        $sub = (new Builder())->from('t')->select(['id']);
        $builder = (new Builder())
            ->from('users', 'u')
            ->filterWhereIn('id', $sub)
            ->selectSub($sub, 'cnt')
            ->orderByRaw('random()')
            ->filterExists($sub)
            ->reset();

        $this->expectException(ValidationException::class);
        $builder->build();
    }

    public function testExactSimpleSelect(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name', 'email'])
            ->filter([Query::equal('status', ['active'])])
            ->sortAsc('name')
            ->limit(10)
            ->offset(5)
            ->build();

        $this->assertSame(
            'SELECT "id", "name", "email" FROM "users" WHERE "status" IN (?) ORDER BY "name" ASC LIMIT ? OFFSET ?',
            $result->query
        );
        $this->assertSame(['active', 10, 5], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactSelectWithMultipleFilters(): void
    {
        $result = (new Builder())
            ->from('products')
            ->select(['id', 'name', 'price'])
            ->filter([
                Query::greaterThan('price', 10),
                Query::lessThan('price', 100),
                Query::equal('category', ['electronics']),
                Query::isNotNull('name'),
            ])
            ->build();

        $this->assertSame(
            'SELECT "id", "name", "price" FROM "products" WHERE "price" > ? AND "price" < ? AND "category" IN (?) AND "name" IS NOT NULL',
            $result->query
        );
        $this->assertSame([10, 100, 'electronics'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactMultipleJoins(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['users.id', 'orders.total', 'profiles.bio'])
            ->join('orders', 'users.id', 'orders.user_id')
            ->leftJoin('profiles', 'users.id', 'profiles.user_id')
            ->build();

        $this->assertSame(
            'SELECT "users"."id", "orders"."total", "profiles"."bio" FROM "users" JOIN "orders" ON "users"."id" = "orders"."user_id" LEFT JOIN "profiles" ON "users"."id" = "profiles"."user_id"',
            $result->query
        );
        $this->assertSame([], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactInsertMultipleRows(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'Alice', 'email' => 'alice@test.com'])
            ->set(['name' => 'Bob', 'email' => 'bob@test.com'])
            ->insert();

        $this->assertSame(
            'INSERT INTO "users" ("name", "email") VALUES (?, ?), (?, ?)',
            $result->query
        );
        $this->assertSame(['Alice', 'alice@test.com', 'Bob', 'bob@test.com'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactInsertReturning(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'Alice', 'email' => 'alice@test.com'])
            ->returning(['id'])
            ->insert();

        $this->assertSame(
            'INSERT INTO "users" ("name", "email") VALUES (?, ?) RETURNING "id"',
            $result->query
        );
        $this->assertSame(['Alice', 'alice@test.com'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactUpdateReturning(): void
    {
        $result = (new Builder())
            ->from('users')
            ->set(['name' => 'Updated'])
            ->filter([Query::equal('id', [1])])
            ->returning(['*'])
            ->update();

        $this->assertSame(
            'UPDATE "users" SET "name" = ? WHERE "id" IN (?) RETURNING *',
            $result->query
        );
        $this->assertSame(['Updated', 1], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactDeleteReturning(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('id', [5])])
            ->returning(['id'])
            ->delete();

        $this->assertSame(
            'DELETE FROM "users" WHERE "id" IN (?) RETURNING "id"',
            $result->query
        );
        $this->assertSame([5], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactUpsertOnConflict(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['id' => 1, 'name' => 'Alice', 'email' => 'alice@test.com'])
            ->onConflict(['id'], ['name', 'email'])
            ->upsert();

        $this->assertSame(
            'INSERT INTO "users" ("id", "name", "email") VALUES (?, ?, ?) ON CONFLICT ("id") DO UPDATE SET "name" = EXCLUDED."name", "email" = EXCLUDED."email"',
            $result->query
        );
        $this->assertSame([1, 'Alice', 'alice@test.com'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactUpsertOnConflictReturning(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['id' => 1, 'name' => 'Alice'])
            ->onConflict(['id'], ['name'])
            ->returning(['id', 'name'])
            ->upsert();

        $this->assertSame(
            'INSERT INTO "users" ("id", "name") VALUES (?, ?) ON CONFLICT ("id") DO UPDATE SET "name" = EXCLUDED."name" RETURNING "id", "name"',
            $result->query
        );
        $this->assertSame([1, 'Alice'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactInsertOrIgnore(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['id' => 1, 'name' => 'Alice'])
            ->insertOrIgnore();

        $this->assertSame(
            'INSERT INTO "users" ("id", "name") VALUES (?, ?) ON CONFLICT DO NOTHING',
            $result->query
        );
        $this->assertSame([1, 'Alice'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactVectorSearchCosine(): void
    {
        $result = (new Builder())
            ->from('embeddings')
            ->select(['id', 'title'])
            ->orderByVectorDistance('embedding', [0.1, 0.2, 0.3], VectorMetric::Cosine)
            ->limit(5)
            ->build();

        $this->assertSame(
            'SELECT "id", "title" FROM "embeddings" ORDER BY ("embedding" <=> ?::vector) ASC LIMIT ?',
            $result->query
        );
        $this->assertSame(['[0.1,0.2,0.3]', 5], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactVectorSearchEuclidean(): void
    {
        $result = (new Builder())
            ->from('embeddings')
            ->select(['id', 'title'])
            ->orderByVectorDistance('embedding', [0.5, 0.6], VectorMetric::Euclidean)
            ->limit(10)
            ->build();

        $this->assertSame(
            'SELECT "id", "title" FROM "embeddings" ORDER BY ("embedding" <-> ?::vector) ASC LIMIT ?',
            $result->query
        );
        $this->assertSame(['[0.5,0.6]', 10], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactJsonbContains(): void
    {
        $result = (new Builder())
            ->from('documents')
            ->select(['id', 'title'])
            ->filterJsonContains('tags', 'php')
            ->build();

        $this->assertSame(
            'SELECT "id", "title" FROM "documents" WHERE "tags" @> ?::jsonb',
            $result->query
        );
        $this->assertSame(['"php"'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactJsonbOverlaps(): void
    {
        $result = (new Builder())
            ->from('documents')
            ->filterJsonOverlaps('tags', ['php', 'js'])
            ->build();

        $this->assertSame(
            'SELECT * FROM "documents" WHERE ("tags" @> ?::jsonb OR "tags" @> ?::jsonb)',
            $result->query
        );
        $this->assertSame(['"php"', '"js"'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactJsonPath(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->filterJsonPath('metadata', 'key', '=', 'value')
            ->build();

        $this->assertSame(
            'SELECT "id", "name" FROM "users" WHERE "metadata"->>\'key\' = ?',
            $result->query
        );
        $this->assertSame(['value'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactCte(): void
    {
        $cteQuery = (new Builder())
            ->from('orders')
            ->select(['user_id', 'total'])
            ->filter([Query::greaterThan('total', 100)]);

        $result = (new Builder())
            ->with('big_orders', $cteQuery)
            ->from('big_orders')
            ->select(['user_id', 'total'])
            ->build();

        $this->assertSame(
            'WITH "big_orders" AS (SELECT "user_id", "total" FROM "orders" WHERE "total" > ?) SELECT "user_id", "total" FROM "big_orders"',
            $result->query
        );
        $this->assertSame([100], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactWindowFunction(): void
    {
        $result = (new Builder())
            ->from('employees')
            ->select(['id', 'name', 'department'])
            ->selectWindow('ROW_NUMBER()', 'row_num', ['department'], ['-salary'])
            ->build();

        $this->assertSame(
            'SELECT "id", "name", "department", ROW_NUMBER() OVER (PARTITION BY "department" ORDER BY "salary" DESC) AS "row_num" FROM "employees"',
            $result->query
        );
        $this->assertSame([], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactUnion(): void
    {
        $second = (new Builder())
            ->from('archived_users')
            ->select(['id', 'name']);

        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->union($second)
            ->build();

        $this->assertSame(
            '(SELECT "id", "name" FROM "users") UNION (SELECT "id", "name" FROM "archived_users")',
            $result->query
        );
        $this->assertSame([], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactForUpdateOf(): void
    {
        $result = (new Builder())
            ->from('accounts')
            ->select(['id', 'balance'])
            ->filter([Query::equal('id', [42])])
            ->forUpdateOf('accounts')
            ->build();

        $this->assertSame(
            'SELECT "id", "balance" FROM "accounts" WHERE "id" IN (?) FOR UPDATE OF "accounts"',
            $result->query
        );
        $this->assertSame([42], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactForShareSkipLocked(): void
    {
        $result = (new Builder())
            ->from('jobs')
            ->select(['id', 'payload'])
            ->filter([Query::equal('status', ['pending'])])
            ->forShareSkipLocked()
            ->limit(1)
            ->build();

        $this->assertSame(
            'SELECT "id", "payload" FROM "jobs" WHERE "status" IN (?) LIMIT ? FOR SHARE SKIP LOCKED',
            $result->query
        );
        $this->assertSame(['pending', 1], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAggregationGroupByHaving(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'order_count')
            ->groupBy(['user_id'])
            ->having([Query::greaterThan('order_count', 5)])
            ->build();

        $this->assertSame(
            'SELECT COUNT(*) AS "order_count" FROM "orders" GROUP BY "user_id" HAVING COUNT(*) > ?',
            $result->query
        );
        $this->assertSame([5], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactSubqueryWhereIn(): void
    {
        $subquery = (new Builder())
            ->from('orders')
            ->select(['user_id'])
            ->filter([Query::greaterThan('total', 500)]);

        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->filterWhereIn('id', $subquery)
            ->build();

        $this->assertSame(
            'SELECT "id", "name" FROM "users" WHERE "id" IN (SELECT "user_id" FROM "orders" WHERE "total" > ?)',
            $result->query
        );
        $this->assertSame([500], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactExistsSubquery(): void
    {
        $subquery = (new Builder())
            ->from('orders')
            ->select(['id'])
            ->filter([Query::equal('orders.user_id', [1])]);

        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->filterExists($subquery)
            ->build();

        $this->assertSame(
            'SELECT "id", "name" FROM "users" WHERE EXISTS (SELECT "id" FROM "orders" WHERE "orders"."user_id" IN (?))',
            $result->query
        );
        $this->assertSame([1], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactNestedWhereGroups(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->filter([
                Query::equal('status', ['active']),
                Query::or([
                    Query::greaterThan('age', 18),
                    Query::equal('role', ['admin']),
                ]),
            ])
            ->build();

        $this->assertSame(
            'SELECT "id", "name" FROM "users" WHERE "status" IN (?) AND ("age" > ? OR "role" IN (?))',
            $result->query
        );
        $this->assertSame(['active', 18, 'admin'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactDistinctWithOffset(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['name', 'email'])
            ->distinct()
            ->sortAsc('name')
            ->limit(20)
            ->offset(10)
            ->build();

        $this->assertSame(
            'SELECT DISTINCT "name", "email" FROM "users" ORDER BY "name" ASC LIMIT ? OFFSET ?',
            $result->query
        );
        $this->assertSame([20, 10], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedWhenTrue(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->when(true, function (Builder $b) {
                $b->filter([Query::equal('status', ['active'])]);
            })
            ->build();

        $this->assertSame(
            'SELECT "id", "name" FROM "users" WHERE "status" IN (?)',
            $result->query
        );
        $this->assertSame(['active'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedWhenFalse(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->when(false, function (Builder $b) {
                $b->filter([Query::equal('status', ['active'])]);
            })
            ->build();

        $this->assertSame(
            'SELECT "id", "name" FROM "users"',
            $result->query
        );
        $this->assertSame([], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedExplain(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->filter([Query::equal('status', ['active'])])
            ->explain();

        $this->assertSame(
            'EXPLAIN SELECT "id", "name" FROM "users" WHERE "status" IN (?)',
            $result->query
        );
        $this->assertSame(['active'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedExplainAnalyze(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->filter([Query::greaterThan('age', 18)])
            ->explain(true);

        $this->assertSame(
            'EXPLAIN (ANALYZE) SELECT "id", "name" FROM "users" WHERE "age" > ?',
            $result->query
        );
        $this->assertSame([18], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedCursorAfterWithFilters(): void
    {
        $result = (new Builder())
            ->from('posts')
            ->select(['id', 'title'])
            ->filter([Query::equal('status', ['published'])])
            ->cursorAfter('abc123')
            ->limit(10)
            ->build();

        $this->assertSame(
            'SELECT "id", "title" FROM "posts" WHERE "status" IN (?) AND "_cursor" > ? LIMIT ?',
            $result->query
        );
        $this->assertSame(['published', 'abc123', 10], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedMultipleCtes(): void
    {
        $cteA = (new Builder())
            ->from('orders')
            ->select(['customer_id'])
            ->filter([Query::greaterThan('total', 100)]);

        $cteB = (new Builder())
            ->from('customers')
            ->select(['id', 'name'])
            ->filter([Query::equal('active', [true])]);

        $result = (new Builder())
            ->with('a', $cteA)
            ->with('b', $cteB)
            ->from('a')
            ->select(['customer_id'])
            ->join('b', 'a.customer_id', 'b.id')
            ->build();

        $this->assertSame(
            'WITH "a" AS (SELECT "customer_id" FROM "orders" WHERE "total" > ?), "b" AS (SELECT "id", "name" FROM "customers" WHERE "active" IN (?)) SELECT "customer_id" FROM "a" JOIN "b" ON "a"."customer_id" = "b"."id"',
            $result->query
        );
        $this->assertSame([100, true], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedMultipleWindowFunctions(): void
    {
        $result = (new Builder())
            ->from('employees')
            ->select(['id', 'name', 'department', 'salary'])
            ->selectWindow('ROW_NUMBER()', 'row_num', ['department'], ['salary'])
            ->selectWindow('RANK()', 'salary_rank', ['department'], ['-salary'])
            ->build();

        $this->assertSame(
            'SELECT "id", "name", "department", "salary", ROW_NUMBER() OVER (PARTITION BY "department" ORDER BY "salary" ASC) AS "row_num", RANK() OVER (PARTITION BY "department" ORDER BY "salary" DESC) AS "salary_rank" FROM "employees"',
            $result->query
        );
        $this->assertSame([], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedUnionWithOrderAndLimit(): void
    {
        $second = (new Builder())
            ->from('archived_users')
            ->select(['id', 'name']);

        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->sortAsc('name')
            ->limit(50)
            ->union($second)
            ->build();

        $this->assertSame(
            '(SELECT "id", "name" FROM "users" ORDER BY "name" ASC LIMIT ?) UNION (SELECT "id", "name" FROM "archived_users")',
            $result->query
        );
        $this->assertSame([50], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedDeeplyNestedConditions(): void
    {
        $result = (new Builder())
            ->from('products')
            ->select(['id', 'name'])
            ->filter([
                Query::and([
                    Query::greaterThan('price', 10),
                    Query::or([
                        Query::equal('category', ['electronics']),
                        Query::and([
                            Query::equal('brand', ['acme']),
                            Query::lessThan('stock', 5),
                        ]),
                    ]),
                ]),
            ])
            ->build();

        $this->assertSame(
            'SELECT "id", "name" FROM "products" WHERE ("price" > ? AND ("category" IN (?) OR ("brand" IN (?) AND "stock" < ?)))',
            $result->query
        );
        $this->assertSame([10, 'electronics', 'acme', 5], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedForUpdateOfWithJoin(): void
    {
        $result = (new Builder())
            ->from('accounts')
            ->select(['accounts.id', 'accounts.balance', 'users.name'])
            ->join('users', 'accounts.user_id', 'users.id')
            ->filter([Query::greaterThan('accounts.balance', 0)])
            ->forUpdateOf('accounts')
            ->build();

        $this->assertSame(
            'SELECT "accounts"."id", "accounts"."balance", "users"."name" FROM "accounts" JOIN "users" ON "accounts"."user_id" = "users"."id" WHERE "accounts"."balance" > ? FOR UPDATE OF "accounts"',
            $result->query
        );
        $this->assertSame([0], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedForShareOf(): void
    {
        $result = (new Builder())
            ->from('inventory')
            ->select(['id', 'quantity'])
            ->filter([Query::equal('warehouse', ['main'])])
            ->forShareOf('inventory')
            ->build();

        $this->assertSame(
            'SELECT "id", "quantity" FROM "inventory" WHERE "warehouse" IN (?) FOR SHARE OF "inventory"',
            $result->query
        );
        $this->assertSame(['main'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedConflictSetRaw(): void
    {
        $result = (new Builder())
            ->from('counters')
            ->set(['id' => 'page_views', 'count' => 1])
            ->onConflict(['id'], ['count'])
            ->conflictSetRaw('count', '"counters"."count" + EXCLUDED."count"')
            ->upsert();

        $this->assertSame(
            'INSERT INTO "counters" ("id", "count") VALUES (?, ?) ON CONFLICT ("id") DO UPDATE SET "count" = "counters"."count" + EXCLUDED."count"',
            $result->query
        );
        $this->assertSame(['page_views', 1], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedUpsertReturningAll(): void
    {
        $result = (new Builder())
            ->from('settings')
            ->set(['key' => 'theme', 'value' => 'dark'])
            ->onConflict(['key'], ['value'])
            ->returning(['*'])
            ->upsert();

        $this->assertSame(
            'INSERT INTO "settings" ("key", "value") VALUES (?, ?) ON CONFLICT ("key") DO UPDATE SET "value" = EXCLUDED."value" RETURNING *',
            $result->query
        );
        $this->assertSame(['theme', 'dark'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedDeleteReturningMultiple(): void
    {
        $result = (new Builder())
            ->from('sessions')
            ->filter([Query::lessThan('expires_at', '2024-01-01')])
            ->returning(['id', 'user_id'])
            ->delete();

        $this->assertSame(
            'DELETE FROM "sessions" WHERE "expires_at" < ? RETURNING "id", "user_id"',
            $result->query
        );
        $this->assertSame(['2024-01-01'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedSetJsonAppend(): void
    {
        $result = (new Builder())
            ->from('users')
            ->setJsonAppend('tags', ['vip'])
            ->filter([Query::equal('id', [1])])
            ->update();

        $this->assertSame(
            'UPDATE "users" SET "tags" = COALESCE("tags", \'[]\'::jsonb) || ?::jsonb WHERE "id" IN (?)',
            $result->query
        );
        $this->assertSame(['["vip"]', 1], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedSetJsonPrepend(): void
    {
        $result = (new Builder())
            ->from('users')
            ->setJsonPrepend('tags', ['urgent'])
            ->filter([Query::equal('id', [2])])
            ->update();

        $this->assertSame(
            'UPDATE "users" SET "tags" = ?::jsonb || COALESCE("tags", \'[]\'::jsonb) WHERE "id" IN (?)',
            $result->query
        );
        $this->assertSame(['["urgent"]', 2], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedSetJsonInsert(): void
    {
        $result = (new Builder())
            ->from('users')
            ->setJsonInsert('tags', 0, 'first')
            ->filter([Query::equal('id', [3])])
            ->update();

        $this->assertSame(
            'UPDATE "users" SET "tags" = jsonb_insert("tags", \'{0}\', ?::jsonb) WHERE "id" IN (?)',
            $result->query
        );
        $this->assertSame(['"first"', 3], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedSetJsonRemove(): void
    {
        $result = (new Builder())
            ->from('users')
            ->setJsonRemove('tags', 'obsolete')
            ->filter([Query::equal('id', [4])])
            ->update();

        $this->assertSame(
            'UPDATE "users" SET "tags" = "tags" - ? WHERE "id" IN (?)',
            $result->query
        );
        $this->assertSame(['"obsolete"', 4], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedSetJsonIntersect(): void
    {
        $result = (new Builder())
            ->from('users')
            ->setJsonIntersect('tags', ['a', 'b'])
            ->filter([Query::equal('id', [5])])
            ->update();

        $this->assertSame(
            'UPDATE "users" SET "tags" = (SELECT jsonb_agg(elem) FROM jsonb_array_elements("tags") AS elem WHERE elem <@ ?::jsonb) WHERE "id" IN (?)',
            $result->query
        );
        $this->assertSame(['["a","b"]', 5], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedSetJsonDiff(): void
    {
        $result = (new Builder())
            ->from('users')
            ->setJsonDiff('tags', ['x', 'y'])
            ->filter([Query::equal('id', [6])])
            ->update();

        $this->assertSame(
            'UPDATE "users" SET "tags" = (SELECT COALESCE(jsonb_agg(elem), \'[]\'::jsonb) FROM jsonb_array_elements("tags") AS elem WHERE NOT elem <@ ?::jsonb) WHERE "id" IN (?)',
            $result->query
        );
        $this->assertSame(['["x","y"]', 6], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedSetJsonUnique(): void
    {
        $result = (new Builder())
            ->from('users')
            ->setJsonUnique('tags')
            ->filter([Query::equal('id', [7])])
            ->update();

        $this->assertSame(
            'UPDATE "users" SET "tags" = (SELECT jsonb_agg(DISTINCT elem) FROM jsonb_array_elements("tags") AS elem) WHERE "id" IN (?)',
            $result->query
        );
        $this->assertSame([7], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedEmptyInClause(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id'])
            ->filter([Query::equal('status', [])])
            ->build();

        $this->assertSame(
            'SELECT "id" FROM "users" WHERE 1 = 0',
            $result->query
        );
        $this->assertSame([], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedEmptyAndGroup(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id'])
            ->filter([Query::and([])])
            ->build();

        $this->assertSame(
            'SELECT "id" FROM "users" WHERE 1 = 1',
            $result->query
        );
        $this->assertSame([], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedEmptyOrGroup(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id'])
            ->filter([Query::or([])])
            ->build();

        $this->assertSame(
            'SELECT "id" FROM "users" WHERE 1 = 0',
            $result->query
        );
        $this->assertSame([], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedVectorSearchWithFilters(): void
    {
        $result = (new Builder())
            ->from('documents')
            ->select(['id', 'title'])
            ->filter([Query::equal('status', ['published'])])
            ->orderByVectorDistance('embedding', [0.1, 0.2, 0.3], VectorMetric::Cosine)
            ->limit(5)
            ->build();

        $this->assertSame(
            'SELECT "id", "title" FROM "documents" WHERE "status" IN (?) ORDER BY ("embedding" <=> ?::vector) ASC LIMIT ?',
            $result->query
        );
        $this->assertSame(['published', '[0.1,0.2,0.3]', 5], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testSearchEmptyTermReturnsNoMatch(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::search('body', '   ')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE 1 = 0', $result->query);
    }

    public function testNotSearchEmptyTermReturnsAllMatch(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notSearch('body', '   ')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE 1 = 1', $result->query);
    }

    public function testSearchExactTermWrapsInQuotes(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::search('body', '"exact phrase"')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame("SELECT * FROM \"t\" WHERE to_tsvector(regexp_replace(\"body\", '[^\\w]+', ' ', 'g')) @@ websearch_to_tsquery(?)", $result->query);
        $this->assertSame(['"exact phrase"'], $result->bindings);
    }

    public function testSearchSpecialCharsAreSanitized(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::search('body', '@+hello-world*')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['hello or world'], $result->bindings);
    }

    public function testUpsertConflictSetRawWithBindings(): void
    {
        $result = (new Builder())
            ->from('counters')
            ->set(['id' => 'views', 'count' => 1])
            ->onConflict(['id'], ['count'])
            ->conflictSetRaw('count', '"counters"."count" + ?', [1])
            ->upsert();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT INTO "counters" ("id", "count") VALUES (?, ?) ON CONFLICT ("id") DO UPDATE SET "count" = "counters"."count" + ?', $result->query);
    }

    public function testTableSampleBernoulli(): void
    {
        $result = (new Builder())
            ->from('users')
            ->tablesample(10.0, 'BERNOULLI')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "users" TABLESAMPLE BERNOULLI(10)', $result->query);
    }

    public function testTableSampleSystem(): void
    {
        $result = (new Builder())
            ->from('users')
            ->tablesample(25.0, 'system')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "users" TABLESAMPLE SYSTEM(25)', $result->query);
    }

    public function testImplementsTableSampling(): void
    {
        $this->assertInstanceOf(TableSampling::class, new Builder());
    }

    public function testTableSampleRejectsUnknownMethod(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid TABLESAMPLE method');

        (new Builder())
            ->from('users')
            ->tablesample(10.0, 'DROP TABLE');
    }

    public function testExplainRejectsUnknownFormat(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid EXPLAIN format');

        (new Builder())
            ->from('users')
            ->explain(format: 'csv');
    }

    public function testImplementsConditionalAggregates(): void
    {
        $this->assertInstanceOf(ConditionalAggregates::class, new Builder());
    }

    public function testImplementsMerge(): void
    {
        $this->assertInstanceOf(Merge::class, new Builder());
    }

    public function testImplementsLateralJoins(): void
    {
        $this->assertInstanceOf(LateralJoins::class, new Builder());
    }

    public function testImplementsFullOuterJoins(): void
    {
        $this->assertInstanceOf(FullOuterJoins::class, new Builder());
    }

    public function testUpdateFromBasic(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->set(['status' => 'shipped'])
            ->updateFrom('shipments', 's')
            ->updateFromWhere('orders.id = s.order_id')
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE "orders" SET "status" = ? FROM "shipments" AS "s" WHERE orders.id = s.order_id', $result->query);
    }

    public function testUpdateFromWithWhereFilter(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->set(['status' => 'shipped'])
            ->updateFrom('shipments')
            ->updateFromWhere('orders.id = shipments.order_id')
            ->filter([Query::equal('orders.active', [true])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE "orders" SET "status" = ? FROM "shipments" WHERE "orders"."active" IN (?) AND orders.id = shipments.order_id', $result->query);
    }

    public function testUpdateFromWithBindings(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->set(['status' => 'shipped'])
            ->updateFrom('shipments', 's')
            ->updateFromWhere('orders.id = s.order_id AND s.region = ?', 'US')
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE "orders" SET "status" = ? FROM "shipments" AS "s" WHERE orders.id = s.order_id AND s.region = ?', $result->query);
        $this->assertContains('US', $result->bindings);
    }

    public function testUpdateFromWithoutAliasOrCondition(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->set(['status' => 'done'])
            ->updateFrom('inventory')
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE "orders" SET "status" = ? FROM "inventory"', $result->query);
    }

    public function testUpdateFromReturning(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->set(['status' => 'shipped'])
            ->updateFrom('shipments', 's')
            ->updateFromWhere('orders.id = s.order_id')
            ->returning(['orders.id'])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE "orders" SET "status" = ? FROM "shipments" AS "s" WHERE orders.id = s.order_id RETURNING "orders"."id"', $result->query);
    }

    public function testUpdateFromNoAssignmentsThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->from('orders')
            ->updateFrom('shipments')
            ->update();
    }

    public function testDeleteUsingBasic(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->deleteUsing('old_orders', 'orders.id = old_orders.id')
            ->delete();
        $this->assertBindingCount($result);

        $this->assertSame('DELETE FROM "orders" USING "old_orders" WHERE orders.id = old_orders.id', $result->query);
    }

    public function testDeleteUsingWithBindings(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->deleteUsing('expired', 'orders.id = expired.id AND expired.reason = ?', 'timeout')
            ->delete();
        $this->assertBindingCount($result);

        $this->assertSame('DELETE FROM "orders" USING "expired" WHERE orders.id = expired.id AND expired.reason = ?', $result->query);
        $this->assertContains('timeout', $result->bindings);
    }

    public function testDeleteUsingWithFilterCombined(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->deleteUsing('expired', 'orders.id = expired.id')
            ->filter([Query::equal('orders.status', ['cancelled'])])
            ->delete();
        $this->assertBindingCount($result);

        $this->assertSame('DELETE FROM "orders" USING "expired" WHERE "orders"."status" IN (?) AND orders.id = expired.id', $result->query);
    }

    public function testDeleteUsingReturning(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->deleteUsing('expired', 'orders.id = expired.id')
            ->returning(['orders.id'])
            ->delete();
        $this->assertBindingCount($result);

        $this->assertSame('DELETE FROM "orders" USING "expired" WHERE orders.id = expired.id RETURNING "orders"."id"', $result->query);
    }

    public function testDeleteUsingWithoutCondition(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->deleteUsing('old_orders', '')
            ->filter([Query::equal('status', ['old'])])
            ->delete();
        $this->assertBindingCount($result);

        $this->assertSame('DELETE FROM "orders" USING "old_orders" WHERE "status" IN (?)', $result->query);
    }

    public function testUpsertSelectReturning(): void
    {
        $source = (new Builder())
            ->from('staging')
            ->select(['id', 'name', 'email']);

        $result = (new Builder())
            ->into('users')
            ->fromSelect(['id', 'name', 'email'], $source)
            ->onConflict(['id'], ['name', 'email'])
            ->returning(['id'])
            ->upsertSelect();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT INTO "users" ("id", "name", "email") SELECT "id", "name", "email" FROM "staging" ON CONFLICT ("id") DO UPDATE SET "name" = EXCLUDED."name", "email" = EXCLUDED."email" RETURNING "id"', $result->query);
    }

    public function testCountWhenFilter(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->countWhen('status = ?', 'active_count', 'active')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) FILTER (WHERE status = ?) AS "active_count" FROM "orders"', $result->query);
        $this->assertSame(['active'], $result->bindings);
    }

    public function testCountWhenWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->countWhen('status = ?', '', 'active')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) FILTER (WHERE status = ?) FROM "orders"', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testSumWhenFilter(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->sumWhen('amount', 'status = ?', 'active_total', 'active')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT SUM("amount") FILTER (WHERE status = ?) AS "active_total" FROM "orders"', $result->query);
        $this->assertSame(['active'], $result->bindings);
    }

    public function testSumWhenWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->sumWhen('amount', 'status = ?', '', 'active')
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testAvgWhenFilter(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->avgWhen('amount', 'status = ?', 'avg_active', 'active')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT AVG("amount") FILTER (WHERE status = ?) AS "avg_active" FROM "orders"', $result->query);
    }

    public function testAvgWhenWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->avgWhen('amount', 'status = ?', '', 'active')
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testMinWhenFilter(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->minWhen('amount', 'status = ?', 'min_active', 'active')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT MIN("amount") FILTER (WHERE status = ?) AS "min_active" FROM "orders"', $result->query);
    }

    public function testMinWhenWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->minWhen('amount', 'status = ?', '', 'active')
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testMaxWhenFilter(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->maxWhen('amount', 'status = ?', 'max_active', 'active')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT MAX("amount") FILTER (WHERE status = ?) AS "max_active" FROM "orders"', $result->query);
    }

    public function testMaxWhenWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->maxWhen('amount', 'status = ?', '', 'active')
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testMergeIntoBasic(): void
    {
        $source = (new Builder())
            ->from('staging')
            ->select(['id', 'name', 'email']);

        $result = (new Builder())
            ->mergeInto('users')
            ->using($source, 'src')
            ->on('users.id = src.id')
            ->whenMatched('UPDATE SET name = src.name, email = src.email')
            ->whenNotMatched('INSERT (id, name, email) VALUES (src.id, src.name, src.email)')
            ->executeMerge();
        $this->assertBindingCount($result);

        $this->assertSame('MERGE INTO "users" USING (SELECT "id", "name", "email" FROM "staging") AS "src" ON users.id = src.id WHEN MATCHED THEN UPDATE SET name = src.name, email = src.email WHEN NOT MATCHED THEN INSERT (id, name, email) VALUES (src.id, src.name, src.email)', $result->query);
    }

    public function testMergeWithBindings(): void
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

        $this->assertContains('pending', $result->bindings);
    }

    public function testMergeWithConditionBindings(): void
    {
        $source = (new Builder())->from('staging');

        $result = (new Builder())
            ->mergeInto('users')
            ->using($source, 'src')
            ->on('users.id = src.id AND src.region = ?', 'US')
            ->whenMatched('UPDATE SET name = src.name')
            ->executeMerge();
        $this->assertBindingCount($result);

        $this->assertContains('US', $result->bindings);
    }

    public function testMergeWithClauseBindings(): void
    {
        $source = (new Builder())->from('staging');

        $result = (new Builder())
            ->mergeInto('users')
            ->using($source, 'src')
            ->on('users.id = src.id')
            ->whenMatched('UPDATE SET count = users.count + ?', 1)
            ->whenNotMatched('INSERT (id, count) VALUES (src.id, ?)', 1)
            ->executeMerge();
        $this->assertBindingCount($result);

        $this->assertSame([1, 1], $result->bindings);
    }

    public function testMergeWithoutTargetThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())->executeMerge();
    }

    public function testMergeWithoutSourceThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->mergeInto('users')
            ->executeMerge();
    }

    public function testMergeWithoutConditionThrows(): void
    {
        $this->expectException(ValidationException::class);

        $source = (new Builder())->from('staging');
        (new Builder())
            ->mergeInto('users')
            ->using($source, 'src')
            ->executeMerge();
    }

    public function testJoinLateral(): void
    {
        $sub = (new Builder())
            ->from('orders')
            ->select(['total'])
            ->filter([Query::greaterThan('total', 100)])
            ->limit(5);

        $result = (new Builder())
            ->from('users')
            ->joinLateral($sub, 'latest_orders')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "users" JOIN LATERAL (SELECT "total" FROM "orders" WHERE "total" > ? LIMIT ?) AS "latest_orders" ON true', $result->query);
    }

    public function testLeftJoinLateral(): void
    {
        $sub = (new Builder())
            ->from('orders')
            ->select(['total'])
            ->limit(3);

        $result = (new Builder())
            ->from('users')
            ->leftJoinLateral($sub, 'recent_orders')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "users" LEFT JOIN LATERAL (SELECT "total" FROM "orders" LIMIT ?) AS "recent_orders" ON true', $result->query);
    }

    public function testJoinLateralWithType(): void
    {
        $sub = (new Builder())->from('orders')->select(['id']);

        $result = (new Builder())
            ->from('users')
            ->joinLateral($sub, 'o', JoinType::Left)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "users" LEFT JOIN LATERAL (SELECT "id" FROM "orders") AS "o" ON true', $result->query);
    }

    public function testFullOuterJoin(): void
    {
        $result = (new Builder())
            ->from('users')
            ->fullOuterJoin('orders', 'users.id', 'orders.user_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "users" FULL OUTER JOIN "orders" ON "users"."id" = "orders"."user_id"', $result->query);
    }

    public function testFullOuterJoinWithAlias(): void
    {
        $result = (new Builder())
            ->from('users')
            ->fullOuterJoin('orders', 'users.id', 'o.user_id', '=', 'o')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "users" FULL OUTER JOIN "orders" AS "o" ON "users"."id" = "o"."user_id"', $result->query);
    }

    public function testExplainVerbose(): void
    {
        $result = (new Builder())
            ->from('users')
            ->explain(verbose: true);

        $this->assertStringStartsWith('EXPLAIN (VERBOSE) SELECT', $result->query);
    }

    public function testExplainBuffers(): void
    {
        $result = (new Builder())
            ->from('users')
            ->explain(buffers: true);

        $this->assertStringStartsWith('EXPLAIN (BUFFERS) SELECT', $result->query);
    }

    public function testExplainFormat(): void
    {
        $result = (new Builder())
            ->from('users')
            ->explain(format: 'json');

        $this->assertStringStartsWith('EXPLAIN (FORMAT JSON) SELECT', $result->query);
    }

    public function testExplainAllOptions(): void
    {
        $result = (new Builder())
            ->from('users')
            ->explain(analyze: true, verbose: true, buffers: true, format: 'yaml');

        $this->assertStringStartsWith('EXPLAIN (ANALYZE, VERBOSE, BUFFERS, FORMAT YAML)', $result->query);
    }

    public function testObjectFilterNestedEqual(): void
    {
        $query = Query::equal('metadata.key', ['value']);
        $query->setAttributeType(ColumnType::Object->value);

        $result = (new Builder())
            ->from('t')
            ->filter([$query])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "metadata"->>\'key\' IN (?)', $result->query);
    }

    public function testObjectFilterNestedNotEqual(): void
    {
        $query = Query::notEqual('metadata.key', ['a', 'b']);
        $query->setAttributeType(ColumnType::Object->value);

        $result = (new Builder())
            ->from('t')
            ->filter([$query])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "metadata"->>\'key\' NOT IN (?, ?)', $result->query);
    }

    public function testObjectFilterNestedLessThan(): void
    {
        $query = Query::lessThan('data.score', 50);
        $query->setAttributeType(ColumnType::Object->value);

        $result = (new Builder())
            ->from('t')
            ->filter([$query])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "data"->>\'score\' < ?', $result->query);
    }

    public function testObjectFilterNestedLessThanEqual(): void
    {
        $query = Query::lessThanEqual('data.score', 50);
        $query->setAttributeType(ColumnType::Object->value);

        $result = (new Builder())
            ->from('t')
            ->filter([$query])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "data"->>\'score\' <= ?', $result->query);
    }

    public function testObjectFilterNestedGreaterThan(): void
    {
        $query = Query::greaterThan('data.score', 50);
        $query->setAttributeType(ColumnType::Object->value);

        $result = (new Builder())
            ->from('t')
            ->filter([$query])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "data"->>\'score\' > ?', $result->query);
    }

    public function testObjectFilterNestedGreaterThanEqual(): void
    {
        $query = Query::greaterThanEqual('data.score', 50);
        $query->setAttributeType(ColumnType::Object->value);

        $result = (new Builder())
            ->from('t')
            ->filter([$query])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "data"->>\'score\' >= ?', $result->query);
    }

    public function testObjectFilterNestedStartsWith(): void
    {
        $query = Query::startsWith('data.name', 'foo');
        $query->setAttributeType(ColumnType::Object->value);

        $result = (new Builder())
            ->from('t')
            ->filter([$query])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "data"->>\'name\' ILIKE ?', $result->query);
    }

    public function testObjectFilterNestedNotStartsWith(): void
    {
        $query = Query::notStartsWith('data.name', 'foo');
        $query->setAttributeType(ColumnType::Object->value);

        $result = (new Builder())
            ->from('t')
            ->filter([$query])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "data"->>\'name\' NOT ILIKE ?', $result->query);
    }

    public function testObjectFilterNestedEndsWith(): void
    {
        $query = Query::endsWith('data.name', 'bar');
        $query->setAttributeType(ColumnType::Object->value);

        $result = (new Builder())
            ->from('t')
            ->filter([$query])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "data"->>\'name\' ILIKE ?', $result->query);
    }

    public function testObjectFilterNestedNotEndsWith(): void
    {
        $query = Query::notEndsWith('data.name', 'bar');
        $query->setAttributeType(ColumnType::Object->value);

        $result = (new Builder())
            ->from('t')
            ->filter([$query])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "data"->>\'name\' NOT ILIKE ?', $result->query);
    }

    public function testObjectFilterNestedContains(): void
    {
        $query = Query::containsString('data.name', ['mid']);
        $query->setAttributeType(ColumnType::Object->value);

        $result = (new Builder())
            ->from('t')
            ->filter([$query])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "data"->>\'name\' ILIKE ?', $result->query);
    }

    public function testObjectFilterNestedNotContains(): void
    {
        $query = Query::notContains('data.name', ['mid']);
        $query->setAttributeType(ColumnType::Object->value);

        $result = (new Builder())
            ->from('t')
            ->filter([$query])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "data"->>\'name\' NOT ILIKE ?', $result->query);
    }

    public function testObjectFilterNestedIsNull(): void
    {
        $query = Query::isNull('data.value');
        $query->setAttributeType(ColumnType::Object->value);

        $result = (new Builder())
            ->from('t')
            ->filter([$query])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "data"->>\'value\' IS NULL', $result->query);
    }

    public function testObjectFilterNestedIsNotNull(): void
    {
        $query = Query::isNotNull('data.value');
        $query->setAttributeType(ColumnType::Object->value);

        $result = (new Builder())
            ->from('t')
            ->filter([$query])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "data"->>\'value\' IS NOT NULL', $result->query);
    }

    public function testObjectFilterTopLevelEqual(): void
    {
        $query = Query::equal('metadata', [['key' => 'val']]);
        $query->setAttributeType(ColumnType::Object->value);

        $result = (new Builder())
            ->from('t')
            ->filter([$query])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE ("metadata" @> ?::jsonb)', $result->query);
    }

    public function testObjectFilterTopLevelNotEqual(): void
    {
        $query = Query::notEqual('metadata', [['key' => 'val']]);
        $query->setAttributeType(ColumnType::Object->value);

        $result = (new Builder())
            ->from('t')
            ->filter([$query])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE (NOT ("metadata" @> ?::jsonb))', $result->query);
    }

    public function testObjectFilterTopLevelContains(): void
    {
        $query = Query::containsString('tags', [['key' => 'val']]);
        $query->setAttributeType(ColumnType::Object->value);

        $result = (new Builder())
            ->from('t')
            ->filter([$query])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE ("tags" @> ?::jsonb)', $result->query);
    }

    public function testObjectFilterTopLevelStartsWith(): void
    {
        $query = Query::startsWith('metadata', 'foo');
        $query->setAttributeType(ColumnType::Object->value);

        $result = (new Builder())
            ->from('t')
            ->filter([$query])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "metadata"::text ILIKE ?', $result->query);
    }

    public function testObjectFilterTopLevelNotStartsWith(): void
    {
        $query = Query::notStartsWith('metadata', 'foo');
        $query->setAttributeType(ColumnType::Object->value);

        $result = (new Builder())
            ->from('t')
            ->filter([$query])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "metadata"::text NOT ILIKE ?', $result->query);
    }

    public function testObjectFilterTopLevelEndsWith(): void
    {
        $query = Query::endsWith('metadata', 'bar');
        $query->setAttributeType(ColumnType::Object->value);

        $result = (new Builder())
            ->from('t')
            ->filter([$query])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "metadata"::text ILIKE ?', $result->query);
    }

    public function testObjectFilterTopLevelNotEndsWith(): void
    {
        $query = Query::notEndsWith('metadata', 'bar');
        $query->setAttributeType(ColumnType::Object->value);

        $result = (new Builder())
            ->from('t')
            ->filter([$query])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "metadata"::text NOT ILIKE ?', $result->query);
    }

    public function testObjectFilterTopLevelIsNull(): void
    {
        $query = Query::isNull('metadata');
        $query->setAttributeType(ColumnType::Object->value);

        $result = (new Builder())
            ->from('t')
            ->filter([$query])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "metadata" IS NULL', $result->query);
    }

    public function testObjectFilterTopLevelIsNotNull(): void
    {
        $query = Query::isNotNull('metadata');
        $query->setAttributeType(ColumnType::Object->value);

        $result = (new Builder())
            ->from('t')
            ->filter([$query])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "metadata" IS NOT NULL', $result->query);
    }

    public function testBuildJsonbPathDeepNested(): void
    {
        $query = Query::equal('data.level1.level2.leaf', ['val']);
        $query->setAttributeType(ColumnType::Object->value);

        $result = (new Builder())
            ->from('t')
            ->filter([$query])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "t" WHERE "data"->\'level1\'->\'level2\'->>\'leaf\' IN (?)', $result->query);
    }

    public function testVectorFilterDefault(): void
    {
        $result = (new Builder())
            ->from('embeddings')
            ->filter([Query::vectorCosine('embedding', [0.1, 0.2])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "embeddings" WHERE ("embedding" <=> ?::vector)', $result->query);
    }

    public function testSpatialDistanceEqual(): void
    {
        $result = (new Builder())
            ->from('locations')
            ->filterDistance('loc', [1.0, 2.0], '=', 500.0)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "locations" WHERE ST_Distance("loc", ST_GeomFromText(?, 4326)) = ?', $result->query);
    }

    public function testSpatialDistanceNotEqual(): void
    {
        $result = (new Builder())
            ->from('locations')
            ->filterDistance('loc', [1.0, 2.0], '!=', 500.0)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "locations" WHERE ST_Distance("loc", ST_GeomFromText(?, 4326)) != ?', $result->query);
    }

    public function testResetClearsMergeState(): void
    {
        $source = (new Builder())->from('staging');
        $builder = (new Builder())
            ->mergeInto('users')
            ->using($source, 'src')
            ->on('users.id = src.id')
            ->whenMatched('DELETE')
            ->whenNotMatched('INSERT (id) VALUES (src.id)');

        $builder->reset();

        $this->expectException(ValidationException::class);
        $builder->executeMerge();
    }

    public function testResetClearsUpdateFromState(): void
    {
        $builder = (new Builder())
            ->from('orders')
            ->set(['status' => 'shipped'])
            ->updateFrom('shipments', 's')
            ->updateFromWhere('orders.id = s.order_id');

        $builder->reset();

        $result = $builder
            ->from('orders')
            ->set(['status' => 'done'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString('FROM "shipments"', $result->query);
    }

    public function testResetClearsDeleteUsingState(): void
    {
        $builder = (new Builder())
            ->from('orders')
            ->deleteUsing('expired', 'orders.id = expired.id');

        $builder->reset();

        $result = $builder
            ->from('orders')
            ->filter([Query::equal('id', [1])])
            ->delete();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString('USING', $result->query);
    }

    public function testCteWithInsertReturning(): void
    {
        $cteQuery = (new Builder())
            ->from('orders')
            ->select(['id', 'customer_id'])
            ->filter([Query::equal('status', ['pending'])]);

        $sourceWithCte = (new Builder())
            ->with('pending_orders', $cteQuery)
            ->from('pending_orders')
            ->select(['id', 'customer_id']);

        $result = (new Builder())
            ->into('archived_orders')
            ->fromSelect(['id', 'customer_id'], $sourceWithCte)
            ->insertSelect();

        $this->assertSame('INSERT INTO "archived_orders" ("id", "customer_id") WITH "pending_orders" AS (SELECT "id", "customer_id" FROM "orders" WHERE "status" IN (?)) SELECT "id", "customer_id" FROM "pending_orders"', $result->query);
        $this->assertContains('pending', $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testRecursiveCteJoinToMainTable(): void
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
            ->withRecursiveSeedStep('tree', $seed, $step)
            ->from('tree')
            ->select(['id', 'name'])
            ->build();

        $this->assertSame('WITH RECURSIVE "tree" AS (SELECT "id", "parent_id", "name" FROM "categories" WHERE "parent_id" IS NULL UNION ALL SELECT "categories"."id", "categories"."parent_id", "categories"."name" FROM "categories" JOIN "tree" ON "categories"."parent_id" = "tree"."id") SELECT "id", "name" FROM "tree"', $result->query);
        $this->assertBindingCount($result);
    }

    public function testMultipleCtesJoinBetweenThem(): void
    {
        $cteA = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->filter([Query::equal('active', [true])]);

        $cteB = (new Builder())
            ->from('orders')
            ->select(['user_id', 'total'])
            ->filter([Query::greaterThan('total', 50)]);

        $result = (new Builder())
            ->with('active_users', $cteA)
            ->with('big_orders', $cteB)
            ->from('active_users')
            ->select(['active_users.name', 'big_orders.total'])
            ->join('big_orders', 'active_users.id', 'big_orders.user_id')
            ->build();

        $this->assertSame(
            'WITH "active_users" AS (SELECT "id", "name" FROM "users" WHERE "active" IN (?)), "big_orders" AS (SELECT "user_id", "total" FROM "orders" WHERE "total" > ?) SELECT "active_users"."name", "big_orders"."total" FROM "active_users" JOIN "big_orders" ON "active_users"."id" = "big_orders"."user_id"',
            $result->query
        );
        $this->assertSame([true, 50], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testMergeWithCteSource(): void
    {
        $cteQuery = (new Builder())
            ->from('staging')
            ->select(['id', 'name', 'email'])
            ->filter([Query::equal('status', ['ready'])]);

        $sourceFromCte = (new Builder())
            ->with('ready_staging', $cteQuery)
            ->from('ready_staging')
            ->select(['id', 'name', 'email']);

        $result = (new Builder())
            ->mergeInto('users')
            ->using($sourceFromCte, 'src')
            ->on('users.id = src.id')
            ->whenMatched('UPDATE SET name = src.name, email = src.email')
            ->whenNotMatched('INSERT (id, name, email) VALUES (src.id, src.name, src.email)')
            ->executeMerge();

        $this->assertSame('MERGE INTO "users" USING (WITH "ready_staging" AS (SELECT "id", "name", "email" FROM "staging" WHERE "status" IN (?)) SELECT "id", "name", "email" FROM "ready_staging") AS "src" ON users.id = src.id WHEN MATCHED THEN UPDATE SET name = src.name, email = src.email WHEN NOT MATCHED THEN INSERT (id, name, email) VALUES (src.id, src.name, src.email)', $result->query);
        $this->assertContains('ready', $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testJsonPathWithWhereAndJoin(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['users.id', 'orders.total'])
            ->join('orders', 'users.id', 'orders.user_id')
            ->filterJsonPath('users.metadata', 'role', '=', 'admin')
            ->filter([Query::greaterThan('orders.total', 100)])
            ->build();

        $this->assertSame('SELECT "users"."id", "orders"."total" FROM "users" JOIN "orders" ON "users"."id" = "orders"."user_id" WHERE "users"."metadata"->>\'role\' = ? AND "orders"."total" > ?', $result->query);
        $this->assertSame(['admin', 100], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testJsonContainsWithGroupByHaving(): void
    {
        $result = (new Builder())
            ->from('products')
            ->count('*', 'cnt')
            ->filterJsonContains('tags', 'sale')
            ->groupBy(['category'])
            ->having([Query::greaterThan('cnt', 3)])
            ->build();

        $this->assertSame('SELECT COUNT(*) AS "cnt" FROM "products" WHERE "tags" @> ?::jsonb GROUP BY "category" HAVING COUNT(*) > ?', $result->query);
        $this->assertBindingCount($result);
    }

    public function testUpdateFromWithComplexSubqueryReturning(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->set(['status' => 'shipped'])
            ->updateFrom('shipments', 's')
            ->updateFromWhere('orders.id = s.order_id AND s.date > ?', '2024-01-01')
            ->filter([Query::equal('orders.warehouse', ['US-EAST'])])
            ->returning(['orders.id', 'orders.status'])
            ->update();

        $this->assertSame('UPDATE "orders" SET "status" = ? FROM "shipments" AS "s" WHERE "orders"."warehouse" IN (?) AND orders.id = s.order_id AND s.date > ? RETURNING "orders"."id", "orders"."status"', $result->query);
        $this->assertBindingCount($result);
    }

    public function testDeleteUsingWithFilterReturning(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->deleteUsing('blacklist', 'orders.user_id = blacklist.user_id')
            ->filter([Query::lessThan('orders.created_at', '2023-01-01')])
            ->returning(['orders.id'])
            ->delete();

        $this->assertSame('DELETE FROM "orders" USING "blacklist" WHERE "orders"."created_at" < ? AND orders.user_id = blacklist.user_id RETURNING "orders"."id"', $result->query);
        $this->assertBindingCount($result);
    }

    public function testLateralJoinWithAggregateAndWhere(): void
    {
        $sub = (new Builder())
            ->from('orders')
            ->select(['user_id'])
            ->sum('total', 'order_total')
            ->filter([Query::greaterThan('total', 0)])
            ->groupBy(['user_id'])
            ->limit(5);

        $result = (new Builder())
            ->from('users')
            ->select(['users.name'])
            ->joinLateral($sub, 'user_orders')
            ->filter([Query::equal('users.active', [true])])
            ->build();

        $this->assertSame('SELECT "users"."name" FROM "users" JOIN LATERAL (SELECT SUM("total") AS "order_total", "user_id" FROM "orders" WHERE "total" > ? GROUP BY "user_id" LIMIT ?) AS "user_orders" ON true WHERE "users"."active" IN (?)', $result->query);
        $this->assertBindingCount($result);
    }

    public function testFullOuterJoinWithNullFilter(): void
    {
        $result = (new Builder())
            ->from('employees')
            ->select(['employees.name', 'departments.name'])
            ->fullOuterJoin('departments', 'employees.dept_id', 'departments.id')
            ->filter([
                Query::or([
                    Query::isNull('employees.dept_id'),
                    Query::isNull('departments.id'),
                ]),
            ])
            ->build();

        $this->assertSame('SELECT "employees"."name", "departments"."name" FROM "employees" FULL OUTER JOIN "departments" ON "employees"."dept_id" = "departments"."id" WHERE ("employees"."dept_id" IS NULL OR "departments"."id" IS NULL)', $result->query);
        $this->assertBindingCount($result);
    }

    public function testWindowFunctionWithDistinct(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->distinct()
            ->select(['customer_id'])
            ->selectWindow('ROW_NUMBER()', 'rn', ['customer_id'], ['created_at'])
            ->build();

        $this->assertSame('SELECT DISTINCT "customer_id", ROW_NUMBER() OVER (PARTITION BY "customer_id" ORDER BY "created_at" ASC) AS "rn" FROM "orders"', $result->query);
        $this->assertBindingCount($result);
    }

    public function testNamedWindowDefinitionWithJoin(): void
    {
        $result = (new Builder())
            ->from('employees')
            ->select(['employees.name', 'departments.name'])
            ->join('departments', 'employees.dept_id', 'departments.id')
            ->selectWindow('RANK()', 'salary_rank', null, null, 'dept_window')
            ->window('dept_window', ['employees.dept_id'], ['-employees.salary'])
            ->build();

        $this->assertSame('SELECT "employees"."name", "departments"."name", RANK() OVER "dept_window" AS "salary_rank" FROM "employees" JOIN "departments" ON "employees"."dept_id" = "departments"."id" WINDOW "dept_window" AS (PARTITION BY "employees"."dept_id" ORDER BY "employees"."salary" DESC)', $result->query);
        $this->assertBindingCount($result);
    }

    public function testMultipleWindowFunctionsRowNumberRankSum(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->select(['employee_id', 'amount'])
            ->selectWindow('ROW_NUMBER()', 'rn', null, ['date'])
            ->selectWindow('RANK()', 'rnk', ['region'], ['-amount'])
            ->selectWindow('SUM("amount")', 'running_total', ['region'], ['date'])
            ->build();

        $this->assertSame(
            'SELECT "employee_id", "amount", ROW_NUMBER() OVER (ORDER BY "date" ASC) AS "rn", RANK() OVER (PARTITION BY "region" ORDER BY "amount" DESC) AS "rnk", SUM("amount") OVER (PARTITION BY "region" ORDER BY "date" ASC) AS "running_total" FROM "sales"',
            $result->query
        );
        $this->assertBindingCount($result);
    }

    public function testExplainAnalyzeWithCteAndJoin(): void
    {
        $cteQuery = (new Builder())
            ->from('orders')
            ->select(['user_id', 'total'])
            ->filter([Query::greaterThan('total', 100)]);

        $result = (new Builder())
            ->with('big_orders', $cteQuery)
            ->from('users')
            ->select(['users.name', 'big_orders.total'])
            ->join('big_orders', 'users.id', 'big_orders.user_id')
            ->explain(analyze: true, verbose: true, format: 'json');

        $this->assertStringStartsWith('EXPLAIN (ANALYZE, VERBOSE, FORMAT JSON)', $result->query);
        $this->assertSame('EXPLAIN (ANALYZE, VERBOSE, FORMAT JSON) WITH "big_orders" AS (SELECT "user_id", "total" FROM "orders" WHERE "total" > ?) SELECT "users"."name", "big_orders"."total" FROM "users" JOIN "big_orders" ON "users"."id" = "big_orders"."user_id"', $result->query);
        $this->assertTrue($result->readOnly);
        $this->assertBindingCount($result);
    }

    public function testVectorDistanceOrderByWithLimit(): void
    {
        $result = (new Builder())
            ->from('items')
            ->select(['id', 'title'])
            ->orderByVectorDistance('embedding', [0.1, 0.2, 0.3], VectorMetric::Cosine)
            ->limit(10)
            ->build();

        $this->assertSame(
            'SELECT "id", "title" FROM "items" ORDER BY ("embedding" <=> ?::vector) ASC LIMIT ?',
            $result->query
        );
        $this->assertSame(['[0.1,0.2,0.3]', 10], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testUpsertWithReturning(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['id' => 1, 'name' => 'Alice', 'email' => 'alice@test.com'])
            ->onConflict(['id'], ['name', 'email'])
            ->returning(['id', 'name', 'email'])
            ->upsert();

        $this->assertSame(
            'INSERT INTO "users" ("id", "name", "email") VALUES (?, ?, ?) ON CONFLICT ("id") DO UPDATE SET "name" = EXCLUDED."name", "email" = EXCLUDED."email" RETURNING "id", "name", "email"',
            $result->query
        );
        $this->assertBindingCount($result);
    }

    public function testUpsertConflictSetRawWithRegularOnConflict(): void
    {
        $result = (new Builder())
            ->into('stats')
            ->set(['id' => 'page', 'views' => 1, 'updated_at' => '2024-01-01'])
            ->onConflict(['id'], ['views', 'updated_at'])
            ->conflictSetRaw('views', '"stats"."views" + EXCLUDED."views"')
            ->upsert();

        $this->assertSame('INSERT INTO "stats" ("id", "views", "updated_at") VALUES (?, ?, ?) ON CONFLICT ("id") DO UPDATE SET "views" = "stats"."views" + EXCLUDED."views", "updated_at" = EXCLUDED."updated_at"', $result->query);
        $this->assertBindingCount($result);
    }

    public function testInsertAsWithComplexCteQuery(): void
    {
        $cteQuery = (new Builder())
            ->from('staging')
            ->select(['id', 'name'])
            ->filter([Query::equal('status', ['ready'])]);

        $source = (new Builder())
            ->with('ready', $cteQuery)
            ->from('ready')
            ->select(['id', 'name']);

        $result = (new Builder())
            ->into('users')
            ->fromSelect(['id', 'name'], $source)
            ->insertSelect();

        $this->assertSame('INSERT INTO "users" ("id", "name") WITH "ready" AS (SELECT "id", "name" FROM "staging" WHERE "status" IN (?)) SELECT "id", "name" FROM "ready"', $result->query);
        $this->assertBindingCount($result);
    }

    public function testForUpdateWithJoin(): void
    {
        $result = (new Builder())
            ->from('accounts')
            ->select(['accounts.id', 'accounts.balance'])
            ->join('users', 'accounts.user_id', 'users.id')
            ->filter([Query::equal('users.active', [true])])
            ->forUpdate()
            ->build();

        $this->assertSame('SELECT "accounts"."id", "accounts"."balance" FROM "accounts" JOIN "users" ON "accounts"."user_id" = "users"."id" WHERE "users"."active" IN (?) FOR UPDATE', $result->query);
        $this->assertBindingCount($result);
    }

    public function testForShareWithSubquery(): void
    {
        $sub = (new Builder())
            ->from('vip_users')
            ->select(['id']);

        $result = (new Builder())
            ->from('accounts')
            ->filterWhereIn('user_id', $sub)
            ->forShare()
            ->build();

        $this->assertSame('SELECT * FROM "accounts" WHERE "user_id" IN (SELECT "id" FROM "vip_users") FOR SHARE', $result->query);
        $this->assertBindingCount($result);
    }

    public function testNestedOrAndFilterParenthesization(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::or([
                    Query::and([
                        Query::equal('a', [1]),
                        Query::greaterThan('b', 10),
                    ]),
                    Query::and([
                        Query::lessThan('c', 5),
                        Query::between('d', 100, 200),
                    ]),
                ]),
            ])
            ->build();

        $this->assertSame(
            'SELECT * FROM "t" WHERE (("a" IN (?) AND "b" > ?) OR ("c" < ? AND "d" BETWEEN ? AND ?))',
            $result->query
        );
        $this->assertSame([1, 10, 5, 100, 200], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testTripleNestedAndOrFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::and([
                    Query::or([
                        Query::equal('status', ['active']),
                        Query::notEqual('status', 'banned'),
                    ]),
                    Query::or([
                        Query::greaterThan('age', 18),
                        Query::lessThan('age', 65),
                    ]),
                    Query::between('score', 0, 100),
                ]),
            ])
            ->build();

        $this->assertSame(
            'SELECT * FROM "t" WHERE (("status" IN (?) OR "status" != ?) AND ("age" > ? OR "age" < ?) AND "score" BETWEEN ? AND ?)',
            $result->query
        );
        $this->assertSame(['active', 'banned', 18, 65, 0, 100], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testIsNullAndIsNotNullSameQuery(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::isNull('deleted_at'),
                Query::isNotNull('email'),
            ])
            ->build();

        $this->assertSame(
            'SELECT * FROM "t" WHERE "deleted_at" IS NULL AND "email" IS NOT NULL',
            $result->query
        );
        $this->assertSame([], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testBetweenNotEqualGreaterThanCombined(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::between('price', 10, 100),
                Query::notEqual('category', 'deprecated'),
                Query::greaterThan('stock', 0),
            ])
            ->build();

        $this->assertSame(
            'SELECT * FROM "t" WHERE "price" BETWEEN ? AND ? AND "category" != ? AND "stock" > ?',
            $result->query
        );
        $this->assertSame([10, 100, 'deprecated', 0], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testStartsWithAndContainsOnSameColumn(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::startsWith('name', 'John'),
                Query::containsString('name', ['Doe']),
            ])
            ->build();

        $this->assertSame('SELECT * FROM "t" WHERE "name" ILIKE ? AND "name" ILIKE ?', $result->query);
        $this->assertCount(2, $result->bindings);
        $this->assertSame('John%', $result->bindings[0]);
        $this->assertSame('%Doe%', $result->bindings[1]);
        $this->assertBindingCount($result);
    }

    public function testRegexAndEqualCombined(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::regex('slug', '^test-'),
                Query::equal('status', ['active']),
            ])
            ->build();

        $this->assertSame('SELECT * FROM "t" WHERE "slug" ~ ? AND "status" IN (?)', $result->query);
        $this->assertSame(['^test-', 'active'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testNotContainsAndContainsDifferentColumns(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::notContains('bio', ['spam']),
                Query::containsString('title', ['important']),
            ])
            ->build();

        $this->assertSame('SELECT * FROM "t" WHERE "bio" NOT ILIKE ? AND "title" ILIKE ?', $result->query);
        $this->assertBindingCount($result);
    }

    public function testMultipleOrGroupsInSameFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::or([
                    Query::equal('color', ['red']),
                    Query::equal('color', ['blue']),
                ]),
                Query::or([
                    Query::equal('size', ['S']),
                    Query::equal('size', ['M']),
                ]),
            ])
            ->build();

        $this->assertSame(
            'SELECT * FROM "t" WHERE ("color" IN (?) OR "color" IN (?)) AND ("size" IN (?) OR "size" IN (?))',
            $result->query
        );
        $this->assertSame(['red', 'blue', 'S', 'M'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testAndWrappingOrWrappingAnd(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::and([
                    Query::or([
                        Query::and([
                            Query::equal('a', [1]),
                            Query::equal('b', [2]),
                        ]),
                        Query::equal('c', [3]),
                    ]),
                ]),
            ])
            ->build();

        $this->assertSame(
            'SELECT * FROM "t" WHERE ((("a" IN (?) AND "b" IN (?)) OR "c" IN (?)))',
            $result->query
        );
        $this->assertSame([1, 2, 3], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testFilterWithBooleanValues(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('active', [true])])
            ->build();

        $this->assertSame(
            'SELECT * FROM "t" WHERE "active" IN (?)',
            $result->query
        );
        $this->assertSame([true], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testCteBindingsMainQueryBindingsHavingBindingsOrder(): void
    {
        $cteQuery = (new Builder())
            ->from('orders')
            ->select(['customer_id'])
            ->filter([Query::equal('status', ['shipped'])]);

        $result = (new Builder())
            ->with('shipped', $cteQuery)
            ->from('shipped')
            ->count('*', 'cnt')
            ->filter([Query::greaterThan('total', 50)])
            ->groupBy(['customer_id'])
            ->having([Query::greaterThan('cnt', 3)])
            ->build();

        $this->assertSame('shipped', $result->bindings[0]);
        $this->assertSame(50, $result->bindings[1]);
        $this->assertSame(3, $result->bindings[2]);
        $this->assertBindingCount($result);
    }

    public function testUnionBothBranchesBindingsOrder(): void
    {
        $other = (new Builder())
            ->from('archived')
            ->select(['id', 'name'])
            ->filter([Query::equal('year', [2023])]);

        $result = (new Builder())
            ->from('current')
            ->select(['id', 'name'])
            ->filter([Query::equal('year', [2024])])
            ->union($other)
            ->build();

        $this->assertSame(2024, $result->bindings[0]);
        $this->assertSame(2023, $result->bindings[1]);
        $this->assertBindingCount($result);
    }

    public function testSubqueryInWhereAndSelectBindingOrder(): void
    {
        $selectSub = (new Builder())
            ->from('orders')
            ->count('*', 'cnt')
            ->filter([Query::equal('orders.user_id', [99])]);

        $whereSub = (new Builder())
            ->from('vip_users')
            ->select(['id'])
            ->filter([Query::equal('level', ['gold'])]);

        $result = (new Builder())
            ->from('users')
            ->selectSub($selectSub, 'order_count')
            ->filter([Query::equal('status', ['active'])])
            ->filterWhereIn('id', $whereSub)
            ->build();

        $this->assertSame(99, $result->bindings[0]);
        $this->assertSame('active', $result->bindings[1]);
        $this->assertSame('gold', $result->bindings[2]);
        $this->assertBindingCount($result);
    }

    public function testJoinOnBindingsWhereBindingsHavingBindingsOrder(): void
    {
        $result = (new Builder())
            ->from('users')
            ->count('*', 'cnt')
            ->joinWhere('orders', function (JoinBuilder $join): void {
                $join->on('users.id', 'orders.user_id')
                     ->where('orders.amount', '>', 50);
            })
            ->filter([Query::equal('users.active', [true])])
            ->groupBy(['users.id'])
            ->having([Query::greaterThan('cnt', 2)])
            ->build();

        $this->assertSame(50, $result->bindings[0]);
        $this->assertSame(true, $result->bindings[1]);
        $this->assertSame(2, $result->bindings[2]);
        $this->assertBindingCount($result);
    }

    public function testInsertAsBindings(): void
    {
        $source = (new Builder())
            ->from('staging')
            ->select(['id', 'name'])
            ->filter([Query::equal('ready', [true])]);

        $result = (new Builder())
            ->into('users')
            ->fromSelect(['id', 'name'], $source)
            ->insertSelect();

        $this->assertSame('INSERT INTO "users" ("id", "name") SELECT "id", "name" FROM "staging" WHERE "ready" IN (?)', $result->query);
        $this->assertSame([true], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testUpsertFilterValuesConflictUpdateBindingOrder(): void
    {
        $result = (new Builder())
            ->into('counters')
            ->set(['id' => 'views', 'count' => 1])
            ->onConflict(['id'], ['count'])
            ->conflictSetRaw('count', '"counters"."count" + ?', [1])
            ->upsert();

        $this->assertSame('views', $result->bindings[0]);
        $this->assertSame(1, $result->bindings[1]);
        $this->assertSame(1, $result->bindings[2]);
        $this->assertBindingCount($result);
    }

    public function testMergeSourceBindingsActionBindingsOrder(): void
    {
        $source = (new Builder())
            ->from('staging')
            ->filter([Query::equal('status', ['pending'])]);

        $result = (new Builder())
            ->mergeInto('users')
            ->using($source, 'src')
            ->on('users.id = src.id AND src.region = ?', 'US')
            ->whenMatched('UPDATE SET count = users.count + ?', 1)
            ->whenNotMatched('INSERT (id, count) VALUES (src.id, ?)', 0)
            ->executeMerge();

        $this->assertSame('pending', $result->bindings[0]);
        $this->assertSame('US', $result->bindings[1]);
        $this->assertSame(1, $result->bindings[2]);
        $this->assertSame(0, $result->bindings[3]);
        $this->assertBindingCount($result);
    }

    public function testSelectEmptyArray(): void
    {
        $result = (new Builder())
            ->from('t')
            ->select([])
            ->build();

        $this->assertSame('SELECT  FROM "t"', $result->query);
        $this->assertBindingCount($result);
    }

    public function testGroupByThreeColumns(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count('*', 'cnt')
            ->groupBy(['a', 'b', 'c'])
            ->build();

        $this->assertSame(
            'SELECT COUNT(*) AS "cnt" FROM "t" GROUP BY "a", "b", "c"',
            $result->query
        );
        $this->assertBindingCount($result);
    }

    public function testInterleavedSortAscDesc(): void
    {
        $result = (new Builder())
            ->from('t')
            ->sortAsc('a')
            ->sortDesc('b')
            ->sortAsc('c')
            ->build();

        $this->assertSame(
            'SELECT * FROM "t" ORDER BY "a" ASC, "b" DESC, "c" ASC',
            $result->query
        );
        $this->assertBindingCount($result);
    }

    public function testLimitOneOffsetZero(): void
    {
        $result = (new Builder())
            ->from('t')
            ->limit(1)
            ->offset(0)
            ->build();

        $this->assertSame(
            'SELECT * FROM "t" LIMIT ? OFFSET ?',
            $result->query
        );
        $this->assertSame([1, 0], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testLimitZero(): void
    {
        $result = (new Builder())
            ->from('t')
            ->limit(0)
            ->build();

        $this->assertSame(
            'SELECT * FROM "t" LIMIT ?',
            $result->query
        );
        $this->assertSame([0], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testDistinctWithMultipleAggregates(): void
    {
        $result = (new Builder())
            ->from('t')
            ->distinct()
            ->count('*', 'cnt')
            ->sum('price', 'total')
            ->build();

        $this->assertSame('SELECT DISTINCT COUNT(*) AS "cnt", SUM("price") AS "total" FROM "t"', $result->query);
        $this->assertBindingCount($result);
    }

    public function testCountStarWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count('*')
            ->build();

        $this->assertSame('SELECT COUNT(*) FROM "t"', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
        $this->assertBindingCount($result);
    }

    public function testCountStarWithAlias(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count('*', 'total')
            ->build();

        $this->assertSame('SELECT COUNT(*) AS "total" FROM "t"', $result->query);
        $this->assertBindingCount($result);
    }

    public function testSelfJoinWithAlias(): void
    {
        $result = (new Builder())
            ->from('employees', 'e')
            ->select(['e.name', 'm.name'])
            ->leftJoin('employees', 'e.manager_id', 'm.id', '=', 'm')
            ->build();

        $this->assertSame(
            'SELECT "e"."name", "m"."name" FROM "employees" AS "e" LEFT JOIN "employees" AS "m" ON "e"."manager_id" = "m"."id"',
            $result->query
        );
        $this->assertBindingCount($result);
    }

    public function testThreeWayJoin(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['users.name', 'orders.total', 'products.title'])
            ->join('orders', 'users.id', 'orders.user_id')
            ->join('products', 'orders.product_id', 'products.id')
            ->build();

        $this->assertSame(
            'SELECT "users"."name", "orders"."total", "products"."title" FROM "users" JOIN "orders" ON "users"."id" = "orders"."user_id" JOIN "products" ON "orders"."product_id" = "products"."id"',
            $result->query
        );
        $this->assertBindingCount($result);
    }

    public function testCrossJoinWithFilter(): void
    {
        $result = (new Builder())
            ->from('colors')
            ->select(['colors.name', 'sizes.label'])
            ->crossJoin('sizes')
            ->filter([Query::equal('colors.active', [true])])
            ->build();

        $this->assertSame('SELECT "colors"."name", "sizes"."label" FROM "colors" CROSS JOIN "sizes" WHERE "colors"."active" IN (?)', $result->query);
        $this->assertBindingCount($result);
    }

    public function testLeftJoinWhereRightSideIsNull(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['users.id', 'users.name'])
            ->leftJoin('orders', 'users.id', 'orders.user_id')
            ->filter([Query::isNull('orders.id')])
            ->build();

        $this->assertSame(
            'SELECT "users"."id", "users"."name" FROM "users" LEFT JOIN "orders" ON "users"."id" = "orders"."user_id" WHERE "orders"."id" IS NULL',
            $result->query
        );
        $this->assertBindingCount($result);
    }

    public function testBeforeBuildCallbackAddsFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->beforeBuild(function (Builder $b): void {
                $b->filter([Query::equal('tenant_id', [42])]);
            })
            ->build();

        $this->assertSame('SELECT * FROM "t" WHERE "tenant_id" IN (?)', $result->query);
        $this->assertContains(42, $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testAfterBuildCallbackWrapsQuery(): void
    {
        $result = (new Builder())
            ->from('t')
            ->select(['id'])
            ->afterBuild(function (\Utopia\Query\Builder\Statement $r): \Utopia\Query\Builder\Statement {
                return new \Utopia\Query\Builder\Statement(
                    'SELECT * FROM (' . $r->query . ') AS wrapped',
                    $r->bindings,
                    $r->readOnly,
                );
            })
            ->build();

        $this->assertSame('SELECT * FROM (SELECT "id" FROM "t") AS wrapped', $result->query);
        $this->assertBindingCount($result);
    }

    public function testCloneModifyOriginalUnchanged(): void
    {
        $original = (new Builder())
            ->from('users')
            ->select(['id', 'name']);

        $cloned = $original->clone();
        $cloned->filter([Query::equal('active', [true])]);

        $originalResult = $original->build();
        $clonedResult = $cloned->build();

        $this->assertSame('SELECT "id", "name" FROM "users"', $originalResult->query);
        $this->assertSame('SELECT "id", "name" FROM "users" WHERE "active" IN (?)', $clonedResult->query);
        $this->assertBindingCount($originalResult);
        $this->assertBindingCount($clonedResult);
    }

    public function testResetAndRebuild(): void
    {
        $builder = (new Builder())
            ->from('users')
            ->select(['id'])
            ->filter([Query::equal('status', ['active'])])
            ->sortAsc('name')
            ->limit(10);

        $builder->reset();

        $result = $builder
            ->from('orders')
            ->select(['total'])
            ->build();

        $this->assertSame('SELECT "total" FROM "orders"', $result->query);
        $this->assertSame([], $result->bindings);
        $this->assertStringNotContainsString('users', $result->query);
        $this->assertBindingCount($result);
    }

    public function testReadOnlyFlagSelectIsTrue(): void
    {
        $result = (new Builder())
            ->from('t')
            ->build();

        $this->assertTrue($result->readOnly);
    }

    public function testReadOnlyFlagInsertImplicit(): void
    {
        $result = (new Builder())
            ->into('t')
            ->set(['a' => 1])
            ->insert();

        $this->assertFalse($result->readOnly);
    }

    public function testReadOnlyFlagUpdateImplicit(): void
    {
        $result = (new Builder())
            ->from('t')
            ->set(['a' => 1])
            ->update();

        $this->assertFalse($result->readOnly);
    }

    public function testReadOnlyFlagDeleteImplicit(): void
    {
        $result = (new Builder())
            ->from('t')
            ->delete();

        $this->assertFalse($result->readOnly);
    }

    public function testMultipleSetCallsForMultiRowInsert(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'Alice', 'age' => 30])
            ->set(['name' => 'Bob', 'age' => 25])
            ->set(['name' => 'Charlie', 'age' => 35])
            ->insert();

        $this->assertSame(
            'INSERT INTO "users" ("name", "age") VALUES (?, ?), (?, ?), (?, ?)',
            $result->query
        );
        $this->assertSame(['Alice', 30, 'Bob', 25, 'Charlie', 35], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testSetWithBooleanAndNullValues(): void
    {
        $result = (new Builder())
            ->into('t')
            ->set(['active' => true, 'deleted' => false, 'notes' => null])
            ->insert();

        $this->assertSame(
            'INSERT INTO "t" ("active", "deleted", "notes") VALUES (?, ?, ?)',
            $result->query
        );
        $this->assertSame([true, false, null], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testInsertOrIgnorePostgreSQLSyntax(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['id' => 1, 'name' => 'John', 'email' => 'john@test.com'])
            ->insertOrIgnore();

        $this->assertSame(
            'INSERT INTO "users" ("id", "name", "email") VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
            $result->query
        );
        $this->assertSame([1, 'John', 'john@test.com'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testNotStartsWithFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notStartsWith('name', 'test')])
            ->build();

        $this->assertSame('SELECT * FROM "t" WHERE "name" NOT ILIKE ?', $result->query);
        $this->assertSame(['test%'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testNotEndsWithFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notEndsWith('name', 'test')])
            ->build();

        $this->assertSame('SELECT * FROM "t" WHERE "name" NOT ILIKE ?', $result->query);
        $this->assertSame(['%test'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testNaturalJoin(): void
    {
        $result = (new Builder())
            ->from('a')
            ->naturalJoin('b')
            ->build();

        $this->assertSame('SELECT * FROM "a" NATURAL JOIN "b"', $result->query);
        $this->assertBindingCount($result);
    }

    public function testNaturalJoinWithAlias(): void
    {
        $result = (new Builder())
            ->from('a')
            ->naturalJoin('b', 'b_alias')
            ->build();

        $this->assertSame('SELECT * FROM "a" NATURAL JOIN "b" AS "b_alias"', $result->query);
        $this->assertBindingCount($result);
    }

    public function testFilterWhereNotIn(): void
    {
        $sub = (new Builder())
            ->from('blocked')
            ->select(['user_id']);

        $result = (new Builder())
            ->from('users')
            ->filterWhereNotIn('id', $sub)
            ->build();

        $this->assertSame('SELECT * FROM "users" WHERE "id" NOT IN (SELECT "user_id" FROM "blocked")', $result->query);
        $this->assertBindingCount($result);
    }

    public function testExplainReadOnlyFlag(): void
    {
        $result = (new Builder())
            ->from('users')
            ->explain();

        $this->assertTrue($result->readOnly);
    }

    public function testExplainAnalyzeReadOnlyFlag(): void
    {
        $result = (new Builder())
            ->from('users')
            ->explain(true);

        $this->assertTrue($result->readOnly);
    }

    public function testUnionAllWithBindingsOrder(): void
    {
        $other = (new Builder())
            ->from('b')
            ->filter([Query::equal('type', ['beta'])]);

        $result = (new Builder())
            ->from('a')
            ->filter([Query::equal('type', ['alpha'])])
            ->unionAll($other)
            ->build();

        $this->assertSame('(SELECT * FROM "a" WHERE "type" IN (?)) UNION ALL (SELECT * FROM "b" WHERE "type" IN (?))', $result->query);
        $this->assertSame('alpha', $result->bindings[0]);
        $this->assertSame('beta', $result->bindings[1]);
        $this->assertBindingCount($result);
    }

    public function testExceptAll(): void
    {
        $other = (new Builder())->from('b');

        $result = (new Builder())
            ->from('a')
            ->exceptAll($other)
            ->build();

        $this->assertSame('(SELECT * FROM "a") EXCEPT ALL (SELECT * FROM "b")', $result->query);
        $this->assertBindingCount($result);
    }

    public function testIntersectAll(): void
    {
        $other = (new Builder())->from('b');

        $result = (new Builder())
            ->from('a')
            ->intersectAll($other)
            ->build();

        $this->assertSame('(SELECT * FROM "a") INTERSECT ALL (SELECT * FROM "b")', $result->query);
        $this->assertBindingCount($result);
    }

    public function testInsertAlias(): void
    {
        $result = (new Builder())
            ->into('users')
            ->insertAs('new_row')
            ->set(['id' => 1, 'name' => 'Alice'])
            ->onConflict(['id'], ['name'])
            ->conflictSetRaw('name', 'COALESCE("new_row"."name", EXCLUDED."name")')
            ->upsert();

        $this->assertSame('INSERT INTO "users" AS "new_row" ("id", "name") VALUES (?, ?) ON CONFLICT ("id") DO UPDATE SET "name" = COALESCE("new_row"."name", EXCLUDED."name")', $result->query);
        $this->assertBindingCount($result);
    }

    public function testFromNone(): void
    {
        $result = (new Builder())
            ->from()
            ->select('1 AS one')
            ->build();

        $this->assertSame('SELECT 1 AS one', $result->query);
        $this->assertBindingCount($result);
    }

    public function testSelectRawWithBindings(): void
    {
        $result = (new Builder())
            ->from('t')
            ->select('COALESCE("name", ?) AS display_name', ['Unknown'])
            ->build();

        $this->assertSame('SELECT COALESCE("name", ?) AS display_name FROM "t"', $result->query);
        $this->assertSame(['Unknown'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testInsertColumnExpression(): void
    {
        $result = (new Builder())
            ->into('locations')
            ->set(['name' => 'NYC', 'coords' => 'POINT(40.7128 -74.0060)'])
            ->insertColumnExpression('coords', 'ST_GeomFromText(?, 4326)')
            ->insert();

        $this->assertSame('INSERT INTO "locations" ("name", "coords") VALUES (?, ST_GeomFromText(?, 4326))', $result->query);
        $this->assertBindingCount($result);
    }

    public function testResetClearsReturningColumns(): void
    {
        $builder = (new Builder())
            ->into('users')
            ->set(['name' => 'Test'])
            ->returning(['id']);

        $builder->reset();

        $result = $builder
            ->into('users')
            ->set(['name' => 'Test2'])
            ->insert();

        $this->assertStringNotContainsString('RETURNING', $result->query);
        $this->assertBindingCount($result);
    }

    public function testResetClearsLateralJoins(): void
    {
        $sub = (new Builder())->from('orders')->select(['id']);

        $builder = (new Builder())
            ->from('users')
            ->joinLateral($sub, 'o');

        $builder->reset();

        $result = $builder->from('users')->build();
        $this->assertStringNotContainsString('LATERAL', $result->query);
        $this->assertBindingCount($result);
    }

    public function testCteWithDeleteReturning(): void
    {
        $cteQuery = (new Builder())
            ->from('users')
            ->select(['id'])
            ->filter([Query::equal('status', ['inactive'])]);

        $result = (new Builder())
            ->with('inactive_users', $cteQuery)
            ->from('users')
            ->filterWhereIn('id', (new Builder())->from('inactive_users')->select(['id']))
            ->returning(['id', 'name'])
            ->delete();

        $this->assertSame('DELETE FROM "users" WHERE "id" IN (SELECT "id" FROM "inactive_users") RETURNING "id", "name"', $result->query);
        $this->assertBindingCount($result);
    }

    public function testMultipleConditionalAggregates(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->countWhen('status = ?', 'active_count', 'active')
            ->countWhen('status = ?', 'cancelled_count', 'cancelled')
            ->sumWhen('amount', 'status = ?', 'active_total', 'active')
            ->groupBy(['region'])
            ->build();

        $this->assertSame('SELECT COUNT(*) FILTER (WHERE status = ?) AS "active_count", COUNT(*) FILTER (WHERE status = ?) AS "cancelled_count", SUM("amount") FILTER (WHERE status = ?) AS "active_total" FROM "orders" GROUP BY "region"', $result->query);
        $this->assertSame(['active', 'cancelled', 'active'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testTableSampleWithFilter(): void
    {
        $result = (new Builder())
            ->from('events')
            ->tablesample(5.0)
            ->filter([Query::greaterThan('ts', '2024-01-01')])
            ->limit(100)
            ->build();

        $this->assertSame('SELECT * FROM "events" TABLESAMPLE BERNOULLI(5) WHERE "ts" > ? LIMIT ?', $result->query);
        $this->assertBindingCount($result);
    }

    public function testLeftJoinLateralWithFilter(): void
    {
        $sub = (new Builder())
            ->from('comments')
            ->select(['body'])
            ->filter([Query::equal('approved', [true])])
            ->sortDesc('created_at')
            ->limit(3);

        $result = (new Builder())
            ->from('posts')
            ->select(['posts.title'])
            ->leftJoinLateral($sub, 'recent_comments')
            ->filter([Query::equal('posts.published', [true])])
            ->build();

        $this->assertSame('SELECT "posts"."title" FROM "posts" LEFT JOIN LATERAL (SELECT "body" FROM "comments" WHERE "approved" IN (?) ORDER BY "created_at" DESC LIMIT ?) AS "recent_comments" ON true WHERE "posts"."published" IN (?)', $result->query);
        $this->assertBindingCount($result);
    }

    public function testFullOuterJoinWithOperator(): void
    {
        $result = (new Builder())
            ->from('a')
            ->fullOuterJoin('b', 'a.key', 'b.key', '!=')
            ->build();

        $this->assertSame('SELECT * FROM "a" FULL OUTER JOIN "b" ON "a"."key" != "b"."key"', $result->query);
        $this->assertBindingCount($result);
    }

    public function testJoinWhereWithLeftType(): void
    {
        $result = (new Builder())
            ->from('users')
            ->joinWhere('orders', function (JoinBuilder $join): void {
                $join->on('users.id', 'orders.user_id')
                     ->where('orders.status', '=', 'active');
            }, JoinType::Left)
            ->build();

        $this->assertSame('SELECT * FROM "users" LEFT JOIN "orders" ON "users"."id" = "orders"."user_id" AND orders.status = ?', $result->query);
        $this->assertBindingCount($result);
    }

    public function testJoinWhereWithMultipleOnAndWhere(): void
    {
        $result = (new Builder())
            ->from('users')
            ->joinWhere('orders', function (JoinBuilder $join): void {
                $join->on('users.id', 'orders.user_id')
                     ->on('users.tenant_id', 'orders.tenant_id')
                     ->where('orders.amount', '>', 100)
                     ->where('orders.status', '=', 'active');
            })
            ->build();

        $this->assertSame('SELECT * FROM "users" JOIN "orders" ON "users"."id" = "orders"."user_id" AND "users"."tenant_id" = "orders"."tenant_id" AND orders.amount > ? AND orders.status = ?', $result->query);
        $this->assertSame([100, 'active'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testEqualWithMultipleValues(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('id', [1, 2, 3])])
            ->build();

        $this->assertSame(
            'SELECT * FROM "t" WHERE "id" IN (?, ?, ?)',
            $result->query
        );
        $this->assertSame([1, 2, 3], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testNotEqualSingleValue(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notEqual('status', 'deleted')])
            ->build();

        $this->assertSame(
            'SELECT * FROM "t" WHERE "status" != ?',
            $result->query
        );
        $this->assertSame(['deleted'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testContainsAllArrayFilter(): void
    {
        $query = Query::containsAll('tags', ['php', 'go']);
        $query->setOnArray(true);

        $result = (new Builder())
            ->from('docs')
            ->filter([$query])
            ->build();

        $this->assertSame('SELECT * FROM "docs" WHERE "tags" @> ?::jsonb', $result->query);
        $this->assertBindingCount($result);
    }

    public function testContainsAnyArrayFilter(): void
    {
        $query = Query::containsAny('tags', ['php', 'go']);
        $query->setOnArray(true);

        $result = (new Builder())
            ->from('docs')
            ->filter([$query])
            ->build();

        $this->assertSame('SELECT * FROM "docs" WHERE ("tags" @> ?::jsonb OR "tags" @> ?::jsonb)', $result->query);
        $this->assertBindingCount($result);
    }

    public function testNotContainsArrayFilter(): void
    {
        $query = Query::notContains('tags', ['deprecated']);
        $query->setOnArray(true);

        $result = (new Builder())
            ->from('docs')
            ->filter([$query])
            ->build();

        $this->assertSame('SELECT * FROM "docs" WHERE NOT ("tags" @> ?::jsonb)', $result->query);
        $this->assertBindingCount($result);
    }

    public function testSelectSubquery(): void
    {
        $sub = (new Builder())
            ->from('orders')
            ->count('*', 'cnt')
            ->filter([Query::equal('orders.user_id', [1])]);

        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->selectSub($sub, 'order_count')
            ->filter([Query::equal('id', [1])])
            ->build();

        $this->assertSame('SELECT "id", "name", (SELECT COUNT(*) AS "cnt" FROM "orders" WHERE "orders"."user_id" IN (?)) AS "order_count" FROM "users" WHERE "id" IN (?)', $result->query);
        $this->assertBindingCount($result);
    }

    public function testRawFilterWithMultipleBindings(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::raw('score BETWEEN ? AND ?', [10, 90])])
            ->build();

        $this->assertSame('SELECT * FROM "t" WHERE score BETWEEN ? AND ?', $result->query);
        $this->assertSame([10, 90], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testCursorBeforeDescOrder(): void
    {
        $result = (new Builder())
            ->from('t')
            ->sortDesc('id')
            ->cursorBefore(100)
            ->limit(25)
            ->build();

        $this->assertSame('SELECT * FROM "t" WHERE "_cursor" < ? ORDER BY "id" DESC LIMIT ?', $result->query);
        $this->assertContains(100, $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testSetRawInUpdate(): void
    {
        $result = (new Builder())
            ->from('counters')
            ->setRaw('count', '"count" + ?', [1])
            ->filter([Query::equal('id', ['page_views'])])
            ->update();

        $this->assertSame(
            'UPDATE "counters" SET "count" = "count" + ? WHERE "id" IN (?)',
            $result->query
        );
        $this->assertSame([1, 'page_views'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testMultipleSetRawInUpdate(): void
    {
        $result = (new Builder())
            ->from('t')
            ->setRaw('count', '"count" + ?', [1])
            ->setRaw('updated_at', 'NOW()')
            ->filter([Query::equal('id', [1])])
            ->update();

        $this->assertSame('UPDATE "t" SET "count" = "count" + ?, "updated_at" = NOW() WHERE "id" IN (?)', $result->query);
        $this->assertBindingCount($result);
    }

    public function testJsonPathInvalidOperatorThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->from('t')
            ->filterJsonPath('data', 'key', 'INVALID', 'val')
            ->build();
    }

    public function testJsonPathInvalidPathThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->from('t')
            ->filterJsonPath('data', 'key; DROP TABLE users', '=', 'val')
            ->build();
    }

    public function testDeleteWithoutConditions(): void
    {
        $result = (new Builder())
            ->from('t')
            ->delete();

        $this->assertSame('DELETE FROM "t"', $result->query);
        $this->assertSame([], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testUpdateWithMultipleFilters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->set(['status' => 'archived'])
            ->filter([
                Query::equal('active', [false]),
                Query::lessThan('updated_at', '2023-01-01'),
            ])
            ->update();

        $this->assertSame(
            'UPDATE "t" SET "status" = ? WHERE "active" IN (?) AND "updated_at" < ?',
            $result->query
        );
        $this->assertSame(['archived', false, '2023-01-01'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testPageEdgeCasePageOne(): void
    {
        $result = (new Builder())
            ->from('t')
            ->page(1, 10)
            ->build();

        $this->assertSame([10, 0], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testPageThrowsForPageZero(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->from('t')
            ->page(0, 10);
    }

    public function testTransactionMethods(): void
    {
        $builder = new Builder();

        $this->assertSame('BEGIN', $builder->begin()->query);
        $this->assertSame('COMMIT', $builder->commit()->query);
        $this->assertSame('ROLLBACK', $builder->rollback()->query);
        $this->assertSame('ROLLBACK TO SAVEPOINT "sp1"', $builder->rollbackToSavepoint('sp1')->query);
        $this->assertSame('RELEASE SAVEPOINT "sp1"', $builder->releaseSavepoint('sp1')->query);
    }

    public function testSpatialDistanceWithMeters(): void
    {
        $result = (new Builder())
            ->from('locations')
            ->filterDistance('coords', [40.7128, -74.0060], '>', 10000.0, true)
            ->build();

        $this->assertSame('SELECT * FROM "locations" WHERE ST_Distance(("coords"::geography), ST_SetSRID(ST_GeomFromText(?), 4326)::geography) > ?', $result->query);
        $this->assertSame('POINT(40.7128 -74.006)', $result->bindings[0]);
        $this->assertSame(10000.0, $result->bindings[1]);
        $this->assertBindingCount($result);
    }

    public function testCteUpdateReturning(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->set(['status' => 'processed'])
            ->filter([Query::equal('status', ['pending'])])
            ->returning(['id', 'status'])
            ->update();

        $this->assertSame(
            'UPDATE "orders" SET "status" = ? WHERE "status" IN (?) RETURNING "id", "status"',
            $result->query
        );
        $this->assertSame(['processed', 'pending'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testInsertOrIgnoreReturningPostgreSQL(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['id' => 1, 'name' => 'Alice'])
            ->returning(['id'])
            ->insertOrIgnore();

        $this->assertSame(
            'INSERT INTO "users" ("id", "name") VALUES (?, ?) ON CONFLICT DO NOTHING RETURNING "id"',
            $result->query
        );
        $this->assertBindingCount($result);
    }

    public function testUpsertSelectWithBindings(): void
    {
        $source = (new Builder())
            ->from('staging')
            ->select(['id', 'name', 'email'])
            ->filter([Query::equal('status', ['ready'])]);

        $result = (new Builder())
            ->into('users')
            ->fromSelect(['id', 'name', 'email'], $source)
            ->onConflict(['id'], ['name', 'email'])
            ->returning(['id'])
            ->upsertSelect();

        $this->assertSame('INSERT INTO "users" ("id", "name", "email") SELECT "id", "name", "email" FROM "staging" WHERE "status" IN (?) ON CONFLICT ("id") DO UPDATE SET "name" = EXCLUDED."name", "email" = EXCLUDED."email" RETURNING "id"', $result->query);
        $this->assertContains('ready', $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testMultipleHooks(): void
    {
        $hook1 = new class () implements Filter {
            public function filter(string $table): Condition
            {
                return new Condition('tenant_id = ?', [1]);
            }
        };

        $hook2 = new class () implements Filter {
            public function filter(string $table): Condition
            {
                return new Condition('deleted = ?', [false]);
            }
        };

        $result = (new Builder())
            ->from('users')
            ->addHook($hook1)
            ->addHook($hook2)
            ->build();

        $this->assertSame('SELECT * FROM "users" WHERE tenant_id = ? AND deleted = ?', $result->query);
        $this->assertSame([1, false], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testToRawSqlWithNullAndBooleans(): void
    {
        $raw = (new Builder())
            ->from('t')
            ->filter([
                Query::equal('active', [true]),
                Query::equal('deleted', [false]),
            ])
            ->toRawSql();

        $this->assertSame('SELECT * FROM "t" WHERE "active" IN (1) AND "deleted" IN (0)', $raw);
        $this->assertStringNotContainsString('?', $raw);
    }

    public function testFromSubWithFilter(): void
    {
        $sub = (new Builder())
            ->from('orders')
            ->select(['user_id', 'total'])
            ->filter([Query::greaterThan('total', 100)]);

        $result = (new Builder())
            ->fromSub($sub, 'big_orders')
            ->select(['user_id'])
            ->filter([Query::greaterThan('total', 500)])
            ->build();

        $this->assertSame('SELECT "user_id" FROM (SELECT "user_id", "total" FROM "orders" WHERE "total" > ?) AS "big_orders" WHERE "total" > ?', $result->query);
        $this->assertSame([100, 500], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testHavingRawWithGroupByAndFilter(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'cnt')
            ->sum('amount', 'total')
            ->filter([Query::equal('status', ['active'])])
            ->groupBy(['user_id'])
            ->havingRaw('SUM("amount") > ? AND COUNT(*) > ?', [1000, 5])
            ->build();

        $this->assertSame('SELECT COUNT(*) AS "cnt", SUM("amount") AS "total" FROM "orders" WHERE "status" IN (?) GROUP BY "user_id" HAVING SUM("amount") > ? AND COUNT(*) > ?', $result->query);
        $this->assertSame(['active', 1000, 5], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testNotBetweenWithOtherFilters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::notBetween('price', 50, 100),
                Query::equal('active', [true]),
                Query::isNotNull('name'),
            ])
            ->build();

        $this->assertSame(
            'SELECT * FROM "t" WHERE "price" NOT BETWEEN ? AND ? AND "active" IN (?) AND "name" IS NOT NULL',
            $result->query
        );
        $this->assertSame([50, 100, true], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testCaseExpressionWithBindingsInSelect(): void
    {
        $case = (new CaseExpression())
            ->when('price', Operator::GreaterThan, 100, 'expensive')
            ->when('price', Operator::GreaterThan, 50, 'moderate')
            ->else('cheap')
            ->alias('price_tier');

        $result = (new Builder())
            ->from('products')
            ->select(['id', 'name'])
            ->selectCase($case)
            ->filter([Query::equal('active', [true])])
            ->build();

        $this->assertSame('SELECT "id", "name", CASE WHEN "price" > ? THEN ? WHEN "price" > ? THEN ? ELSE ? END AS "price_tier" FROM "products" WHERE "active" IN (?)', $result->query);
        $this->assertSame([100, 'expensive', 50, 'moderate', 'cheap', true], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testMergeWithDeleteAction(): void
    {
        $source = (new Builder())->from('staging');

        $result = (new Builder())
            ->mergeInto('users')
            ->using($source, 'src')
            ->on('users.id = src.id')
            ->whenMatched('DELETE')
            ->whenNotMatched('INSERT (id, name) VALUES (src.id, src.name)')
            ->executeMerge();

        $this->assertSame('MERGE INTO "users" USING (SELECT * FROM "staging") AS "src" ON users.id = src.id WHEN MATCHED THEN DELETE WHEN NOT MATCHED THEN INSERT (id, name) VALUES (src.id, src.name)', $result->query);
        $this->assertBindingCount($result);
    }

    public function testFromNoneEmitsNoFromClause(): void
    {
        $result = (new Builder())
            ->fromNone()
            ->selectRaw('1 + 1')
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString('FROM', $result->query);
        $this->assertSame('SELECT 1 + 1', $result->query);
    }

    public function testSelectCastEmitsCastExpression(): void
    {
        $result = (new Builder())
            ->from('products')
            ->selectCast('price', 'DECIMAL(10, 2)', 'price_decimal')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT CAST("price" AS DECIMAL(10, 2)) AS "price_decimal" FROM "products"', $result->query);
    }

    public function testSelectCastRejectsInvalidType(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid cast type');

        (new Builder())
            ->from('t')
            ->selectCast('c', 'INT); DROP TABLE x;--', 'a');
    }

    public function testSelectWindowRejectsInvalidFunction(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid window function');

        (new Builder())
            ->from('t')
            ->selectWindow('ROW_NUMBER()); DROP --', 'w');
    }

    /**
     * @return list<array{0: string}>
     */
    public static function reservedWordsProvider(): array
    {
        return [
            ['select'],
            ['from'],
            ['where'],
            ['order'],
            ['group'],
            ['having'],
            ['user'],
            ['table'],
            ['insert'],
            ['update'],
            ['delete'],
            ['join'],
            ['on'],
            ['and'],
            ['or'],
            ['not'],
            ['in'],
            ['between'],
            ['like'],
            ['is'],
            ['null'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reservedWordsProvider')]
    public function testReservedWordInSelect(string $word): void
    {
        $result = (new Builder())
            ->from('t')
            ->select([$word])
            ->build();

        $this->assertStringContainsString('"' . $word . '"', $result->query);
        $stripped = \preg_replace('/"[^"]+"/', '', $result->query) ?? '';
        // Lowercase reserved word must not appear bare outside quotes
        $this->assertDoesNotMatchRegularExpression(
            '/(?<![A-Za-z0-9_])' . \preg_quote($word, '/') . '(?![A-Za-z0-9_])/',
            $stripped
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reservedWordsProvider')]
    public function testReservedWordInFrom(string $word): void
    {
        $result = (new Builder())
            ->from($word)
            ->build();

        $this->assertStringContainsString('FROM "' . $word . '"', $result->query);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reservedWordsProvider')]
    public function testReservedWordInFilter(string $word): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal($word, ['x'])])
            ->build();

        $this->assertStringContainsString('"' . $word . '"', $result->query);
        $this->assertSame(['x'], $result->bindings);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function unicodeIdentifiersProvider(): array
    {
        return [
            ['café'],
            ['日本'],
            ['column_with_émoji'],
            ['Ω_omega'],
            ['данные'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unicodeIdentifiersProvider')]
    public function testUnicodeIdentifierInSelect(string $identifier): void
    {
        $result = (new Builder())
            ->from('t')
            ->select([$identifier])
            ->build();

        $this->assertStringContainsString('"' . $identifier . '"', $result->query);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unicodeIdentifiersProvider')]
    public function testUnicodeIdentifierInFrom(string $identifier): void
    {
        $result = (new Builder())
            ->from($identifier)
            ->build();

        $this->assertStringContainsString('"' . $identifier . '"', $result->query);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unicodeIdentifiersProvider')]
    public function testUnicodeIdentifierInFilter(string $identifier): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal($identifier, ['x'])])
            ->build();

        $this->assertStringContainsString('"' . $identifier . '"', $result->query);
        $this->assertSame(['x'], $result->bindings);
    }

    public function testWhereColumnEmitsQualifiedIdentifiers(): void
    {
        $result = (new Builder())
            ->from('users')
            ->whereColumn('users.id', '=', 'orders.user_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "users" WHERE "users"."id" = "orders"."user_id"', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testWhereColumnRejectsUnknownOperator(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid whereColumn operator: NOT_AN_OP');

        (new Builder())
            ->from('users')
            ->whereColumn('a', 'NOT_AN_OP', 'b');
    }

    public function testWhereColumnCombinesWithFilter(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('status', ['active'])])
            ->whereColumn('users.id', '=', 'orders.user_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM "users" WHERE "status" IN (?) AND "users"."id" = "orders"."user_id"', $result->query);
        $this->assertContains('active', $result->bindings);
    }

    public function testImplementsSequences(): void
    {
        $this->assertInstanceOf(Sequences::class, new Builder());
    }

    public function testNextValEmitsSequenceCall(): void
    {
        $result = (new Builder())
            ->fromNone()
            ->nextVal('seq_user_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT nextval(\'seq_user_id\')', $result->query);
    }

    public function testCurrValEmitsSequenceCall(): void
    {
        $result = (new Builder())
            ->fromNone()
            ->currVal('seq_user_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT currval(\'seq_user_id\')', $result->query);
    }

    public function testNextValWithAlias(): void
    {
        $result = (new Builder())
            ->fromNone()
            ->nextVal('seq_user_id', 'next_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT nextval(\'seq_user_id\') AS "next_id"', $result->query);
    }

    public function testNextValRejectsInvalidName(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid sequence name');

        (new Builder())->nextVal('bad name; DROP TABLE x');
    }
}
