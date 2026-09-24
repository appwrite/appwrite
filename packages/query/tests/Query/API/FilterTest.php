<?php

namespace Tests\Query\API;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Method;
use Utopia\Query\Query;

class FilterTest extends TestCase
{
    public function testEqual(): void
    {
        $query = Query::equal('name', ['John', 'Jane']);
        $this->assertSame(Method::Equal, $query->getMethod());
        $this->assertSame('name', $query->getAttribute());
        $this->assertSame(['John', 'Jane'], $query->getValues());
    }

    public function testNotEqual(): void
    {
        $query = Query::notEqual('name', 'John');
        $this->assertSame(Method::NotEqual, $query->getMethod());
        $this->assertSame(['John'], $query->getValues());
    }

    public function testNotEqualWithList(): void
    {
        $query = Query::notEqual('name', ['John', 'Jane']);
        $this->assertSame(['John', 'Jane'], $query->getValues());
    }

    public function testNotEqualWithMap(): void
    {
        $query = Query::notEqual('data', ['key' => 'value']);
        $this->assertSame([['key' => 'value']], $query->getValues());
    }

    public function testLessThan(): void
    {
        $query = Query::lessThan('age', 30);
        $this->assertSame(Method::LessThan, $query->getMethod());
        $this->assertSame('age', $query->getAttribute());
        $this->assertSame([30], $query->getValues());
    }

    public function testLessThanEqual(): void
    {
        $query = Query::lessThanEqual('age', 30);
        $this->assertSame(Method::LessThanEqual, $query->getMethod());
        $this->assertSame([30], $query->getValues());
    }

    public function testGreaterThan(): void
    {
        $query = Query::greaterThan('age', 18);
        $this->assertSame(Method::GreaterThan, $query->getMethod());
        $this->assertSame([18], $query->getValues());
    }

    public function testGreaterThanEqual(): void
    {
        $query = Query::greaterThanEqual('age', 18);
        $this->assertSame(Method::GreaterThanEqual, $query->getMethod());
        $this->assertSame([18], $query->getValues());
    }

    public function testContains(): void
    {
        $query = Query::containsString('tags', ['php', 'js']);
        $this->assertSame(Method::Contains, $query->getMethod());
        $this->assertSame(['php', 'js'], $query->getValues());
    }

    public function testContainsAny(): void
    {
        $query = Query::containsAny('tags', ['php', 'js']);
        $this->assertSame(Method::ContainsAny, $query->getMethod());
        $this->assertSame(['php', 'js'], $query->getValues());
    }

    public function testNotContains(): void
    {
        $query = Query::notContains('tags', ['php']);
        $this->assertSame(Method::NotContains, $query->getMethod());
        $this->assertSame(['php'], $query->getValues());
    }

    public function testContainsDeprecated(): void
    {
        $query = Query::containsString('tags', ['a', 'b']);
        $this->assertSame(Method::Contains, $query->getMethod());
        $this->assertSame(['a', 'b'], $query->getValues());
    }

    public function testBetween(): void
    {
        $query = Query::between('age', 18, 65);
        $this->assertSame(Method::Between, $query->getMethod());
        $this->assertSame([18, 65], $query->getValues());
    }

    public function testNotBetween(): void
    {
        $query = Query::notBetween('age', 18, 65);
        $this->assertSame(Method::NotBetween, $query->getMethod());
        $this->assertSame([18, 65], $query->getValues());
    }

    public function testSearch(): void
    {
        $query = Query::search('content', 'hello world');
        $this->assertSame(Method::Search, $query->getMethod());
        $this->assertSame(['hello world'], $query->getValues());
    }

    public function testNotSearch(): void
    {
        $query = Query::notSearch('content', 'hello');
        $this->assertSame(Method::NotSearch, $query->getMethod());
        $this->assertSame(['hello'], $query->getValues());
    }

    public function testIsNull(): void
    {
        $query = Query::isNull('email');
        $this->assertSame(Method::IsNull, $query->getMethod());
        $this->assertSame('email', $query->getAttribute());
        $this->assertSame([], $query->getValues());
    }

    public function testIsNotNull(): void
    {
        $query = Query::isNotNull('email');
        $this->assertSame(Method::IsNotNull, $query->getMethod());
    }

    public function testStartsWith(): void
    {
        $query = Query::startsWith('name', 'Jo');
        $this->assertSame(Method::StartsWith, $query->getMethod());
        $this->assertSame(['Jo'], $query->getValues());
    }

    public function testNotStartsWith(): void
    {
        $query = Query::notStartsWith('name', 'Jo');
        $this->assertSame(Method::NotStartsWith, $query->getMethod());
    }

    public function testEndsWith(): void
    {
        $query = Query::endsWith('email', '.com');
        $this->assertSame(Method::EndsWith, $query->getMethod());
        $this->assertSame(['.com'], $query->getValues());
    }

    public function testNotEndsWith(): void
    {
        $query = Query::notEndsWith('email', '.com');
        $this->assertSame(Method::NotEndsWith, $query->getMethod());
    }

    public function testRegex(): void
    {
        $query = Query::regex('name', '^Jo.*');
        $this->assertSame(Method::Regex, $query->getMethod());
        $this->assertSame(['^Jo.*'], $query->getValues());
    }

    public function testExists(): void
    {
        $query = Query::exists(['name', 'email']);
        $this->assertSame(Method::Exists, $query->getMethod());
        $this->assertSame('', $query->getAttribute());
        $this->assertSame(['name', 'email'], $query->getValues());
    }

    public function testNotExistsArray(): void
    {
        $query = Query::notExists(['name']);
        $this->assertSame(Method::NotExists, $query->getMethod());
        $this->assertSame(['name'], $query->getValues());
    }

    public function testNotExistsScalar(): void
    {
        $query = Query::notExists('name');
        $this->assertSame(['name'], $query->getValues());
    }

    public function testCreatedBefore(): void
    {
        $query = Query::createdBefore('2024-01-01');
        $this->assertSame(Method::LessThan, $query->getMethod());
        $this->assertSame('$createdAt', $query->getAttribute());
        $this->assertSame(['2024-01-01'], $query->getValues());
    }

    public function testCreatedAfter(): void
    {
        $query = Query::createdAfter('2024-01-01');
        $this->assertSame(Method::GreaterThan, $query->getMethod());
        $this->assertSame('$createdAt', $query->getAttribute());
    }

    public function testUpdatedBefore(): void
    {
        $query = Query::updatedBefore('2024-06-01');
        $this->assertSame(Method::LessThan, $query->getMethod());
        $this->assertSame('$updatedAt', $query->getAttribute());
    }

    public function testUpdatedAfter(): void
    {
        $query = Query::updatedAfter('2024-06-01');
        $this->assertSame(Method::GreaterThan, $query->getMethod());
        $this->assertSame('$updatedAt', $query->getAttribute());
    }

    public function testCreatedBetween(): void
    {
        $query = Query::createdBetween('2024-01-01', '2024-12-31');
        $this->assertSame(Method::Between, $query->getMethod());
        $this->assertSame('$createdAt', $query->getAttribute());
        $this->assertSame(['2024-01-01', '2024-12-31'], $query->getValues());
    }

    public function testUpdatedBetween(): void
    {
        $query = Query::updatedBetween('2024-01-01', '2024-12-31');
        $this->assertSame(Method::Between, $query->getMethod());
        $this->assertSame('$updatedAt', $query->getAttribute());
    }
}
