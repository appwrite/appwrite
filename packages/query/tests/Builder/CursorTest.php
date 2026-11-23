<?php

namespace Utopia\Query\Tests\Builder;

use ArrayObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Utopia\Query\Builder\MySQL;
use Utopia\Query\Builder\PostgreSQL;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Query;

class CursorTest extends TestCase
{
    /**
     * @return array<string, array{array<mixed>|object}>
     */
    public static function rows(): array
    {
        $object = new stdClass();
        $object->_cursor = 42;

        return [
            'array' => [['_cursor' => 42, 'name' => 'a']],
            'array access object' => [new ArrayObject(['_cursor' => 42])],
            'plain object' => [$object],
        ];
    }

    /**
     * @param  array<mixed>|object  $row
     */
    #[DataProvider('rows')]
    public function testCursorAfterBindsTheRowCursorColumn(array|object $row): void
    {
        $result = new MySQL()->from('t')->cursorAfter($row)->build();

        $this->assertSame('SELECT * FROM `t` WHERE `_cursor` > ?', $result->query);
        $this->assertSame([42], $result->bindings);
    }

    /**
     * @param  array<mixed>|object  $row
     */
    #[DataProvider('rows')]
    public function testCursorBeforeBindsTheRowCursorColumn(array|object $row): void
    {
        $result = new PostgreSQL()->from('t')->cursorBefore($row)->build();

        $this->assertSame('SELECT * FROM "t" WHERE "_cursor" < ?', $result->query);
        $this->assertSame([42], $result->bindings);
    }

    public function testCursorRowWithoutCursorColumnThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Cursor row has no _cursor value');

        new MySQL()->from('t')->cursorAfter(['id' => 42])->build();
    }

    public function testCursorObjectWithoutCursorColumnThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Cursor row has no _cursor value');

        new MySQL()->from('t')->cursorBefore(new ArrayObject(['id' => 42]))->build();
    }

    public function testParsedScalarCursorBindsAsIs(): void
    {
        $result = new MySQL()
            ->from('t')
            ->queries([Query::parse('{"method":"cursorAfter","values":["abc"]}')])
            ->build();

        $this->assertSame('SELECT * FROM `t` WHERE `_cursor` > ?', $result->query);
        $this->assertSame(['abc'], $result->bindings);
    }
}
