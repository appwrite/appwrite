<?php

namespace Tests\Query\Builder;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\Case\Expression as CaseExpression;
use Utopia\Query\Builder\Case\Operator;
use Utopia\Query\Builder\Feature\ConditionalAggregates;
use Utopia\Query\Builder\Feature\Cube;
use Utopia\Query\Builder\Feature\Hints;
use Utopia\Query\Builder\Feature\Json;
use Utopia\Query\Builder\Feature\LateralJoins;
use Utopia\Query\Builder\Feature\Rollup;
use Utopia\Query\Builder\Feature\Sequences;
use Utopia\Query\Builder\Feature\Totals;
use Utopia\Query\Builder\MariaDB as Builder;
use Utopia\Query\Builder\Statement;
use Utopia\Query\Compiler;
use Utopia\Query\Exception\UnsupportedException;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Method;
use Utopia\Query\Query;

class MariaDBTest extends TestCase
{
    use AssertsBindingCount;

    public function testImplementsCompiler(): void
    {
        $this->assertInstanceOf(Compiler::class, new Builder());
    }

    public function testInheritsRollupButNotCubeOrTotals(): void
    {
        $builder = new Builder();
        $interfaces = \class_implements($builder);

        $this->assertInstanceOf(Rollup::class, $builder);
        $this->assertArrayNotHasKey(Cube::class, $interfaces);
        $this->assertArrayNotHasKey(Totals::class, $interfaces);
    }

    public function testImplementsJson(): void
    {
        $this->assertInstanceOf(Json::class, new Builder());
    }

    public function testImplementsConditionalAggregates(): void
    {
        $this->assertInstanceOf(ConditionalAggregates::class, new Builder());
    }

    public function testImplementsHints(): void
    {
        $this->assertInstanceOf(Hints::class, new Builder());
    }

    public function testImplementsLateralJoins(): void
    {
        $this->assertInstanceOf(LateralJoins::class, new Builder());
    }

