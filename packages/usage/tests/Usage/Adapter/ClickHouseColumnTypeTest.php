<?php

namespace Utopia\Tests\Adapter;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Utopia\Usage\Adapter\ClickHouse as ClickHouseAdapter;

/**
 * Pure unit test (no live ClickHouse) for the private column-type mapping.
 * The adapter constructor builds a lazy transport client and never connects,
 * so getColumnType() can be exercised via reflection to assert the
 * LowCardinality vs Nullable(String) decision per dimension column.
 */
class ClickHouseColumnTypeTest extends TestCase
{
    private ClickHouseAdapter $adapter;

    private ReflectionMethod $getColumnType;

    protected function setUp(): void
    {
        $this->adapter = new ClickHouseAdapter(
            'clickhouse',
            'default',
            '',
            8123,
            false,
            namespace: 'utopia_usage_coltype',
        );

        $this->getColumnType = new ReflectionMethod(ClickHouseAdapter::class, 'getColumnType');
        $this->getColumnType->setAccessible(true);
    }

    private function columnType(string $id, string $type = 'event'): string
    {
        /** @var string $columnType */
        $columnType = $this->getColumnType->invoke($this->adapter, $id, $type);
        return $columnType;
    }

    /**
     * Lower-cardinality premium geo dims must map to LowCardinality(Nullable(String)).
     */
    public function testLowCardinalityPremiumGeoColumns(): void
    {
        foreach ([
            'continentCode', 'subdivisions', 'connectionType',
            'connectionUsageType', 'autonomousSystemNumber',
        ] as $col) {
            $this->assertSame(
                'LowCardinality(Nullable(String))',
                $this->columnType($col),
                "{$col} should be LowCardinality(Nullable(String))"
            );
        }
    }

    /**
     * High-cardinality premium geo dims must fall through to Nullable(String).
     */
    public function testHighCardinalityPremiumGeoColumns(): void
    {
        foreach ([
            'city', 'isp', 'autonomousSystemOrganization', 'connectionOrganization',
        ] as $col) {
            $this->assertSame(
                'Nullable(String)',
                $this->columnType($col),
                "{$col} should be plain Nullable(String)"
            );
        }
    }

    /**
     * Low-cardinality request-attribute and premium-geo dims added in #36/#37
     * must map to LowCardinality(Nullable(String)).
     */
    public function testLowCardinalityRequestAndGeoColumns(): void
    {
        foreach (['protocol', 'ipReputation', 'timeZone', 'weatherCode'] as $col) {
            $this->assertSame(
                'LowCardinality(Nullable(String))',
                $this->columnType($col),
                "{$col} should be LowCardinality(Nullable(String))"
            );
        }
    }

    /**
     * High-cardinality dims — un-normalized request text (accept/acceptLanguage/
     * queryKeys) and premium-geo — must fall through to plain Nullable(String).
     */
    public function testHighCardinalityRequestAndGeoColumns(): void
    {
        foreach ([
            'accept', 'acceptLanguage', 'queryKeys',
            'postalCode', 'latitude', 'longitude',
        ] as $col) {
            $this->assertSame(
                'Nullable(String)',
                $this->columnType($col),
                "{$col} should be plain Nullable(String)"
            );
        }
    }

    /**
     * SDK dims must map to LowCardinality(Nullable(String)).
     */
    public function testLowCardinalitySdkColumns(): void
    {
        foreach (['sdk', 'sdkVersion'] as $col) {
            $this->assertSame(
                'LowCardinality(Nullable(String))',
                $this->columnType($col),
                "{$col} should be LowCardinality(Nullable(String))"
            );
        }
    }

    /**
     * The gauge replica ordinal holds a handful of distinct values, so it
     * must map to LowCardinality(Nullable(String)).
     */
    public function testLowCardinalityOrdinalColumn(): void
    {
        $this->assertSame(
            'LowCardinality(Nullable(String))',
            $this->columnType('ordinal', 'gauge'),
            'ordinal should be LowCardinality(Nullable(String))'
        );
    }
}
