<?php

namespace Tests\Query\Builder\Feature\ClickHouse;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\ClickHouse as Builder;
use Utopia\Query\Builder\ClickHouse\FormattedInsertStatement;
use Utopia\Query\Exception\ValidationException;

class InsertFormatTest extends TestCase
{
    use AssertsBindingCount;

    public function testInsertFormatWithExplicitColumns(): void
    {
        $result = (new Builder())
            ->into('events')
            ->insertFormat('JSONEachRow', ['id', 'event', 'time'])
            ->insert();

        $this->assertInstanceOf(FormattedInsertStatement::class, $result);
        $this->assertSame(
            'INSERT INTO `events` (`id`, `event`, `time`) FORMAT JSONEachRow',
            $result->query
        );
        $this->assertSame([], $result->bindings);
        $this->assertSame(['id', 'event', 'time'], $result->columns);
        $this->assertSame('JSONEachRow', $result->format);
    }

    public function testInsertFormatDerivesColumnsFromSet(): void
    {
        $result = (new Builder())
            ->into('events')
            ->set(['id' => null, 'event' => null, 'time' => null])
            ->insertFormat('JSONEachRow')
            ->insert();

        $this->assertInstanceOf(FormattedInsertStatement::class, $result);
        $this->assertSame(
            'INSERT INTO `events` (`id`, `event`, `time`) FORMAT JSONEachRow',
            $result->query
        );
        $this->assertSame([], $result->bindings);
        $this->assertSame(['id', 'event', 'time'], $result->columns);
        $this->assertSame('JSONEachRow', $result->format);
    }

    public function testInsertFormatExplicitColumnsTakePrecedenceOverSet(): void
    {
        $result = (new Builder())
            ->into('events')
            ->set(['a' => 1, 'b' => 2])
            ->insertFormat('JSONEachRow', ['x', 'y'])
            ->insert();

        $this->assertInstanceOf(FormattedInsertStatement::class, $result);
        $this->assertSame(
            'INSERT INTO `events` (`x`, `y`) FORMAT JSONEachRow',
            $result->query
        );
        $this->assertSame(['x', 'y'], $result->columns);
    }

    public function testInsertFormatSupportsOtherClickHouseFormats(): void
    {
        $result = (new Builder())
            ->into('events')
            ->insertFormat('TSV', ['id', 'name'])
            ->insert();

        $this->assertInstanceOf(FormattedInsertStatement::class, $result);
        $this->assertSame(
            'INSERT INTO `events` (`id`, `name`) FORMAT TSV',
            $result->query
        );
        $this->assertSame('TSV', $result->format);
    }

    public function testInsertFormatAcceptsUnderscoreInFormatName(): void
    {
        $result = (new Builder())
            ->into('events')
            ->insertFormat('My_Format', ['id'])
            ->insert();

        $this->assertInstanceOf(FormattedInsertStatement::class, $result);
        $this->assertSame(
            'INSERT INTO `events` (`id`) FORMAT My_Format',
            $result->query
        );
        $this->assertSame('My_Format', $result->format);
    }

    public function testInsertFormatRejectsInvalidFormatName(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->into('events')
            ->insertFormat('Bad Format!', ['id']);
    }

    public function testInsertFormatRejectsEmptyColumnName(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->into('events')
            ->insertFormat('JSONEachRow', ['id', ''])
            ->insert();
    }

    public function testInsertFormatWithoutColumnsEmitsBareEnvelope(): void
    {
        $result = (new Builder())
            ->into('events')
            ->insertFormat('JSONEachRow')
            ->insert();

        $this->assertInstanceOf(FormattedInsertStatement::class, $result);
        $this->assertSame(
            'INSERT INTO `events` FORMAT JSONEachRow',
            $result->query
        );
        $this->assertSame([], $result->columns);
        $this->assertNull($result->body);
    }

    public function testInsertWithoutFormatStillEmitsValues(): void
    {
        $result = (new Builder())
            ->into('events')
            ->set(['id' => 1, 'event' => 'login'])
            ->insert();

        $this->assertNotInstanceOf(FormattedInsertStatement::class, $result);
        $this->assertSame(
            'INSERT INTO `events` (`id`, `event`) VALUES (?, ?)',
            $result->query
        );
        $this->assertSame([1, 'login'], $result->bindings);
    }

    public function testFormattedInsertStatementWithExecutorPreservesFormatMetadata(): void
    {
        $result = (new Builder())
            ->into('events')
            ->insertFormat('JSONEachRow', ['id', 'event'])
            ->insert();

        $this->assertInstanceOf(FormattedInsertStatement::class, $result);

        $executor = fn (): int => 0;
        $rebound = $result->withExecutor($executor);

        $this->assertInstanceOf(FormattedInsertStatement::class, $rebound);
        $this->assertSame($result->query, $rebound->query);
        $this->assertSame($result->bindings, $rebound->bindings);
        $this->assertSame($result->columns, $rebound->columns);
        $this->assertSame($result->format, $rebound->format);
    }

    public function testResetClearsInsertFormatState(): void
    {
        $builder = (new Builder())
            ->into('events')
            ->insertFormat('JSONEachRow', ['id']);

        $builder->reset();

        $result = $builder
            ->into('events')
            ->set(['id' => 1])
            ->insert();

        $this->assertNotInstanceOf(FormattedInsertStatement::class, $result);
        $this->assertSame('INSERT INTO `events` (`id`) VALUES (?)', $result->query);
    }
}