    public function testBasicSelect(): void
    {
        $result = (new Builder())
            ->from('t')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t`', $result->query);
    }

    public function testSelectWithFilters(): void
    {
        $result = (new Builder())
            ->select(['name', 'email'])
            ->from('users')
            ->filter([
                Query::equal('status', ['active']),
                Query::greaterThan('age', 18),
            ])
            ->sortAsc('name')
            ->limit(25)
            ->offset(0)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `name`, `email` FROM `users` WHERE `status` IN (?) AND `age` > ? ORDER BY `name` ASC LIMIT ? OFFSET ?',
            $result->query
        );
        $this->assertSame(['active', 18, 25, 0], $result->bindings);
    }

    public function testGeomFromTextWithoutAxisOrder(): void
    {
        $result = (new Builder())
            ->from('locations')
            ->filterIntersects('area', [1.0, 2.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `locations` WHERE ST_Intersects(`area`, ST_GeomFromText(?, 4326))', $result->query);
        $this->assertStringNotContainsString('axis-order', $result->query);
    }

    public function testFilterDistanceMetersUsesDistanceSphere(): void
    {
        $result = (new Builder())
            ->from('locations')
            ->filterDistance('coords', [40.7128, -74.0060], '<', 5000.0, true)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `locations` WHERE ST_DISTANCE_SPHERE(`coords`, ST_GeomFromText(?, 4326)) < ?', $result->query);
        $this->assertSame('POINT(40.7128 -74.006)', $result->bindings[0]);
        $this->assertSame(5000.0, $result->bindings[1]);
    }

    public function testFilterDistanceNoMetersUsesStDistance(): void
    {
        $result = (new Builder())
            ->from('locations')
            ->filterDistance('coords', [1.0, 2.0], '>', 100.0)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `locations` WHERE ST_Distance(`coords`, ST_GeomFromText(?, 4326)) > ?', $result->query);
        $this->assertStringNotContainsString('ST_DISTANCE_SPHERE', $result->query);
    }

    public function testSpatialDistanceLessThanMeters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::distanceLessThan('attr', [0, 0], 1000, true)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE ST_DISTANCE_SPHERE(`attr`, ST_GeomFromText(?, 4326)) < ?', $result->query);
    }

    public function testSpatialDistanceGreaterThanNoMeters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::distanceGreaterThan('attr', [0, 0], 500, false)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE ST_Distance(`attr`, ST_GeomFromText(?, 4326)) > ?', $result->query);
        $this->assertStringNotContainsString('ST_DISTANCE_SPHERE', $result->query);
    }

    public function testSpatialDistanceEqualMeters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::distanceEqual('attr', [10, 20], 100, true)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE ST_DISTANCE_SPHERE(`attr`, ST_GeomFromText(?, 4326)) = ?', $result->query);
    }

    public function testSpatialDistanceNotEqualNoMeters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::distanceNotEqual('attr', [10, 20], 50, false)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE ST_Distance(`attr`, ST_GeomFromText(?, 4326)) != ?', $result->query);
    }

    public function testSpatialDistanceMetersNonPointTypeThrowsValidation(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Distance in meters is not supported between');

        $query = Query::distanceLessThan('attr', [[0, 0], [1, 1], [2, 2]], 1000, true);
        $query->setAttributeType('linestring');

        (new Builder())
            ->from('t')
            ->filter([$query])
            ->build();
    }

    public function testSpatialDistanceMetersPointTypeWithPointAttribute(): void
    {
        $query = Query::distanceLessThan('attr', [10, 20], 1000, true);
        $query->setAttributeType('point');

        $result = (new Builder())
            ->from('t')
            ->filter([$query])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE ST_DISTANCE_SPHERE(`attr`, ST_GeomFromText(?, 4326)) < ?', $result->query);
    }

    public function testSpatialDistanceMetersWithEmptyAttributeTypePassesThrough(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::distanceLessThan('attr', [0, 0], 1000, true)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE ST_DISTANCE_SPHERE(`attr`, ST_GeomFromText(?, 4326)) < ?', $result->query);
    }

    public function testSpatialDistanceMetersPolygonAttributeThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Distance in meters is not supported between polygon and point');

        $query = Query::distanceLessThan('attr', [10, 20], 1000, true);
        $query->setAttributeType('polygon');

        (new Builder())
            ->from('t')
            ->filter([$query])
            ->build();
    }

    public function testSpatialDistanceNoMetersDoesNotValidateType(): void
    {
        $query = Query::distanceLessThan('attr', [[0, 0], [1, 1], [2, 2]], 1000, false);
        $query->setAttributeType('linestring');

        $result = (new Builder())
            ->from('t')
            ->filter([$query])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE ST_Distance(`attr`, ST_GeomFromText(?, 4326)) < ?', $result->query);
    }

    public function testFilterIntersectsUsesMariaDbGeomFromText(): void
    {
        $result = (new Builder())
            ->from('zones')
            ->filterIntersects('area', [1.0, 2.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `zones` WHERE ST_Intersects(`area`, ST_GeomFromText(?, 4326))', $result->query);
        $this->assertSame('POINT(1 2)', $result->bindings[0]);
    }

    public function testFilterNotIntersects(): void
    {
        $result = (new Builder())
            ->from('zones')
            ->filterNotIntersects('area', [1.0, 2.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `zones` WHERE NOT ST_Intersects(`area`, ST_GeomFromText(?, 4326))', $result->query);
    }

    public function testFilterCovers(): void
    {
        $result = (new Builder())
            ->from('zones')
            ->filterCovers('area', [1.0, 2.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `zones` WHERE ST_Contains(`area`, ST_GeomFromText(?, 4326))', $result->query);
    }

    public function testFilterSpatialEquals(): void
    {
        $result = (new Builder())
            ->from('zones')
            ->filterSpatialEquals('area', [1.0, 2.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `zones` WHERE ST_Equals(`area`, ST_GeomFromText(?, 4326))', $result->query);
    }

    public function testSpatialCrosses(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::crosses('attr', [1.0, 2.0])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE ST_Crosses(`attr`, ST_GeomFromText(?, 4326))', $result->query);
    }

    public function testSpatialTouches(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::touches('attr', [1.0, 2.0])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE ST_Touches(`attr`, ST_GeomFromText(?, 4326))', $result->query);
    }

    public function testSpatialOverlaps(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::overlaps('attr', [[0, 0], [1, 1]])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE ST_Overlaps(`attr`, ST_GeomFromText(?, 4326))', $result->query);
    }

    public function testSpatialWithLinestring(): void
    {
        $result = (new Builder())
            ->from('roads')
            ->filterIntersects('path', [[0, 0], [1, 1], [2, 2]])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('LINESTRING(0 0, 1 1, 2 2)', $result->bindings[0]);
    }

    public function testSpatialWithPolygon(): void
    {
        $result = (new Builder())
            ->from('areas')
            ->filterIntersects('zone', [[[0, 0], [1, 0], [1, 1], [0, 1], [0, 0]]])
            ->build();
        $this->assertBindingCount($result);

        /** @var string $wkt */
        $wkt = $result->bindings[0];
        $this->assertSame('POLYGON((0 0, 1 0, 1 1, 0 1, 0 0))', $wkt);
    }

    public function testInsertSingleRow(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'Alice', 'email' => 'a@b.com'])
            ->insert();
        $this->assertBindingCount($result);

        $this->assertSame(
            'INSERT INTO `users` (`name`, `email`) VALUES (?, ?)',
            $result->query
        );
        $this->assertSame(['Alice', 'a@b.com'], $result->bindings);
    }

    public function testInsertBatch(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'Alice', 'email' => 'a@b.com'])
            ->set(['name' => 'Bob', 'email' => 'b@b.com'])
            ->insert();
        $this->assertBindingCount($result);

        $this->assertSame(
            'INSERT INTO `users` (`name`, `email`) VALUES (?, ?), (?, ?)',
            $result->query
        );
    }

    public function testUpsertUsesOnDuplicateKey(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['id' => 1, 'name' => 'Alice', 'email' => 'a@b.com'])
            ->onConflict(['id'], ['name', 'email'])
            ->upsert();
        $this->assertBindingCount($result);

        $this->assertSame(
            'INSERT INTO `users` (`id`, `name`, `email`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `email` = VALUES(`email`)',
            $result->query
        );
    }

    public function testUpsertWithReturningThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('MariaDB does not support RETURNING with ON DUPLICATE KEY UPDATE');

        (new Builder())
            ->into('users')
            ->set(['id' => 1, 'name' => 'Alice', 'email' => 'a@b.com'])
            ->onConflict(['id'], ['name', 'email'])
            ->returning(['id'])
            ->upsert();
    }

    public function testInsertOrIgnore(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'John', 'email' => 'john@example.com'])
            ->insertOrIgnore();
        $this->assertBindingCount($result);

        $this->assertSame(
            'INSERT IGNORE INTO `users` (`name`, `email`) VALUES (?, ?)',
            $result->query
        );
    }

    public function testUpdateWithWhere(): void
    {
        $result = (new Builder())
            ->from('users')
            ->set(['status' => 'archived'])
            ->filter([Query::equal('status', ['inactive'])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame(
            'UPDATE `users` SET `status` = ? WHERE `status` IN (?)',
            $result->query
        );
    }

    public function testDeleteWithWhere(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::lessThan('last_login', '2024-01-01')])
            ->delete();
        $this->assertBindingCount($result);

        $this->assertSame(
            'DELETE FROM `users` WHERE `last_login` < ?',
            $result->query
        );
    }

    public function testSortRandom(): void
    {
        $result = (new Builder())
            ->from('t')
            ->sortRandom()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` ORDER BY RAND()', $result->query);
    }

