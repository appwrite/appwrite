<?php

namespace Utopia\Tests\Usage;

use PHPUnit\Framework\TestCase;
use Utopia\Usage\Metric;

class MetricTest extends TestCase
{
    /**
     * Test Metric::getEventSchema() returns correct attribute definitions
     */
    public function testGetEventSchemaReturnsAttributeDefinitions(): void
    {
        $schema = Metric::getEventSchema();

        // 3 core (metric, value, time) + one column per EVENT_COLUMNS entry.
        $this->assertCount(3 + count(Metric::EVENT_COLUMNS), $schema);

        $metricAttr = $schema[0];
        $this->assertEquals('metric', $metricAttr['$id']);
        $this->assertEquals('string', $metricAttr['type']);
        $this->assertEquals(255, $metricAttr['size']);
        $this->assertTrue($metricAttr['required']);

        $valueAttr = $schema[1];
        $this->assertEquals('value', $valueAttr['$id']);
        $this->assertEquals('integer', $valueAttr['type']);
        $this->assertTrue($valueAttr['required']);

        $timeAttr = $schema[2];
        $this->assertEquals('time', $timeAttr['$id']);
        $this->assertEquals('datetime', $timeAttr['type']);
        $this->assertFalse($timeAttr['required']);

        $ids = array_column($schema, '$id');
        foreach (Metric::EVENT_COLUMNS as $col) {
            $this->assertContains($col, $ids, "Event schema missing dimension column {$col}");
        }
    }

    /**
     * Test Metric::getGaugeSchema() returns correct attribute definitions
     */
    public function testGetGaugeSchemaReturnsAttributeDefinitions(): void
    {
        $schema = Metric::getGaugeSchema();

        // 3 core (metric, value, time) + 4 GAUGE_COLUMNS.
        $this->assertCount(3 + count(Metric::GAUGE_COLUMNS), $schema);

        $this->assertEquals('metric', $schema[0]['$id']);
        $this->assertEquals('value', $schema[1]['$id']);
        $this->assertEquals('time', $schema[2]['$id']);

        $ids = array_column($schema, '$id');
        foreach (Metric::GAUGE_COLUMNS as $col) {
            $this->assertContains($col, $ids);
        }
    }

    /**
     * Test backward-compatible getSchema() returns event schema
     */
    public function testGetSchemaReturnsEventSchema(): void
    {
        $schema = Metric::getSchema();
        $eventSchema = Metric::getEventSchema();
        $this->assertEquals($eventSchema, $schema);
    }

    /**
     * Test Metric::getEventIndexes() returns one entry per indexed dimension.
     */
    public function testGetEventIndexesReturnsIndexDefinitions(): void
    {
        $indexes = Metric::getEventIndexes();

        $ids = array_column($indexes, '$id');
        $this->assertNotContains('index-userAgent', $ids, 'userAgent index must be dropped');
    }

    /**
     * Test Metric::getGaugeIndexes() returns one entry per gauge dimension.
     */
    public function testGetGaugeIndexesReturnsIndexDefinitions(): void
    {
        $indexes = Metric::getGaugeIndexes();

        $this->assertCount(count(Metric::GAUGE_COLUMNS), $indexes);
    }

    public function testEventIndexesCoverNewFilterableColumns(): void
    {
        $indexed = [];
        foreach (Metric::getEventIndexes() as $idx) {
            /** @var array<int, string> $attrs */
            $attrs = $idx['attributes'];
            $indexed = array_merge($indexed, $attrs);
        }
        foreach ([
            'path', 'method', 'status', 'service', 'resourceType',
            'resourceId', 'resourceInternalId', 'teamId', 'teamInternalId',
            'country', 'region', 'hostname', 'ip',
            'osName', 'clientType', 'clientName', 'deviceName',
            'protocol', 'accept', 'acceptLanguage', 'queryKeys',
            'ipReputation',
            'postalCode', 'timeZone', 'weatherCode',
        ] as $col) {
            $this->assertContains($col, $indexed, "Event indexes missing {$col}");
        }

        // latitude/longitude are display-only and intentionally NOT indexed.
        $this->assertNotContains('latitude', $indexed);
        $this->assertNotContains('longitude', $indexed);
    }

