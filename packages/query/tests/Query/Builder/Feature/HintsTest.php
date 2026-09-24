<?php

namespace Tests\Query\Builder\Feature;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\ClickHouse as ClickHouseBuilder;
use Utopia\Query\Builder\MariaDB as MariaDBBuilder;
use Utopia\Query\Builder\MySQL as MySQLBuilder;
use Utopia\Query\Exception\ValidationException;

class HintsTest extends TestCase
{
    use AssertsBindingCount;

    public function testSingleHintEmitsOptimizerComment(): void
    {
        $result = (new MySQLBuilder())
            ->from('users')
            ->hint('NO_INDEX_MERGE(users)')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame(
            'SELECT /*+ NO_INDEX_MERGE(users) */ * FROM `users`',
            $result->query
        );
    }

    public function testMultipleHintsAreSpaceSeparatedInsideSingleComment(): void
    {
        $result = (new MySQLBuilder())
            ->from('users')
            ->hint('NO_INDEX_MERGE(users)')
            ->hint('BKA(users)')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT /*+ NO_INDEX_MERGE(users) BKA(users) */ * FROM `users`', $result->query);
    }

    public function testHintAcceptsBacktickedIdentifier(): void
    {
        $result = (new MySQLBuilder())
            ->from('users')
            ->hint('INDEX(`users` `idx_users_age`)')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT /*+ INDEX(`users` `idx_users_age`) */ * FROM `users`', $result->query);
    }

    public function testHintAcceptsSetVarStyle(): void
    {
        $result = (new MySQLBuilder())
            ->from('users')
            ->hint('SET_VAR(sort_buffer_size=16M)')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT /*+ SET_VAR(sort_buffer_size=16M) */ * FROM `users`', $result->query);
    }

    public function testHintRejectsSemicolonInjection(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid hint');

        (new MySQLBuilder())
            ->from('users')
            ->hint('DROP TABLE users; --');
    }

    public function testHintRejectsBlockCommentCloser(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid hint');

        (new MySQLBuilder())
            ->from('users')
            ->hint('foo */ UNION SELECT');
    }

    public function testHintRejectsEmptyStringOnMySQL(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid hint');

        (new MySQLBuilder())
            ->from('users')
            ->hint('');
    }

    public function testHintIsInheritedByMariaDB(): void
    {
        $result = (new MariaDBBuilder())
            ->from('users')
            ->hint('NO_INDEX_MERGE(users)')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT /*+ NO_INDEX_MERGE(users) */ * FROM `users`', $result->query);
    }

    public function testClickHouseHintEmitsSettingsClause(): void
    {
        $result = (new ClickHouseBuilder())
            ->from('events')
            ->hint('max_threads=2')
            ->build();

        $this->assertBindingCount($result);
        // ClickHouse emits hints as SETTINGS, not as an optimizer comment.
        $this->assertSame('SELECT * FROM `events` SETTINGS max_threads=2', $result->query);
    }

    public function testClickHouseHintRejectsBacktickSyntax(): void
    {
        // ClickHouse uses a stricter regex that forbids backticks and parens.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid hint');

        (new ClickHouseBuilder())
            ->from('events')
            ->hint('INDEX(`users` `idx_users_age`)');
    }

    public function testChainableReturnsSameInstance(): void
    {
        $builder = new MySQLBuilder();
        $returned = $builder->from('t')->hint('BKA(t)');

        $this->assertSame($builder, $returned);
    }
}
