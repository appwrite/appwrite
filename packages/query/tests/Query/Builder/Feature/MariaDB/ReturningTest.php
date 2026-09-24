<?php

namespace Tests\Query\Builder\Feature\MariaDB;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\MariaDB as Builder;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Query;

class ReturningTest extends TestCase
{
    use AssertsBindingCount;

    public function testInsertReturningColumnsAreBacktickQuoted(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'John'])
            ->returning(['id', 'name'])
            ->insert();

        $this->assertBindingCount($result);
        $this->assertSame('INSERT INTO `users` (`name`) VALUES (?) RETURNING `id`, `name`', $result->query);
    }

    public function testReturningDefaultIsStarWildcard(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'John'])
            ->returning()
            ->insert();

        $this->assertBindingCount($result);
        $this->assertSame('INSERT INTO `users` (`name`) VALUES (?) RETURNING *', $result->query);
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
        $this->assertSame('UPDATE `users` SET `name` = ? WHERE `id` IN (?) RETURNING `id`', $result->query);
    }

    public function testDeleteReturningEmitsReturningClause(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('id', [1])])
            ->returning(['id'])
            ->delete();

        $this->assertBindingCount($result);
        $this->assertSame('DELETE FROM `users` WHERE `id` IN (?) RETURNING `id`', $result->query);
    }

    public function testInsertOrIgnoreReturningEmitsReturningClause(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'John'])
            ->returning(['id'])
            ->insertOrIgnore();

        $this->assertBindingCount($result);
        $this->assertSame('INSERT IGNORE INTO `users` (`name`) VALUES (?) RETURNING `id`', $result->query);
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
        $this->assertSame(['Jane', 42], $result->bindings);
    }

    public function testUpsertWithReturningThrows(): void
    {
        // MariaDB's ON DUPLICATE KEY UPDATE path cannot coexist with RETURNING.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('MariaDB does not support RETURNING with ON DUPLICATE KEY UPDATE');

        (new Builder())
            ->into('users')
            ->set(['id' => 1, 'name' => 'Alice'])
            ->onConflict(['id'], ['name'])
            ->returning(['id'])
            ->upsert();
    }

    public function testUpsertSelectWithReturningThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('MariaDB does not support RETURNING with ON DUPLICATE KEY UPDATE');

        $source = (new Builder())
            ->from('staging')
            ->select(['id', 'name']);

        (new Builder())
            ->into('users')
            ->fromSelect(['id', 'name'], $source)
            ->onConflict(['id'], ['name'])
            ->returning(['id'])
            ->upsertSelect();
    }

    public function testUpsertAfterReturningClearedSucceeds(): void
    {
        // Clearing RETURNING with returning([]) allows upsert() through.
        $result = (new Builder())
            ->into('users')
            ->set(['id' => 1, 'name' => 'Alice'])
            ->onConflict(['id'], ['name'])
            ->returning(['id'])
            ->returning([])
            ->upsert();

        $this->assertBindingCount($result);
        $this->assertSame('INSERT INTO `users` (`id`, `name`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `name` = VALUES(`name`)', $result->query);
        $this->assertStringNotContainsString('RETURNING', $result->query);
    }

    public function testChainableReturnsSameInstance(): void
    {
        $builder = new Builder();
        $returned = $builder->into('users')->set(['name' => 'x'])->returning(['id']);

        $this->assertSame($builder, $returned);
    }
}