    public function testGaugeIndexesCoverIdColumns(): void
    {
        $indexed = [];
        foreach (Metric::getGaugeIndexes() as $idx) {
            /** @var array<int, string> $attrs */
            $attrs = $idx['attributes'];
            $indexed = array_merge($indexed, $attrs);
        }
        foreach (['resourceId', 'resourceInternalId', 'teamId', 'teamInternalId'] as $col) {
            $this->assertContains($col, $indexed);
        }
    }

    /**
     * Test backward-compatible getIndexes() returns event indexes
     */
    public function testGetIndexesReturnsEventIndexes(): void
    {
        $indexes = Metric::getIndexes();
        $eventIndexes = Metric::getEventIndexes();
        $this->assertEquals($eventIndexes, $indexes);
    }

    /**
     * Test Metric::validate() accepts valid event data
     */
    public function testValidateAcceptsValidEventData(): void
    {
        $validData = [
            'metric' => 'requests',
            'value' => 100,
            'time' => '2024-01-01 12:00:00',
            'path' => '/v1/storage/files',
            'method' => 'POST',
            'status' => '201',
            'resourceType' => 'bucket',
            'resourceId' => 'abc123',
            'region' => 'us',
        ];

        // Should not throw exception
        Metric::validate($validData, 'event');
        $this->expectNotToPerformAssertions();
    }

    /**
     * Test Metric::validate() accepts valid gauge data
     */
    public function testValidateAcceptsValidGaugeData(): void
    {
        $validData = [
            'metric' => 'storage',
            'value' => 10000,
            'time' => '2024-01-01 12:00:00',
            'teamId' => 'team1',
            'resourceId' => 'r1',
        ];

        Metric::validate($validData, 'gauge');
        $this->expectNotToPerformAssertions();
    }

    /**
     * Test Metric::validate() accepts minimal required data
     */
    public function testValidateAcceptsMinimalData(): void
    {
        $minimalData = [
            'metric' => 'requests',
            'value' => 50,
        ];

        Metric::validate($minimalData, 'event');
        $this->expectNotToPerformAssertions();
    }

    /**
     * Test Metric::validate() rejects missing required metric
     */
    public function testValidateRejectsMissingMetric(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Required attribute 'metric' is missing");

        Metric::validate([
            'value' => 100,
        ], 'event');
    }

    /**
     * Test Metric::validate() rejects missing required value
     */
    public function testValidateRejectsMissingValue(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Required attribute 'value' is missing");

        Metric::validate([
            'metric' => 'requests',
        ], 'event');
    }

    /**
     * Test Metric::validate() rejects non-string metric
     */
    public function testValidateRejectsNonStringMetric(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Attribute 'metric' must be a string");

        Metric::validate([
            'metric' => 123,
            'value' => 100,
        ], 'event');
    }

    /**
     * Test Metric::validate() rejects oversized metric string
     */
    public function testValidateRejectsOversizedMetric(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("exceeds maximum size of 255 characters");

        Metric::validate([
            'metric' => str_repeat('a', 256),
            'value' => 100,
        ], 'event');
    }

    /**
     * Test Metric::validate() rejects non-integer value
     */
    public function testValidateRejectsNonIntegerValue(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Attribute 'value' must be an integer");

        Metric::validate([
            'metric' => 'requests',
            'value' => '100',
        ], 'event');
    }

    /**
     * Test Metric::validate() accepts DateTime object for time
     */
    public function testValidateAcceptsDateTimeForTime(): void
    {
        $data = [
            'metric' => 'requests',
            'value' => 100,
            'time' => new \DateTime('2024-01-01 12:00:00'),
        ];

        Metric::validate($data, 'event');
        $this->expectNotToPerformAssertions();
    }

