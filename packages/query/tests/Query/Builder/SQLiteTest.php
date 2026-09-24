<?php

namespace Tests\Query\Builder;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\Case\Expression as CaseExpression;
use Utopia\Query\Builder\Case\Operator;
use Utopia\Query\Builder\Feature\ConditionalAggregates;
use Utopia\Query\Builder\Feature\Cube;
use Utopia\Query\Builder\Feature\Json;
use Utopia\Query\Builder\Feature\Rollup;
use Utopia\Query\Builder\Feature\Totals;
use Utopia\Query\Builder\SQLite as Builder;
use Utopia\Query\Builder\Statement;
use Utopia\Query\Compiler;
use Utopia\Query\Exception\UnsupportedException;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Query;

class SQLiteTest extends TestCase
{
    use AssertsBindingCount;

    public function testImplementsCompiler(): void
    {
        $this->assertInstanceOf(Compiler::class, new Builder());
    }

    public function testDoesNotImplementGroupByModifiers(): void
    {
        $interfaces = \class_implements(new Builder());

        $this->assertArrayNotHasKey(Rollup::class, $interfaces);
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

    public function testSortRandom(): void
    {
        $result = (new Builder())
            ->from('t')
            ->sortRandom()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` ORDER BY RANDOM()', $result->query);
    }

    public function testRegexThrowsUnsupported(): void
    {
        $this->expectException(UnsupportedException::class);
        $this->expectExceptionMessage('REGEXP is not natively supported in SQLite.');

        (new Builder())
            ->from('t')
            ->filter([Query::regex('slug', '^[a-z]+$')])
            ->build();
    }

    public function testSearchThrowsUnsupported(): void
    {
        $this->expectException(UnsupportedException::class);
        $this->expectExceptionMessage('Full-text search is not supported in the SQLite query builder.');

        (new Builder())
            ->from('t')
            ->filter([Query::search('content', 'hello')])
            ->build();
    }

    public function testNotSearchThrowsUnsupported(): void
    {
        $this->expectException(UnsupportedException::class);

        (new Builder())
            ->from('t')
            ->filter([Query::notSearch('content', 'hello')])
            ->build();
    }

    public function testUpsertUsesOnConflict(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['id' => 1, 'name' => 'Alice', 'email' => 'a@b.com'])
            ->onConflict(['id'], ['name', 'email'])
            ->upsert();
        $this->assertBindingCount($result);

        $this->assertSame(
            'INSERT INTO `users` (`id`, `name`, `email`) VALUES (?, ?, ?) ON CONFLICT (`id`) DO UPDATE SET `name` = excluded.`name`, `email` = excluded.`email`',
            $result->query
        );
        $this->assertSame([1, 'Alice', 'a@b.com'], $result->bindings);
    }

    public function testUpsertMultipleConflictKeys(): void
    {
        $result = (new Builder())
            ->into('user_roles')
            ->set(['user_id' => 1, 'role_id' => 2, 'granted_at' => '2024-01-01'])
            ->onConflict(['user_id', 'role_id'], ['granted_at'])
            ->upsert();
        $this->assertBindingCount($result);

        $this->assertSame(
            'INSERT INTO `user_roles` (`user_id`, `role_id`, `granted_at`) VALUES (?, ?, ?) ON CONFLICT (`user_id`, `role_id`) DO UPDATE SET `granted_at` = excluded.`granted_at`',
            $result->query
        );
        $this->assertSame([1, 2, '2024-01-01'], $result->bindings);
    }

    public function testUpsertWithSetRaw(): void
    {
        $result = (new Builder())
            ->into('counters')
            ->set(['id' => 1, 'count' => 1])
            ->onConflict(['id'], ['count'])
            ->upsert();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT INTO `counters` (`id`, `count`) VALUES (?, ?) ON CONFLICT (`id`) DO UPDATE SET `count` = excluded.`count`', $result->query);
    }

    public function testInsertOrIgnore(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'John', 'email' => 'john@example.com'])
            ->insertOrIgnore();
        $this->assertBindingCount($result);

        $this->assertSame(
            'INSERT OR IGNORE INTO `users` (`name`, `email`) VALUES (?, ?)',
            $result->query
        );
        $this->assertSame(['John', 'john@example.com'], $result->bindings);
    }

    public function testInsertOrIgnoreBatch(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'Alice', 'email' => 'a@b.com'])
            ->set(['name' => 'Bob', 'email' => 'b@b.com'])
            ->insertOrIgnore();
        $this->assertBindingCount($result);

        $this->assertSame(
            'INSERT OR IGNORE INTO `users` (`name`, `email`) VALUES (?, ?), (?, ?)',
            $result->query
        );
        $this->assertSame(['Alice', 'a@b.com', 'Bob', 'b@b.com'], $result->bindings);
    }

    public function testSetJsonAppend(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->setJsonAppend('tags', ['new_tag'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `docs` SET `tags` = json_group_array(value) FROM (SELECT value FROM json_each(IFNULL(`tags`, \'[]\')) UNION ALL SELECT value FROM json_each(?)) WHERE `id` IN (?)', $result->query);
    }

    public function testSetJsonPrepend(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->setJsonPrepend('tags', ['first'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `docs` SET `tags` = json_group_array(value) FROM (SELECT value FROM json_each(?) UNION ALL SELECT value FROM json_each(IFNULL(`tags`, \'[]\'))) WHERE `id` IN (?)', $result->query);
    }

    public function testSetJsonPrependOrderMatters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->setJsonPrepend('items', ['first'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `t` SET `items` = json_group_array(value) FROM (SELECT value FROM json_each(?) UNION ALL SELECT value FROM json_each(IFNULL(`items`, \'[]\'))) WHERE `id` IN (?)', $result->query);
    }

    public function testSetJsonInsert(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->setJsonInsert('tags', 0, 'inserted')
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `docs` SET `tags` = json_insert(`tags`, \'$[0]\', json(?)) WHERE `id` IN (?)', $result->query);
    }

    public function testSetJsonInsertWithIndex(): void
    {
        $result = (new Builder())
            ->from('t')
            ->setJsonInsert('items', 3, 'value')
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `t` SET `items` = json_insert(`items`, \'$[3]\', json(?)) WHERE `id` IN (?)', $result->query);
    }

    public function testSetJsonRemove(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->setJsonRemove('tags', 'old_tag')
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `docs` SET `tags` = (SELECT json_group_array(value) FROM json_each(`tags`) WHERE value != json(?)) WHERE `id` IN (?)', $result->query);
    }

    public function testSetJsonIntersect(): void
    {
        $result = (new Builder())
            ->from('t')
            ->setJsonIntersect('tags', ['a', 'b'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `t` SET `tags` = (SELECT json_group_array(value) FROM json_each(IFNULL(`tags`, \'[]\')) WHERE value IN (SELECT value FROM json_each(?))) WHERE `id` IN (?)', $result->query);
    }

    public function testSetJsonDiff(): void
    {
        $result = (new Builder())
            ->from('t')
            ->setJsonDiff('tags', ['x'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `t` SET `tags` = (SELECT json_group_array(value) FROM json_each(IFNULL(`tags`, \'[]\')) WHERE value NOT IN (SELECT value FROM json_each(?))) WHERE `id` IN (?)', $result->query);
        $this->assertContains(\json_encode(['x']), $result->bindings);
    }

    public function testSetJsonUnique(): void
    {
        $result = (new Builder())
            ->from('t')
            ->setJsonUnique('tags')
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `t` SET `tags` = (SELECT json_group_array(DISTINCT value) FROM json_each(IFNULL(`tags`, \'[]\'))) WHERE `id` IN (?)', $result->query);
    }

    public function testUpdateClearsJsonSets(): void
    {
        $builder = (new Builder())
            ->from('t')
            ->setJsonAppend('tags', ['a'])
            ->filter([Query::equal('id', [1])]);

        $result1 = $builder->update();
        $this->assertBindingCount($result1);
        $this->assertSame('UPDATE `t` SET `tags` = json_group_array(value) FROM (SELECT value FROM json_each(IFNULL(`tags`, \'[]\')) UNION ALL SELECT value FROM json_each(?)) WHERE `id` IN (?)', $result1->query);

        $builder->reset();

        $result2 = $builder
            ->from('t')
            ->set(['name' => 'test'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result2);
        $this->assertStringNotContainsString('json_group_array', $result2->query);
    }

    public function testCountWhenWithAlias(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->countWhen('status = ?', 'active_count', 'active')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(CASE WHEN status = ? THEN 1 END) AS `active_count` FROM `orders`', $result->query);
        $this->assertSame(['active'], $result->bindings);
    }

    public function testCountWhenWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->countWhen('status = ?', '', 'active')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(CASE WHEN status = ? THEN 1 END) FROM `orders`', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testSumWhenWithAlias(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->sumWhen('amount', 'status = ?', 'total_active', 'active')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT SUM(CASE WHEN status = ? THEN `amount` END) AS `total_active` FROM `orders`', $result->query);
        $this->assertSame(['active'], $result->bindings);
    }

    public function testSumWhenWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->sumWhen('amount', 'status = ?', '', 'active')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT SUM(CASE WHEN status = ? THEN `amount` END) FROM `orders`', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testAvgWhenWithAlias(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->avgWhen('amount', 'region = ?', 'avg_east', 'east')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT AVG(CASE WHEN region = ? THEN `amount` END) AS `avg_east` FROM `orders`', $result->query);
        $this->assertSame(['east'], $result->bindings);
    }

    public function testAvgWhenWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->avgWhen('amount', 'region = ?', '', 'east')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT AVG(CASE WHEN region = ? THEN `amount` END) FROM `orders`', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testMinWhenWithAlias(): void
    {
        $result = (new Builder())
            ->from('products')
            ->minWhen('price', 'category = ?', 'min_electronics', 'electronics')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT MIN(CASE WHEN category = ? THEN `price` END) AS `min_electronics` FROM `products`', $result->query);
        $this->assertSame(['electronics'], $result->bindings);
    }

    public function testMinWhenWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('products')
            ->minWhen('price', 'category = ?', '', 'electronics')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT MIN(CASE WHEN category = ? THEN `price` END) FROM `products`', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testMaxWhenWithAlias(): void
    {
        $result = (new Builder())
            ->from('products')
            ->maxWhen('price', 'category = ?', 'max_electronics', 'electronics')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT MAX(CASE WHEN category = ? THEN `price` END) AS `max_electronics` FROM `products`', $result->query);
        $this->assertSame(['electronics'], $result->bindings);
    }

    public function testMaxWhenWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('products')
            ->maxWhen('price', 'category = ?', '', 'electronics')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT MAX(CASE WHEN category = ? THEN `price` END) FROM `products`', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testSpatialDistanceThrowsUnsupported(): void
    {
        $this->expectException(UnsupportedException::class);
        $this->expectExceptionMessage('Spatial distance queries are not supported in SQLite.');

        (new Builder())
            ->from('t')
            ->filter([Query::distanceLessThan('attr', [0, 0], 1000, true)])
            ->build();
    }

    public function testSpatialPredicateThrowsUnsupported(): void
    {
        $this->expectException(UnsupportedException::class);
        $this->expectExceptionMessage('Spatial predicates are not supported in SQLite.');

        (new Builder())
            ->from('t')
            ->filter([Query::intersects('attr', [1.0, 2.0])])
            ->build();
    }

    public function testSpatialNotIntersectsThrowsUnsupported(): void
    {
        $this->expectException(UnsupportedException::class);

        (new Builder())
            ->from('t')
            ->filter([Query::notIntersects('attr', [1.0, 2.0])])
            ->build();
    }

    public function testFilterJsonContains(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->filterJsonContains('tags', ['php', 'js'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `docs` WHERE (EXISTS (SELECT 1 FROM json_each(`tags`) WHERE json_each.value = json(?)) AND EXISTS (SELECT 1 FROM json_each(`tags`) WHERE json_each.value = json(?)))', $result->query);
    }

    public function testFilterJsonNotContains(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->filterJsonNotContains('tags', ['admin'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `docs` WHERE NOT (EXISTS (SELECT 1 FROM json_each(`tags`) WHERE json_each.value = json(?)))', $result->query);
    }

    public function testFilterJsonOverlaps(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->filterJsonOverlaps('tags', ['php', 'js'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `docs` WHERE (EXISTS (SELECT 1 FROM json_each(`tags`) WHERE json_each.value = json(?)) OR EXISTS (SELECT 1 FROM json_each(`tags`) WHERE json_each.value = json(?)))', $result->query);
    }

    public function testFilterJsonPathValid(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filterJsonPath('data', 'age', '>=', 21)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE json_extract(`data`, \'$.age\') >= ?', $result->query);
        $this->assertSame(21, $result->bindings[0]);
    }

    public function testFilterJsonPathInvalidPathThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid JSON path');

        (new Builder())
            ->from('users')
            ->filterJsonPath('data', 'age; DROP TABLE users', '=', 1)
            ->build();
    }

    public function testFilterJsonPathInvalidOperatorThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid JSON path operator');

        (new Builder())
            ->from('users')
            ->filterJsonPath('data', 'age', 'LIKE', 1)
            ->build();
    }

    public function testFilterJsonPathAllOperators(): void
    {
        $operators = ['=', '!=', '<', '>', '<=', '>=', '<>'];
        foreach ($operators as $op) {
            $result = (new Builder())
                ->from('t')
                ->filterJsonPath('data', 'val', $op, 42)
                ->build();
            $this->assertBindingCount($result);

            $this->assertStringContainsString("json_extract(`data`, '$.val') {$op} ?", $result->query);
        }
    }

    public function testFilterJsonContainsSingleItem(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filterJsonContains('tags', 'php')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (EXISTS (SELECT 1 FROM json_each(`tags`) WHERE json_each.value = json(?)))', $result->query);
    }

    public function testFilterJsonContainsMultipleItems(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filterJsonContains('tags', ['php', 'js', 'rust'])
            ->build();
        $this->assertBindingCount($result);

        $count = substr_count($result->query, 'EXISTS (SELECT 1 FROM json_each(`tags`) WHERE json_each.value = json(?))');
        $this->assertSame(3, $count);
        $this->assertSame('SELECT * FROM `t` WHERE (EXISTS (SELECT 1 FROM json_each(`tags`) WHERE json_each.value = json(?)) AND EXISTS (SELECT 1 FROM json_each(`tags`) WHERE json_each.value = json(?)) AND EXISTS (SELECT 1 FROM json_each(`tags`) WHERE json_each.value = json(?)))', $result->query);
    }

    public function testFilterJsonOverlapsMultipleItems(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filterJsonOverlaps('tags', ['a', 'b', 'c'])
            ->build();
        $this->assertBindingCount($result);

        $count = substr_count($result->query, 'EXISTS (SELECT 1 FROM json_each(`tags`) WHERE json_each.value = json(?))');
        $this->assertSame(3, $count);
        $this->assertSame('SELECT * FROM `t` WHERE (EXISTS (SELECT 1 FROM json_each(`tags`) WHERE json_each.value = json(?)) OR EXISTS (SELECT 1 FROM json_each(`tags`) WHERE json_each.value = json(?)) OR EXISTS (SELECT 1 FROM json_each(`tags`) WHERE json_each.value = json(?)))', $result->query);
    }

    public function testResetClearsJsonSets(): void
    {
        $builder = new Builder();
        $builder->from('t')->setJsonAppend('tags', ['a']);
        $builder->reset();

        $result = $builder
            ->from('t')
            ->set(['name' => 'test'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString('json_group_array', $result->query);
        $this->assertSame('UPDATE `t` SET `name` = ? WHERE `id` IN (?)', $result->query);
    }

    public function testBasicSelect(): void
    {
        $result = (new Builder())
            ->from('t')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t`', $result->query);
    }

    public function testSelectWithColumns(): void
    {
        $result = (new Builder())
            ->select(['name', 'email'])
            ->from('users')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `name`, `email` FROM `users`', $result->query);
    }

    public function testFilterAndSort(): void
    {
        $result = (new Builder())
            ->select(['name'])
            ->from('users')
            ->filter([
                Query::equal('status', ['active']),
                Query::greaterThan('age', 18),
            ])
            ->sortAsc('name')
            ->limit(10)
            ->offset(5)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `name` FROM `users` WHERE `status` IN (?) AND `age` > ? ORDER BY `name` ASC LIMIT ? OFFSET ?',
            $result->query
        );
        $this->assertSame(['active', 18, 10, 5], $result->bindings);
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
        $this->assertSame(['Alice', 'a@b.com', 'Bob', 'b@b.com'], $result->bindings);
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
        $this->assertSame(['archived', 'inactive'], $result->bindings);
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
        $this->assertSame(['2024-01-01'], $result->bindings);
    }

