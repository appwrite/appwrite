<?php

namespace Tests\Query\Builder\Feature;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\ClickHouse as ClickHouseBuilder;
use Utopia\Query\Builder\MySQL as MySQLBuilder;
use Utopia\Query\Query;

class BitwiseAggregatesTest extends TestCase
{
    use AssertsBindingCount;

    public function testBitAndWithAliasEmitsBitAndAndAsAlias(): void
    {
        $result = (new ClickHouseBuilder())
            ->from('events')
            ->bitAnd('flags', 'and_flags')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT BIT_AND(`flags`) AS `and_flags` FROM `events`', $result->query);
    }

    public function testBitOrWithAliasEmitsBitOr(): void
    {
        $result = (new ClickHouseBuilder())
            ->from('events')
            ->bitOr('flags', 'or_flags')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT BIT_OR(`flags`) AS `or_flags` FROM `events`', $result->query);
    }

    public function testBitXorWithAliasEmitsBitXor(): void
    {
        $result = (new ClickHouseBuilder())
            ->from('events')
            ->bitXor('flags', 'xor_flags')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT BIT_XOR(`flags`) AS `xor_flags` FROM `events`', $result->query);
    }

    public function testBitAndWithoutAliasOmitsAsClause(): void
    {
        $result = (new ClickHouseBuilder())
            ->from('events')
            ->bitAnd('flags')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT BIT_AND(`flags`) FROM `events`', $result->query);
        $this->assertStringNotContainsString('AS ``', $result->query);
    }

    public function testBitAndOnMySQLBuilderUsesSameSyntax(): void
    {
        $result = (new MySQLBuilder())
            ->from('events')
            ->bitAnd('flags', 'a')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT BIT_AND(`flags`) AS `a` FROM `events`', $result->query);
    }

    public function testBitwiseAggregateDoesNotAddBindings(): void
    {
        $result = (new ClickHouseBuilder())
            ->from('events')
            ->bitOr('flags', 'o')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame([], $result->bindings);
    }

    public function testBitAndChainedWithWhereUsesCorrectBindingOrder(): void
    {
        $result = (new ClickHouseBuilder())
            ->from('events')
            ->bitAnd('flags', 'a')
            ->filter([Query::equal('tenant', ['acme'])])
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame(['acme'], $result->bindings);
    }
}