    /**
     * Test Metric::validate() accepts datetime string for time
     */
    public function testValidateAcceptsDatetimeStringForTime(): void
    {
        $data = [
            'metric' => 'requests',
            'value' => 100,
            'time' => '2024-01-01 12:00:00',
        ];

        Metric::validate($data, 'event');
        $this->expectNotToPerformAssertions();
    }

    /**
     * Test Metric::validate() rejects invalid datetime string
     */
    public function testValidateRejectsInvalidDatetimeString(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("not a valid datetime string");

        Metric::validate([
            'metric' => 'requests',
            'value' => 100,
            'time' => 'invalid-date',
        ], 'event');
    }

    /**
     * Test Metric constructor initializes with data
     */
    public function testConstructorInitializesWithData(): void
    {
        $data = [
            '$id' => 'metric-1',
            'metric' => 'requests',
            'value' => 100,
            'type' => 'event',
            'path' => '/v1/storage/files',
            'method' => 'POST',
            'status' => '201',
            'resourceType' => 'bucket',
            'resourceId' => 'abc123',
        ];

        $metric = new Metric($data);

        $this->assertEquals('metric-1', $metric->getId());
        $this->assertEquals('requests', $metric->getMetric());
        $this->assertEquals(100, $metric->getValue());
        $this->assertEquals('event', $metric->getType());
        $this->assertEquals('/v1/storage/files', $metric->getPath());
        $this->assertEquals('POST', $metric->getMethod());
        $this->assertEquals('201', $metric->getStatus());
        $this->assertEquals('bucket', $metric->getResourceType());
        $this->assertEquals('abc123', $metric->getResourceId());
    }

    /**
     * Test Metric::getId() returns metric ID
     */
    public function testGetIdReturnsMetricId(): void
    {
        $metric = new Metric(['$id' => 'metric-123']);
        $this->assertEquals('metric-123', $metric->getId());
    }

    /**
     * Test Metric::getId() returns empty string when ID not set
     */
    public function testGetIdReturnsEmptyStringWhenNotSet(): void
    {
        $metric = new Metric([]);
        $this->assertEquals('', $metric->getId());
    }

    /**
     * Test Metric::getMetric() returns metric name
     */
    public function testGetMetricReturnsMetricName(): void
    {
        $metric = new Metric(['metric' => 'bandwidth']);
        $this->assertEquals('bandwidth', $metric->getMetric());
    }

    /**
     * Test Metric::getValue() returns metric value
     */
    public function testGetValueReturnsValue(): void
    {
        $metric = new Metric(['value' => 1024]);
        $this->assertEquals(1024, $metric->getValue());
    }

    /**
     * Test Metric::getValue() returns default when not set
     */
    public function testGetValueReturnsDefaultWhenNotSet(): void
    {
        $metric = new Metric([]);
        $this->assertEquals(0, $metric->getValue());
    }

    /**
     * Test Metric::getType() returns type
     */
    public function testGetTypeReturnsType(): void
    {
        $metric = new Metric(['type' => 'gauge']);
        $this->assertEquals('gauge', $metric->getType());
    }

    /**
     * Test Metric::getType() returns default type
     */
    public function testGetTypeReturnsDefaultType(): void
    {
        $metric = new Metric([]);
        $this->assertEquals('event', $metric->getType());
    }

    /**
     * Test event-specific getters return null when not set
     */
    public function testEventGettersReturnNullWhenNotSet(): void
    {
        $metric = new Metric([]);
        $this->assertNull($metric->getPath());
        $this->assertNull($metric->getMethod());
        $this->assertNull($metric->getStatus());
        $this->assertNull($metric->getResourceType());
        $this->assertNull($metric->getResourceId());
    }