    public function testRegex(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::regex('slug', '^[a-z]+$')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `slug` REGEXP ?', $result->query);
    }

    public function testSearch(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::search('content', 'hello')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE MATCH(`content`) AGAINST(? IN BOOLEAN MODE)', $result->query);
        $this->assertSame(['hello*'], $result->bindings);
    }

    public function testExplain(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('status', ['active'])])
            ->explain();

        $this->assertStringStartsWith('EXPLAIN SELECT', $result->query);
    }

    public function testExplainAnalyze(): void
    {
        $result = (new Builder())
            ->from('users')
            ->explain(true);

        $this->assertStringStartsWith('EXPLAIN ANALYZE SELECT', $result->query);
    }

    public function testTransactionStatements(): void
    {
        $builder = new Builder();

        $this->assertSame('BEGIN', $builder->begin()->query);
        $this->assertSame('COMMIT', $builder->commit()->query);
        $this->assertSame('ROLLBACK', $builder->rollback()->query);
    }

    public function testForUpdate(): void
    {
        $result = (new Builder())
            ->from('t')
            ->forUpdate()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` FOR UPDATE', $result->query);
    }

    public function testHintInSelect(): void
    {
        $result = (new Builder())
            ->from('users')
            ->hint('NO_INDEX_MERGE(users)')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT /*+ NO_INDEX_MERGE(users) */ * FROM `users`', $result->query);
    }

    public function testMaxExecutionTime(): void
    {
        $result = (new Builder())
            ->from('users')
            ->maxExecutionTime(5000)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT /*+ MAX_EXECUTION_TIME(5000) */ * FROM `users`', $result->query);
    }

    public function testSetJsonAppend(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->setJsonAppend('tags', ['new_tag'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `docs` SET `tags` = JSON_MERGE_PRESERVE(IFNULL(`tags`, JSON_ARRAY()), ?) WHERE `id` IN (?)', $result->query);
    }

    public function testSetJsonPrepend(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->setJsonPrepend('tags', ['first'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `docs` SET `tags` = JSON_MERGE_PRESERVE(?, IFNULL(`tags`, JSON_ARRAY())) WHERE `id` IN (?)', $result->query);
    }

    public function testSetJsonInsert(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->setJsonInsert('tags', 0, 'inserted')
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `docs` SET `tags` = JSON_ARRAY_INSERT(`tags`, ?, ?) WHERE `id` IN (?)', $result->query);
    }

    public function testSetJsonRemove(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->setJsonRemove('tags', 'old_tag')
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `docs` SET `tags` = JSON_REMOVE(`tags`, JSON_UNQUOTE(JSON_SEARCH(`tags`, \'one\', ?))) WHERE `id` IN (?)', $result->query);
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
            'UPDATE `docs` SET `data` = JSON_SET(`data`, ?, ?) WHERE `id` IN (?)',
            $result->query
        );
        $this->assertSame('$.name', $result->bindings[0]);
        $this->assertSame('NewValue', $result->bindings[1]);
        $this->assertSame(1, $result->bindings[2]);
    }

    public function testSetJsonIntersect(): void
    {
        $result = (new Builder())
            ->from('t')
            ->setJsonIntersect('tags', ['a', 'b'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `t` SET `tags` = (SELECT JSON_ARRAYAGG(val) FROM JSON_TABLE(`tags`, \'$[*]\' COLUMNS(val JSON PATH \'$\')) AS jt WHERE JSON_CONTAINS(?, val)) WHERE `id` IN (?)', $result->query);
    }

    public function testSetJsonDiff(): void
    {
        $result = (new Builder())
            ->from('t')
            ->setJsonDiff('tags', ['x'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `t` SET `tags` = (SELECT JSON_ARRAYAGG(val) FROM JSON_TABLE(`tags`, \'$[*]\' COLUMNS(val JSON PATH \'$\')) AS jt WHERE NOT JSON_CONTAINS(?, val)) WHERE `id` IN (?)', $result->query);
    }

    public function testSetJsonUnique(): void
    {
        $result = (new Builder())
            ->from('t')
            ->setJsonUnique('tags')
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `t` SET `tags` = (SELECT JSON_ARRAYAGG(val) FROM (SELECT DISTINCT val FROM JSON_TABLE(`tags`, \'$[*]\' COLUMNS(val JSON PATH \'$\')) AS jt) AS dt) WHERE `id` IN (?)', $result->query);
    }

    public function testFilterJsonContains(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->filterJsonContains('meta', 'admin')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `docs` WHERE JSON_CONTAINS(`meta`, ?)', $result->query);
    }

    public function testFilterJsonNotContains(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->filterJsonNotContains('meta', 'admin')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `docs` WHERE NOT JSON_CONTAINS(`meta`, ?)', $result->query);
    }

    public function testFilterJsonOverlaps(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->filterJsonOverlaps('tags', ['php', 'js'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `docs` WHERE JSON_OVERLAPS(`tags`, ?)', $result->query);
    }

    public function testFilterJsonPath(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filterJsonPath('data', 'age', '>=', 21)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE JSON_EXTRACT(`data`, \'$.age\') >= ?', $result->query);
        $this->assertSame(21, $result->bindings[0]);
    }

    public function testCountWhenWithAlias(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->countWhen('status = ?', 'active_count', 'active')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(CASE WHEN status = ? THEN 1 END) AS `active_count` FROM `orders`', $result->query);
    }

    public function testSumWhenWithAlias(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->sumWhen('amount', 'status = ?', 'total_active', 'active')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT SUM(CASE WHEN status = ? THEN `amount` END) AS `total_active` FROM `orders`', $result->query);
    }

    public function testExactSpatialDistanceMetersQuery(): void
    {
        $result = (new Builder())
            ->from('locations')
            ->filterDistance('coords', [40.7128, -74.0060], '<', 5000.0, true)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `locations` WHERE ST_DISTANCE_SPHERE(`coords`, ST_GeomFromText(?, 4326)) < ?',
            $result->query
        );
        $this->assertSame(['POINT(40.7128 -74.006)', 5000.0], $result->bindings);
    }

    public function testExactSpatialDistanceNoMetersQuery(): void
    {
        $result = (new Builder())
            ->from('locations')
            ->filterDistance('coords', [1.0, 2.0], '>', 100.0)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `locations` WHERE ST_Distance(`coords`, ST_GeomFromText(?, 4326)) > ?',
            $result->query
        );
        $this->assertSame(['POINT(1 2)', 100.0], $result->bindings);
    }

    public function testExactIntersectsQuery(): void
    {
        $result = (new Builder())
            ->from('zones')
            ->filterIntersects('area', [1.0, 2.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `zones` WHERE ST_Intersects(`area`, ST_GeomFromText(?, 4326))',
            $result->query
        );
        $this->assertSame(['POINT(1 2)'], $result->bindings);
    }

    public function testResetClearsState(): void
    {
        $builder = (new Builder())
            ->select(['name'])
            ->from('users')
            ->filter([Query::equal('x', [1])])
            ->limit(10);

        $builder->build();
        $builder->reset();

        $result = $builder
            ->from('orders')
            ->filter([Query::greaterThan('total', 100)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `orders` WHERE `total` > ?', $result->query);
        $this->assertSame([100], $result->bindings);
    }

    public function testSpatialDistanceGreaterThanMeters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::distanceGreaterThan('attr', [5, 10], 2000, true)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE ST_DISTANCE_SPHERE(`attr`, ST_GeomFromText(?, 4326)) > ?', $result->query);
    }

    public function testSpatialDistanceNotEqualMeters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::distanceNotEqual('attr', [5, 10], 500, true)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE ST_DISTANCE_SPHERE(`attr`, ST_GeomFromText(?, 4326)) != ?', $result->query);
    }

    public function testSpatialDistanceEqualNoMeters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::distanceEqual('attr', [5, 10], 500, false)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE ST_Distance(`attr`, ST_GeomFromText(?, 4326)) = ?', $result->query);
        $this->assertStringNotContainsString('ST_DISTANCE_SPHERE', $result->query);
        $this->assertSame('SELECT * FROM `t` WHERE ST_Distance(`attr`, ST_GeomFromText(?, 4326)) = ?', $result->query);
    }

    public function testSpatialDistanceWktString(): void
    {
        $query = new Query(Method::DistanceLessThan, 'coords', [['POINT(10 20)', 500.0, false]]);

        $result = (new Builder())
            ->from('t')
            ->filter([$query])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE ST_Distance(`coords`, ST_GeomFromText(?, 4326)) < ?', $result->query);
        $this->assertContains('POINT(10 20)', $result->bindings);
    }

    public function testCteJoinWhereGroupByHavingOrderLimit(): void
    {
        $cte = (new Builder())
            ->from('raw_orders')
            ->select(['customer_id', 'amount'])
            ->filter([Query::greaterThan('amount', 0)]);

        $result = (new Builder())
            ->with('filtered_orders', $cte)
            ->from('filtered_orders')
            ->join('customers', 'filtered_orders.customer_id', 'customers.id')
            ->filter([Query::equal('customers.active', [1])])
            ->sum('filtered_orders.amount', 'total')
            ->groupBy(['customers.country'])
            ->having([Query::greaterThan('total', 100)])
            ->sortDesc('total')
            ->limit(10)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('WITH `filtered_orders` AS (SELECT `customer_id`, `amount` FROM `raw_orders` WHERE `amount` > ?) SELECT SUM(`filtered_orders`.`amount`) AS `total` FROM `filtered_orders` JOIN `customers` ON `filtered_orders`.`customer_id` = `customers`.`id` WHERE `customers`.`active` IN (?) GROUP BY `customers`.`country` HAVING SUM(`filtered_orders`.`amount`) > ? ORDER BY `total` DESC LIMIT ?', $result->query);
    }

    public function testWindowFunctionWithJoin(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->join('products', 'sales.product_id', 'products.id')
            ->selectWindow('ROW_NUMBER()', 'rn', ['products.category'], ['sales.amount'])
            ->select(['products.name', 'sales.amount'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `products`.`name`, `sales`.`amount`, ROW_NUMBER() OVER (PARTITION BY `products`.`category` ORDER BY `sales`.`amount` ASC) AS `rn` FROM `sales` JOIN `products` ON `sales`.`product_id` = `products`.`id`', $result->query);
    }

    public function testMultipleWindowFunctions(): void
    {
        $result = (new Builder())
            ->from('employees')
            ->selectWindow('ROW_NUMBER()', 'rn', ['department'], ['salary'])
            ->selectWindow('RANK()', 'rnk', ['department'], ['-salary'])
            ->select(['name', 'department', 'salary'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `name`, `department`, `salary`, ROW_NUMBER() OVER (PARTITION BY `department` ORDER BY `salary` ASC) AS `rn`, RANK() OVER (PARTITION BY `department` ORDER BY `salary` DESC) AS `rnk` FROM `employees`', $result->query);
    }

    public function testJoinAggregateHaving(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->join('customers', 'orders.customer_id', 'customers.id')
            ->count('*', 'order_count')
            ->sum('orders.total', 'revenue')
            ->groupBy(['customers.country'])
            ->having([Query::greaterThan('order_count', 5)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `order_count`, SUM(`orders`.`total`) AS `revenue` FROM `orders` JOIN `customers` ON `orders`.`customer_id` = `customers`.`id` GROUP BY `customers`.`country` HAVING COUNT(*) > ?', $result->query);
    }

    public function testUnionAllWithOrderLimit(): void
    {
        $archive = (new Builder())
            ->from('orders_archive')
            ->select(['id', 'total', 'created_at'])
            ->filter([Query::greaterThan('created_at', '2023-01-01')]);

        $result = (new Builder())
            ->from('orders')
            ->select(['id', 'total', 'created_at'])
            ->unionAll($archive)
            ->sortDesc('created_at')
            ->limit(50)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT `id`, `total`, `created_at` FROM `orders` ORDER BY `created_at` DESC LIMIT ?) UNION ALL (SELECT `id`, `total`, `created_at` FROM `orders_archive` WHERE `created_at` > ?)', $result->query);
    }

    public function testSubSelectWithFilter(): void
    {
        $sub = (new Builder())
            ->from('orders')
            ->select(['customer_id'])
            ->sum('total', 'total_spent')
            ->groupBy(['customer_id']);

        $result = (new Builder())
            ->from('customers')
            ->selectSub($sub, 'spending')
            ->filter([Query::equal('active', [1])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT (SELECT SUM(`total`) AS `total_spent`, `customer_id` FROM `orders` GROUP BY `customer_id`) AS `spending` FROM `customers` WHERE `active` IN (?)', $result->query);
    }

    public function testFilterWhereInSubquery(): void
    {
        $sub = (new Builder())
            ->from('premium_users')
            ->select(['id'])
            ->filter([Query::equal('tier', ['gold'])]);

        $result = (new Builder())
            ->from('orders')
            ->filterWhereIn('user_id', $sub)
            ->filter([Query::greaterThan('total', 100)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `orders` WHERE `total` > ? AND `user_id` IN (SELECT `id` FROM `premium_users` WHERE `tier` IN (?))', $result->query);
    }

    public function testExistsSubqueryWithFilter(): void
    {
        $sub = (new Builder())
            ->from('orders')
            ->filter([Query::raw('orders.customer_id = customers.id')])
            ->filter([Query::greaterThan('total', 1000)]);

        $result = (new Builder())
            ->from('customers')
            ->filterExists($sub)
            ->filter([Query::equal('active', [1])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `customers` WHERE `active` IN (?) AND EXISTS (SELECT * FROM `orders` WHERE orders.customer_id = customers.id AND `total` > ?)', $result->query);
    }

    public function testUpsertOnDuplicateKeyUpdate(): void
    {
        $result = (new Builder())
            ->into('counters')
            ->set(['id' => 1, 'name' => 'visits', 'count' => 1])
            ->onConflict(['id'], ['count'])
            ->upsert();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT INTO `counters` (`id`, `name`, `count`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `count` = VALUES(`count`)', $result->query);
    }

    public function testInsertSelectQuery(): void
    {
        $source = (new Builder())
            ->from('staging')
            ->select(['name', 'email'])
            ->filter([Query::equal('imported', [0])]);

        $result = (new Builder())
            ->into('users')
            ->fromSelect(['name', 'email'], $source)
            ->insertSelect();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT INTO `users` (`name`, `email`) SELECT `name`, `email` FROM `staging` WHERE `imported` IN (?)', $result->query);
    }

    public function testCaseExpressionWithAggregate(): void
    {
        $case = (new CaseExpression())
            ->when('status', Operator::Equal, 'active', 'active')
            ->when('status', Operator::Equal, 'inactive', 'inactive')
            ->else('other')
            ->alias('label');

        $result = (new Builder())
            ->from('users')
            ->selectCase($case)
            ->count('*', 'cnt')
            ->groupBy(['status'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt`, CASE WHEN `status` = ? THEN ? WHEN `status` = ? THEN ? ELSE ? END AS `label` FROM `users` GROUP BY `status`', $result->query);
    }

    public function testBeforeBuildCallback(): void
    {
        $callbackCalled = false;
        $result = (new Builder())
            ->from('users')
            ->beforeBuild(function (Builder $b) use (&$callbackCalled) {
                $callbackCalled = true;
                $b->filter([Query::equal('injected', ['yes'])]);
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertTrue($callbackCalled);
        $this->assertSame('SELECT * FROM `users` WHERE `injected` IN (?)', $result->query);
    }

    public function testAfterBuildCallback(): void
    {
        $capturedQuery = '';
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('status', ['active'])])
            ->afterBuild(function (Statement $r) use (&$capturedQuery) {
                $capturedQuery = 'executed';
                return $r;
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('executed', $capturedQuery);
    }

    public function testNestedLogicalFilters(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([
                Query::or([
                    Query::and([
                        Query::equal('status', ['active']),
                        Query::greaterThan('age', 18),
                    ]),
                    Query::and([
                        Query::lessThan('score', 50),
                        Query::notEqual('role', 'admin'),
                    ]),
                ]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE ((`status` IN (?) AND `age` > ?) OR (`score` < ? AND `role` != ?))', $result->query);
    }

    public function testTripleJoin(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->join('customers', 'orders.customer_id', 'customers.id')
            ->join('products', 'orders.product_id', 'products.id')
            ->leftJoin('categories', 'products.category_id', 'categories.id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `orders` JOIN `customers` ON `orders`.`customer_id` = `customers`.`id` JOIN `products` ON `orders`.`product_id` = `products`.`id` LEFT JOIN `categories` ON `products`.`category_id` = `categories`.`id`', $result->query);
    }

    public function testSelfJoinWithAlias(): void
    {
        $result = (new Builder())
            ->from('employees', 'e')
            ->leftJoin('employees', 'e.manager_id', 'm.id', '=', 'm')
            ->select(['e.name', 'm.name'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `e`.`name`, `m`.`name` FROM `employees` AS `e` LEFT JOIN `employees` AS `m` ON `e`.`manager_id` = `m`.`id`', $result->query);
    }

    public function testDistinctWithCount(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->distinct()
            ->countDistinct('customer_id', 'unique_customers')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT DISTINCT COUNT(DISTINCT `customer_id`) AS `unique_customers` FROM `orders`', $result->query);
    }

    public function testBindingOrderVerification(): void
    {
        $cte = (new Builder())
            ->from('raw')
            ->filter([Query::greaterThan('val', 0)]);

        $result = (new Builder())
            ->with('filtered', $cte)
            ->from('filtered')
            ->filter([Query::equal('status', ['active'])])
            ->count('*', 'cnt')
            ->groupBy(['region'])
            ->having([Query::greaterThan('cnt', 5)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(0, $result->bindings[0]);
        $this->assertSame('active', $result->bindings[1]);
        $this->assertSame(5, $result->bindings[2]);
    }

    public function testCloneAndModify(): void
    {
        $original = (new Builder())
            ->from('users')
            ->filter([Query::equal('status', ['active'])]);

        $cloned = $original->clone();
        $cloned->filter([Query::greaterThan('age', 18)]);

        $origResult = $original->build();
        $clonedResult = $cloned->build();
        $this->assertBindingCount($origResult);
        $this->assertBindingCount($clonedResult);

        $this->assertStringNotContainsString('`age`', $origResult->query);
        $this->assertSame('SELECT * FROM `users` WHERE `status` IN (?) AND `age` > ?', $clonedResult->query);
    }

    public function testReadOnlyFlagOnSelect(): void
    {
        $result = (new Builder())
            ->from('users')
            ->build();
        $this->assertBindingCount($result);

        $this->assertTrue($result->readOnly);
    }

    public function testReadOnlyFlagOnUpdate(): void
    {
        $result = (new Builder())
            ->from('users')
            ->set(['status' => 'archived'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertFalse($result->readOnly);
    }

    public function testMultipleSortDirections(): void
    {
        $result = (new Builder())
            ->from('users')
            ->sortAsc('last_name')
            ->sortDesc('created_at')
            ->sortAsc('first_name')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `users` ORDER BY `last_name` ASC, `created_at` DESC, `first_name` ASC',
            $result->query
        );
    }

    public function testBooleanAndNullFilterValues(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([
                Query::equal('active', [true]),
                Query::equal('deleted', [false]),
                Query::isNull('suspended_at'),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame([true, false], $result->bindings);
        $this->assertSame('SELECT * FROM `users` WHERE `active` IN (?) AND `deleted` IN (?) AND `suspended_at` IS NULL', $result->query);
    }

    public function testGroupByMultipleColumns(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'total')
            ->groupBy(['region', 'category', 'year'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `total` FROM `orders` GROUP BY `region`, `category`, `year`', $result->query);
    }

    public function testWindowWithNamedDefinition(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->window('w', ['category'], ['date'])
            ->selectWindow('SUM(amount)', 'running', null, null, 'w')
            ->select(['category', 'date', 'amount'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `category`, `date`, `amount`, SUM(amount) OVER `w` AS `running` FROM `sales` WINDOW `w` AS (PARTITION BY `category` ORDER BY `date` ASC)', $result->query);
    }

    public function testInsertBatchMultipleRows(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'Alice', 'email' => 'a@b.com'])
            ->set(['name' => 'Bob', 'email' => 'b@b.com'])
            ->set(['name' => 'Charlie', 'email' => 'c@b.com'])
            ->insert();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT INTO `users` (`name`, `email`) VALUES (?, ?), (?, ?), (?, ?)', $result->query);
        $this->assertSame(['Alice', 'a@b.com', 'Bob', 'b@b.com', 'Charlie', 'c@b.com'], $result->bindings);
    }

    public function testDeleteWithComplexFilter(): void
    {
        $result = (new Builder())
            ->from('sessions')
            ->filter([
                Query::or([
                    Query::lessThan('expires_at', '2024-01-01'),
                    Query::equal('revoked', [1]),
                ]),
            ])
            ->delete();
        $this->assertBindingCount($result);

        $this->assertStringStartsWith('DELETE FROM `sessions`', $result->query);
        $this->assertSame('DELETE FROM `sessions` WHERE (`expires_at` < ? OR `revoked` IN (?))', $result->query);
    }

    public function testCountWhenWithGroupByAndHaving(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->countWhen('status = ?', 'completed', 'completed')
            ->countWhen('status = ?', 'pending', 'pending')
            ->groupBy(['region'])
            ->having([Query::greaterThan('completed', 10)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(CASE WHEN status = ? THEN 1 END) AS `completed`, COUNT(CASE WHEN status = ? THEN 1 END) AS `pending` FROM `orders` GROUP BY `region` HAVING `completed` > ?', $result->query);
    }

    public function testFilterWhereNotInSubquery(): void
    {
        $sub = (new Builder())
            ->from('blocked')
            ->select(['user_id']);

        $result = (new Builder())
            ->from('users')
            ->filterWhereNotIn('id', $sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE `id` NOT IN (SELECT `user_id` FROM `blocked`)', $result->query);
    }

    public function testFromSubqueryWithFilter(): void
    {
        $sub = (new Builder())
            ->from('events')
            ->select(['user_id'])
            ->count('*', 'event_count')
            ->groupBy(['user_id']);

        $result = (new Builder())
            ->fromSub($sub, 'user_events')
            ->filter([Query::greaterThan('event_count', 10)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM (SELECT COUNT(*) AS `event_count`, `user_id` FROM `events` GROUP BY `user_id`) AS `user_events` WHERE `event_count` > ?', $result->query);
    }

    public function testLimitOneOffsetZero(): void
    {
        $result = (new Builder())
            ->from('t')
            ->limit(1)
            ->offset(0)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` LIMIT ? OFFSET ?', $result->query);
        $this->assertSame([1, 0], $result->bindings);
    }

    public function testBetweenWithNotEqual(): void
    {
        $result = (new Builder())
            ->from('products')
            ->filter([
                Query::between('price', 10, 100),
                Query::notEqual('status', 'discontinued'),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `products` WHERE `price` BETWEEN ? AND ? AND `status` != ?', $result->query);
    }

    public function testIsNullIsNotNullCombined(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([
                Query::isNull('deleted_at'),
                Query::isNotNull('email'),
                Query::equal('status', ['active']),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE `deleted_at` IS NULL AND `email` IS NOT NULL AND `status` IN (?)', $result->query);
    }

    public function testCrossJoin(): void
    {
        $result = (new Builder())
            ->from('users')
            ->crossJoin('config')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` CROSS JOIN `config`', $result->query);
    }

    public function testRecursiveCte(): void
    {
        $seed = (new Builder())
            ->from('categories')
            ->select(['id', 'name', 'parent_id'])
            ->filter([Query::isNull('parent_id')]);

        $step = (new Builder())
            ->from('categories')
            ->select(['categories.id', 'categories.name', 'categories.parent_id'])
            ->join('tree', 'categories.parent_id', 'tree.id');

        $result = (new Builder())
            ->withRecursiveSeedStep('tree', $seed, $step)
            ->from('tree')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('WITH RECURSIVE `tree` AS (SELECT `id`, `name`, `parent_id` FROM `categories` WHERE `parent_id` IS NULL UNION ALL SELECT `categories`.`id`, `categories`.`name`, `categories`.`parent_id` FROM `categories` JOIN `tree` ON `categories`.`parent_id` = `tree`.`id`) SELECT * FROM `tree`', $result->query);
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

        $this->assertStringContainsString('`' . $word . '`', $result->query);
        $stripped = \preg_replace('/`[^`]+`/', '', $result->query) ?? '';
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

        $this->assertStringContainsString('FROM `' . $word . '`', $result->query);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reservedWordsProvider')]
    public function testReservedWordInFilter(string $word): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal($word, ['x'])])
            ->build();

        $this->assertStringContainsString('`' . $word . '`', $result->query);
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

        $this->assertStringContainsString('`' . $identifier . '`', $result->query);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unicodeIdentifiersProvider')]
    public function testUnicodeIdentifierInFrom(string $identifier): void
    {
        $result = (new Builder())
            ->from($identifier)
            ->build();

        $this->assertStringContainsString('`' . $identifier . '`', $result->query);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unicodeIdentifiersProvider')]
    public function testUnicodeIdentifierInFilter(string $identifier): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal($identifier, ['x'])])
            ->build();

        $this->assertStringContainsString('`' . $identifier . '`', $result->query);
        $this->assertSame(['x'], $result->bindings);
    }

    public function testWhereRawAppendsFragmentAndBindings(): void
    {
        $result = (new Builder())
            ->from('users')
            ->whereRaw('a = ?', [1])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE a = ?', $result->query);
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

        $this->assertSame('SELECT * FROM `users` WHERE `b` IN (?) AND a = ?', $result->query);
        $this->assertContains(1, $result->bindings);
        $this->assertContains(2, $result->bindings);
    }

    public function testWhereColumnEmitsQualifiedIdentifiers(): void
    {
        $result = (new Builder())
            ->from('users')
            ->whereColumn('users.id', '=', 'orders.user_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE `users`.`id` = `orders`.`user_id`', $result->query);
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

        $this->assertSame('SELECT * FROM `users` WHERE `status` IN (?) AND `users`.`id` = `orders`.`user_id`', $result->query);
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

        $this->assertSame('SELECT NEXTVAL(`seq_user_id`)', $result->query);
    }

    public function testCurrValEmitsSequenceCall(): void
    {
        $result = (new Builder())
            ->fromNone()
            ->currVal('seq_user_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT LASTVAL(`seq_user_id`)', $result->query);
    }

    public function testNextValWithAlias(): void
    {
        $result = (new Builder())
            ->fromNone()
            ->nextVal('seq_user_id', 'next_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT NEXTVAL(`seq_user_id`) AS `next_id`', $result->query);
    }

    public function testNextValRejectsInvalidName(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid sequence name');

        (new Builder())->nextVal('bad name; DROP TABLE x');
    }

    public function testGroupByTimeBucketUnsupported(): void
    {
        $this->expectException(UnsupportedException::class);

        (new Builder())
            ->from('events')
            ->count('*', 'count')
            ->groupByTimeBucket('time', '1h')
            ->build();
    }
}