    public function testDeleteWithoutWhere(): void
    {
        $result = (new Builder())
            ->from('users')
            ->delete();
        $this->assertBindingCount($result);

        $this->assertSame('DELETE FROM `users`', $result->query);
    }

    public function testTransactionStatements(): void
    {
        $builder = new Builder();

        $this->assertSame('BEGIN', $builder->begin()->query);
        $this->assertSame('COMMIT', $builder->commit()->query);
        $this->assertSame('ROLLBACK', $builder->rollback()->query);
    }

    public function testSavepoint(): void
    {
        $builder = new Builder();

        $this->assertSame('SAVEPOINT `sp1`', $builder->savepoint('sp1')->query);
        $this->assertSame('RELEASE SAVEPOINT `sp1`', $builder->releaseSavepoint('sp1')->query);
        $this->assertSame('ROLLBACK TO SAVEPOINT `sp1`', $builder->rollbackToSavepoint('sp1')->query);
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

    public function testForShare(): void
    {
        $result = (new Builder())
            ->from('t')
            ->forShare()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` FOR SHARE', $result->query);
    }

    public function testSetJsonAppendBindingValues(): void
    {
        $result = (new Builder())
            ->from('t')
            ->setJsonAppend('tags', ['a', 'b'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertContains(\json_encode(['a', 'b']), $result->bindings);
    }

    public function testSetJsonPrependBindingValues(): void
    {
        $result = (new Builder())
            ->from('t')
            ->setJsonPrepend('tags', ['x', 'y'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertContains(\json_encode(['x', 'y']), $result->bindings);
    }

    public function testSetJsonInsertBindingValues(): void
    {
        $result = (new Builder())
            ->from('t')
            ->setJsonInsert('items', 5, 'hello')
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertContains(\json_encode('hello'), $result->bindings);
    }

    public function testSetJsonRemoveBindingValues(): void
    {
        $result = (new Builder())
            ->from('t')
            ->setJsonRemove('tags', 'remove_me')
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertContains(\json_encode('remove_me'), $result->bindings);
    }

    public function testSetJsonIntersectBindingValues(): void
    {
        $result = (new Builder())
            ->from('t')
            ->setJsonIntersect('tags', ['keep_a', 'keep_b'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertContains(\json_encode(['keep_a', 'keep_b']), $result->bindings);
    }

    public function testSetJsonDiffBindingValues(): void
    {
        $result = (new Builder())
            ->from('t')
            ->setJsonDiff('tags', ['remove_a'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertContains(\json_encode(['remove_a']), $result->bindings);
    }

    public function testConditionalAggregatesMultipleBindings(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->countWhen('status = ? AND region = ?', 'combo', 'active', 'east')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(CASE WHEN status = ? AND region = ? THEN 1 END) AS `combo` FROM `orders`', $result->query);
        $this->assertSame(['active', 'east'], $result->bindings);
    }

    public function testSpatialDistanceGreaterThanThrows(): void
    {
        $this->expectException(UnsupportedException::class);

        (new Builder())
            ->from('t')
            ->filter([Query::distanceGreaterThan('attr', [0, 0], 500, false)])
            ->build();
    }

    public function testSpatialCrossesThrows(): void
    {
        $this->expectException(UnsupportedException::class);

        (new Builder())
            ->from('t')
            ->filter([Query::crosses('path', [1.0, 2.0])])
            ->build();
    }

    public function testSpatialTouchesThrows(): void
    {
        $this->expectException(UnsupportedException::class);

        (new Builder())
            ->from('t')
            ->filter([Query::touches('area', [1.0, 2.0])])
            ->build();
    }

    public function testSpatialOverlapsThrows(): void
    {
        $this->expectException(UnsupportedException::class);

        (new Builder())
            ->from('t')
            ->filter([Query::overlaps('area', [[0, 0], [1, 1]])])
            ->build();
    }

    public function testExactUpsertQuery(): void
    {
        $result = (new Builder())
            ->into('settings')
            ->set(['key' => 'theme', 'value' => 'dark'])
            ->onConflict(['key'], ['value'])
            ->upsert();
        $this->assertBindingCount($result);

        $this->assertSame(
            'INSERT INTO `settings` (`key`, `value`) VALUES (?, ?) ON CONFLICT (`key`) DO UPDATE SET `value` = excluded.`value`',
            $result->query
        );
        $this->assertSame(['theme', 'dark'], $result->bindings);
    }

    public function testExactInsertOrIgnoreQuery(): void
    {
        $result = (new Builder())
            ->into('t')
            ->set(['id' => 1, 'name' => 'test'])
            ->insertOrIgnore();
        $this->assertBindingCount($result);

        $this->assertSame(
            'INSERT OR IGNORE INTO `t` (`id`, `name`) VALUES (?, ?)',
            $result->query
        );
        $this->assertSame([1, 'test'], $result->bindings);
    }

    public function testExactCountWhenQuery(): void
    {
        $result = (new Builder())
            ->from('t')
            ->countWhen('active = ?', 'active_count', 1)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT COUNT(CASE WHEN active = ? THEN 1 END) AS `active_count` FROM `t`',
            $result->query
        );
        $this->assertSame([1], $result->bindings);
    }

    public function testExactSumWhenQuery(): void
    {
        $result = (new Builder())
            ->from('t')
            ->sumWhen('amount', 'type = ?', 'credit_total', 'credit')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT SUM(CASE WHEN type = ? THEN `amount` END) AS `credit_total` FROM `t`',
            $result->query
        );
        $this->assertSame(['credit'], $result->bindings);
    }

    public function testExactAvgWhenQuery(): void
    {
        $result = (new Builder())
            ->from('t')
            ->avgWhen('score', 'grade = ?', 'avg_a', 'A')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT AVG(CASE WHEN grade = ? THEN `score` END) AS `avg_a` FROM `t`',
            $result->query
        );
        $this->assertSame(['A'], $result->bindings);
    }

    public function testExactMinWhenQuery(): void
    {
        $result = (new Builder())
            ->from('t')
            ->minWhen('price', 'in_stock = ?', 'min_available', 1)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT MIN(CASE WHEN in_stock = ? THEN `price` END) AS `min_available` FROM `t`',
            $result->query
        );
        $this->assertSame([1], $result->bindings);
    }

    public function testExactMaxWhenQuery(): void
    {
        $result = (new Builder())
            ->from('t')
            ->maxWhen('price', 'in_stock = ?', 'max_available', 1)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT MAX(CASE WHEN in_stock = ? THEN `price` END) AS `max_available` FROM `t`',
            $result->query
        );
        $this->assertSame([1], $result->bindings);
    }

    public function testExactFilterJsonPathQuery(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filterJsonPath('profile', 'settings.theme', '=', 'dark')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            "SELECT * FROM `users` WHERE json_extract(`profile`, '$.settings.theme') = ?",
            $result->query
        );
        $this->assertSame(['dark'], $result->bindings);
    }

    public function testSetJsonAppendReturnsSelf(): void
    {
        $builder = new Builder();
        $returned = $builder->from('t')->setJsonAppend('tags', ['a']);
        $this->assertSame($builder, $returned);
    }

    public function testSetJsonPrependReturnsSelf(): void
    {
        $builder = new Builder();
        $returned = $builder->from('t')->setJsonPrepend('tags', ['a']);
        $this->assertSame($builder, $returned);
    }

    public function testSetJsonInsertReturnsSelf(): void
    {
        $builder = new Builder();
        $returned = $builder->from('t')->setJsonInsert('tags', 0, 'a');
        $this->assertSame($builder, $returned);
    }

    public function testSetJsonRemoveReturnsSelf(): void
    {
        $builder = new Builder();
        $returned = $builder->from('t')->setJsonRemove('tags', 'a');
        $this->assertSame($builder, $returned);
    }

    public function testSetJsonIntersectReturnsSelf(): void
    {
        $builder = new Builder();
        $returned = $builder->from('t')->setJsonIntersect('tags', ['a']);
        $this->assertSame($builder, $returned);
    }

    public function testSetJsonDiffReturnsSelf(): void
    {
        $builder = new Builder();
        $returned = $builder->from('t')->setJsonDiff('tags', ['a']);
        $this->assertSame($builder, $returned);
    }

    public function testSetJsonUniqueReturnsSelf(): void
    {
        $builder = new Builder();
        $returned = $builder->from('t')->setJsonUnique('tags');
        $this->assertSame($builder, $returned);
    }

    public function testResetReturnsSelf(): void
    {
        $builder = new Builder();
        $returned = $builder->reset();
        $this->assertSame($builder, $returned);
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

    public function testRecursiveCteWithFilter(): void
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
            ->filter([Query::notEqual('name', 'Hidden')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('WITH RECURSIVE `tree` AS (SELECT `id`, `name`, `parent_id` FROM `categories` WHERE `parent_id` IS NULL UNION ALL SELECT `categories`.`id`, `categories`.`name`, `categories`.`parent_id` FROM `categories` JOIN `tree` ON `categories`.`parent_id` = `tree`.`id`) SELECT * FROM `tree` WHERE `name` != ?', $result->query);
    }

    public function testMultipleCTEs(): void
    {
        $cte1 = (new Builder())
            ->from('orders')
            ->select(['customer_id'])
            ->sum('total', 'order_sum')
            ->groupBy(['customer_id']);

        $cte2 = (new Builder())
            ->from('customers')
            ->select(['id', 'name'])
            ->filter([Query::equal('active', [1])]);

        $result = (new Builder())
            ->with('order_sums', $cte1)
            ->with('active_customers', $cte2)
            ->from('order_sums')
            ->join('active_customers', 'order_sums.customer_id', 'active_customers.id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('WITH `order_sums` AS (SELECT SUM(`total`) AS `order_sum`, `customer_id` FROM `orders` GROUP BY `customer_id`), `active_customers` AS (SELECT `id`, `name` FROM `customers` WHERE `active` IN (?)) SELECT * FROM `order_sums` JOIN `active_customers` ON `order_sums`.`customer_id` = `active_customers`.`id`', $result->query);
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

    public function testWindowFunctionWithGroupBy(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->selectWindow('SUM(amount)', 'running', ['category'], ['date'])
            ->select(['category', 'date'])
            ->groupBy(['category', 'date'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `category`, `date`, SUM(amount) OVER (PARTITION BY `category` ORDER BY `date` ASC) AS `running` FROM `sales` GROUP BY `category`, `date`', $result->query);
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

    public function testNamedWindowDefinition(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->window('w', ['category'], ['date'])
            ->selectWindow('SUM(amount)', 'total', null, null, 'w')
            ->select(['category', 'date', 'amount'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `category`, `date`, `amount`, SUM(amount) OVER `w` AS `total` FROM `sales` WINDOW `w` AS (PARTITION BY `category` ORDER BY `date` ASC)', $result->query);
    }

    public function testJoinAggregateGroupByHaving(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->join('customers', 'orders.customer_id', 'customers.id')
            ->count('*', 'cnt')
            ->sum('orders.total', 'revenue')
            ->groupBy(['customers.country'])
            ->having([Query::greaterThan('cnt', 5)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt`, SUM(`orders`.`total`) AS `revenue` FROM `orders` JOIN `customers` ON `orders`.`customer_id` = `customers`.`id` GROUP BY `customers`.`country` HAVING COUNT(*) > ?', $result->query);
    }

    public function testSelfJoin(): void
    {
        $result = (new Builder())
            ->from('employees', 'e')
            ->leftJoin('employees', 'e.manager_id', 'm.id', '=', 'm')
            ->select(['e.name', 'm.name'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `e`.`name`, `m`.`name` FROM `employees` AS `e` LEFT JOIN `employees` AS `m` ON `e`.`manager_id` = `m`.`id`', $result->query);
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

    public function testLeftJoinWithInnerJoinCombined(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->join('customers', 'orders.customer_id', 'customers.id')
            ->leftJoin('discounts', 'orders.discount_id', 'discounts.id')
            ->filter([Query::isNotNull('customers.email')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `orders` JOIN `customers` ON `orders`.`customer_id` = `customers`.`id` LEFT JOIN `discounts` ON `orders`.`discount_id` = `discounts`.`id` WHERE `customers`.`email` IS NOT NULL', $result->query);
    }

    public function testUnionAndUnionAllMixed(): void
    {
        $q2 = (new Builder())->from('t2')->filter([Query::equal('year', [2023])]);
        $q3 = (new Builder())->from('t3')->filter([Query::equal('year', [2022])]);

        $result = (new Builder())
            ->from('t1')
            ->filter([Query::equal('year', [2024])])
            ->union($q2)
            ->unionAll($q3)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t1` WHERE `year` IN (?) UNION SELECT * FROM `t2` WHERE `year` IN (?) UNION ALL SELECT * FROM `t3` WHERE `year` IN (?)', $result->query);
        $this->assertStringNotContainsString('(SELECT', $result->query);
    }

    public function testUnionEmitsBareCompoundSelect(): void
    {
        $other = (new Builder())
            ->from('archived_users')
            ->select(['id', 'name']);

        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->union($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `id`, `name` FROM `users` UNION SELECT `id`, `name` FROM `archived_users`',
            $result->query,
        );
    }

    public function testMultipleUnions(): void
    {
        $q2 = (new Builder())->from('t2');
        $q3 = (new Builder())->from('t3');
        $q4 = (new Builder())->from('t4');

        $result = (new Builder())
            ->from('t1')
            ->union($q2)
            ->union($q3)
            ->union($q4)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(3, substr_count($result->query, 'UNION'));
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

    public function testFromSubqueryWithJoin(): void
    {
        $sub = (new Builder())
            ->from('events')
            ->select(['user_id'])
            ->count('*', 'event_count')
            ->groupBy(['user_id']);

        $result = (new Builder())
            ->fromSub($sub, 'user_events')
            ->join('users', 'user_events.user_id', 'users.id')
            ->filter([Query::greaterThan('event_count', 10)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM (SELECT COUNT(*) AS `event_count`, `user_id` FROM `events` GROUP BY `user_id`) AS `user_events` JOIN `users` ON `user_events`.`user_id` = `users`.`id` WHERE `event_count` > ?', $result->query);
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
            ->filter([Query::greaterThan('total', 50)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `orders` WHERE `total` > ? AND `user_id` IN (SELECT `id` FROM `premium_users` WHERE `tier` IN (?))', $result->query);
    }

    public function testExistsSubqueryWithFilter(): void
    {
        $sub = (new Builder())
            ->from('orders')
            ->filter([Query::raw('orders.customer_id = customers.id')])
            ->filter([Query::greaterThan('total', 500)]);

        $result = (new Builder())
            ->from('customers')
            ->filterExists($sub)
            ->filter([Query::equal('active', [1])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `customers` WHERE `active` IN (?) AND EXISTS (SELECT * FROM `orders` WHERE orders.customer_id = customers.id AND `total` > ?)', $result->query);
    }

    public function testInsertOrIgnoreVerifySyntax(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['id' => 1, 'name' => 'Test', 'email' => 'test@test.com'])
            ->insertOrIgnore();
        $this->assertBindingCount($result);

        $this->assertStringStartsWith('INSERT OR IGNORE INTO', $result->query);
        $this->assertSame('INSERT OR IGNORE INTO `users` (`id`, `name`, `email`) VALUES (?, ?, ?)', $result->query);
    }

    public function testUpsertConflictHandling(): void
    {
        $result = (new Builder())
            ->into('settings')
            ->set(['key' => 'theme', 'value' => 'dark', 'updated_at' => '2024-01-01'])
            ->onConflict(['key'], ['value', 'updated_at'])
            ->upsert();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT INTO `settings` (`key`, `value`, `updated_at`) VALUES (?, ?, ?) ON CONFLICT (`key`) DO UPDATE SET `value` = excluded.`value`, `updated_at` = excluded.`updated_at`', $result->query);
    }

    public function testCaseExpressionWithWhere(): void
    {
        $case = (new CaseExpression())
            ->when('status', Operator::Equal, 'active', 'Active')
            ->when('status', Operator::Equal, 'inactive', 'Inactive')
            ->else('Unknown')
            ->alias('label');

        $result = (new Builder())
            ->from('users')
            ->selectCase($case)
            ->filter([Query::greaterThan('age', 18)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT CASE WHEN `status` = ? THEN ? WHEN `status` = ? THEN ? ELSE ? END AS `label` FROM `users` WHERE `age` > ?', $result->query);
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

    public function testUpdateWithComplexFilter(): void
    {
        $result = (new Builder())
            ->from('users')
            ->set(['status' => 'archived'])
            ->filter([
                Query::or([
                    Query::lessThan('last_login', '2023-01-01'),
                    Query::and([
                        Query::equal('role', ['guest']),
                        Query::isNull('email_verified_at'),
                    ]),
                ]),
            ])
            ->update();
        $this->assertBindingCount($result);

        $this->assertStringStartsWith('UPDATE `users` SET', $result->query);
        $this->assertSame('UPDATE `users` SET `status` = ? WHERE (`last_login` < ? OR (`role` IN (?) AND `email_verified_at` IS NULL))', $result->query);
    }

    public function testDeleteWithSubqueryFilter(): void
    {
        $sub = (new Builder())
            ->from('blocked_ids')
            ->select(['user_id']);

        $result = (new Builder())
            ->from('sessions')
            ->filterWhereIn('user_id', $sub)
            ->delete();
        $this->assertBindingCount($result);

        $this->assertStringStartsWith('DELETE FROM `sessions`', $result->query);
        $this->assertSame('DELETE FROM `sessions` WHERE `user_id` IN (SELECT `user_id` FROM `blocked_ids`)', $result->query);
    }

    public function testNestedLogicalOperatorsDepth3(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::or([
                    Query::and([
                        Query::or([
                            Query::equal('a', [1]),
                            Query::equal('b', [2]),
                        ]),
                        Query::greaterThan('c', 3),
                    ]),
                    Query::lessThan('d', 4),
                ]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame([1, 2, 3, 4], $result->bindings);
    }

    public function testIsNullAndEqualCombined(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([
                Query::isNull('deleted_at'),
                Query::equal('status', ['active']),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE `deleted_at` IS NULL AND `status` IN (?)', $result->query);
    }

    public function testBetweenAndGreaterThanCombined(): void
    {
        $result = (new Builder())
            ->from('products')
            ->filter([
                Query::between('price', 10, 100),
                Query::greaterThan('stock', 0),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `products` WHERE `price` BETWEEN ? AND ? AND `stock` > ?', $result->query);
        $this->assertSame([10, 100, 0], $result->bindings);
    }

    public function testStartsWithAndContainsCombined(): void
    {
        $result = (new Builder())
            ->from('files')
            ->filter([
                Query::startsWith('path', '/usr'),
                Query::containsString('name', ['test']),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `files` WHERE `path` LIKE ? AND `name` LIKE ?', $result->query);
    }

    public function testDistinctWithAggregate(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->distinct()
            ->countDistinct('customer_id', 'unique_customers')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT DISTINCT COUNT(DISTINCT `customer_id`) AS `unique_customers` FROM `orders`', $result->query);
    }

    public function testMultipleOrderByColumns(): void
    {
        $result = (new Builder())
            ->from('users')
            ->sortAsc('last_name')
            ->sortAsc('first_name')
            ->sortDesc('created_at')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `users` ORDER BY `last_name` ASC, `first_name` ASC, `created_at` DESC',
            $result->query
        );
    }

    public function testEmptySelectReturnsAllColumns(): void
    {
        $result = (new Builder())
            ->from('t')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t`', $result->query);
    }

    public function testBooleanValuesInFilters(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([
                Query::equal('active', [true]),
                Query::equal('deleted', [false]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame([true, false], $result->bindings);
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
            ->limit(10)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(0, $result->bindings[0]);
        $this->assertSame('active', $result->bindings[1]);
        $this->assertSame(5, $result->bindings[2]);
        $this->assertSame(10, $result->bindings[3]);
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

    public function testResetAndRebuild(): void
    {
        $builder = (new Builder())
            ->from('users')
            ->filter([Query::equal('status', ['active'])])
            ->sortAsc('name')
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

    public function testReadOnlyFlagOnSelect(): void
    {
        $result = (new Builder())
            ->from('users')
            ->build();
        $this->assertBindingCount($result);

        $this->assertTrue($result->readOnly);
    }

    public function testReadOnlyFlagOnInsert(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'test'])
            ->insert();
        $this->assertBindingCount($result);

        $this->assertFalse($result->readOnly);
    }

    public function testMultipleSetForInsertUpdate(): void
    {
        $result = (new Builder())
            ->into('events')
            ->set(['name' => 'a', 'value' => 1])
            ->set(['name' => 'b', 'value' => 2])
            ->set(['name' => 'c', 'value' => 3])
            ->insert();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT INTO `events` (`name`, `value`) VALUES (?, ?), (?, ?), (?, ?)', $result->query);
    }

    public function testGroupByMultipleColumns(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'cnt')
            ->groupBy(['region', 'category', 'year'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `orders` GROUP BY `region`, `category`, `year`', $result->query);
    }

    public function testExplainQuery(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('status', ['active'])])
            ->explain();

        $this->assertStringStartsWith('EXPLAIN SELECT', $result->query);
        $this->assertTrue($result->readOnly);
    }

    public function testExplainAnalyzeQuery(): void
    {
        $result = (new Builder())
            ->from('users')
            ->explain(true);

        $this->assertStringStartsWith('EXPLAIN ANALYZE SELECT', $result->query);
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

    public function testInsertSelectFromSubquery(): void
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

    public function testSelectRawExpression(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select("strftime('%Y', created_at) AS year")
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT strftime(\'%Y\', created_at) AS year FROM `users`', $result->query);
    }

    public function testCountWhenWithGroupBy(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->countWhen('status = ?', 'active_count', 'active')
            ->countWhen('status = ?', 'pending_count', 'pending')
            ->groupBy(['region'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(CASE WHEN status = ? THEN 1 END) AS `active_count`, COUNT(CASE WHEN status = ? THEN 1 END) AS `pending_count` FROM `orders` GROUP BY `region`', $result->query);
    }

    public function testNotBetweenFilter(): void
    {
        $result = (new Builder())
            ->from('products')
            ->filter([Query::notBetween('price', 10, 50)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `products` WHERE `price` NOT BETWEEN ? AND ?', $result->query);
        $this->assertSame([10, 50], $result->bindings);
    }

    public function testMultipleFilterTypes(): void
    {
        $result = (new Builder())
            ->from('products')
            ->filter([
                Query::greaterThan('price', 10),
                Query::startsWith('name', 'Pro'),
                Query::containsString('description', ['premium']),
                Query::isNotNull('sku'),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `products` WHERE `price` > ? AND `name` LIKE ? AND `description` LIKE ? AND `sku` IS NOT NULL', $result->query);
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

        $this->assertSame('SELECT CAST(`price` AS DECIMAL(10, 2)) AS `price_decimal` FROM `products`', $result->query);
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

}