    /**
     * Test event-specific getters return correct values
     */
    public function testEventGettersReturnCorrectValues(): void
    {
        $metric = new Metric([
            'path' => '/v1/databases',
            'method' => 'GET',
            'status' => '200',
            'resourceType' => 'database',
            'resourceId' => 'db123',
        ]);
        $this->assertEquals('/v1/databases', $metric->getPath());
        $this->assertEquals('GET', $metric->getMethod());
        $this->assertEquals('200', $metric->getStatus());
        $this->assertEquals('database', $metric->getResourceType());
        $this->assertEquals('db123', $metric->getResourceId());
    }

    /**
     * Test Metric::getTime() returns timestamp
     */
    public function testGetTimeReturnsTimestamp(): void
    {
        $time = '2024-01-01 12:00:00';
        $metric = new Metric(['time' => $time]);
        $this->assertEquals($time, $metric->getTime());
    }

    /**
     * Test Metric::getTime() returns null when not set
     */
    public function testGetTimeReturnsNullWhenNotSet(): void
    {
        $metric = new Metric([]);
        $this->assertNull($metric->getTime());
    }

    /**
     * Test Metric::getTenant() returns tenant ID as string
     */
    public function testGetTenantReturnsTenantId(): void
    {
        $metric = new Metric(['tenant' => '123']);
        $this->assertEquals('123', $metric->getTenant());
        $this->assertIsString($metric->getTenant());
    }

    /**
     * Test Metric::getTenant() returns null when not set
     */
    public function testGetTenantReturnsNullWhenNotSet(): void
    {
        $metric = new Metric([]);
        $this->assertNull($metric->getTenant());
    }

    /**
     * Test Metric::getTenant() converts numeric tenant to string
     */
    public function testGetTenantConvertsNumericToString(): void
    {
        $metric = new Metric(['tenant' => 456]);
        $this->assertEquals('456', $metric->getTenant());
        $this->assertIsString($metric->getTenant());
    }

    /**
     * Test Metric::getAttributes() returns all attributes
     */
    public function testGetAttributesReturnsAllAttributes(): void
    {
        $data = [
            '$id' => 'metric-1',
            'metric' => 'requests',
            'value' => 100,
        ];

        $metric = new Metric($data);
        $attributes = $metric->getAttributes();

        $this->assertEquals('metric-1', $attributes['$id']);
        $this->assertEquals('requests', $attributes['metric']);
        $this->assertEquals(100, $attributes['value']);
    }

    /**
     * Test Metric::getAttribute() returns attribute value
     */
    public function testGetAttributeReturnsValue(): void
    {
        $metric = new Metric(['custom' => 'custom-value']);
        $this->assertEquals('custom-value', $metric->getAttribute('custom'));
    }

    /**
     * Test Metric::getAttribute() returns default when not set
     */
    public function testGetAttributeReturnsDefaultWhenNotSet(): void
    {
        $metric = new Metric([]);
        $this->assertEquals('default', $metric->getAttribute('missing', 'default'));
    }

    /**
     * Test Metric::setAttribute() sets attribute and returns self
     */
    public function testSetAttributeSetsAndReturnsSelf(): void
    {
        $metric = new Metric([]);
        $result = $metric->setAttribute('custom', 'value');

        $this->assertSame($metric, $result);
        $this->assertEquals('value', $metric->getAttribute('custom'));
    }

    /**
     * Test Metric::setAttribute() supports method chaining
     */
    public function testSetAttributeSupportsChaining(): void
    {
        $metric = (new Metric([]))
            ->setAttribute('attr1', 'value1')
            ->setAttribute('attr2', 'value2');

        $this->assertEquals('value1', $metric->getAttribute('attr1'));
        $this->assertEquals('value2', $metric->getAttribute('attr2'));
    }

