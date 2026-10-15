<?php

declare(strict_types=1);

namespace Utopia\Audit\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Audit\Query;

final class QueryTest extends TestCase
{
    public function testQueryStaticFactoryMethods(): void
    {
        $query = Query::equal('userId', '123');
        $this->assertSame(Query::TYPE_EQUAL, $query->getMethod()->value);
        $this->assertSame('userId', $query->getAttribute());
        $this->assertSame(['123'], $query->getValues());

        $query = Query::lessThan('time', '2024-01-01');
        $this->assertSame(Query::TYPE_LESSER, $query->getMethod()->value);
        $this->assertSame('time', $query->getAttribute());
        $this->assertSame(['2024-01-01'], $query->getValues());

        $query = Query::greaterThan('time', '2023-01-01');
        $this->assertSame(Query::TYPE_GREATER, $query->getMethod()->value);
        $this->assertSame('time', $query->getAttribute());
        $this->assertSame(['2023-01-01'], $query->getValues());

        $query = Query::between('time', '2023-01-01', '2024-01-01');
        $this->assertSame(Query::TYPE_BETWEEN, $query->getMethod()->value);
        $this->assertSame('time', $query->getAttribute());
        $this->assertSame(['2023-01-01', '2024-01-01'], $query->getValues());

        $query = Query::containsString('event', ['create', 'update', 'delete']);
        $this->assertSame(Query::TYPE_CONTAINS, $query->getMethod()->value);
        $this->assertSame('event', $query->getAttribute());
        $this->assertSame(['create', 'update', 'delete'], $query->getValues());

        $query = Query::orderDesc('time');
        $this->assertSame(Query::TYPE_ORDER_DESC, $query->getMethod()->value);
        $this->assertSame('time', $query->getAttribute());
        $this->assertSame([], $query->getValues());

        $query = Query::orderAsc('userId');
        $this->assertSame(Query::TYPE_ORDER_ASC, $query->getMethod()->value);
        $this->assertSame('userId', $query->getAttribute());
        $this->assertSame([], $query->getValues());

        $query = Query::limit(10);
        $this->assertSame(Query::TYPE_LIMIT, $query->getMethod()->value);
        $this->assertSame('', $query->getAttribute());
        $this->assertSame([10], $query->getValues());

        $query = Query::offset(5);
        $this->assertSame(Query::TYPE_OFFSET, $query->getMethod()->value);
        $this->assertSame('', $query->getAttribute());
        $this->assertSame([5], $query->getValues());
    }

    public function testQueryParseAndToString(): void
    {
        $json = '{"method":"equal","attribute":"userId","values":["123"]}';
        $query = Query::parse($json);
        $this->assertSame(Query::TYPE_EQUAL, $query->getMethod()->value);
        $this->assertSame('userId', $query->getAttribute());
        $this->assertSame(['123'], $query->getValues());

        $query = Query::equal('event', 'create');
        $json = $query->toString();
        $this->assertJson($json);

        $parsed = Query::parse($json);
        $this->assertSame(Query::TYPE_EQUAL, $parsed->getMethod()->value);
        $this->assertSame('event', $parsed->getAttribute());
        $this->assertSame(['create'], $parsed->getValues());

        $array = $query->toArray();
        $this->assertArrayHasKey('method', $array);
        $this->assertArrayHasKey('attribute', $array);
        $this->assertArrayHasKey('values', $array);
        $this->assertEquals(Query::TYPE_EQUAL, $array['method']);
        $this->assertEquals('event', $array['attribute']);
        $this->assertEquals(['create'], $array['values']);
    }

    public function testQueryParseQueries(): void
    {
        $queries = [
            '{"method":"equal","attribute":"userId","values":["123"]}',
            '{"method":"greaterThan","attribute":"time","values":["2023-01-01"]}',
            '{"method":"limit","values":[10]}',
        ];

        $parsed = Query::parseQueries($queries);

        $this->assertCount(3, $parsed);
        $this->assertInstanceOf(Query::class, $parsed[0]);
        $this->assertInstanceOf(Query::class, $parsed[1]);
        $this->assertInstanceOf(Query::class, $parsed[2]);

        $this->assertSame(Query::TYPE_EQUAL, $parsed[0]->getMethod()->value);
        $this->assertSame(Query::TYPE_GREATER, $parsed[1]->getMethod()->value);
        $this->assertSame(Query::TYPE_LIMIT, $parsed[2]->getMethod()->value);
    }

