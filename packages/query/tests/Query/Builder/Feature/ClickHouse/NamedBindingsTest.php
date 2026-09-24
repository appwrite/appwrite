<?php

namespace Tests\Query\Builder\Feature\ClickHouse;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\ClickHouse as Builder;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Query;

class NamedBindingsTest extends TestCase
{
    use AssertsBindingCount;

    public function testPositionalPlaceholdersAreStillTheDefault(): void
    {
        $result = (new Builder())
            ->from('events')
            ->filter([Query::equal('tenant', ['acme'])])
            ->build();

        $this->assertSame(
            'SELECT * FROM `events` WHERE `tenant` IN (?)',
            $result->query
        );
        $this->assertSame(['acme'], $result->bindings);
        $this->assertNull($result->namedBindings);
    }

    public function testWhereChainCompilesToNamedTypedPlaceholdersWhenEnabled(): void
    {
        $result = (new Builder())
            ->useNamedBindings()
            ->withParamTypes([
                'time' => 'DateTime64(3)',
                'tenant' => 'String',
                'value' => 'Int64',
            ])
            ->from('events')
            ->filter([
                Query::greaterThan('time', '2024-01-01 00:00:00'),
                Query::equal('tenant', ['acme']),
                Query::lessThanEqual('value', 100),
            ])
            ->build();

        $this->assertSame(
            'SELECT * FROM `events`'
            . ' WHERE `time` > {param0:DateTime64(3)}'
            . ' AND `tenant` IN ({param1:String})'
            . ' AND `value` <= {param2:Int64}',
            $result->query
        );
        $this->assertSame(['2024-01-01 00:00:00', 'acme', 100], $result->bindings);
        $this->assertSame(
            [
                'param0' => '2024-01-01 00:00:00',
                'param1' => 'acme',
                'param2' => 100,
            ],
            $result->namedBindings
        );
    }

    public function testTypeInferenceFallsBackWhenNoRegistration(): void
    {
        $result = (new Builder())
            ->useNamedBindings()
            ->from('events')
            ->filter([
                Query::greaterThan('time', '2024-01-01 00:00:00'),
                Query::lessThanEqual('value', 100),
                Query::equal('tenant', [true]),
            ])
            ->build();

        $this->assertSame(
            'SELECT * FROM `events`'
            . ' WHERE `time` > {param0:String}'
            . ' AND `value` <= {param1:Int64}'
            . ' AND `tenant` IN ({param2:UInt8})',
            $result->query
        );
        $this->assertSame(
            [
                'param0' => '2024-01-01 00:00:00',
                'param1' => 100,
                'param2' => true,
            ],
            $result->namedBindings
        );
    }

    public function testRegistrationOverridesInference(): void
    {
        $result = (new Builder())
            ->useNamedBindings()
            ->withParamType('time', 'DateTime64(3)')
            ->from('events')
            ->filter([Query::greaterThan('time', '2024-01-01 00:00:00')])
            ->build();

        $this->assertSame(
            'SELECT * FROM `events` WHERE `time` > {param0:DateTime64(3)}',
            $result->query
        );
        $this->assertSame(
            ['param0' => '2024-01-01 00:00:00'],
            $result->namedBindings
        );
    }

    public function testLimitAndOffsetBindingsGetInferredTypes(): void
    {
        $result = (new Builder())
            ->useNamedBindings()
            ->from('events')
            ->limit(10)
            ->offset(20)
            ->build();

        $this->assertSame(
            'SELECT * FROM `events` LIMIT {param0:Int64} OFFSET {param1:Int64}',
            $result->query
        );
        $this->assertSame(
            ['param0' => 10, 'param1' => 20],
            $result->namedBindings
        );
    }

    public function testDeleteUsesNamedTypedPlaceholdersWhenEnabled(): void
    {
        $result = (new Builder())
            ->useNamedBindings()
            ->withParamType('time', 'DateTime64(3)')
            ->from('audit_log')
            ->filter([Query::lessThan('time', '2024-01-01 00:00:00')])
            ->delete();

        $this->assertSame(
            'DELETE FROM `audit_log` WHERE `time` < {param0:DateTime64(3)}',
            $result->query
        );
        $this->assertSame(
            ['param0' => '2024-01-01 00:00:00'],
            $result->namedBindings
        );
    }

    public function testStatementWithoutBindingsHasNoNamedBindings(): void
    {
        $result = (new Builder())
            ->useNamedBindings()
            ->from('events')
            ->build();

        $this->assertSame('SELECT * FROM `events`', $result->query);
        $this->assertNull($result->namedBindings);
    }

    public function testWithParamTypeRejectsInvalidTypeString(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())->withParamType('time', 'DROP TABLE x; --');
    }

    public function testWithParamTypeAcceptsNestedParameterizedType(): void
    {
        $result = (new Builder())
            ->useNamedBindings()
            ->withParamType('time', 'Nullable(DateTime64(3))')
            ->from('events')
            ->filter([Query::greaterThan('time', '2024-01-01 00:00:00')])
            ->build();

        $this->assertSame(
            'SELECT * FROM `events` WHERE `time` > {param0:Nullable(DateTime64(3))}',
            $result->query
        );
        $this->assertSame(
            ['param0' => '2024-01-01 00:00:00'],
            $result->namedBindings
        );
    }

    public function testResetClearsBindingMetadata(): void
    {
        $builder = (new Builder())
            ->useNamedBindings()
            ->withParamType('tenant', 'String')
            ->from('events')
            ->filter([Query::equal('tenant', ['acme'])]);

        $builder->reset();

        $result = $builder
            ->useNamedBindings()
            ->withParamType('value', 'Int64')
            ->from('events')
            ->filter([Query::greaterThan('value', 1)])
            ->build();

        $this->assertSame(
            'SELECT * FROM `events` WHERE `value` > {param0:Int64}',
            $result->query
        );
        $this->assertSame(['param0' => 1], $result->namedBindings);
    }

    public function testResetDisablesNamedBindingsMode(): void
    {
        $builder = (new Builder())
            ->useNamedBindings()
            ->withParamType('tenant', 'String')
            ->from('events')
            ->filter([Query::equal('tenant', ['acme'])]);

        $builder->reset();

        $result = $builder
            ->from('events')
            ->filter([Query::equal('tenant', ['acme'])])
            ->build();

        $this->assertSame(
            'SELECT * FROM `events` WHERE `tenant` IN (?)',
            $result->query
        );
        $this->assertSame(['acme'], $result->bindings);
        $this->assertNull($result->namedBindings);
    }

    public function testResetClearsRegisteredParamTypes(): void
    {
        $builder = (new Builder())
            ->withParamType('tenant', 'FixedString(36)');

        $builder->reset();

        $result = $builder
            ->useNamedBindings()
            ->from('events')
            ->filter([Query::equal('tenant', ['acme'])])
            ->build();

        $this->assertSame(
            'SELECT * FROM `events` WHERE `tenant` IN ({param0:String})',
            $result->query
        );
    }
}