    /**
     * Test Metric::hasAttribute() returns true when attribute exists
     */
    public function testHasAttributeReturnsTrueWhenExists(): void
    {
        $metric = new Metric(['key' => 'value']);
        $this->assertTrue($metric->hasAttribute('key'));
    }

    /**
     * Test Metric::hasAttribute() returns false when attribute doesn't exist
     */
    public function testHasAttributeReturnsFalseWhenNotExists(): void
    {
        $metric = new Metric([]);
        $this->assertFalse($metric->hasAttribute('missing'));
    }

    /**
     * Test Metric::removeAttribute() removes attribute and returns self
     */
    public function testRemoveAttributeRemovesAndReturnsSelf(): void
    {
        $metric = new Metric(['key' => 'value']);
        $result = $metric->removeAttribute('key');

        $this->assertSame($metric, $result);
        $this->assertFalse($metric->hasAttribute('key'));
    }

    /**
     * Test Metric::isEmpty() returns false when ID is set
     */
    public function testIsEmptyReturnsFalseWhenIdSet(): void
    {
        $metric = new Metric(['$id' => 'metric-1']);
        $this->assertFalse($metric->isEmpty());
    }

    /**
     * Test Metric::isEmpty() returns true when ID is not set
     */
    public function testIsEmptyReturnsTrueWhenNoId(): void
    {
        $metric = new Metric([]);
        $this->assertTrue($metric->isEmpty());
    }

    /**
     * Test Metric::toArray() returns array representation
     */
    public function testToArrayReturnsArray(): void
    {
        $data = [
            '$id' => 'metric-1',
            'metric' => 'requests',
            'value' => 100,
        ];

        $metric = new Metric($data);
        $array = $metric->toArray();

        $this->assertEquals('metric-1', $array['$id']);
        $this->assertEquals('requests', $array['metric']);
        $this->assertEquals(100, $array['value']);
    }

    /**
     * Test EVENT_COLUMNS constant
     */
    public function testEventColumnsConstant(): void
    {
        $expected = [
            'path', 'method', 'status',
            'service', 'resourceType', 'resourceId', 'resourceInternalId',
            'teamId', 'teamInternalId',
            'country', 'region', 'hostname', 'ip',
            'protocol', 'accept', 'acceptLanguage', 'queryKeys',
            'ipReputation',
            'city', 'continentCode', 'subdivisions',
            'postalCode', 'latitude', 'longitude', 'timeZone', 'weatherCode',
            'isp', 'autonomousSystemNumber', 'autonomousSystemOrganization',
            'connectionType', 'connectionUsageType', 'connectionOrganization',
            'osCode', 'osName', 'osVersion',
            'clientType', 'clientCode', 'clientName', 'clientVersion',
            'clientEngine', 'clientEngineVersion',
            'sdk', 'sdkVersion',
            'deviceName', 'deviceBrand', 'deviceModel',
        ];
        $this->assertSame($expected, Metric::EVENT_COLUMNS);
    }

    /**
     * Test GAUGE_COLUMNS constant
     */
    public function testGaugeColumnsConstant(): void
    {
        $expected = ['service', 'resourceType', 'teamId', 'teamInternalId', 'resourceId', 'resourceInternalId', 'ordinal'];
        $this->assertSame($expected, Metric::GAUGE_COLUMNS);
    }

    /**
     * The replica ordinal is a gauge-only dimension: present in GAUGE_COLUMNS,
     * the gauge schema and gauge indexes, extracted from tags into its column,
     * and readable via the typed accessor. Events must not carry it.
     */
    public function testOrdinalIsGaugeOnly(): void
    {
        $this->assertContains('ordinal', Metric::GAUGE_COLUMNS);
        $this->assertNotContains('ordinal', Metric::EVENT_COLUMNS);

        $gaugeIds = array_column(Metric::getGaugeSchema(), '$id');
        $this->assertContains('ordinal', $gaugeIds);

        $eventIds = array_column(Metric::getEventSchema(), '$id');
        $this->assertNotContains('ordinal', $eventIds);

        $indexIds = array_column(Metric::getGaugeIndexes(), '$id');
        $this->assertContains('index-ordinal', $indexIds);

        $columns = Metric::extractColumns(['resourceId' => 'db_a', 'ordinal' => 1], 'gauge');
        $this->assertSame('1', $columns['ordinal']);
        $this->assertSame('db_a', $columns['resourceId']);

        $metric = new Metric(['ordinal' => '2']);
        $this->assertSame('2', $metric->getOrdinal());
        $this->assertNull((new Metric([]))->getOrdinal());
    }