    public function testGetValue(): void
    {
        $query = Query::equal('userId', '123');
        $this->assertEquals('123', $query->getValue());

        $query = Query::limit(10);
        $this->assertEquals(10, $query->getValue());

        $query = Query::orderAsc('time');
        $this->assertNull($query->getValue());
        $this->assertEquals('default', $query->getValue('default'));
    }

    public function testQueryWithEmptyAttribute(): void
    {
        $query = Query::limit(25);
        $this->assertSame('', $query->getAttribute());
        $this->assertSame([25], $query->getValues());

        $query = Query::offset(10);
        $this->assertSame('', $query->getAttribute());
        $this->assertSame([10], $query->getValues());
    }

    public function testQueryParseInvalidJson(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid query');

        Query::parse('{"method":"equal","attribute":"userId"');
    }

    public function testQueryParseNonArray(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid query. Must be an array');

        Query::parse('"string"');
    }

    public function testQueryParseInvalidMethodType(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid query method. Must be a string');

        Query::parse('{"method":["array"],"attribute":"test","values":[]}');
    }

    public function testQueryParseInvalidAttributeType(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid query attribute. Must be a string');

        Query::parse('{"method":"equal","attribute":123,"values":[]}');
    }

    public function testQueryParseInvalidValuesType(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid query values. Must be an array');

        Query::parse('{"method":"equal","attribute":"test","values":"string"}');
    }

    public function testQueryToStringWithComplexValues(): void
    {
        $query = Query::between('time', '2023-01-01', '2024-12-31');
        $json = $query->toString();
        $this->assertJson($json);

        $parsed = Query::parse($json);
        $this->assertSame(Query::TYPE_BETWEEN, $parsed->getMethod()->value);
        $this->assertSame('time', $parsed->getAttribute());
        $this->assertSame(['2023-01-01', '2024-12-31'], $parsed->getValues());
    }

    /**
     * @return iterable<string, array{string, string, array<mixed>, Query}>
     */
    public static function legacyTypes(): iterable
    {
        yield 'TYPE_EQUAL' => [Query::TYPE_EQUAL, 'userId', ['123'], Query::equal('userId', '123')];
        yield 'TYPE_NOT_EQUAL' => [Query::TYPE_NOT_EQUAL, 'event', ['delete'], Query::notEqual('event', 'delete')];
        yield 'TYPE_LESSER' => [Query::TYPE_LESSER, 'time', ['2024-01-01'], Query::lessThan('time', '2024-01-01')];
        yield 'TYPE_LESSER_EQUAL' => [Query::TYPE_LESSER_EQUAL, 'time', ['2024-01-01'], Query::lessThanEqual('time', '2024-01-01')];
        yield 'TYPE_GREATER' => [Query::TYPE_GREATER, 'time', ['2023-01-01'], Query::greaterThan('time', '2023-01-01')];
        yield 'TYPE_GREATER_EQUAL' => [Query::TYPE_GREATER_EQUAL, 'time', ['2023-01-01'], Query::greaterThanEqual('time', '2023-01-01')];
        yield 'TYPE_BETWEEN' => [Query::TYPE_BETWEEN, 'time', ['2023-01-01', '2024-01-01'], Query::between('time', '2023-01-01', '2024-01-01')];
        yield 'TYPE_NOT_BETWEEN' => [Query::TYPE_NOT_BETWEEN, 'time', ['2023-01-01', '2024-01-01'], Query::notBetween('time', '2023-01-01', '2024-01-01')];
        yield 'TYPE_CONTAINS' => [Query::TYPE_CONTAINS, 'event', ['create', 'update'], Query::containsString('event', ['create', 'update'])];
        yield 'TYPE_NOT_CONTAINS' => [Query::TYPE_NOT_CONTAINS, 'event', ['delete'], Query::notContains('event', ['delete'])];
        yield 'TYPE_IS_NULL' => [Query::TYPE_IS_NULL, 'userId', [], Query::isNull('userId')];
        yield 'TYPE_IS_NOT_NULL' => [Query::TYPE_IS_NOT_NULL, 'userId', [], Query::isNotNull('userId')];
        yield 'TYPE_STARTS_WITH' => [Query::TYPE_STARTS_WITH, 'event', ['users.'], Query::startsWith('event', 'users.')];
        yield 'TYPE_NOT_STARTS_WITH' => [Query::TYPE_NOT_STARTS_WITH, 'event', ['teams.'], Query::notStartsWith('event', 'teams.')];
        yield 'TYPE_ENDS_WITH' => [Query::TYPE_ENDS_WITH, 'event', ['.create'], Query::endsWith('event', '.create')];
        yield 'TYPE_NOT_ENDS_WITH' => [Query::TYPE_NOT_ENDS_WITH, 'event', ['.delete'], Query::notEndsWith('event', '.delete')];
        yield 'TYPE_REGEX' => [Query::TYPE_REGEX, 'resource', ['^user/'], Query::regex('resource', '^user/')];
        yield 'TYPE_SELECT' => [Query::TYPE_SELECT, '', ['event', 'time'], Query::select(['event', 'time'])];
        yield 'TYPE_ORDER_DESC' => [Query::TYPE_ORDER_DESC, 'time', [], Query::orderDesc('time')];
        yield 'TYPE_ORDER_ASC' => [Query::TYPE_ORDER_ASC, 'time', [], Query::orderAsc('time')];
        yield 'TYPE_ORDER_RANDOM' => [Query::TYPE_ORDER_RANDOM, '', [], Query::orderRandom()];
        yield 'TYPE_LIMIT' => [Query::TYPE_LIMIT, '', [25], Query::limit(25)];
        yield 'TYPE_OFFSET' => [Query::TYPE_OFFSET, '', [50], Query::offset(50)];
        yield 'TYPE_CURSOR_AFTER' => [Query::TYPE_CURSOR_AFTER, '', [['$id' => 'log1']], Query::cursorAfter(['$id' => 'log1'])];
        yield 'TYPE_CURSOR_BEFORE' => [Query::TYPE_CURSOR_BEFORE, '', [['$id' => 'log2']], Query::cursorBefore(['$id' => 'log2'])];
    }

