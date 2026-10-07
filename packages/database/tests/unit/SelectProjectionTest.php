<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory as CacheMemory;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory as DatabaseMemory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

/**
 * Drives Database::find(), the entry point the HTTP layer calls, rather than the
 * validator alone.
 *
 * A malformed `select` value reached str_contains() and raised a TypeError. A
 * TypeError is an Error, not an Exception, so it escaped the QueryException catch
 * in the callers and surfaced as a 500 — where every other query method answers the
 * same malformation with a typed refusal that becomes a 400.
 */
class SelectProjectionTest extends TestCase
{
    private Database $database;

    protected function setUp(): void
    {
        $this->database = new Database(new DatabaseMemory(), new Cache(new CacheMemory()));
        $this->database
            ->setDatabase('utopiaTests')
            ->setNamespace('select_' . \uniqid());

        $this->database->create();
        $this->database->createCollection('widgets');
        $this->database->createAttribute('widgets', 'sku', Database::VAR_STRING, 255, false);
        $this->database->createDocument('widgets', new Document([
            '$id' => 'widget',
            '$permissions' => [Permission::read(Role::any())],
            'sku' => 'abc',
        ]));
    }

    /**
     * @param array<mixed> $values
     *
     * @dataProvider malformedSelections
     */
    public function testAMalformedSelectionIsRefusedRatherThanFatal(array $values): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Attribute selection must be a string, got');

        $this->database->find('widgets', [Query::select($values)]);
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function malformedSelections(): array
    {
        return [
            'nested array' => [[['sku']]],
            'nested wildcard' => [[['*']]],
            'mixed flat and nested' => [['sku', ['x']]],
            'two nested values' => [[['a'], ['b']]],
        ];
    }

    /**
     * The refusal must be a catchable Exception. A TypeError is an Error, so a caller
     * catching Exception — as the HTTP layer does — never sees it and returns a 500.
     *
     * @param array<mixed> $values
     *
     * @dataProvider malformedSelections
     */
    public function testTheRefusalIsCatchableAsAnException(array $values): void
    {
        $caught = null;

        try {
            $this->database->find('widgets', [Query::select($values)]);
        } catch (\Exception $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(
            QueryException::class,
            $caught,
            'a malformed selection must be catchable as an Exception, otherwise it escapes as a 500',
        );
    }

    public function testTheLegitimateFlatFormStillProjects(): void
    {
        $rows = $this->database->find('widgets', [Query::select(['sku'])]);

        $this->assertCount(1, $rows);
        $this->assertSame('abc', $rows[0]->getAttribute('sku'));
    }

    public function testTheWildcardStillProjects(): void
    {
        $this->assertCount(1, $this->database->find('widgets', [Query::select(['*'])]));
    }

    /**
     * The type check must not swallow the schema check that already worked.
     */
    public function testAnUnknownAttributeIsStillRefusedBySchema(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Attribute not found in schema: nope');

        $this->database->find('widgets', [Query::select(['nope'])]);
    }
}