    /**
     * Test that the event schema contains every new dimension column.
     */
    public function testEventSchemaHasAllNewColumns(): void
    {
        $ids = array_column(Metric::getEventSchema(), '$id');
        foreach ([
            'service', 'resourceInternalId', 'teamId', 'teamInternalId',
            'region', 'hostname', 'osCode', 'osName', 'osVersion',
            'clientType', 'clientCode', 'clientName', 'clientVersion',
            'clientEngine', 'clientEngineVersion',
            'deviceName', 'deviceBrand', 'deviceModel',
        ] as $col) {
            $this->assertContains($col, $ids, "Event schema missing {$col}");
        }
        $this->assertNotContains('userAgent', $ids, 'userAgent must be removed');
        $this->assertNotContains('tags', $ids, 'tags must be removed');
    }

    /**
     * The premium geo dimensions are event-only string columns that are
     * present in EVENT_COLUMNS and the event schema (as non-required
     * strings) but never leak into the gauge column set.
     */
    public function testPremiumGeoColumnsArePresentAsEventStrings(): void
    {
        $geo = [
            'city', 'continentCode', 'subdivisions',
            'isp', 'autonomousSystemNumber', 'autonomousSystemOrganization',
            'connectionType', 'connectionUsageType', 'connectionOrganization',
        ];

        $schema = Metric::getEventSchema();
        $eventIds = array_column($schema, '$id');
        $gaugeIds = array_column(Metric::getGaugeSchema(), '$id');

        foreach ($geo as $col) {
            $this->assertContains($col, Metric::EVENT_COLUMNS, "EVENT_COLUMNS missing {$col}");
            $this->assertContains($col, $eventIds, "Event schema missing {$col}");
            $this->assertNotContains($col, Metric::GAUGE_COLUMNS, "{$col} must be event-only");
            $this->assertNotContains($col, $gaugeIds, "{$col} must not be in gauge schema");

            $matches = array_values(array_filter(
                $schema,
                static fn (array $attr): bool => $attr['$id'] === $col,
            ));
            $this->assertCount(1, $matches, "{$col} must appear exactly once");
            $this->assertSame('string', $matches[0]['type'], "{$col} must be a string column");
            $this->assertFalse($matches[0]['required'], "{$col} must be optional");
        }
    }

    /**
     * The sdk dimensions are event-only string columns that are present in
     * EVENT_COLUMNS and the event schema (as non-required strings) but never
     * leak into the gauge column set.
     */
    public function testSdkColumnsArePresentAsEventStrings(): void
    {
        $sdk = ['sdk', 'sdkVersion'];

        $schema = Metric::getEventSchema();
        $eventIds = array_column($schema, '$id');
        $gaugeIds = array_column(Metric::getGaugeSchema(), '$id');

        foreach ($sdk as $col) {
            $this->assertContains($col, Metric::EVENT_COLUMNS, "EVENT_COLUMNS missing {$col}");
            $this->assertContains($col, $eventIds, "Event schema missing {$col}");
            $this->assertNotContains($col, Metric::GAUGE_COLUMNS, "{$col} must be event-only");
            $this->assertNotContains($col, $gaugeIds, "{$col} must not be in gauge schema");

            $matches = array_values(array_filter(
                $schema,
                static fn (array $attr): bool => $attr['$id'] === $col,
            ));
            $this->assertCount(1, $matches, "{$col} must appear exactly once");
            $this->assertSame('string', $matches[0]['type'], "{$col} must be a string column");
            $this->assertFalse($matches[0]['required'], "{$col} must be optional");
        }
    }