    /**
     * @param array<mixed> $values
     */
    #[DataProvider('legacyTypes')]
    public function testLegacyTypeBuildsTheSameQueryAsItsFactory(string $type, string $attribute, array $values, Query $expected): void
    {
        $query = new Query($type, $attribute, $values);

        $this->assertSame($expected->getMethod(), $query->getMethod());
        $this->assertSame($expected->toArray(), $query->toArray());
        $this->assertSame($expected->toString(), $query->toString());
    }

    /**
     * @param array<mixed> $values
     */
    #[DataProvider('legacyTypes')]
    public function testLegacyTypeRoundTripsThroughParse(string $type, string $attribute, array $values, Query $expected): void
    {
        $parsed = Query::parse(new Query($type, $attribute, $values)->toString());

        $this->assertInstanceOf(Query::class, $parsed);
        $this->assertSame($expected->toArray(), $parsed->toArray());
    }

    public function testLegacyTypesParseInABatch(): void
    {
        $parsed = Query::parseQueries([
            new Query(Query::TYPE_EQUAL, 'userId', ['123'])->toString(),
            new Query(Query::TYPE_GREATER, 'time', ['2023-01-01'])->toString(),
            new Query(Query::TYPE_ORDER_DESC, 'time')->toString(),
            new Query(Query::TYPE_LIMIT, values: [10])->toString(),
        ]);

        $this->assertSame(
            [
                Query::equal('userId', '123')->toArray(),
                Query::greaterThan('time', '2023-01-01')->toArray(),
                Query::orderDesc('time')->toArray(),
                Query::limit(10)->toArray(),
            ],
            \array_map(static fn (Query $query): array => $query->toArray(), $parsed),
        );
    }

    public function testUnknownTypeIsRejectedOnConstruction(): void
    {
        $this->expectException(\ValueError::class);

        new Query('lesser', 'time', ['2024-01-01']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownTypes(): iterable
    {
        yield 'unknown name' => ['lesser'];
        yield 'wrong case' => ['EQUAL'];
        yield 'empty' => [''];
    }

    #[DataProvider('unknownTypes')]
    public function testUnknownTypeIsRejectedByParse(string $type): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid query method: ' . $type);

        Query::parse(\json_encode(['method' => $type, 'attribute' => 'time', 'values' => ['2024-01-01']], JSON_THROW_ON_ERROR));
    }
}