    /**
     * Test that the gauge schema contains the new team and resource id columns.
     */
    public function testGaugeSchemaHasTeamAndResourceColumns(): void
    {
        $ids = array_column(Metric::getGaugeSchema(), '$id');
        foreach (['teamId', 'teamInternalId', 'resourceId', 'resourceInternalId'] as $col) {
            $this->assertContains($col, $ids);
        }
        $this->assertNotContains('tags', $ids);
    }

    /**
     * Test the 18 new typed getters return the value passed via the constructor.
     */
    public function testNewGettersReturnString(): void
    {
        $m = new Metric([
            'service' => 'storage', 'resourceInternalId' => '42',
            'teamId' => 'org_x', 'teamInternalId' => '7',
            'region' => 'fra', 'hostname' => 'app.example.com',
            'osCode' => 'IOS', 'osName' => 'iOS', 'osVersion' => '17.4',
            'clientType' => 'mobile-app', 'clientCode' => 'APW', 'clientName' => 'Appwrite SDK',
            'clientVersion' => '15.0.0', 'clientEngine' => 'WebKit', 'clientEngineVersion' => '605',
            'deviceName' => 'smartphone', 'deviceBrand' => 'Apple', 'deviceModel' => 'iPhone 13',
        ]);
        $this->assertSame('storage', $m->getService());
        $this->assertSame('42', $m->getResourceInternalId());
        $this->assertSame('org_x', $m->getTeamId());
        $this->assertSame('7', $m->getTeamInternalId());
        $this->assertSame('fra', $m->getRegion());
        $this->assertSame('app.example.com', $m->getHostname());
        $this->assertSame('IOS', $m->getOsCode());
        $this->assertSame('iOS', $m->getOsName());
        $this->assertSame('17.4', $m->getOsVersion());
        $this->assertSame('mobile-app', $m->getClientType());
        $this->assertSame('APW', $m->getClientCode());
        $this->assertSame('Appwrite SDK', $m->getClientName());
        $this->assertSame('15.0.0', $m->getClientVersion());
        $this->assertSame('WebKit', $m->getClientEngine());
        $this->assertSame('605', $m->getClientEngineVersion());
        $this->assertSame('smartphone', $m->getDeviceName());
        $this->assertSame('Apple', $m->getDeviceBrand());
        $this->assertSame('iPhone 13', $m->getDeviceModel());
    }

    public function testDroppedGettersDoNotExist(): void
    {
        $this->assertFalse(method_exists(Metric::class, 'getUserAgent'));
        $this->assertFalse(method_exists(Metric::class, 'getTags'));
    }

    /**
     * The ip dimension is event-only and round-trips through the typed
     * accessor.
     */
    public function testGetIpReturnsAddress(): void
    {
        $m = new Metric(['ip' => '203.0.113.42']);
        $this->assertSame('203.0.113.42', $m->getIp());

        $m6 = new Metric(['ip' => '2001:db8::1']);
        $this->assertSame('2001:db8::1', $m6->getIp());

        $missing = new Metric([]);
        $this->assertNull($missing->getIp());
    }

    /**
     * ip is event-only; the gauge column set must not include it.
     */
    public function testIpIsEventOnly(): void
    {
        $this->assertContains('ip', Metric::EVENT_COLUMNS);
        $this->assertNotContains('ip', Metric::GAUGE_COLUMNS);

        $eventIds = array_column(Metric::getEventSchema(), '$id');
        $this->assertContains('ip', $eventIds);

        $gaugeIds = array_column(Metric::getGaugeSchema(), '$id');
        $this->assertNotContains('ip', $gaugeIds);
    }
}
